<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Queue {
    public function retry_item() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to retry queue items.', 'technorapper-ai-wire'));
        }
        $id = absint($_GET['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_retry_item_' . $id);
        $this->retry_queue_item($id);
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-queue&retried=1'));
        exit;
    }

    /**
     * A saved review under the score or fact-flag gate is replayed forever
     * unless Retry asks the independent reviewer again. A review that already
     * clears the editorial gates still publishes without another model call.
     */
    private function retry_queue_item($id) {
        $item = $this->get_item($id);
        if (!$item || !in_array($item['status'], array('error', 'retry_wait', 'quarantined'), true)) {
            return;
        }
        $rewrite = !empty($item['rewrite_json']) ? json_decode($item['rewrite_json'], true) : null;
        $review = !empty($item['review_json']) ? json_decode($item['review_json'], true) : null;
        $s = $this->settings_for_item($item, $this->settings());
        $blocked = is_array($review) && $this->review_draft_blockers($review, $s);
        if ($this->should_force_publish_retry($item, $rewrite, $review, $s) && !$blocked) {
            $this->update_item($id, array(
                'status' => 'reviewed',
                'attempts' => 0,
                'next_retry_at' => null,
                'error_text' => null,
            ));
            $this->advance_item_until_idle($id, $s);
            return;
        }
        $fields = array(
            'status' => $this->has_usable_rewrite($rewrite) ? 'generated' : 'pending',
            'attempts' => 0,
            'next_retry_at' => null,
            'error_text' => null,
            'review_json' => '',
        );
        $this->update_item($id, $fields);
        $this->advance_item_until_idle($id, $s);
    }

    public function is_run_in_progress() {
        $lock = get_option(self::PROCESSING_LOCK_OPTION, array());
        return is_array($lock) && !empty($lock['token']);
    }

    public function release_processing_lock() {
        delete_option(self::PROCESSING_LOCK_OPTION);
        wp_cache_delete(self::PROCESSING_LOCK_OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        self::schedule_cron();
        return true;
    }

    public function stop_running_jobs($reason = 'Stopped by administrator.') {
        $this->release_processing_lock();
        $this->store_last_run(array(
            'at' => time(),
            'reason' => 'stopped',
            'feeds_checked' => 0,
            'feed_errors' => array(),
            'new_items' => 0,
            'processed' => 0,
            'drafts' => 0,
            'published' => 0,
            'quarantined' => 0,
            'skipped' => sanitize_text_field($reason),
        ));
        return true;
    }

    public function delete_queue_item($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }
        return (bool) $wpdb->delete($this->table, array('id' => $id), array('%d'));
    }

    public function clean_queue($mode = 'all') {
        global $wpdb;
        $this->release_processing_lock();
        if ($mode === 'unfinished') {
            $deleted = $wpdb->query("DELETE FROM {$this->table} WHERE status NOT IN ('draft', 'published')");
            $reason = 'cleared';
            $skipped = 'Cleared unfinished queue items.';
        } else {
            $deleted = $wpdb->query("DELETE FROM {$this->table}");
            $reason = 'cleaned';
            $skipped = 'Queue board cleaned.';
        }
        $this->store_last_run(array(
            'at' => time(),
            'reason' => $reason,
            'feeds_checked' => 0,
            'feed_errors' => array(),
            'new_items' => 0,
            'processed' => 0,
            'drafts' => 0,
            'published' => 0,
            'quarantined' => 0,
            'skipped' => $skipped,
        ));
        return (int) $deleted;
    }

    public function stop_run() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to stop this task.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_stop_run');
        $this->stop_running_jobs();
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-queue&queue_notice=stopped'));
        exit;
    }

    public function delete_item() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to delete queue items.', 'technorapper-ai-wire'));
        }
        $id = absint($_GET['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_delete_item_' . $id);
        $this->delete_queue_item($id);
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-queue&queue_notice=deleted'));
        exit;
    }

    public function clean_queue_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to clean the queue.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_clean_queue');
        $mode = sanitize_key(wp_unslash($_POST['mode'] ?? 'all'));
        if ($mode !== 'unfinished') {
            $mode = 'all';
        }
        $this->clean_queue($mode);
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-queue&queue_notice=' . ($mode === 'unfinished' ? 'cleared' : 'cleaned')));
        exit;
    }

    private function insert_item($key, $experiment_id, $variant, $feed, $name, $guid, $url, $title, $body, $published_at = '') {
        global $wpdb;
        $now = current_time('mysql', true);
        $published = $this->normalize_source_date($published_at);
        $row = array(
            'source_key' => $key, 'experiment_id' => $experiment_id, 'variant' => $variant, 'feed_url' => $feed, 'feed_name' => $name, 'source_guid' => $guid,
            'source_url' => $url, 'source_title' => $title, 'source_body' => $body,
            'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        );
        $formats = array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s');
        if ($published !== '') {
            $row['source_published_at'] = $published;
            $formats[] = '%s';
        }
        $ok = $wpdb->insert($this->table, $row, $formats);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    private function update_item($id, $fields) {
        global $wpdb;
        $fields['updated_at'] = current_time('mysql', true);
        $wpdb->update($this->table, $fields, array('id' => (int) $id));
    }

    private function get_item($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A);
    }

    private function has_item($key) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE source_key = %s", $key));
    }

    private function has_source_url($url) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE source_url = %s", $url));
    }

    private function schedule_retry($id, $message) {
        $item = $this->get_item($id);
        $attempts = $item ? ((int) ($item['attempts'] ?? 0) + 1) : 4;
        if ($attempts > 3) {
            $this->update_item($id, array('status' => 'error', 'attempts' => $attempts, 'next_retry_at' => null, 'error_text' => $message));
            return;
        }
        $delay = min(3600, 60 * (2 ** ($attempts - 1)));
        $retry_at = gmdate('Y-m-d H:i:s', time() + $delay);
        $this->update_item($id, array('status' => 'retry_wait', 'attempts' => $attempts, 'next_retry_at' => $retry_at, 'error_text' => $message));
        wp_schedule_single_event(time() + $delay + 5, self::CRON_HOOK, array('retry', (int) $id, $attempts));
    }

    private function store_last_run($summary) {
        update_option(self::LAST_RUN_OPTION, $summary, false);
    }

    private function refresh_processing_lock($token) {
        $current = get_option(self::PROCESSING_LOCK_OPTION, array());
        if (!is_array($current) || empty($current['token']) || !hash_equals((string) $current['token'], (string) $token)) {
            return false;
        }
        // Compare-and-swap the serialized option value. This prevents an old
        // worker from overwriting a newer owner's lease after stale recovery.
        $previous = $current;
        $current['created_at'] = microtime(true);
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->options,
            array('option_value' => maybe_serialize($current)),
            array(
                'option_name' => self::PROCESSING_LOCK_OPTION,
                'option_value' => maybe_serialize($previous),
            ),
            array('%s'),
            array('%s', '%s')
        );
        if ($updated === 1) {
            wp_cache_delete(self::PROCESSING_LOCK_OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            return true;
        }
        $verified = get_option(self::PROCESSING_LOCK_OPTION, array());
        return is_array($verified)
            && !empty($verified['token'])
            && hash_equals((string) $verified['token'], (string) $token)
            && isset($verified['created_at'])
            && (float) $verified['created_at'] >= (float) $current['created_at'];
    }
}
