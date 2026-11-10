<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * YouTube Shorts reuse the spoken Reel MP4. Title, description, tags,
 * category, privacy, audience, and synthetic-media disclosure are set
 * through YouTube Data API v3 videos.insert — not Studio, not stills.
 */
trait TechnoRapper_AI_Wire_Social_YouTube {
    public static function youtube_credentials_ready($credentials = null) {
        if (!is_array($credentials)) {
            $credentials = wp_parse_args(get_option(self::SOCIAL_CREDENTIALS_OPTION, array()), self::social_credential_defaults());
        }
        return !empty($credentials['youtube_refresh_token']) || !empty($credentials['youtube_access_token']);
    }

    public static function youtube_video_categories() {
        return array(
            '28' => 'Science & Technology',
            '25' => 'News & Politics',
            '27' => 'Education',
            '22' => 'People & Blogs',
            '24' => 'Entertainment',
            '2' => 'Autos & Vehicles',
            '1' => 'Film & Animation',
            '20' => 'Gaming',
            '26' => 'Howto & Style',
            '10' => 'Music',
            '17' => 'Sports',
            '19' => 'Travel & Events',
            '23' => 'Comedy',
            '15' => 'Pets & Animals',
            '29' => 'Nonprofits & Activism',
        );
    }

    private function youtube_category_id($settings) {
        $id = preg_replace('/[^0-9]/', '', (string) ($settings['social_youtube_category_id'] ?? '28'));
        $allowed = array_keys(self::youtube_video_categories());
        return in_array($id, $allowed, true) ? $id : '28';
    }

    private function sanitize_youtube_title($raw, $copy, $post) {
        $title = $this->clip_social_text($raw, 100);
        $title = str_replace(array('<', '>'), '', $title);
        if ($title === '') {
            $title = $this->clip_social_text((string) ($copy['hook'] ?? $post->post_title), 100);
            $title = str_replace(array('<', '>'), '', $title);
        }
        return $title;
    }

