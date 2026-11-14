<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * State-driven editorial queue. Each tick advances exactly one stage and
 * writes that result before the next stage starts, so an upstream API or
 * social-adapter failure cannot roll back a committed item.
 *
 * Canonical statuses: pending → generated → reviewed → draft|published,
 * with quarantined as the fail-closed manual-review sink.
 */
trait TechnoRapper_AI_Wire_Pipeline {
    private function pipeline_status($status) {
        $status = sanitize_key((string) $status);
        if ($status === 'new') {
            return 'pending';
        }
        if ($status === 'rewritten') {
            return 'generated';
        }
        return $status;
    }

    private function pipeline_item_is_actionable($item) {
        if (!is_array($item)) {
            return false;
        }
        $status = $this->pipeline_status($item['status'] ?? '');
        if (in_array($status, array('pending', 'generated', 'reviewed', 'waiting_budget', 'quarantined'), true)) {
            return true;
        }
        if ($status === 'retry_wait') {
            $next = $item['next_retry_at'] ?? '';
            return $next === '' || $next === null || strtotime((string) $next . ' UTC') <= time();
        }
        return false;
    }

    private function next_pipeline_item_id_raw($exclude = array()) {
        global $wpdb;
        $exclude_ids = array();
        foreach ((array) $exclude as $key => $value) {
            $id = is_int($key) && $key > 0 ? $key : (int) $value;
            if ($id > 0) {
                $exclude_ids[] = $id;
            }
        }
        $exclude_ids = array_values(array_unique($exclude_ids));
        $not_in = $exclude_ids ? ' AND id NOT IN (' . implode(',', array_map('intval', $exclude_ids)) . ')' : '';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE (
                (status IN ('pending', 'new', 'waiting_budget', 'generated', 'rewritten') AND (wp_post_id IS NULL OR wp_post_id = 0))
                OR status = 'reviewed'
                OR (status = 'retry_wait' AND (next_retry_at IS NULL OR next_retry_at <= %s))
             ){$not_in}
             ORDER BY CASE
                WHEN status IN ('reviewed') THEN 0
                WHEN status IN ('generated', 'rewritten') THEN 1
                WHEN status IN ('pending', 'new', 'waiting_budget') THEN 2
                WHEN status = 'retry_wait' THEN 3
                ELSE 4
             END ASC, id DESC
             LIMIT 1",
            current_time('mysql', true)
        ));
    }

    private function next_pipeline_item_id($exclude = array()) {
        $s = $this->settings();
        $tried = is_array($exclude) ? $exclude : array();
        for ($i = 0; $i < 40; $i++) {
            $id = $this->next_pipeline_item_id_raw($tried);
            if ($id < 1) {
                return 0;
            }
            $item = $this->get_item($id);
            if ($this->lane_has_room_for_item($item, $s, $tried)) {
                return $id;
            }
            $tried[$id] = true;
        }
        return 0;
    }

    /** Unfinished work that can still fill today's post cap. Quarantine is not this. */
    private function pipeline_item_is_unfinished($item) {
        $status = $this->pipeline_status(is_array($item) ? ($item['status'] ?? '') : '');
        return in_array($status, array('pending', 'generated', 'reviewed', 'waiting_budget', 'retry_wait'), true);
    }

    /**
     * Advance one committed pipeline stage. Manual Run now still finishes
     * writer, independent review, and publish in the same request, matching
     * the previous operator workflow.
     */
    private function process_item($id, $s, $complete = false) {
        try {
            if ($complete) {
                $this->advance_item_until_idle((int) $id, $s);
                return;
            }
            $this->process_item_stage((int) $id, $s);
        } catch (Throwable $e) {
            $item = $this->get_item((int) $id);
            $status = $this->pipeline_status(is_array($item) ? ($item['status'] ?? '') : '');
            if (in_array($status, array('draft', 'published', 'quarantined'), true)) {
                return;
            }
            $this->schedule_retry((int) $id, 'Pipeline stage failed: ' . $e->getMessage());
        }
    }

    private function pipeline_item_fingerprint($item) {
        $item = is_array($item) ? $item : array();
        return $this->pipeline_status($item['status'] ?? '') . '|' . md5((string) ($item['rewrite_json'] ?? '') . "\n" . (string) ($item['review_json'] ?? ''));
    }

    private function advance_item_until_idle($id, $s, $max_stages = 6, $started_at = null, $time_budget = null, $lock_token = null) {
        $id = (int) $id;
        for ($i = 0; $i < $max_stages; $i++) {
            if ($lock_token && !$this->refresh_processing_lock($lock_token)) {
                return;
            }
            if ($started_at !== null && $time_budget !== null && (microtime(true) - $started_at) >= $time_budget) {
                return;
            }
            $item = $this->get_item($id);
            if (!$this->pipeline_item_is_actionable($item)) {
                return;
            }
            $before = $this->pipeline_item_fingerprint($item);
            $this->process_item($id, $s);
            $after_item = $this->get_item($id);
            $after = $this->pipeline_status($after_item['status'] ?? '');
            if (in_array($after, array('retry_wait', 'waiting_budget', 'error', 'quarantined', 'draft', 'published'), true)) {
                return;
            }
            if ($this->pipeline_item_fingerprint($after_item) === $before) {
                return;
            }
        }
    }

    private function process_item_stage($id, $s) {
        $item = $this->get_item($id);
        if (!$item) {
            return;
        }
        $status = $this->pipeline_status($item['status'] ?? '');
        if (in_array($status, array('draft', 'published', 'error'), true)) {
            return;
        }
        $review = !empty($item['review_json']) ? json_decode($item['review_json'], true) : null;
        $review = is_array($review) ? $review : null;
        $value_hold = $this->story_value_local_reason($item);
        // A list or deal with no review yet still goes to the writer so the
        // reviewer can say whether people are searching it on the internet.
        // Once that flag is true, this website posts it. A thin source with
        // nothing to search is still held before a model call.
        if ($value_hold !== '' && !$this->people_are_searching($review)) {
            $awaiting_search_judgment = $review === null && $this->story_value_local_is_filler($item);
            if (!$awaiting_search_judgment) {
                if ($status !== 'quarantined') {
                    $this->quarantine_item($id, $value_hold, $item);
                }
                return;
            }
        }
        $s = $this->settings_for_item($item, $s);
        if ($status === 'quarantined') {
            $this->pipeline_republish_if_clear($id, $item, $s);
            return;
        }
        if (in_array($status, array('pending', 'waiting_budget', 'retry_wait'), true)) {
            $this->pipeline_generate($id, $item, $s);
            return;
        }
        if ($status === 'generated') {
            $this->pipeline_review($id, $item, $s);
            return;
        }
        if ($status === 'reviewed') {
            $this->pipeline_publish($id, $item, $s);
        }
    }

    private function pipeline_generate($id, $item, $s) {
        $item = $this->assign_item_voice($id, $item, $s);
        $rewrite = !empty($item['rewrite_json']) ? json_decode($item['rewrite_json'], true) : null;
        if (!is_array($rewrite) || !$this->has_usable_rewrite($rewrite)) {
            $rewrite = $this->ai_json(
                $s['writer_model'],
                $this->writer_prompt($item['variant'] ?? 'A', $this->item_is_comic($item)),
                $this->build_writer_input($item),
                $s,
                false
            );
            if (is_wp_error($rewrite)) {
                $this->handle_stage_error($id, $rewrite);
                return;
            }
        }
        $rewrite = $this->prepare_rewrite_seo($rewrite);
        if (!$this->has_usable_rewrite($rewrite)) {
            $this->quarantine_item($id, 'Writer returned an empty title or body. Held for manual review.', $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
            ));
            return;
        }
        $seo_blockers = $this->rewrite_seo_blockers($rewrite, $s);
        // A low SEO score waits for review. Top pop-culture news and stories
        // everyone is searching for still publish to the website under that score.
        if ($seo_blockers && !$this->seo_blockers_are_score_only($seo_blockers)) {
            $this->quarantine_item($id, 'Held after rewrite SEO tests: ' . implode(', ', $seo_blockers), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
            ));
            return;
        }
        $this->update_item($id, array(
            'status' => 'generated',
            'rewrite_json' => wp_json_encode($rewrite),
            'error_text' => null,
        ));
        wp_schedule_single_event(time() + 1, self::CRON_HOOK, array('review', (int) $id, wp_generate_uuid4()));
    }

    private function pipeline_review($id, $item, $s) {
        $rewrite = !empty($item['rewrite_json']) ? json_decode($item['rewrite_json'], true) : null;
        if (!is_array($rewrite) || !$this->has_usable_rewrite($rewrite)) {
            $this->update_item($id, array('status' => 'pending', 'error_text' => 'Generation output missing; queued again.'));
            wp_schedule_single_event(time() + 1, self::CRON_HOOK, array('generate', (int) $id, wp_generate_uuid4()));
            return;
        }
        $rewrite = $this->prepare_rewrite_seo($rewrite);
        $review = !empty($item['review_json']) ? json_decode($item['review_json'], true) : null;
        if (!is_array($review)) {
            $review = $this->ai_json(
                $s['reviewer_model'],
                $this->reviewer_prompt($s),
                $this->build_review_input($item, $rewrite),
                $s,
                !empty($s['enable_web_search'])
            );
            if (is_wp_error($review)) {
                $this->handle_stage_error($id, $review);
                return;
            }
            if (!is_array($review['story_value'] ?? null)) {
                $review['story_value'] = array(
                    'publish' => false,
                    'substance' => 'thin',
                    'reader_demand' => 'low',
                    'adds_value' => false,
                    'reason' => 'The reviewer did not say this story is worth publishing.',
                );
            }
            if (!is_array($review['audience'] ?? null)) {
                $review['audience'] = array(
                    'pop_culture_top' => false,
                    'everyone_searching' => false,
                    'reason' => 'The reviewer did not mark this as top pop-culture news or a story everyone is searching for.',
                );
            }
        }
        $review = $this->normalize_review_score($review, $rewrite, $item);
        $blockers = $this->review_draft_blockers($review, $s);
        if ($blockers) {
            $this->quarantine_item($id, 'Held for editorial review: ' . implode(', ', $blockers), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
                'review_json' => wp_json_encode($review),
            ));
            return;
        }
        if ($this->pipeline_polish_near_miss($id, $item, $rewrite, $review, $s)) {
            return;
        }
        $this->update_item($id, array(
            'status' => 'reviewed',
            'rewrite_json' => wp_json_encode($rewrite),
            'review_json' => wp_json_encode($review),
            'error_text' => null,
        ));
        wp_schedule_single_event(time() + 1, self::CRON_HOOK, array('publish', (int) $id, wp_generate_uuid4()));
    }

    /**
     * One extra writer pass for a website-grade 85-89 that the reviewer
     * already named fixes for. Fail closed: keep the original article.
     */
    private function pipeline_polish_near_miss($id, $item, $rewrite, $review, $s) {
        if (!$this->review_should_polish($review, $rewrite)) {
            return false;
        }
        $polished = $this->ai_json(
            $s['writer_model'],
            $this->polish_writer_prompt($item['variant'] ?? 'A', $this->item_is_comic($item)),
            $this->build_polish_input($item, $rewrite, $review),
            $s,
            false
        );
        if (is_wp_error($polished) || !$this->has_usable_rewrite($polished)) {
            return false;
        }
        $polished['_tr_score_polish'] = 1;
        $polished = $this->prepare_rewrite_seo($polished);
        if ($this->rewrite_seo_blockers($polished, $s)) {
            return false;
        }
        $this->update_item($id, array(
            'status' => 'generated',
            'rewrite_json' => wp_json_encode($polished),
            'review_json' => '',
            'error_text' => 'Polished once to try for a 90 review score.',
        ));
        wp_schedule_single_event(time() + 1, self::CRON_HOOK, array('review', (int) $id, wp_generate_uuid4()));
        return true;
    }

    private function pipeline_publish($id, $item, $s) {
        $rewrite = !empty($item['rewrite_json']) ? json_decode($item['rewrite_json'], true) : null;
        $review = !empty($item['review_json']) ? json_decode($item['review_json'], true) : null;
        if (!is_array($rewrite) || !is_array($review)) {
            $this->quarantine_item($id, 'Publish stage missing generation or independent review payload.', $item);
            return;
        }
        $blockers = $this->review_draft_blockers($review, $s);
        if ($blockers) {
            $this->quarantine_item($id, 'Held for editorial review: ' . implode(', ', $blockers), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
                'review_json' => wp_json_encode($review),
            ));
            return;
        }
        $rewrite = $this->prepare_rewrite_seo($rewrite);
        $guard = $this->policy_guard_rewrite($rewrite);
        if (is_wp_error($guard)) {
            if ($guard->get_error_code() === 'tr_ai_wire_policy_unavailable') {
                $this->handle_stage_error($id, $guard);
                return;
            }
            $this->quarantine_item($id, $guard->get_error_message(), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
                'review_json' => wp_json_encode($review),
            ));
            return;
        }
        $seo_blockers = $this->rewrite_seo_blockers($rewrite, $s, $review);
        if ($seo_blockers) {
            $this->quarantine_item($id, 'Held after rewrite SEO tests: ' . implode(', ', $seo_blockers), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
                'review_json' => wp_json_encode($review),
            ));
            return;
        }
        $gate = $this->evaluate_pre_publish_gate($rewrite, $review, $item, $s);
        $review['prepublish_gate'] = $gate;
        if ($this->pre_publish_gate_holds($gate) && $this->mapped_post_status($s) === 'publish') {
            $this->quarantine_item($id, 'Held by pre-publish gate. ' . $this->format_pre_publish_gate($gate), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
                'review_json' => wp_json_encode($review),
            ));
            return;
        }
        $this->create_draft($id, $item, $rewrite, $review, $s);
    }

    /**
     * A later run with looser gates can still publish a stored rewrite/review
     * that now clears, matching the previous queue behaviour. Missing payloads
     * stay quarantined until Retry.
     */
    private function pipeline_republish_if_clear($id, $item, $s) {
        $rewrite = !empty($item['rewrite_json']) ? json_decode($item['rewrite_json'], true) : null;
        $review = !empty($item['review_json']) ? json_decode($item['review_json'], true) : null;
        if (!is_array($rewrite) || !is_array($review) || !$this->has_usable_rewrite($rewrite)) {
            return;
        }
        $rewrite = $this->prepare_rewrite_seo($rewrite);
        if ($this->review_draft_blockers($review, $s) || $this->rewrite_seo_blockers($rewrite, $s, $review)) {
            return;
        }
        $gate = $this->evaluate_pre_publish_gate($rewrite, $review, $item, $s);
        if ($this->pre_publish_gate_holds($gate) && $this->mapped_post_status($s) === 'publish') {
            return;
        }
        $this->pipeline_publish($id, $item, $s);
    }

    private function handle_stage_error($id, $error) {
        if (!is_wp_error($error)) {
            $this->schedule_retry($id, 'Pipeline stage failed.');
            return;
        }
        if ($error->get_error_code() === 'tr_ai_wire_limit') {
            $this->update_item($id, array('status' => 'waiting_budget', 'error_text' => $error->get_error_message()));
        } elseif ($error->get_error_code() === 'tr_ai_wire_policy') {
            $this->quarantine_item($id, $error->get_error_message());
        } elseif ($error->get_error_code() === 'tr_ai_wire_api_permanent') {
            $this->update_item($id, array('status' => 'error', 'error_text' => $error->get_error_message()));
        } else {
            $this->schedule_retry($id, $error->get_error_message());
        }
    }

    private function quarantine_item($id, $reason, $item = null, $fields = array()) {
        $item = is_array($item) ? $item : $this->get_item($id);
        $update = array_merge(array(
            'status' => 'quarantined',
            'error_text' => $reason,
        ), is_array($fields) ? $fields : array());
        $this->update_item($id, $update);
        $this->record_quarantine($id, $reason, is_array($item) ? $item : array(), $update);
    }

    private function record_quarantine($id, $reason, $item, $update) {
        global $wpdb;
        try {
            $rewrite = $update['rewrite_json'] ?? ($item['rewrite_json'] ?? '');
            $review = $update['review_json'] ?? ($item['review_json'] ?? '');
            $wpdb->insert(
                $wpdb->prefix . self::QUARANTINE_TABLE_SUFFIX,
                array(
                    'queue_item_id' => (int) $id,
                    'reason' => $reason,
                    'source_url' => $item['source_url'] ?? '',
                    'source_title' => $item['source_title'] ?? '',
                    'rewrite_json' => is_string($rewrite) ? $rewrite : wp_json_encode($rewrite),
                    'review_json' => is_string($review) ? $review : wp_json_encode($review),
                    'created_at' => current_time('mysql', true),
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        } catch (Throwable $e) {
            // Ledger failure must not change queue or CMS state.
        }
    }
}
