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
 * that follow the spoken words, Fal Veo motion from one B-roll still,
 * then a last-frame hold and a brand sting.
 */
trait TechnoRapper_AI_Wire_Social_Reel {
    private function social_wants_reel($settings = null, $post_id = 0) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $post_id = (int) $post_id;
        if ($post_id > 0) {
            $mode = sanitize_key((string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
            if ($mode === 'reel') {
                return true;
            }
            if (in_array($mode, array('single', 'pack', 'carousel', 'collage'), true)) {
                return false;
            }
        }
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

    private function social_motion_id($post_id) {
        $post_id = (int) $post_id;
        $video_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_motion_id', true);
        if ($video_id < 1) {
            return 0;
        }
        $path = get_attached_file($video_id);
        return ($path && file_exists($path)) ? $video_id : 0;
    }

    private function social_reel_video_models() {
        return array(
            'veo-lite' => array(
                'label' => 'Veo 3.1 Lite',
                'short' => 'Suggested for a $10/month budget',
                'recommended' => true,
                'endpoint' => 'fal-ai/veo3.1/lite/image-to-video',
                'usd_per_second' => 0.03,
                'durations' => array(4, 6, 8),
                'image_field' => 'image_url',
                'duration_suffix' => 's',
                'resolution' => '720p',
                'aspect_ratio' => '9:16',
                'auto_fix' => true,
                'docs' => 'https://fal.ai/models/fal-ai/veo3.1/lite/image-to-video',
                'blurb' => 'Silent 720p image-to-video. Cheapest Veo that still looks like camera motion, not a Ken Burns still.',
            ),
            'veo-fast' => array(
                'label' => 'Veo 3.1 Fast',
                'short' => 'Sharper motion when traffic pays',
                'recommended' => false,
                'endpoint' => 'fal-ai/veo3.1/fast/image-to-video',
                'usd_per_second' => 0.10,
                'durations' => array(4, 6, 8),
                'image_field' => 'image_url',
                'duration_suffix' => 's',
                'resolution' => '720p',
                'aspect_ratio' => '9:16',
                'auto_fix' => true,
                'docs' => 'https://fal.ai/models/fal-ai/veo3.1/fast/image-to-video',
                'blurb' => 'Same 4/6/8s Veo family, higher fidelity. About 3× Lite. Raise the video budget first.',
            ),
            'veo' => array(
                'label' => 'Veo 3.1',
                'short' => 'Full Veo look, highest Veo bill',
                'recommended' => false,
                'endpoint' => 'fal-ai/veo3.1/image-to-video',
                'usd_per_second' => 0.20,
                'durations' => array(4, 6, 8),
                'image_field' => 'image_url',
                'duration_suffix' => 's',
                'resolution' => '720p',
                'aspect_ratio' => '9:16',
                'auto_fix' => true,
                'docs' => 'https://fal.ai/models/fal-ai/veo3.1/image-to-video',
                'blurb' => 'Flagship Veo stills-to-motion. 8s is about $1.60 — roughly $48/month at one Reel/day.',
            ),
            'kling-std' => array(
                'label' => 'Kling 3.0 Standard',
                'short' => 'Longer clips (up to 15s)',
                'recommended' => false,
                'endpoint' => 'fal-ai/kling-video/v3/standard/image-to-video',
                'usd_per_second' => 0.084,
                'durations' => array(5, 8, 10, 15),
                'image_field' => 'start_image_url',
                'duration_suffix' => '',
                'docs' => 'https://fal.ai/models/fal-ai/kling-video/v3/standard/image-to-video',
                'blurb' => 'Silent Kling Standard. 8s is about $0.67; 15s is about $1.26. Use when you want a longer moving shot than Veo 8s.',
            ),
            'kling-pro' => array(
                'label' => 'Kling 3.0 Pro',
                'short' => 'Best motion, highest bill',
                'recommended' => false,
                'endpoint' => 'fal-ai/kling-video/v3/pro/image-to-video',
                'usd_per_second' => 0.112,
                'durations' => array(5, 8, 10, 15),
                'image_field' => 'start_image_url',
                'duration_suffix' => '',
                'docs' => 'https://fal.ai/models/fal-ai/kling-video/v3/pro/image-to-video',
                'blurb' => 'Silent Kling Pro, up to 15s. 8s is about $0.90; 15s is about $1.68 — a $10 budget is only a handful of clips.',
            ),
        );
    }

    private function social_reel_video_model($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $model = sanitize_key((string) ($settings['social_reel_video_model'] ?? self::SOCIAL_REEL_VIDEO_DEFAULT));
        $models = $this->social_reel_video_models();
        return isset($models[$model]) ? $model : self::SOCIAL_REEL_VIDEO_DEFAULT;
    }

    private function social_reel_clip_seconds($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $wanted = absint($settings['social_reel_clip_seconds'] ?? 8);
        $model = $this->social_reel_video_model($settings);
        $models = $this->social_reel_video_models();
        $allowed = isset($models[$model]['durations']) ? (array) $models[$model]['durations'] : array(4, 6, 8);
        if (in_array($wanted, $allowed, true)) {
            return $wanted;
        }
        $best = (int) $allowed[0];
        foreach ($allowed as $seconds) {
            $seconds = (int) $seconds;
            if (abs($seconds - $wanted) < abs($best - $wanted)) {
                $best = $seconds;
            }
        }
        return $best;
    }

    private function social_reel_video_estimate_usd($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $models = $this->social_reel_video_models();
        $model = $this->social_reel_video_model($settings);
        $rate = (float) ($models[$model]['usd_per_second'] ?? 0.03);
        return round($this->social_reel_clip_seconds($settings) * $rate, 4);
    }

    private function social_fal_motion_payload($model, $image_url, $prompt, $seconds) {
        $models = $this->social_reel_video_models();
        $spec = isset($models[$model]) ? $models[$model] : $models[self::SOCIAL_REEL_VIDEO_DEFAULT];
        $field = (string) ($spec['image_field'] ?? 'image_url');
        $suffix = (string) ($spec['duration_suffix'] ?? 's');
        $payload = array(
            'prompt' => $this->clip_social_text($prompt, 1200),
            $field => $image_url,
            'duration' => (string) $seconds . $suffix,
            'generate_audio' => false,
        );
        if (!empty($spec['aspect_ratio'])) {
            $payload['aspect_ratio'] = (string) $spec['aspect_ratio'];
        }
        if (!empty($spec['resolution'])) {
            $payload['resolution'] = (string) $spec['resolution'];
        }
        if (!empty($spec['auto_fix'])) {
            $payload['auto_fix'] = true;
        }
        return $payload;
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
        if ($this->fal_api_key() === '') {
            return new WP_Error(
                'tr_ai_wire_reel_fal_key',
                'Instagram Reels need a Fal.ai API key to generate motion. Save it on Social settings. The plugin will not fall back to a slideshow of posters.'
            );
        }
        $video_budget = (float) ($settings['social_video_budget_usd'] ?? 0);
        if ($video_budget <= 0) {
            return new WP_Error(
                'tr_ai_wire_reel_video_budget',
                'Reel video generation is paused because the monthly video budget is 0. Raise it (default $10) or switch Instagram back to a still format.'
            );
        }
        $script = (string) ($copy['reel_script'] ?? '');
        if ($script === '') {
            $script = $this->fallback_reel_script($copy, $post);
        }
        $motion = $this->generate_social_reel_motion($post, $settings, $copy, $broll_path);
        if (is_wp_error($motion)) {
            return $motion;
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
        $overlays = array();
        $end_path = '';
        foreach ($timeline as $index => $clip) {
            if (($clip['kind'] ?? '') === 'end') {
                $jpeg = $this->render_social_reel_frame('end', $copy, $photo, $clip['caption']);
                if (is_wp_error($jpeg)) {
                    $this->social_reel_cleanup($tmp);
                    return $jpeg;
                }
                $end_path = $tmp . '/end.jpg';
                if (file_put_contents($end_path, $jpeg) === false) {
                    $this->social_reel_cleanup($tmp);
                    return new WP_Error('tr_ai_wire_reel_temp', 'Could not write the Reel end card.');
                }
                continue;
            }
            $overlay = $this->render_social_reel_caption_overlay($copy, $clip['caption']);
            if (is_wp_error($overlay) || !is_string($overlay) || $overlay === '') {
                continue;
            }
            $overlay_path = $tmp . '/caption-' . (string) $index . '.png';
            if (file_put_contents($overlay_path, $overlay) === false) {
                continue;
            }
            $overlays[] = array(
                'path' => $overlay_path,
                'duration' => (float) $clip['duration'],
            );
        }
        if ($end_path === '') {
            $this->social_reel_cleanup($tmp);
            return new WP_Error('tr_ai_wire_reel_frames', 'A Reel must end on a brand sting.');
        }
        $muxed = $this->compose_social_reel_motion($motion['path'], $overlays, $end_path, $voice_path, $out_path, $duration);
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

    private function generate_social_reel_motion($post, $settings, $copy, $broll_path) {
        $existing = $this->social_motion_id($post->ID);
        if ($existing > 0 && (string) get_post_meta($post->ID, '_tr_ai_wire_social_regenerate_image', true) !== '1') {
            $path = get_attached_file($existing);
            if ($path && file_exists($path)) {
                return array('id' => $existing, 'path' => $path);
            }
        }
        $pending = json_decode((string) get_post_meta($post->ID, '_tr_ai_wire_fal_request', true), true);
        if (is_array($pending) && !empty($pending['request_id']) && !empty($pending['endpoint'])) {
            $polled = $this->social_fal_await($pending['endpoint'], (string) $pending['request_id']);
            if (is_wp_error($polled)) {
                if ($polled->get_error_code() !== 'tr_ai_wire_reel_video_wait') {
                    delete_post_meta($post->ID, '_tr_ai_wire_fal_request');
                }
                return $polled;
            }
            return $this->store_social_motion($post, $polled, $copy);
        }
        $estimate = $this->social_reel_video_estimate_usd($settings);
        $month_key = 'tr_ai_wire_social_video_usage_' . gmdate('Ym');
        $usage = (float) get_option($month_key, 0);
        $budget = (float) ($settings['social_video_budget_usd'] ?? 0);
        if ($budget <= 0 || ($usage + $estimate) > $budget) {
            return new WP_Error('tr_ai_wire_reel_video_budget', 'Reel video generation is paused because its monthly video budget is exhausted.');
        }
        $model = $this->social_reel_video_model($settings);
        $models = $this->social_reel_video_models();
        $endpoint = (string) $models[$model]['endpoint'];
        $seconds = $this->social_reel_clip_seconds($settings);
        $image_url = $this->social_reel_motion_image_url($post, $broll_path);
        if (is_wp_error($image_url)) {
            return $image_url;
        }
        $brief = $this->social_image_scene_brief((string) ($copy['visual_brief'] ?? ''));
        $prompt = $brief !== '' ? $brief : 'Photoreal cinematic news B-roll of the story, slow camera move.';
        $prompt .= ' Slow cinematic push-in, natural light, no text, no logos, no watermarks, no subtitles, no captions.';
        $submitted = $this->social_fal_submit($endpoint, $this->social_fal_motion_payload($model, $image_url, $prompt, $seconds));
        if (is_wp_error($submitted)) {
            return $submitted;
        }
        update_option($month_key, round($usage + $estimate, 6), false);
        update_post_meta($post->ID, '_tr_ai_wire_fal_request', wp_json_encode(array(
            'request_id' => (string) $submitted['request_id'],
            'endpoint' => $endpoint,
            'estimated_usd' => $estimate,
        )));
        $polled = $this->social_fal_await($endpoint, (string) $submitted['request_id']);
        if (is_wp_error($polled)) {
            if ($polled->get_error_code() !== 'tr_ai_wire_reel_video_wait') {
                delete_post_meta($post->ID, '_tr_ai_wire_fal_request');
            }
            return $polled;
        }
        return $this->store_social_motion($post, $polled, $copy);
    }

    private function social_reel_motion_image_url($post, $broll_path) {
        $broll_id = $this->social_broll_id($post->ID);
        $url = $broll_id > 0 ? wp_get_attachment_url($broll_id) : '';
        if (is_string($url) && $url !== '' && wp_http_validate_url($url) && strpos($url, 'https://') === 0) {
            return $url;
        }
        $bytes = file_get_contents($broll_path);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('tr_ai_wire_reel_broll', 'The Reel B-roll file could not be read for motion generation.');
        }
        return 'data:image/jpeg;base64,' . base64_encode($bytes);
    }

    private function social_fal_submit($endpoint, $payload) {
        $key = $this->fal_api_key();
        if ($key === '') {
            return new WP_Error('tr_ai_wire_reel_fal_key', 'Instagram Reels need a Fal.ai API key to generate motion.');
        }
        $response = wp_remote_post('https://queue.fal.run/' . ltrim((string) $endpoint, '/'), array(
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Key ' . $key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_reel_video', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['request_id'])) {
            $message = sanitize_text_field($data['detail'] ?? ($data['error'] ?? ('Fal queue returned HTTP ' . $status . '.')));
            if (is_array($data['detail'] ?? null)) {
                $message = sanitize_text_field(wp_json_encode($data['detail']));
            }
            return new WP_Error('tr_ai_wire_reel_video', $message !== '' ? $message : 'Fal did not accept the video job.');
        }
        return $data;
    }

    private function social_fal_await($endpoint, $request_id) {
        $key = $this->fal_api_key();
        if ($key === '') {
            return new WP_Error('tr_ai_wire_reel_fal_key', 'Instagram Reels need a Fal.ai API key to generate motion.');
        }
        $request_id = rawurlencode((string) $request_id);
        $base = 'https://queue.fal.run/' . ltrim((string) $endpoint, '/') . '/requests/' . $request_id;
        $headers = array(
            'Authorization' => 'Key ' . $key,
            'Accept' => 'application/json',
        );
        $deadline = time() + max(0, (int) apply_filters('tr_ai_wire_fal_poll_seconds', 75));
        do {
            $status_response = wp_remote_get($base . '/status', array('timeout' => 30, 'headers' => $headers));
            if (is_wp_error($status_response)) {
                return new WP_Error('tr_ai_wire_reel_video', $status_response->get_error_message());
            }
            $payload = json_decode((string) wp_remote_retrieve_body($status_response), true);
            $state = strtoupper((string) ($payload['status'] ?? ''));
            if ($state === 'COMPLETED') {
                $result_response = wp_remote_get($base, array('timeout' => 45, 'headers' => $headers));
                if (is_wp_error($result_response)) {
                    return new WP_Error('tr_ai_wire_reel_video', $result_response->get_error_message());
                }
                $result = json_decode((string) wp_remote_retrieve_body($result_response), true);
                $video_url = (string) ($result['video']['url'] ?? '');
                if ($video_url === '' || !wp_http_validate_url($video_url)) {
                    return new WP_Error('tr_ai_wire_reel_video', 'Fal completed without a video URL.');
                }
                $download = wp_remote_get($video_url, array('timeout' => 60));
                if (is_wp_error($download)) {
                    return new WP_Error('tr_ai_wire_reel_video', $download->get_error_message());
                }
                $bytes = wp_remote_retrieve_body($download);
                if (!is_string($bytes) || strlen($bytes) < 1024) {
                    return new WP_Error('tr_ai_wire_reel_video', 'Fal returned an empty video file.');
                }
                return $bytes;
            }
            if (in_array($state, array('FAILED', 'CANCELLED', 'ERROR'), true)) {
                $message = sanitize_text_field($payload['error'] ?? ('Fal video job ' . strtolower($state) . '.'));
                return new WP_Error('tr_ai_wire_reel_video', $message);
            }
            if (time() >= $deadline) {
                return new WP_Error('tr_ai_wire_reel_video_wait', 'Fal is still generating the Reel motion. The job will retry shortly.');
            }
            sleep(4);
        } while (true);
    }

    private function store_social_motion($post, $bytes, $copy) {
        $name = 'technorapper-social-motion-' . (int) $post->ID . '.mp4';
        $upload = wp_upload_bits($name, null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('tr_ai_wire_social_video_upload', sanitize_text_field($upload['error']));
        }
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'video/mp4',
            'post_title' => sanitize_text_field((string) ($copy['hook'] ?? $post->post_title) . ' — reel motion'),
            'post_status' => 'inherit',
            'post_parent' => 0,
        ), $upload['file'], 0, true);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
        update_post_meta($post->ID, '_tr_ai_wire_social_motion_id', (int) $attachment_id);
        delete_post_meta($post->ID, '_tr_ai_wire_fal_request');
        $path = get_attached_file((int) $attachment_id);
        if (!$path || !file_exists($path)) {
            return new WP_Error('tr_ai_wire_reel_video', 'The generated motion clip could not be stored.');
        }
        return array('id' => (int) $attachment_id, 'path' => $path);
    }

    private function render_social_reel_caption_overlay($copy, $caption) {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return new WP_Error('tr_ai_wire_social_gd', 'PHP GD is required to render Reel captions.');
        }
        $width = 1080;
        $height = 1920;
        $copy = is_array($copy) ? $copy : array();
        $caption = trim(wp_strip_all_tags((string) $caption));
        $font = $this->social_font_path();
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        imagealphablending($canvas, true);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $cyan = imagecolorallocate($canvas, 43, 213, 255);
        $stroke = imagecolorallocate($canvas, 0, 0, 0);
        $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, 96);
        if ($caption !== '') {
            $size = $font ? 44 : 5;
            $lines = $this->wrap_social_lines($caption, 960, $size, $font, 28, 2);
            $y = $height - 160 - ((max(0, count($lines) - 1)) * (int) round($size * 1.16));
            foreach ($lines as $line) {
                $this->draw_social_centered_text($canvas, $line, $size, $y, $white, $font, $width, $stroke, 3);
                $y += (int) round($size * 1.16);
            }
        }
        ob_start();
        imagepng($canvas);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render a Reel caption overlay.');
    }

    private function compose_social_reel_motion($motion_path, $overlays, $end_path, $audio_path, $out_path, $audio_duration) {
        $ffmpeg = $this->social_ffmpeg_path();
        if ($ffmpeg === '') {
            return new WP_Error(
                'tr_ai_wire_reel_ffmpeg',
                'Instagram Reels need ffmpeg on this WordPress host. The plugin will not fall back to a slideshow of stills.'
            );
        }
        if (!is_string($motion_path) || $motion_path === '' || !file_exists($motion_path)) {
            return new WP_Error('tr_ai_wire_reel_video', 'Generated Reel motion is missing.');
        }
        if (!is_string($end_path) || $end_path === '' || !file_exists($end_path)) {
            return new WP_Error('tr_ai_wire_reel_frames', 'A Reel must end on a brand sting.');
        }
        if (!is_string($audio_path) || $audio_path === '' || !file_exists($audio_path)) {
            return new WP_Error('tr_ai_wire_reel_tts', 'A spoken voiceover is required. Silent poster clips are a slideshow.');
        }
        $motion_duration = $this->social_video_duration($motion_path);
        $audio_duration = max(8.0, (float) $audio_duration);
        $hold = max(0.0, round($audio_duration - $motion_duration, 2));
        $end = 2.0;
        $args = array('-y', '-i', $motion_path, '-i', $audio_path, '-loop', '1', '-t', number_format($end, 2, '.', ''), '-i', $end_path);
        $overlay_inputs = array();
        foreach (array_values((array) $overlays) as $overlay) {
            if (empty($overlay['path']) || !file_exists($overlay['path'])) {
                continue;
            }
            $args[] = '-i';
            $args[] = (string) $overlay['path'];
            $overlay_inputs[] = $overlay;
        }
        $filters = array();
        $base = '[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,setsar=1,format=yuv420p';
        if ($hold > 0.05) {
            $base .= ',tpad=stop_mode=clone:stop_duration=' . number_format($hold, 2, '.', '');
        }
        $filters[] = $base . '[base]';
        $last = 'base';
        $clock = 0.0;
        foreach ($overlay_inputs as $index => $overlay) {
            $input = $index + 3;
            $start = $clock;
            $clock += max(0.4, (float) ($overlay['duration'] ?? 2));
            $label = 's' . (string) $index;
            $filters[] = '[' . $last . '][' . (string) $input . ':v]overlay=0:0:enable=\'between(t,' . number_format($start, 2, '.', '') . ',' . number_format($clock, 2, '.', '') . ')\'[' . $label . ']';
            $last = $label;
        }
        $filters[] = '[2:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,setsar=1,format=yuv420p[end]';
        $filters[] = '[' . $last . '][end]concat=n=2:v=1:a=0[v]';
        $args[] = '-filter_complex';
        $args[] = implode(';', $filters);
        $args = array_merge($args, array(
            '-map', '[v]',
            '-map', '1:a',
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
        ));
        $result = $this->social_run_binary($ffmpeg, $args, 150);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!file_exists($out_path) || filesize($out_path) < 1024) {
            return new WP_Error('tr_ai_wire_reel_mux', 'ffmpeg did not produce a usable Reel video.');
        }
        return $out_path;
    }

    private function social_video_duration($path) {
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
                if ($seconds >= 1 && $seconds <= 60) {
                    return $seconds;
                }
            }
        }
        return (float) $this->social_reel_clip_seconds();
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

    private function social_run_binary($binary, $args, $timeout = 90) {
        if (!function_exists('proc_open')) {
            return new WP_Error('tr_ai_wire_reel_ffmpeg', 'proc_open is disabled, so this host cannot render Reels.');
        }
        $timeout = max(15, min(240, (int) $timeout));
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
        $deadline = time() + $timeout;
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