    private function sanitize_youtube_description($raw, $copy, $post) {
        $url = get_permalink($post);
        $text = trim(str_replace(array('<', '>'), '', (string) $raw));
        if ($text === '') {
            $hook = trim((string) ($copy['hook'] ?? $post->post_title));
            $sub = trim((string) ($copy['on_image_subhead'] ?? ''));
            $text = $hook;
            if ($sub !== '' && stripos($text, $sub) === false) {
                $text .= "\n\n" . $sub;
            }
        }
        $text = $this->clip_social_block($text, 4200);
        if ($url && strpos($text, $url) === false) {
            $text = trim($text . "\n\n" . $url);
        }
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 5000) {
            $text = mb_substr($text, 0, 5000, 'UTF-8');
        } elseif (strlen($text) > 5000) {
            $text = substr($text, 0, 5000);
        }
        return $text;
    }

    private function sanitize_youtube_tags($raw, $copy = array()) {
        $source = array();
        if (is_array($raw)) {
            $source = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $source = preg_split('/[,]+/', $raw);
        }
        if (!$source && is_array($copy)) {
            $source = (array) ($copy['hashtags'] ?? array());
        }
        $tags = array();
        $total = 0;
        foreach ($source as $tag) {
            $tag = is_array($tag) ? (string) ($tag['tag'] ?? implode(' ', $tag)) : (string) $tag;
            $tag = strtolower(trim($tag));
            $tag = ltrim($tag, '#');
            $tag = preg_replace('/\s+/', ' ', $tag);
            $tag = trim((string) preg_replace('/[^a-z0-9 _-]/i', '', $tag));
            if ($tag === '' || in_array($tag, $tags, true)) {
                continue;
            }
            if (function_exists('mb_strlen')) {
                if (mb_strlen($tag, 'UTF-8') > 30) {
                    $tag = mb_substr($tag, 0, 30, 'UTF-8');
                }
            } elseif (strlen($tag) > 30) {
                $tag = substr($tag, 0, 30);
            }
            $next = $total + strlen($tag) + 1;
            if ($next > 450) {
                break;
            }
            $tags[] = $tag;
            $total = $next;
            if (count($tags) >= 8) {
                break;
            }
        }
        return $tags;
    }

    private function youtube_video_resource($copy, $post, $settings) {
        $copy = is_array($copy) ? $copy : array();
        $title = $this->sanitize_youtube_title($copy['youtube_title'] ?? '', $copy, $post);
        $description = $this->sanitize_youtube_description($copy['youtube_description'] ?? '', $copy, $post);
        $tags = $this->sanitize_youtube_tags($copy['youtube_tags'] ?? array(), $copy);
        $snippet = array(
            'title' => $title,
            'description' => $description,
            'categoryId' => $this->youtube_category_id($settings),
        );
        if ($tags) {
            $snippet['tags'] = $tags;
        }
        return array(
            'snippet' => $snippet,
            'status' => array(
                'privacyStatus' => 'public',
                'selfDeclaredMadeForKids' => false,
                'embeddable' => true,
                'containsSyntheticMedia' => true,
            ),
        );
    }

    private function publish_youtube_short($copy, $video_path, $credentials, $settings, $post, $existing_id = '') {
        if (!self::youtube_credentials_ready($credentials)) {
            return new WP_Error('tr_ai_wire_yt_missing_token', 'YouTube is not connected. Connect the channel on Social settings.');
        }
        $existing_id = sanitize_text_field((string) $existing_id);
        if ($existing_id !== '') {
            $token = TechnoRapper_AI_Wire_YouTube_OAuth::access_token($credentials);
            if (is_wp_error($token)) {
                return $token;
            }
            $wait = $this->youtube_wait_for_processing($existing_id, $token);
            if (is_wp_error($wait)) {
                if ($wait->get_error_code() === 'tr_ai_wire_yt_video_wait') {
                    $wait->add_data($existing_id);
                }
                return $wait;
            }
            return array(
                'id' => $existing_id,
                'url' => 'https://www.youtube.com/shorts/' . rawurlencode($existing_id),
            );
        }
        $video_path = (string) $video_path;
        if ($video_path === '' || !is_readable($video_path)) {
            return new WP_Error('tr_ai_wire_yt_video_file', 'The spoken Short file is missing from WordPress Media. The worker will not post a still image to YouTube.');
        }
        $size = filesize($video_path);
        if ($size < 1) {
            return new WP_Error('tr_ai_wire_yt_video_file', 'The spoken Short file is empty.');
        }
        $token = TechnoRapper_AI_Wire_YouTube_OAuth::access_token($credentials);
        if (is_wp_error($token)) {
            return $token;
        }
        $resource = $this->youtube_video_resource($copy, $post, $settings);
        if (($resource['snippet']['title'] ?? '') === '') {
            return new WP_Error('tr_ai_wire_yt_title', 'YouTube rejected an empty title. The Writer pass must return youtube_title.');
        }
        $notify = empty($settings['social_youtube_notify_subscribers']) ? 'false' : 'true';
        $init = wp_remote_post(
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status&notifySubscribers=' . $notify,
            array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json; charset=UTF-8',
                    'X-Upload-Content-Length' => (string) $size,
                    'X-Upload-Content-Type' => 'video/mp4',
                ),
                'body' => wp_json_encode($resource),
            )
        );
        $quota = $this->youtube_quota_error($init);
        if (is_wp_error($quota)) {
            return $quota;
        }
        if (is_wp_error($init)) {
            return new WP_Error('tr_ai_wire_yt_upload_init', $init->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($init);
        $session = (string) wp_remote_retrieve_header($init, 'location');
        if ($status === 401) {
            $refreshed = TechnoRapper_AI_Wire_YouTube_OAuth::refresh_access_token($credentials);
            if (is_wp_error($refreshed)) {
                return $refreshed;
            }
            return new WP_Error('tr_ai_wire_yt_video_wait', 'YouTube access token was refreshed. The Short will retry shortly.');
        }
        if (($status < 200 || $status >= 300) || $session === '' || strpos($session, 'https://') !== 0) {
            $data = json_decode(wp_remote_retrieve_body($init), true);
            $message = sanitize_text_field($data['error']['message'] ?? ('YouTube upload session returned HTTP ' . $status . '.'));
            if ($this->youtube_is_quota_message($message) || $status === 403) {
                return new WP_Error('tr_ai_wire_yt_quota', 'YouTube API quota is full for today. The item is queued to try again later.');
            }
            return new WP_Error('tr_ai_wire_yt_upload_init', $message);
        }
        $bytes = file_get_contents($video_path);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('tr_ai_wire_yt_video_file', 'The spoken Short file could not be read for upload.');
        }
        $put = wp_remote_request($session, array(
            'method' => 'PUT',
            'timeout' => 180,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'video/mp4',
                'Content-Length' => (string) strlen($bytes),
            ),
            'body' => $bytes,
        ));
        $quota = $this->youtube_quota_error($put);
        if (is_wp_error($quota)) {
            return $quota;
        }
        if (is_wp_error($put)) {
            return new WP_Error('tr_ai_wire_yt_upload', $put->get_error_message());
        }
        $put_status = (int) wp_remote_retrieve_response_code($put);
        $data = json_decode(wp_remote_retrieve_body($put), true);
        $video_id = sanitize_text_field((string) ($data['id'] ?? ''));
        if ($put_status === 308) {
            return new WP_Error('tr_ai_wire_yt_video_wait', 'YouTube upload is incomplete. The Short will resume shortly.');
        }
        if ($put_status < 200 || $put_status >= 300 || $video_id === '') {
            $message = sanitize_text_field($data['error']['message'] ?? ('YouTube upload returned HTTP ' . $put_status . '.'));
            if (stripos($message, 'uploadLimitExceeded') !== false || (($data['error']['errors'][0]['reason'] ?? '') === 'uploadLimitExceeded')) {
                return new WP_Error('tr_ai_wire_yt_upload_limit', 'This YouTube channel has hit its daily upload limit. Held for review.');
            }
            if ($this->youtube_is_quota_message($message) || $put_status === 403) {
                return new WP_Error('tr_ai_wire_yt_quota', 'YouTube API quota is full for today. The item is queued to try again later.');
            }
            return new WP_Error('tr_ai_wire_yt_upload', $message);
        }
        $wait = $this->youtube_wait_for_processing($video_id, $token);
        if (is_wp_error($wait)) {
            if ($wait->get_error_code() === 'tr_ai_wire_yt_video_wait') {
                $wait->add_data($video_id);
            }
            return $wait;
        }
        return array(
            'id' => $video_id,
            'url' => 'https://www.youtube.com/shorts/' . rawurlencode($video_id),
        );
    }

    private function youtube_wait_for_processing($video_id, $token) {
        $tries = 8;
        for ($i = 0; $i < $tries; $i++) {
            if ($i > 0) {
                $this->youtube_poll_sleep();
            }
            $url = add_query_arg(array(
                'part' => 'status,processingDetails,snippet',
                'id' => $video_id,
            ), 'https://www.googleapis.com/youtube/v3/videos');
            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'headers' => array('Authorization' => 'Bearer ' . $token),
            ));
            if (is_wp_error($response)) {
                return new WP_Error('tr_ai_wire_yt_video_wait', 'YouTube processing status could not be read. The Short will retry shortly.');
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $item = is_array($data['items'][0] ?? null) ? $data['items'][0] : array();
            $processing = (string) ($item['processingDetails']['processingStatus'] ?? '');
            $upload = (string) ($item['status']['uploadStatus'] ?? '');
            if ($processing === 'failed' || $upload === 'failed' || $upload === 'rejected') {
                $reason = sanitize_text_field((string) ($item['processingDetails']['processingFailureReason'] ?? $item['status']['failureReason'] ?? $item['status']['rejectionReason'] ?? 'processing failed'));
                return new WP_Error('tr_ai_wire_yt_processing', 'YouTube rejected or failed the Short (' . $reason . ').');
            }
            if ($processing === 'succeeded' || $upload === 'processed') {
                return true;
            }
        }
        return new WP_Error('tr_ai_wire_yt_video_wait', 'YouTube is still processing the Short. The item stays queued.');
    }

    private function youtube_poll_sleep() {
        $seconds = (int) apply_filters('tr_ai_wire_youtube_poll_seconds', 2);
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    private function youtube_quota_error($response) {
        if (is_wp_error($response)) {
            return null;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $reason = (string) ($data['error']['errors'][0]['reason'] ?? '');
        $message = (string) ($data['error']['message'] ?? '');
        if ($status === 403 && ($reason === 'quotaExceeded' || $reason === 'dailyLimitExceeded' || $this->youtube_is_quota_message($message))) {
            return new WP_Error('tr_ai_wire_yt_quota', 'YouTube API quota is full for today. The item is queued to try again later.');
        }
        return null;
    }

    private function youtube_is_quota_message($message) {
        $message = strtolower((string) $message);
        return strpos($message, 'quota') !== false || strpos($message, 'rate limit') !== false;
    }

    private function delete_youtube_video($video_id, $credentials) {
        $video_id = sanitize_text_field((string) $video_id);
        if ($video_id === '') {
            return true;
        }
        $token = TechnoRapper_AI_Wire_YouTube_OAuth::access_token($credentials);
        if (is_wp_error($token)) {
            return $token;
        }
        $url = add_query_arg(array('id' => $video_id), 'https://www.googleapis.com/youtube/v3/videos');
        $response = wp_remote_request($url, array(
            'method' => 'DELETE',
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . $token),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_yt_delete', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 204 || $status === 200 || $status === 404) {
            return true;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return new WP_Error('tr_ai_wire_yt_delete', sanitize_text_field($data['error']['message'] ?? ('YouTube delete returned HTTP ' . $status . '.')));
    }
}
