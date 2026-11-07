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
    const SOCIAL_IMAGE_MODEL = 'gpt-image-2';
    const SOCIAL_IMAGE_ESTIMATED_USD = 0.006;
    const INSTAGRAM_GRAPH_VERSION = 'v22.0';

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
        if (!empty($this->settings()['social_distribution_enabled']) && !wp_next_scheduled(self::SOCIAL_CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::SOCIAL_CRON_HOOK);
        }
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire&social_credentials_saved=1'));
        exit;
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
        $credentials = $this->social_credentials();
        $platforms = array();
        if (!empty($credentials['x_user_access_token'])) {
            $platforms[] = 'x';
        }
        if (!empty($credentials['instagram_user_access_token']) && !empty($credentials['instagram_user_id'])) {
            $platforms[] = 'instagram';
        }
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
        if (empty($settings['social_distribution_enabled'])) {
            return;
        }
        $credentials = $this->social_credentials();
        if (empty($credentials['x_user_access_token']) && (empty($credentials['instagram_user_access_token']) || empty($credentials['instagram_user_id']))) {
            return;
        }
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
                $image_id = $this->generate_social_image($post, $settings);
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
        $wpdb->update($this->social_table(), array('image_attachment_id' => (int) $image_id), array('id' => (int) $row['id']));
        $copy = $this->social_copy($post);
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

    private function social_copy($post) {
        $title = trim((string) get_post_meta($post->ID, 'rank_math_twitter_title', true));
        if ($title === '') {
            $title = trim((string) get_post_meta($post->ID, '_yoast_wpseo_twitter-title', true));
        }
        if ($title === '') {
            $title = $post->post_title;
        }
        $description = trim((string) get_post_meta($post->ID, 'rank_math_twitter_description', true));
        if ($description === '') {
            $description = trim((string) get_post_meta($post->ID, '_yoast_wpseo_twitter-description', true));
        }
        if ($description === '') {
            $description = $post->post_excerpt;
        }
        $description = wp_strip_all_tags($description);
        $url = get_permalink($post);
        $max_x_chars = 250;
        $length = function ($value) {
            return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        };
        $slice = function ($value, $start, $count) {
            return function_exists('mb_substr') ? mb_substr($value, $start, $count, 'UTF-8') : substr($value, $start, $count);
        };
        $fixed_length = $length("\n\n" . $url);
        $x_title = $slice($title, 0, max(1, $max_x_chars - $fixed_length));
        $fixed_length = $length($x_title . "\n\n" . $url);
        $x_description = $slice($description, 0, max(0, $max_x_chars - $fixed_length - 2));
        $x_text = $x_title . ($x_description !== '' ? "\n\n" . $x_description : '') . "\n\n" . $url;
        $caption = trim($title . "\n\n" . $description . "\n\nRead the full story: " . get_permalink($post));
        $caption = function_exists('mb_substr') ? mb_substr($caption, 0, 2200, 'UTF-8') : substr($caption, 0, 2200);
        return array('x' => $x_text, 'instagram' => $caption, 'instagram_alt' => $title);
    }

    private function generate_social_image($post, $settings) {
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
            return $this->generate_social_image_locked($post, $settings);
        } finally {
            delete_option($lock_name);
        }
    }

    private function generate_social_image_locked($post, $settings) {
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
        $description = trim(wp_strip_all_tags($post->post_excerpt));
        if ($description === '') {
            $description = wp_trim_words(wp_strip_all_tags($post->post_content), 45, '');
        }
        $prompt = 'Create an original editorial illustration for a technology news article. Use a polished, contemporary technology magazine art direction with a deep navy background and electric cyan accents. Depict the subject symbolically from this headline and summary; do not imitate any existing photograph, do not show a real identifiable person, and do not include text, logos, watermarks, or interface screenshots. Headline: ' . $post->post_title . '. Summary: ' . $description;
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
        $branded = $this->brand_social_image($bytes, $post->post_title);
        if (is_wp_error($branded)) {
            return $branded;
        }
        $upload = wp_upload_bits('technorapper-social-' . (int) $post->ID . '.jpg', null, $branded);
        if (!empty($upload['error'])) {
            return new WP_Error('tr_ai_wire_social_image_upload', sanitize_text_field($upload['error']));
        }
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'image/jpeg',
            'post_title' => sanitize_text_field($post->post_title . ' — social graphic'),
            'post_status' => 'inherit',
            'post_parent' => (int) $post->ID,
        ), $upload['file'], (int) $post->ID, true);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($post->post_title));
        return (int) $attachment_id;
    }

    private function brand_social_image($bytes, $title) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return new WP_Error('tr_ai_wire_social_gd', 'PHP GD is required to render the branded social image.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) {
            return new WP_Error('tr_ai_wire_social_image_decode', 'The generated social image could not be opened.');
        }
        $source_width = imagesx($source);
        $source_height = imagesy($source);
        $target_width = 1080;
        $target_height = 1350;
        $target_ratio = $target_width / $target_height;
        $source_ratio = $source_width / $source_height;
        if ($source_ratio > $target_ratio) {
            $crop_width = (int) round($source_height * $target_ratio);
            $source_x = (int) (($source_width - $crop_width) / 2);
            $source_y = 0;
        } else {
            $crop_width = $source_width;
            $crop_height = (int) round($source_width / $target_ratio);
            $source_x = 0;
            $source_y = (int) (($source_height - $crop_height) / 2);
        }
        $canvas = imagecreatetruecolor($target_width, $target_height);
        imagecopyresampled($canvas, $source, 0, 0, $source_x, $source_y, $target_width, $target_height, $crop_width, isset($crop_height) ? $crop_height : $source_height);
        imagedestroy($source);
        $navy = imagecolorallocate($canvas, 9, 20, 43);
        $cyan = imagecolorallocate($canvas, 43, 213, 255);
        imagefilledrectangle($canvas, 0, 0, $target_width, 115, $navy);
        imagefilledrectangle($canvas, 0, 111, $target_width, 119, $cyan);
        imagefilledrectangle($canvas, 0, 1005, $target_width, $target_height, $navy);
        imagefilledrectangle($canvas, 56, 1055, 72, 1285, $cyan);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 171, 197, 226);
        $font = $this->social_font_path();
        if ($font) {
            imagettftext($canvas, 28, 0, 98, 76, $white, $font, 'TECHNORAPPER.COM');
        } else {
            imagestring($canvas, 5, 98, 52, 'TECHNORAPPER.COM', $white);
        }
        $words = preg_split('/\s+/', trim(wp_strip_all_tags($title)));
        $lines = array();
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            $box = $font ? imagettfbbox(40, 0, $font, $candidate) : false;
            $too_wide = $font ? ($box[2] > 860) : (strlen($candidate) > 35);
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
        $y = 1080;
        foreach (array_slice($lines, 0, 4) as $text_line) {
            if ($font) {
                imagettftext($canvas, 40, 0, 104, $y + 40, $white, $font, $text_line);
                $y += 58;
            } else {
                imagestring($canvas, 5, 104, $y, substr($text_line, 0, 80), $white);
                $y += 42;
            }
        }
        if ($font) {
            imagettftext($canvas, 18, 0, 104, 1315, $muted, $font, 'TECH NEWS  |  AI  |  MOBILE  |  GADGETS');
        } else {
            imagestring($canvas, 4, 104, 1286, 'TECH NEWS  |  AI  |  MOBILE  |  GADGETS', $muted);
        }
        ob_start();
        imagejpeg($canvas, null, 88);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render the branded image.');
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
            return new WP_Error('tr_ai_wire_ig_quota', sanitize_text_field($data['error']['message'] ?? ('Could not read Instagram publishing quota (HTTP ' . $status . ').')));
        }
        if (isset($data['data'][0]) && is_array($data['data'][0])) {
            return $data['data'][0];
        }
        return is_array($data) ? $data : array();
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
            <p>Automatic distribution is <?php echo !empty($settings['social_distribution_enabled']) ? '<strong>on</strong>' : '<strong>off</strong>'; ?>. Turning it off pauses queued work. Today: <?php echo esc_html((string) $this->social_published_today()); ?> / <?php echo esc_html((string) (int) ($settings['social_daily_limit'] ?? 10)); ?> articles distributed.</p>
            <p>Social image budget this month: $<?php echo esc_html(number_format_i18n($image_usage, 3)); ?> / $<?php echo esc_html(number_format_i18n((float) ($settings['social_image_budget_usd'] ?? 0), 2)); ?> estimated.</p>
            <table class="widefat striped"><thead><tr><th>Queued</th><th>Article</th><th>Platform</th><th>Status</th><th>Result / details</th></tr></thead><tbody>
            <?php if (!$rows) : ?><tr><td colspan="5">No social items have been queued.</td></tr><?php else : foreach ($rows as $row) : $post = get_post((int) $row['post_id']); ?>
                <tr><td><?php echo esc_html($row['created_at']); ?></td><td><?php if ($post) : ?><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html($post->post_title); ?></a><?php endif; ?></td><td><?php echo esc_html($row['platform']); ?></td><td><?php echo esc_html($row['status']); ?></td><td><?php if ($row['remote_url']) : ?><a href="<?php echo esc_url($row['remote_url']); ?>" target="_blank" rel="noopener">View post</a><?php elseif ($row['remote_id']) : ?>Published media ID: <?php echo esc_html($row['remote_id']); ?><?php endif; ?><?php if ($row['error_text']) : ?><br><?php echo esc_html($row['error_text']); ?><?php endif; ?><?php if ($row['status'] === 'needs_review') : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;"><input type="hidden" name="action" value="tr_ai_wire_retry_social_item"><input type="hidden" name="item_id" value="<?php echo esc_attr((string) (int) $row['id']); ?>"><?php wp_nonce_field('tr_ai_wire_retry_social_item_' . (int) $row['id']); ?><label><input type="checkbox" name="confirmed_not_published" value="1" required> I checked that it is not already published</label><br><button class="button button-secondary">Retry this platform post</button></form><?php endif; ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody></table>
        </div>
        <?php
    }
}
