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
        if (in_array($status, array('pending', 'generated', 'reviewed', 'waiting_budget'), true)) {
            return true;
        }
        if ($status === 'retry_wait') {
            $next = $item['next_retry_at'] ?? '';
            return $next === '' || $next === null || strtotime((string) $next . ' UTC') <= time();
        }
        return false;
    }

    private function next_pipeline_item_id($exclude = array()) {
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
             ORDER BY id DESC
             LIMIT 1",
            current_time('mysql', true)
        ));
    }

    /**
     * Advance one pipeline stage and persist it. The unused $complete flag is
     * kept so historical callers do not fatally mismatch; it no longer chains
     * writer, reviewer, and publisher in one request.
     */
    private function process_item($id, $s, $complete = false) {
        unset($complete);
        try {
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

    private function advance_item_until_idle($id, $s, $max_stages = 5, $started_at = null, $time_budget = null, $lock_token = null) {
        $id = (int) $id;
        for ($i = 0; $i < $max_stages; $i++) {
            if ($lock_token && !$this->refresh_processing_lock($lock_token)) {
                return;
            }
            if ($started_at !== null && $time_budget !== null && (microtime(true) - $started_at) >= $time_budget) {
                wp_schedule_single_event(time() + 15, self::CRON_HOOK, array('continue', wp_generate_uuid4()));
                return;
            }
            $item = $this->get_item($id);
            if (!$this->pipeline_item_is_actionable($item)) {
                return;
            }
            $before = $this->pipeline_status($item['status'] ?? '');
            $this->process_item($id, $s);
            $after_item = $this->get_item($id);
            $after = $this->pipeline_status($after_item['status'] ?? '');
            if ($after === $before || in_array($after, array('retry_wait', 'waiting_budget', 'error', 'quarantined', 'draft', 'published'), true)) {
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
        if (in_array($status, array('quarantined', 'draft', 'published', 'error'), true)) {
            return;
        }
        $s = $this->settings_for_item($item, $s);
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
        $rewrite = !empty($item['rewrite_json']) ? json_decode($item['rewrite_json'], true) : null;
        if (!is_array($rewrite) || !$this->has_usable_rewrite($rewrite)) {
            $rewrite = $this->ai_json(
                $s['writer_model'],
                $this->writer_prompt($item['variant'] ?? 'A'),
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
        }
        $blockers = $this->review_draft_blockers($review, $s);
        if ($blockers) {
            $this->quarantine_item($id, 'Held for editorial review: ' . implode(', ', $blockers), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
                'review_json' => wp_json_encode($review),
            ));
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
        $seo_blockers = $this->rewrite_seo_blockers($rewrite, $s);
        if ($seo_blockers) {
            $this->quarantine_item($id, 'Held after rewrite SEO tests: ' . implode(', ', $seo_blockers), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
                'review_json' => wp_json_encode($review),
            ));
            return;
        }
        $this->create_draft($id, $item, $rewrite, $review, $s);
    }

    private function handle_stage_error($id, $error) {
        if (!is_wp_error($error)) {
            $this->schedule_retry($id, 'Pipeline stage failed.');
            return;
        }
        if ($error->get_error_code() === 'tr_ai_wire_limit') {
            $this->update_item($id, array('status' => 'waiting_budget', 'error_text' => $error->get_error_message()));
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
