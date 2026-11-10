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
        return wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), self::social_credential_defaults());
    }

    public static function social_credential_defaults() {
        return array(
            'x_user_access_token' => '',
            'x_api_key' => '',
            'x_api_secret' => '',
            'x_access_token' => '',
            'x_access_token_secret' => '',
            'instagram_user_access_token' => '',
            'instagram_user_id' => '',
        );
    }

    public static function x_credentials_ready($credentials = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), self::social_credential_defaults());
        }
        if (self::x_oauth1_ready($credentials)) {
            return true;
        }
        return !empty($credentials['x_user_access_token']);
    }

    public static function x_oauth1_ready($credentials = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), self::social_credential_defaults());
        }
        return !empty($credentials['x_api_key'])
            && !empty($credentials['x_api_secret'])
            && !empty($credentials['x_access_token'])
            && !empty($credentials['x_access_token_secret']);
    }

    public static function x_oauth1_field_labels() {
        return array(
            'x_api_key' => 'API Key',
            'x_api_secret' => 'API Key Secret',
            'x_access_token' => 'Access Token',
            'x_access_token_secret' => 'Access Token Secret',
        );
    }

    public static function x_oauth1_missing_labels($credentials = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), self::social_credential_defaults());
        }
        $missing = array();
        foreach (self::x_oauth1_field_labels() as $key => $label) {
            if (empty($credentials[$key])) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    public static function x_status_text($credentials = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), self::social_credential_defaults());
        }
        if (self::x_oauth1_ready($credentials)) {
            return 'Keys and tokens saved (API Key, API Key Secret, Access Token, Access Token Secret). Bearer is not needed.';
        }
        $missing = self::x_oauth1_missing_labels($credentials);
        if (!empty($credentials['x_user_access_token'])) {
            return 'Only a Bearer token is saved. X needs the four Keys and tokens values, not Bearer. Missing: ' . implode(', ', $missing) . '.';
        }
        return $missing ? 'not connected — save ' . implode(', ', $missing) : 'not connected';
    }

    public static function instagram_credentials_ready($credentials = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), self::social_credential_defaults());
        }
        return !empty($credentials['instagram_user_access_token']) && !empty($credentials['instagram_user_id']);
    }

    public static function connected_social_platforms($credentials = null, $settings = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), self::social_credential_defaults());
        }
        if (!is_array($settings)) {
            $settings = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        }
        $settings = self::apply_social_platform_toggles($settings);
        $platforms = array();
        if (self::x_credentials_ready($credentials) && !empty($settings['social_x_enabled'])) {
            $platforms[] = 'x';
        }
        if (self::instagram_credentials_ready($credentials) && !empty($settings['social_instagram_enabled'])) {
            $platforms[] = 'instagram';
        }
        return $platforms;
    }

    public static function social_credentials_connected($credentials = null, $settings = null) {
        return (bool) self::connected_social_platforms($credentials, $settings);
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
        $secret_fields = array(
            'x_user_access_token',
            'x_api_key',
            'x_api_secret',
            'x_access_token',
            'x_access_token_secret',
            'instagram_user_access_token',
        );
        foreach ($secret_fields as $field) {
            if (!empty($_POST['remove_' . $field])) {
                $next[$field] = '';
            } elseif (isset($input[$field]) && trim((string) $input[$field]) !== '') {
                $next[$field] = trim(sanitize_text_field((string) $input[$field]));
            }
        }
        if (!empty($_POST['remove_x_oauth'])) {
            $next['x_api_key'] = '';
            $next['x_api_secret'] = '';
            $next['x_access_token'] = '';
            $next['x_access_token_secret'] = '';
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
        $old_settings = $this->settings();
        $settings_input = isset($_POST[self::OPTION]) && is_array($_POST[self::OPTION]) ? wp_unslash($_POST[self::OPTION]) : array();
        $new_settings = array_merge($old_settings, $this->sanitize_social_settings_from_input($settings_input, $old_settings, self::defaults()));
        update_option(self::OPTION, $new_settings, false);
        $this->settings_updated($old_settings, $new_settings);
        $now_connected = self::social_credentials_connected($next, $new_settings);
        $was_connected = self::social_credentials_connected($current, $old_settings);
        $backfill = 0;
        $backfill_platform = '';
        if (!empty($new_settings['social_distribution_enabled']) && $now_connected) {
            if (
                !empty($new_settings['social_x_enabled'])
                && !self::x_credentials_ready($current)
                && self::x_credentials_ready($next)
            ) {
                $backfill = $this->enqueue_missing_platform_posts('x');
                $backfill_platform = 'x';
            } elseif (
                !empty($new_settings['social_instagram_enabled'])
                && !self::instagram_credentials_ready($current)
                && self::instagram_credentials_ready($next)
            ) {
                $backfill = $this->enqueue_missing_platform_posts('instagram');
                $backfill_platform = 'instagram';
            }
            $this->kick_social_queue(5);
            $this->queue_site_context_job(!$was_connected);
        }
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tr-ai-wire-social-settings',
            'social_credentials_saved' => '1',
            'social_backfill' => (int) $backfill,
            'social_backfill_platform' => $backfill_platform,
        ), admin_url('admin.php')));
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
        $page = sanitize_key($_POST['redirect_page'] ?? 'tr-ai-wire-social-settings');
        if (!in_array($page, array('tr-ai-wire-social-settings', 'tr-ai-wire-social'), true)) {
            $page = 'tr-ai-wire-social-settings';
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
            . 'visual_style should describe photoreal cinematic news photography (lighting, setting, mood) for a full-bleed Instagram poster — never illustration, cartoon, navy cards, or graphic-design kits. '
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
            $body = 'Onboard X or Instagram on Social settings. Until then we do not run the site-context job and we do not spend OpenAI tokens on social copy or images.';
        } elseif (!$enabled) {
            $state = 'idle';
            $title = 'We will understand this site when a network is on';
            $body = 'Turn on Post to X and/or Post to Instagram on Social settings and AI Wire will read this WordPress site — name, desks, recent stories, and voice — so every later social post stays in your context.';
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

    public function render_site_context_card($redirect_page = 'tr-ai-wire-social-settings') {
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

    /**
     * Social adapters fire only after WordPress confirms a verified publish
     * transition (draft/pending/future → publish) with a public permalink.
     * Failures here must never roll back the article.
     */
    public function queue_social_for_post($new_status, $old_status, $post) {
        try {
            $this->queue_social_on_verified_publish($new_status, $old_status, $post);
        } catch (Throwable $e) {
            // Distribution errors are isolated from CMS integrity.
        }
    }

    private function queue_social_on_verified_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || !($post instanceof WP_Post) || $post->post_type !== 'post') {
            return;
        }
        if (!in_array((string) $old_status, array('draft', 'pending', 'future', 'auto-draft'), true)) {
            return;
        }
        if (wp_is_post_revision($post->ID) || !get_post_meta($post->ID, '_tr_ai_wire_queue_id', true)) {
            return;
        }
        if (get_post_status($post) !== 'publish') {
            return;
        }
        $permalink = get_permalink($post);
        if (!$permalink || strpos((string) $permalink, 'preview=true') !== false) {
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
        $this->collect_social_article((int) $post->ID, $settings);
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
        if ($this->social_wants_reel($settings) && function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        $credentials = $this->social_credentials();
        $limit = min(20, max(1, (int) ($settings['social_daily_limit'] ?? 10)));
        if ($this->social_published_today() >= $limit) {
            global $wpdb;
            $table = $this->social_table();
            $pending_new = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social q WHERE q.status = 'queued' AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}tr_ai_wire_social p WHERE p.post_id = q.post_id AND p.status = 'published' AND p.updated_at >= UTC_DATE())");
            if ($pending_new > 0) {
                $tomorrow = strtotime('tomorrow 00:20:00 UTC');
                wp_schedule_single_event($tomorrow, self::SOCIAL_CRON_HOOK);
            }
        }

        global $wpdb;
        $table = $this->social_table();
        $wpdb->query("UPDATE {$wpdb->prefix}tr_ai_wire_social SET status = 'needs_review', error_text = 'The worker stopped before recording an outcome. Check the platform before retrying.' WHERE status = 'processing' AND updated_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE");
        $this->requeue_instagram_prepublish_quota_failures();
        $rows = $this->social_queued_rows_for_worker($limit);
        $active = self::connected_social_platforms($credentials, $settings);
        foreach ((array) $rows as $row) {
            if (empty($this->settings()['social_distribution_enabled']) || !$active) {
                break;
            }
            if (!in_array((string) ($row['platform'] ?? ''), $active, true)) {
                continue;
            }
            if ($this->social_published_today() >= $limit && !$this->social_post_published_today((int) $row['post_id']) && !$this->social_item_is_manual_repost($row)) {
                $tomorrow = strtotime('tomorrow 00:20:00 UTC');
                if (!wp_next_scheduled(self::SOCIAL_CRON_HOOK)) {
                    wp_schedule_single_event($tomorrow, self::SOCIAL_CRON_HOOK);
                }
                continue;
            }
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}tr_ai_wire_social SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'queued'",
                current_time('mysql', true),
                (int) $row['id']
            ));
            if ($claimed !== 1) {
                continue;
            }
            $pack = array($row);
            if ($this->social_row_is_pack($row, $settings) && !$this->social_item_is_manual_repost($row)) {
                $pack = $this->claim_instagram_pack($row, $limit, $settings);
            }
            if (count($pack) > 1) {
                if ($row['platform'] === 'x') {
                    $this->finish_instagram_pack($pack, $this->publish_x_pack($pack, $credentials, $settings));
                } else {
                    $this->finish_instagram_pack($pack, $this->publish_instagram_pack($pack, $credentials, $settings));
                }
                break;
            }
            $result = $this->publish_social_item($row, $credentials, $settings);
            if (is_wp_error($result)) {
                if ($result->get_error_code() === 'tr_ai_wire_social_image_busy') {
                    $wpdb->update($table, array('status' => 'queued', 'updated_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
                    wp_schedule_single_event(time() + 60, self::SOCIAL_CRON_HOOK);
                    break;
                }
                if ($result->get_error_code() === 'tr_ai_wire_ig_quota_full') {
                    $wpdb->update($table, array('status' => 'queued', 'error_text' => $result->get_error_message(), 'updated_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
                    wp_schedule_single_event(strtotime('+1 hour'), self::SOCIAL_CRON_HOOK);
                    break;
                }
                // Do not auto-retry an ambiguous publish response: the platform
                // may have accepted the post before the connection failed.
                $this->finish_social_item($row['id'], 'needs_review', '', '', $result->get_error_message());
                if ($this->social_finishable_queued_count() > 0) {
                    $this->kick_social_queue(5);
                }
                break;
            }
            $this->finish_social_item($row['id'], 'published', $result['id'], $result['url'], '');
            break;
        }

        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued'");
        $finishable = $this->social_finishable_queued_count();
        if ($remaining > 0 && ($this->social_published_today() < $limit || $finishable > 0 || $this->social_queued_repost_count() > 0)) {
            $this->kick_social_queue($finishable > 0 ? 5 : 60);
        }
    }

    private function social_queued_rows_for_worker($limit) {
        global $wpdb;
        $table = $this->social_table();
        $platform_sql = $this->social_active_platform_sql();
        if ($platform_sql === '') {
            return array();
        }
        if ($this->social_published_today() < $limit) {
            return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued'{$platform_sql} ORDER BY created_at ASC LIMIT 25", ARRAY_A);
        }
        $queued_platform_sql = $this->social_active_platform_sql('q');
        return $wpdb->get_results(
            "SELECT q.* FROM {$wpdb->prefix}tr_ai_wire_social q
             WHERE q.status = 'queued'{$queued_platform_sql}
               AND (
                    EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}tr_ai_wire_social p
                        WHERE p.post_id = q.post_id
                          AND p.status = 'published'
                    )
                    OR EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} m
                        WHERE m.post_id = q.post_id
                          AND m.meta_key = CONCAT('_tr_ai_wire_social_repost_', q.id)
                          AND m.meta_value = '1'
                    )
               )
             ORDER BY q.created_at ASC
             LIMIT 25",
            ARRAY_A
        );
    }

    private function social_active_platform_sql($alias = '') {
        $platforms = self::connected_social_platforms();
        if (!$platforms) {
            return '';
        }
        global $wpdb;
        $parts = array();
        foreach ($platforms as $platform) {
            $parts[] = $wpdb->prepare('%s', $platform);
        }
        $column = $alias !== '' ? $alias . '.platform' : 'platform';
        return ' AND ' . $column . ' IN (' . implode(',', $parts) . ')';
    }

    private function social_finishable_queued_count() {
        global $wpdb;
        $table = $this->social_table();
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social q
             WHERE q.status = 'queued'
               AND EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}tr_ai_wire_social p
                    WHERE p.post_id = q.post_id
                      AND p.status = 'published'
               )"
        );
    }

    private function social_queued_count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued'");
    }

    private function kick_social_queue($delay = 5, $even_if_empty = false) {
        if (!$this->social_distribution_ready()) {
            return false;
        }
        if (!$even_if_empty && $this->social_queued_count() < 1) {
            return false;
        }
        $delay = max(1, (int) $delay);
        $want = time() + $delay;
        $next = wp_next_scheduled(self::SOCIAL_CRON_HOOK);
        if ($next && $next <= $want + 15) {
            return true;
        }
        wp_clear_scheduled_hook(self::SOCIAL_CRON_HOOK);
        wp_schedule_single_event($want, self::SOCIAL_CRON_HOOK);
        return true;
    }

    public function run_social_queue_now() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run social publishing.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_run_social_queue');
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        $this->flush_social_batch(true, true);
        $processed = 0;
        for ($i = 0; $i < 15; $i++) {
            $before = $this->social_queued_count();
            if ($before < 1) {
                break;
            }
            $this->process_social_queue();
            $after = $this->social_queued_count();
            if ($after < $before) {
                $processed++;
                continue;
            }
            if ($this->social_finishable_queued_count() < 1) {
                break;
            }
        }
        $this->kick_social_queue(5);
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social&social_ran=' . (int) $processed));
        exit;
    }

    private function publish_social_item($row, $credentials, $settings) {
        $prepared = $this->prepare_social_delivery($row, $settings);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        if ($row['platform'] === 'x') {
            if (!self::x_credentials_ready($credentials)) {
                return new WP_Error('tr_ai_wire_x_missing_token', 'X is not connected. Save API Key, API Key Secret, Access Token, and Access Token Secret from the X developer portal Keys and tokens page.');
            }
            return $this->publish_x_post($prepared['copy']['x'], $prepared['image_path'], $credentials);
        }
        if ($row['platform'] === 'instagram') {
            if (empty($credentials['instagram_user_access_token']) || empty($credentials['instagram_user_id'])) {
                return new WP_Error('tr_ai_wire_ig_missing_token', 'The Instagram access token or account ID is no longer configured.');
            }
            return $this->publish_instagram_post(
                $prepared['copy']['instagram'],
                $prepared['copy']['instagram_alt'],
                $prepared['image_url'],
                $credentials,
                (string) ($prepared['video_url'] ?? '')
            );
        }
        return new WP_Error('tr_ai_wire_social_platform', 'Unknown social platform.');
    }

    private function prepare_social_delivery($row, $settings) {
        $post = get_post((int) $row['post_id']);
        if (!$post || $post->post_status !== 'publish') {
            return new WP_Error('tr_ai_wire_social_post', 'The source article is no longer published.');
        }
        $copy = $this->social_copy($post, $settings);
        $wants_reel = $this->social_wants_reel($settings) && (($row['platform'] ?? '') === 'instagram');
        $force_new_image = (string) get_post_meta($post->ID, '_tr_ai_wire_social_regenerate_image', true) === '1';
        if ($wants_reel && $this->social_broll_id($post->ID) < 1) {
            $force_new_image = true;
        }
        $image_id = $force_new_image ? 0 : $this->social_existing_image_id($post->ID, $row);
        if ($image_id < 1) {
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
        $image_url = wp_get_attachment_url($image_id);
        if (!$image_path || !file_exists($image_path) || !$image_url) {
            return new WP_Error('tr_ai_wire_social_image', 'The generated social image is missing from WordPress Media.');
        }
        global $wpdb;
        $prepared = array(
            'row' => $row,
            'post' => $post,
            'copy' => $copy,
            'image_id' => (int) $image_id,
            'image_path' => $image_path,
            'image_url' => $image_url,
        );
        if ($wants_reel) {
            $broll_id = $this->social_broll_id($post->ID);
            $broll_path = $broll_id > 0 ? get_attached_file($broll_id) : '';
            $reel = $this->ensure_social_reel($post, $settings, $copy, $broll_path);
            if (is_wp_error($reel)) {
                return $reel;
            }
            $prepared['video_id'] = (int) $reel['id'];
            $prepared['video_url'] = (string) $reel['url'];
            $prepared['video_path'] = (string) $reel['path'];
        }
        $wpdb->update($this->social_table(), array(
            'image_attachment_id' => (int) $image_id,
            'copy_json' => wp_json_encode($copy),
        ), array('id' => (int) $row['id']));
        return $prepared;
    }

    private function claim_instagram_pack($lead, $limit, $settings) {
        global $wpdb;
        $table = $this->social_table();
        $wanted = $this->social_slide_count($settings);
        $pack = array($lead);
        $seen = array((int) $lead['post_id'] => true);
        $slots_left = max(0, $limit - $this->social_published_today());
        $new_in_pack = ($this->social_post_published_today((int) $lead['post_id']) || $this->social_item_is_manual_repost($lead)) ? 0 : 1;
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued' AND platform = %s AND id != %d ORDER BY created_at ASC LIMIT 25",
            (string) $lead['platform'],
            (int) $lead['id']
        ), ARRAY_A);
        foreach ((array) $candidates as $candidate) {
            if (count($pack) >= $wanted) {
                break;
            }
            $post_id = (int) $candidate['post_id'];
            if (isset($seen[$post_id]) || $this->social_item_is_manual_repost($candidate) || !$this->social_row_is_pack($candidate, $settings)) {
                continue;
            }
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            $is_new = !$this->social_post_published_today($post_id);
            if ($is_new && $new_in_pack >= $slots_left) {
                continue;
            }
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}tr_ai_wire_social SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'queued'",
                current_time('mysql', true),
                (int) $candidate['id']
            ));
            if ($claimed !== 1) {
                continue;
            }
            $candidate['status'] = 'processing';
            $pack[] = $candidate;
            $seen[$post_id] = true;
            if ($is_new) {
                $new_in_pack++;
            }
        }
        return $pack;
    }

    private function publish_instagram_pack($pack, $credentials, $settings) {
        if (empty($credentials['instagram_user_access_token']) || empty($credentials['instagram_user_id'])) {
            return new WP_Error('tr_ai_wire_ig_missing_token', 'The Instagram access token or account ID is no longer configured.');
        }
        $prepared = array();
        foreach ($pack as $row) {
            $item = $this->prepare_social_delivery($row, $settings);
            if (is_wp_error($item)) {
                return $item;
            }
            $prepared[] = $item;
        }
        if (count($prepared) < 2) {
            $first = $prepared[0];
            return $this->publish_instagram_post($first['copy']['instagram'], $first['copy']['instagram_alt'], $first['image_url'], $credentials);
        }
        $format = $this->normalize_social_post_format($settings['social_post_format'] ?? 'carousel');
        $caption = $this->instagram_pack_caption($prepared);
        $alt = (string) ($prepared[0]['copy']['instagram_alt'] ?? (self::site_brand_name() . ' stories'));
        if ($format === 'collage') {
            $bytes = array();
            foreach ($prepared as $item) {
                $jpeg = file_get_contents($item['image_path']);
                if (is_string($jpeg) && $jpeg !== '') {
                    $bytes[] = $jpeg;
                }
            }
            $collage = $this->compose_social_collage($bytes);
            if (is_wp_error($collage)) {
                return $collage;
            }
            $stored = $this->store_social_jpeg($prepared[0]['post'], $collage, self::site_brand_name() . ' roundup', $prepared[0]['copy'], 'collage', 'pack');
            if (is_wp_error($stored)) {
                return $stored;
            }
            $url = wp_get_attachment_url((int) $stored);
            if (!$url) {
                return new WP_Error('tr_ai_wire_ig_image_url', 'The Instagram collage must have a public HTTP(S) Media Library URL.');
            }
            return $this->publish_instagram_post($caption, $alt, array($url), $credentials);
        }
        $urls = array();
        foreach ($prepared as $item) {
            $urls[] = $item['image_url'];
        }
        return $this->publish_instagram_post($caption, $alt, $urls, $credentials);
    }

    private function publish_x_pack($pack, $credentials, $settings) {
        if (!self::x_credentials_ready($credentials)) {
            return new WP_Error('tr_ai_wire_x_missing_token', 'X is not connected. Save API Key, API Key Secret, Access Token, and Access Token Secret from the X developer portal Keys and tokens page.');
        }
        $prepared = array();
        foreach ($pack as $row) {
            $item = $this->prepare_social_delivery($row, $settings);
            if (is_wp_error($item)) {
                return $item;
            }
            $prepared[] = $item;
        }
        if (count($prepared) < 2) {
            $first = $prepared[0];
            return $this->publish_x_post($first['copy']['x'], $first['image_path'], $credentials);
        }
        $paths = array();
        foreach ($prepared as $item) {
            if (!empty($item['image_path'])) {
                $paths[] = $item['image_path'];
            }
            if (count($paths) >= 4) {
                break;
            }
        }
        return $this->publish_x_post($this->x_pack_caption($prepared), $paths, $credentials);
    }

    private function x_pack_caption($prepared) {
        $titles = array();
        foreach ($prepared as $item) {
            $copy = is_array($item['copy'] ?? null) ? $item['copy'] : array();
            $title = trim((string) ($copy['on_image_headline'] ?? $copy['hook'] ?? ''));
            if ($title === '' && $item['post'] instanceof WP_Post) {
                $title = (string) $item['post']->post_title;
            }
            if ($title !== '') {
                $titles[] = $title;
            }
        }
        $caption = 'Today on ' . self::site_brand_name() . ': ' . implode(' • ', $titles);
        $home = home_url('/');
        if (strpos($caption, $home) === false) {
            $caption .= ' ' . $home;
        }
        return $this->clip_social_block($caption, 280);
    }

    private function instagram_pack_caption($prepared) {
        $lines = array('Today on ' . self::site_brand_name() . ':');
        $hashtags = array();
        $cta = 'Read the full stories on ' . self::site_brand_name();
        foreach ($prepared as $item) {
            $copy = is_array($item['copy'] ?? null) ? $item['copy'] : array();
            $title = trim((string) ($copy['on_image_headline'] ?? ''));
            if ($title === '' && $item['post'] instanceof WP_Post) {
                $title = (string) $item['post']->post_title;
            }
            if ($title !== '') {
                $lines[] = '• ' . $title;
            }
            if ($cta === 'Read the full stories on ' . self::site_brand_name() && !empty($copy['cta'])) {
                $cta = (string) $copy['cta'];
            }
            foreach ((array) ($copy['hashtags'] ?? array()) as $tag) {
                $tag = ltrim(strtolower((string) $tag), '#');
                if ($tag !== '' && !in_array($tag, $hashtags, true)) {
                    $hashtags[] = $tag;
                }
                if (count($hashtags) >= 8) {
                    break;
                }
            }
        }
        $caption = implode("\n", $lines) . "\n\n" . $cta;
        $home = home_url('/');
        if (strpos($caption, $home) === false) {
            $caption .= "\n" . $home;
        }
        if ($hashtags) {
            $tag_line = '';
            foreach ($hashtags as $tag) {
                $tag_line .= '#' . $tag . ' ';
            }
            $caption .= "\n\n" . trim($tag_line);
        }
        return $this->clip_social_block($caption, 2200);
    }

    private function finish_instagram_pack($pack, $result) {
        global $wpdb;
        $table = $this->social_table();
        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            if ($code === 'tr_ai_wire_social_image_busy') {
                foreach ($pack as $row) {
                    $wpdb->update($table, array('status' => 'queued', 'updated_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
                }
                wp_schedule_single_event(time() + 60, self::SOCIAL_CRON_HOOK);
                return;
            }
            if ($code === 'tr_ai_wire_ig_quota_full') {
                foreach ($pack as $row) {
                    $wpdb->update($table, array(
                        'status' => 'queued',
                        'error_text' => $result->get_error_message(),
                        'updated_at' => current_time('mysql', true),
                    ), array('id' => (int) $row['id']));
                }
                wp_schedule_single_event(strtotime('+1 hour'), self::SOCIAL_CRON_HOOK);
                return;
            }
            $this->finish_social_item($pack[0]['id'], 'needs_review', '', '', $result->get_error_message());
            foreach (array_slice($pack, 1) as $row) {
                $wpdb->update($table, array('status' => 'queued', 'updated_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
            }
            return;
        }
        foreach ($pack as $row) {
            $this->finish_social_item($row['id'], 'published', $result['id'], $result['url'], '');
        }
    }

    private function social_copy($post, $settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $cached = get_post_meta($post->ID, '_tr_ai_wire_social_copy', true);
        $decoded = null;
        if (is_string($cached) && $cached !== '') {
            $maybe = json_decode($cached, true);
            if (is_array($maybe) && !empty($maybe['x']) && !empty($maybe['instagram'])) {
                $decoded = $maybe;
                if (!$this->social_wants_reel($settings) || $this->writer_reel_script_present($decoded)) {
                    return $this->sanitize_social_copy($decoded, $post);
                }
            }
        }
        $generated = $this->generate_social_copy($post, $settings);
        if (!is_wp_error($generated) && is_array($generated)) {
            $copy = $this->sanitize_social_copy($generated, $post);
            update_post_meta($post->ID, '_tr_ai_wire_social_copy', wp_json_encode($copy));
            return $copy;
        }
        if (is_array($decoded)) {
            return $this->sanitize_social_copy($decoded, $post);
        }
        $copy = $this->sanitize_social_copy(array(), $post);
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
            . 'on_image_headline: Instagram poster headline, 4–9 words, punchy, can include a number only if it is in the article. Written to sit in huge condensed type on a photo, so keep it short enough for 2–3 lines. on_image_subhead: one supporting line, max 10 words. '
            . 'visual_brief: describe only the photograph (tight device close-up, crowded office from behind, factory, night city, server hall). Prefer dusk, night, or hard rim light. Do not include the headline wording. Anonymous people from behind or in shadow are ok. No celebrities, no real brand logos, no screenshots, no illustration, no letters or numbers in the artwork (type is overlaid later). '
            . 'x_text: hook plus one curiosity or value line; do not include the URL. instagram_caption: hook, 2–4 short lines of value/story, then CTA; do not include the URL. cta: tell them to read the full story on ' . $brand . '. hashtags: 3–5 specific tags for this site, no spam. instagram_alt: factual description of the graphic. '
            . 'reel_script: write the spoken Reel in this same Writer pass — do not leave the voiceover for another model. 40–55 spoken words, about 15–18 seconds, THIS article only. The viewer must learn the takeaway without opening the article. Three beats: hook in the first sentence; the one fact or mechanism from key_takeaways (or the lede if none); what that changes for the viewer\'s phone, job, or money. Do not tease. Do not say “full story” or “link in bio” until after the lesson. No hashtags, no URL, no packing other stories into a roundup. '
            . 'reel_captions: 3–5 short on-screen lines that match spoken phrases, max 6 words each. These are captions under a voiceover, not poster headlines. ';
        if ($profile) {
            $user .= "Site context (follow this):\n" . wp_json_encode($profile) . "\n";
        }
        $user .= "Article JSON:\n" . wp_json_encode($snapshot)
            . "\nReturn JSON keys: hook, on_image_headline, on_image_subhead, visual_brief, cta, x_text, instagram_caption, instagram_alt, hashtags (array), reel_script, reel_captions (array).";
        $copy_settings = $s;
        $copy_settings['max_output_tokens'] = min(1600, max(400, (int) ($s['max_output_tokens'] ?? 1600)));
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
        $headline = $this->clip_social_text($raw['on_image_headline'] ?? $hook, 70);
        $subhead = $this->clip_social_text($raw['on_image_subhead'] ?? $excerpt, 56);
        $profile = $this->site_context_profile();
        $default_cta = !empty($profile['cta_style']) ? $profile['cta_style'] : ('Read the full story on ' . self::site_brand_name());
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
        $copy = array(
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
        $copy['reel_script'] = $this->sanitize_reel_script($raw['reel_script'] ?? '', $copy, $post);
        $copy['reel_captions'] = $this->sanitize_reel_captions($raw['reel_captions'] ?? array(), $copy['reel_script']);
        return $copy;
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
        $copy['_poster_variant'] = $this->social_poster_variant_for_post($post);
        $headline = (string) ($copy['on_image_headline'] ?? $post->post_title);
        $prompt = $this->social_image_prompt($post, $copy);
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
        $attachment_id = $this->store_social_jpeg($post, $branded, $headline, $copy, $copy['_poster_variant']);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        if ($this->social_wants_reel($settings)) {
            $broll = $this->crop_social_broll($bytes);
            if (is_wp_error($broll)) {
                return $broll;
            }
            $broll_id = $this->store_social_broll($post, $broll, $copy);
            if (is_wp_error($broll_id)) {
                return $broll_id;
            }
            update_post_meta((int) $post->ID, '_tr_ai_wire_social_broll_id', (int) $broll_id);
        }
        delete_post_meta((int) $post->ID, '_tr_ai_wire_social_regenerate_image');
        return (int) $attachment_id;
    }

    private function social_image_is_current($image_id) {
        $image_id = (int) $image_id;
        if (!$this->social_image_file_exists($image_id)) {
            return false;
        }
        return (string) get_post_meta($image_id, '_tr_ai_wire_social_layout', true) === self::SOCIAL_IMAGE_LAYOUT;
    }

    private function social_image_file_exists($image_id) {
        $image_id = (int) $image_id;
        if ($image_id < 1 || !wp_attachment_is_image($image_id)) {
            return false;
        }
        $path = get_attached_file($image_id);
        return $path && file_exists($path);
    }

    private function social_existing_image_id($post_id, $row = null) {
        $post_id = (int) $post_id;
        $image_id = is_array($row) ? (int) ($row['image_attachment_id'] ?? 0) : 0;
        if ($this->social_image_file_exists($image_id)) {
            return $image_id;
        }
        $image_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_image_id', true);
        return $this->social_image_file_exists($image_id) ? $image_id : 0;
    }

    private function social_image_prompt($post, $copy = array()) {
        $copy = is_array($copy) ? $copy : array();
        $brief = $this->social_image_scene_brief((string) ($copy['visual_brief'] ?? ''));
        $profile = $this->site_context_profile();
        $revision = ($post instanceof WP_Post) ? (int) get_post_meta($post->ID, '_tr_ai_wire_social_image_revision', true) : 0;
        $variant = $this->normalize_poster_variant($copy['_poster_variant'] ?? $this->social_poster_variant_for_post($post));
        $prompt = 'Untitled photoreal cinematic photograph, vertical 4:5, full-bleed, no graphic design. '
            . 'One real-world scene, magazine lighting, shallow depth of field, high contrast. This is a photograph, not a poster, not a title card, not a thumbnail. '
            . $this->social_image_safe_zone($variant) . ' '
            . 'Do not render any text, letters, numbers, captions, headlines, subtitles, logos, watermarks, lower-thirds, or typography of any kind. '
            . 'Do not draw a poster, card, border, navy panel, illustration, cartoon, 3D render, collage, infographic, UI screenshot, or product mockup. '
            . 'Do not imitate a specific existing photo. Anonymous people (backs, silhouettes, generic office workers) are allowed. Never a real celebrity. '
            . 'Art direction: ' . $this->social_image_art_direction($profile) . '. '
            . 'Camera: ' . $this->social_image_camera($post, $revision) . '. '
            . ($brief !== '' ? 'Scene: ' . $brief . '. ' : 'Scene: one clear technology-news photograph with a single subject. ')
            . 'Keep the subject in the center of the frame so a 9:16 crop still holds. '
            . 'Make the picture stop a social-media scroll.';
        if ($revision > 0) {
            $prompt .= ' This is regeneration attempt ' . $revision . '. Use a different camera angle, tighter crop, and stronger lighting than a generic stock photo. Prefer dusk, night, or dramatic rim light if it still matches the story. Still no text in the photograph.';
        }
        return $prompt;
    }

    private function social_poster_variants() {
        return array('bottom-center', 'left-stack', 'kicker-bar', 'bottom-left', 'cover-top');
    }

    private function normalize_poster_variant($variant) {
        $variant = sanitize_key((string) $variant);
        return in_array($variant, $this->social_poster_variants(), true) ? $variant : 'bottom-center';
    }

    private function social_poster_variant_for_post($post) {
        $id = ($post instanceof WP_Post) ? (int) $post->ID : (int) $post;
        $revision = $id > 0 ? (int) get_post_meta($id, '_tr_ai_wire_social_image_revision', true) : 0;
        $variants = $this->social_poster_variants();
        $index = (int) (sprintf('%u', crc32($id . ':' . $revision)) % count($variants));
        return $variants[$index];
    }

    private function social_image_safe_zone($variant) {
        if ($variant === 'left-stack') {
            return 'Keep the left third of the frame naturally dark and empty of objects so we can overlay type later. Put the subject on the right.';
        }
        if ($variant === 'cover-top') {
            return 'Keep the upper third of the frame naturally darker and simpler so we can overlay type later. Put the subject in the lower half.';
        }
        return 'The lower half of the frame is naturally dark and empty of objects so we can overlay type later.';
    }

    private function normalize_social_post_format($format) {
        $format = sanitize_key((string) $format);
        return in_array($format, array('single', 'carousel', 'collage', 'reel'), true) ? $format : 'single';
    }

    private function social_slide_count($settings) {
        $format = $this->normalize_social_post_format(is_array($settings) ? ($settings['social_post_format'] ?? 'single') : 'single');
        if ($format === 'single' || $format === 'reel') {
            return 1;
        }
        $count = (int) (is_array($settings) ? ($settings['social_slide_count'] ?? 5) : 5);
        if (!in_array($count, array(4, 5, 6), true)) {
            $count = 5;
        }
        $limit = min(20, max(1, (int) (is_array($settings) ? ($settings['social_daily_limit'] ?? 10) : 10)));
        return min($count, $limit);
    }

    private function social_batch_ids() {
        $stored = get_option(self::SOCIAL_BATCH_OPTION, array());
        $ids = is_array($stored) ? ($stored['post_ids'] ?? $stored) : array();
        $clean = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        return $clean;
    }

    private function social_batch_save($ids) {
        update_option(self::SOCIAL_BATCH_OPTION, array(
            'post_ids' => array_values(array_map('intval', $ids)),
            'updated_at' => current_time('mysql', true),
        ), false);
    }

    private function collect_social_article($post_id, $settings) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return;
        }
        $format = $this->normalize_social_post_format($settings['social_post_format'] ?? 'single');
        if ($format === 'single' || $format === 'reel') {
            $this->enqueue_social_article($post_id, 'single');
            $this->kick_social_queue(30);
            return;
        }
        $mix = $this->social_mix_today();
        $carousel_left = max(0, (int) ($settings['social_carousel_daily_limit'] ?? 2) - $mix['carousel']);
        $single_left = max(0, (int) ($settings['social_single_daily_limit'] ?? 3) - $mix['single']);
        if ($carousel_left > 0) {
            $ids = $this->social_batch_ids();
            if (!in_array($post_id, $ids, true)) {
                $ids[] = $post_id;
                $this->social_batch_save($ids);
            }
            if (count($ids) >= $this->social_slide_count($settings)) {
                $this->flush_social_batch(true);
            }
            return;
        }
        if ($single_left > 0) {
            $this->enqueue_social_article($post_id, 'single');
            $this->social_mix_add('single');
            $this->kick_social_queue(30);
        }
    }

    public function queue_missing_platform_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to queue social publishing.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_queue_missing_platform');
        $platform = sanitize_key((string) ($_POST['platform'] ?? ''));
        if (!in_array($platform, array('x', 'instagram'), true) || !in_array($platform, self::connected_social_platforms(), true)) {
            wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social'));
            exit;
        }
        $queued = $this->enqueue_missing_platform_posts($platform);
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tr-ai-wire-social',
            'social_backfill' => (int) $queued,
            'social_backfill_platform' => $platform,
        ), admin_url('admin.php')));
        exit;
    }

    private function enqueue_missing_platform_posts($platform) {
        $platform = sanitize_key((string) $platform);
        if (!in_array($platform, self::connected_social_platforms(), true)) {
            return 0;
        }
        global $wpdb;
        $table = $this->social_table();
        $post_ids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->prefix}tr_ai_wire_social ORDER BY created_at DESC LIMIT 50");
        $queued = 0;
        $now = current_time('mysql', true);
        foreach ((array) $post_ids as $post_id) {
            $post_id = (int) $post_id;
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = %s LIMIT 1",
                $post_id,
                $platform
            ));
            if ($exists > 0) {
                continue;
            }
            $image_id = $this->social_existing_image_id($post_id);
            $sibling = $wpdb->get_row($wpdb->prepare(
                "SELECT image_attachment_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND image_attachment_id > 0 ORDER BY id DESC LIMIT 1",
                $post_id
            ), ARRAY_A);
            if ($image_id < 1 && $sibling) {
                $image_id = $this->social_image_file_exists((int) $sibling['image_attachment_id']) ? (int) $sibling['image_attachment_id'] : 0;
            }
            $inserted = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}tr_ai_wire_social (post_id, platform, status, image_attachment_id, created_at, updated_at) VALUES (%d, %s, 'queued', %d, %s, %s)",
                $post_id,
                $platform,
                $image_id,
                $now,
                $now
            ));
            if ($inserted === 1) {
                $queued++;
            }
        }
        if ($queued > 0) {
            $this->kick_social_queue(5);
        }
        return $queued;
    }

    private function missing_platform_post_count($platform) {
        $platform = sanitize_key((string) $platform);
        if (!in_array($platform, self::connected_social_platforms(), true)) {
            return 0;
        }
        global $wpdb;
        $table = $this->social_table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT s.post_id) FROM {$wpdb->prefix}tr_ai_wire_social s
             WHERE NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}tr_ai_wire_social p WHERE p.post_id = s.post_id AND p.platform = %s
             )",
            $platform
        ));
    }

    private function enqueue_social_article($post_id, $mode = 'single') {
        $post_id = (int) $post_id;
        $platforms = self::connected_social_platforms();
        if ($post_id < 1 || !$platforms) {
            return;
        }
        update_post_meta($post_id, '_tr_ai_wire_social_mode', $mode === 'pack' ? 'pack' : 'single');
        global $wpdb;
        $table = $this->social_table();
        $now = current_time('mysql', true);
        foreach ($platforms as $platform) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}tr_ai_wire_social (post_id, platform, status, created_at, updated_at) VALUES (%d, %s, 'queued', %s, %s)",
                $post_id,
                $platform,
                $now,
                $now
            ));
        }
    }

    private function flush_social_batch($force = false, $allow_singles = false) {
        $ids = $this->social_batch_ids();
        if (!$ids) {
            return false;
        }
        $settings = $this->settings();
        $wanted = $this->social_slide_count($settings);
        $mix = $this->social_mix_today();
        $carousel_left = max(0, (int) ($settings['social_carousel_daily_limit'] ?? 2) - $mix['carousel']);
        $single_left = max(0, (int) ($settings['social_single_daily_limit'] ?? 3) - $mix['single']);
        if (!$force && count($ids) < $wanted) {
            return false;
        }
        $as_pack = $carousel_left > 0;
        if (!$as_pack && !($allow_singles && $single_left > 0)) {
            if (!$force) {
                return false;
            }
            if (!$allow_singles) {
                return false;
            }
        }
        $this->social_batch_save(array());
        $mode = $as_pack ? 'pack' : 'single';
        $queued = 0;
        foreach ($ids as $post_id) {
            if ($mode === 'single' && $queued >= $single_left) {
                break;
            }
            $post = get_post((int) $post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            $this->enqueue_social_article((int) $post_id, $mode);
            $queued++;
        }
        if ($queued < 1) {
            return false;
        }
        $this->social_mix_add($mode === 'pack' ? 'carousel' : 'single', $mode === 'pack' ? 1 : $queued);
        $this->kick_social_queue(30);
        return true;
    }

    private function social_mix_option_name() {
        return self::SOCIAL_MIX_OPTION . '_' . gmdate('Ymd');
    }

    private function social_mix_today() {
        $stored = get_option($this->social_mix_option_name(), array());
        return array(
            'carousel' => max(0, (int) (is_array($stored) ? ($stored['carousel'] ?? 0) : 0)),
            'single' => max(0, (int) (is_array($stored) ? ($stored['single'] ?? 0) : 0)),
        );
    }

    private function social_mix_add($kind, $by = 1) {
        $mix = $this->social_mix_today();
        $kind = $kind === 'carousel' ? 'carousel' : 'single';
        $mix[$kind] += max(1, (int) $by);
        update_option($this->social_mix_option_name(), $mix, false);
    }

    private function social_row_is_pack($row, $settings) {
        $mode = (string) get_post_meta((int) ($row['post_id'] ?? 0), '_tr_ai_wire_social_mode', true);
        if ($mode === 'single') {
            return false;
        }
        if ($mode === 'pack') {
            return true;
        }
        $format = $this->normalize_social_post_format($settings['social_post_format'] ?? 'single');
        return $format !== 'single' && $format !== 'reel';
    }

    private function store_social_jpeg($post, $bytes, $headline, $copy, $variant, $suffix = '', $layout = null) {
        $name = 'technorapper-social-' . (int) $post->ID;
        $suffix = sanitize_file_name((string) $suffix);
        if ($suffix !== '') {
            $name .= '-' . $suffix;
        }
        $upload = wp_upload_bits($name . '.jpg', null, $bytes);
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
        update_post_meta($attachment_id, '_tr_ai_wire_social_layout', sanitize_key((string) ($layout ? $layout : self::SOCIAL_IMAGE_LAYOUT)));
        update_post_meta($attachment_id, '_tr_ai_wire_social_variant', sanitize_key((string) $variant));
        return (int) $attachment_id;
    }

    private function compose_social_collage($jpegs) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return new WP_Error('tr_ai_wire_social_gd', 'PHP GD is required to render a social collage.');
        }
        $jpegs = array_values(array_filter((array) $jpegs, 'is_string'));
        if (count($jpegs) < 2) {
            return $jpegs[0] ?? new WP_Error('tr_ai_wire_social_collage', 'A collage needs at least two graphics.');
        }
        $width = 1080;
        $height = 1350;
        $gap = 8;
        $canvas = imagecreatetruecolor($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 0, 0, 0));
        foreach ($this->social_collage_cells(count($jpegs), $width, $height, $gap) as $index => $cell) {
            if (!isset($jpegs[$index])) {
                break;
            }
            $source = @imagecreatefromstring($jpegs[$index]);
            if (!$source) {
                continue;
            }
            imagecopyresampled($canvas, $source, $cell['x'], $cell['y'], 0, 0, $cell['w'], $cell['h'], imagesx($source), imagesy($source));
            imagedestroy($source);
        }
        ob_start();
        imagejpeg($canvas, null, 88);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_collage', 'Could not render the collage.');
    }

    private function social_collage_cells($count, $width, $height, $gap) {
        $count = max(2, min(6, (int) $count));
        $gap = max(0, (int) $gap);
        $width = (int) $width;
        $height = (int) $height;
        if ($count === 2) {
            $cell_w = (int) floor(($width - $gap) / 2);
            return array(
                array('x' => 0, 'y' => 0, 'w' => $cell_w, 'h' => $height),
                array('x' => $cell_w + $gap, 'y' => 0, 'w' => $width - $cell_w - $gap, 'h' => $height),
            );
        }
        if ($count === 3) {
            $top_h = (int) floor(($height - $gap) / 2);
            $cell_w = (int) floor(($width - $gap) / 2);
            return array(
                array('x' => 0, 'y' => 0, 'w' => $width, 'h' => $top_h),
                array('x' => 0, 'y' => $top_h + $gap, 'w' => $cell_w, 'h' => $height - $top_h - $gap),
                array('x' => $cell_w + $gap, 'y' => $top_h + $gap, 'w' => $width - $cell_w - $gap, 'h' => $height - $top_h - $gap),
            );
        }
        if ($count === 4) {
            $cell_w = (int) floor(($width - $gap) / 2);
            $cell_h = (int) floor(($height - $gap) / 2);
            return array(
                array('x' => 0, 'y' => 0, 'w' => $cell_w, 'h' => $cell_h),
                array('x' => $cell_w + $gap, 'y' => 0, 'w' => $width - $cell_w - $gap, 'h' => $cell_h),
                array('x' => 0, 'y' => $cell_h + $gap, 'w' => $cell_w, 'h' => $height - $cell_h - $gap),
                array('x' => $cell_w + $gap, 'y' => $cell_h + $gap, 'w' => $width - $cell_w - $gap, 'h' => $height - $cell_h - $gap),
            );
        }
        if ($count === 5) {
            $top_h = (int) floor(($height - $gap) / 2);
            $bot_h = $height - $top_h - $gap;
            $top_w = (int) floor(($width - $gap) / 2);
            $bot_w = (int) floor(($width - (2 * $gap)) / 3);
            return array(
                array('x' => 0, 'y' => 0, 'w' => $top_w, 'h' => $top_h),
                array('x' => $top_w + $gap, 'y' => 0, 'w' => $width - $top_w - $gap, 'h' => $top_h),
                array('x' => 0, 'y' => $top_h + $gap, 'w' => $bot_w, 'h' => $bot_h),
                array('x' => $bot_w + $gap, 'y' => $top_h + $gap, 'w' => $bot_w, 'h' => $bot_h),
                array('x' => (2 * $bot_w) + (2 * $gap), 'y' => $top_h + $gap, 'w' => $width - (2 * $bot_w) - (2 * $gap), 'h' => $bot_h),
            );
        }
        $cell_w = (int) floor(($width - (2 * $gap)) / 3);
        $cell_h = (int) floor(($height - $gap) / 2);
        $cells = array();
        for ($row = 0; $row < 2; $row++) {
            for ($col = 0; $col < 3; $col++) {
                $x = $col * ($cell_w + $gap);
                $y = $row * ($cell_h + $gap);
                $w = ($col === 2) ? ($width - $x) : $cell_w;
                $h = ($row === 1) ? ($height - $y) : $cell_h;
                $cells[] = array('x' => $x, 'y' => $y, 'w' => $w, 'h' => $h);
            }
        }
        return $cells;
    }

    private function social_image_camera($post, $revision = 0) {
        $cameras = array(
            'tight overhead still life of one device on a dark desk, hard side light',
            'over-the-shoulder of a person at a glowing monitor, face not identifiable',
            'extreme close-up of a phone or laptop edge, bokeh lights behind',
            'wide office floor from behind, one figure walking, dusk windows',
            'macro of a keyboard, cable, or chip with a single rim light',
        );
        $id = ($post instanceof WP_Post) ? (int) $post->ID : (int) $post;
        $index = (int) (sprintf('%u', crc32('cam:' . $id . ':' . (int) $revision)) % count($cameras));
        return $cameras[$index];
    }

    private function social_image_art_direction($profile) {
        $default = 'cinematic photoreal news photography, dusk or night rim light, one hero subject filling the frame';
        $style = trim((string) ($profile['visual_style'] ?? ''));
        if ($style === '' || preg_match('/illustrat|cartoon|navy|magazine art|vector|flat design|infographic|collage|3d render/i', $style)) {
            return $default;
        }
        return $style;
    }

    private function social_image_scene_brief($brief) {
        $brief = trim((string) $brief);
        if ($brief === '' || preg_match('/illustrat|cartoon|symbolic|navy|vector|infographic|magazine card/i', $brief)) {
            return '';
        }
        return $brief;
    }

    private function forget_cached_social_image($post_id) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return 0;
        }
        delete_post_meta($post_id, '_tr_ai_wire_social_image_id');
        delete_post_meta($post_id, '_tr_ai_wire_social_slide_ids');
        delete_post_meta($post_id, '_tr_ai_wire_social_broll_id');
        delete_post_meta($post_id, '_tr_ai_wire_social_reel_id');
        $revision = (int) get_post_meta($post_id, '_tr_ai_wire_social_image_revision', true);
        update_post_meta($post_id, '_tr_ai_wire_social_image_revision', $revision + 1);
        update_post_meta($post_id, '_tr_ai_wire_social_regenerate_image', '1');
        global $wpdb;
        $table = $this->social_table();
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tr_ai_wire_social SET image_attachment_id = 0, updated_at = %s WHERE post_id = %d AND status IN ('queued', 'needs_review')",
            current_time('mysql', true),
            $post_id
        ));
        return is_int($updated) ? $updated : 0;
    }

    private function requeue_social_item($id, $regenerate_image = false) {
        $id = (int) $id;
        if ($id < 1) {
            return false;
        }
        global $wpdb;
        $table = $this->social_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $status = (string) ($row['status'] ?? '');
        if (!$row || !in_array($status, array('needs_review', 'published'), true)) {
            return false;
        }
        $post_id = (int) $row['post_id'];
        $from_published = $status === 'published';
        if ($from_published) {
            $deleted = $this->delete_live_social_post($row);
            if (is_wp_error($deleted)) {
                $wpdb->update($table, array(
                    'error_text' => $deleted->get_error_message(),
                    'updated_at' => current_time('mysql', true),
                ), array('id' => $id, 'status' => 'published'));
                return false;
            }
            $regenerate_image = true;
            update_post_meta($post_id, '_tr_ai_wire_social_repost_' . $id, '1');
        }
        $cached = $this->social_existing_image_id($post_id, $row);
        $rebuild = (bool) $regenerate_image;
        if ($rebuild) {
            $this->forget_cached_social_image($post_id);
        }
        $fields = array(
            'status' => 'queued',
            'error_text' => null,
            'remote_id' => '',
            'remote_url' => '',
            'updated_at' => current_time('mysql', true),
        );
        if ($rebuild) {
            $fields['image_attachment_id'] = 0;
        }
        $updated = $wpdb->update($table, $fields, array('id' => $id, 'status' => $status));
        if ($updated !== 1) {
            return false;
        }
        $this->kick_social_queue(5);
        return true;
    }

    private function delete_live_social_post($row) {
        $platform = (string) ($row['platform'] ?? '');
        $remote_id = sanitize_text_field((string) ($row['remote_id'] ?? ''));
        if ($remote_id === '') {
            return true;
        }
        $credentials = $this->social_credentials();
        if ($platform === 'instagram') {
            return $this->delete_instagram_media($remote_id, $credentials);
        }
        if ($platform === 'x') {
            return $this->delete_x_post($remote_id, $credentials);
        }
        return true;
    }

    private function delete_instagram_media($media_id, $credentials) {
        $credentials = $this->instagram_resolve_publish_credentials($credentials);
        if (is_wp_error($credentials)) {
            return $credentials;
        }
        $media_id = preg_replace('/[^0-9_]/', '', (string) $media_id);
        if ($media_id === '') {
            return true;
        }
        $url = add_query_arg(
            array('access_token' => $credentials['instagram_user_access_token']),
            'https://graph.facebook.com/' . self::INSTAGRAM_GRAPH_VERSION . '/' . rawurlencode($media_id)
        );
        $response = wp_remote_request($url, array('timeout' => 30, 'method' => 'DELETE'));
        return $this->social_delete_result($response, 'Instagram');
    }

    private function delete_x_post($tweet_id, $credentials) {
        if (!self::x_credentials_ready($credentials)) {
            return new WP_Error('tr_ai_wire_x_delete', 'X Keys and tokens are no longer configured.');
        }
        $tweet_id = preg_replace('/[^0-9]/', '', (string) $tweet_id);
        if ($tweet_id === '') {
            return true;
        }
        $url = 'https://api.x.com/2/tweets/' . rawurlencode($tweet_id);
        $response = wp_remote_request($url, array(
            'timeout' => 30,
            'method' => 'DELETE',
            'headers' => array('Authorization' => $this->x_authorization_header('DELETE', $url, $credentials)),
        ));
        return $this->social_delete_result($response, 'X');
    }

    private function social_delete_result($response, $platform) {
        if (is_wp_error($response)) {
            if ($this->social_remote_already_gone($response->get_error_message())) {
                return true;
            }
            return new WP_Error('tr_ai_wire_social_delete', $platform . ' could not delete the live post: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : array();
        $message = sanitize_text_field(
            $data['error']['message']
            ?? $data['detail']
            ?? $data['title']
            ?? ($data['errors'][0]['message'] ?? '')
        );
        if ($status >= 200 && $status < 300) {
            return true;
        }
        if ($this->social_remote_already_gone($message, $status)) {
            return true;
        }
        if ($message === '') {
            $message = $platform . ' returned HTTP ' . $status . ' while deleting the live post.';
        }
        return new WP_Error('tr_ai_wire_social_delete', $platform . ' could not delete the live post: ' . $message);
    }

    private function social_remote_already_gone($message, $status = 0) {
        if (in_array((int) $status, array(404, 410), true)) {
            return true;
        }
        $message = (string) $message;
        return stripos($message, 'does not exist') !== false
            || stripos($message, 'not found') !== false
            || stripos($message, 'already deleted') !== false
            || stripos($message, 'cannot be loaded') !== false
            || stripos($message, 'unknown media') !== false;
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
        $subhead = trim(wp_strip_all_tags((string) ($copy['on_image_subhead'] ?? '')));
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
        $variant = $this->normalize_poster_variant($copy['_poster_variant'] ?? 'bottom-center');
        $this->draw_social_poster_variant($canvas, $variant, $headline, $subhead, $target_width, $target_height);
        ob_start();
        imagejpeg($canvas, null, 88);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render the branded image.');
    }

    private function draw_social_poster_variant($canvas, $variant, $headline, $subhead, $width, $height) {
        $font = $this->social_font_path();
        $cyan = imagecolorallocate($canvas, 43, 213, 255);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 220, 226, 232);
        $stroke = imagecolorallocate($canvas, 0, 0, 0);
        if ($variant === 'left-stack') {
            $this->draw_social_left_gradient($canvas, $width, $height);
            $this->draw_social_bottom_gradient($canvas, $width, $height, 0.58);
            imagefilledrectangle($canvas, 0, 0, 10, $height, $cyan);
            $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, 78);
            $this->draw_social_type_block($canvas, $headline, $subhead, $font, $white, $muted, $stroke, $width, $height, array(
                'align' => 'left',
                'max_width' => 640,
                'left' => 48,
                'max_block' => (int) round($height * 0.5),
                'min_baseline' => 560,
                'handle' => 'left',
            ));
            return;
        }
        if ($variant === 'bottom-left') {
            $this->draw_social_bottom_gradient($canvas, $width, $height);
            $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, 78);
            $this->draw_social_type_block($canvas, $headline, $subhead, $font, $white, $muted, $stroke, $width, $height, array(
                'align' => 'left',
                'max_width' => 980,
                'left' => 48,
                'max_block' => (int) round($height * 0.46),
                'min_baseline' => 620,
                'handle' => 'left',
            ));
            return;
        }
        if ($variant === 'kicker-bar') {
            $this->draw_social_bottom_gradient($canvas, $width, $height);
            $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, 78);
            $this->draw_social_type_block($canvas, $headline, $subhead, $font, $white, $muted, $stroke, $width, $height, array(
                'align' => 'left',
                'max_width' => 980,
                'left' => 48,
                'max_block' => (int) round($height * 0.42),
                'min_baseline' => 640,
                'handle' => 'right',
                'kicker' => ($subhead !== '') ? $this->social_poster_headline($this->clip_social_text($subhead, 28)) : 'TECH NEWS',
            ));
            return;
        }
        if ($variant === 'cover-top') {
            $this->draw_social_top_gradient($canvas, $width, $height);
            $this->draw_social_bottom_gradient($canvas, $width, $height, 0.78);
            $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, $height - 72);
            $this->draw_social_type_block($canvas, $headline, $subhead, $font, $white, $muted, $stroke, $width, $height, array(
                'align' => 'left',
                'max_width' => 980,
                'left' => 48,
                'max_block' => (int) round($height * 0.36),
                'min_baseline' => 180,
                'pin_top' => 180,
                'handle' => 'right',
            ));
            return;
        }
        $this->draw_social_bottom_gradient($canvas, $width, $height);
        $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, 78);
        $this->draw_social_type_block($canvas, $headline, $subhead, $font, $white, $muted, $stroke, $width, $height, array(
            'align' => 'center',
            'max_width' => 1000,
            'max_block' => (int) round($height * 0.46),
            'min_baseline' => 620,
            'handle' => 'right',
        ));
    }

    private function draw_social_brand_mark($canvas, $font, $white, $cyan, $x, $y) {
        $mark = self::site_brand_mark();
        if ($font) {
            imagettftext($canvas, 28, 0, $x, $y, $white, $font, $mark);
            $width = 220;
            if (function_exists('imagettfbbox')) {
                $box = imagettfbbox(28, 0, $font, $mark);
                if (is_array($box)) {
                    $width = max(80, abs((int) $box[2] - (int) $box[0]));
                }
            }
            imagefilledrectangle($canvas, $x, $y + 12, $x + $width, $y + 20, $cyan);
            return;
        }
        imagestring($canvas, 5, $x, $y - 50, $mark, $white);
    }

    private function draw_social_type_block($canvas, $headline, $subhead, $font, $white, $muted, $stroke, $width, $height, $opts) {
        $opts = is_array($opts) ? $opts : array();
        $align = (($opts['align'] ?? '') === 'left') ? 'left' : 'center';
        $left = (int) ($opts['left'] ?? 48);
        $max_width = (int) ($opts['max_width'] ?? 1000);
        $layout = $this->social_poster_headline_layout($headline, $font, $max_width, (int) ($opts['max_block'] ?? 620), 4);
        $subhead_size = $font ? max(24, min(32, (int) round($layout['size'] * 0.28))) : 5;
        $subhead_step = (int) round($subhead_size * 1.2);
        $kicker = trim((string) ($opts['kicker'] ?? ''));
        $kicker_size = $font ? 22 : 5;
        $subhead_lines = ($subhead !== '' && $kicker === '') ? $this->wrap_social_lines($subhead, min(940, $max_width), $subhead_size, $font, 36, 2) : array();
        $handle_y = $height - 28;
            $kicker_block = $kicker !== '' ? ($kicker_size + 28) : 0;
        $subhead_block = $subhead_lines ? (14 + ((count($subhead_lines) - 1) * $subhead_step) + $subhead_size) : 0;
        $line_count = max(1, count($layout['lines']));
        if (!empty($opts['pin_top'])) {
            $y = (int) $opts['pin_top'] + $kicker_block;
        } else {
            $y = $handle_y - 40 - $subhead_block - $kicker_block - (($line_count - 1) * $layout['line_height']);
            $min_baseline = (int) ($opts['min_baseline'] ?? 620);
            if ($y < $min_baseline) {
                $y = $min_baseline;
            }
        }
            if ($kicker !== '') {
            $accent = imagecolorallocate($canvas, 43, 213, 255);
            $ascent = (int) round($layout['size']);
            if ($font && function_exists('imagettfbbox') && !empty($layout['lines'][0])) {
                $box = imagettfbbox($layout['size'], 0, $font, (string) $layout['lines'][0]);
                $ascent = max($ascent, abs((int) $box[7]));
            }
            $kicker_y = $y - $ascent - $kicker_size - 16;
            $this->draw_social_aligned_text($canvas, $kicker, $kicker_size, $kicker_y, $align, $left, $accent, $font, $width, $stroke, 2);
            imagefilledrectangle($canvas, $left, $kicker_y + 8, $left + 96, $kicker_y + 14, $accent);
        }
        foreach ($layout['lines'] as $index => $text_line) {
            $this->draw_social_aligned_text($canvas, $text_line, $layout['size'], $y, $align, $left, $white, $font, $width, $stroke, 3);
            if ($index < $line_count - 1) {
                $y += $layout['line_height'];
            }
        }
        if ($subhead_lines) {
            $y += 18 + $subhead_size;
            foreach ($subhead_lines as $index => $text_line) {
                $this->draw_social_aligned_text($canvas, $text_line, $subhead_size, $y, $align, $left, $muted, $font, $width, $stroke, 2);
                if ($index < count($subhead_lines) - 1) {
                    $y += $subhead_step;
                }
            }
        }
        $handle = (string) ($opts['handle'] ?? 'right');
        $handle_text = self::site_brand_handle();
        if ($font && $handle_text !== '') {
            $hx = ($handle === 'left') ? $left : max($left, $width - 24 - (int) (strlen($handle_text) * 11));
            imagettftext($canvas, 18, 0, $hx, $handle_y, imagecolorallocate($canvas, 43, 213, 255), $font, $handle_text);
        }
    }

    private function social_poster_headline($text) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') {
            return '';
        }
        return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }

    private function social_poster_headline_layout($headline, $font, $max_width, $max_block_height, $max_lines = 4) {
        $headline = $this->social_poster_headline($headline);
        $max_lines = max(1, (int) $max_lines);
        $min_size = $font ? 64 : 5;
        $max_size = $font ? 118 : 5;
        $chosen = array(
            'size' => $min_size,
            'lines' => $this->wrap_social_lines($headline, $max_width, $min_size, $font, 18, $max_lines),
            'line_height' => $font ? (int) round($min_size * 1.08) : 36,
        );
        $chosen['block_height'] = max(1, count($chosen['lines'])) * $chosen['line_height'];
        if ($headline === '' || !$font) {
            return $chosen;
        }
        for ($size = $max_size; $size >= $min_size; $size -= 2) {
            $line_height = (int) round($size * 1.08);
            $lines = $this->wrap_social_lines($headline, $max_width, $size, $font, 18, 12);
            if (count($lines) > $max_lines) {
                continue;
            }
            $block_height = count($lines) * $line_height;
            if ($block_height <= $max_block_height) {
                return array(
                    'size' => $size,
                    'lines' => $lines,
                    'line_height' => $line_height,
                    'block_height' => $block_height,
                );
            }
        }
        return $chosen;
    }

    private function draw_social_bottom_gradient($canvas, $width, $height, $cover_from = 0.62) {
        $start = (int) round($height * 0.36);
        $span = $height - $start;
        if ($span < 1) {
            return;
        }
        for ($i = 0; $i < $span; $i++) {
            $t = $i / $span;
            $alpha = (int) max(0, min(127, (int) round(118 - ($t * 150))));
            $color = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
            imageline($canvas, 0, $start + $i, $width, $start + $i, $color);
        }
        $cover = imagecolorallocatealpha($canvas, 0, 0, 0, 18);
        imagefilledrectangle($canvas, 0, (int) round($height * (float) $cover_from), $width, $height, $cover);
    }

    private function draw_social_top_gradient($canvas, $width, $height) {
        $span = (int) round($height * 0.42);
        for ($i = 0; $i < $span; $i++) {
            $t = 1 - ($i / max(1, $span));
            $alpha = (int) max(0, min(127, (int) round(40 + ($t * 70))));
            $color = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
            imageline($canvas, 0, $i, $width, $i, $color);
        }
    }

    private function draw_social_left_gradient($canvas, $width, $height) {
        $span = (int) round($width * 0.62);
        for ($i = 0; $i < $span; $i++) {
            $t = 1 - ($i / max(1, $span));
            $alpha = (int) max(0, min(127, (int) round(30 + ($t * 80))));
            $color = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
            imageline($canvas, $i, 0, $i, $height, $color);
        }
    }

    private function draw_social_aligned_text($canvas, $text, $size, $y, $align, $left, $color, $font, $canvas_width, $stroke = null, $stroke_width = 0) {
        $text = (string) $text;
        if ($text === '') {
            return;
        }
        $x = (int) $left;
        if ($font && function_exists('imagettfbbox') && $align !== 'left') {
            $box = imagettfbbox($size, 0, $font, $text);
            $text_width = abs($box[2] - $box[0]);
            $x = (int) max(32, ($canvas_width - $text_width) / 2);
        }
        $this->draw_social_stroked_text($canvas, $text, $size, $x, $y, $color, $font, $stroke, $stroke_width);
    }

    private function draw_social_stroked_text($canvas, $text, $size, $x, $y, $color, $font, $stroke = null, $stroke_width = 0) {
        $text = (string) $text;
        if ($text === '') {
            return;
        }
        if ($font && function_exists('imagettftext')) {
            if ($stroke && $stroke_width > 0) {
                $width = (int) $stroke_width;
                for ($ox = -$width; $ox <= $width; $ox++) {
                    for ($oy = -$width; $oy <= $width; $oy++) {
                        if ($ox === 0 && $oy === 0) {
                            continue;
                        }
                        imagettftext($canvas, $size, 0, $x + $ox, $y + $oy, $stroke, $font, $text);
                    }
                }
            }
            imagettftext($canvas, $size, 0, $x, $y, $color, $font, $text);
            return;
        }
        imagestring($canvas, 5, (int) $x, (int) $y - 24, substr($text, 0, 80), $color);
    }

    private function draw_social_centered_text($canvas, $text, $size, $y, $color, $font, $canvas_width, $stroke = null, $stroke_width = 0) {
        $this->draw_social_aligned_text($canvas, $text, $size, $y, 'center', 32, $color, $font, $canvas_width, $stroke, $stroke_width);
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
            $too_wide = $box ? (abs($box[2] - $box[0]) > $max_width) : (strlen($candidate) > $fallback_chars);
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
            dirname(__DIR__) . '/assets/fonts/Anton-Regular.ttf',
            '/usr/share/fonts/truetype/macos/Inter-Bold.ttf',
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

    private function publish_x_post($text, $image_path, $credentials) {
        if (is_string($credentials)) {
            $credentials = array_merge(self::social_credential_defaults(), array('x_user_access_token' => $credentials));
        }
        if (!self::x_credentials_ready($credentials)) {
            return new WP_Error('tr_ai_wire_x_missing_token', 'X is not connected. Save API Key, API Key Secret, Access Token, and Access Token Secret from the X developer portal Keys and tokens page.');
        }
        if (!self::x_oauth1_ready($credentials) && preg_match('/^\d+-[A-Za-z0-9]/', (string) ($credentials['x_user_access_token'] ?? ''))) {
            return new WP_Error('tr_ai_wire_x_oauth1_incomplete', 'That value is an X Access Token from Keys and tokens, not an OAuth 2.0 Bearer token. Save API Key, API Key Secret, Access Token, and Access Token Secret together.');
        }
        $paths = is_array($image_path) ? $image_path : array($image_path);
        $media_ids = array();
        foreach ($paths as $path) {
            $media_id = $this->x_upload_image($path, $credentials);
            if (is_wp_error($media_id)) {
                return $media_id;
            }
            $media_ids[] = $media_id;
            if (count($media_ids) >= 4) {
                break;
            }
        }
        if (!$media_ids) {
            return new WP_Error('tr_ai_wire_x_media', 'X needs at least one image to publish.');
        }
        $posted = $this->x_http_json('https://api.x.com/2/tweets', $credentials, array(
            'text' => $text,
            'media' => array('media_ids' => $media_ids),
        ));
        if (is_wp_error($posted) || empty($posted['data']['id'])) {
            return is_wp_error($posted) ? $posted : new WP_Error('tr_ai_wire_x_post', 'X did not return a post ID.');
        }
        $id = (string) $posted['data']['id'];
        return array('id' => $id, 'url' => 'https://x.com/i/web/status/' . rawurlencode($id));
    }

    private function x_upload_image($image_path, $credentials) {
        $bytes = is_string($image_path) && $image_path !== '' ? file_get_contents($image_path) : '';
        if (!$bytes || strlen($bytes) > 5 * 1024 * 1024) {
            return new WP_Error('tr_ai_wire_x_media_size', 'The X image is empty or exceeds the 5 MB image limit.');
        }
        $init = $this->x_http_json('https://api.x.com/2/media/upload/initialize', $credentials, array(
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
        $append_url = 'https://api.x.com/2/media/upload/' . rawurlencode($media_id) . '/append';
        $append = wp_remote_post($append_url, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => $this->x_authorization_header('POST', $append_url, $credentials),
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $body,
        ));
        if (is_wp_error($append) || (int) wp_remote_retrieve_response_code($append) < 200 || (int) wp_remote_retrieve_response_code($append) >= 300) {
            return new WP_Error('tr_ai_wire_x_media_append', is_wp_error($append) ? $append->get_error_message() : 'X rejected the media upload.');
        }
        $finalized = $this->x_http_json('https://api.x.com/2/media/upload/' . rawurlencode($media_id) . '/finalize', $credentials, array());
        if (is_wp_error($finalized)) {
            return $finalized;
        }
        $processing = (string) ($finalized['data']['processing_info']['state'] ?? 'succeeded');
        if ($processing === 'succeeded') {
            return $media_id;
        }
        $ready = false;
        $check_after = max(1, (int) ($finalized['data']['processing_info']['check_after_secs'] ?? 1));
        for ($attempt = 0; $attempt < 6; $attempt++) {
            sleep(min(5, $check_after));
            $status_url = add_query_arg(array('command' => 'STATUS', 'media_id' => $media_id), 'https://api.x.com/2/media/upload');
            $status_response = wp_remote_get($status_url, array(
                'timeout' => 30,
                'headers' => array('Authorization' => $this->x_authorization_header('GET', $status_url, $credentials)),
            ));
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
        return $media_id;
    }

    private function publish_instagram_post($caption, $alt_text, $image_url, $credentials, $video_url = '') {
        $video_url = is_string($video_url) ? $video_url : '';
        if ($video_url !== '' && wp_http_validate_url($video_url)) {
            return $this->publish_instagram_reel($caption, $video_url, $credentials);
        }
        $urls = is_array($image_url) ? $image_url : array($image_url);
        $urls = array_values(array_filter($urls, function ($url) {
            return is_string($url) && $url !== '' && wp_http_validate_url($url);
        }));
        if (!$urls) {
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
        $alt = function_exists('mb_substr') ? mb_substr(sanitize_text_field($alt_text), 0, 1000, 'UTF-8') : substr(sanitize_text_field($alt_text), 0, 1000);
        $token = $credentials['instagram_user_access_token'];
        if (count($urls) === 1) {
            $container = $this->instagram_request($base . '/media', array(
                'image_url' => $urls[0],
                'caption' => $caption,
                'alt_text' => $alt,
                'access_token' => $token,
            ));
            if (is_wp_error($container) || empty($container['id'])) {
                return is_wp_error($container) ? $container : new WP_Error('tr_ai_wire_ig_container', 'Instagram did not return a media container ID.');
            }
            $wait = $this->instagram_wait_for_container((string) $container['id'], $token);
            if (is_wp_error($wait)) {
                return $wait;
            }
            return $this->instagram_publish_container((string) $container['id'], $credentials);
        }
        $children = array();
        foreach ($urls as $url) {
            $child = $this->instagram_request($base . '/media', array(
                'image_url' => $url,
                'is_carousel_item' => 'true',
                'alt_text' => $alt,
                'access_token' => $token,
            ));
            if (is_wp_error($child) || empty($child['id'])) {
                return is_wp_error($child) ? $child : new WP_Error('tr_ai_wire_ig_container', 'Instagram did not return a carousel item ID.');
            }
            $wait = $this->instagram_wait_for_container((string) $child['id'], $token);
            if (is_wp_error($wait)) {
                return $wait;
            }
            $children[] = (string) $child['id'];
        }
        $parent = $this->instagram_request($base . '/media', array(
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => $caption,
            'access_token' => $token,
        ));
        if (is_wp_error($parent) || empty($parent['id'])) {
            return is_wp_error($parent) ? $parent : new WP_Error('tr_ai_wire_ig_container', 'Instagram did not return a carousel container ID.');
        }
        $wait = $this->instagram_wait_for_container((string) $parent['id'], $token);
        if (is_wp_error($wait)) {
            return $wait;
        }
        return $this->instagram_publish_container((string) $parent['id'], $credentials);
    }

    private function publish_instagram_reel($caption, $video_url, $credentials) {
        if (!is_string($video_url) || $video_url === '' || !wp_http_validate_url($video_url)) {
            return new WP_Error('tr_ai_wire_ig_video_url', 'The Instagram Reel must have a public HTTP(S) Media Library URL.');
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
            'media_type' => 'REELS',
            'video_url' => $video_url,
            'caption' => $caption,
            'share_to_feed' => 'true',
            'access_token' => $credentials['instagram_user_access_token'],
        ));
        if (is_wp_error($container) || empty($container['id'])) {
            return is_wp_error($container) ? $container : new WP_Error('tr_ai_wire_ig_container', 'Instagram did not return a Reel container ID.');
        }
        $wait = $this->instagram_wait_for_container((string) $container['id'], $credentials['instagram_user_access_token'], 24, 2);
        if (is_wp_error($wait)) {
            return $wait;
        }
        return $this->instagram_publish_container((string) $container['id'], $credentials);
    }

    private function instagram_wait_for_container($container_id, $token, $attempts = 6, $sleep = 1) {
        $container_id = (string) $container_id;
        $attempts = max(3, min(30, (int) $attempts));
        $sleep = max(1, min(5, (int) $sleep));
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $status_url = add_query_arg(array(
                'fields' => 'status_code',
                'access_token' => $token,
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
                return true;
            }
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                return new WP_Error('tr_ai_wire_ig_processing', 'Instagram could not prepare the media container.');
            }
            sleep($sleep);
        }
        return new WP_Error('tr_ai_wire_ig_processing', 'Instagram media processing did not finish in time. Check the Social distribution queue before retrying.');
    }

    private function instagram_publish_container($container_id, $credentials) {
        $base = 'https://graph.facebook.com/' . self::INSTAGRAM_GRAPH_VERSION . '/' . rawurlencode($credentials['instagram_user_id']);
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

    private function x_http_json($url, $credentials, $payload) {
        $request_args = array(
            'timeout' => 75,
            'headers' => array(
                'Authorization' => $this->x_authorization_header('POST', $url, $credentials),
                'Content-Type' => 'application/json',
            ),
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

    private function x_authorization_header($method, $url, $credentials) {
        if (self::x_oauth1_ready($credentials)) {
            return $this->x_oauth1_header($method, $url, $credentials);
        }
        return 'Bearer ' . (string) ($credentials['x_user_access_token'] ?? '');
    }

    private function x_oauth1_header($method, $url, $credentials) {
        $parts = wp_parse_url((string) $url);
        $base_url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');
        $query = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $oauth = array(
            'oauth_consumer_key' => (string) $credentials['x_api_key'],
            'oauth_nonce' => wp_generate_password(32, false, false),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => (string) $credentials['x_access_token'],
            'oauth_version' => '1.0',
        );
        $combined = array_merge($query, $oauth);
        ksort($combined);
        $pairs = array();
        foreach ($combined as $key => $value) {
            $pairs[] = $this->x_oauth1_encode($key) . '=' . $this->x_oauth1_encode($value);
        }
        $base = strtoupper((string) $method) . '&' . $this->x_oauth1_encode($base_url) . '&' . $this->x_oauth1_encode(implode('&', $pairs));
        $signing_key = $this->x_oauth1_encode($credentials['x_api_secret']) . '&' . $this->x_oauth1_encode($credentials['x_access_token_secret']);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $signing_key, true));
        ksort($oauth);
        $header = array();
        foreach ($oauth as $key => $value) {
            $header[] = $this->x_oauth1_encode($key) . '="' . $this->x_oauth1_encode($value) . '"';
        }
        return 'OAuth ' . implode(', ', $header);
    }

    private function x_oauth1_encode($value) {
        return str_replace(array('+', '%7E'), array('%20', '~'), rawurlencode((string) $value));
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
        return 'Instagram rejected this token for ' . $label . '. Paste the Facebook Page access_token from Graph API Explorer GET me/accounts — the token inside your Page object — not the User Token at the top of the page. Then retry.';
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
            "UPDATE {$wpdb->prefix}tr_ai_wire_social SET status = 'queued', error_text = NULL, remote_id = '', remote_url = '', updated_at = %s WHERE platform = 'instagram' AND status = 'needs_review' AND error_text LIKE %s",
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

    private function social_pack_size($remote_id) {
        $remote_id = sanitize_text_field((string) $remote_id);
        if ($remote_id === '') {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE remote_id = %s AND platform = 'instagram'",
            $remote_id
        ));
    }

    private function finish_social_item($id, $status, $remote_id, $remote_url, $error) {
        global $wpdb;
        $id = (int) $id;
        $table = $this->social_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $wpdb->update($table, array(
            'status' => sanitize_key($status),
            'remote_id' => sanitize_text_field($remote_id),
            'remote_url' => esc_url_raw($remote_url),
            'error_text' => $error !== '' ? sanitize_text_field($error) : null,
            'updated_at' => current_time('mysql', true),
        ), array('id' => $id));
        if ($status === 'published' && $row) {
            delete_post_meta((int) $row['post_id'], '_tr_ai_wire_social_repost_' . $id);
        }
    }

    private function social_item_is_manual_repost($row) {
        $post_id = (int) ($row['post_id'] ?? 0);
        $id = (int) ($row['id'] ?? 0);
        if ($post_id < 1 || $id < 1) {
            return false;
        }
        return (string) get_post_meta($post_id, '_tr_ai_wire_social_repost_' . $id, true) === '1';
    }

    private function social_queued_repost_count() {
        global $wpdb;
        $table = $this->social_table();
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social q
             INNER JOIN {$wpdb->postmeta} m
                ON m.post_id = q.post_id
               AND m.meta_key = CONCAT('_tr_ai_wire_social_repost_', q.id)
               AND m.meta_value = '1'
             WHERE q.status = 'queued'"
        );
    }

    private function social_published_today() {
        global $wpdb;
        $table = $this->social_table();
        $start = gmdate('Y-m-d 00:00:00');
        $end = gmdate('Y-m-d 23:59:59');
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'published' AND updated_at BETWEEN %s AND %s",
            $start,
            $end
        ));
    }

    private function social_post_published_today($post_id) {
        global $wpdb;
        $table = $this->social_table();
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND status = 'published' AND updated_at >= UTC_DATE() LIMIT 1",
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
        if (!$this->requeue_social_item($id, !empty($_POST['regenerate_image']))) {
            wp_die(esc_html__('Could not delete the live post and queue a replacement. The original post was left up so you do not get a duplicate. Check the Social distribution error, then try again.', 'technorapper-ai-wire'));
        }
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social'));
        exit;
    }

    private function render_social_retry_form($row, $image_id, $status) {
        $id = (int) ($row['id'] ?? 0);
        $platform = (string) ($row['platform'] ?? '');
        $platform_label = $platform === 'instagram' ? 'Instagram' : 'X';
        $published = $status === 'published';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="tr_ai_wire_retry_social_item">';
        echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
        wp_nonce_field('tr_ai_wire_retry_social_item_' . $id);
        if ($published) {
            echo '<p class="description">This deletes the live ' . esc_html($platform_label) . ' post, then generates a new huge-type poster and publishes it. If that post is already gone, the replacement still goes out.</p>';
            echo '<label><input type="checkbox" name="confirmed_not_published" value="1" required> Delete the live ' . esc_html($platform_label) . ' post and replace it</label><br>';
            echo '<input type="hidden" name="regenerate_image" value="1">';
            echo '<button class="button button-primary">Regenerate graphic and post again</button>';
            echo '</form>';
            return;
        }
        echo '<label><input type="checkbox" name="confirmed_not_published" value="1" required> I checked that it is not already published</label><br>';
        if ($image_id > 0) {
            echo '<p class="description">The graphic is already generated. Token refresh and retry will post this file and will not spend image budget.</p>';
            echo '<button class="button button-primary">Post existing graphic to ' . esc_html($platform_label) . '</button>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_retry_social_item">';
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
            wp_nonce_field('tr_ai_wire_retry_social_item_' . $id);
            echo '<label><input type="checkbox" name="confirmed_not_published" value="1" required> I checked that it is not already published</label><br>';
            echo '<input type="hidden" name="regenerate_image" value="1">';
            echo '<button class="button button-secondary">Generate a new graphic and retry</button>';
            echo '</form>';
            return;
        }
        echo '<button class="button button-secondary">Retry this platform post</button>';
        echo '</form>';
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
            'queued' => 'Waiting. If a graphic already exists, the worker posts that file and does not generate another.',
            'processing' => 'In progress: generating the graphic or talking to the platform.',
            'published' => 'Posted on this platform. If the graphic is wrong, regenerate and post again — the plugin deletes the live post first, then publishes a new graphic.',
            'needs_review' => 'The platform response was unclear. Confirm this is not already live, then post the saved graphic or generate a new one.',
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
            $label = 'Generated graphic';
            $remote_id = (string) ($row['remote_id'] ?? '');
            if ($remote_id !== '' && $status === 'published') {
                $pack_size = $this->social_pack_size($remote_id);
                if ($pack_size > 1) {
                    $label = 'Posted in a ' . $pack_size . '-story Instagram post';
                }
            }
            $reel_id = ($post instanceof WP_Post) ? $this->social_reel_id($post->ID) : 0;
            if ($reel_id > 0 && (string) ($row['platform'] ?? '') === 'instagram') {
                $label = 'Spoken Instagram Reel';
            }
            echo '<span class="tr-ai-wire-social-preview-label">' . ($this->social_image_is_current($image_id) ? esc_html($label) : 'Saved graphic — retry posts this file unless you generate a new one') . '</span></a>';
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
        if (!empty($copy['reel_script']) && (string) ($row['platform'] ?? '') === 'instagram' && $this->social_wants_reel()) {
            echo '<p class="description"><strong>Reel voiceover:</strong> ' . esc_html(wp_trim_words((string) $copy['reel_script'], 40, '…')) . '</p>';
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
        if ($status === 'needs_review' || $status === 'published') {
            $this->render_social_retry_form($row, $image_id, $status);
        }
        echo '</div></div>';
    }

    public function social_distribution_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social ORDER BY created_at DESC LIMIT 100", ARRAY_A);
        $settings = $this->settings();
        $image_usage = (float) get_option('tr_ai_wire_social_image_usage_' . gmdate('Ym'), 0);
        ?>
        <div class="wrap">
            <h1>Social queue</h1>
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
                .tr-ai-wire-social-copy label{display:block;margin:6px 0;}
                .tr-ai-wire-social-status{display:inline-block;padding:2px 8px;border-radius:999px;background:#dcdcde;font-size:12px;text-transform:lowercase;}
                .tr-ai-wire-social-status.is-published{background:#c6e1c6;}
                .tr-ai-wire-social-status.is-needs_review{background:#f0c33c;}
                .tr-ai-wire-social-status.is-processing{background:#c5d9ed;}
            </style>
            <?php if (!empty($_GET['site_context'])) : ?><div class="notice notice-info is-dismissible"><p>We are understanding your site so everything we do for social stays in your context.</p></div><?php endif; ?>
            <?php if (isset($_GET['social_ran'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo ((int) $_GET['social_ran'] > 0) ? 'Processed queued social posts. Refresh in a minute if a graphic is still generating.' : 'No queued social post could be processed now. If items are still waiting, check the daily limit, image budget, and WP-Cron.'; ?></p></div><?php endif; ?>
            <?php if (isset($_GET['social_backfill'])) : ?><div class="notice notice-success is-dismissible"><p><?php
            $backfill = (int) $_GET['social_backfill'];
            $backfill_platform = sanitize_key((string) ($_GET['social_backfill_platform'] ?? 'x'));
            $label = $backfill_platform === 'instagram' ? 'Instagram' : 'X';
            echo $backfill > 0
                ? 'Queued ' . esc_html((string) $backfill) . ' existing article' . ($backfill === 1 ? '' : 's') . ' for ' . esc_html($label) . ' using saved graphics. No new images will be generated.'
                : 'No extra ' . esc_html($label) . ' rows were needed — every queued article already has that platform.';
            ?></p></div><?php endif; ?>
            <?php $this->render_site_context_card('tr-ai-wire-social'); ?>
            <?php
            $published_today = $this->social_published_today();
            $daily_limit = (int) ($settings['social_daily_limit'] ?? 10);
            $failed_x = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE platform = 'x' AND status = 'needs_review'");
            $failed_ig = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE platform = 'instagram' AND status = 'needs_review'");
            $finishable_now = $this->social_finishable_queued_count();
            ?>
            <p>X posting is <?php echo !empty($settings['social_x_enabled']) && TechnoRapper_AI_Wire::x_credentials_ready() ? '<strong>on</strong>' : '<strong>off</strong>'; ?> · Instagram posting is <?php echo !empty($settings['social_instagram_enabled']) && TechnoRapper_AI_Wire::instagram_credentials_ready() ? '<strong>on</strong>' : '<strong>off</strong>'; ?>. Change that on <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-social-settings')); ?>">Social settings</a>. Today: <?php echo esc_html((string) $published_today); ?> / <?php echo esc_html((string) $daily_limit); ?> articles distributed. That cap counts unique website articles, not each platform. A successful Instagram post does not spend an extra slot for X on the same article.</p>
            <?php if ($published_today >= $daily_limit && ($failed_x > 0 || $failed_ig > 0 || $finishable_now > 0)) : ?>
            <div class="notice notice-warning"><p><?php
            $parts = array();
            if ($failed_x > 0) {
                $parts[] = (string) $failed_x . ' failed X post' . ($failed_x === 1 ? '' : 's');
            }
            if ($failed_ig > 0) {
                $parts[] = (string) $failed_ig . ' failed Instagram post' . ($failed_ig === 1 ? '' : 's');
            }
            if ($finishable_now > 0) {
                $parts[] = (string) $finishable_now . ' queued platform post' . ($finishable_now === 1 ? '' : 's') . ' still finishing today';
            }
            echo 'The article cap is full, but ' . esc_html(implode(' and ', $parts)) . ' can still run. Retry a failed row or Process now — you do not need to raise the daily limit.';
            ?></p></div>
            <?php endif; ?>
            <p>Social image budget this month: $<?php echo esc_html(number_format_i18n($image_usage, 3)); ?> / $<?php echo esc_html(number_format_i18n((float) ($settings['social_image_budget_usd'] ?? 0), 2)); ?> estimated.</p>
            <?php
            $wanted = $this->social_slide_count($settings);
            $mix = $this->social_mix_today();
            $carousel_daily = (int) ($settings['social_carousel_daily_limit'] ?? 2);
            $single_daily = (int) ($settings['social_single_daily_limit'] ?? 3);
            $format_labels = array(
                'single' => 'one social post per article',
                'carousel' => 'a carousel of ' . (string) $wanted . ' website articles in one Instagram post (X attaches up to 4 images in one tweet)',
                'collage' => 'a collage of ' . (string) $wanted . ' website articles in one Instagram image',
                'reel' => 'one spoken Instagram Reel per website article (current Writer model writes the 15–18s script; voiceover + captions on one B-roll shot, not a slideshow)',
            );
            $format = $this->normalize_social_post_format($settings['social_post_format'] ?? 'single');
            ?>
            <p>The social job currently posts <strong><?php echo esc_html($format_labels[$format] ?? 'one social post per article'); ?></strong><?php
            if ($format !== 'single' && $format !== 'reel') {
                echo '. Today: ' . esc_html((string) $mix['carousel']) . ' / ' . esc_html((string) $carousel_daily) . ' carousel posts and ' . esc_html((string) $mix['single']) . ' / ' . esc_html((string) $single_daily) . ' independent posts';
                $holding = count($this->social_batch_ids());
                if ($holding > 0 && $holding < $wanted) {
                    echo '. Holding ' . esc_html((string) $holding) . ' article' . ($holding === 1 ? '' : 's') . ' for the next carousel';
                }
            }
            ?>. Change this under <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-social-settings')); ?>">Social settings</a>. Process now ships whatever is waiting even if a carousel is not full.</p>
            <?php
            $queued_now = $this->social_queued_count();
            $next_run = wp_next_scheduled(self::SOCIAL_CRON_HOOK);
            if ($queued_now > 0 && $next_run && $next_run > time() + 600) {
                echo '<div class="notice notice-warning"><p>Queued posts are waiting until ' . esc_html(gmdate('Y-m-d H:i', $next_run)) . ' UTC — usually because the daily cap was hit earlier. Raising the daily limit does not start them by itself. Process the queue now.</p></div>';
            }
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0 16px;">
                <input type="hidden" name="action" value="tr_ai_wire_run_social_queue">
                <?php wp_nonce_field('tr_ai_wire_run_social_queue'); ?>
                <?php submit_button('Process queued social posts now', 'secondary', 'submit', false); ?>
                <p class="description" style="display:inline;margin-left:8px;">Posts waiting rows now. Saved graphics are reused. Use this after refreshing an X token, after raising the daily limit, or to ship a leftover batch.</p>
            </form>
            <?php
            foreach (array('x' => 'X', 'instagram' => 'Instagram') as $platform => $label) {
                if (!in_array($platform, self::connected_social_platforms(), true)) {
                    continue;
                }
                $missing = $this->missing_platform_post_count($platform);
                if ($missing < 1) {
                    continue;
                }
                ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 16px;">
                <input type="hidden" name="action" value="tr_ai_wire_queue_missing_platform">
                <input type="hidden" name="platform" value="<?php echo esc_attr($platform); ?>">
                <?php wp_nonce_field('tr_ai_wire_queue_missing_platform'); ?>
                <?php submit_button('Queue saved graphics for ' . $label, 'secondary', 'submit', false); ?>
                <p class="description" style="display:inline;margin-left:8px;"><?php echo esc_html((string) $missing); ?> article<?php echo $missing === 1 ? '' : 's'; ?> already went to another network. This queues <?php echo esc_html($label); ?> using the saved graphic and does not generate a new image.</p>
            </form>
                <?php
            }
            ?>
            <p class="description">Each row is one platform post for a published AI Wire article. The graphic on the right is the branded social card — not the article featured image. The worker generates that card, writes a caption, then tries to publish it.<?php
            if ($next_run) {
                echo ' Next automatic attempt: ' . esc_html(gmdate('Y-m-d H:i', $next_run)) . ' UTC.';
            }
            ?></p>
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
