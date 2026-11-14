<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Feeds {
    /** @var array{desk_lane:string,feed_url:string} */
    private $editorial_fetch_scope = array('desk_lane' => '', 'feed_url' => '');

    /**
     * Serialize manual and scheduled runs. add_option() is atomic at the
     * database level, unlike a transient-based check-then-set lock.
     */
    public function process_feeds($mode = null) {
        $args = array();
        if (is_array($mode)) {
            $args = $mode;
        } elseif (is_string($mode) && $mode !== '') {
            $args['reason'] = $mode;
        }
        $foreground = !empty($args['foreground']);
        $summary = array(
            'at' => time(),
            'reason' => sanitize_key($args['reason'] ?? ($foreground ? 'manual' : 'scheduled')),
            'feeds_checked' => 0,
            'feed_errors' => array(),
            'new_items' => 0,
            'processed' => 0,
            'drafts' => 0,
            'published' => 0,
            'quarantined' => 0,
            'skipped' => '',
        );
        $now = time();
        $lock = array('created_at' => $now, 'token' => wp_generate_uuid4());

        if (!add_option(self::PROCESSING_LOCK_OPTION, $lock, '', false)) {
            $existing = get_option(self::PROCESSING_LOCK_OPTION, array());
            $created_at = is_array($existing) ? absint($existing['created_at'] ?? 0) : 0;
            $lock_age = $created_at ? ($now - $created_at) : self::PROCESSING_LOCK_TTL + 1;
            $can_steal = $foreground ? $lock_age > 200 : ($lock_age > self::PROCESSING_LOCK_TTL);

            if (!$can_steal) {
                $summary['skipped'] = $foreground ? 'A run is already in progress. Wait a minute and try again.' : 'locked';
                $this->store_last_run($summary);
                $this->maybe_schedule_next_clock_cron($args);
                $this->reschedule_continue_if_blocked($args);
                return $summary;
            }

            // Delete only the exact stale value we inspected. A concurrent
            // heartbeat changes option_value and makes this conditional delete fail.
            global $wpdb;
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                self::PROCESSING_LOCK_OPTION,
                maybe_serialize($existing)
            ));
            if (!$deleted) {
                $summary['skipped'] = 'Could not take over a previous run lock.';
                $this->store_last_run($summary);
                $this->maybe_schedule_next_clock_cron($args);
                $this->reschedule_continue_if_blocked($args);
                return $summary;
            }
            wp_cache_delete(self::PROCESSING_LOCK_OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            if (!add_option(self::PROCESSING_LOCK_OPTION, $lock, '', false)) {
                $summary['skipped'] = 'Could not start a run because another worker took the lock.';
                $this->store_last_run($summary);
                $this->maybe_schedule_next_clock_cron($args);
                $this->reschedule_continue_if_blocked($args);
                return $summary;
            }
        }

        try {
            $this->process_feeds_locked($lock['token'], $args, $summary);
        } finally {
            $current = get_option(self::PROCESSING_LOCK_OPTION, array());
            if (is_array($current) && !empty($current['token']) && hash_equals($lock['token'], (string) $current['token'])) {
                global $wpdb;
                $released = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                    self::PROCESSING_LOCK_OPTION,
                    maybe_serialize($current)
                ));
                if ($released) {
                    wp_cache_delete(self::PROCESSING_LOCK_OPTION, 'options');
                    wp_cache_delete('alloptions', 'options');
                }
            }
        }
        $this->store_last_run($summary);
        $this->maybe_schedule_next_clock_cron($args);
        return $summary;
    }

    /**
     * After a no-arg scheduled tick, queue the next clock slot. Retry/continue
     * events carry args and must not steal this reschedule.
     */
    private function maybe_schedule_next_clock_cron($args) {
        if (!empty($args['foreground'])) {
            return;
        }
        $reason = sanitize_key($args['reason'] ?? 'scheduled');
        if ($reason !== 'scheduled') {
            return;
        }
        self::ensure_clock_cron();
    }

    private function process_feeds_locked($lock_token, $args = array(), &$summary = null) {
        if (!is_array($summary)) {
            $summary = array();
        }
        foreach (array('feeds_checked', 'new_items', 'processed', 'drafts', 'published', 'quarantined') as $key) {
            if (!isset($summary[$key])) {
                $summary[$key] = 0;
            }
        }
        if (!isset($summary['feed_errors']) || !is_array($summary['feed_errors'])) {
            $summary['feed_errors'] = array();
        }
        $this->maybe_upgrade_schema();
        $this->maybe_backfill_home_media();
        $this->bind_editorial_fetch_scope($args);
        try {
            $this->process_feeds_locked_scoped($lock_token, $args, $summary);
        } finally {
            $this->bind_editorial_fetch_scope(array());
        }
    }

    private function process_feeds_locked_scoped($lock_token, $args, &$summary) {
        $foreground = !empty($args['foreground']);
        if (!function_exists('fetch_feed')) {
            require_once ABSPATH . WPINC . '/feed.php';
        }
        $s = $this->settings();
        if (!$this->api_key()) {
            $summary['skipped'] = 'No API key is configured.';
            return;
        }
        if ($this->remaining_posts_today($s) <= 0) {
            $summary['skipped'] = 'Daily post cap is 0 or already used. Raise Maximum posts per day and run again.';
            return;
        }
        $processed_this_run = 0;
        $filled_this_run = 0;
        $run_limit = (int) $s['max_items_per_run'];
        $started_at = microtime(true);
        // Foreground Fetch now needs room for several writer+reviewer passes;
        // ~6 validations used to exhaust the old 90s budget and look like a stop.
        $time_budget_seconds = $foreground ? 180 : 25;
        $reason = sanitize_key($args['reason'] ?? '');
        $this->note_background_progress(12, $reason === 'continue' ? 'Continuing unfinished items' : $this->fetch_scope_label($s));
        $this->ingest_new_feed_items($s, $summary);
        $this->note_background_progress(20, 'Feeds checked');
        $touched = array();
        $reingested = false;
        while ((microtime(true) - $started_at) < $time_budget_seconds) {
            if (!$this->refresh_processing_lock($lock_token)) {
                return;
            }
            if ($this->remaining_posts_today($s) <= 0) {
                return;
            }
            if ($filled_this_run >= max(1, $run_limit)) {
                $summary['stopped'] = 'Stopped after ' . (int) $filled_this_run . ' posts this run. Stories are still waiting.';
                $this->schedule_editorial_continue($touched);
                return;
            }
            $waiting_id = $this->next_pipeline_item_id($touched);
            if ($waiting_id < 1) {
                if (!$reingested && $this->remaining_posts_today($s) > 0) {
                    $reingested = true;
                    $this->note_background_progress(22, 'Looking for more feed stories');
                    $this->ingest_new_feed_items($s, $summary);
                    continue;
                }
                break;
            }
            if ($foreground) {
                $this->advance_item_until_idle((int) $waiting_id, $s, 5, $started_at, $time_budget_seconds, $lock_token);
            } else {
                $this->process_item((int) $waiting_id, $s, false);
            }
            $touched[(int) $waiting_id] = true;
            $processed_this_run++;
            $summary['processed'] = $processed_this_run;
            $this->note_background_progress(
                20 + (int) (70 * max($filled_this_run, 1) / max(1, $run_limit)),
                'Validating story ' . $processed_this_run
            );
            $row = $this->get_item((int) $waiting_id);
            $status = (string) ($row['status'] ?? '');
            if ($status === 'draft') {
                $summary['drafts']++;
                $filled_this_run++;
            } elseif ($status === 'published') {
                $summary['published']++;
                $filled_this_run++;
            } elseif ($status === 'quarantined') {
                $summary['quarantined']++;
            }
        }
        if ($this->remaining_posts_today($s) > 0) {
            $this->schedule_editorial_continue($touched);
        }
    }

    private function normalize_fetch_scope($args) {
        $args = is_array($args) ? $args : array();
        $lane = sanitize_key((string) ($args['desk_lane'] ?? ''));
        if (!in_array($lane, array('tech', 'comics'), true)) {
            $lane = '';
        }
        $feed = esc_url_raw((string) ($args['feed_url'] ?? ''), array('http', 'https'));
        if ($feed !== '' && !wp_http_validate_url($feed)) {
            $feed = '';
        }
        return array('desk_lane' => $lane, 'feed_url' => $feed);
    }

    private function bind_editorial_fetch_scope($args) {
        $this->editorial_fetch_scope = $this->normalize_fetch_scope($args);
    }

    private function fetch_scope_label($s = null) {
        $scope = $this->editorial_fetch_scope;
        $feed = (string) ($scope['feed_url'] ?? '');
        $lane = (string) ($scope['desk_lane'] ?? '');
        if ($feed !== '') {
            $tenant = $this->tenant_for_feed($feed, $s);
            $name = is_array($tenant) ? trim((string) ($tenant['name'] ?? '')) : '';
            return 'Fetching ' . ($name !== '' ? $name : $feed);
        }
        if ($lane === 'tech') {
            return 'Fetching tech feeds';
        }
        if ($lane === 'comics') {
            return 'Fetching comics feeds';
        }
        return 'Fetching feeds';
    }

    private function tenant_in_fetch_scope($tenant) {
        $scope = $this->editorial_fetch_scope;
        $feed = (string) ($scope['feed_url'] ?? '');
        $lane = (string) ($scope['desk_lane'] ?? '');
        if ($feed === '' && $lane === '') {
            return true;
        }
        $url = esc_url_raw(is_array($tenant) ? ($tenant['url'] ?? '') : '', array('http', 'https'));
        if ($feed !== '' && $url !== $feed) {
            return false;
        }
        if ($lane !== '' && $this->tenant_desk_lane($tenant) !== $lane) {
            return false;
        }
        return true;
    }

    private function item_in_fetch_scope($item) {
        $scope = $this->editorial_fetch_scope;
        $feed = (string) ($scope['feed_url'] ?? '');
        $lane = (string) ($scope['desk_lane'] ?? '');
        if ($feed === '' && $lane === '') {
            return true;
        }
        $item = is_array($item) ? $item : array();
        $url = esc_url_raw((string) ($item['feed_url'] ?? ''), array('http', 'https'));
        if ($feed !== '' && $url !== $feed) {
            return false;
        }
        if ($lane !== '' && $this->item_desk_lane($item) !== $lane) {
            return false;
        }
        return true;
    }

    /** Keep filling today's post cap after a time or item-limit stop. */
    private function schedule_editorial_continue($exclude = array()) {
        $scope = is_array($this->editorial_fetch_scope) ? $this->editorial_fetch_scope : array();
        $waiting_id = $this->next_pipeline_item_id($exclude);
        if ($waiting_id < 1) {
            return;
        }
        $row = $this->get_item($waiting_id);
        if (!$this->pipeline_item_is_unfinished($row)) {
            return;
        }
        // A single-feed or desk fetch used to stop here, so the queue sat
        // after the per-run limit (often 10) with stories still pending.
        wp_schedule_single_event(time() + 15, self::CRON_HOOK, array(array(
            'reason' => 'continue',
            'desk_lane' => (string) ($scope['desk_lane'] ?? ''),
            'feed_url' => (string) ($scope['feed_url'] ?? ''),
        )));
    }

    /** A continue that hit a live lock must come back, or the queue stops at the per-run limit. */
    private function reschedule_continue_if_blocked($args) {
        if (sanitize_key($args['reason'] ?? '') !== 'continue') {
            return;
        }
        $this->bind_editorial_fetch_scope($args);
        $this->schedule_editorial_continue();
        $this->bind_editorial_fetch_scope(array());
    }

    /** Next editorial cron, including a continue that carries a feed scope. */
    private function next_editorial_cron_timestamp() {
        $crons = function_exists('_get_cron_array') ? _get_cron_array() : array();
        if (!is_array($crons)) {
            $next = wp_next_scheduled(self::CRON_HOOK);
            return $next ? (int) $next : 0;
        }
        foreach ($crons as $timestamp => $hooks) {
            if (!empty($hooks[self::CRON_HOOK])) {
                return (int) $timestamp;
            }
        }
        return 0;
    }

    private function ingest_new_feed_items($s, &$summary) {
        $new_ids = array();
        $lifetime = function () {
            return 5 * MINUTE_IN_SECONDS;
        };
        add_filter('wp_feed_cache_transient_lifetime', $lifetime);
        add_filter('http_headers_useragent', array($this, 'feed_user_agent'));
        add_filter('wp_feed_options', array($this, 'configure_feed'), 10, 2);
        foreach ($this->enabled_tenants($s) as $tenant) {
            if (!$this->tenant_in_fetch_scope($tenant)) {
                continue;
            }
            $feed_url = esc_url_raw($tenant['url'] ?? '', array('http', 'https'));
            if (!$feed_url || !wp_http_validate_url($feed_url)) {
                continue;
            }
            $summary['feeds_checked']++;
            $feed_name = sanitize_text_field($tenant['name'] ?? '');
            if ($feed_name === '') {
                $host = wp_parse_url($feed_url, PHP_URL_HOST);
                $feed_name = is_string($host) ? $host : $feed_url;
            }
            $entries = $this->read_feed_entries($feed_url, $summary, $feed_name);
            foreach ($entries as $entry) {
                $url = esc_url_raw($entry['url'] ?? '', array('http', 'https'));
                if (!$url || !wp_http_validate_url($url)) {
                    continue;
                }
                $title = sanitize_text_field($entry['title'] ?? '');
                $image = $this->source_image_url((string) ($entry['body'] ?? ''));
                $body = wp_strip_all_tags((string) ($entry['body'] ?? ''), true);
                $body = trim(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8'));
                $body = function_exists('mb_substr') ? mb_substr($body, 0, (int) $s['max_source_chars']) : substr($body, 0, (int) $s['max_source_chars']);
                if ($image !== '') {
                    $body = '<img src="' . esc_url($image) . '" alt="" /> ' . $body;
                }
                if ($body === '' || $title === '') {
                    continue;
                }
                $guid = sanitize_text_field(($entry['guid'] ?? '') !== '' ? $entry['guid'] : $url);
                $key = hash('sha256', $feed_url . '|' . $url);
                if ($this->has_item($key) || $this->has_source_url($url)) {
                    continue;
                }
                $assignment = $this->experiment_assignment($key, $s);
                $id = $this->insert_item($key, $assignment['experiment_id'], $assignment['variant'], $feed_url, $feed_name, $guid, $url, $title, $body, $entry['published_at'] ?? '');
                if ($id) {
                    $new_ids[] = (int) $id;
                    $summary['new_items']++;
                }
            }
        }
        remove_filter('wp_feed_cache_transient_lifetime', $lifetime);
        remove_filter('http_headers_useragent', array($this, 'feed_user_agent'));
        remove_filter('wp_feed_options', array($this, 'configure_feed'), 10);
        return $new_ids;
    }

    public function feed_user_agent() {
        return 'TechnoRapperAIWire/' . self::VERSION . '; ' . home_url('/');
    }

    public function configure_feed($feed) {
        if (is_object($feed) && method_exists($feed, 'set_timeout')) {
            $feed->set_timeout(20);
        }
        if (is_object($feed) && method_exists($feed, 'set_useragent')) {
            $feed->set_useragent($this->feed_user_agent());
        }
        if (is_object($feed) && method_exists($feed, 'force_feed')) {
            $feed->force_feed(true);
        }
        return $feed;
    }

    private function read_feed_entries($feed_url, &$summary, $feed_name) {
        $feed = fetch_feed($feed_url);
        $raw = '';
        $simplepie_entries = array();
        if (!is_wp_error($feed)) {
            if (method_exists($feed, 'get_raw_data')) {
                $raw = (string) $feed->get_raw_data();
            }
            if (method_exists($feed, 'get_items')) {
                foreach ((array) $feed->get_items() as $item) {
                    if (!is_object($item)) {
                        continue;
                    }
                    $body = $item->get_content() ?: $item->get_description();
                    if ($this->source_image_url($body) === '' && method_exists($item, 'get_enclosure')) {
                        $enc = $item->get_enclosure();
                        $link = ($enc && method_exists($enc, 'get_link')) ? (string) $enc->get_link() : '';
                        if ($link !== '') {
                            $body = '<img src="' . esc_url($link) . '" alt="" /> ' . $body;
                        }
                    }
                    $simplepie_entries[] = array(
                        'title' => $item->get_title(),
                        'url' => $item->get_permalink(),
                        'guid' => $item->get_id(),
                        'body' => $body,
                        'published_at' => method_exists($item, 'get_date') ? (string) $item->get_date('Y-m-d H:i:s') : '',
                    );
                }
            }
        } else {
            $summary['feed_errors'][] = $feed_name . ': ' . $feed->get_error_message();
        }
        if ($raw === '') {
            $response = wp_remote_get($feed_url, array(
                'timeout' => 20,
                'redirection' => 3,
                'user-agent' => $this->feed_user_agent(),
            ));
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 300) {
                $raw = (string) wp_remote_retrieve_body($response);
            } elseif (empty($simplepie_entries) && is_wp_error($response)) {
                $summary['feed_errors'][] = $feed_name . ': ' . $response->get_error_message();
            }
        }
        $parsed = $raw !== '' ? $this->parse_feed_xml($raw) : array();
        $entries = count($parsed) >= count($simplepie_entries) ? $parsed : $simplepie_entries;
        return array_slice($entries, 0, 50);
    }

    private function parse_feed_xml($xml) {
        $xml = trim((string) $xml);
        if ($xml === '' || strpos($xml, '<') === false) {
            return array();
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return array();
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
        $nodes = $xpath->query('//atom:entry');
        if (!$nodes || $nodes->length === 0) {
            $nodes = $xpath->query('//*[local-name()="entry"]');
        }
        if (!$nodes || $nodes->length === 0) {
            $nodes = $xpath->query('//item');
        }
        $entries = array();
        if (!$nodes) {
            return $entries;
        }
        foreach ($nodes as $node) {
            $title = $this->xpath_text($xpath, './/*[local-name()="title"]', $node);
            $url = $this->atom_entry_link($xpath, $node);
            if ($url === '') {
                $url = $this->xpath_text($xpath, './/*[local-name()="link"]', $node);
            }
            $guid = $this->xpath_text($xpath, './/*[local-name()="id"]', $node);
            if ($guid === '') {
                $guid = $this->xpath_text($xpath, './/*[local-name()="guid"]', $node);
            }
            $body = $this->xpath_text($xpath, './/*[local-name()="encoded"]', $node);
            if ($body === '') {
                $body = $this->xpath_text($xpath, './/*[local-name()="content"]', $node);
            }
            if ($body === '') {
                $body = $this->xpath_text($xpath, './/*[local-name()="summary"]', $node);
            }
            if ($body === '') {
                $body = $this->xpath_text($xpath, './/*[local-name()="description"]', $node);
            }
            if ($title === '' || $url === '') {
                continue;
            }
            $published = $this->xpath_text($xpath, './/*[local-name()="published"]', $node);
            if ($published === '') {
                $published = $this->xpath_text($xpath, './/*[local-name()="updated"]', $node);
            }
            if ($published === '') {
                $published = $this->xpath_text($xpath, './/*[local-name()="pubDate"]', $node);
            }
            if ($published === '') {
                $published = $this->xpath_text($xpath, './/*[local-name()="date"]', $node);
            }
            $body = $this->feed_entry_image_markup($xpath, $node, $body);
            $entries[] = array(
                'title' => $title,
                'url' => $url,
                'guid' => $guid,
                'body' => $body,
                'published_at' => $published,
            );
        }
        return $entries;
    }

    private function atom_entry_link($xpath, $node) {
        $queries = array(
            './/*[local-name()="link"][@rel="alternate"]/@href',
            './/*[local-name()="link"][not(@rel)]/@href',
            './/*[local-name()="link"]/@href',
        );
        foreach ($queries as $query) {
            $found = $xpath->query($query, $node);
            if ($found && $found->length) {
                $href = trim((string) $found->item(0)->nodeValue);
                if ($href !== '') {
                    return $href;
                }
            }
        }
        return '';
    }

    private function feed_entry_image_markup($xpath, $node, $body) {
        $body = (string) $body;
        if ($this->source_image_url($body) !== '') {
            return $body;
        }
        foreach (array(
            array('.//*[local-name()="enclosure"]', 'url'),
            array('.//*[local-name()="thumbnail"]', 'url'),
            array('.//*[local-name()="content"][@url]', 'url'),
        ) as $spec) {
            $found = $xpath->query($spec[0], $node);
            if (!$found || !$found->length) {
                continue;
            }
            $url = esc_url_raw(trim((string) $found->item(0)->getAttribute($spec[1])));
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                return '<img src="' . esc_attr($url) . '" alt="" /> ' . $body;
            }
        }
        return $body;
    }

    private function xpath_text($xpath, $query, $node) {
        $found = $xpath->query($query, $node);
        if (!$found || !$found->length) {
            return '';
        }
        return trim((string) $found->item(0)->textContent);
    }
}
