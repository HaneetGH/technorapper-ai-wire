<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opt-in distribution of published AI Wire articles to X and Instagram.
 * Each destination is tracked independently so a retry on one platform never
 * repeats a successful post on the other.
 */
trait TechnoRapper_AI_Wire_Social {
    private function social_table() {
        global $wpdb;
        return $wpdb->prefix . 'tr_ai_wire_social';
    }

    private function social_credentials() {
        return wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), array(
            'x_user_access_token' => '',
            'instagram_user_access_token' => '',
            'instagram_user_id' => '',
        ));
    }

    public static function connected_social_platforms($credentials = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), array(
                'x_user_access_token' => '',
                'instagram_user_access_token' => '',
                'instagram_user_id' => '',
            ));
        }
        $platforms = array();
        if (!empty($credentials['x_user_access_token'])) {
            $platforms[] = 'x';
        }
        if (!empty($credentials['instagram_user_access_token']) && !empty($credentials['instagram_user_id'])) {
            $platforms[] = 'instagram';
        }
        return $platforms;
    }

    public static function social_credentials_connected($credentials = null) {
        return (bool) self::connected_social_platforms($credentials);
    }

    public function social_distribution_ready() {
        return !empty($this->settings()['social_distribution_enabled']) && self::social_credentials_connected();
    }

    public function save_social_credentials() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage social publishing credentials.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_save_social_credentials');
        $current = $this->social_credentials();
        $input = isset($_POST['social']) && is_array($_POST['social']) ? wp_unslash($_POST['social']) : array();
        $next = $current;
        foreach (array('x_user_access_token', 'instagram_user_access_token') as $field) {
            if (!empty($_POST['remove_' . $field])) {
                $next[$field] = '';
            } elseif (isset($input[$field]) && trim((string) $input[$field]) !== '') {
                $next[$field] = trim(sanitize_text_field((string) $input[$field]));
            }
        }
        if (isset($input['instagram_user_id'])) {
            $next['instagram_user_id'] = preg_replace('/[^0-9]/', '', (string) $input['instagram_user_id']);
        }
        if (empty($next['instagram_user_access_token'])) {
            $next['instagram_user_id'] = '';
        }
        if (get_option(self::SOCIAL_CREDENTIALS_OPTION, null) === null) {
            add_option(self::SOCIAL_CREDENTIALS_OPTION, $next, '', false);
        } else {
            update_option(self::SOCIAL_CREDENTIALS_OPTION, $next, false);
        }
        $now_connected = self::social_credentials_connected($next);
        $was_connected = self::social_credentials_connected($current);
        if (!empty($this->settings()['social_distribution_enabled']) && $now_connected) {
            if (!wp_next_scheduled(self::SOCIAL_CRON_HOOK)) {
                wp_schedule_single_event(time() + 5, self::SOCIAL_CRON_HOOK);
            }
            $this->queue_site_context_job(!$was_connected);
        }
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire&social_credentials_saved=1'));
        exit;
    }

    private function site_context_defaults() {
        return array(
            'status' => 'idle',
            'started_at' => '',
            'completed_at' => '',
            'fingerprint' => '',
            'error' => '',
            'profile' => array(),
        );
    }

    private function site_context_record() {
        $stored = get_option(self::SOCIAL_CONTEXT_OPTION, array());
        return wp_parse_args(is_array($stored) ? $stored : array(), $this->site_context_defaults());
    }

    private function save_site_context_record($record) {
        $record = wp_parse_args(is_array($record) ? $record : array(), $this->site_context_defaults());
        if (get_option(self::SOCIAL_CONTEXT_OPTION, null) === null) {
            add_option(self::SOCIAL_CONTEXT_OPTION, $record, '', false);
            return;
        }
        update_option(self::SOCIAL_CONTEXT_OPTION, $record, false);
    }

    private function site_context_fingerprint($snapshot = null) {
        $snapshot = is_array($snapshot) ? $snapshot : $this->gather_site_context_snapshot();
        $key = array(
            $snapshot['name'] ?? '',
            $snapshot['tagline'] ?? '',
            $snapshot['url'] ?? '',
            implode('|', (array) ($snapshot['desks'] ?? array())),
            implode('|', (array) ($snapshot['recent_titles'] ?? array())),
        );
        return hash('sha256', wp_json_encode($key));
    }

    public function site_context_needs_refresh($record = null) {
        $record = is_array($record) ? $record : $this->site_context_record();
        $status = (string) ($record['status'] ?? 'idle');
        if ($status === 'queued' || $status === 'running') {
            $started = strtotime((string) ($record['started_at'] ?? '') . ' UTC');
            if ($started && $started > time() - 900) {
                return false;
            }
            return true;
        }
        if ($status !== 'ready' || empty($record['profile']) || empty($record['completed_at'])) {
            return true;
        }
        $completed = strtotime((string) $record['completed_at'] . ' UTC');
        if (!$completed || $completed < time() - self::SOCIAL_CONTEXT_TTL) {
            return true;
        }
        return ($record['fingerprint'] ?? '') !== $this->site_context_fingerprint();
    }

    public function queue_site_context_job($force = false) {
        if (!$this->social_distribution_ready()) {
            return false;
        }
        $record = $this->site_context_record();
        if (!$force && !$this->site_context_needs_refresh($record)) {
            return false;
        }
        $record['status'] = 'queued';
        $record['started_at'] = gmdate('Y-m-d H:i:s');
        $record['error'] = '';
        $this->save_site_context_record($record);
        if (!wp_next_scheduled(self::SOCIAL_CONTEXT_CRON_HOOK)) {
            wp_schedule_single_event(time() + 2, self::SOCIAL_CONTEXT_CRON_HOOK);
        }
        return true;
    }

    public function refresh_site_context_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to refresh site context.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_refresh_site_context');
        $queued = $this->queue_site_context_job(true);
        $page = sanitize_key($_POST['redirect_page'] ?? 'tr-ai-wire');
        if ($page !== 'tr-ai-wire-social') {
            $page = 'tr-ai-wire';
        }
        $url = admin_url('admin.php?page=' . $page);
        if ($queued) {
            $url = add_query_arg('site_context', 'learning', $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public function process_site_context() {
        $settings = $this->settings();
        if (!$this->social_distribution_ready()) {
            $record = $this->site_context_record();
            if (in_array((string) ($record['status'] ?? ''), array('queued', 'running'), true) && empty($record['completed_at'])) {
                $record['status'] = 'idle';
                $record['error'] = '';
                $this->save_site_context_record($record);
            }
            return;
        }
        $record = $this->site_context_record();
        if (($record['status'] ?? '') === 'running') {
            $started = strtotime((string) ($record['started_at'] ?? '') . ' UTC');
            if ($started && $started > time() - 900) {
                return;
            }
        }
        $record['status'] = 'running';
        $record['started_at'] = gmdate('Y-m-d H:i:s');
        $record['error'] = '';
        $this->save_site_context_record($record);

        $snapshot = $this->gather_site_context_snapshot();
        $fingerprint = $this->site_context_fingerprint($snapshot);
        $learned = $this->learn_site_context($snapshot, $settings);
        if (is_wp_error($learned)) {
            $record['status'] = 'error';
            $record['error'] = $learned->get_error_message();
            $this->save_site_context_record($record);
            return;
        }
        $record['status'] = 'ready';
        $record['completed_at'] = gmdate('Y-m-d H:i:s');
        $record['fingerprint'] = $fingerprint;
        $record['profile'] = $learned;
        $record['error'] = '';
        $this->save_site_context_record($record);
    }

    private function gather_site_context_snapshot() {
        $desks = array();
        $cats = get_categories(array('hide_empty' => true, 'number' => 12, 'orderby' => 'count', 'order' => 'DESC'));
        if (is_array($cats)) {
            foreach ($cats as $cat) {
                if (!empty($cat->name)) {
                    $desks[] = (string) $cat->name;
                }
            }
        }
        $recent_titles = array();
        $recent = array();
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 12,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ));
        foreach ($posts as $post) {
            $recent_titles[] = (string) $post->post_title;
            $recent[] = array(
                'title' => (string) $post->post_title,
                'excerpt' => wp_trim_words(wp_strip_all_tags((string) ($post->post_excerpt ?: $post->post_content)), 24, ''),
            );
        }
        $about = '';
        $about_posts = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'name' => 'about',
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ));
        if ($about_posts) {
            $about = wp_trim_words(wp_strip_all_tags((string) $about_posts[0]->post_content), 80, '');
        }
        return array(
            'name' => (string) get_bloginfo('name'),
            'tagline' => (string) get_bloginfo('description'),
            'url' => home_url('/'),
            'language' => (string) get_bloginfo('language'),
            'desks' => $desks,
            'recent_titles' => $recent_titles,
            'recent_posts' => $recent,
            'about' => $about,
        );
    }

    private function learn_site_context($snapshot, $s) {
        if (!$this->social_distribution_ready()) {
            return new WP_Error('tr_ai_wire_social_not_ready', 'Connect a social account and enable Social distribution before spending OpenAI tokens on site context.');
        }
        $system = 'You study one WordPress publisher so later social posts stay in that site\'s context. Use only the supplied snapshot. Do not invent coverage areas, products, or a different brand. Return JSON only.';
        $user = 'Read this WordPress site and describe what it is, who it is for, and what later social copy should prioritize. '
            . 'Priorities should match the live desks and recent stories, not a generic newsroom. Voice should match a technology editorial desk, not an ad agency. '
            . 'visual_style should describe photoreal Instagram news-poster photography (lighting, setting, mood), not a navy illustration kit. '
            . "Site snapshot:\n" . wp_json_encode($snapshot)
            . "\nReturn JSON keys: site_name, one_line, audience, desks (array), voice, priorities (array of up to 6 short phrases), avoid (array), cta_style, visual_style.";
        $copy_settings = $s;
        $copy_settings['max_output_tokens'] = min(900, max(400, (int) ($s['max_output_tokens'] ?? 900)));
        $parsed = $this->ai_json($s['writer_model'] ?? 'gpt-6-luna', $system, $user, $copy_settings, false);
        if (is_wp_error($parsed)) {
            return $parsed;
        }
        return $this->sanitize_site_context_profile($parsed, $snapshot);
    }

    private function sanitize_site_context_profile($raw, $snapshot) {
        $raw = is_array($raw) ? $raw : array();
        $list = function ($value, $limit) {
            $out = array();
            foreach ((array) $value as $item) {
                $item = sanitize_text_field((string) $item);
                if ($item !== '') {
                    $out[] = $item;
                }
                if (count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        };
        $name = sanitize_text_field((string) ($raw['site_name'] ?? ($snapshot['name'] ?? '')));
        $one_line = sanitize_text_field((string) ($raw['one_line'] ?? ($snapshot['tagline'] ?? '')));
        return array(
            'site_name' => $name !== '' ? $name : 'This WordPress site',
            'one_line' => $one_line,
            'audience' => sanitize_text_field((string) ($raw['audience'] ?? '')),
            'desks' => $list($raw['desks'] ?? ($snapshot['desks'] ?? array()), 8),
            'voice' => sanitize_text_field((string) ($raw['voice'] ?? '')),
            'priorities' => $list($raw['priorities'] ?? array(), 6),
            'avoid' => $list($raw['avoid'] ?? array(), 6),
            'cta_style' => sanitize_text_field((string) ($raw['cta_style'] ?? 'Read the full story on this site')),
            'visual_style' => sanitize_text_field((string) ($raw['visual_style'] ?? '')),
        );
    }

    private function site_context_profile() {
        $record = $this->site_context_record();
        return (($record['status'] ?? '') === 'ready' && is_array($record['profile'] ?? null)) ? $record['profile'] : array();
    }

    public function site_context_view() {
        $record = $this->site_context_record();
        $needs = $this->site_context_needs_refresh($record);
        $status = (string) ($record['status'] ?? 'idle');
        $enabled = !empty($this->settings()['social_distribution_enabled']);
        $connected = self::social_credentials_connected();
        if (!$connected) {
            $state = 'idle';
            $title = 'Connect X or Instagram first';
            $body = 'Onboard a social account in the connections card. Until then we do not run the site-context job and we do not spend OpenAI tokens on social copy or images.';
        } elseif (!$enabled) {
            $state = 'idle';
            $title = 'We will understand this site when social is on';
            $body = 'Turn on Social distribution and AI Wire will read this WordPress site — name, desks, recent stories, and voice — so every later social post stays in your context.';
        } elseif ($status === 'queued' || $status === 'running' || ($enabled && empty($record['completed_at']) && $status !== 'error')) {
            $state = 'learning';
            $title = 'We are understanding you';
            $body = 'AI Wire is reading this WordPress site so everything we write and design for social stays in your context. This runs in the background and does not change published articles.';
        } elseif ($status === 'error') {
            $state = 'error';
            $title = 'We could not finish understanding this site';
            $body = $record['error'] !== '' ? (string) $record['error'] : 'The last context job failed. Refresh to try again. Social posts can still go out with a generic desk voice until this succeeds.';
        } elseif ($needs) {
            $state = 'stale';
            $title = 'Your site context should be refreshed';
            $body = 'The last read is older than 14 days, or the site name, tagline, desks, or recent stories have changed. Refresh so social copy matches who you are now.';
        } else {
            $state = 'ready';
            $profile = is_array($record['profile'] ?? null) ? $record['profile'] : array();
            $title = 'We understand this site';
            $one_line = trim((string) ($profile['one_line'] ?? ''));
            $body = $one_line !== '' ? $one_line : 'Later social posts will use this site\'s desks, audience, and voice.';
            if (!empty($profile['audience'])) {
                $body .= ' Audience: ' . $profile['audience'];
            }
        }
        $last = '';
        if (!empty($record['completed_at'])) {
            $ts = strtotime($record['completed_at'] . ' UTC');
            if ($ts) {
                $last = function_exists('wp_date') ? wp_date('j M Y, g:i A', $ts) : gmdate('j M Y, g:i A', $ts) . ' UTC';
            }
        }
        return array(
            'state' => $state,
            'title' => $title,
            'body' => $body,
            'last' => $last,
            'needs_refresh' => $needs || $state === 'idle' || $state === 'error' || $state === 'stale',
            'can_refresh' => $enabled && $connected,
        );
    }

    public function render_site_context_card($redirect_page = 'tr-ai-wire') {
        $view = $this->site_context_view();
        $colors = array(
            'idle' => '#646970',
            'learning' => '#dba617',
            'ready' => '#16803c',
            'stale' => '#dba617',
            'error' => '#b32d2e',
        );
        $color = $colors[$view['state']] ?? '#646970';
        echo '<div class="card" style="max-width:900px;margin:16px 0;padding:16px 20px;border-left:4px solid ' . esc_attr($color) . ';">';
        echo '<h2 style="margin-top:0;">' . esc_html($view['title']) . '</h2>';
        echo '<p>' . esc_html($view['body']) . '</p>';
        echo '<p><strong>Last understood:</strong> ' . ($view['last'] !== '' ? esc_html($view['last']) : 'Not yet') . '</p>';
        if ($view['state'] === 'ready' && !$view['needs_refresh']) {
            echo '<p class="description">Context is current. Refresh if you change the site title, tagline, or main desks.</p>';
        } elseif ($view['needs_refresh'] && $view['can_refresh']) {
            echo '<p class="description">Refresh recommended so the next social posts use an up-to-date read of this site.</p>';
        }
        if ($view['can_refresh']) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_refresh_site_context">';
            echo '<input type="hidden" name="redirect_page" value="' . esc_attr($redirect_page) . '">';
            wp_nonce_field('tr_ai_wire_refresh_site_context');
            submit_button($view['state'] === 'learning' ? 'Run again' : 'Refresh site context', 'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</div>';
    }

    public function queue_social_for_post($post_id, $post, $update, $post_before) {
        if (!($post instanceof WP_Post) || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            return;
        }
        if ($post_before instanceof WP_Post && $post_before->post_status === 'publish') {
            return;
        }
        if (wp_is_post_revision($post_id) || !get_post_meta($post_id, '_tr_ai_wire_queue_id', true)) {
            return;
        }
        $settings = $this->settings();
        if (empty($settings['social_distribution_enabled'])) {
            return;
        }
        $platforms = self::connected_social_platforms();
        if (!$platforms) {
            return;
        }

        global $wpdb;
        $table = $this->social_table();
        $now = current_time('mysql', true);
        foreach ($platforms as $platform) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (post_id, platform, status, created_at, updated_at) VALUES (%d, %s, 'queued', %s, %s)",
                (int) $post_id,
                $platform,
                $now,
                $now
            ));
        }
        if (!wp_next_scheduled(self::SOCIAL_CRON_HOOK)) {
            wp_schedule_single_event(time() + 30, self::SOCIAL_CRON_HOOK);
        }
    }

    public function process_social_queue() {
        $lock_name = 'tr_ai_wire_social_worker_lock';
        $lock_token = wp_generate_password(32, false, false);
        if (!add_option($lock_name, array('token' => $lock_token, 'time' => time()), '', false)) {
            $existing = get_option($lock_name, array());
            $lock_time = is_array($existing) ? (int) ($existing['time'] ?? 0) : (int) $existing;
            if ($lock_time && $lock_time < time() - 900) {
                delete_option($lock_name);
            }
            if (!add_option($lock_name, array('token' => $lock_token, 'time' => time()), '', false)) {
                return;
            }
        }
        try {
            $this->process_social_queue_locked();
        } finally {
            $existing = get_option($lock_name, array());
            if (is_array($existing) && ($existing['token'] ?? '') === $lock_token) {
                delete_option($lock_name);
            }
        }
    }

    private function process_social_queue_locked() {
        $settings = $this->settings();
        if (!$this->social_distribution_ready()) {
            return;
        }
        if ($this->site_context_needs_refresh()) {
            $this->queue_site_context_job(false);
        }
        $credentials = $this->social_credentials();
        $limit = min(10, max(1, (int) ($settings['social_daily_limit'] ?? 10)));
        if ($this->social_published_today() >= $limit) {
            global $wpdb;
            $table = $this->social_table();
            $pending_new = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} q WHERE q.status = 'queued' AND NOT EXISTS (SELECT 1 FROM {$table} p WHERE p.post_id = q.post_id AND p.status = 'published' AND p.updated_at >= UTC_DATE())");
            if ($pending_new > 0) {
                $tomorrow = strtotime('tomorrow 00:20:00 UTC');
                wp_schedule_single_event($tomorrow, self::SOCIAL_CRON_HOOK);
            }
        }

        global $wpdb;
        $table = $this->social_table();
        $wpdb->query("UPDATE {$table} SET status = 'needs_review', error_text = 'The worker stopped before recording an outcome. Check the platform before retrying.' WHERE status = 'processing' AND updated_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE");
        $this->requeue_instagram_prepublish_quota_failures();
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1", ARRAY_A);
        foreach ((array) $rows as $row) {
            if (empty($this->settings()['social_distribution_enabled'])) {
                break;
            }
            if ($this->social_published_today() >= $limit && !$this->social_post_published_today((int) $row['post_id'])) {
                $tomorrow = strtotime('tomorrow 00:20:00 UTC');
                wp_schedule_single_event($tomorrow, self::SOCIAL_CRON_HOOK);
                break;
            }
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'queued'",
                current_time('mysql', true),
                (int) $row['id']
            ));
            if ($claimed !== 1) {
                continue;
            }
            $result = $this->publish_social_item($row, $credentials, $settings);
            if (is_wp_error($result)) {
                if ($result->get_error_code() === 'tr_ai_wire_social_image_busy') {
                    $wpdb->update($table, array('status' => 'queued', 'updated_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
                    wp_schedule_single_event(time() + 60, self::SOCIAL_CRON_HOOK);
                    continue;
                }
                if ($result->get_error_code() === 'tr_ai_wire_ig_quota_full') {
                    $wpdb->update($table, array('status' => 'queued', 'error_text' => $result->get_error_message(), 'updated_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
                    wp_schedule_single_event(strtotime('+1 hour'), self::SOCIAL_CRON_HOOK);
                    continue;
                }
                // Do not auto-retry an ambiguous publish response: the platform
                // may have accepted the post before the connection failed.
                $this->finish_social_item($row['id'], 'needs_review', '', '', $result->get_error_message());
                continue;
            }
            $this->finish_social_item($row['id'], 'published', $result['id'], $result['url'], '');
        }

        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'queued'");
        $finishable = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} q WHERE q.status = 'queued' AND EXISTS (SELECT 1 FROM {$table} p WHERE p.post_id = q.post_id AND p.status = 'published' AND p.updated_at >= UTC_DATE())");
        if ($remaining > 0 && ($this->social_published_today() < $limit || $finishable > 0) && !wp_next_scheduled(self::SOCIAL_CRON_HOOK)) {
            wp_schedule_single_event(time() + 60, self::SOCIAL_CRON_HOOK);
        }
    }

    private function publish_social_item($row, $credentials, $settings) {
        $post = get_post((int) $row['post_id']);
        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error('tr_ai_wire_social_post', 'The source article is no longer published.');
        }
        $copy = $this->social_copy($post, $settings);
        $image_id = (int) get_post_meta($post->ID, '_tr_ai_wire_social_image_id', true);
        if (!$image_id || !get_attached_file($image_id)) {
            $lock_name = 'tr_ai_wire_social_image_lock_' . (int) $post->ID;
            if (!add_option($lock_name, time(), '', false)) {
                $lock_time = (int) get_option($lock_name, 0);
                if ($lock_time && $lock_time < time() - 900) {
                    delete_option($lock_name);
                }
                if (!add_option($lock_name, time(), '', false)) {
                    return new WP_Error('tr_ai_wire_social_image_busy', 'Another worker is preparing this article image.');
                }
            }
            try {
                $image_id = $this->generate_social_image($post, $settings, $copy);
                if (is_wp_error($image_id)) {
                    return $image_id;
                }
                update_post_meta($post->ID, '_tr_ai_wire_social_image_id', (int) $image_id);
            } finally {
                delete_option($lock_name);
            }
        }
        $image_path = get_attached_file($image_id);
        if (!$image_path || !file_exists($image_path)) {
            return new WP_Error('tr_ai_wire_social_image', 'The generated social image is missing from WordPress Media.');
        }
        global $wpdb;
        $wpdb->update($this->social_table(), array(
            'image_attachment_id' => (int) $image_id,
            'copy_json' => wp_json_encode($copy),
        ), array('id' => (int) $row['id']));
        if ($row['platform'] === 'x') {
            if (empty($credentials['x_user_access_token'])) {
                return new WP_Error('tr_ai_wire_x_missing_token', 'The X access token is no longer configured.');
            }
            return $this->publish_x_post($copy['x'], $image_path, $credentials['x_user_access_token']);
        }
        if ($row['platform'] === 'instagram') {
            if (empty($credentials['instagram_user_access_token']) || empty($credentials['instagram_user_id'])) {
                return new WP_Error('tr_ai_wire_ig_missing_token', 'The Instagram access token or account ID is no longer configured.');
            }
            return $this->publish_instagram_post($copy['instagram'], $copy['instagram_alt'], wp_get_attachment_url($image_id), $credentials);
        }
        return new WP_Error('tr_ai_wire_social_platform', 'Unknown social platform.');
    }

    private function social_copy($post, $settings = null) {
        $cached = get_post_meta($post->ID, '_tr_ai_wire_social_copy', true);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && !empty($decoded['x']) && !empty($decoded['instagram'])) {
                return $this->sanitize_social_copy($decoded, $post);
            }
        }
        $generated = $this->generate_social_copy($post, is_array($settings) ? $settings : $this->settings());
        if (!is_wp_error($generated) && is_array($generated)) {
            $copy = $this->sanitize_social_copy($generated, $post);
        } else {
            $copy = $this->sanitize_social_copy(array(), $post);
        }
        update_post_meta($post->ID, '_tr_ai_wire_social_copy', wp_json_encode($copy));
        return $copy;
    }

    private function social_article_snapshot($post) {
        $qid = (int) get_post_meta($post->ID, '_tr_ai_wire_queue_id', true);
        $item = $qid > 0 ? $this->get_item($qid) : null;
        $rewrite = array();
        if (is_array($item) && !empty($item['rewrite_json'])) {
            $decoded = json_decode($item['rewrite_json'], true);
            if (is_array($decoded)) {
                $rewrite = $decoded;
            }
        }
        $takeaways = array();
        foreach ((array) ($rewrite['key_takeaways'] ?? array()) as $takeaway) {
            $takeaway = sanitize_text_field((string) $takeaway);
            if ($takeaway !== '') {
                $takeaways[] = $takeaway;
            }
            if (count($takeaways) >= 3) {
                break;
            }
        }
        $tags = array();
        foreach ((array) ($rewrite['tags'] ?? array()) as $tag) {
            $tag = sanitize_text_field((string) $tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
            if (count($tags) >= 6) {
                break;
            }
        }
        $excerpt = trim(wp_strip_all_tags((string) $post->post_excerpt));
        if ($excerpt === '') {
            $excerpt = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 40, '');
        }
        return array(
            'title' => (string) $post->post_title,
            'lede' => sanitize_text_field((string) ($rewrite['lede'] ?? '')),
            'excerpt' => $excerpt,
            'url' => get_permalink($post),
            'category' => sanitize_text_field((string) ($rewrite['category'] ?? '')),
            'tags' => $tags,
            'social_title' => sanitize_text_field((string) ($rewrite['social_title'] ?? '')),
            'social_description' => sanitize_text_field((string) ($rewrite['social_description'] ?? '')),
            'key_takeaways' => $takeaways,
        );
    }

    private function generate_social_copy($post, $s) {
        if (!$this->social_distribution_ready()) {
            return new WP_Error('tr_ai_wire_social_not_ready', 'Connect a social account and enable Social distribution before spending OpenAI tokens on social copy.');
        }
        $snapshot = $this->social_article_snapshot($post);
        $profile = $this->site_context_profile();
        $brand = !empty($profile['site_name']) ? $profile['site_name'] : 'this site';
        $system = 'You are the social desk for ' . $brand . '. Repackage an already-published article for X and Instagram. Do not write a new article. Use only supplied article fields plus the site context. Never invent facts, dates, prices, quotes, tests, or “we tried it”. Never fake urgency or countdown deals. Return JSON only.';
        $user = 'Build one social package that matches this publisher\'s priorities. Principles to hit when the article supports them: hook that stops the scroll; curiosity; a feeling (stakes for a reader\'s phone/job/money, not melodrama); relatability; pattern interrupt; before/after contrast; a tiny story; useful value; a clear CTA to read the article; branding; shareability; saveability; cultural relevance when the source has it. '
            . 'on_image_headline: Instagram poster headline, 8–16 words, punchy, can include a number only if it is in the article. Written to sit in huge type on a photo. on_image_subhead: one supporting line, max 14 words. '
            . 'visual_brief: one photoreal cinematic scene for that headline (office, phone, factory, city, device close-up). Anonymous people from behind or in shadow are ok. No celebrities, no real brand logos, no screenshots, no letters or numbers in the artwork (type is overlaid later). '
            . 'x_text: hook plus one curiosity or value line; do not include the URL. instagram_caption: hook, 2–4 short lines of value/story, then CTA; do not include the URL. cta: tell them to read the full story on ' . $brand . '. hashtags: 3–5 specific tags for this site, no spam. instagram_alt: factual description of the graphic. ';
        if ($profile) {
            $user .= "Site context (follow this):\n" . wp_json_encode($profile) . "\n";
        }
        $user .= "Article JSON:\n" . wp_json_encode($snapshot)
            . "\nReturn JSON keys: hook, on_image_headline, on_image_subhead, visual_brief, cta, x_text, instagram_caption, instagram_alt, hashtags (array).";
        $copy_settings = $s;
        $copy_settings['max_output_tokens'] = min(1200, max(400, (int) ($s['max_output_tokens'] ?? 1200)));
        return $this->ai_json($s['writer_model'] ?? 'gpt-6-luna', $system, $user, $copy_settings, false);
    }

    private function sanitize_social_copy($raw, $post) {
        $raw = is_array($raw) ? $raw : array();
        $url = get_permalink($post);
        $title = trim((string) $post->post_title);
        $excerpt = trim(wp_strip_all_tags((string) $post->post_excerpt));
        if ($excerpt === '') {
            $excerpt = wp_trim_words(wp_strip_all_tags((string) $post->post_content), 36, '');
        }
        $hook = $this->clip_social_text($raw['hook'] ?? $title, 110);
        $headline = $this->clip_social_text($raw['on_image_headline'] ?? $hook, 96);
        $subhead = $this->clip_social_text($raw['on_image_subhead'] ?? $excerpt, 80);
        $profile = $this->site_context_profile();
        $default_cta = !empty($profile['cta_style']) ? $profile['cta_style'] : 'Read the full story on TechnoRapper';
        $cta = $this->clip_social_text($raw['cta'] ?? $default_cta, 60);
        $visual = $this->clip_social_text($raw['visual_brief'] ?? ('Photoreal cinematic scene of: ' . $title), 360);
        $alt = $this->clip_social_text($raw['instagram_alt'] ?? $headline, 1000);
        $hashtags = array();
        foreach ((array) ($raw['hashtags'] ?? array()) as $tag) {
            $tag = strtolower(trim((string) $tag));
            $tag = ltrim($tag, '#');
            $tag = preg_replace('/[^a-z0-9_]/', '', $tag);
            if ($tag !== '' && !in_array($tag, $hashtags, true)) {
                $hashtags[] = $tag;
            }
            if (count($hashtags) >= 5) {
                break;
            }
        }
        $x_body = $this->clip_social_block($raw['x_text'] ?? ($hook . ($excerpt !== '' ? "\n\n" . $excerpt : '')), 220);
        $x = $this->fit_social_with_url($x_body, $url, 270);
        $caption = trim((string) ($raw['instagram_caption'] ?? ($hook . "\n\n" . $excerpt)));
        $caption = $this->clip_social_block($caption, 1800);
        if (strpos($caption, $url) === false) {
            $caption = trim($caption . "\n\n" . $cta . "\n" . $url);
        }
        if ($hashtags) {
            $tag_line = '';
            foreach ($hashtags as $tag) {
                $tag_line .= '#' . $tag . ' ';
            }
            if (stripos($caption, '#' . $hashtags[0]) === false) {
                $caption = trim($caption . "\n\n" . trim($tag_line));
            }
        }
        $caption = $this->clip_social_block($caption, 2200);
        return array(
            'hook' => $hook,
            'on_image_headline' => $headline,
            'on_image_subhead' => $subhead,
            'visual_brief' => $visual,
            'cta' => $cta,
            'x' => $x,
            'instagram' => $caption,
            'instagram_alt' => $alt,
            'hashtags' => $hashtags,
        );
    }

    private function clip_social_text($value, $max) {
        $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $value)));
        return $this->limit_social_chars($value, $max);
    }

    private function clip_social_block($value, $max) {
        $value = trim(wp_strip_all_tags((string) $value));
        $value = preg_replace("/[ \t]+/", ' ', $value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value);
        return $this->limit_social_chars($value, $max);
    }

    private function limit_social_chars($value, $max) {
        if ($value === '') {
            return '';
        }
        $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($len <= $max) {
            return $value;
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    private function fit_social_with_url($body, $url, $max) {
        $suffix = "\n\n" . $url;
        $room = max(1, $max - (function_exists('mb_strlen') ? mb_strlen($suffix, 'UTF-8') : strlen($suffix)));
        $body = $this->limit_social_chars(trim((string) $body), $room);
        return $body . $suffix;
    }

    private function generate_social_image($post, $settings, $copy = array()) {
        $lock_name = 'tr_ai_wire_social_image_generation_lock';
        if (!add_option($lock_name, time(), '', false)) {
            $lock_time = (int) get_option($lock_name, 0);
            if ($lock_time && $lock_time < time() - 900) {
                delete_option($lock_name);
            }
            if (!add_option($lock_name, time(), '', false)) {
                return new WP_Error('tr_ai_wire_social_image_busy', 'Another image request is in progress.');
            }
        }
        try {
            return $this->generate_social_image_locked($post, $settings, $copy);
        } finally {
            delete_option($lock_name);
        }
    }

    private function generate_social_image_locked($post, $settings, $copy = array()) {
        if (!$this->social_distribution_ready()) {
            return new WP_Error('tr_ai_wire_social_not_ready', 'Connect a social account and enable Social distribution before spending OpenAI tokens on social images.');
        }
        $month_key = 'tr_ai_wire_social_image_usage_' . gmdate('Ym');
        $usage = (float) get_option($month_key, 0);
        $budget = (float) ($settings['social_image_budget_usd'] ?? 0);
        if ($budget <= 0 || ($usage + self::SOCIAL_IMAGE_ESTIMATED_USD) > $budget) {
            return new WP_Error('tr_ai_wire_social_image_budget', 'Social image generation is paused because its monthly image budget is exhausted.');
        }
        $key = $this->api_key();
        if (!$key) {
            return new WP_Error('tr_ai_wire_social_image_key', 'Add the OpenAI API key before generating social images.');
        }
        $copy = is_array($copy) ? $copy : array();
        $headline = (string) ($copy['on_image_headline'] ?? $post->post_title);
        $brief = (string) ($copy['visual_brief'] ?? '');
        $profile = $this->site_context_profile();
        $visual_style = !empty($profile['visual_style']) ? $profile['visual_style'] : 'cinematic photoreal Instagram news poster, high contrast, full-bleed photo';
        $prompt = 'Create an original photoreal cinematic still for an Instagram news poster from ' . (!empty($profile['site_name']) ? $profile['site_name'] : 'this technology publisher') . '. '
            . 'Art direction: ' . $visual_style . '. One clear scene that fills the frame, magazine lighting, shallow depth of field. Leave the lower third a little darker so overlay type can sit there. '
            . 'Do not imitate a specific existing photo. Anonymous people (backs, silhouettes, generic office workers) are allowed. '
            . 'Do not show a real celebrity, a real brand logo, a watermark, a UI screenshot, or any letters, numbers, or captions in the artwork — headline type is overlaid later. '
            . 'Subject: ' . $headline . '. '
            . ($brief !== '' ? 'Visual brief: ' . $brief . '. ' : '')
            . 'Mood must stop a social-media scroll, like a viral tech news graphic.';
        $response = wp_remote_post('https://api.openai.com/v1/images/generations', array(
            'timeout' => 120,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'model' => self::SOCIAL_IMAGE_MODEL,
                'prompt' => $prompt,
                'size' => '1024x1536',
                'quality' => 'low',
                'output_format' => 'jpeg',
                'n' => 1,
            )),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_social_image_api', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($data['data'][0]['b64_json'])) {
            $message = sanitize_text_field($data['error']['message'] ?? ('Image API returned HTTP ' . $status . '.'));
            return new WP_Error('tr_ai_wire_social_image_api', $message);
        }
        $bytes = base64_decode($data['data'][0]['b64_json'], true);
        if (!$bytes) {
            return new WP_Error('tr_ai_wire_social_image_decode', 'OpenAI returned an invalid image payload.');
        }
        update_option($month_key, round($usage + self::SOCIAL_IMAGE_ESTIMATED_USD, 6), false);
        $branded = $this->brand_social_image($bytes, $copy);
        if (is_wp_error($branded)) {
            return $branded;
        }
        $upload = wp_upload_bits('technorapper-social-' . (int) $post->ID . '.jpg', null, $branded);
        if (!empty($upload['error'])) {
            return new WP_Error('tr_ai_wire_social_image_upload', sanitize_text_field($upload['error']));
        }
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'image/jpeg',
            'post_title' => sanitize_text_field($headline . ' — social graphic'),
            'post_status' => 'inherit',
            'post_parent' => 0,
        ), $upload['file'], 0, true);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) ($copy['instagram_alt'] ?? $headline)));
        update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
        return (int) $attachment_id;
    }

    private function brand_social_image($bytes, $copy) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return new WP_Error('tr_ai_wire_social_gd', 'PHP GD is required to render the branded social image.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) {
            return new WP_Error('tr_ai_wire_social_image_decode', 'The generated social image could not be opened.');
        }
        $copy = is_array($copy) ? $copy : array();
        $headline = $this->social_poster_headline((string) ($copy['on_image_headline'] ?? $copy['hook'] ?? ''));
        $subhead = (string) ($copy['on_image_subhead'] ?? '');
        $source_width = imagesx($source);
        $source_height = imagesy($source);
        $target_width = 1080;
        $target_height = 1350;
        $target_ratio = $target_width / $target_height;
        $source_ratio = $source_width / $source_height;
        if ($source_ratio > $target_ratio) {
            $crop_width = (int) round($source_height * $target_ratio);
            $crop_height = $source_height;
            $source_x = (int) (($source_width - $crop_width) / 2);
            $source_y = 0;
        } else {
            $crop_width = $source_width;
            $crop_height = (int) round($source_width / $target_ratio);
            $source_x = 0;
            $source_y = (int) (($source_height - $crop_height) / 2);
        }
        $canvas = imagecreatetruecolor($target_width, $target_height);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, $source_x, $source_y, $target_width, $target_height, $crop_width, $crop_height);
        imagedestroy($source);
        $this->draw_social_bottom_gradient($canvas, $target_width, $target_height);
        $cyan = imagecolorallocate($canvas, 43, 213, 255);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 210, 220, 230);
        $font = $this->social_font_path();
        if ($font) {
            imagettftext($canvas, 20, 0, 48, 64, $white, $font, 'TECHNORAPPER');
            imagefilledrectangle($canvas, 48, 76, 220, 82, $cyan);
        } else {
            imagestring($canvas, 5, 48, 28, 'TECHNORAPPER', $white);
        }
        $headline_size = 46;
        $lines = $this->wrap_social_lines($headline, 980, $headline_size, $font, 22, 5);
        $line_height = $font ? 62 : 36;
        $block_height = (count($lines) * $line_height) + ($subhead !== '' ? 48 : 0);
        $y = $target_height - 72 - $block_height;
        if ($y < 860) {
            $y = 860;
        }
        foreach ($lines as $text_line) {
            $this->draw_social_centered_text($canvas, $text_line, $headline_size, $y, $white, $font, $target_width);
            $y += $line_height;
        }
        if ($subhead !== '') {
            $y += 8;
            foreach ($this->wrap_social_lines($subhead, 920, 22, $font, 40, 2) as $text_line) {
                $this->draw_social_centered_text($canvas, $text_line, 22, $y, $muted, $font, $target_width);
                $y += 34;
            }
        }
        if ($font) {
            imagettftext($canvas, 16, 0, $target_width - 268, $target_height - 28, $cyan, $font, '@technorapper');
        }
        ob_start();
        imagejpeg($canvas, null, 88);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render the branded image.');
    }

    private function social_poster_headline($text) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') {
            return '';
        }
        return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }

    private function draw_social_bottom_gradient($canvas, $width, $height) {
        $start = (int) round($height * 0.52);
        $span = $height - $start;
        if ($span < 1) {
            return;
        }
        for ($i = 0; $i < $span; $i++) {
            $t = $i / $span;
            $alpha = (int) max(0, min(127, 110 - (int) round($t * 110)));
            $color = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
            imageline($canvas, 0, $start + $i, $width, $start + $i, $color);
        }
    }

    private function draw_social_centered_text($canvas, $text, $size, $y, $color, $font, $canvas_width) {
        $text = (string) $text;
        if ($text === '') {
            return;
        }
        if ($font && function_exists('imagettfbbox')) {
            $box = imagettfbbox($size, 0, $font, $text);
            $text_width = abs($box[2] - $box[0]);
            $x = (int) max(36, ($canvas_width - $text_width) / 2);
            imagettftext($canvas, $size, 0, $x, $y, $color, $font, $text);
            return;
        }
        imagestring($canvas, 5, (int) max(20, ($canvas_width / 2) - 80), $y - 24, substr($text, 0, 80), $color);
    }

    private function wrap_social_lines($text, $max_width, $size, $font, $fallback_chars, $max_lines) {
        $words = preg_split('/\s+/', trim(wp_strip_all_tags((string) $text)));
        $lines = array();
        $line = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            $box = ($font && function_exists('imagettfbbox')) ? imagettfbbox($size, 0, $font, $candidate) : false;
            $too_wide = $box ? ($box[2] > $max_width) : (strlen($candidate) > $fallback_chars);
            if ($too_wide && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return array_slice($lines, 0, max(1, (int) $max_lines));
    }

    private function social_font_path() {
        if (!function_exists('imagettftext') || !function_exists('imagettfbbox')) {
            return '';
        }
        foreach (array(
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
        ) as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
        return '';
    }

    private function publish_x_post($text, $image_path, $token) {
        $bytes = file_get_contents($image_path);
        if (!$bytes || strlen($bytes) > 5 * 1024 * 1024) {
            return new WP_Error('tr_ai_wire_x_media_size', 'The X image is empty or exceeds the 5 MB image limit.');
        }
        $init = $this->social_http_json('https://api.x.com/2/media/upload/initialize', $token, array(
            'media_type' => 'image/jpeg',
            'total_bytes' => strlen($bytes),
            'media_category' => 'tweet_image',
        ));
        if (is_wp_error($init) || empty($init['data']['id'])) {
            return is_wp_error($init) ? $init : new WP_Error('tr_ai_wire_x_media', 'X did not return an upload ID.');
        }
        $media_id = (string) $init['data']['id'];
        $boundary = 'traiwire' . wp_generate_password(24, false, false);
        $body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"segment_index\"\r\n\r\n0\r\n"
            . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"media\"; filename=\"social.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n"
            . $bytes . "\r\n--" . $boundary . "--\r\n";
        $append = wp_remote_post('https://api.x.com/2/media/upload/' . rawurlencode($media_id) . '/append', array(
            'timeout' => 60,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary),
            'body' => $body,
        ));
        if (is_wp_error($append) || (int) wp_remote_retrieve_response_code($append) < 200 || (int) wp_remote_retrieve_response_code($append) >= 300) {
            return new WP_Error('tr_ai_wire_x_media_append', is_wp_error($append) ? $append->get_error_message() : 'X rejected the media upload.');
        }
        $finalized = $this->social_http_json('https://api.x.com/2/media/upload/' . rawurlencode($media_id) . '/finalize', $token, array());
        if (is_wp_error($finalized)) {
            return $finalized;
        }
        $processing = (string) ($finalized['data']['processing_info']['state'] ?? 'succeeded');
        if ($processing !== 'succeeded') {
            $ready = false;
            $check_after = max(1, (int) ($finalized['data']['processing_info']['check_after_secs'] ?? 1));
            for ($attempt = 0; $attempt < 6; $attempt++) {
                sleep(min(5, $check_after));
                $status_url = add_query_arg(array('command' => 'STATUS', 'media_id' => $media_id), 'https://api.x.com/2/media/upload');
                $status_response = wp_remote_get($status_url, array('timeout' => 30, 'headers' => array('Authorization' => 'Bearer ' . $token)));
                if (is_wp_error($status_response)) {
                    return new WP_Error('tr_ai_wire_x_media_status', $status_response->get_error_message());
                }
                $status_code = (int) wp_remote_retrieve_response_code($status_response);
                if ($status_code < 200 || $status_code >= 300) {
                    return new WP_Error('tr_ai_wire_x_media_status', 'X returned HTTP ' . $status_code . ' while processing the image.');
                }
                $status_data = json_decode(wp_remote_retrieve_body($status_response), true);
                $processing = (string) ($status_data['data']['processing_info']['state'] ?? '');
                if ($processing === 'succeeded') {
                    $ready = true;
                    break;
                }
                if ($processing === 'failed') {
                    return new WP_Error('tr_ai_wire_x_media_status', 'X failed to process the uploaded image.');
                }
                $check_after = max(1, (int) ($status_data['data']['processing_info']['check_after_secs'] ?? 1));
            }
            if (!$ready) {
                return new WP_Error('tr_ai_wire_x_media_status', 'X image processing did not finish in time.');
            }
        }
        $posted = $this->social_http_json('https://api.x.com/2/tweets', $token, array(
            'text' => $text,
            'media' => array('media_ids' => array($media_id)),
        ));
        if (is_wp_error($posted) || empty($posted['data']['id'])) {
            return is_wp_error($posted) ? $posted : new WP_Error('tr_ai_wire_x_post', 'X did not return a post ID.');
        }
        $id = (string) $posted['data']['id'];
        return array('id' => $id, 'url' => 'https://x.com/i/web/status/' . rawurlencode($id));
    }

    private function publish_instagram_post($caption, $alt_text, $image_url, $credentials) {
        if (!$image_url || !wp_http_validate_url($image_url)) {
            return new WP_Error('tr_ai_wire_ig_image_url', 'The Instagram image must have a public HTTP(S) Media Library URL.');
        }
        $credentials = $this->instagram_resolve_publish_credentials($credentials);
        if (is_wp_error($credentials)) {
            return $credentials;
        }
        $base = 'https://graph.facebook.com/' . self::INSTAGRAM_GRAPH_VERSION . '/' . rawurlencode($credentials['instagram_user_id']);
        $limit = $this->instagram_publish_limit($credentials);
        if (is_wp_error($limit)) {
            return $limit;
        }
        if (isset($limit['quota_usage'], $limit['config']['quota_total']) && (int) $limit['quota_usage'] >= (int) $limit['config']['quota_total']) {
            return new WP_Error('tr_ai_wire_ig_quota_full', 'Instagram publishing quota is full. The item is queued to try again later.');
        }
        $container = $this->instagram_request($base . '/media', array(
            'image_url' => $image_url,
            'caption' => $caption,
            'alt_text' => function_exists('mb_substr') ? mb_substr(sanitize_text_field($alt_text), 0, 1000, 'UTF-8') : substr(sanitize_text_field($alt_text), 0, 1000),
            'access_token' => $credentials['instagram_user_access_token'],
        ));
        if (is_wp_error($container) || empty($container['id'])) {
            return is_wp_error($container) ? $container : new WP_Error('tr_ai_wire_ig_container', 'Instagram did not return a media container ID.');
        }
        $container_id = (string) $container['id'];
        $ready = false;
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $status_url = add_query_arg(array(
                'fields' => 'status_code',
                'access_token' => $credentials['instagram_user_access_token'],
            ), 'https://graph.facebook.com/' . self::INSTAGRAM_GRAPH_VERSION . '/' . rawurlencode($container_id));
            $status_response = wp_remote_get($status_url, array('timeout' => 30));
            if (is_wp_error($status_response)) {
                return new WP_Error('tr_ai_wire_ig_status', $status_response->get_error_message());
            }
            $status_code = (int) wp_remote_retrieve_response_code($status_response);
            if ($status_code < 200 || $status_code >= 300) {
                return new WP_Error('tr_ai_wire_ig_status', 'Instagram returned HTTP ' . $status_code . ' while processing the image.');
            }
            $status_data = json_decode(wp_remote_retrieve_body($status_response), true);
            $code = (string) ($status_data['status_code'] ?? '');
            if ($code === 'FINISHED') {
                $ready = true;
                break;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                return new WP_Error('tr_ai_wire_ig_processing', 'Instagram could not prepare the image container.');
            }
            sleep(1);
        }
        if (!$ready) {
            return new WP_Error('tr_ai_wire_ig_processing', 'Instagram image processing did not finish in time. Check the Social distribution queue before retrying.');
        }
        $published = $this->instagram_request($base . '/media_publish', array(
            'creation_id' => $container_id,
            'access_token' => $credentials['instagram_user_access_token'],
        ));
        if (is_wp_error($published) || empty($published['id'])) {
            return is_wp_error($published) ? $published : new WP_Error('tr_ai_wire_ig_publish', 'Instagram did not return a media ID.');
        }
        $media_id = (string) $published['id'];
        $permalink = $this->instagram_media_permalink($media_id, $credentials['instagram_user_access_token']);
        return array('id' => $media_id, 'url' => is_wp_error($permalink) ? '' : $permalink);
    }

    private function social_http_json($url, $token, $payload) {
        $request_args = array(
            'timeout' => 75,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'),
        );
        if ($payload) {
            $request_args['body'] = wp_json_encode($payload);
        }
        $response = wp_remote_post($url, $request_args);
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_social_http', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) {
            return new WP_Error('tr_ai_wire_social_http', sanitize_text_field($data['detail'] ?? $data['title'] ?? $data['errors'][0]['message'] ?? ('Social API returned HTTP ' . $status . '.')));
        }
        return is_array($data) ? $data : array();
    }

    private function instagram_request($url, $payload) {
        $response = wp_remote_post($url, array('timeout' => 60, 'body' => $payload));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_instagram_http', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !empty($data['error'])) {
            return new WP_Error('tr_ai_wire_instagram_http', sanitize_text_field($data['error']['message'] ?? ('Instagram API returned HTTP ' . $status . '.')));
        }
        return is_array($data) ? $data : array();
    }

    private function instagram_graph_get($path, $token, $query = array()) {
        $query = is_array($query) ? $query : array();
        $query['access_token'] = $token;
        $url = add_query_arg($query, 'https://graph.facebook.com/' . self::INSTAGRAM_GRAPH_VERSION . '/' . ltrim((string) $path, '/'));
        $response = wp_remote_get($url, array('timeout' => 30));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_instagram_http', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !empty($data['error'])) {
            return new WP_Error('tr_ai_wire_instagram_http', sanitize_text_field($data['error']['message'] ?? ('Instagram API returned HTTP ' . $status . '.')));
        }
        return is_array($data) ? $data : array();
    }

    private function instagram_missing_object_help($ig_id) {
        $ig_id = preg_replace('/[^0-9]/', '', (string) $ig_id);
        $label = $ig_id !== '' ? 'account ' . $ig_id : 'this Instagram account';
        return 'Instagram rejected this token for ' . $label . '. Paste the TechnoRapper Page access_token from Graph API Explorer GET me/accounts — the token inside the TechnoRapper object — not the User Token at the top of the page. Then retry.';
    }

    private function instagram_is_missing_object_error($message) {
        $message = (string) $message;
        return stripos($message, 'does not exist') !== false
            || stripos($message, 'missing permissions') !== false
            || stripos($message, 'unsupported post request') !== false;
    }

    private function instagram_persist_resolved_credentials($credentials) {
        $current = $this->social_credentials();
        $current['instagram_user_access_token'] = (string) ($credentials['instagram_user_access_token'] ?? '');
        $current['instagram_user_id'] = preg_replace('/[^0-9]/', '', (string) ($credentials['instagram_user_id'] ?? ''));
        update_option(self::SOCIAL_CREDENTIALS_OPTION, $current, false);
    }

    private function instagram_resolve_publish_credentials($credentials) {
        $token = (string) ($credentials['instagram_user_access_token'] ?? '');
        $ig_id = preg_replace('/[^0-9]/', '', (string) ($credentials['instagram_user_id'] ?? ''));
        if ($token === '' || $ig_id === '') {
            return new WP_Error('tr_ai_wire_ig_missing_token', 'The Instagram access token or account ID is no longer configured.');
        }
        $probe = $this->instagram_graph_get($ig_id, $token, array('fields' => 'id,username'));
        if (!is_wp_error($probe) && !empty($probe['id'])) {
            return $credentials;
        }
        $me = $this->instagram_graph_get('me', $token, array('fields' => 'id,name,instagram_business_account'));
        if (!is_wp_error($me) && !empty($me['instagram_business_account']['id'])) {
            $linked = preg_replace('/[^0-9]/', '', (string) $me['instagram_business_account']['id']);
            if ($linked !== '') {
                $credentials['instagram_user_id'] = $linked;
                if ($linked !== $ig_id) {
                    $this->instagram_persist_resolved_credentials($credentials);
                }
                return $credentials;
            }
        }
        $accounts = $this->instagram_graph_get('me/accounts', $token, array(
            'fields' => 'name,id,access_token,instagram_business_account',
            'limit' => 100,
        ));
        $pages = (!is_wp_error($accounts) && !empty($accounts['data']) && is_array($accounts['data'])) ? $accounts['data'] : array();
        $matched = null;
        $ig_pages = array();
        foreach ($pages as $page) {
            $page_ig = preg_replace('/[^0-9]/', '', (string) ($page['instagram_business_account']['id'] ?? ''));
            if ($page_ig === '' || empty($page['access_token'])) {
                continue;
            }
            $ig_pages[] = $page;
            if ($page_ig === $ig_id) {
                $matched = $page;
                break;
            }
        }
        if (!$matched && count($ig_pages) === 1) {
            $matched = $ig_pages[0];
        }
        if ($matched) {
            $credentials['instagram_user_access_token'] = (string) $matched['access_token'];
            $credentials['instagram_user_id'] = preg_replace('/[^0-9]/', '', (string) $matched['instagram_business_account']['id']);
            $this->instagram_persist_resolved_credentials($credentials);
            return $credentials;
        }
        $probe_message = is_wp_error($probe) ? $probe->get_error_message() : '';
        if ($this->instagram_is_missing_object_error($probe_message)) {
            return new WP_Error('tr_ai_wire_ig_token_mismatch', $this->instagram_missing_object_help($ig_id));
        }
        return is_wp_error($probe) ? $probe : new WP_Error('tr_ai_wire_ig_token_mismatch', $this->instagram_missing_object_help($ig_id));
    }

    private function instagram_publish_limit($credentials) {
        $url = add_query_arg(array('fields' => 'quota_usage,config', 'access_token' => $credentials['instagram_user_access_token']),
            'https://graph.facebook.com/' . self::INSTAGRAM_GRAPH_VERSION . '/' . rawurlencode($credentials['instagram_user_id']) . '/content_publishing_limit');
        $response = wp_remote_get($url, array('timeout' => 30));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_ig_quota', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !empty($data['error'])) {
            $message = sanitize_text_field($data['error']['message'] ?? ('Could not read Instagram publishing quota (HTTP ' . $status . ').'));
            $code = (int) ($data['error']['code'] ?? 0);
            // content_publishing_limit is an optional pre-check of Instagram's
            // rolling 24-hour post cap. It is not the publish call. If this
            // Page token cannot read that edge, skip it and try to publish.
            if ($this->instagram_quota_check_is_optional_failure($message, $code)) {
                return array();
            }
            return new WP_Error('tr_ai_wire_ig_quota', $message);
        }
        if (isset($data['data'][0]) && is_array($data['data'][0])) {
            return $data['data'][0];
        }
        return is_array($data) ? $data : array();
    }

    private function instagram_quota_check_is_optional_failure($message, $code = 0) {
        if ((int) $code === 100) {
            return true;
        }
        $message = (string) $message;
        return stripos($message, 'nonexisting field') !== false
            || stripos($message, 'content_publishing_limit') !== false;
    }

    public function maybe_requeue_instagram_quota_field_failures() {
        if (!$this->social_distribution_ready()) {
            return 0;
        }
        $updated = $this->requeue_instagram_prepublish_quota_failures();
        if ($updated > 0 && !wp_next_scheduled(self::SOCIAL_CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::SOCIAL_CRON_HOOK);
        }
        return $updated;
    }

    private function requeue_instagram_prepublish_quota_failures() {
        global $wpdb;
        $table = $this->social_table();
        $like = '%' . $wpdb->esc_like('content_publishing_limit') . '%';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'queued', error_text = NULL, remote_id = '', remote_url = '', updated_at = %s WHERE platform = 'instagram' AND status = 'needs_review' AND error_text LIKE %s",
            current_time('mysql', true),
            $like
        ));
        return is_int($updated) ? $updated : 0;
    }

    private function instagram_media_permalink($media_id, $token) {
        $url = add_query_arg(array('fields' => 'permalink', 'access_token' => $token),
            'https://graph.facebook.com/' . self::INSTAGRAM_GRAPH_VERSION . '/' . rawurlencode($media_id));
        $response = wp_remote_get($url, array('timeout' => 30));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) {
            return new WP_Error('tr_ai_wire_ig_permalink', 'Instagram published the media, but its permalink could not be read.');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($data['permalink']) ? esc_url_raw($data['permalink']) : new WP_Error('tr_ai_wire_ig_permalink', 'Instagram did not return a permalink.');
    }

    private function finish_social_item($id, $status, $remote_id, $remote_url, $error) {
        global $wpdb;
        $wpdb->update($this->social_table(), array(
            'status' => sanitize_key($status),
            'remote_id' => sanitize_text_field($remote_id),
            'remote_url' => esc_url_raw($remote_url),
            'error_text' => $error !== '' ? sanitize_text_field($error) : null,
            'updated_at' => current_time('mysql', true),
        ), array('id' => (int) $id));
    }

    private function social_published_today() {
        global $wpdb;
        $table = $this->social_table();
        $start = gmdate('Y-m-d 00:00:00');
        $end = gmdate('Y-m-d 23:59:59');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$table} WHERE status = 'published' AND updated_at BETWEEN %s AND %s",
            $start,
            $end
        ));
    }

    private function social_post_published_today($post_id) {
        global $wpdb;
        $table = $this->social_table();
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND status = 'published' AND updated_at >= UTC_DATE() LIMIT 1",
            (int) $post_id
        ));
    }

    public function retry_social_item() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to retry social publishing.', 'technorapper-ai-wire'));
        }
        $id = absint($_POST['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_retry_social_item_' . $id);
        if (empty($_POST['confirmed_not_published'])) {
            wp_die(esc_html__('Confirm that this item is not already live on the platform before retrying.', 'technorapper-ai-wire'));
        }
        global $wpdb;
        $wpdb->update($this->social_table(), array(
            'status' => 'queued',
            'error_text' => null,
            'remote_id' => '',
            'remote_url' => '',
            'updated_at' => current_time('mysql', true),
        ), array('id' => $id, 'status' => 'needs_review'));
        if (!wp_next_scheduled(self::SOCIAL_CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::SOCIAL_CRON_HOOK);
        }
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social'));
        exit;
    }

    private function social_queue_copy($row, $post) {
        $copy = array();
        if (!empty($row['copy_json'])) {
            $decoded = json_decode($row['copy_json'], true);
            if (is_array($decoded)) {
                $copy = $decoded;
            }
        } elseif ($post instanceof WP_Post) {
            $meta_copy = json_decode((string) get_post_meta($post->ID, '_tr_ai_wire_social_copy', true), true);
            if (is_array($meta_copy)) {
                $copy = $meta_copy;
            }
        }
        return $copy;
    }

    private function social_queue_image_id($row, $post) {
        $image_id = (int) ($row['image_attachment_id'] ?? 0);
        if ($image_id > 0 && wp_attachment_is_image($image_id)) {
            return $image_id;
        }
        if ($post instanceof WP_Post) {
            $fallback = (int) get_post_meta($post->ID, '_tr_ai_wire_social_image_id', true);
            if ($fallback > 0 && wp_attachment_is_image($fallback)) {
                return $fallback;
            }
        }
        return 0;
    }

    private function social_status_copy($status) {
        $map = array(
            'queued' => 'Waiting. The worker generates the branded graphic, then tries to post it.',
            'processing' => 'In progress: generating the graphic or talking to the platform.',
            'published' => 'Posted on this platform.',
            'needs_review' => 'The platform response was unclear. Confirm this is not already live, then retry so you do not double-post.',
        );
        $status = sanitize_key((string) $status);
        return isset($map[$status]) ? $map[$status] : '';
    }

    private function social_error_copy($error) {
        $error = trim((string) $error);
        if ($error === '') {
            return '';
        }
        if (stripos($error, 'nonexisting field') !== false && stripos($error, 'content_publishing_limit') !== false) {
            return 'Instagram could not read the daily publishing quota for this token. The branded graphic was still generated. Confirm it is not already live, then retry — the next attempt will skip that quota check and try to publish.';
        }
        if ($this->instagram_is_missing_object_error($error)) {
            $ig_id = '';
            if (preg_match('/(\d{10,})/', $error, $matches)) {
                $ig_id = $matches[1];
            }
            return $this->instagram_missing_object_help($ig_id);
        }
        return $error;
    }

    private function social_queue_caption($row, $copy) {
        $platform = (string) ($row['platform'] ?? '');
        if ($platform === 'instagram' && !empty($copy['instagram'])) {
            return (string) $copy['instagram'];
        }
        if ($platform === 'x' && !empty($copy['x'])) {
            return (string) $copy['x'];
        }
        if (!empty($copy['hook'])) {
            return (string) $copy['hook'];
        }
        return '';
    }

    private function render_social_queue_creative($row, $post, $copy) {
        $image_id = $this->social_queue_image_id($row, $post);
        $caption = $this->social_queue_caption($row, $copy);
        $error = $this->social_error_copy((string) ($row['error_text'] ?? ''));
        $status = sanitize_key((string) ($row['status'] ?? ''));
        echo '<div class="tr-ai-wire-social-item">';
        echo '<div class="tr-ai-wire-social-preview">';
        if ($image_id > 0) {
            $full = wp_get_attachment_url($image_id);
            echo '<a class="tr-ai-wire-social-preview-link" href="' . esc_url($full ? $full : get_edit_post_link($image_id)) . '" target="_blank" rel="noopener">';
            echo wp_get_attachment_image($image_id, 'medium', false, array(
                'class' => 'tr-ai-wire-social-preview-image',
                'alt' => (string) ($copy['instagram_alt'] ?? $copy['on_image_headline'] ?? 'Generated social graphic'),
            ));
            echo '<span class="tr-ai-wire-social-preview-label">Generated graphic</span></a>';
        } else {
            echo '<div class="tr-ai-wire-social-preview-empty">Graphic not generated yet. The worker creates it before posting.</div>';
        }
        echo '</div><div class="tr-ai-wire-social-copy">';
        if (!empty($copy['on_image_headline'])) {
            echo '<p class="tr-ai-wire-social-headline"><strong>' . esc_html($copy['on_image_headline']) . '</strong></p>';
        }
        if (!empty($copy['on_image_subhead'])) {
            echo '<p class="tr-ai-wire-social-subhead">' . esc_html($copy['on_image_subhead']) . '</p>';
        }
        if (!empty($copy['hook']) && (empty($copy['on_image_headline']) || $copy['hook'] !== $copy['on_image_headline'])) {
            echo '<p class="description">' . esc_html($copy['hook']) . '</p>';
        }
        if ($caption !== '') {
            echo '<p class="tr-ai-wire-social-caption">' . esc_html(wp_trim_words($caption, 36, '…')) . '</p>';
        }
        $status_copy = $this->social_status_copy($status);
        if ($status_copy !== '') {
            echo '<p class="description">' . esc_html($status_copy) . '</p>';
        }
        if (!empty($row['remote_url'])) {
            echo '<p><a href="' . esc_url($row['remote_url']) . '" target="_blank" rel="noopener">View live post</a></p>';
        } elseif (!empty($row['remote_id'])) {
            echo '<p>Published media ID: ' . esc_html($row['remote_id']) . '</p>';
        }
        if ($error !== '') {
            echo '<p class="tr-ai-wire-social-error">' . esc_html($error) . '</p>';
        }
        if ($status === 'needs_review') {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_retry_social_item">';
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string) (int) $row['id']) . '">';
            wp_nonce_field('tr_ai_wire_retry_social_item_' . (int) $row['id']);
            echo '<label><input type="checkbox" name="confirmed_not_published" value="1" required> I checked that it is not already published</label><br>';
            echo '<button class="button button-secondary">Retry this platform post</button></form>';
        }
        echo '</div></div>';
    }

    public function social_distribution_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . $this->social_table() . ' ORDER BY created_at DESC LIMIT 100', ARRAY_A);
        $settings = $this->settings();
        $image_usage = (float) get_option('tr_ai_wire_social_image_usage_' . gmdate('Ym'), 0);
        ?>
        <div class="wrap">
            <h1>Social distribution</h1>
            <style>
                .tr-ai-wire-social-item{display:flex;gap:16px;align-items:flex-start;max-width:920px;}
                .tr-ai-wire-social-preview{flex:0 0 132px;}
                .tr-ai-wire-social-preview-link{display:block;text-decoration:none;}
                .tr-ai-wire-social-preview-image{display:block;width:120px;height:auto;border-radius:8px;background:#09142b;border:1px solid #c3c4c7;}
                .tr-ai-wire-social-preview-label{display:block;margin-top:6px;font-size:12px;color:#50575e;}
                .tr-ai-wire-social-preview-empty{width:120px;min-height:150px;padding:12px;border:1px dashed #c3c4c7;border-radius:8px;background:#f6f7f7;color:#646970;font-size:12px;}
                .tr-ai-wire-social-copy{min-width:0;}
                .tr-ai-wire-social-headline,.tr-ai-wire-social-subhead,.tr-ai-wire-social-caption{margin:0 0 6px;}
                .tr-ai-wire-social-caption{white-space:pre-wrap;color:#1d2327;}
                .tr-ai-wire-social-error{color:#b32d2e;margin:8px 0;}
                .tr-ai-wire-social-status{display:inline-block;padding:2px 8px;border-radius:999px;background:#dcdcde;font-size:12px;text-transform:lowercase;}
                .tr-ai-wire-social-status.is-published{background:#c6e1c6;}
                .tr-ai-wire-social-status.is-needs_review{background:#f0c33c;}
                .tr-ai-wire-social-status.is-processing{background:#c5d9ed;}
            </style>
            <?php if (!empty($_GET['site_context'])) : ?><div class="notice notice-info is-dismissible"><p>We are understanding your site so everything we do for social stays in your context.</p></div><?php endif; ?>
            <?php $this->render_site_context_card('tr-ai-wire-social'); ?>
            <p>Automatic distribution is <?php echo !empty($settings['social_distribution_enabled']) ? '<strong>on</strong>' : '<strong>off</strong>'; ?>. Turning it off pauses queued work. Today: <?php echo esc_html((string) $this->social_published_today()); ?> / <?php echo esc_html((string) (int) ($settings['social_daily_limit'] ?? 10)); ?> articles distributed.</p>
            <p>Social image budget this month: $<?php echo esc_html(number_format_i18n($image_usage, 3)); ?> / $<?php echo esc_html(number_format_i18n((float) ($settings['social_image_budget_usd'] ?? 0), 2)); ?> estimated.</p>
            <p class="description">Each row is one platform post for a published AI Wire article. The graphic on the right is the branded social card — not the article featured image. The worker generates that card, writes a caption, then tries to publish it.</p>
            <table class="widefat striped"><thead><tr><th>Queued</th><th>Article</th><th>Platform</th><th>Status</th><th>Generated graphic / result</th></tr></thead><tbody>
            <?php if (!$rows) : ?><tr><td colspan="5">No social items have been queued.</td></tr><?php else : foreach ($rows as $row) :
                $post = get_post((int) $row['post_id']);
                $copy = $this->social_queue_copy($row, $post);
                $status = sanitize_key((string) ($row['status'] ?? ''));
                ?>
                <tr>
                    <td><?php echo esc_html($row['created_at']); ?></td>
                    <td><?php if ($post) : ?><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a><?php endif; ?></td>
                    <td><?php echo esc_html($row['platform']); ?></td>
                    <td><span class="tr-ai-wire-social-status is-<?php echo esc_attr($status); ?>"><?php echo esc_html($status); ?></span></td>
                    <td><?php $this->render_social_queue_creative($row, $post, $copy); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody></table>
        </div>
        <?php
    }
}
