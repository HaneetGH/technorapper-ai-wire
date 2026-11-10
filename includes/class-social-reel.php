<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instagram Reels are one spoken story, not a slideshow of posters.
 *
 * A carousel packs several article cards. A collage grids those cards.
 * A Ken Burns pass over those JPEGs is still a slideshow. This renderer
 * does none of that: one article, a voiceover as the timeline, captions
 * that follow the spoken words, one B-roll photograph, hard cuts only.
 */
trait TechnoRapper_AI_Wire_Social_Reel {
    private function social_wants_reel($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        return $this->normalize_social_post_format($settings['social_post_format'] ?? 'single') === 'reel';
    }

    private function social_ffmpeg_path() {
        $filtered = apply_filters('tr_ai_wire_ffmpeg_path', '');
        if (is_string($filtered) && $filtered !== '') {
            return is_executable($filtered) ? $filtered : '';
        }
        if (defined('TR_AI_WIRE_FFMPEG') && TR_AI_WIRE_FFMPEG && is_executable(TR_AI_WIRE_FFMPEG)) {
            return (string) TR_AI_WIRE_FFMPEG;
        }
        foreach (array('/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg') as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return '';
    }

    private function social_ffprobe_path($ffmpeg = '') {
        $ffmpeg = $ffmpeg !== '' ? $ffmpeg : $this->social_ffmpeg_path();
        if ($ffmpeg === '') {
            return '';
        }
        $probe = preg_replace('/ffmpeg$/', 'ffprobe', $ffmpeg);
        return ($probe && $probe !== $ffmpeg && is_executable($probe)) ? $probe : '';
    }

    private function social_broll_id($post_id) {
        $post_id = (int) $post_id;
        $image_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_broll_id', true);
        if ($image_id > 0 && $this->social_image_file_exists($image_id)) {
            return $image_id;
        }
        return 0;
    }

    private function social_reel_id($post_id) {
        $post_id = (int) $post_id;
        $video_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_reel_id', true);
        if ($video_id < 1) {
            return 0;
        }
        $path = get_attached_file($video_id);
        if (!$path || !file_exists($path)) {
            return 0;
        }
        if ((string) get_post_meta($video_id, '_tr_ai_wire_social_layout', true) !== self::SOCIAL_REEL_LAYOUT) {
            return 0;
        }
        return $video_id;
    }

    private function fallback_reel_script($copy, $post) {
        $copy = is_array($copy) ? $copy : array();
        $hook = trim(wp_strip_all_tags((string) ($copy['hook'] ?? '')));
        $lesson = trim(wp_strip_all_tags((string) ($copy['on_image_subhead'] ?? '')));
        if ($post instanceof WP_Post) {
            $snapshot = $this->social_article_snapshot($post);
            $takeaway = trim(wp_strip_all_tags((string) (($snapshot['key_takeaways'][0] ?? ''))));
            if ($takeaway !== '') {
                $lesson = $takeaway;
            } elseif ($lesson === '') {
                $lede = trim(wp_strip_all_tags((string) ($snapshot['lede'] ?? $snapshot['excerpt'] ?? '')));
                $lesson = $this->clip_social_text($lede, 140);
            }
        }
        $parts = array();
        foreach (array($hook, $lesson) as $part) {
            $part = rtrim($part, '.');
            if ($part !== '' && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }
        if (!$parts) {
            $parts[] = 'Here is what changed';
        }
        $script = implode('. ', $parts) . '.';
        return $this->clip_social_block($script, 700);
    }

    private function writer_reel_script_present($raw) {
        $script = $this->clip_social_block(is_array($raw) ? ($raw['reel_script'] ?? '') : '', 700);
        $words = preg_split('/\s+/', $script, -1, PREG_SPLIT_NO_EMPTY);
        return count($words) >= 12;
    }

    private function fallback_reel_captions($script) {
        $script = trim(wp_strip_all_tags((string) $script));
        $sentences = preg_split('/(?<=[.!?])\s+/', $script);
        $captions = array();
        foreach ((array) $sentences as $sentence) {
            $sentence = trim($sentence, " \t\n\r\0\x0B\"'");
            $sentence = preg_replace('/\s+/', ' ', (string) $sentence);
            if ($sentence === '') {
                continue;
            }
            $words = preg_split('/\s+/', $sentence);
            $chunk = array();
            foreach ((array) $words as $word) {
                $chunk[] = $word;
                if (count($chunk) >= 6) {
                    $captions[] = implode(' ', $chunk);
                    $chunk = array();
                }
                if (count($captions) >= 5) {
                    return $captions;
                }
            }
            if ($chunk) {
                $captions[] = implode(' ', $chunk);
            }
            if (count($captions) >= 5) {
                break;
            }
        }
        return array_slice(array_values(array_filter($captions)), 0, 5);
    }

    private function sanitize_reel_script($raw, $copy, $post) {
        $script = $this->clip_social_block($raw, 700);
        $words = preg_split('/\s+/', $script, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 12) {
            $script = $this->fallback_reel_script($copy, $post);
        }
        return $script;
    }

    private function sanitize_reel_captions($raw, $script) {
        $captions = array();
        foreach ((array) $raw as $line) {
            $line = $this->clip_social_text($line, 42);
            if ($line !== '' && !in_array($line, $captions, true)) {
                $captions[] = $line;
            }
            if (count($captions) >= 5) {
                break;
            }
        }
        if (count($captions) < 3) {
            $captions = $this->fallback_reel_captions($script);
        }
        return array_slice($captions, 0, 5);
    }

    private function crop_social_broll($bytes) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return new WP_Error('tr_ai_wire_social_gd', 'PHP GD is required to render a Reel B-roll frame.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) {
            return new WP_Error('tr_ai_wire_social_image_decode', 'The generated social image could not be opened.');
        }
        $source_width = imagesx($source);
        $source_height = imagesy($source);
        $target_width = 1080;
        $target_height = 1920;
        $target_ratio = $target_width / $target_height;
        $source_ratio = $source_width / max(1, $source_height);
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
        imagecopyresampled($canvas, $source, 0, 0, $source_x, $source_y, $target_width, $target_height, $crop_width, $crop_height);
        imagedestroy($source);
        $this->draw_social_bottom_gradient($canvas, $target_width, $target_height, 0.82);
        ob_start();
        imagejpeg($canvas, null, 88);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render the Reel B-roll frame.');
    }

    private function store_social_broll($post, $bytes, $copy) {
        $headline = (string) ($copy['on_image_headline'] ?? $copy['hook'] ?? 'B-roll');
        return $this->store_social_jpeg($post, $bytes, $headline, $copy, 'broll', 'broll', 'reel-broll');
    }

    private function build_social_reel_timeline($copy, $duration) {
        $copy = is_array($copy) ? $copy : array();
        $duration = max(12.0, min(24.0, (float) $duration));
        $hook = trim((string) ($copy['hook'] ?? $copy['on_image_headline'] ?? ''));
        $captions = array_values((array) ($copy['reel_captions'] ?? array()));
        if (count($captions) < 3) {
            $captions = $this->fallback_reel_captions((string) ($copy['reel_script'] ?? $hook));
        }
        $captions = array_slice($captions, 0, 5);
        if ($hook !== '' && (empty($captions) || strcasecmp((string) $captions[0], $hook) !== 0)) {
            array_unshift($captions, $hook);
            $captions = array_slice($captions, 0, 5);
        }
        $end = 2.0;
        $middle = max(10.0, $duration - $end);
        $each = $middle / max(1, count($captions));
        $clips = array();
        foreach ($captions as $line) {
            $clips[] = array(
                'kind' => 'broll',
                'caption' => (string) $line,
                'duration' => round($each, 2),
            );
        }
        $clips[] = array(
            'kind' => 'end',
            'caption' => (string) ($copy['cta'] ?? ('Full story on ' . self::site_brand_name())),
            'duration' => $end,
        );
        return $clips;
    }

    private function render_social_reel_frame($kind, $copy, $photo_bytes, $caption) {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return new WP_Error('tr_ai_wire_social_gd', 'PHP GD is required to render a Reel frame.');
        }
        $width = 1080;
        $height = 1920;
        $kind = sanitize_key((string) $kind);
        $copy = is_array($copy) ? $copy : array();
        $caption = trim(wp_strip_all_tags((string) $caption));
        $font = $this->social_font_path();
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, true);
        $black = imagecolorallocate($canvas, 7, 11, 20);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $black);
        if ($kind === 'broll' && is_string($photo_bytes) && $photo_bytes !== '') {
            $photo = @imagecreatefromstring($photo_bytes);
            if ($photo) {
                $photo_width = imagesx($photo);
                $photo_height = imagesy($photo);
                imagecopyresampled($canvas, $photo, 0, 0, 0, 0, $width, $height, $photo_width, $photo_height);
                imagedestroy($photo);
                $this->draw_social_bottom_gradient($canvas, $width, $height, 0.78);
            }
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 214, 222, 230);
        $cyan = imagecolorallocate($canvas, 43, 213, 255);
        $stroke = imagecolorallocate($canvas, 0, 0, 0);
        if ($kind === 'open') {
            $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, 96);
            $size = $font ? 72 : 5;
            $lines = $this->wrap_social_lines($caption, 960, $size, $font, 18, 4);
            $y = (int) round($height * 0.42);
            foreach ($lines as $index => $line) {
                $this->draw_social_centered_text($canvas, $line, $size, $y, $white, $font, $width, $stroke, 3);
                $y += (int) round($size * 1.12);
            }
        } elseif ($kind === 'end') {
            $mark = self::site_brand_mark();
            $mark_size = $font ? 64 : 5;
            $this->draw_social_centered_text($canvas, $mark, $mark_size, (int) round($height * 0.44), $white, $font, $width, $stroke, 3);
            $cta_size = $font ? 36 : 5;
            $this->draw_social_centered_text($canvas, $caption, $cta_size, (int) round($height * 0.52), $muted, $font, $width, $stroke, 2);
            $handle = self::site_brand_handle();
            if ($handle !== '') {
                $this->draw_social_centered_text($canvas, $handle, $font ? 28 : 5, (int) round($height * 0.58), $cyan, $font, $width, $stroke, 2);
            }
        } else {
            $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, 96);
            $size = $font ? 44 : 5;
            $lines = $this->wrap_social_lines($caption, 960, $size, $font, 28, 2);
            $y = $height - 160 - ((max(0, count($lines) - 1)) * (int) round($size * 1.16));
            foreach ($lines as $line) {
                $this->draw_social_centered_text($canvas, $line, $size, $y, $white, $font, $width, $stroke, 3);
                $y += (int) round($size * 1.16);
            }
        }
        ob_start();
        imagejpeg($canvas, null, 88);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render a Reel frame.');
    }

    private function ensure_social_reel($post, $settings, $copy, $broll_path) {
        if (!$post instanceof WP_Post) {
            return new WP_Error('tr_ai_wire_social_post', 'The source article is no longer published.');
        }
        $copy = is_array($copy) ? $copy : array();
        $existing = $this->social_reel_id($post->ID);
        if ($existing > 0 && (string) get_post_meta($post->ID, '_tr_ai_wire_social_regenerate_image', true) !== '1') {
            $existing_script = (string) get_post_meta($existing, '_tr_ai_wire_reel_script', true);
            if ($existing_script === (string) ($copy['reel_script'] ?? '')) {
                $url = wp_get_attachment_url($existing);
                $path = get_attached_file($existing);
                if ($url && $path) {
                    return array('id' => $existing, 'url' => $url, 'path' => $path);
                }
            }
        }
        if ($this->social_ffmpeg_path() === '') {
            return new WP_Error(
                'tr_ai_wire_reel_ffmpeg',
                'Instagram Reels need ffmpeg on this WordPress host. Install ffmpeg, or switch Instagram back to a still format. The plugin will not Ken Burns the posters into a slideshow.'
            );
        }
        if (!is_string($broll_path) || $broll_path === '' || !file_exists($broll_path)) {
            return new WP_Error('tr_ai_wire_reel_broll', 'A text-free 9:16 B-roll frame is required before rendering the Reel.');
        }
        $script = (string) ($copy['reel_script'] ?? '');
        if ($script === '') {
            $script = $this->fallback_reel_script($copy, $post);
        }
        $audio = $this->generate_social_tts($script, $settings);
        if (is_wp_error($audio)) {
            return $audio;
        }
        $photo = file_get_contents($broll_path);
        if (!is_string($photo) || $photo === '') {
            return new WP_Error('tr_ai_wire_reel_broll', 'The Reel B-roll file could not be read.');
        }
        $tmp = get_temp_dir() . 'tr-ai-wire-reel-' . wp_generate_password(8, false, false);
        if (!wp_mkdir_p($tmp)) {
            return new WP_Error('tr_ai_wire_reel_temp', 'Could not create a temporary directory for the Reel.');
        }
        $voice_path = $tmp . '/voice.mp3';
        $out_path = $tmp . '/reel.mp4';
        if (file_put_contents($voice_path, $audio) === false) {
            $this->social_reel_cleanup($tmp);
            return new WP_Error('tr_ai_wire_reel_temp', 'Could not write the Reel voiceover file.');
        }
        $duration = $this->social_audio_duration($voice_path, $script);
        $timeline = $this->build_social_reel_timeline($copy, $duration);
        $frames = array();
        foreach ($timeline as $index => $clip) {
            $jpeg = $this->render_social_reel_frame($clip['kind'], $copy, $photo, $clip['caption']);
            if (is_wp_error($jpeg)) {
                $this->social_reel_cleanup($tmp);
                return $jpeg;
            }
            $frame_path = $tmp . '/frame-' . (string) $index . '.jpg';
            if (file_put_contents($frame_path, $jpeg) === false) {
                $this->social_reel_cleanup($tmp);
                return new WP_Error('tr_ai_wire_reel_temp', 'Could not write a Reel frame.');
            }
            $frames[] = array(
                'path' => $frame_path,
                'duration' => (float) $clip['duration'],
                'kind' => (string) $clip['kind'],
            );
        }
        $muxed = $this->compose_social_reel_video($frames, $voice_path, $out_path);
        if (is_wp_error($muxed)) {
            $this->social_reel_cleanup($tmp);
            return $muxed;
        }
        $bytes = file_get_contents($out_path);
        $this->social_reel_cleanup($tmp);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('tr_ai_wire_reel_mux', 'ffmpeg did not produce a Reel video.');
        }
        $stored = $this->store_social_video($post, $bytes, $copy, $script);
        if (is_wp_error($stored)) {
            return $stored;
        }
        update_post_meta($post->ID, '_tr_ai_wire_social_reel_id', (int) $stored);
        $url = wp_get_attachment_url((int) $stored);
        $path = get_attached_file((int) $stored);
        if (!$url || !$path) {
            return new WP_Error('tr_ai_wire_ig_video_url', 'The Instagram Reel must have a public HTTP(S) Media Library URL.');
        }
        return array('id' => (int) $stored, 'url' => $url, 'path' => $path);
    }

    private function generate_social_tts($script, $settings) {
        $script = trim(wp_strip_all_tags((string) $script));
        if ($script === '') {
            return new WP_Error('tr_ai_wire_reel_script', 'A spoken Reel script is required.');
        }
        $month_key = 'tr_ai_wire_social_image_usage_' . gmdate('Ym');
        $usage = (float) get_option($month_key, 0);
        $budget = (float) ($settings['social_image_budget_usd'] ?? 0);
        if ($budget <= 0 || ($usage + self::SOCIAL_TTS_ESTIMATED_USD) > $budget) {
            return new WP_Error('tr_ai_wire_social_image_budget', 'Social image generation is paused because its monthly image budget is exhausted.');
        }
        $key = $this->api_key();
        if (!$key) {
            return new WP_Error('tr_ai_wire_social_image_key', 'Add the OpenAI API key before generating a Reel voiceover.');
        }
        $response = wp_remote_post('https://api.openai.com/v1/audio/speech', array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => self::SOCIAL_TTS_MODEL,
                'voice' => self::SOCIAL_TTS_VOICE,
                'input' => $script,
                'instructions' => 'News desk. Clear, calm, slightly urgent. No theatrical announcer. Speak so a listener can repeat the takeaway.',
                'response_format' => 'mp3',
            )),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_reel_tts', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $type = (string) wp_remote_retrieve_header($response, 'content-type');
        if ($status < 200 || $status >= 300 || $body === '' || (strpos($type, 'json') !== false)) {
            $data = json_decode($body, true);
            $message = sanitize_text_field($data['error']['message'] ?? ('Speech API returned HTTP ' . $status . '.'));
            return new WP_Error('tr_ai_wire_reel_tts', $message);
        }
        update_option($month_key, round($usage + self::SOCIAL_TTS_ESTIMATED_USD, 6), false);
        return $body;
    }

    private function social_audio_duration($path, $script) {
        $probe = $this->social_ffprobe_path();
        if ($probe !== '') {
            $result = $this->social_run_binary($probe, array(
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $path,
            ));
            if (!is_wp_error($result)) {
                $seconds = (float) trim((string) $result);
                if ($seconds >= 6 && $seconds <= 60) {
                    return $seconds;
                }
            }
        }
        $words = preg_split('/\s+/', wp_strip_all_tags((string) $script), -1, PREG_SPLIT_NO_EMPTY);
        return max(10.0, min(24.0, count($words) / 2.4));
    }

    private function compose_social_reel_video($frames, $audio_path, $out_path) {
        $ffmpeg = $this->social_ffmpeg_path();
        if ($ffmpeg === '') {
            return new WP_Error(
                'tr_ai_wire_reel_ffmpeg',
                'Instagram Reels need ffmpeg on this WordPress host. The plugin will not fall back to a slideshow of stills.'
            );
        }
        $frames = array_values((array) $frames);
        if (count($frames) < 3) {
            return new WP_Error('tr_ai_wire_reel_frames', 'A Reel needs spoken captions on one B-roll shot and an end card.');
        }
        $kinds = array();
        foreach ($frames as $frame) {
            $kinds[] = (string) ($frame['kind'] ?? '');
            if (empty($frame['path']) || !file_exists($frame['path'])) {
                return new WP_Error('tr_ai_wire_reel_frames', 'A Reel frame file is missing.');
            }
        }
        if (!in_array('broll', $kinds, true) || !in_array('end', $kinds, true)) {
            return new WP_Error('tr_ai_wire_reel_frames', 'A Reel must open on the B-roll with the spoken hook, then end on a brand sting.');
        }
        if (!is_string($audio_path) || $audio_path === '' || !file_exists($audio_path)) {
            return new WP_Error('tr_ai_wire_reel_tts', 'A spoken voiceover is required. Silent poster clips are a slideshow.');
        }
        $list = dirname($out_path) . '/concat.txt';
        $lines = array();
        foreach ($frames as $frame) {
            $lines[] = "file '" . str_replace("'", "'\\''", (string) $frame['path']) . "'";
            $lines[] = 'duration ' . number_format((float) $frame['duration'], 2, '.', '');
        }
        $lines[] = "file '" . str_replace("'", "'\\''", (string) $frames[count($frames) - 1]['path']) . "'";
        if (file_put_contents($list, implode("\n", $lines) . "\n") === false) {
            return new WP_Error('tr_ai_wire_reel_temp', 'Could not write the ffmpeg concat list.');
        }
        $args = array(
            '-y',
            '-f', 'concat',
            '-safe', '0',
            '-i', $list,
            '-i', $audio_path,
            '-vf', 'fps=30,format=yuv420p',
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-profile:v', 'high',
            '-level', '4.0',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-ar', '44100',
            '-shortest',
            '-movflags', '+faststart',
            $out_path,
        );
        $result = $this->social_run_binary($ffmpeg, $args);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!file_exists($out_path) || filesize($out_path) < 1024) {
            return new WP_Error('tr_ai_wire_reel_mux', 'ffmpeg did not produce a usable Reel video.');
        }
        return $out_path;
    }

    private function store_social_video($post, $bytes, $copy, $script) {
        $name = 'technorapper-social-reel-' . (int) $post->ID . '.mp4';
        $upload = wp_upload_bits($name, null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('tr_ai_wire_social_video_upload', sanitize_text_field($upload['error']));
        }
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'video/mp4',
            'post_title' => sanitize_text_field((string) ($copy['hook'] ?? $post->post_title) . ' — social reel'),
            'post_status' => 'inherit',
            'post_parent' => 0,
        ), $upload['file'], 0, true);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
        update_post_meta($attachment_id, '_tr_ai_wire_social_layout', self::SOCIAL_REEL_LAYOUT);
        update_post_meta($attachment_id, '_tr_ai_wire_reel_script', sanitize_textarea_field((string) $script));
        return (int) $attachment_id;
    }

    private function social_run_binary($binary, $args) {
        if (!function_exists('proc_open')) {
            return new WP_Error('tr_ai_wire_reel_ffmpeg', 'proc_open is disabled, so this host cannot render Reels.');
        }
        $cmd = array_merge(array((string) $binary), array_values((array) $args));
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = @proc_open($cmd, $descriptors, $pipes, sys_get_temp_dir());
        if (!is_resource($process)) {
            return new WP_Error('tr_ai_wire_reel_ffmpeg', 'Could not start ffmpeg.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = time() + 90;
        do {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (empty($status['running'])) {
                break;
            }
            if (time() > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return new WP_Error('tr_ai_wire_reel_ffmpeg', 'ffmpeg timed out while rendering the Reel.');
            }
            usleep(80000);
        } while (true);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            $message = trim(wp_strip_all_tags($stderr !== '' ? $stderr : $stdout));
            $message = $this->clip_social_text($message !== '' ? $message : ('ffmpeg exited ' . (string) $code . '.'), 240);
            return new WP_Error('tr_ai_wire_reel_ffmpeg', $message);
        }
        return $stdout;
    }

    private function social_reel_cleanup($dir) {
        $dir = (string) $dir;
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        foreach ((array) $files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}
