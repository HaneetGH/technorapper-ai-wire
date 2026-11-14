<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opt-in distribution of published AI Wire articles to X, Instagram, and YouTube Shorts.
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
            'youtube_client_id' => '',
            'youtube_client_secret' => '',
            'youtube_refresh_token' => '',
            'youtube_access_token' => '',
            'youtube_access_expires' => '',
            'youtube_channel_id' => '',
            'youtube_channel_title' => '',
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
        if (self::youtube_credentials_ready($credentials) && !empty($settings['social_youtube_enabled'])) {
            $platforms[] = 'youtube';
        }
        return $platforms;
    }

    public static function social_credentials_connected($credentials = null, $settings = null) {
        return (bool) self::connected_social_platforms($credentials, $settings);
    }

    public static function social_platform_label($platform) {
        $labels = array(
            'x' => 'X',
            'instagram' => 'Instagram',
            'youtube' => 'YouTube',
        );
        $platform = sanitize_key((string) $platform);
        return $labels[$platform] ?? $platform;
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
            'youtube_client_id',
            'youtube_client_secret',
            'youtube_refresh_token',
            'youtube_access_token',
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
        if (!empty($_POST['remove_fal_api_key'])) {
            delete_option(self::FAL_API_KEY_OPTION);
        } elseif (isset($input['fal_api_key']) && trim((string) $input['fal_api_key']) !== '') {
            $fal_key = trim(sanitize_text_field((string) $input['fal_api_key']));
            if (strlen($fal_key) <= 512 && !preg_match('/\s/', $fal_key)) {
                if (get_option(self::FAL_API_KEY_OPTION, null) === null) {
                    add_option(self::FAL_API_KEY_OPTION, $fal_key, '', false);
                } else {
                    update_option(self::FAL_API_KEY_OPTION, $fal_key, false);
                }
            }
        }
        if (empty($next['instagram_user_access_token'])) {
            $next['instagram_user_id'] = '';
        }
        if (!empty($_POST['remove_youtube_oauth'])) {
            $next['youtube_refresh_token'] = '';
            $next['youtube_access_token'] = '';
            $next['youtube_access_expires'] = '';
            $next['youtube_channel_id'] = '';
            $next['youtube_channel_title'] = '';
        } elseif (!empty($input['youtube_refresh_token']) || !empty($input['youtube_access_token'])) {
            if (!empty($input['youtube_refresh_token']) && empty($input['youtube_access_token'])) {
                $next['youtube_access_expires'] = '0';
            } elseif (!empty($input['youtube_access_token'])) {
                $next['youtube_access_expires'] = (string) (time() + 3300);
            }
        }
        if (
            empty($next['youtube_channel_id'])
            && self::youtube_credentials_ready($next)
            && TechnoRapper_AI_Wire_YouTube_OAuth::configured($next)
        ) {
            $yt_token = TechnoRapper_AI_Wire_YouTube_OAuth::access_token($next);
            if (!is_wp_error($yt_token)) {
                $channel = TechnoRapper_AI_Wire_YouTube_OAuth::fetch_channel($yt_token);
                if (!is_wp_error($channel)) {
                    $next = TechnoRapper_AI_Wire_YouTube_OAuth::store_tokens($next, array(), $channel);
                }
            }
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
            } elseif (
                !empty($new_settings['social_youtube_enabled'])
                && !self::youtube_credentials_ready($current)
                && self::youtube_credentials_ready($next)
            ) {
                $backfill = $this->enqueue_missing_platform_posts('youtube');
                $backfill_platform = 'youtube';
            }
            $this->kick_social_queue(5);
            $this->queue_site_context_job(!$was_connected);
        }
        $this->process_social_ideas_from_request();
        $this->process_reel_host_image_from_request($new_settings);
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tr-ai-wire-social-settings',
            'social_credentials_saved' => '1',
            'social_backfill' => (int) $backfill,
            'social_backfill_platform' => $backfill_platform,
        ), admin_url('admin.php')));
        exit;
    }

    private function social_custom_ideas() {
        $stored = get_option(self::SOCIAL_IDEAS_OPTION, array());
        $items = is_array($stored) ? ($stored['items'] ?? $stored) : array();
        $clean = array();
        foreach ((array) $items as $item) {
            $idea = $this->sanitize_social_idea_item($item);
            if ($idea) {
                $clean[] = $idea;
            }
        }
        return $clean;
    }

    private function save_social_custom_ideas($ideas) {
        $items = array();
        foreach ((array) $ideas as $item) {
            $idea = $this->sanitize_social_idea_item($item);
            if ($idea) {
                $items[] = $idea;
            }
        }
        $payload = array(
            'items' => $items,
            'updated_at' => current_time('mysql', true),
        );
        if (get_option(self::SOCIAL_IDEAS_OPTION, null) === null) {
            add_option(self::SOCIAL_IDEAS_OPTION, $payload, '', false);
            return $items;
        }
        update_option(self::SOCIAL_IDEAS_OPTION, $payload, false);
        return $items;
    }

    private function sanitize_social_idea_item($item) {
        if (!is_array($item)) {
            return null;
        }
        $title = $this->clip_social_text($item['title'] ?? '', 80);
        $description = $this->clip_social_block($item['description'] ?? '', 800);
        if ($title === '' || $description === '') {
            return null;
        }
        if ($this->social_image_brief_is_unsafe($title . ' ' . $description)) {
            return null;
        }
        $id = sanitize_key((string) ($item['id'] ?? ''));
        if (!preg_match('/^idea_[a-z0-9]+$/', $id)) {
            $id = 'idea_' . substr(md5($title . '|' . $description), 0, 12);
        }
        $image_id = absint($item['image_id'] ?? 0);
        if ($image_id > 0 && !wp_attachment_is_image($image_id)) {
            $image_id = 0;
        }
        return array(
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'image_id' => $image_id,
            'enabled' => empty($item['enabled']) ? 0 : 1,
            'created_at' => sanitize_text_field((string) ($item['created_at'] ?? current_time('mysql', true))),
        );
    }

    private function process_reel_host_image_from_request($settings) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $host_id = $this->saved_social_reel_host_image_id();
        if ($host_id < 1) {
            $host_id = absint($settings['social_reel_host_image_id'] ?? 0);
        }
        if (!empty($_FILES['social_reel_host_image']['name']) && !empty($_FILES['social_reel_host_image']['tmp_name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            $uploaded = media_handle_upload('social_reel_host_image', 0);
            if (!is_wp_error($uploaded)) {
                $host_id = (int) $uploaded;
                update_post_meta($host_id, '_tr_ai_wire_social_host', '1');
            }
        } elseif (!empty($_POST['remove_reel_host_image'])) {
            $host_id = 0;
        }
        $this->persist_social_reel_host_image_id($host_id);
    }

    private function process_social_ideas_from_request() {
        $remove = array();
        foreach ((array) wp_unslash($_POST['remove_social_idea'] ?? array()) as $id) {
            $id = sanitize_key((string) $id);
            if ($id !== '') {
                $remove[] = $id;
            }
        }
        $enabled = array();
        foreach ((array) wp_unslash($_POST['social_idea_enabled'] ?? array()) as $id) {
            $id = sanitize_key((string) $id);
            if ($id !== '') {
                $enabled[] = $id;
            }
        }
        $has_idea_fields = isset($_POST['social_idea_keep']) || isset($_POST['social_idea_enabled']) || isset($_POST['remove_social_idea']);
        $next = array();
        foreach ($this->social_custom_ideas() as $idea) {
            if (in_array($idea['id'], $remove, true)) {
                continue;
            }
            if ($has_idea_fields) {
                $idea['enabled'] = in_array($idea['id'], $enabled, true) ? 1 : 0;
            }
            $next[] = $idea;
        }
        $created = $this->social_idea_from_request_upload();
        if (is_array($created)) {
            $next[] = $created;
        }
        $this->save_social_custom_ideas($next);
    }

    private function social_idea_from_request_upload() {
        $title = $this->clip_social_text(wp_unslash($_POST['social_idea_title'] ?? ''), 80);
        $description = $this->clip_social_block(wp_unslash($_POST['social_idea_description'] ?? ''), 800);
        $has_file = !empty($_FILES['social_idea_image']['name']) && !empty($_FILES['social_idea_image']['tmp_name']);
        if ($title === '' || $description === '') {
            return null;
        }
        if ($this->social_image_brief_is_unsafe($title . ' ' . $description)) {
            return null;
        }
        $image_id = 0;
        if ($has_file) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            $uploaded = media_handle_upload('social_idea_image', 0);
            if (!is_wp_error($uploaded)) {
                $image_id = (int) $uploaded;
                update_post_meta($image_id, '_tr_ai_wire_social_idea', '1');
            }
        }
        return array(
            'id' => 'idea_' . strtolower(wp_generate_password(8, false, false)),
            'title' => $title,
            'description' => $description,
            'image_id' => $image_id,
            'enabled' => 1,
            'created_at' => current_time('mysql', true),
        );
    }

    private function social_custom_idea($id) {
        $id = sanitize_key((string) $id);
        foreach ($this->social_custom_ideas() as $idea) {
            if ($idea['id'] === $id) {
                return $idea;
            }
        }
        return null;
    }

    private function selected_social_news_formats($settings, $role = 'story') {
        $catalog = self::social_news_formats();
        $chosen = array();
        $saved = isset($settings['social_news_formats']) ? (array) $settings['social_news_formats'] : array_keys($catalog);
        foreach ($saved as $id) {
            $id = sanitize_key((string) $id);
            if (!isset($catalog[$id])) {
                continue;
            }
            if ($role !== '' && (($catalog[$id]['role'] ?? 'story') !== $role)) {
                continue;
            }
            $chosen[] = $id;
        }
        if ($role !== 'cover') {
            foreach ($this->social_custom_ideas() as $idea) {
                if (!empty($idea['enabled'])) {
                    $chosen[] = $idea['id'];
                }
            }
        }
        if (!$chosen && $role === 'story') {
            $chosen[] = 'editorial_poster';
        }
        return array_values(array_unique($chosen));
    }

    private function social_news_format_for_post($post, $settings, $role = 'story') {
        $pool = $this->selected_social_news_formats($settings, $role);
        if (!$pool) {
            return '';
        }
        $id = ($post instanceof WP_Post) ? (int) $post->ID : (int) $post;
        $revision = $id > 0 ? (int) get_post_meta($id, '_tr_ai_wire_social_image_revision', true) : 0;
        $index = (int) (sprintf('%u', crc32($role . ':' . $id . ':' . $revision)) % count($pool));
        return $pool[$index];
    }

    private function social_news_format_has_custom_overlay($format) {
        $format = sanitize_key((string) $format);
        if (in_array($format, array('photo_brief', 'weekly_recap', 'numbered_roundup', 'product_roundup', 'rumor_card', 'device_mockup'), true)) {
            return true;
        }
        return $this->social_custom_idea($format) ? true : false;
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
        echo '<div class="card tr-ai-wire-card" style="border-left:4px solid ' . esc_attr($color) . ';">';
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

    private $social_lock_token = '';
    private $social_active_row_id = 0;

    private function social_heartbeat() {
        if ($this->social_lock_token !== '') {
            $lock = get_option('tr_ai_wire_social_worker_lock', array());
            if (is_array($lock) && (string) ($lock['token'] ?? '') === $this->social_lock_token) {
                update_option('tr_ai_wire_social_worker_lock', array('token' => $this->social_lock_token, 'time' => time()), false);
            }
        }
        if ($this->social_active_row_id > 0) {
            global $wpdb;
            $wpdb->update(
                $this->social_table(),
                array('updated_at' => current_time('mysql', true)),
                array('id' => $this->social_active_row_id, 'status' => 'processing')
            );
        }
    }

    public function process_social_queue() {
        $lock_name = 'tr_ai_wire_social_worker_lock';
        $lock_token = wp_generate_password(32, false, false);
        if (!add_option($lock_name, array('token' => $lock_token, 'time' => time()), '', false)) {
            $existing = get_option($lock_name, array());
            $lock_time = is_array($existing) ? (int) ($existing['time'] ?? 0) : (int) $existing;
            if ($lock_time && $lock_time < time() - 240) {
                delete_option($lock_name);
            }
            if (!add_option($lock_name, array('token' => $lock_token, 'time' => time()), '', false)) {
                return false;
            }
        }
        $this->social_lock_token = $lock_token;
        try {
            $this->process_social_queue_locked();
        } finally {
            $this->social_lock_token = '';
            $this->social_active_row_id = 0;
            $existing = get_option($lock_name, array());
            if (is_array($existing) && ($existing['token'] ?? '') === $lock_token) {
                delete_option($lock_name);
            }
        }
        return true;
    }

    private function process_social_queue_locked() {
        $settings = $this->settings();
        if (!$this->social_distribution_ready()) {
            return;
        }
        if ($this->site_context_needs_refresh()) {
            $this->queue_site_context_job(false);
        }
        if (($this->social_wants_reel($settings) || (int) ($settings['social_reel_daily_limit'] ?? 0) > 0) && function_exists('set_time_limit')) {
            set_time_limit(300);
        }
        $credentials = $this->social_credentials();
        $limit = $this->social_article_daily_limit($settings);
        $instagram_spacing_wait = 0;
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
        // Held Reels stuck mid-tick: resume only while the row is still young.
        // Older rows used to requeue forever (4-minute loop) and burn Fal steps for hours.
        $wpdb->query("UPDATE {$wpdb->prefix}tr_ai_wire_social s SET s.status = 'needs_review', s.error_text = 'Reel build ran over 45 minutes without a finished MP4. Finished pieces are kept. Click Resume to continue from the last Fal step, or Stop and rerun Reel to start clean.' WHERE s.status IN ('queued', 'processing') AND s.created_at < UTC_TIMESTAMP() - INTERVAL 45 MINUTE AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} h WHERE h.post_id = s.post_id AND h.meta_key = '_tr_ai_wire_social_hold_reel' AND h.meta_value = '1') AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = s.post_id AND m.meta_key = '_tr_ai_wire_social_mode' AND m.meta_value = 'reel') AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} r WHERE r.post_id = s.post_id AND r.meta_key = '_tr_ai_wire_social_reel_id' AND r.meta_value <> '' AND r.meta_value <> '0')");
        $wpdb->query("UPDATE {$wpdb->prefix}tr_ai_wire_social s SET s.status = 'queued', s.error_text = 'Resuming the Reel build where it stopped.' WHERE s.status = 'processing' AND s.updated_at < UTC_TIMESTAMP() - INTERVAL 4 MINUTE AND s.created_at >= UTC_TIMESTAMP() - INTERVAL 45 MINUTE AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} h WHERE h.post_id = s.post_id AND h.meta_key = '_tr_ai_wire_social_hold_reel' AND h.meta_value = '1') AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = s.post_id AND m.meta_key = '_tr_ai_wire_social_mode' AND m.meta_value = 'reel')");
        $wpdb->query("UPDATE {$wpdb->prefix}tr_ai_wire_social SET status = 'needs_review', error_text = 'The worker stopped before recording an outcome. Check the platform before retrying.' WHERE status = 'processing' AND updated_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE");
        $this->requeue_instagram_prepublish_quota_failures();
        $rows = $this->social_queued_rows_for_worker($limit);
        $active = self::connected_social_platforms($credentials, $settings);
        $released_posts = array();
        foreach ((array) $rows as $row) {
            if (empty($this->settings()['social_distribution_enabled']) || !$active) {
                break;
            }
            if (!in_array((string) ($row['platform'] ?? ''), $active, true)) {
                continue;
            }
            if (isset($released_posts[(int) ($row['post_id'] ?? 0)])) {
                continue;
            }
            if ($this->social_published_today() >= $limit && !$this->social_post_published_today((int) $row['post_id']) && !$this->social_item_is_manual_repost($row) && !$this->social_row_is_forced_pack($row)) {
                $tomorrow = strtotime('tomorrow 00:20:00 UTC');
                if (!wp_next_scheduled(self::SOCIAL_CRON_HOOK)) {
                    wp_schedule_single_event($tomorrow, self::SOCIAL_CRON_HOOK);
                }
                continue;
            }
            if ((string) ($row['platform'] ?? '') === 'instagram' && !$this->social_item_is_manual_repost($row) && !$this->social_row_skips_instagram_spacing($row)) {
                $wait = $this->instagram_spacing_wait_seconds();
                if ($wait > 0) {
                    $instagram_spacing_wait = max($instagram_spacing_wait, $wait);
                    continue;
                }
            }
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}tr_ai_wire_social SET status = 'processing', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'queued'",
                current_time('mysql', true),
                (int) $row['id']
            ));
            if ($claimed !== 1) {
                continue;
            }
            if ($this->social_row_holds_reel_for_review($row)) {
                if (function_exists('set_time_limit')) {
                    set_time_limit(180);
                }
                $this->social_active_row_id = (int) $row['id'];
                $prepared = $this->prepare_social_delivery($row, $settings);
                $this->social_active_row_id = 0;
                if (is_wp_error($prepared)) {
                    if ($this->social_defer_unready_row($row, $prepared)) {
                        continue;
                    }
                    $this->finish_social_item($row['id'], 'needs_review', '', '', $prepared->get_error_message());
                    continue;
                }
                delete_post_meta((int) $row['post_id'], '_tr_ai_wire_reel_auto_retries');
                $this->finish_social_item($row['id'], 'needs_review', '', '', $this->social_reel_hold_copy($row));
                if ($this->social_finishable_queued_count() > 0) {
                    $this->kick_social_queue(5);
                }
                continue;
            }
            $pack = array($row);
            $explicit = $this->social_row_explicit_pack($row);
            if (($explicit !== '' || $this->social_row_is_pack($row, $settings)) && !$this->social_item_is_manual_repost($row)) {
                $pack = $this->claim_instagram_pack($row, $limit, $settings);
            }
            if ($explicit !== '' && count($pack) < 2) {
                $this->release_social_rows_to_queue($pack);
                foreach ($pack as $held) {
                    $released_posts[(int) ($held['post_id'] ?? 0)] = true;
                }
                continue;
            }
            if (count($pack) > 1) {
                if (function_exists('set_time_limit')) {
                    set_time_limit(600);
                }
                if ($row['platform'] === 'x') {
                    $this->finish_instagram_pack($pack, $this->publish_x_pack($pack, $credentials, $settings));
                } elseif ($row['platform'] === 'instagram') {
                    $this->finish_instagram_pack($pack, $this->publish_instagram_pack($pack, $credentials, $settings));
                    $instagram_spacing_wait = max($instagram_spacing_wait, $this->instagram_spacing_wait_seconds());
                } else {
                    $result = $this->publish_social_item($row, $credentials, $settings);
                    if (is_wp_error($result)) {
                        $this->finish_social_item($row['id'], 'needs_review', '', '', $result->get_error_message());
                    } else {
                        $this->finish_social_item($row['id'], 'published', $result['id'], $result['url'], '');
                    }
                }
                break;
            }
            $result = $this->publish_social_item($row, $credentials, $settings);
            if (is_wp_error($result)) {
                if ($this->social_defer_unready_row($row, $result)) {
                    continue;
                }
                if ($result->get_error_code() === 'tr_ai_wire_ig_quota_full' || $result->get_error_code() === 'tr_ai_wire_yt_quota') {
                    $wpdb->update($table, array('status' => 'queued', 'error_text' => $result->get_error_message(), 'updated_at' => current_time('mysql', true)), array('id' => (int) $row['id']));
                    wp_schedule_single_event(strtotime('+1 hour'), self::SOCIAL_CRON_HOOK);
                    if ($result->get_error_code() === 'tr_ai_wire_yt_quota') {
                        continue;
                    }
                    break;
                }
                // Do not auto-retry an ambiguous publish response: the platform
                // may have accepted the post before the connection failed.
                $this->finish_social_item($row['id'], 'needs_review', '', '', $result->get_error_message());
                if ($this->social_row_is_reel($row)) {
                    continue;
                }
                if ($this->social_finishable_queued_count() > 0) {
                    $this->kick_social_queue(5);
                }
                break;
            }
            $this->finish_social_item($row['id'], 'published', $result['id'], $result['url'], '');
            if ((string) ($row['platform'] ?? '') === 'instagram' && !$this->social_row_is_reel($row)) {
                $instagram_spacing_wait = max($instagram_spacing_wait, $this->instagram_spacing_wait_seconds());
            }
            break;
        }

        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued'");
        $finishable = $this->social_finishable_queued_count();
        if ($remaining > 0 && !$this->social_queue_waiting_for_pack_mate() && ($this->social_published_today() < $limit || $finishable > 0 || $this->social_queued_repost_count() > 0 || $this->social_queued_forced_pack_count() > 0)) {
            $delay = $finishable > 0 ? 5 : 60;
            if ($instagram_spacing_wait > 0 && !$this->social_queued_has_spacing_exempt()) {
                $delay = max($delay, $instagram_spacing_wait);
            }
            $this->kick_social_queue($delay);
        }
    }

    private function social_defer_unready_row($row, $error) {
        if (!is_wp_error($error)) {
            return false;
        }
        $code = $error->get_error_code();
        $post_id = (int) ($row['post_id'] ?? 0);
        $is_wait = $code === 'tr_ai_wire_reel_video_wait'
            || $code === 'tr_ai_wire_yt_video_wait'
            || $code === 'tr_ai_wire_social_image_busy'
            || $this->social_reel_error_is_transient($error, $post_id);
        if (!$is_wait) {
            return false;
        }
        global $wpdb;
        $fields = array(
            'status' => 'queued',
            'updated_at' => current_time('mysql', true),
        );
        if ($code !== 'tr_ai_wire_social_image_busy') {
            $fields['error_text'] = $error->get_error_message();
        }
        if ($code === 'tr_ai_wire_yt_video_wait') {
            $pending_id = $error->get_error_data();
            if (is_string($pending_id) && $pending_id !== '') {
                $fields['remote_id'] = sanitize_text_field($pending_id);
            }
        }
        $wpdb->update($this->social_table(), $fields, array('id' => (int) $row['id']));
        $kick = ($code === 'tr_ai_wire_yt_video_wait') ? 45 : 30;
        $this->kick_social_queue($kick, true);
        return true;
    }

    private function social_row_skips_instagram_spacing($row) {
        if ($this->social_row_is_forced_pack($row) || $this->social_row_is_reel($row)) {
            return true;
        }
        return $this->social_row_explicit_pack($row) !== '';
    }

    private function instagram_stills_spacing_seconds() {
        $settings = $this->settings();
        if (self::sanitize_clock_times($settings['social_post_times'] ?? array())) {
            return 0;
        }
        $hours = min(12, max(0, (int) ($settings['social_still_spacing_hours'] ?? 3)));
        return $hours * HOUR_IN_SECONDS;
    }

    private function instagram_spacing_wait_seconds() {
        global $wpdb;
        $settings = $this->settings();
        $times = self::sanitize_clock_times($settings['social_post_times'] ?? array());
        $last = $wpdb->get_var(
            "SELECT s.updated_at FROM {$wpdb->prefix}tr_ai_wire_social s
             WHERE s.platform = 'instagram' AND s.status = 'published'
               AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} m
                    WHERE m.post_id = s.post_id
                      AND m.meta_key = '_tr_ai_wire_social_mode'
                      AND m.meta_value = 'reel'
               )
             ORDER BY s.updated_at DESC LIMIT 1"
        );
        $last_ts = 0;
        if (is_string($last) && $last !== '') {
            $parsed = strtotime($last . ' UTC');
            if ($parsed) {
                $last_ts = (int) $parsed;
            }
        }
        if ($times) {
            $anchor = $last_ts ? $last_ts : (time() - 59);
            $next = self::next_clock_timestamp($times, $anchor);
            if ($next < 1) {
                return 0;
            }
            return $next > time() ? ($next - time()) : 0;
        }
        if ($last_ts < 1) {
            return 0;
        }
        $need = $this->instagram_stills_spacing_seconds();
        if ($need < 1) {
            return 0;
        }
        $elapsed = time() - $last_ts;
        return $elapsed >= $need ? 0 : ($need - $elapsed);
    }

    private function instagram_still_spacing_copy() {
        $settings = $this->settings();
        $times = self::sanitize_clock_times($settings['social_post_times'] ?? array());
        if ($times) {
            return 'Single Instagram posts go out at ' . implode(', ', $times) . ' (' . self::site_timezone_label() . ').';
        }
        $hours = min(12, max(0, (int) ($settings['social_still_spacing_hours'] ?? 3)));
        if ($hours < 1) {
            return 'Single Instagram posts are not delayed between each other.';
        }
        $unit = $hours === 1 ? 'hour' : 'hours';
        return 'Single Instagram posts wait ' . $hours . ' ' . $unit . ' after the last still.';
    }

    private function social_queued_has_spacing_exempt() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social q
             WHERE q.status = 'queued'
               AND (
                    EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} m
                        WHERE m.post_id = q.post_id
                          AND m.meta_key = '_tr_ai_wire_social_mode'
                          AND m.meta_value IN ('reel', 'pack', 'carousel', 'collage')
                    )
                    OR EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} f
                        WHERE f.post_id = q.post_id
                          AND f.meta_key = '_tr_ai_wire_social_force_pack'
                          AND f.meta_value IN ('carousel', 'collage', 'reel')
                    )
               )"
        ) > 0;
    }

    private function social_queued_rows_for_worker($limit) {
        global $wpdb;
        $table = $this->social_table();
        $platform_sql = $this->social_active_platform_sql();
        if ($platform_sql === '') {
            return array();
        }
        $forced_first = "(EXISTS (SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = {$wpdb->prefix}tr_ai_wire_social.post_id AND f.meta_key = '_tr_ai_wire_social_force_pack' AND f.meta_value IN ('carousel', 'collage', 'reel'))) DESC, created_at ASC";
        if ($this->social_published_today() < $limit) {
            return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued'{$platform_sql} ORDER BY {$forced_first} LIMIT 25", ARRAY_A);
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
                    OR EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} f
                        WHERE f.post_id = q.post_id
                          AND f.meta_key = '_tr_ai_wire_social_force_pack'
                          AND f.meta_value IN ('carousel', 'collage', 'reel')
                    )
               )
             ORDER BY (EXISTS (SELECT 1 FROM {$wpdb->postmeta} f WHERE f.post_id = q.post_id AND f.meta_key = '_tr_ai_wire_social_force_pack' AND f.meta_value IN ('carousel', 'collage', 'reel'))) DESC, q.created_at ASC
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
        $this->enqueue_background_job('social');
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social'));
        exit;
    }

    public function generate_social_pack_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to generate a social carousel, collage, or Reel.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_generate_social_pack');
        $kind = $this->social_manual_pack_kind($_POST['pack_kind'] ?? '');
        $this->enqueue_background_job('social_pack', array('pack_kind' => $kind));
        wp_safe_redirect(add_query_arg(array(
            'page' => 'tr-ai-wire-social',
            'social_pack' => $kind,
        ), admin_url('admin.php')));
        exit;
    }

    private function social_manual_pack_kind($kind) {
        $kind = sanitize_key((string) $kind);
        return in_array($kind, array('carousel', 'collage', 'reel'), true) ? $kind : 'carousel';
    }

    private function drain_social_queue($max = 15) {
        if (function_exists('set_time_limit')) {
            set_time_limit(600);
        }
        $this->flush_social_batch(true, true);
        $processed = 0;
        $max = max(1, (int) $max);
        for ($i = 0; $i < $max; $i++) {
            if ($this->background_job_was_cancelled()) {
                break;
            }
            $this->note_background_progress(10 + (int) (80 * (($i + 1) / $max)), 'Publishing social item ' . ($i + 1));
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
        return $processed;
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
            $video_url = $this->social_row_is_reel($row) ? (string) ($prepared['video_url'] ?? '') : '';
            return $this->publish_instagram_post(
                $prepared['copy']['instagram'],
                $prepared['copy']['instagram_alt'],
                $prepared['image_url'],
                $credentials,
                $video_url
            );
        }
        if ($row['platform'] === 'youtube') {
            return $this->publish_youtube_short(
                $prepared['copy'],
                (string) ($prepared['video_path'] ?? ''),
                $credentials,
                $settings,
                $prepared['post'],
                (string) ($row['remote_id'] ?? '')
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
        if (is_wp_error($copy)) {
            return $copy;
        }
        $blocked = $this->social_copy_policy_error($copy);
        if (is_wp_error($blocked)) {
            delete_post_meta($post->ID, '_tr_ai_wire_social_copy');
            return $blocked;
        }
        $guard = $this->policy_guard_social_copy($copy);
        if (is_wp_error($guard)) {
            delete_post_meta($post->ID, '_tr_ai_wire_social_copy');
            return $guard;
        }
        $platform = (string) ($row['platform'] ?? '');
        $wants_reel = $platform === 'youtube' || ($this->social_wants_reel($settings, (int) $post->ID) && $platform === 'instagram');
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
        $lead_format = $this->social_row_explicit_pack($lead);
        $wanted = $lead_format !== '' ? $this->social_pack_slide_count($settings) : $this->social_slide_count($settings);
        $forced = $this->social_row_is_forced_pack($lead);
        $pack = array($lead);
        $seen = array((int) $lead['post_id'] => true);
        $slots_left = max(0, $limit - $this->social_published_today());
        $new_in_pack = ($this->social_post_published_today((int) $lead['post_id']) || $this->social_item_is_manual_repost($lead)) ? 0 : 1;
        if ($lead_format !== '') {
            $modes = $lead_format === 'collage' ? array('collage') : array('pack', 'carousel');
            $placeholders = implode(', ', array_fill(0, count($modes), '%s'));
            $candidates = $wpdb->get_results($wpdb->prepare(
                "SELECT s.* FROM {$wpdb->prefix}tr_ai_wire_social s
                 INNER JOIN {$wpdb->postmeta} m
                    ON m.post_id = s.post_id
                   AND m.meta_key = '_tr_ai_wire_social_mode'
                   AND m.meta_value IN ({$placeholders})
                 WHERE s.status = 'queued' AND s.platform = %s AND s.id != %d
                 ORDER BY s.created_at ASC
                 LIMIT 25",
                ...array_merge($modes, array((string) $lead['platform'], (int) $lead['id']))
            ), ARRAY_A);
        } else {
            $candidates = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued' AND platform = %s AND id != %d ORDER BY created_at ASC LIMIT 25",
                (string) $lead['platform'],
                (int) $lead['id']
            ), ARRAY_A);
        }
        foreach ((array) $candidates as $candidate) {
            if (count($pack) >= $wanted) {
                break;
            }
            $post_id = (int) $candidate['post_id'];
            if (isset($seen[$post_id]) || $this->social_item_is_manual_repost($candidate)) {
                continue;
            }
            if ($lead_format !== '') {
                if ($this->social_row_explicit_pack($candidate) !== $lead_format) {
                    continue;
                }
            } elseif (!$this->social_row_is_pack($candidate, $settings)) {
                continue;
            }
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            $is_new = !$this->social_post_published_today($post_id);
            if (!$forced && $is_new && $new_in_pack >= $slots_left) {
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

    private function social_pack_cover_url($prepared, $settings) {
        $cover_id = $this->maybe_social_pack_cover($prepared, $settings);
        if ($cover_id < 1) {
            return '';
        }
        $url = wp_get_attachment_url($cover_id);
        return (is_string($url) && $url !== '' && wp_http_validate_url($url)) ? $url : '';
    }

    private function maybe_social_pack_cover($prepared, $settings) {
        if (!is_array($prepared) || count($prepared) < 2) {
            return 0;
        }
        $lead = $prepared[0]['post'] ?? null;
        if (!$lead instanceof WP_Post) {
            return 0;
        }
        $format = $this->social_news_format_for_post($lead, $settings, 'cover');
        if ($format === '') {
            return 0;
        }
        $copy = $this->social_cover_copy($prepared, $format);
        $catalog = self::social_news_formats();
        $family = (string) ($catalog[$format]['family'] ?? 'title_card');
        if ($family === 'photo' && !empty($prepared[0]['image_path']) && is_readable($prepared[0]['image_path'])) {
            $bytes = file_get_contents($prepared[0]['image_path']);
            $branded = is_string($bytes) ? $this->brand_social_image($bytes, $copy) : null;
        } else {
            $branded = $this->render_local_social_graphic($copy);
        }
        if (is_wp_error($branded) || !is_string($branded) || $branded === '') {
            return 0;
        }
        $stored = $this->store_social_jpeg($lead, $branded, (string) ($copy['on_image_headline'] ?? self::site_brand_name()), $copy, 'cover', 'cover');
        return is_wp_error($stored) ? 0 : (int) $stored;
    }

    private function social_cover_copy($prepared, $format) {
        $count = max(2, count($prepared));
        $topic = $this->social_cover_topic($prepared);
        $copy = array(
            '_news_format' => $format,
            'instagram_alt' => self::site_brand_name() . ' roundup cover',
            'on_image_kicker' => strtoupper($topic) . ' NEWS',
        );
        if ($format === 'weekly_recap') {
            $copy['on_image_headline'] = 'THIS WEEK IN ' . $topic;
            $copy['on_image_subhead'] = 'SWIPE — the last slide is the one';
            return $copy;
        }
        if ($format === 'numbered_roundup') {
            $copy['on_image_headline'] = $count . ' ' . strtolower($topic) . ' shifts. Wait for the last one';
            $copy['on_image_subhead'] = 'SWIPE →';
            return $copy;
        }
        $copy['on_image_headline'] = $count . ' BIG ' . $topic . ' NEWS';
        $copy['on_image_subhead'] = 'SWIPE TO THE LAST ONE';
        return $copy;
    }

    private function social_curiosity_slide_copy($prepared) {
        $count = max(2, is_array($prepared) ? count($prepared) : 2);
        $topic = $this->social_cover_topic($prepared);
        return array(
            '_news_format' => 'weekly_recap',
            'instagram_alt' => 'Swipe for the next ' . strtolower($topic) . ' story',
            'on_image_kicker' => 'WAIT',
            'on_image_headline' => 'THE NEXT SLIDE IS THE ONE',
            'on_image_subhead' => 'SWIPE — don\'t skip story ' . (string) $count,
        );
    }

    private function social_pack_curiosity_url($prepared) {
        if (!is_array($prepared) || count($prepared) < 2) {
            return '';
        }
        $lead = $prepared[0]['post'] ?? null;
        if (!$lead instanceof WP_Post) {
            return '';
        }
        $copy = $this->social_curiosity_slide_copy($prepared);
        $branded = $this->render_local_social_graphic($copy);
        if (is_wp_error($branded) || !is_string($branded) || $branded === '') {
            return '';
        }
        $stored = $this->store_social_jpeg($lead, $branded, (string) $copy['on_image_headline'], $copy, 'curiosity', 'curiosity');
        if (is_wp_error($stored)) {
            return '';
        }
        $url = wp_get_attachment_url((int) $stored);
        return (is_string($url) && $url !== '' && wp_http_validate_url($url)) ? $url : '';
    }

    private function instagram_carousel_urls($prepared, $settings) {
        $story_urls = array();
        foreach ((array) $prepared as $item) {
            if (!empty($item['image_url']) && is_string($item['image_url'])) {
                $story_urls[] = $item['image_url'];
            }
        }
        $urls = array();
        $cover = $this->social_pack_cover_url($prepared, $settings);
        if ($cover !== '') {
            $urls[] = $cover;
        }
        $curiosity = $this->social_pack_curiosity_url($prepared);
        $last = count($story_urls) - 1;
        foreach ($story_urls as $i => $url) {
            if ($curiosity !== '' && $i === $last && $last >= 1 && count($urls) < 9) {
                $urls[] = $curiosity;
            }
            $urls[] = $url;
            if (count($urls) >= 10) {
                break;
            }
        }
        return $urls;
    }

    private function social_cover_topic($prepared) {
        foreach ((array) $prepared as $item) {
            $copy = is_array($item['copy'] ?? null) ? $item['copy'] : array();
            $category = strtoupper($this->clip_social_text((string) ($copy['category'] ?? ''), 16));
            if ($category !== '' && !is_numeric($category)) {
                return $category;
            }
            if ($item['post'] instanceof WP_Post) {
                $snapshot = $this->social_article_snapshot($item['post']);
                $category = strtoupper($this->clip_social_text((string) ($snapshot['category'] ?? ''), 16));
                if ($category !== '' && !is_numeric($category)) {
                    return $category;
                }
            }
        }
        $profile = $this->site_context_profile();
        $desks = (array) ($profile['desks'] ?? array());
        if (!empty($desks[0])) {
            $desk = strtoupper($this->clip_social_text((string) $desks[0], 16));
            if ($desk !== '') {
                return $desk;
            }
        }
        return 'TECH';
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
        $format = $this->social_item_pack_format($prepared[0], $settings);
        $caption = $this->instagram_pack_caption($prepared);
        $guard = $this->policy_guard_text($caption, 'social');
        if (is_wp_error($guard)) {
            return $guard;
        }
        $alt = (string) ($prepared[0]['copy']['instagram_alt'] ?? (self::site_brand_name() . ' stories'));
        if ($format === 'carousel') {
            return $this->publish_instagram_post($caption, $alt, $this->instagram_carousel_urls($prepared, $settings), $credentials);
        }
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
        $caption = $this->x_pack_caption($prepared);
        $guard = $this->policy_guard_text($caption, 'social');
        if (is_wp_error($guard)) {
            return $guard;
        }
        return $this->publish_x_post($caption, $paths, $credentials);
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
        $caption = 'Last photo is the one. Today on ' . self::site_brand_name() . ': ' . implode(' • ', $titles);
        $home = home_url('/');
        if (strpos($caption, $home) === false) {
            $caption .= ' ' . $home;
        }
        return $this->clip_social_block($caption, 280);
    }

    private function instagram_pack_caption($prepared) {
        $lines = array(
            'Don\'t skip the last slide.',
            'Today on ' . self::site_brand_name() . ':',
        );
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
        $caption = $this->instagram_caption_without_links($caption, $cta);
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
        $force_script = (string) get_post_meta($post->ID, '_tr_ai_wire_reel_regen_script', true) === '1';
        if ($force_script) {
            delete_post_meta($post->ID, '_tr_ai_wire_social_copy');
            delete_post_meta($post->ID, '_tr_ai_wire_reel_lesson');
        }
        $cached = get_post_meta($post->ID, '_tr_ai_wire_social_copy', true);
        $decoded = null;
        if (is_string($cached) && $cached !== '' && !$force_script) {
            $maybe = json_decode($cached, true);
            if (is_array($maybe) && !empty($maybe['x']) && !empty($maybe['instagram'])) {
                $decoded = $maybe;
                if (!$this->social_wants_reel($settings, (int) $post->ID) || $this->writer_reel_script_present($decoded, $settings)) {
                    return $this->sanitize_social_copy($decoded, $post, $settings);
                }
            }
        }
        $generated = $this->generate_social_copy($post, $settings);
        if (!is_wp_error($generated) && is_array($generated)) {
            $copy = $this->sanitize_social_copy($generated, $post, $settings);
            $guard = $this->policy_guard_social_copy($copy);
            if (is_wp_error($guard)) {
                return $guard;
            }
            update_post_meta($post->ID, '_tr_ai_wire_social_copy', wp_json_encode($copy));
            delete_post_meta($post->ID, '_tr_ai_wire_reel_regen_script');
            return $copy;
        }
        if (is_wp_error($generated) && $generated->get_error_code() === 'tr_ai_wire_policy') {
            return $generated;
        }
        if (is_array($decoded)) {
            return $this->sanitize_social_copy($decoded, $post, $settings);
        }
        $copy = $this->sanitize_social_copy(array(), $post, $settings);
        $guard = $this->policy_guard_social_copy($copy);
        if (is_wp_error($guard)) {
            return $guard;
        }
        update_post_meta($post->ID, '_tr_ai_wire_social_copy', wp_json_encode($copy));
        delete_post_meta($post->ID, '_tr_ai_wire_reel_regen_script');
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
        $comic = $this->post_is_comic($post);
        $system = $this->social_copy_system_prompt($brand, $comic);
        $user = $this->social_copy_user_prompt($brand, $profile, $snapshot, $comic, $s);
        $copy_settings = $s;
        $copy_settings['max_output_tokens'] = min($this->social_reel_is_roundup($s) ? 2800 : 1600, max(400, (int) ($s['max_output_tokens'] ?? 1600)));
        return $this->ai_json($s['writer_model'] ?? 'gpt-6-luna', $system, $user, $copy_settings, false);
    }

    private function social_copy_system_prompt($brand, $comic = false) {
        $prompt = 'You are the social editor and growth lead for ' . $brand . ', with a newsroom desk and ten years of Instagram, X, and YouTube Shorts distribution. '
            . 'Repackage an already-published article as this desk’s take. Do not write a new article. Do not sound like a wire dump or a screenshot of another outlet. Use only supplied article fields plus the site context. '
            . 'Never invent facts, dates, prices, quotes, tests, or “we tried it”. Never fake countdown deals or fake scarcity. '
            . 'Stop the scroll with a specific name, number, or mechanism from the article — not generic clickbait. Instagram hides empty teases and repeated news-aggregator cards. ';
        if (!empty($comic)) {
            $prompt .= 'A pinch of dry comic sense in the hook, on-image line, and caption is welcome if it stays restrained and the named change still leads. Never a meme page. ';
        }
        return $prompt . 'Safe for a news brand: no nudity, and keep the wording suitable for a general audience. Return JSON only.';
    }

    private function social_copy_user_prompt($brand, $profile, $snapshot, $comic = false, $settings = null) {
        $user = 'Write the package a growth editor would actually post to get views, saves, and sends. Lead with a specific name, number, or mechanism from the article; a reader stake (phone, job, money, privacy, or status); then pay the curiosity off in the next lines with saveable bullets. Short. No throat-clearing. '
            . (!empty($comic) ? 'One dry wink is enough. ' : '')
            . 'Never use: you won’t believe, wait for it, wait until the end, must see, gone wrong, shocking, this changes everything, this will blow your mind. Those lines look like bait and Instagram withholds them. Open a loop only if the caption pays it off in the next sentence. '
            . 'Write original wording, not a restated press blurb. Keep it suitable for a general news audience. Never invent the twist. '
            . 'on_image_headline: 4–9 words, scroll-stopping, a number only if it is in the article, short enough for 2–3 lines of huge type. '
            . (!empty($comic) ? 'A wry twist is fine if the named change is still the headline. ' : '')
            . 'on_image_subhead: one supporting line that makes them tap, max 10 words. '
            . 'on_image_kicker: optional 1–3 word label such as TECH NEWS or AI NEWS when it fits. Use RUMORS only if the article itself is a rumor or leak. '
            . 'on_image_bullets: exactly 3 short bullets grounded in the article. Put 2–4 key words in **double asterisks**. Never invent facts. '
            . ($this->social_reel_is_roundup($settings)
                ? 'visual_brief: viral 2D clean vector newsroom, midnight navy, cyan rim light, unique illustrated set per packed story that makes the story obvious. A person or mascot is optional; no-person object scenes are good; never a person from behind in every beat. No photoreal people, no letters or numbers in the artwork. '
                : ('visual_brief: describe only a fully clothed news photograph built to stop a thumb (extreme crop, one glowing object, rain on glass, dusk rim light, surprising scale). '
                    . (!empty($comic) ? 'Add one sly photoreal visual beat that makes someone smile — cold coffee beside a glowing phone, an empty night office with one light on, a spinning wait cursor on a laptop — while remaining a real photograph. ' : '')
                    . 'Device close-up, empty office, factory, night city, server hall, or product still life. A person is optional — no-person stills are good. If a person appears they are fully clothed in ordinary work or street clothes, faces not identifiable, not seen from behind. Adults only. No celebrities, no real brand logos, no screenshots, no illustration, no letters or numbers in the artwork (type is overlaid later). Do not include the headline wording. '))
            . 'x_text: hook plus one curiosity line that makes them tap the link; do not include the URL. instagram_caption: hook as the first line (named change, not a tease), 2–4 short lines that pay off the curiosity, one save or send ask, then tell them the full story is in the bio. Never ask for likes or follows. Never include a URL or permalink. Instagram hides posts that look like link spam. cta: tell them to read the full story on ' . $brand . '. hashtags: 3–5 specific tags, no spam. instagram_alt: factual description of the graphic. '
            . 'reel_script: write the spoken Reel in this same Writer pass — do not leave the voiceover for another model. ' . $this->social_reel_script_brief($settings)
            . ($this->social_reel_is_roundup($settings)
                ? ' Pack 3–5 supplied desk stories into one spoken roundup. Go past 150 words when the stories need it. Do not invent extra companies. '
                : ' THIS article only. Teach the viewer something they can repeat. Not a robot reading a press release. Beats: (1) pattern-interrupt hook naming the change; (2) what this is in plain words; (3) why it matters / the mechanism from key_takeaways; (4) one concrete example for their phone, game, job, or money; (5) the takeaway. Do not tease. No packing other stories into a roundup. ')
            . (!empty($comic) ? 'A light wry line is fine after the takeaway; do not turn the Reel into a bit. ' : '')
            . 'Do not say “full story” or “link in bio” until after the lesson. No hashtags, no URL. '
            . ($this->social_reel_is_roundup($settings)
                ? 'reel_captions: 12–24 short on-screen scene lines (max 6 words) that change every 3–4 seconds and name the packed desk story on screen. reel_scene_briefs: one unique illustrated-set brief per caption that makes the story obvious (room, device, map, object). A person or mascot is optional. Never a person from behind in every beat, never a generic desk twice. '
                : 'reel_captions: 4–5 short on-screen lesson lines (max 6 words) matching those beats — big sparse text for animated cards and B-roll. ')
            . 'youtube_title: 40–70 characters, max 100, no < or >, a specific name/number/twist the article supports. '
            . (!empty($comic) ? 'A dry wink is fine if the named change is still obvious. ' : '')
            . 'This is the public YouTube listing title — the Short goes live immediately, not as a draft. '
            . 'youtube_description: two short lines that finish the lesson; do not include the URL or #Shorts (we append both). No hashtag dump. '
            . 'youtube_tags: 3–8 short YouTube tags (words, not #hashtags) grounded in the article. They are sent in the upload API with the public listing. '
            . 'Every field must be suitable for a general audience on X, Instagram, and YouTube. ';
        if ($profile) {
            $user .= "Site context (follow this):\n" . wp_json_encode($profile) . "\n";
        }
        $user .= "Article JSON:\n" . wp_json_encode($snapshot)
            . "\nReturn JSON keys: hook, on_image_headline, on_image_subhead, on_image_kicker, on_image_bullets (array), visual_brief, cta, x_text, instagram_caption, instagram_alt, hashtags (array), reel_script, reel_captions (array)"
            . ($this->social_reel_is_roundup($settings) ? ', reel_scene_briefs (array)' : '')
            . ', youtube_title, youtube_description, youtube_tags (array).';
        return $user;
    }

    private function sanitize_social_copy($raw, $post, $settings = null) {
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
        if ($this->social_image_brief_is_unsafe($visual)) {
            $visual = '';
        }
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
        $caption = $this->instagram_caption_without_links($caption, $cta);
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
        $kicker = $this->clip_social_text($raw['on_image_kicker'] ?? $raw['kicker'] ?? '', 28);
        $copy = array(
            'hook' => $hook,
            'on_image_headline' => $headline,
            'on_image_subhead' => $subhead,
            'on_image_kicker' => $kicker,
            'on_image_bullets' => $this->sanitize_social_bullets($raw, $post),
            'visual_brief' => $visual,
            'cta' => $cta,
            'x' => $x,
            'instagram' => $caption,
            'instagram_alt' => $alt,
            'hashtags' => $hashtags,
        );
        $copy['reel_script'] = $this->sanitize_reel_script($raw['reel_script'] ?? '', $copy, $post, $settings);
        $copy['reel_captions'] = $this->sanitize_reel_captions($raw['reel_captions'] ?? array(), $copy['reel_script'], $settings);
        if ($this->social_reel_is_roundup($settings)) {
            $copy['reel_scene_briefs'] = $this->sanitize_reel_scene_briefs($raw['reel_scene_briefs'] ?? array(), $copy['reel_captions']);
        }
        $copy['youtube_title'] = $this->sanitize_youtube_title($raw['youtube_title'] ?? '', $copy, $post);
        $copy['youtube_description'] = $this->sanitize_youtube_description($raw['youtube_description'] ?? '', $copy, $post);
        $copy['youtube_tags'] = $this->sanitize_youtube_tags($raw['youtube_tags'] ?? array(), $copy);
        return $copy;
    }

    private function sanitize_social_bullets($raw, $post) {
        $bullets = array();
        $source = array();
        if (is_array($raw) && isset($raw['on_image_bullets'])) {
            $source = (array) $raw['on_image_bullets'];
        } elseif (is_array($raw) && isset($raw['bullets'])) {
            $source = (array) $raw['bullets'];
        }
        foreach ($source as $bullet) {
            if (is_array($bullet)) {
                $bullet = (string) ($bullet['text'] ?? '');
            }
            $bullet = $this->sanitize_social_bullet_text($bullet);
            if ($bullet === '' || in_array($bullet, $bullets, true)) {
                continue;
            }
            $bullets[] = $bullet;
            if (count($bullets) >= 3) {
                return $bullets;
            }
        }
        $snapshot = ($post instanceof WP_Post) ? $this->social_article_snapshot($post) : array('key_takeaways' => array(), 'excerpt' => '');
        foreach ((array) ($snapshot['key_takeaways'] ?? array()) as $line) {
            $line = $this->sanitize_social_bullet_text($line);
            if ($line === '' || in_array($line, $bullets, true)) {
                continue;
            }
            $bullets[] = $line;
            if (count($bullets) >= 3) {
                return $bullets;
            }
        }
        $excerpt = trim((string) ($snapshot['excerpt'] ?? ''));
        if ($excerpt !== '' && count($bullets) < 3) {
            foreach (preg_split('/(?<=[.!?])\s+/', $excerpt) as $sentence) {
                $sentence = $this->sanitize_social_bullet_text($sentence);
                if ($sentence === '' || in_array($sentence, $bullets, true)) {
                    continue;
                }
                $bullets[] = $sentence;
                if (count($bullets) >= 3) {
                    break;
                }
            }
        }
        return $bullets;
    }

    private function sanitize_social_bullet_text($value) {
        $value = $this->strip_social_poison_phrases($value);
        $value = preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $value));
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\*{3,}/', '**', $value);
        return $this->limit_social_chars($value, 110);
    }

    private function social_copy_policy_error($copy) {
        $copy = is_array($copy) ? $copy : array();
        $parts = array(
            $copy['hook'] ?? '',
            $copy['on_image_headline'] ?? '',
            $copy['on_image_subhead'] ?? '',
            $copy['on_image_kicker'] ?? '',
            $copy['cta'] ?? '',
            $copy['x'] ?? '',
            $copy['instagram'] ?? '',
            $copy['instagram_alt'] ?? '',
            $copy['reel_script'] ?? '',
            $copy['youtube_title'] ?? '',
            $copy['youtube_description'] ?? '',
        );
        foreach ((array) ($copy['on_image_bullets'] ?? array()) as $bullet) {
            $parts[] = is_array($bullet) ? (string) ($bullet['text'] ?? '') : (string) $bullet;
        }
        foreach ((array) ($copy['reel_captions'] ?? array()) as $caption) {
            $parts[] = is_array($caption) ? (string) ($caption['caption'] ?? implode(' ', $caption)) : (string) $caption;
        }
        foreach ((array) ($copy['youtube_tags'] ?? array()) as $tag) {
            $parts[] = (string) $tag;
        }
        $reason = $this->social_caption_policy_reason(implode("\n", $parts));
        if ($reason !== '') {
            return new WP_Error(
                'tr_ai_wire_social_policy',
                'Held: the social copy is not safe to publish (' . $reason . '). Edit the caption or regenerate copy.'
            );
        }
        return null;
    }

    private function social_caption_policy_reason($text) {
        $text = strtolower(wp_strip_all_tags((string) $text));
        if ($text === '') {
            return '';
        }
        if (preg_match('/\b(porn|porno|xxx|onlyfans|nsfw|hentai|rape|raping)\b/i', $text)) {
            return 'sexual content';
        }
        if (preg_match('/\b(fuck|fucking|motherfucker|cunt)\b/i', $text)) {
            return 'profanity';
        }
        if (preg_match('/\b(kill yourself|kys)\b/i', $text)) {
            return 'self-harm';
        }
        return '';
    }

    private function social_image_brief_is_unsafe($text) {
        $text = strtolower(wp_strip_all_tags((string) $text));
        if ($text === '') {
            return false;
        }
        if (preg_match('/\b(nude|nudity|naked|nsfw|porn|porno|sexual|sexy|seductive|erotic|lingerie|bikini|topless|cleavage|underwear|undressed|stripper)\b/i', $text)) {
            return true;
        }
        if (preg_match('/\b(gore|gory|bloody corpse|decapitat|dismember|behead)\b/i', $text)) {
            return true;
        }
        if (preg_match('/\b(child|children|kid|kids|toddler|infant|baby|minor|underage|teen(?:ager)?s?|schoolgirl|schoolboy)\b/i', $text)) {
            return true;
        }
        if (preg_match('/\b(suicide|self-harm|self harm)\b/i', $text)) {
            return true;
        }
        if (preg_match('/\b(attractive|hot|beautiful)\s+(woman|girl|man|boy|model)\b/i', $text)) {
            return true;
        }
        return false;
    }

    private function social_image_safety_clause() {
        return 'Safety (non-negotiable): news photography only, safe for Instagram and X. '
            . 'All people fully clothed in ordinary work or street clothes; faces not identifiable. '
            . 'No nudity, no sexual content, no lingerie, no suggestive poses, no exposed skin beyond face and hands. '
            . 'No children and no one who appears under 18. '
            . 'No gore, blood, weapons as the subject, self-harm, or graphic injury. '
            . 'No hate symbols, no real identifiable people, no celebrity or politician likenesses, no drugs. PG-rated.';
    }

    private function clip_social_text($value, $max) {
        $value = $this->strip_social_poison_phrases($value);
        $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $value)));
        return $this->limit_social_chars($value, $max);
    }

    private function clip_social_block($value, $max) {
        $value = $this->strip_social_poison_phrases($value);
        $value = trim(wp_strip_all_tags((string) $value));
        $value = preg_replace("/[ \t]+/", ' ', $value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value);
        return $this->limit_social_chars($value, $max);
    }

    /**
     * Instagram withholds generic bait. Strip it even if a model still emits it.
     */
    private function strip_social_poison_phrases($text) {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }
        $patterns = array(
            '/\byou won[\'’]?t believe\b[,!.:]*/iu',
            '/\bwait for it\b[,!.:]*/iu',
            '/\bwait (?:until|till) the end\b[,!.:]*/iu',
            '/\bmust see\b[,!.:]*/iu',
            '/\bgone wrong\b[,!.:]*/iu',
            '/\bthis changes everything\b[,!.:]*/iu',
            '/\bthis will blow your mind\b[,!.:]*/iu',
            '/\bblow(?:s|ing)? your mind\b[,!.:]*/iu',
            '/\bshocking(?:ly)?\b[,!.:]*/iu',
        );
        $out = preg_replace($patterns, '', $text);
        if (!is_string($out)) {
            $out = $text;
        }
        $out = preg_replace('/[ \t]{2,}/', ' ', $out);
        $out = preg_replace("/\n{3,}/", "\n\n", $out);
        return is_string($out) ? trim($out) : trim($text);
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
        $blocked = $this->social_copy_policy_error($copy);
        if (is_wp_error($blocked)) {
            return $blocked;
        }
        $copy = is_array($copy) ? $copy : array();
        $copy['_news_format'] = $this->social_news_format_for_post($post, $settings, 'story');
        $copy['_poster_variant'] = ($copy['_news_format'] === 'breaking_kicker')
            ? 'kicker-bar'
            : $this->social_poster_variant_for_post($post);
        if ($copy['_news_format'] === 'breaking_kicker' && empty($copy['on_image_kicker'])) {
            $copy['on_image_kicker'] = 'TECH NEWS';
        }
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
        if ($this->social_wants_reel($settings, (int) $post->ID)) {
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
        $format = sanitize_key((string) ($copy['_news_format'] ?? ''));
        $comic = $this->post_is_comic($post);
        $prompt = $this->social_image_prompt_for_format($format, $variant, $post, $revision, $profile, $brief, $comic);
        $idea = $this->social_custom_idea($format);
        if ($idea) {
            $idea_text = $this->clip_social_text($idea['title'] . '. ' . $idea['description'], 420);
            if ($idea_text !== '' && !$this->social_image_brief_is_unsafe($idea_text)) {
                $prompt .= ' Publisher-supplied format idea (layout inspiration only; do not copy trademarks, celebrity likenesses, or any specific headline from a reference): '
                    . $idea_text . '.';
                if (!empty($idea['image_id'])) {
                    $prompt .= ' Match the density and overlay rhythm of a publisher-supplied reference stored in their library. Invent a new scene.';
                }
            }
        }
        if ($revision > 0) {
            $prompt .= ' This is regeneration attempt ' . $revision . '. Use a different camera angle, tighter crop, and stronger lighting than a generic stock photo. Prefer dusk, night, or dramatic rim light if it still matches the story. Still no text in the photograph.';
        }
        $prompt .= ' ' . $this->social_image_safety_clause();
        return $prompt;
    }

    private function social_image_prompt_for_format($format, $variant, $post, $revision, $profile, $brief, $comic = false) {
        $art = $this->social_image_art_direction($profile, $comic);
        $camera = $this->social_image_camera($post, $revision, $comic);
        $scene = $brief !== '' ? 'Scene: ' . $brief . '. ' : 'Scene: one scroll-stopping technology-news photograph with a single hero subject filling the frame. ';
        $no_text = 'Do not render any text, letters, numbers, captions, headlines, subtitles, logos, watermarks, lower-thirds, or typography of any kind. ';
        $no_people_brands = 'Do not imitate a specific existing photo. Anonymous fully clothed people (backs, silhouettes, generic office workers) are allowed. Never a real celebrity. No real brand logos. ';
        $stop = $this->social_image_scroll_stop_clause($comic);
        if ($format === 'device_mockup') {
            return 'Untitled photoreal product photograph, vertical 4:5, full-bleed studio or desk still life. '
                . 'One generic phone, wearable, laptop, or similar device that fits the story. No readable UI, no logos, no model names. '
                . 'Keep the upper third simpler so we can overlay a headline later. '
                . $no_text
                . 'This is product photography, not a poster, not a title card, not a thumbnail. '
                . $no_people_brands
                . 'Art direction: ' . $art . '. Camera: ' . $camera . '. '
                . $scene
                . $stop;
        }
        if ($format === 'rumor_card') {
            return 'Untitled photoreal studio photograph, vertical 4:5, full-bleed, light background. '
                . 'A tight cluster of generic rounded tiles or unbranded devices on a pale surface. No real app icons, no logos, no letters. '
                . 'Keep the top of the frame clean for a headline overlay. '
                . $no_text
                . 'This is a photograph, not a poster, not a title card. '
                . $no_people_brands
                . 'Art direction: ' . $art . '. Camera: ' . $camera . '. '
                . $scene
                . $stop;
        }
        if ($format === 'photo_brief') {
            return 'Untitled photoreal cinematic photograph, vertical 4:5, full-bleed, no graphic design. '
                . 'One real-world scene, magazine lighting, shallow depth of field, high contrast. This is a photograph, not a poster, not a title card, not a thumbnail. '
                . 'The whole frame can hold a subject; we will darken it and overlay type later. '
                . $no_text
                . 'Do not draw a poster, card, border, navy panel, illustration, cartoon, 3D render, collage, infographic, or UI screenshot. '
                . $no_people_brands
                . 'Art direction: ' . $art . '. Camera: ' . $camera . '. '
                . $scene
                . $stop;
        }
        return 'Untitled photoreal cinematic photograph, vertical 4:5, full-bleed, no graphic design. '
            . 'One real-world scene, magazine lighting, shallow depth of field, high contrast. This is a photograph, not a poster, not a title card, not a thumbnail. '
            . $this->social_image_safe_zone($variant) . ' '
            . $no_text
            . 'Do not draw a poster, card, border, navy panel, illustration, cartoon, 3D render, collage, infographic, UI screenshot, or product mockup. '
            . $no_people_brands
            . 'Art direction: ' . $art . '. Camera: ' . $camera . '. '
            . $scene
            . $stop;
    }

    private function social_image_scroll_stop_clause($comic = false) {
        $prompt = 'This photo must stop a thumb mid-scroll and make someone tap. '
            . 'Pattern interrupt: extreme crop, one glowing object, rain on glass, dusk rim light, or a surprising scale. ';
        if (!empty($comic)) {
            $prompt .= 'One sly photoreal visual beat is welcome if it still looks like magazine news photography: wry scale, a tired desk, an ironic empty room. Never a drawing or a joke poster. ';
        }
        return $prompt
            . 'High contrast, saturated night color, one hero filling the frame. Not a polite stock photo. '
            . 'Still photoreal news photography, still fully clothed, still no text.';
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

    private function social_daily_mix_limits($settings) {
        $settings = is_array($settings) ? $settings : array();
        $format = $this->normalize_social_post_format($settings['social_post_format'] ?? 'single');
        $carousel = min(20, max(0, (int) ($settings['social_carousel_daily_limit'] ?? 0)));
        $collage = min(20, max(0, (int) ($settings['social_collage_daily_limit'] ?? 0)));
        $single = min(20, max(0, (int) ($settings['social_single_daily_limit'] ?? 0)));
        $reel = min(20, max(0, (int) ($settings['social_reel_daily_limit'] ?? 0)));
        if (empty($settings['social_mix_saved'])) {
            if ($format === 'carousel') {
                if ($carousel < 1) {
                    $carousel = 3;
                }
                $collage = 0;
                $reel = 0;
            } elseif ($format === 'collage') {
                if ($collage < 1) {
                    $collage = 1;
                }
                $carousel = 0;
                $reel = 0;
            } elseif ($format === 'reel') {
                $carousel = 0;
                $collage = 0;
                $single = 0;
                if ($reel < 1) {
                    $reel = min(10, max(1, (int) ($settings['social_daily_limit'] ?? 10)));
                }
            }
            // format=single is the stored default. Keep the numeric mix (carousel 3,
            // single 2, collage 0) instead of throwing pack quotas away. That zeroing
            // made every site post only singles until Social settings were saved.
            if ($single < 1 && $carousel < 1 && $collage < 1 && $reel < 1) {
                $single = 2;
            }
        }
        $mix = $this->clamp_social_mix_counts(array(
            'reel' => $reel,
            'carousel' => $carousel,
            'collage' => $collage,
            'single' => $single,
        ), (int) ($settings['social_daily_limit'] ?? 10));
        return array(
            'carousel' => $mix['carousel'],
            'collage' => $mix['collage'],
            'single' => $mix['single'],
            'reel' => $mix['reel'],
        );
    }

    private function social_article_daily_limit($settings) {
        $settings = is_array($settings) ? $settings : array();
        $posts = min(20, max(1, (int) ($settings['social_daily_limit'] ?? 10)));
        $mix = $this->social_daily_mix_limits($settings);
        $slides = $this->social_pack_slide_count($settings);
        $need = (int) $mix['reel'] + (int) $mix['single'] + $slides * ((int) $mix['carousel'] + (int) $mix['collage']);
        if ($need < 1) {
            $need = $posts;
        }
        return min(200, max($posts, $need));
    }

    private function social_pack_slide_count($settings) {
        $count = (int) (is_array($settings) ? ($settings['social_slide_count'] ?? 5) : 5);
        return in_array($count, array(4, 5, 6), true) ? $count : 5;
    }

    private function social_mix_remaining($settings) {
        $limits = $this->social_daily_mix_limits($settings);
        $mix = $this->social_mix_today();
        return array(
            'carousel' => max(0, $limits['carousel'] - $mix['carousel']),
            'collage' => max(0, $limits['collage'] - $mix['collage']),
            'single' => max(0, $limits['single'] - $mix['single']),
            'reel' => max(0, ($limits['reel'] ?? 0) - ($mix['reel'] ?? 0)),
        );
    }

    private function social_next_pack_kind($settings) {
        $left = $this->social_mix_remaining($settings);
        if ($left['carousel'] > 0) {
            return 'carousel';
        }
        if ($left['collage'] > 0) {
            return 'collage';
        }
        return '';
    }

    private function social_item_pack_format($item, $settings) {
        $post = is_array($item) ? ($item['post'] ?? null) : null;
        $mode = '';
        if ($post instanceof WP_Post) {
            $mode = (string) get_post_meta($post->ID, '_tr_ai_wire_social_mode', true);
        }
        if ($mode === 'collage') {
            return 'collage';
        }
        if ($mode === 'carousel' || $mode === 'pack') {
            return 'carousel';
        }
        if ($mode === 'reel') {
            return 'reel';
        }
        if ($mode === 'single') {
            return 'single';
        }
        $kind = $this->social_next_pack_kind($settings);
        return $kind !== '' ? $kind : $this->normalize_social_post_format($settings['social_post_format'] ?? 'single');
    }

    private function social_slide_count($settings) {
        $limits = $this->social_daily_mix_limits($settings);
        if ($limits['carousel'] < 1 && $limits['collage'] < 1) {
            return 1;
        }
        return $this->social_pack_slide_count($settings);
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

    private function social_min_review_score($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        return min(100, max(0, (int) ($settings['social_min_review_score'] ?? 90)));
    }

    private function social_article_review_score($post_id) {
        $qid = (int) get_post_meta((int) $post_id, '_tr_ai_wire_queue_id', true);
        if ($qid < 1) {
            return null;
        }
        $item = $this->get_item($qid);
        if (!is_array($item)) {
            return null;
        }
        return $this->extract_review_score($item['review_json'] ?? '');
    }

    private function social_article_clears_quality_gate($post_id, $settings = null) {
        $min = $this->social_min_review_score($settings);
        if ($min < 1) {
            return true;
        }
        $score = $this->social_article_review_score($post_id);
        if ($score === null) {
            return true;
        }
        return $score >= $min;
    }

    private function mark_social_quality_skip($post_id, $settings = null) {
        $score = $this->social_article_review_score($post_id);
        $min = $this->social_min_review_score($settings);
        $detail = $score === null
            ? 'No review score'
            : ('Review score ' . (string) $score . ' is below the social minimum ' . (string) $min);
        update_post_meta((int) $post_id, '_tr_ai_wire_social_skip', $detail);
    }

    private function social_quality_label($post_id) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return '';
        }
        $skip = trim((string) get_post_meta($post_id, '_tr_ai_wire_social_skip', true));
        if ($skip !== '') {
            return 'Skipped';
        }
        $mode = sanitize_key((string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
        if ($mode === 'reel') {
            return 'Reel';
        }
        if ($mode === 'pack' || $mode === 'carousel') {
            return 'Carousel';
        }
        if ($mode === 'collage') {
            return 'Collage';
        }
        if ($mode === 'single') {
            return 'Still';
        }
        return '';
    }

    private function collect_social_article($post_id, $settings) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return;
        }
        if (!$this->social_article_clears_quality_gate($post_id, $settings)) {
            $this->mark_social_quality_skip($post_id, $settings);
            return;
        }
        delete_post_meta($post_id, '_tr_ai_wire_social_skip');
        $left = $this->social_mix_remaining($settings);
        if ($left['reel'] > 0 && $this->social_reel_weekly_remaining($settings) > 0) {
            $this->enqueue_social_article($post_id, 'reel');
            $this->social_mix_add('reel');
            $this->kick_social_queue(30);
            return;
        }
        if ($left['carousel'] > 0 || $left['collage'] > 0) {
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
        if ($left['single'] > 0) {
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
        if (!in_array($platform, array('x', 'instagram', 'youtube'), true) || !in_array($platform, self::connected_social_platforms(), true)) {
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
            if (!$this->social_article_clears_quality_gate($post_id)) {
                continue;
            }
            if ($platform === 'youtube') {
                $mode = sanitize_key((string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
                if ($mode !== 'reel') {
                    continue;
                }
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
        $sql = "SELECT COUNT(DISTINCT s.post_id) FROM {$wpdb->prefix}tr_ai_wire_social s
             WHERE NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}tr_ai_wire_social p WHERE p.post_id = s.post_id AND p.platform = %s
             )";
        if ($platform === 'youtube') {
            $sql .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = s.post_id AND m.meta_key = %s AND m.meta_value = %s)", '_tr_ai_wire_social_mode', 'reel');
        }
        return (int) $wpdb->get_var($wpdb->prepare($sql, $platform));
    }

    private function enqueue_social_article($post_id, $mode = 'single') {
        $post_id = (int) $post_id;
        $platforms = self::connected_social_platforms();
        if ($post_id < 1 || !$platforms) {
            return;
        }
        $mode = sanitize_key((string) $mode);
        if ($mode === 'carousel') {
            $mode = 'pack';
        }
        if (!in_array($mode, array('pack', 'collage', 'single', 'reel'), true)) {
            $mode = 'single';
        }
        update_post_meta($post_id, '_tr_ai_wire_social_mode', $mode);
        global $wpdb;
        $table = $this->social_table();
        $now = current_time('mysql', true);
        foreach ($platforms as $platform) {
            if ($platform === 'youtube' && $mode !== 'reel') {
                continue;
            }
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
        $left = $this->social_mix_remaining($settings);
        $pack_kind = $this->social_next_pack_kind($settings);
        $count = count($ids);
        // A carousel/collage needs at least two articles. Process now may ship a
        // short pack (2+) before the configured slide count, but a single leftover
        // stays held while pack quota remains — otherwise Instagram publishes it
        // as a still and the mix looks like "singles only".
        $as_pack = $pack_kind !== '' && $count >= 2 && ($force || $count >= $wanted);
        if (!$as_pack && !($allow_singles && $left['single'] > 0 && $pack_kind === '')) {
            return false;
        }
        $this->social_batch_save(array());
        $mode = $as_pack ? $pack_kind : 'single';
        $queued = 0;
        foreach ($ids as $post_id) {
            if ($mode === 'single' && $queued >= $left['single']) {
                break;
            }
            $post = get_post((int) $post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            $this->enqueue_social_article((int) $post_id, $mode === 'carousel' ? 'pack' : $mode);
            $queued++;
        }
        if ($queued < 1) {
            return false;
        }
        $this->social_mix_add($as_pack ? $pack_kind : 'single', $as_pack ? 1 : $queued);
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
            'collage' => max(0, (int) (is_array($stored) ? ($stored['collage'] ?? 0) : 0)),
            'single' => max(0, (int) (is_array($stored) ? ($stored['single'] ?? 0) : 0)),
            'reel' => max(0, (int) (is_array($stored) ? ($stored['reel'] ?? 0) : 0)),
        );
    }

    private function social_mix_add($kind, $by = 1) {
        $mix = $this->social_mix_today();
        if ($kind !== 'carousel' && $kind !== 'collage' && $kind !== 'reel') {
            $kind = 'single';
        }
        $mix[$kind] += max(1, (int) $by);
        update_option($this->social_mix_option_name(), $mix, false);
        if ($kind === 'reel') {
            $week = $this->social_reel_week_count();
            update_option($this->social_reel_week_option_name(), $week + max(1, (int) $by), false);
        }
    }

    private function social_reel_week_option_name() {
        return self::SOCIAL_MIX_OPTION . '_week_' . gmdate('oW');
    }

    private function social_reel_week_count() {
        return max(0, (int) get_option($this->social_reel_week_option_name(), 0));
    }

    private function social_reel_weekly_remaining($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $cap = min(14, max(0, absint($settings['social_reel_weekly_limit'] ?? 0)));
        if ($cap < 1) {
            return 99;
        }
        return max(0, $cap - $this->social_reel_week_count());
    }

    private function social_row_is_pack($row, $settings) {
        if ((string) ($row['platform'] ?? '') === 'youtube') {
            return false;
        }
        $mode = (string) get_post_meta((int) ($row['post_id'] ?? 0), '_tr_ai_wire_social_mode', true);
        if ($mode === 'single' || $mode === 'reel') {
            return false;
        }
        if ($mode === 'pack' || $mode === 'carousel' || $mode === 'collage') {
            return true;
        }
        return $this->social_next_pack_kind($settings) !== '';
    }

    private function social_still_platforms() {
        $platforms = array();
        foreach (self::connected_social_platforms() as $platform) {
            if ($platform === 'x' || $platform === 'instagram') {
                $platforms[] = $platform;
            }
        }
        return $platforms;
    }

    private function social_row_explicit_pack($row) {
        $mode = sanitize_key((string) get_post_meta((int) ($row['post_id'] ?? 0), '_tr_ai_wire_social_mode', true));
        if ($mode === 'collage') {
            return 'collage';
        }
        if ($mode === 'pack' || $mode === 'carousel') {
            return 'carousel';
        }
        return '';
    }

    private function social_row_is_forced_pack($row) {
        $value = sanitize_key((string) get_post_meta((int) ($row['post_id'] ?? 0), '_tr_ai_wire_social_force_pack', true));
        return $value === 'carousel' || $value === 'collage' || $value === 'reel';
    }

    private function social_row_is_reel($row) {
        $post_id = (int) ($row['post_id'] ?? 0);
        if ($post_id < 1) {
            return false;
        }
        return sanitize_key((string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true)) === 'reel';
    }

    private function social_row_holds_reel_for_review($row) {
        $post_id = (int) ($row['post_id'] ?? 0);
        if ($post_id < 1) {
            return false;
        }
        if ((string) get_post_meta($post_id, '_tr_ai_wire_social_hold_reel', true) !== '1') {
            return false;
        }
        return $this->social_row_is_reel($row);
    }

    private function social_reel_hold_copy($row) {
        $platform = self::social_platform_label((string) ($row['platform'] ?? ''));
        return 'Held: Watch this Reel, then click Post to ' . $platform . '. It has not been posted yet.';
    }

    private function social_reel_preview_id($post_id) {
        $post_id = (int) $post_id;
        $video_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_reel_id', true);
        if ($video_id < 1 || $this->social_reel_cut_is_stale($video_id)) {
            return 0;
        }
        $path = get_attached_file($video_id);
        return ($path && file_exists($path)) ? $video_id : 0;
    }

    private function release_social_rows_to_queue($rows) {
        global $wpdb;
        $table = $this->social_table();
        $now = current_time('mysql', true);
        foreach ((array) $rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $wpdb->update($table, array(
                'status' => 'queued',
                'updated_at' => $now,
            ), array(
                'id' => $id,
                'status' => 'processing',
            ));
        }
    }

    private function social_queue_waiting_for_pack_mate() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT DISTINCT post_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued' LIMIT 40", ARRAY_A);
        if (!$rows) {
            return false;
        }
        $counts = array('carousel' => array(), 'collage' => array());
        foreach ($rows as $row) {
            $format = $this->social_row_explicit_pack($row);
            if ($format === '') {
                return false;
            }
            $counts[$format][(int) $row['post_id']] = true;
        }
        foreach ($counts as $posts) {
            if (count($posts) >= 2) {
                return false;
            }
        }
        return true;
    }

    private function social_queued_forced_pack_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social q
             INNER JOIN {$wpdb->postmeta} m
                ON m.post_id = q.post_id
               AND m.meta_key = '_tr_ai_wire_social_force_pack'
               AND m.meta_value IN ('carousel', 'collage', 'reel')
             WHERE q.status = 'queued'"
        );
    }

    private function force_generate_social_pack($kind) {
        $kind = $kind === 'collage' ? 'collage' : 'carousel';
        if (!$this->social_distribution_ready() || !$this->social_still_platforms()) {
            return new WP_Error('tr_ai_wire_social_pack', 'Turn on X or Instagram under Social settings before generating a ' . $kind . '. YouTube only receives Reels.');
        }
        $ids = $this->social_force_pack_candidates($this->social_pack_slide_count($this->settings()), $kind);
        if (count($ids) < 2) {
            $pending = $this->social_explicit_pack_pending_count($kind);
            if ($pending >= 2) {
                return new WP_Error('tr_ai_wire_social_pack', 'A ' . $kind . ' of ' . (string) $pending . ' articles is already queued. Use Process queued social posts now to publish it.');
            }
            $ready = count($ids);
            $ready_label = $ready === 1 ? 'is 1' : ('are ' . (string) $ready);
            return new WP_Error('tr_ai_wire_social_pack', 'A ' . $kind . ' needs at least 2 published articles that have not already been posted. There ' . $ready_label . ' ready right now.');
        }
        $batch = array();
        foreach ($this->social_batch_ids() as $id) {
            if (!in_array((int) $id, $ids, true)) {
                $batch[] = (int) $id;
            }
        }
        $this->social_batch_save($batch);
        foreach ($ids as $post_id) {
            $this->enqueue_social_article((int) $post_id, $kind);
            update_post_meta((int) $post_id, '_tr_ai_wire_social_force_pack', $kind);
        }
        $this->social_mix_add($kind, 1);
        $this->kick_social_queue(5);
        return count($ids);
    }

    private function force_generate_social_reel() {
        if (!$this->social_distribution_ready()) {
            return new WP_Error('tr_ai_wire_social_reel', 'Turn on Instagram or YouTube under Social settings before generating a Reel.');
        }
        $platforms = self::connected_social_platforms();
        if (!in_array('instagram', $platforms, true) && !in_array('youtube', $platforms, true)) {
            return new WP_Error('tr_ai_wire_social_reel', 'Turn on Instagram or YouTube under Social settings before generating a Reel. X only receives the still poster.');
        }
        if ($this->social_reel_weekly_remaining() < 1) {
            return new WP_Error('tr_ai_wire_social_reel', 'The weekly Reel cap is full. Raise it on Social settings, or wait for next week.');
        }
        $waiting = $this->social_held_reel_pending_post_id();
        if ($waiting > 0) {
            return new WP_Error('tr_ai_wire_social_reel', 'A Reel is already waiting for review on Social queue. Watch it, then click Post. It has not been posted yet.');
        }
        $ids = $this->social_force_reel_candidates();
        if (!$ids) {
            return new WP_Error('tr_ai_wire_social_reel', 'A Reel needs one published article that has not already gone out as video. There is none ready right now.');
        }
        $post_id = (int) $ids[0];
        // New generation must not reuse a broken older MP4 / voice / lesson cards.
        $this->purge_social_reel_assets($post_id, array('keep_broll' => false, 'regen_script' => false));
        $batch = array();
        foreach ($this->social_batch_ids() as $id) {
            if ((int) $id !== $post_id) {
                $batch[] = (int) $id;
            }
        }
        $this->social_batch_save($batch);
        $this->enqueue_social_article($post_id, 'reel');
        update_post_meta($post_id, '_tr_ai_wire_social_force_pack', 'reel');
        update_post_meta($post_id, '_tr_ai_wire_social_hold_reel', '1');
        $this->social_mix_add('reel', 1);
        $this->kick_social_queue(5);
        return $post_id;
    }

    private function social_reel_job_message() {
        $post_id = $this->social_held_reel_pending_post_id();
        if ($post_id < 1) {
            return 'The Reel was queued. Open Social queue to follow it.';
        }
        if ($this->social_reel_preview_id($post_id) > 0) {
            return 'Your Reel is ready. Watch it on Social queue, then click Post. It is not live yet.';
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, error_text FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform IN ('instagram', 'youtube') ORDER BY id ASC LIMIT 1",
            $post_id
        ), ARRAY_A);
        if (is_array($row) && (string) ($row['status'] ?? '') === 'needs_review') {
            return 'The Reel build stopped: ' . $this->social_error_copy((string) ($row['error_text'] ?? '')) . ' Click Resume on the row.';
        }
        $progress = $this->social_reel_progress($post_id);
        $detail = !empty($progress['detail']) ? (' ' . (string) $progress['detail']) : '';
        return 'Your Reel is building.' . $detail . ' Social queue shows each step and keeps itself up to date. Nothing is posted until you click Post.';
    }

    private function social_held_reel_pending_post_id() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT s.post_id FROM {$wpdb->prefix}tr_ai_wire_social s
             INNER JOIN {$wpdb->postmeta} h
                ON h.post_id = s.post_id
               AND h.meta_key = '_tr_ai_wire_social_hold_reel'
               AND h.meta_value = '1'
             INNER JOIN {$wpdb->postmeta} m
                ON m.post_id = s.post_id
               AND m.meta_key = '_tr_ai_wire_social_mode'
               AND m.meta_value = 'reel'
             WHERE s.status IN ('queued', 'processing', 'needs_review')
             LIMIT 1"
        );
    }

    private function social_force_reel_candidates() {
        $ids = array();
        $consider = function ($post_id) use (&$ids) {
            $post_id = (int) $post_id;
            if ($post_id < 1 || $ids || in_array($post_id, $ids, true)) {
                return;
            }
            if ($this->social_force_reel_candidate_ok($post_id)) {
                $ids[] = $post_id;
            }
        };
        global $wpdb;
        $queued = $wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued' GROUP BY post_id ORDER BY MIN(created_at) ASC LIMIT 40");
        foreach ((array) $queued as $id) {
            $consider($id);
        }
        if ($ids) {
            return $ids;
        }
        $recent = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 40,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_tr_ai_wire_queue_id',
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        foreach ((array) $recent as $id) {
            $consider($id);
        }
        return $ids;
    }

    private function social_force_reel_candidate_ok($post_id) {
        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            return false;
        }
        if (!get_post_meta($post_id, '_tr_ai_wire_queue_id', true)) {
            return false;
        }
        if (in_array($post_id, $this->social_batch_ids(), true)) {
            return false;
        }
        $mode = sanitize_key((string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
        if (in_array($mode, array('pack', 'carousel', 'collage', 'reel'), true)) {
            return false;
        }
        if (!$this->social_article_clears_quality_gate($post_id)) {
            return false;
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id, platform, status FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d",
            $post_id
        ), ARRAY_A);
        foreach ((array) $rows as $row) {
            $status = sanitize_key((string) ($row['status'] ?? ''));
            $platform = sanitize_key((string) ($row['platform'] ?? ''));
            if (in_array($status, array('published', 'processing', 'needs_review'), true) && in_array($platform, array('instagram', 'youtube'), true)) {
                return false;
            }
            if (in_array($status, array('processing', 'needs_review'), true)) {
                return false;
            }
            if ($status === 'queued' && $this->social_item_is_manual_repost($row)) {
                return false;
            }
        }
        return true;
    }

    private function social_force_pack_candidates($limit, $kind) {
        $limit = max(2, (int) $limit);
        $kind = $kind === 'collage' ? 'collage' : 'carousel';
        $ids = array();
        $consider = function ($post_id) use (&$ids, $limit, $kind) {
            $post_id = (int) $post_id;
            if ($post_id < 1 || count($ids) >= $limit || in_array($post_id, $ids, true)) {
                return;
            }
            if ($this->social_force_pack_candidate_ok($post_id, $kind)) {
                $ids[] = $post_id;
            }
        };
        foreach ($this->social_batch_ids() as $id) {
            $consider($id);
        }
        if (count($ids) >= $limit) {
            return $ids;
        }
        global $wpdb;
        $queued = $wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'queued' GROUP BY post_id ORDER BY MIN(created_at) ASC LIMIT 40");
        foreach ((array) $queued as $id) {
            $consider($id);
        }
        if (count($ids) >= $limit) {
            return $ids;
        }
        $recent = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 40,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_tr_ai_wire_queue_id',
            'fields' => 'ids',
            'no_found_rows' => true,
        ));
        foreach ((array) $recent as $id) {
            $consider($id);
        }
        return $ids;
    }

    private function social_force_pack_candidate_ok($post_id, $kind) {
        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            return false;
        }
        if (!get_post_meta($post_id, '_tr_ai_wire_queue_id', true)) {
            return false;
        }
        $mode = sanitize_key((string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
        if ($mode === 'reel') {
            return false;
        }
        if (!$this->social_article_clears_quality_gate($post_id)) {
            return false;
        }
        $kind = $kind === 'collage' ? 'collage' : 'carousel';
        $already = ($kind === 'collage' && $mode === 'collage') || ($kind === 'carousel' && ($mode === 'pack' || $mode === 'carousel'));
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id, status FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d",
            $post_id
        ), ARRAY_A);
        $queued = 0;
        foreach ((array) $rows as $row) {
            $status = sanitize_key((string) ($row['status'] ?? ''));
            if (in_array($status, array('published', 'processing', 'needs_review'), true)) {
                return false;
            }
            if ($status === 'queued') {
                $queued++;
                if ($this->social_item_is_manual_repost($row)) {
                    return false;
                }
            }
        }
        if ($already && $queued > 0) {
            return false;
        }
        return true;
    }

    private function maybe_start_stuck_pack_job() {
        if (!$this->social_distribution_ready()) {
            return '';
        }
        $carousel = $this->social_explicit_pack_pending_count('carousel');
        $collage = $this->social_explicit_pack_pending_count('collage');
        if ($carousel < 2 && $collage < 2) {
            return '';
        }
        $kind = $carousel >= 2 ? 'carousel' : 'collage';
        $current = get_option(self::JOB_OPTION, array());
        $busy = is_array($current)
            && in_array((string) ($current['status'] ?? ''), array('queued', 'running'), true)
            && (time() - (int) ($current['updated_at'] ?? 0)) < 180;
        if (!$busy) {
            $this->kick_social_queue(5);
            $this->enqueue_background_job('social');
        }
        return $kind;
    }

    private function social_explicit_pack_pending_count($kind) {
        $kind = $kind === 'collage' ? 'collage' : 'carousel';
        $modes = $kind === 'collage' ? array('collage') : array('pack', 'carousel');
        global $wpdb;
        $placeholders = implode(', ', array_fill(0, count($modes), '%s'));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT s.post_id) FROM {$wpdb->prefix}tr_ai_wire_social s
             INNER JOIN {$wpdb->postmeta} m
                ON m.post_id = s.post_id
               AND m.meta_key = '_tr_ai_wire_social_mode'
               AND m.meta_value IN ({$placeholders})
             WHERE s.status = 'queued'",
            ...$modes
        ));
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
        $format = sanitize_key((string) ($copy['_news_format'] ?? ''));
        if ($format !== '') {
            update_post_meta($attachment_id, '_tr_ai_wire_social_news_format', $format);
        }
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

    private function social_image_camera($post, $revision = 0, $comic = false) {
        $cameras = array(
            'tight overhead still life of one device on a dark desk, hard side light, wet reflections',
            'extreme close-up of a phone or laptop edge, neon bokeh, rain on glass',
            'macro of a keyboard, cable, or chip with a single rim light and dust in the beam',
            'empty newsroom desk at night, cyan monitor glow, no people',
            'wide city grid at dusk from a high window, no figures',
        );
        if (!empty($comic)) {
            $cameras[] = 'empty conference table at night with one laptop open and chairs pushed back, dusk windows';
            $cameras[] = 'cold coffee beside a glowing phone on a dark desk, no people';
        }
        $id = ($post instanceof WP_Post) ? (int) $post->ID : (int) $post;
        $index = (int) (sprintf('%u', crc32('cam:' . $id . ':' . (int) $revision)) % count($cameras));
        return $cameras[$index];
    }

    private function social_image_art_direction($profile, $comic = false) {
        $default = 'cinematic photoreal news photography, dusk or night rim light, high contrast, one hero subject filling the frame, built to stop a thumb';
        if (!empty($comic)) {
            $default = 'cinematic photoreal news photography, dusk or night rim light, high contrast, one hero subject filling the frame, one sly visual beat, built to stop a thumb';
        }
        $style = trim((string) ($profile['visual_style'] ?? ''));
        if ($style === '' || preg_match('/illustrat|cartoon|navy|magazine art|vector|flat design|infographic|collage|3d render/i', $style)) {
            return $default;
        }
        if ($this->social_image_brief_is_unsafe($style)) {
            return $default;
        }
        return $style;
    }

    private function social_image_scene_brief($brief) {
        $brief = trim((string) $brief);
        if ($brief === '' || preg_match('/illustrat|cartoon|symbolic|navy|vector|infographic|magazine card/i', $brief)) {
            return '';
        }
        if ($this->social_image_brief_is_unsafe($brief)) {
            return '';
        }
        return $brief;
    }

    private function social_reel_asset_attachment_ids($post_id) {
        $post_id = (int) $post_id;
        $ids = array();
        foreach (array(
            '_tr_ai_wire_social_reel_id',
            '_tr_ai_wire_social_motion_id',
            '_tr_ai_wire_social_host_id',
            '_tr_ai_wire_social_voice_id',
            '_tr_ai_wire_social_endcard_id',
            '_tr_ai_wire_social_reel_kit_id',
            '_tr_ai_wire_social_host_scene_id',
            '_tr_ai_wire_social_broll_id',
            '_tr_ai_wire_reel_hold_id',
            '_tr_ai_wire_reel_trim_src',
        ) as $key) {
            $id = (int) get_post_meta($post_id, $key, true);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            foreach (array('_tr_ai_wire_reel_detail_', '_tr_ai_wire_reel_card_vid_') as $prefix) {
                $id = (int) get_post_meta($post_id, $prefix . (string) $i, true);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $end_card_vid = (int) get_post_meta($post_id, '_tr_ai_wire_reel_card_vid_90', true);
        if ($end_card_vid > 0) {
            $ids[] = $end_card_vid;
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Delete every saved Reel media file for a post (finished MP4, motion, host,
     * voice, lesson cards, kit) and clear Fal/lesson state so a new build cannot
     * reuse a broken older file.
     */
    private function purge_social_reel_assets($post_id, $args = array()) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return 0;
        }
        $args = is_array($args) ? $args : array();
        $keep_broll = !empty($args['keep_broll']);
        $regen_script = !empty($args['regen_script']);
        $deleted = 0;
        $keep = array();
        if ($keep_broll) {
            $broll = (int) get_post_meta($post_id, '_tr_ai_wire_social_broll_id', true);
            if ($broll > 0) {
                $keep[$broll] = true;
            }
        }
        foreach ($this->social_reel_asset_attachment_ids($post_id) as $attachment_id) {
            if (!empty($keep[$attachment_id])) {
                continue;
            }
            // These IDs came from Reel meta on this post — delete the files so a new
            // build cannot keep serving a broken older MP4 from the Media Library.
            if (get_post($attachment_id)) {
                wp_delete_attachment($attachment_id, true);
                $deleted++;
            }
        }
        foreach (array(
            '_tr_ai_wire_social_reel_id',
            '_tr_ai_wire_social_motion_id',
            '_tr_ai_wire_social_host_id',
            '_tr_ai_wire_social_voice_id',
            '_tr_ai_wire_social_endcard_id',
            '_tr_ai_wire_social_reel_kit_id',
            '_tr_ai_wire_social_host_scene_id',
            '_tr_ai_wire_fal_request',
            '_tr_ai_wire_fal_host_request',
            '_tr_ai_wire_fal_compose_request',
            '_tr_ai_wire_fal_trim_request',
            '_tr_ai_wire_fal_hold',
            '_tr_ai_wire_reel_hold_id',
            '_tr_ai_wire_reel_trim_src',
            '_tr_ai_wire_hold_scale',
            '_tr_ai_wire_hold_scale_tries',
            '_tr_ai_wire_hold_keep',
            '_tr_ai_wire_reel_no_sting',
            '_tr_ai_wire_reel_progress',
            '_tr_ai_wire_reel_ledger',
            '_tr_ai_wire_reel_debug_log',
            '_tr_ai_wire_reel_auto_retries',
            '_tr_ai_wire_reel_lesson',
            '_tr_ai_wire_reel_audio_seconds',
            '_tr_ai_wire_reel_cards_state',
        ) as $meta) {
            delete_post_meta($post_id, $meta);
        }
        if (!$keep_broll) {
            delete_post_meta($post_id, '_tr_ai_wire_social_broll_id');
        }
        for ($i = 0; $i < 8; $i++) {
            delete_post_meta($post_id, '_tr_ai_wire_reel_detail_' . (string) $i);
            delete_post_meta($post_id, '_tr_ai_wire_reel_card_vid_' . (string) $i);
            delete_post_meta($post_id, '_tr_ai_wire_fal_card_' . (string) $i);
        }
        delete_post_meta($post_id, '_tr_ai_wire_reel_card_vid_90');
        delete_post_meta($post_id, '_tr_ai_wire_fal_card_90');
        if ($regen_script) {
            delete_post_meta($post_id, '_tr_ai_wire_social_copy');
            update_post_meta($post_id, '_tr_ai_wire_reel_regen_script', '1');
        }
        return $deleted;
    }

    private function forget_cached_social_image($post_id) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return 0;
        }
        $this->purge_social_reel_assets($post_id, array('keep_broll' => false, 'regen_script' => false));
        $image_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_image_id', true);
        if ($image_id > 0 && get_post_meta($image_id, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment($image_id, true);
        }
        $slide_ids = get_post_meta($post_id, '_tr_ai_wire_social_slide_ids', true);
        if (is_array($slide_ids)) {
            foreach ($slide_ids as $slide_id) {
                $slide_id = (int) $slide_id;
                if ($slide_id > 0 && get_post_meta($slide_id, '_tr_ai_wire_social_asset', true) === '1') {
                    wp_delete_attachment($slide_id, true);
                }
            }
        }
        delete_post_meta($post_id, '_tr_ai_wire_social_image_id');
        delete_post_meta($post_id, '_tr_ai_wire_social_slide_ids');
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
        if (!$regenerate_image && !$from_published) {
            delete_post_meta($post_id, '_tr_ai_wire_social_hold_reel');
        }
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
        if ($platform === 'youtube') {
            return $this->delete_youtube_video($remote_id, $credentials);
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
        $format = sanitize_key((string) ($copy['_news_format'] ?? ''));
        if ($this->social_news_format_has_custom_overlay($format)) {
            $this->draw_social_news_format($canvas, $format, $copy, $target_width, $target_height);
        } else {
            $kicker = trim((string) ($copy['on_image_kicker'] ?? ''));
            $this->draw_social_poster_variant($canvas, $variant, $headline, $subhead, $target_width, $target_height, $kicker);
        }
        ob_start();
        imagejpeg($canvas, null, 88);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render the branded image.');
    }

    private function draw_social_poster_variant($canvas, $variant, $headline, $subhead, $width, $height, $kicker = '') {
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
            $bar = $kicker !== '' ? $this->social_poster_headline($this->clip_social_text($kicker, 28)) : (($subhead !== '') ? $this->social_poster_headline($this->clip_social_text($subhead, 28)) : 'TECH NEWS');
            $this->draw_social_bottom_gradient($canvas, $width, $height);
            $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, 78);
            $this->draw_social_type_block($canvas, $headline, $subhead, $font, $white, $muted, $stroke, $width, $height, array(
                'align' => 'left',
                'max_width' => 980,
                'left' => 48,
                'max_block' => (int) round($height * 0.42),
                'min_baseline' => 640,
                'handle' => 'right',
                'kicker' => $bar,
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

    private function draw_social_news_format($canvas, $format, $copy, $width, $height) {
        $copy = is_array($copy) ? $copy : array();
        $format = sanitize_key((string) $format);
        if ($format === 'weekly_recap') {
            $this->draw_social_weekly_recap($canvas, $copy, $width, $height);
            return;
        }
        if ($format === 'numbered_roundup') {
            $this->draw_social_numbered_roundup($canvas, $copy, $width, $height);
            return;
        }
        if ($format === 'product_roundup') {
            $this->draw_social_product_roundup($canvas, $copy, $width, $height);
            return;
        }
        if ($format === 'rumor_card') {
            $this->draw_social_rumor_card($canvas, $copy, $width, $height);
            return;
        }
        if ($format === 'device_mockup') {
            $this->draw_social_device_mockup($canvas, $copy, $width, $height);
            return;
        }
        $bullets = (array) ($copy['on_image_bullets'] ?? array());
        if ($format === 'photo_brief' || ($this->social_custom_idea($format) && count($bullets) >= 2)) {
            $this->draw_social_photo_brief($canvas, $copy, $width, $height);
            return;
        }
        $headline = $this->social_poster_headline((string) ($copy['on_image_headline'] ?? $copy['hook'] ?? ''));
        $subhead = trim(wp_strip_all_tags((string) ($copy['on_image_subhead'] ?? '')));
        $this->draw_social_poster_variant($canvas, 'bottom-center', $headline, $subhead, $width, $height, (string) ($copy['on_image_kicker'] ?? ''));
    }

    private function render_local_social_graphic($copy) {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return new WP_Error('tr_ai_wire_social_gd', 'PHP GD is required to render the branded social image.');
        }
        $width = 1080;
        $height = 1350;
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, true);
        $this->draw_social_news_format($canvas, (string) ($copy['_news_format'] ?? 'weekly_recap'), $copy, $width, $height);
        ob_start();
        imagejpeg($canvas, null, 88);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render the branded image.');
    }

    private function draw_social_light_top($canvas, $width, $height, $cover_until = 0.36) {
        $span = (int) round($height * (float) $cover_until);
        for ($i = 0; $i < $span; $i++) {
            $t = 1 - ($i / max(1, $span));
            $alpha = (int) max(0, min(127, (int) round(20 + ((1 - $t) * 100))));
            imageline($canvas, 0, $i, $width, $i, imagecolorallocatealpha($canvas, 248, 248, 250, $alpha));
        }
    }

    private function draw_social_dim($canvas, $width, $height, $alpha = 70) {
        $alpha = max(0, min(127, (int) $alpha));
        $overlay = imagecolorallocatealpha($canvas, 0, 0, 0, $alpha);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $overlay);
    }

    private function draw_social_weekly_recap($canvas, $copy, $width, $height) {
        for ($y = 0; $y < $height; $y++) {
            $t = $y / max(1, $height - 1);
            $r = (int) round(108 - ($t * 36));
            $g = (int) round(52 + ($t * 28));
            $b = (int) round(196 - ($t * 48));
            imageline($canvas, 0, $y, $width, $y, imagecolorallocate($canvas, $r, $g, $b));
        }
        $font = $this->social_font_path();
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 230, 230, 240);
        $stroke = imagecolorallocate($canvas, 40, 10, 80);
        $this->draw_social_brand_mark($canvas, $font, $white, imagecolorallocate($canvas, 255, 255, 255), $width - 360, 78);
        $headline = trim(wp_strip_all_tags((string) ($copy['on_image_headline'] ?? 'THIS WEEK')));
        $layout = $this->social_poster_headline_layout($headline, $font, 980, 720, 4);
        $y = 430;
        foreach ($layout['lines'] as $index => $line) {
            $this->draw_social_aligned_text($canvas, $line, $layout['size'], $y, 'left', 64, $white, $font, $width, $stroke, 2);
            $y += $layout['line_height'];
        }
        $subhead = trim(wp_strip_all_tags((string) ($copy['on_image_subhead'] ?? '')));
        if ($subhead !== '') {
            $this->draw_social_aligned_text($canvas, $subhead, $font ? 28 : 5, $y + 24, 'left', 64, $muted, $font, $width, $stroke, 1);
        }
    }

    private function draw_social_numbered_roundup($canvas, $copy, $width, $height) {
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 8, 42, 58));
        $grid = imagecolorallocatealpha($canvas, 40, 120, 140, 110);
        for ($x = 40; $x < $width; $x += 48) {
            imageline($canvas, $x, 0, $x, $height, $grid);
        }
        for ($y = 40; $y < $height; $y += 48) {
            imageline($canvas, 0, $y, $width, $y, $grid);
        }
        $font = $this->social_font_path();
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $lilac = imagecolorallocate($canvas, 176, 150, 255);
        $chip = imagecolorallocate($canvas, 176, 150, 255);
        $chip_bg = imagecolorallocate($canvas, 55, 48, 110);
        $kicker = strtoupper($this->clip_social_text((string) ($copy['on_image_kicker'] ?? 'NEWS'), 22));
        imagefilledrectangle($canvas, 56, 86, 56 + max(160, (int) (strlen($kicker) * 18)), 138, $chip_bg);
        $this->draw_social_aligned_text($canvas, $kicker, $font ? 18 : 4, 122, 'left', 72, $chip, $font, $width, null, 0);
        $headline = trim(wp_strip_all_tags((string) ($copy['on_image_headline'] ?? '')));
        $words = preg_split('/\s+/', $headline);
        $accent_count = min(3, max(1, (int) ceil(count($words) / 2)));
        $accent = implode(' ', array_slice($words, 0, $accent_count));
        $rest = trim(implode(' ', array_slice($words, $accent_count)));
        $y = 280;
        $size = $font ? 72 : 5;
        if ($accent !== '') {
            foreach ($this->wrap_social_lines($accent, 960, $size, $font, 16, 3) as $line) {
                $this->draw_social_aligned_text($canvas, $line, $size, $y, 'left', 56, $lilac, $font, $width, null, 0);
                $y += (int) round($size * 1.08);
            }
        }
        if ($rest !== '') {
            foreach ($this->wrap_social_lines($rest, 960, $size, $font, 16, 3) as $line) {
                $this->draw_social_aligned_text($canvas, $line, $size, $y, 'left', 56, $white, $font, $width, null, 0);
                $y += (int) round($size * 1.08);
            }
        }
        $subhead = trim(wp_strip_all_tags((string) ($copy['on_image_subhead'] ?? '')));
        if ($subhead !== '') {
            $this->draw_social_aligned_text($canvas, $subhead, $font ? 28 : 5, $y + 28, 'left', 56, $white, $font, $width, null, 0);
        }
        $this->draw_social_brand_mark($canvas, $font, $white, $lilac, 56, $height - 64);
    }

    private function draw_social_product_roundup($canvas, $copy, $width, $height) {
        $this->draw_social_dim($canvas, $width, $height, 55);
        $this->draw_social_bottom_gradient($canvas, $width, $height, 0.55);
        $font = $this->social_font_path();
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $lilac = imagecolorallocate($canvas, 176, 150, 255);
        $stroke = imagecolorallocate($canvas, 0, 0, 0);
        $this->draw_social_brand_mark($canvas, $font, $white, $lilac, (int) ($width / 2) - 140, 96);
        $headline = $this->social_poster_headline((string) ($copy['on_image_headline'] ?? ''));
        $layout = $this->social_poster_headline_layout($headline, $font, 980, 520, 4);
        $y = 520;
        foreach ($layout['lines'] as $index => $line) {
            $color = ($index === count($layout['lines']) - 1 && count($layout['lines']) > 1) ? $lilac : $white;
            $this->draw_social_aligned_text($canvas, $line, $layout['size'], $y, 'center', 50, $color, $font, $width, $stroke, 3);
            $y += $layout['line_height'];
        }
        $subhead = $this->social_poster_headline($this->clip_social_text((string) ($copy['on_image_subhead'] ?? ''), 28));
        if ($subhead !== '') {
            $pill_y = $y + 36;
            imagefilledrectangle($canvas, (int) ($width / 2) - 220, $pill_y - 28, (int) ($width / 2) + 220, $pill_y + 18, imagecolorallocate($canvas, 18, 18, 24));
            $this->draw_social_aligned_text($canvas, $subhead, $font ? 22 : 5, $pill_y, 'center', 50, $white, $font, $width, null, 0);
        }
    }

    private function draw_social_rumor_card($canvas, $copy, $width, $height) {
        $this->draw_social_light_top($canvas, $width, $height, 0.42);
        $font = $this->social_font_path();
        $black = imagecolorallocate($canvas, 20, 20, 24);
        $red = imagecolorallocate($canvas, 196, 32, 32);
        $muted = imagecolorallocate($canvas, 90, 90, 98);
        $kicker = strtoupper($this->clip_social_text((string) ($copy['on_image_kicker'] ?? 'NEWS'), 28));
        $this->draw_social_aligned_text($canvas, $kicker, $font ? 18 : 4, 78, 'center', 40, $red, $font, $width, null, 0);
        $headline = trim(wp_strip_all_tags((string) ($copy['on_image_headline'] ?? '')));
        $size = $font ? 42 : 5;
        $y = 140;
        foreach ($this->wrap_social_lines($headline, 960, $size, $font, 22, 4) as $line) {
            $this->draw_social_aligned_text($canvas, $line, $size, $y, 'center', 40, $black, $font, $width, null, 0);
            $y += (int) round($size * 1.15);
        }
        $handle = self::site_brand_handle();
        if ($handle !== '') {
            $this->draw_social_aligned_text($canvas, $handle, $font ? 16 : 4, $height - 36, 'center', 40, $muted, $font, $width, null, 0);
        }
    }

    private function draw_social_device_mockup($canvas, $copy, $width, $height) {
        $this->draw_social_light_top($canvas, $width, $height, 0.34);
        $font = $this->social_font_path();
        $black = imagecolorallocate($canvas, 20, 20, 24);
        $red = imagecolorallocate($canvas, 196, 32, 32);
        $kicker = strtoupper($this->clip_social_text((string) ($copy['on_image_kicker'] ?? 'NEWS'), 28));
        $this->draw_social_aligned_text($canvas, $kicker, $font ? 16 : 4, 64, 'center', 40, $red, $font, $width, null, 0);
        $headline = trim(wp_strip_all_tags((string) ($copy['on_image_headline'] ?? '')));
        $size = $font ? 40 : 5;
        $y = 120;
        foreach ($this->wrap_social_lines($headline, 960, $size, $font, 22, 3) as $line) {
            $this->draw_social_aligned_text($canvas, $line, $size, $y, 'center', 40, $black, $font, $width, null, 0);
            $y += (int) round($size * 1.12);
        }
    }

    private function draw_social_photo_brief($canvas, $copy, $width, $height) {
        $this->draw_social_dim($canvas, $width, $height, 60);
        $this->draw_social_bottom_gradient($canvas, $width, $height, 0.72);
        $font = $this->social_font_path();
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $cyan = imagecolorallocate($canvas, 43, 213, 255);
        $stroke = imagecolorallocate($canvas, 0, 0, 0);
        $this->draw_social_brand_mark($canvas, $font, $white, $cyan, (int) ($width / 2) - 140, 92);
        $headline = trim(wp_strip_all_tags((string) ($copy['on_image_headline'] ?? '')));
        $size = $font ? 42 : 5;
        $y = 210;
        foreach ($this->wrap_social_lines($headline, 960, $size, $font, 22, 3) as $line) {
            $this->draw_social_aligned_text($canvas, $line, $size, $y, 'center', 50, $white, $font, $width, $stroke, 2);
            $y += (int) round($size * 1.12);
        }
        $bullets = (array) ($copy['on_image_bullets'] ?? array());
        $y += 36;
        foreach (array_slice($bullets, 0, 3) as $bullet) {
            $y = $this->draw_social_highlighted_bullet($canvas, (string) $bullet, $font, 72, $y, $width, $white, $stroke);
            $y += 28;
        }
    }

    private function draw_social_highlighted_bullet($canvas, $text, $font, $x, $y, $width, $white, $stroke) {
        $segments = $this->social_highlight_segments($text);
        $size = $font ? 26 : 5;
        imagefilledellipse($canvas, $x, $y - 8, 10, 10, $white);
        $cursor = $x + 28;
        $max = $width - 64;
        $line_y = $y;
        foreach ($segments as $segment) {
            $chunk = (string) ($segment['text'] ?? '');
            if ($chunk === '') {
                continue;
            }
            $chunk_width = $this->social_text_width($chunk, $size, $font, strlen($chunk) * 12);
            if ($cursor + $chunk_width > $max && $cursor > $x + 28) {
                $line_y += (int) round($size * 1.25);
                $cursor = $x + 28;
            }
            if (!empty($segment['highlight'])) {
                $pad = 6;
                $chip = imagecolorallocatealpha($canvas, 70, 150, 190, 40);
                imagefilledrectangle($canvas, $cursor - $pad, $line_y - $size, $cursor + $chunk_width + $pad, $line_y + 8, $chip);
            }
            $this->draw_social_stroked_text($canvas, $chunk, $size, $cursor, $line_y, $white, $font, $stroke, 2);
            $cursor += $chunk_width + 8;
        }
        return $line_y + (int) round($size * 0.4);
    }

    private function social_highlight_segments($text) {
        $parts = preg_split('/(\*\*[^*]+\*\*)/', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $segments = array();
        foreach ((array) $parts as $part) {
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\*\*(.+)\*\*$/', $part, $matches)) {
                $segments[] = array('text' => $matches[1], 'highlight' => true);
            } else {
                $segments[] = array('text' => $part, 'highlight' => false);
            }
        }
        return $segments;
    }

    private function social_text_width($text, $size, $font, $fallback) {
        if ($font && function_exists('imagettfbbox')) {
            $box = imagettfbbox($size, 0, $font, (string) $text);
            if (is_array($box)) {
                return max(1, abs((int) $box[2] - (int) $box[0]));
            }
        }
        return max(1, (int) $fallback);
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

    private function strip_http_urls_from_text($text) {
        $text = preg_replace('#https?://\S+#i', '', (string) $text);
        $text = preg_replace("/\n[ \t]*\n[ \t]*\n+/", "\n\n", (string) $text);
        return trim((string) $text);
    }

    private function instagram_caption_without_links($caption, $cta = '') {
        $caption = $this->strip_http_urls_from_text($caption);
        $cta = $this->strip_http_urls_from_text($cta);
        if ($cta !== '' && stripos($caption, $cta) === false) {
            $caption = trim($caption . "\n\n" . $cta);
        }
        if ($caption !== '' && stripos($caption, 'link in bio') === false) {
            $caption = trim($caption . "\n\nLink in bio.");
        }
        return $this->clip_social_block($caption, 2200);
    }

    private function publish_instagram_post($caption, $alt_text, $image_url, $credentials, $video_url = '') {
        $caption = $this->instagram_caption_without_links($caption);
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
        $caption = $this->instagram_caption_without_links($caption);
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
            // Photoreal AI video + TTS must be disclosed. Stills do not send this flag.
            'is_ai_generated' => 'true',
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
            $this->social_heartbeat();
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
        $row = $wpdb->get_row($wpdb->prepare("SELECT post_id, platform FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $wpdb->update($table, array(
            'status' => sanitize_key($status),
            'remote_id' => sanitize_text_field($remote_id),
            'remote_url' => esc_url_raw($remote_url),
            'error_text' => $error !== '' ? sanitize_text_field($error) : null,
            'updated_at' => current_time('mysql', true),
        ), array('id' => $id));
        $post_id = (int) ($row['post_id'] ?? 0);
        if ($post_id > 0 && ((string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true) === 'reel' || (string) get_post_meta($post_id, '_tr_ai_wire_social_hold_reel', true) === '1')) {
            $this->social_debug_log($post_id, 'Social row finished as ' . sanitize_key($status) . ($error !== '' ? (': ' . $error) : '.'), array(
                'row_id' => (string) $id,
                'platform' => (string) ($row['platform'] ?? ''),
                'status' => sanitize_key($status),
            ));
        }
        if ($status === 'published' && $row) {
            delete_post_meta($post_id, '_tr_ai_wire_social_repost_' . $id);
            $open = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND status IN ('queued', 'processing')",
                $post_id
            ));
            if ($open < 1) {
                delete_post_meta($post_id, '_tr_ai_wire_social_force_pack');
            }
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
        $this->enqueue_background_job('social_retry', array(
            'item_id' => $id,
            'regenerate' => !empty($_POST['regenerate_image']),
        ));
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social'));
        exit;
    }

    public function pack_social_reel_kit() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to prepare a Reel kit.', 'technorapper-ai-wire'));
        }
        $id = absint($_POST['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_pack_reel_kit_' . $id);
        global $wpdb;
        $row = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A) : null;
        $post_id = (int) ($row['post_id'] ?? 0);
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$row || !$post instanceof WP_Post || !$this->social_row_holds_reel_for_review($row)) {
            wp_die(esc_html__('That Reel row is not available for a laptop kit.', 'technorapper-ai-wire'));
        }
        $settings = $this->social_reel_effective_settings($this->settings());
        $copy = json_decode((string) get_post_meta($post_id, '_tr_ai_wire_social_copy', true), true);
        $copy = is_array($copy) ? $copy : array();
        $script = (string) ($copy['reel_script'] ?? '');
        $plan = $this->social_reel_plan($settings);
        $broll_id = $this->social_broll_id($post_id);
        $broll_path = $broll_id > 0 ? (string) get_attached_file($broll_id) : '';
        if ($broll_path === '' || !file_exists($broll_path)) {
            wp_die(esc_html__('A B-roll still is required before packing the laptop kit. Click Resume once first.', 'technorapper-ai-wire'));
        }
        $parts = $this->social_reel_gather($post, $settings, $copy, $broll_path, $plan, $script);
        if (is_wp_error($parts)) {
            wp_die(esc_html($parts->get_error_message()));
        }
        $photo = file_get_contents($broll_path);
        if (!is_string($photo) || $photo === '') {
            wp_die(esc_html__('The B-roll still could not be read for the laptop kit.', 'technorapper-ai-wire'));
        }
        $end_caption = (string) ($copy['cta'] ?? ('Full story on ' . self::site_brand_name()));
        $kit = $this->social_reel_pack_laptop_kit_fallback($post, $settings, $copy, $script, $plan, $parts, $photo, $end_caption);
        if (is_wp_error($kit)) {
            wp_die(esc_html($kit->get_error_message()));
        }
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social&reel_kit=1'));
        exit;
    }

    public function upload_social_reel() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to upload a Reel.', 'technorapper-ai-wire'));
        }
        $id = absint($_POST['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_upload_reel_' . $id);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $post_id = (int) ($row['post_id'] ?? 0);
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$row || !$post instanceof WP_Post) {
            wp_die(esc_html__('That Social queue row is gone.', 'technorapper-ai-wire'));
        }
        if (empty($_FILES['reel_mp4']['tmp_name']) || !is_uploaded_file((string) $_FILES['reel_mp4']['tmp_name'])) {
            wp_die(esc_html__('Choose an MP4 from your laptop first.', 'technorapper-ai-wire'));
        }
        $name = strtolower((string) ($_FILES['reel_mp4']['name'] ?? ''));
        $type = strtolower((string) ($_FILES['reel_mp4']['type'] ?? ''));
        if (substr($name, -4) !== '.mp4' && strpos($type, 'video/') !== 0) {
            wp_die(esc_html__('Upload a .mp4 file.', 'technorapper-ai-wire'));
        }
        $bytes = file_get_contents((string) $_FILES['reel_mp4']['tmp_name']);
        if (!is_string($bytes) || strlen($bytes) < 1024) {
            wp_die(esc_html__('That MP4 is empty.', 'technorapper-ai-wire'));
        }
        $copy = json_decode((string) get_post_meta($post_id, '_tr_ai_wire_social_copy', true), true);
        $copy = is_array($copy) ? $copy : array();
        $stored = $this->store_social_video($post, $bytes, $copy, (string) ($copy['reel_script'] ?? ''), $this->settings(), 'upload');
        if (is_wp_error($stored)) {
            wp_die(esc_html($stored->get_error_message()));
        }
        update_post_meta($post_id, '_tr_ai_wire_social_reel_id', (int) $stored);
        update_post_meta($post_id, '_tr_ai_wire_social_hold_reel', '1');
        update_post_meta($post_id, '_tr_ai_wire_social_mode', 'reel');
        $wpdb->update($this->social_table(), array(
            'status' => 'needs_review',
            'error_text' => $this->social_reel_hold_copy($row),
            'updated_at' => current_time('mysql', true),
        ), array('id' => $id));
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social'));
        exit;
    }

    public function download_social_reel() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to download this Reel.', 'technorapper-ai-wire'));
        }
        $id = absint($_GET['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_download_reel_' . $id);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $post_id = (int) ($row['post_id'] ?? 0);
        if (!$row || $post_id < 1) {
            wp_die(esc_html__('That Social queue row is gone.', 'technorapper-ai-wire'));
        }
        $video_id = $this->social_reel_downloadable_id($post_id);
        $path = $video_id > 0 ? get_attached_file($video_id) : '';
        if (!$path || !file_exists($path) || filesize($path) < 512) {
            wp_die(esc_html__('No video file is ready on this row yet. Click Retry building this Reel first.', 'technorapper-ai-wire'));
        }
        $filename = 'technorapper-reel-' . $post_id . '.mp4';
        nocache_headers();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    public function download_social_reel_kit() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to download this Reel.', 'technorapper-ai-wire'));
        }
        $id = absint($_GET['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_download_kit_' . $id);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $post_id = (int) ($row['post_id'] ?? 0);
        if (!$row || $post_id < 1) {
            wp_die(esc_html__('That Social queue row is gone.', 'technorapper-ai-wire'));
        }
        $kit_id = $this->social_reel_kit_id($post_id);
        $path = $kit_id > 0 ? get_attached_file($kit_id) : '';
        if (!$path || !file_exists($path) || filesize($path) < 64) {
            wp_die(esc_html__('No Reel zip is ready on this row yet. Click Prepare laptop kit once the voiceover is saved.', 'technorapper-ai-wire'));
        }
        $filename = 'technorapper-reel-' . $post_id . '.zip';
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    private function social_reel_downloadable_id($post_id) {
        return $this->social_reel_preview_id($post_id);
    }

    private function social_reel_download_url($row) {
        $id = (int) ($row['id'] ?? 0);
        $post_id = (int) ($row['post_id'] ?? 0);
        if ($id < 1 || $post_id < 1 || $this->social_reel_downloadable_id($post_id) < 1) {
            return '';
        }
        return wp_nonce_url(
            admin_url('admin-post.php?action=tr_ai_wire_download_reel&item_id=' . $id),
            'tr_ai_wire_download_reel_' . $id
        );
    }

    private function social_reel_kit_download_url($row) {
        $id = (int) ($row['id'] ?? 0);
        $post_id = (int) ($row['post_id'] ?? 0);
        if ($id < 1 || $post_id < 1 || $this->social_reel_kit_id($post_id) < 1) {
            return '';
        }
        return wp_nonce_url(
            admin_url('admin-post.php?action=tr_ai_wire_download_kit&item_id=' . $id),
            'tr_ai_wire_download_kit_' . $id
        );
    }

    private function render_social_reel_preview_video($row, $post) {
        if (!$post instanceof WP_Post || !in_array((string) ($row['platform'] ?? ''), array('instagram', 'youtube'), true) || !$this->social_wants_reel(null, $post->ID)) {
            return;
        }
        $reel_id = $this->social_reel_preview_id($post->ID);
        $partial_kind = '';
        $partial_id = 0;
        if ($reel_id < 1) {
            // Prefer B-roll over the talking-host take. The host clip is a full
            // lip-sync readout of the script — an ingredient, not the story edit.
            $partial_id = $this->social_motion_id($post->ID);
            if ($partial_id > 0) {
                $partial_kind = 'broll';
            } else {
                $partial_id = $this->social_host_id($post->ID);
                if ($partial_id > 0) {
                    $partial_kind = 'host';
                }
            }
        }
        $video_id = $reel_id > 0 ? $reel_id : $partial_id;
        $url = $video_id > 0 ? wp_get_attachment_url($video_id) : '';
        if (!$url) {
            return;
        }
        echo '<video class="tr-ai-wire-social-preview-video" controls playsinline preload="metadata" src="' . esc_url($url) . '"></video>';
        if ($reel_id > 0) {
            $download = $this->social_reel_download_url($row);
            if ($download !== '') {
                echo '<p class="tr-ai-wire-reel-downloads"><a class="button" href="' . esc_url($download) . '">Download video</a></p>';
            }
            return;
        }
        if ($partial_kind === 'host') {
            echo '<p class="description"><strong>Talking-host take only</strong> — this is the full lip-sync readout used as an ingredient. It is not the finished Reel. After assembly you get a short host hook, then lesson cards and the sting.</p>';
            return;
        }
        if ($partial_kind === 'broll') {
            echo '<p class="description"><strong>B-roll accent only</strong> (a few seconds, silent). This is not the Reel. The finished Reel is lesson cards carrying the voice, with this clip shown at most twice for about 2 seconds.</p>';
            return;
        }
        echo '<p class="description">Preview of one finished piece only. The finished Reel is still being assembled, then Download video and Post appear here.</p>';
    }

    private function render_social_reel_downloads($row, $post) {
        if (!$post instanceof WP_Post || !in_array((string) ($row['platform'] ?? ''), array('instagram', 'youtube'), true) || !$this->social_wants_reel(null, $post->ID)) {
            return;
        }
        $zip = $this->social_reel_kit_download_url($row);
        if ($this->social_reel_preview_id($post->ID) > 0) {
            if ($zip !== '') {
                echo '<p class="tr-ai-wire-reel-downloads"><a class="button button-primary" href="' . esc_url($zip) . '">Download reel zip</a></p>';
                echo '<p class="description">Laptop kit (.zip): unzip, run make-reel.bat or bash make-reel.sh, then upload reel.mp4.</p>';
            }
            return;
        }
        if ($zip !== '') {
            echo '<p style="margin:10px 0;"><a class="button button-primary" href="' . esc_url($zip) . '">Download reel zip</a></p>';
            echo '<p class="description">Laptop kit (.zip). Unzip on your computer, run <code>make-reel.bat</code> (Windows) or <code>bash make-reel.sh</code> (Mac), then upload <code>reel.mp4</code> below.</p>';
        }
        $parts = array(
            'talking host clip' => $this->social_host_id($post->ID),
            'B-roll accent (silent)' => $this->social_motion_id($post->ID),
            'voice MP3' => $this->social_voice_id($post->ID),
        );
        $links = array();
        foreach ($parts as $label => $attachment_id) {
            $url = $attachment_id > 0 ? wp_get_attachment_url((int) $attachment_id) : '';
            if ($url) {
                $links[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a>';
            }
        }
        if ($links) {
            echo '<p class="description">Saved pieces: ' . implode(' · ', $links) . '</p>';
        }
        $id = (int) ($row['id'] ?? 0);
        $status = sanitize_key((string) ($row['status'] ?? ''));
        if (
            $zip === ''
            && $id > 0
            && $this->social_voice_id($post->ID) > 0
            && in_array($status, array('queued', 'processing', 'needs_review'), true)
            && $this->social_row_holds_reel_for_review($row)
            && $status !== 'processing'
        ) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0;">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_pack_reel_kit">';
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
            wp_nonce_field('tr_ai_wire_pack_reel_kit_' . $id);
            echo '<button class="button button-secondary">Prepare laptop kit (.zip)</button>';
            echo '<p class="description">Packs voice + lesson-card stills so you can finish the Reel on your computer when Fal ffmpeg-api is blocked.</p>';
            echo '</form>';
        }
    }

    private function social_reel_panel_applies($row, $post) {
        return $post instanceof WP_Post
            && in_array((string) ($row['platform'] ?? ''), array('instagram', 'youtube'), true)
            && in_array(sanitize_key((string) ($row['status'] ?? '')), array('queued', 'processing', 'needs_review'), true)
            && $this->social_row_holds_reel_for_review($row)
            && $this->social_reel_preview_id($post->ID) < 1;
    }

    private function social_reel_row_is_live($row) {
        return in_array(sanitize_key((string) ($row['status'] ?? '')), array('queued', 'processing'), true);
    }

    private function render_social_reel_build_panel($row, $post) {
        if (!$this->social_reel_panel_applies($row, $post)) {
            return;
        }
        echo '<div class="tr-ai-wire-reel-build' . ($this->social_reel_row_is_live($row) ? ' is-live' : '') . '" data-row="' . esc_attr((string) (int) $row['id']) . '" data-live="' . ($this->social_reel_row_is_live($row) ? '1' : '0') . '">';
        echo '<div class="tr-ai-wire-reel-build-body">';
        $this->render_social_reel_build_body($row, $post);
        echo '</div></div>';
    }

    private function render_social_reel_build_body($row, $post) {
        $id = (int) ($row['id'] ?? 0);
        $live = $this->social_reel_row_is_live($row);
        $status = sanitize_key((string) ($row['status'] ?? ''));
        $steps = $this->social_reel_build_steps($post->ID);
        $progress = $this->social_reel_progress($post->ID);
        $plan = $this->social_reel_plan();
        if ($live) {
            echo '<p class="tr-ai-wire-reel-build-title"><strong>Building your Reel.</strong> <span class="tr-ai-wire-reel-build-pulse" aria-hidden="true"></span> You can leave this page open or come back later. It keeps going and nothing is posted until you click Post.</p>';
        } else {
            echo '<p class="tr-ai-wire-reel-build-title"><strong>The Reel build stopped.</strong> Nothing was posted. Finished steps are saved and are not paid for again.</p>';
            $this->render_social_failure($row, $post, (string) ($row['error_text'] ?? ''));
        }
        echo '<ol class="tr-ai-wire-reel-steps">';
        foreach ($steps as $step) {
            $state = (string) $step['state'];
            $mark = $state === 'done' ? '✓' : ($state === 'active' ? '…' : '○');
            echo '<li class="is-' . esc_attr($state) . '"><span class="tr-ai-wire-reel-step-mark">' . esc_html($mark) . '</span> ' . esc_html((string) $step['label']);
            if ((string) $step['detail'] !== '') {
                echo ' <span class="description">' . esc_html((string) $step['detail']) . '</span>';
            }
            echo '</li>';
        }
        echo '</ol>';
        if ($live && !empty($progress['detail'])) {
            echo '<p class="description tr-ai-wire-reel-build-detail">' . esc_html((string) $progress['detail']) . '</p>';
        }
        if ($this->social_reel_is_roundup()) {
            echo '<p class="description">Stick-figure roundup: every on-screen scene is unique and covers the voiceover. A 3-second B-roll clip is never repeated. If a scene cannot be drawn, the build stops.</p>';
        } elseif (!empty($plan['host'])) {
            echo '<p class="description">Teach cut: a cold-open lesson card, one short host hook, then lesson cards while the voice continues. B-roll appears at most once as a short accent. Not a full talking-head readout.</p>';
        } else {
            echo '<p class="description">Teach cut: numbered lesson cards carry the voice from start to finish. The B-roll clip appears at most twice, about 2 seconds each, and is never looped. If cards cannot be made, the build stops instead of posting a looping clip.</p>';
        }
        $spent = $this->social_reel_spent_usd($post->ID);
        if ($spent > 0) {
            echo '<p class="description">Fal usage started for this Reel so far: about $' . esc_html(number_format_i18n($spent, 2)) . '. Resuming never pays again for a step that already finished.</p>';
        }
        $this->render_social_reel_debug_log($row, $post);
        echo '<div class="tr-ai-wire-reel-build-actions">';
        if ($live) {
            echo '<button type="button" class="button tr-ai-wire-reel-check">Check now</button> ';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_stop_run">';
            wp_nonce_field('tr_ai_wire_stop_run');
            echo '<button class="button">Stop and delete from queue</button>';
            echo '</form>';
            echo '<form method="post" style="display:inline-block;margin-right:8px;">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_stop_and_rerun_reel">';
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
            wp_nonce_field('tr_ai_wire_stop_and_rerun_reel');
            echo '<button class="button button-secondary">Stop and rerun Reel</button>';
            echo '</form>';
        }
        $stale = $status === 'processing' && (time() - (int) strtotime((string) ($row['updated_at'] ?? '') . ' UTC')) > 180;
        if (!$live || $stale) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_resume_reel">';
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
            wp_nonce_field('tr_ai_wire_resume_reel_' . $id);
            echo '<button class="button button-primary">Resume building this Reel</button>';
            echo '</form>';
        }
        echo '</div>';
        if (!$live) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;display:inline-block;margin-right:8px;" onsubmit="return confirm(\'Remove this Reel from the social queue? The WordPress article stays. Saved Reel media for this attempt is deleted.\');">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_delete_social_reel">';
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
            wp_nonce_field('tr_ai_wire_delete_social_reel_' . $id);
            echo '<p class="description">Remove this stopped Reel from the queue. Nothing was posted. The WordPress article stays.</p>';
            echo '<button class="button button-link-delete">Delete from queue</button>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;display:inline-block;margin-right:8px;">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_regen_reel_script">';
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
            wp_nonce_field('tr_ai_wire_regen_reel_script_' . $id);
            echo '<p class="description">Write a fresh teach script, then rebuild voice + video. Older Reel files for this row are deleted.</p>';
            echo '<button class="button">Generate script again</button>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;display:inline-block;">';
            echo '<input type="hidden" name="action" value="tr_ai_wire_retry_social_item">';
            echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
            wp_nonce_field('tr_ai_wire_retry_social_item_' . $id);
            echo '<input type="hidden" name="confirmed_not_published" value="1">';
            echo '<input type="hidden" name="regenerate_image" value="1">';
            echo '<p class="description">Throw this attempt away and build a new Reel from scratch. Deletes older Reel media and pays Fal for every step again.</p>';
            echo '<button class="button button-secondary">Start over with a new Reel</button>';
            echo '</form>';
            $this->render_social_reel_upload_form($row);
        }
    }

    private function render_social_reel_debug_log($row, $post) {
        if (!$post instanceof WP_Post) {
            return;
        }
        $entries = $this->social_debug_log_entries($post->ID);
        $count = count($entries);
        echo '<details class="tr-ai-wire-reel-debug" style="margin:10px 0;">';
        echo '<summary><strong>Debug log</strong>';
        if ($count > 0) {
            echo ' <span class="description">(' . esc_html((string) $count) . ' line' . ($count === 1 ? '' : 's') . ')</span>';
        }
        echo '</summary>';
        if ($count < 1) {
            echo '<p class="description">No detailed lines yet. Resume or rebuild to capture Fal steps, HTTP codes, and request ids.</p>';
        } else {
            echo '<pre class="tr-ai-wire-reel-debug-pre" style="max-height:220px;overflow:auto;white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:8px;font-size:12px;">';
            $tail = array_slice($entries, -40);
            foreach ($tail as $entry) {
                $at = (int) ($entry['at'] ?? 0);
                $stamp = $at > 0 ? gmdate('H:i:s', $at) : '--:--:--';
                echo esc_html('[' . $stamp . ' UTC] ' . (string) ($entry['msg'] ?? '')) . "\n";
                foreach ((array) ($entry['ctx'] ?? array()) as $key => $value) {
                    echo esc_html('  ' . (string) $key . '=' . (string) $value) . "\n";
                }
            }
            echo '</pre>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px;">';
        echo '<input type="hidden" name="action" value="tr_ai_wire_download_reel_log">';
        echo '<input type="hidden" name="item_id" value="' . esc_attr((string) (int) ($row['id'] ?? 0)) . '">';
        wp_nonce_field('tr_ai_wire_download_reel_log_' . (int) ($row['id'] ?? 0));
        echo '<button class="button button-secondary">Download detail log (.txt)</button>';
        echo '</form>';
        echo '</details>';
    }

    public function download_social_reel_log() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to download this log.', 'technorapper-ai-wire'));
        }
        $id = absint($_POST['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_download_reel_log_' . $id);
        global $wpdb;
        $row = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A) : null;
        $post_id = (int) ($row['post_id'] ?? 0);
        if ($post_id < 1) {
            wp_die(esc_html__('That social row was not found.', 'technorapper-ai-wire'));
        }
        $text = $this->social_debug_log_text($post_id, $row);
        $name = 'tr-ai-wire-reel-' . $post_id . '-' . $id . '.txt';
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) strlen($text));
        echo $text;
        exit;
    }

    /**
     * Drop one stopped/held Reel from the social queue. WordPress posts stay.
     * Sibling unfinished platform rows for the same post are removed too.
     */
    private function delete_social_reel_queue_item($id) {
        $id = (int) $id;
        global $wpdb;
        $row = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A) : null;
        if (!$row || !$this->social_row_holds_reel_for_review($row)) {
            return false;
        }
        $status = sanitize_key((string) ($row['status'] ?? ''));
        if (!in_array($status, array('queued', 'processing', 'needs_review'), true)) {
            return false;
        }
        if ($this->social_reel_preview_id((int) $row['post_id']) > 0 && $status === 'needs_review') {
            // Ready-to-Post Reels stay until the admin posts or starts over.
            return false;
        }
        $post_id = (int) ($row['post_id'] ?? 0);
        $table = $wpdb->prefix . 'tr_ai_wire_social';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d AND status IN ('queued', 'processing', 'needs_review')",
            $post_id
        ));
        $deleted = 0;
        foreach ((array) $ids as $row_id) {
            if ($wpdb->delete($table, array('id' => (int) $row_id), array('%d'))) {
                $deleted++;
            }
        }
        if ($post_id > 0) {
            delete_post_meta($post_id, '_tr_ai_wire_social_hold_reel');
            delete_post_meta($post_id, '_tr_ai_wire_social_force_pack');
            if ((string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true) === 'reel') {
                $this->purge_social_reel_assets($post_id, array('keep_broll' => false, 'regen_script' => true));
                delete_post_meta($post_id, '_tr_ai_wire_social_mode');
            }
        }
        return $deleted > 0;
    }

    public function delete_social_reel_from_queue() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to delete a Reel from the queue.', 'technorapper-ai-wire'));
        }
        $id = absint($_POST['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_delete_social_reel_' . $id);
        $this->delete_social_reel_queue_item($id);
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social&reel_deleted=1'));
        exit;
    }

    public function regenerate_social_reel_script() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to regenerate a Reel script.', 'technorapper-ai-wire'));
        }
        $id = absint($_POST['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_regen_reel_script_' . $id);
        global $wpdb;
        $row = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A) : null;
        $post_id = (int) ($row['post_id'] ?? 0);
        if (!$row || $post_id < 1 || !$this->social_row_holds_reel_for_review($row)) {
            wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social'));
            exit;
        }
        $this->purge_social_reel_assets($post_id, array('keep_broll' => true, 'regen_script' => true));
        update_post_meta($post_id, '_tr_ai_wire_social_hold_reel', '1');
        update_post_meta($post_id, '_tr_ai_wire_social_mode', 'reel');
        $now = current_time('mysql', true);
        $wpdb->update($this->social_table(), array(
            'status' => 'queued',
            'error_text' => null,
            'updated_at' => $now,
        ), array('id' => $id));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tr_ai_wire_social SET status = 'queued', error_text = NULL, updated_at = %s WHERE post_id = %d AND status IN ('needs_review', 'processing') AND (error_text IS NULL OR error_text NOT LIKE %s)",
            $now,
            $post_id,
            'Held:%'
        ));
        $this->kick_social_queue(2, true);
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social&reel_script_regen=1'));
        exit;
    }

    private function social_building_reel_rows() {
        global $wpdb;
        return (array) $wpdb->get_results(
            "SELECT s.* FROM {$wpdb->prefix}tr_ai_wire_social s
             INNER JOIN {$wpdb->postmeta} h ON h.post_id = s.post_id AND h.meta_key = '_tr_ai_wire_social_hold_reel' AND h.meta_value = '1'
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = s.post_id AND m.meta_key = '_tr_ai_wire_social_mode' AND m.meta_value = 'reel'
             WHERE s.status IN ('queued', 'processing')
             ORDER BY s.id ASC",
            ARRAY_A
        );
    }

    private function social_reel_error_is_transient($error, $post_id) {
        $post_id = (int) $post_id;
        if (!is_wp_error($error) || $post_id < 1) {
            return false;
        }
        if (!in_array($error->get_error_code(), array('tr_ai_wire_reel_tts', 'tr_ai_wire_reel_video'), true)) {
            return false;
        }
        if (!preg_match('/cURL error|timed out|could not reach|HTTP 5\d\d|temporar|overloaded|rate limit|try again/i', $error->get_error_message())) {
            return false;
        }
        $tries = (int) get_post_meta($post_id, '_tr_ai_wire_reel_auto_retries', true);
        if ($tries >= 3) {
            return false;
        }
        update_post_meta($post_id, '_tr_ai_wire_reel_auto_retries', $tries + 1);
        return true;
    }

    private function social_reel_reset_pending_errors($post_id) {
        foreach (array('_tr_ai_wire_fal_host_request', '_tr_ai_wire_fal_request', '_tr_ai_wire_fal_compose_request', '_tr_ai_wire_fal_trim_request', '_tr_ai_wire_fal_hold') as $meta_key) {
            $pending = $this->social_fal_pending_get($post_id, $meta_key);
            if ($pending && !empty($pending['errors'])) {
                $pending['errors'] = 0;
                $this->social_fal_pending_save($post_id, $meta_key, $pending);
            }
        }
        delete_post_meta((int) $post_id, '_tr_ai_wire_reel_auto_retries');
    }

    private function resume_social_reel_build($id) {
        $id = (int) $id;
        global $wpdb;
        $row = $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A) : null;
        if (!$row || !$this->social_row_holds_reel_for_review($row)) {
            return false;
        }
        $status = sanitize_key((string) $row['status']);
        if (!in_array($status, array('queued', 'processing', 'needs_review'), true)) {
            return false;
        }
        $post_id = (int) $row['post_id'];
        if ($this->social_reel_preview_id($post_id) > 0 && $status === 'needs_review') {
            return false;
        }
        $this->social_reel_reset_pending_errors($post_id);
        $now = current_time('mysql', true);
        $wpdb->update($this->social_table(), array('status' => 'queued', 'error_text' => null, 'updated_at' => $now), array('id' => $id, 'status' => $status));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}tr_ai_wire_social SET status = 'queued', error_text = NULL, updated_at = %s WHERE post_id = %d AND status = 'needs_review' AND (error_text IS NULL OR error_text NOT LIKE %s)",
            $now,
            $post_id,
            'Held:%'
        ));
        $this->kick_social_queue(2, true);
        return true;
    }

    public function resume_social_reel() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to resume a Reel.', 'technorapper-ai-wire'));
        }
        $id = absint($_POST['item_id'] ?? 0);
        check_admin_referer('tr_ai_wire_resume_reel_' . $id);
        $this->resume_social_reel_build($id);
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social&reel_resumed=1'));
        exit;
    }

    public function toggle_reel_dev_cheap() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change Reel Dev mode.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_reel_dev_cheap');
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $stored = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        $stored['social_reel_dev_cheap'] = $enabled;
        update_option(self::OPTION, $stored, false);
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social&reel_dev_cheap=' . (string) $enabled));
        exit;
    }

    public function ajax_reel_tick() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are not allowed to do that.'), 403);
        }
        check_ajax_referer('tr_ai_wire_reel_tick', 'nonce');
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(150);
        }
        $ran = false;
        if ($this->social_building_reel_rows()) {
            $ran = (bool) $this->process_social_queue();
        }
        $live = $this->social_building_reel_rows();
        $panels = array();
        foreach ($live as $row) {
            $post = get_post((int) $row['post_id']);
            if (!$this->social_reel_panel_applies($row, $post)) {
                continue;
            }
            ob_start();
            $this->render_social_reel_build_body($row, $post);
            $panels[(string) (int) $row['id']] = (string) ob_get_clean();
        }
        wp_send_json_success(array(
            'active' => count($live) > 0,
            'ran' => $ran,
            'panels' => $panels,
        ));
    }

    private function render_social_reel_watch_script() {
        if (!$this->social_building_reel_rows()) {
            return;
        }
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tr_ai_wire_reel_tick'),
        );
        ?>
        <script>
        (function () {
            var cfg = <?php echo wp_json_encode($config); ?>;
            var busy = false;
            var failures = 0;
            var timer = null;
            function schedule(ms) {
                window.clearTimeout(timer);
                timer = window.setTimeout(tick, ms);
            }
            function tick() {
                if (busy) return;
                busy = true;
                var body = new FormData();
                body.set('action', 'tr_ai_wire_reel_tick');
                body.set('nonce', cfg.nonce);
                fetch(cfg.ajaxUrl, {method: 'POST', body: body, credentials: 'same-origin'})
                    .then(function (res) { return res.json(); })
                    .then(function (payload) {
                        busy = false;
                        if (!payload || !payload.success) {
                            failures++;
                            schedule(Math.min(30000, 6000 * failures));
                            return;
                        }
                        failures = 0;
                        var panels = payload.data.panels || {};
                        Object.keys(panels).forEach(function (rowId) {
                            var holder = document.querySelector('.tr-ai-wire-reel-build[data-row="' + rowId + '"] .tr-ai-wire-reel-build-body');
                            if (holder) holder.innerHTML = panels[rowId];
                        });
                        if (!payload.data.active) {
                            window.location.reload();
                            return;
                        }
                        schedule(payload.data.ran ? 4000 : 8000);
                    })
                    .catch(function () {
                        busy = false;
                        failures++;
                        schedule(Math.min(30000, 6000 * failures));
                    });
            }
            document.addEventListener('click', function (event) {
                var button = event.target && event.target.closest ? event.target.closest('.tr-ai-wire-reel-check') : null;
                if (!button) return;
                event.preventDefault();
                button.disabled = true;
                button.textContent = 'Checking…';
                schedule(0);
            });
            schedule(1500);
        }());
        </script>
        <?php
    }

    private function render_social_reel_post_form($row) {
        $id = (int) ($row['id'] ?? 0);
        $post_id = (int) ($row['post_id'] ?? 0);
        $platform = (string) ($row['platform'] ?? '');
        $status = sanitize_key((string) ($row['status'] ?? ''));
        if ($id < 1 || $post_id < 1 || $status !== 'needs_review' || !$this->social_row_holds_reel_for_review($row)) {
            return;
        }
        if ($this->social_reel_downloadable_id($post_id) < 1) {
            return;
        }
        $platform_label = self::social_platform_label($platform);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="tr-ai-wire-reel-post">';
        echo '<input type="hidden" name="action" value="tr_ai_wire_retry_social_item">';
        echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
        wp_nonce_field('tr_ai_wire_retry_social_item_' . $id);
        echo '<label><input type="checkbox" name="confirmed_not_published" value="1" required> I reviewed this video</label>';
        if (in_array($platform, array('instagram', 'youtube'), true)) {
            echo '<button class="button button-primary">Post to ' . esc_html($platform_label) . '</button>';
        } else {
            echo '<button class="button button-primary">Post still to ' . esc_html($platform_label) . '</button>';
        }
        echo '</form>';
    }

    private function render_social_reel_upload_form($row) {
        $id = (int) ($row['id'] ?? 0);
        $post_id = (int) ($row['post_id'] ?? 0);
        if ($id < 1 || $post_id < 1 || $this->social_reel_preview_id($post_id) > 0) {
            return;
        }
        if ($this->social_motion_id($post_id) < 1 && $this->social_reel_kit_id($post_id) < 1 && $this->social_voice_id($post_id) < 1) {
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="tr_ai_wire_upload_reel">';
        echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
        wp_nonce_field('tr_ai_wire_upload_reel_' . $id);
        echo '<p><label for="tr-reel-upload-' . esc_attr((string) $id) . '"><strong>Finished MP4 from your laptop</strong></label><br>';
        echo '<input id="tr-reel-upload-' . esc_attr((string) $id) . '" name="reel_mp4" type="file" accept="video/mp4,.mp4" required></p>';
        echo '<button class="button button-primary">Upload Reel MP4</button>';
        echo '</form>';
    }

    private function render_social_retry_form($row, $image_id, $status) {
        $id = (int) ($row['id'] ?? 0);
        $post_id = (int) ($row['post_id'] ?? 0);
        $platform = (string) ($row['platform'] ?? '');
        $platform_label = self::social_platform_label($platform);
        $published = $status === 'published';
        $held_reel = !$published && $this->social_row_holds_reel_for_review($row);
        $reel_ready = $held_reel && $this->social_reel_downloadable_id($post_id) > 0;
        if ($held_reel) {
            if ($reel_ready) {
                if (in_array($platform, array('instagram', 'youtube'), true)) {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
                    echo '<input type="hidden" name="action" value="tr_ai_wire_retry_social_item">';
                    echo '<input type="hidden" name="item_id" value="' . esc_attr((string) $id) . '">';
                    wp_nonce_field('tr_ai_wire_retry_social_item_' . $id);
                    echo '<label><input type="checkbox" name="confirmed_not_published" value="1" required> Rebuild a new Reel and hold it for review</label><br>';
                    echo '<input type="hidden" name="regenerate_image" value="1">';
                    echo '<button class="button button-secondary">Generate a new Reel</button>';
                    echo '</form>';
                }
                return;
            }
            if (!in_array($platform, array('instagram', 'youtube'), true)) {
                echo '<p class="description">Waiting for the Reel on Instagram or YouTube to finish building.</p>';
            }
            return;
        }
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
            'needs_review' => 'Held for review. If this is a policy hold, edit or regenerate the copy. If it is a platform error, confirm the post is not already live, then retry the saved graphic.',
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
        if (strpos($error, 'Held: Watch this Reel') === 0) {
            return $error;
        }
        if (stripos($error, 'need ffmpeg') !== false || stripos($error, 'has no ffmpeg') !== false) {
            return 'This WordPress host has no ffmpeg, so the video is assembled at Fal instead. Click Resume building this Reel. Download video and Post to Instagram appear on this row when the MP4 is ready.';
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

    private function social_error_is_hold($error) {
        return stripos(trim((string) $error), 'Held: Watch this Reel') === 0;
    }

    private function social_error_is_failure($error) {
        $error = trim((string) $error);
        if ($error === '') {
            return false;
        }
        return !$this->social_error_is_hold($error);
    }

    private function social_failure_stage($row, $post, $error) {
        $error = trim((string) $error);
        $row = is_array($row) ? $row : array();
        if ($post instanceof WP_Post && $this->social_reel_panel_applies($row, $post)) {
            return 'reel';
        }
        if (preg_match('/\b(Reel build|could not assemble|ffmpeg|talking host|lesson card|B-roll|Fal could not|Fal answered)\b/i', $error)) {
            return 'reel';
        }
        $image_id = $this->social_queue_image_id($row, $post instanceof WP_Post ? $post : null);
        if ($image_id > 0) {
            return 'post';
        }
        if (preg_match('/did not return a media ID|did not return a post ID|quota|access token|credits depleted|rejected the|permalink|container/i', $error)) {
            return 'post';
        }
        return 'generate';
    }

    private function social_failure_headline($row, $post, $error) {
        $platform = self::social_platform_label((string) ($row['platform'] ?? ''));
        if ($platform === '') {
            $platform = 'this platform';
        }
        $stage = $this->social_failure_stage($row, $post, $error);
        if ($stage === 'reel') {
            return 'Reel was not finished. Nothing was posted to ' . $platform . '.';
        }
        if ($stage === 'generate') {
            return 'Not generated. Nothing was posted to ' . $platform . '.';
        }
        return 'Not posted to ' . $platform . '. The graphic was generated and is saved.';
    }

    private function render_social_failure($row, $post, $error) {
        $error = $this->social_error_copy((string) $error);
        if (!$this->social_error_is_failure($error)) {
            return;
        }
        $headline = $this->social_failure_headline($row, $post, $error);
        echo '<div class="tr-ai-wire-social-error" role="alert">';
        if ($headline !== '') {
            echo '<strong>' . esc_html($headline) . '</strong>';
        }
        echo esc_html($error);
        echo '</div>';
    }

    private function social_queue_caption($row, $copy) {
        $platform = (string) ($row['platform'] ?? '');
        if ($platform === 'instagram' && !empty($copy['instagram'])) {
            return (string) $copy['instagram'];
        }
        if ($platform === 'youtube' && !empty($copy['youtube_title'])) {
            return trim((string) $copy['youtube_title'] . "\n\n" . (string) ($copy['youtube_description'] ?? ''));
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
        $building = $this->social_reel_panel_applies($row, $post);
        echo '<div class="tr-ai-wire-social-item" data-item-id="' . esc_attr((string) (int) ($row['id'] ?? 0)) . '">';
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
            $reel_id = ($post instanceof WP_Post) ? $this->social_reel_preview_id($post->ID) : 0;
            if ($reel_id > 0 && (string) ($row['platform'] ?? '') === 'instagram') {
                $label = 'Spoken Instagram Reel';
            }
            if ($reel_id > 0 && (string) ($row['platform'] ?? '') === 'youtube') {
                $label = 'Spoken YouTube Short';
            }
            echo '<span class="tr-ai-wire-social-preview-label">' . ($this->social_image_is_current($image_id) ? esc_html($label) : 'Saved graphic — retry posts this file unless you generate a new one') . '</span></a>';
        } else {
            $waiting = $building || in_array($status, array('queued', 'processing'), true);
            $empty = (!$waiting && $this->social_error_is_failure($error))
                ? 'Graphic was not generated. Nothing was posted. See the red error for why.'
                : 'Graphic not generated yet. The worker creates it before posting.';
            echo '<div class="tr-ai-wire-social-preview-empty">' . esc_html($empty) . '</div>';
        }
        $this->render_social_reel_preview_video($row, $post);
        echo '</div><div class="tr-ai-wire-social-copy">';
        $this->render_social_reel_post_form($row);
        $this->render_social_reel_build_panel($row, $post);
        // Ready-for-review Reels skip the build panel; still expose the detail log.
        if ($post instanceof WP_Post
            && $this->social_row_holds_reel_for_review($row)
            && $this->social_reel_preview_id($post->ID) > 0
            && in_array((string) ($row['platform'] ?? ''), array('instagram', 'youtube'), true)
        ) {
            $this->render_social_reel_debug_log($row, $post);
        }
        $this->render_social_reel_downloads($row, $post);
        if (!empty($copy['on_image_headline'])) {
            echo '<p class="tr-ai-wire-social-headline"><strong>' . esc_html($copy['on_image_headline']) . '</strong></p>';
        }
        if (!empty($copy['on_image_subhead'])) {
            echo '<p class="tr-ai-wire-social-subhead">' . esc_html($copy['on_image_subhead']) . '</p>';
        }
        if (!empty($copy['hook']) && (empty($copy['on_image_headline']) || $copy['hook'] !== $copy['on_image_headline'])) {
            echo '<p class="description">' . esc_html($copy['hook']) . '</p>';
        }
        if (!empty($copy['reel_script']) && in_array((string) ($row['platform'] ?? ''), array('instagram', 'youtube'), true) && $this->social_wants_reel(null, (int) ($row['post_id'] ?? 0))) {
            echo '<p class="description"><strong>Reel voiceover:</strong> ' . esc_html(wp_trim_words((string) $copy['reel_script'], 40, '…')) . '</p>';
        }
        if ((string) ($row['platform'] ?? '') === 'youtube' && !empty($copy['youtube_title'])) {
            echo '<p class="description"><strong>YouTube title:</strong> ' . esc_html((string) $copy['youtube_title']) . '</p>';
            if (!empty($copy['youtube_tags'])) {
                echo '<p class="description"><strong>YouTube tags:</strong> ' . esc_html(implode(', ', (array) $copy['youtube_tags'])) . '</p>';
            }
        }
        if ($caption !== '') {
            echo '<p class="tr-ai-wire-social-caption">' . esc_html(wp_trim_words($caption, 36, '…')) . '</p>';
        }
        $status_copy = $this->social_status_copy($status);
        $pack_format = ($status === 'queued') ? $this->social_row_explicit_pack($row) : '';
        if ($pack_format !== '') {
            $status_copy = 'Queued in a ' . $pack_format . '. The worker generates these graphics and posts them together. This does not wait out the gap between single Instagram posts.';
        }
        if ($this->social_row_holds_reel_for_review($row)) {
            $status_copy = 'Held for review. Watch the video, tick the box, then click Post. It is not live yet.';
        }
        if ($building) {
            $status_copy = '';
            $error = '';
        } elseif ($this->social_error_is_failure($error)) {
            $status_copy = '';
        }
        if ($status_copy !== '') {
            echo '<p class="description">' . esc_html($status_copy) . '</p>';
        }
        if (!empty($row['remote_url'])) {
            echo '<p><a href="' . esc_url($row['remote_url']) . '" target="_blank" rel="noopener">View live post</a></p>';
        } elseif (!empty($row['remote_id'])) {
            echo '<p>Published media ID: ' . esc_html($row['remote_id']) . '</p>';
        }
        if ($error !== '') {
            $this->render_social_failure($row, $post, (string) ($row['error_text'] ?? ''));
        }
        if (($status === 'needs_review' || $status === 'published') && !$building) {
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
        $stuck_pack = $this->maybe_start_stuck_pack_job();
        $image_usage = (float) get_option('tr_ai_wire_social_image_usage_' . gmdate('Ym'), 0);
        $video_usage = (float) get_option('tr_ai_wire_social_video_usage_' . gmdate('Ym'), 0);
        $x_posting = !empty($settings['social_x_enabled']) && TechnoRapper_AI_Wire::x_credentials_ready();
        $ig_posting = !empty($settings['social_instagram_enabled']) && TechnoRapper_AI_Wire::instagram_credentials_ready();
        $yt_posting = !empty($settings['social_youtube_enabled']) && TechnoRapper_AI_Wire::youtube_credentials_ready();
        ?>
        <div class="wrap">
            <h1>Social queue</h1>
            <?php if ($stuck_pack !== '') : ?><div class="notice notice-info"><p><?php echo esc_html('A ' . $stuck_pack . ' is queued and the graphics are not created yet. The worker is generating them and posting it now. Stay on this page so the progress bar can finish. ' . $this->instagram_still_spacing_copy()); ?></p></div><?php endif; ?>
            <?php if (!empty($_GET['job_stopped'])) : ?><div class="notice notice-warning is-dismissible"><p>Background job stopped and the stuck Reel/social rows were removed from the queue. WordPress posts stay. Open <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-now')); ?>">Now in queue</a> or Generate Reel now.</p></div><?php endif; ?>
            <?php if (!empty($_GET['reel_resumed'])) : ?><div class="notice notice-info is-dismissible"><p>Resuming the Reel build. Finished steps are kept, so nothing already rendered is paid for again. This page updates itself.</p></div><?php endif; ?>
            <?php if (!empty($_GET['reel_kit'])) : ?><div class="notice notice-success is-dismissible"><p>Laptop kit packed. Click <strong>Download laptop kit (.zip)</strong> on the Reel row, run make-reel on your computer, then Upload Reel MP4.</p></div><?php endif; ?>
            <?php if (!empty($_GET['reel_deleted'])) : ?><div class="notice notice-warning is-dismissible"><p>That Reel was removed from the social queue. The WordPress article stays. Generate Reel now if you want a new one.</p></div><?php endif; ?>
            <?php if (!empty($_GET['reel_script_regen'])) : ?><div class="notice notice-info is-dismissible"><p>Generating a new teach script. Older Reel video/voice files for that row were deleted. This page updates itself while it rebuilds.</p></div><?php endif; ?>
            <?php if (!empty($_GET['reel_dev_cheap'])) : ?><div class="notice notice-warning is-dismissible"><p><?php echo ((string) $_GET['reel_dev_cheap'] === '1') ? esc_html('Dev cheap test is ON. New Reels use no talking face + Veo Lite 4s hooks. Your production model picks stay saved.') : esc_html('Dev cheap test is OFF. New Reels use your production model picks again.'); ?></p></div><?php endif; ?>
            <?php if ($this->social_reel_dev_cheap_on($settings)) : ?><div class="notice notice-warning"><p><strong>Dev cheap test is on.</strong> <?php echo esc_html($this->social_reel_dev_cheap_summary($settings)); ?> Turn it off before real publishing spend.</p></div><?php endif; ?>
            <?php if (!empty($_GET['site_context'])) : ?><div class="notice notice-info is-dismissible"><p>We are understanding your site so everything we do for social stays in your context.</p></div><?php endif; ?>
            <?php if (isset($_GET['social_ran'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo ((int) $_GET['social_ran'] > 0) ? 'Processed queued social posts. Refresh in a minute if a graphic is still generating.' : 'No queued social post could be processed now. If items are still waiting, check the daily limit, image budget, and WP-Cron.'; ?></p></div><?php endif; ?>
            <?php if (isset($_GET['social_pack'])) : ?><div class="notice notice-info is-dismissible"><p><?php
            $pack_kind = $this->social_manual_pack_kind($_GET['social_pack'] ?? '');
            echo $pack_kind === 'reel'
                ? esc_html('Building a Reel for review. Stay on this page — then watch it below and click Post. It is not posted yet.')
                : esc_html('Generating a ' . $pack_kind . ' now. Stay on this page — the progress bar tracks the graphics and the post.');
            ?></p></div><?php endif; ?>
            <?php if (isset($_GET['social_backfill'])) : ?><div class="notice notice-success is-dismissible"><p><?php
            $backfill = (int) $_GET['social_backfill'];
            $backfill_platform = sanitize_key((string) ($_GET['social_backfill_platform'] ?? 'x'));
            $label = self::social_platform_label($backfill_platform);
            echo $backfill > 0
                ? 'Queued ' . esc_html((string) $backfill) . ' existing article' . ($backfill === 1 ? '' : 's') . ' for ' . esc_html($label) . ' using saved graphics. No new images will be generated.'
                : 'No extra ' . esc_html($label) . ' rows were needed — every queued article already has that platform.';
            ?></p></div><?php endif; ?>
            <?php $this->render_site_context_card('tr-ai-wire-social'); ?>
            <?php
            $published_today = $this->social_published_today();
            $daily_limit = $this->social_article_daily_limit($settings);
            $post_limit = (int) ($settings['social_daily_limit'] ?? 10);
            $failed_x = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE platform = 'x' AND status = 'needs_review'");
            $failed_ig = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE platform = 'instagram' AND status = 'needs_review'");
            $failed_yt = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE platform = 'youtube' AND status = 'needs_review'");
            $finishable_now = $this->social_finishable_queued_count();
            ?>
            <div class="tr-ai-wire-status">
                <span class="tr-ai-wire-pill <?php echo $x_posting ? 'is-on' : 'is-off'; ?>">X <?php echo $x_posting ? 'posting' : 'off'; ?></span>
                <span class="tr-ai-wire-pill <?php echo $ig_posting ? 'is-on' : 'is-off'; ?>">Instagram <?php echo $ig_posting ? 'posting' : 'off'; ?></span>
                <span class="tr-ai-wire-pill <?php echo $yt_posting ? 'is-on' : 'is-off'; ?>">YouTube <?php echo $yt_posting ? 'posting' : 'off'; ?></span>
                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-social-settings')); ?>">Open Social settings</a>
            </div>
            <p>X posting is <?php echo $x_posting ? '<strong>on</strong>' : '<strong>off</strong>'; ?> · Instagram posting is <?php echo $ig_posting ? '<strong>on</strong>' : '<strong>off</strong>'; ?> · YouTube posting is <?php echo $yt_posting ? '<strong>on</strong>' : '<strong>off</strong>'; ?>. Change that on <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-social-settings')); ?>">Social settings</a>. Planned social posts today: <strong><?php echo esc_html((string) $post_limit); ?></strong>, split in the mix below. Website articles used for that mix: <?php echo esc_html((string) $published_today); ?> / <?php echo esc_html((string) $daily_limit); ?>. A carousel or collage spends several articles on one social post. A successful Instagram or YouTube post does not spend an extra slot for X on the same article.</p>
            <?php if ($published_today >= $daily_limit && ($failed_x > 0 || $failed_ig > 0 || $failed_yt > 0 || $finishable_now > 0)) : ?>
            <div class="notice notice-warning"><p><?php
            $parts = array();
            if ($failed_x > 0) {
                $parts[] = (string) $failed_x . ' failed X post' . ($failed_x === 1 ? '' : 's');
            }
            if ($failed_ig > 0) {
                $parts[] = (string) $failed_ig . ' failed Instagram post' . ($failed_ig === 1 ? '' : 's');
            }
            if ($failed_yt > 0) {
                $parts[] = (string) $failed_yt . ' failed YouTube post' . ($failed_yt === 1 ? '' : 's');
            }
            if ($finishable_now > 0) {
                $parts[] = (string) $finishable_now . ' queued platform post' . ($finishable_now === 1 ? '' : 's') . ' still finishing today';
            }
            echo 'The article cap is full, but ' . esc_html(implode(' and ', $parts)) . ' can still run. Retry a failed row or Process now — you do not need to raise the daily limit.';
            ?></p></div>
            <?php endif; ?>
            <p>Social image budget this month: $<?php echo esc_html(number_format_i18n($image_usage, 3)); ?> / $<?php echo esc_html(number_format_i18n((float) ($settings['social_image_budget_usd'] ?? 0), 2)); ?> estimated. Reel video budget: $<?php echo esc_html(number_format_i18n($video_usage, 3)); ?> / $<?php echo esc_html(number_format_i18n((float) ($settings['social_video_budget_usd'] ?? 0), 2)); ?> estimated.</p>
            <?php
            $wanted = $this->social_slide_count($settings);
            $mix = $this->social_mix_today();
            $limits = $this->social_daily_mix_limits($settings);
            $weekly_cap = min(14, max(0, absint($settings['social_reel_weekly_limit'] ?? 0)));
            $week_used = $this->social_reel_week_count();
            ?>
            <div class="tr-ai-wire-statbar">
                <p class="tr-ai-wire-stat"><span class="description">Image budget</span><strong class="tr-ai-wire-cost">$<?php echo esc_html(number_format_i18n($image_usage, 2)); ?> / $<?php echo esc_html(number_format_i18n((float) ($settings['social_image_budget_usd'] ?? 0), 2)); ?></strong></p>
                <p class="tr-ai-wire-stat"><span class="description">Reel video budget</span><strong class="tr-ai-wire-cost">$<?php echo esc_html(number_format_i18n($video_usage, 2)); ?> / $<?php echo esc_html(number_format_i18n((float) ($settings['social_video_budget_usd'] ?? 0), 2)); ?></strong></p>
                <p class="tr-ai-wire-stat"><span class="description">Posts today</span><strong><?php echo esc_html((string) $published_today); ?> / <?php echo esc_html((string) $post_limit); ?></strong></p>
                <?php if ($weekly_cap > 0) : ?>
                <p class="tr-ai-wire-stat"><span class="description">Reels this week</span><strong><?php echo esc_html((string) $week_used); ?> / <?php echo esc_html((string) $weekly_cap); ?></strong></p>
                <?php endif; ?>
            </div>
            <div class="tr-ai-wire-grid">
                <?php foreach (array('reel' => 'Reels', 'carousel' => 'Carousels', 'collage' => 'Collages', 'single' => 'Singles') as $mix_key => $mix_label) :
                    $mix_used = (int) ($mix[$mix_key] ?? 0);
                    $mix_limit = (int) ($limits[$mix_key] ?? 0);
                    $mix_pct = $mix_limit > 0 ? min(100, (int) round(100 * $mix_used / $mix_limit)) : 0;
                    ?>
                <div class="tr-ai-wire-tile tr-ai-wire-meter">
                    <p class="description"><?php echo esc_html($mix_label); ?></p>
                    <strong><?php echo esc_html((string) $mix_used); ?> / <?php echo esc_html((string) $mix_limit); ?></strong>
                    <div class="tr-ai-wire-meter-bar" aria-hidden="true"><span style="width:<?php echo esc_attr((string) $mix_pct); ?>%;"></span></div>
                </div>
                <?php endforeach; ?>
            </div>
            <p>Today’s mix: <strong><?php echo esc_html((string) ($mix['reel'] ?? 0)); ?> / <?php echo esc_html((string) ($limits['reel'] ?? 0)); ?> Reels</strong>, <strong><?php echo esc_html((string) $mix['carousel']); ?> / <?php echo esc_html((string) $limits['carousel']); ?> carousels</strong>, <strong><?php echo esc_html((string) $mix['collage']); ?> / <?php echo esc_html((string) $limits['collage']); ?> collages</strong>, <strong><?php echo esc_html((string) $mix['single']); ?> / <?php echo esc_html((string) $limits['single']); ?> single stories</strong><?php
            $holding = count($this->social_batch_ids());
            if ($holding > 0 && $wanted > 1 && $holding < $wanted) {
                echo '. Holding ' . esc_html((string) $holding) . ' article' . ($holding === 1 ? '' : 's') . ' for the next pack of ' . esc_html((string) $wanted);
            }
            ?>. Change the counts under <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-social-settings')); ?>">Social settings</a>. Automatic carousels and collages wait until 4, 5, or 6 articles are held and a daily slot is left. Reels fill first, then carousels, then collages. Collage posts per day defaults to 0, so a collage does not start on its own. One leftover article stays held for the next pack.</p>
            <div class="tr-ai-wire-panel" style="border-left:4px solid #d63638;margin:12px 0;padding:8px 12px;">
                <p style="margin:0 0 8px;"><strong>Dev — cheap test</strong>
                    <?php if ($this->social_reel_dev_cheap_on($settings)) : ?>
                    <span class="description"> · ON now · <?php echo esc_html('~$' . number_format((float) $this->social_reel_total_estimate_usd($settings), 2)); ?> / Reel</span>
                    <?php else : ?>
                    <span class="description"> · off · production config active</span>
                    <?php endif; ?>
                </p>
                <div class="tr-ai-wire-actions" style="margin:0;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                    <input type="hidden" name="action" value="tr_ai_wire_reel_dev_cheap">
                    <input type="hidden" name="enabled" value="1">
                    <?php wp_nonce_field('tr_ai_wire_reel_dev_cheap'); ?>
                    <button class="button"><?php echo $this->social_reel_dev_cheap_on($settings) ? 'Keep Dev cheap on' : 'Turn on Dev cheap test'; ?></button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                    <input type="hidden" name="action" value="tr_ai_wire_reel_dev_cheap">
                    <input type="hidden" name="enabled" value="0">
                    <?php wp_nonce_field('tr_ai_wire_reel_dev_cheap'); ?>
                    <button class="button button-primary"><?php echo $this->social_reel_dev_cheap_on($settings) ? 'Switch to production config' : 'Production config already on'; ?></button>
                </form>
                </div>
                <p class="description" style="margin:8px 0 0;">Cheap test forces no talking face + story hooks + Veo Lite 4s. Production picks (including host / Pro / Fast) stay saved and apply when you switch back.</p>
            </div>
            <?php
            $bg_job = get_option(self::JOB_OPTION, array());
            $bg_busy = is_array($bg_job) && in_array((string) ($bg_job['status'] ?? ''), array('queued', 'running'), true);
            $bg_kind = is_array($bg_job) ? (string) ($bg_job['kind'] ?? '') : '';
            $bg_reelish = in_array($bg_kind, array('social_pack', 'social', 'social_retry'), true);
            $live_reel = $this->social_building_reel_rows();
            $live_reel_id = !empty($live_reel[0]['id']) ? (int) $live_reel[0]['id'] : 0;
            if ($bg_busy) :
                ?>
            <div class="notice notice-warning" style="margin:12px 0;">
                <p><strong>A background job is running<?php echo $bg_reelish ? ' (Reel / social)' : ''; ?>.</strong>
                    <?php echo esc_html((string) ($bg_job['message'] ?? 'Working…')); ?>
                    If it has been stuck for a long time, stop it here and rerun.</p>
                <p class="tr-ai-wire-actions" style="margin:8px 0 0;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                        <input type="hidden" name="action" value="tr_ai_wire_stop_run">
                        <?php wp_nonce_field('tr_ai_wire_stop_run'); ?>
                        <?php submit_button('Stop and delete from queue', 'delete', 'submit', false); ?>
                    </form>
                    <form method="post" style="display:inline-block;">
                        <input type="hidden" name="action" value="tr_ai_wire_stop_and_rerun_reel">
                        <input type="hidden" name="item_id" value="<?php echo esc_attr((string) $live_reel_id); ?>">
                        <?php wp_nonce_field('tr_ai_wire_stop_and_rerun_reel'); ?>
                        <?php submit_button('Stop and rerun Reel', 'primary', 'submit', false); ?>
                    </form>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-now')); ?>">Now in queue</a>
                </p>
            </div>
            <?php endif; ?>
            <div class="tr-ai-wire-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tr_ai_wire_generate_social_pack">
                <input type="hidden" name="pack_kind" value="reel">
                <?php wp_nonce_field('tr_ai_wire_generate_social_pack'); ?>
                <?php submit_button('Generate Reel now', 'primary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tr_ai_wire_generate_social_pack">
                <input type="hidden" name="pack_kind" value="carousel">
                <?php wp_nonce_field('tr_ai_wire_generate_social_pack'); ?>
                <?php submit_button('Generate carousel now', 'primary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tr_ai_wire_generate_social_pack">
                <input type="hidden" name="pack_kind" value="collage">
                <?php wp_nonce_field('tr_ai_wire_generate_social_pack'); ?>
                <?php submit_button('Generate collage now', 'primary', 'submit', false); ?>
            </form>
            </div>
            <p class="description"><strong>Generate Reel now</strong> builds one spoken video and holds it on this page. Watch it, then click Post — it does not go live until you do. Best/Budget set Reel posts per day to at least 1, so those Reels auto-post when the MP4 is ready. Carousel and collage skip the daily mix and that wait. Each of those packs at least 2 stories that have not already been posted. Instagram gets a swipeable carousel or one grid image. X attaches up to 4 of those images on one post. YouTube keeps receiving Reels only. A carousel or collage posts on the next worker run. <?php echo esc_html($this->instagram_still_spacing_copy()); ?></p>
            <?php
            $queued_now = $this->social_queued_count();
            $next_run = wp_next_scheduled(self::SOCIAL_CRON_HOOK);
            if ($queued_now > 0 && $next_run && $next_run > time() + 600) {
                echo '<div class="notice notice-warning"><p>Queued posts are waiting until ' . esc_html(gmdate('Y-m-d H:i', $next_run)) . ' UTC — usually because the daily cap was hit earlier. Raising the daily limit does not start them by itself. Process the queue now.</p></div>';
            }
            ?>
            <div class="tr-ai-wire-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tr_ai_wire_run_social_queue">
                <?php wp_nonce_field('tr_ai_wire_run_social_queue'); ?>
                <?php submit_button('Process queued social posts now', 'secondary', 'submit', false); ?>
            </form>
            </div>
            <p class="description">Runs in the background and keeps this page open. A progress bar tracks it. Saved graphics are reused. Use this after refreshing an X token, after raising the daily limit, or to ship a leftover batch.</p>
            <?php
            foreach (array('x' => 'X', 'instagram' => 'Instagram', 'youtube' => 'YouTube') as $platform => $label) {
                if (!in_array($platform, self::connected_social_platforms(), true)) {
                    continue;
                }
                $missing = $this->missing_platform_post_count($platform);
                if ($missing < 1) {
                    continue;
                }
                ?>
            <div class="tr-ai-wire-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tr_ai_wire_queue_missing_platform">
                <input type="hidden" name="platform" value="<?php echo esc_attr($platform); ?>">
                <?php wp_nonce_field('tr_ai_wire_queue_missing_platform'); ?>
                <?php submit_button($platform === 'youtube' ? 'Queue spoken Shorts for YouTube' : ('Queue saved graphics for ' . $label), 'secondary', 'submit', false); ?>
            </form>
            </div>
            <p class="description"><?php echo esc_html((string) $missing); ?> article<?php echo $missing === 1 ? '' : 's'; ?> already went to another network. <?php echo $platform === 'youtube' ? 'This queues YouTube using the spoken Reel file, not a still image.' : ('This queues ' . esc_html($label) . ' using the saved graphic and does not generate a new image.'); ?></p>
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
        $this->render_social_reel_watch_script();
    }
}
