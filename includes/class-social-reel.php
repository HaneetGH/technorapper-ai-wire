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
 * that follow the spoken words, Fal Veo motion from one B-roll still.
 * Motion loops instead of freezing. Story edit intercuts a talking host
 * with captioned B-roll cutaways, then a short brand sting.
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
        if ((string) get_post_meta($video_id, '_tr_ai_wire_social_layout', true) !== $this->social_reel_layout_key()) {
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

    private function social_voice_id($post_id) {
        $post_id = (int) $post_id;
        $audio_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_voice_id', true);
        if ($audio_id < 1) {
            return 0;
        }
        $path = get_attached_file($audio_id);
        return ($path && file_exists($path)) ? $audio_id : 0;
    }

    private function social_reel_kit_id($post_id) {
        $post_id = (int) $post_id;
        $kit_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_reel_kit_id', true);
        if ($kit_id < 1) {
            return 0;
        }
        $path = get_attached_file($kit_id);
        return ($path && file_exists($path)) ? $kit_id : 0;
    }

    private function social_host_id($post_id) {
        $post_id = (int) $post_id;
        $video_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_host_id', true);
        if ($video_id < 1) {
            return 0;
        }
        $path = get_attached_file($video_id);
        if (!$path || !file_exists($path)) {
            return 0;
        }
        $sig = (string) get_post_meta($video_id, '_tr_ai_wire_social_host_model', true);
        if ($sig !== '' && $sig !== $this->social_reel_host_signature()) {
            return 0;
        }
        return $video_id;
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

    private function social_reel_host_models() {
        return array(
            'kling-avatar-std' => array(
                'label' => 'Kling Avatar Standard',
                'short' => 'Cheapest talking host',
                'recommended' => false,
                'endpoint' => 'fal-ai/kling-video/ai-avatar/v2/standard',
                'usd_per_second' => 0.0562,
                'docs' => 'https://fal.ai/models/fal-ai/kling-video/ai-avatar/v2/standard',
                'blurb' => 'Lip-syncs a still portrait to the Mini TTS voiceover. Use when the host is on camera.',
            ),
            'kling-avatar-pro' => array(
                'label' => 'Kling Avatar Pro',
                'short' => 'Suggested talking host',
                'recommended' => true,
                'endpoint' => 'fal-ai/kling-video/ai-avatar/v2/pro',
                'usd_per_second' => 0.115,
                'docs' => 'https://fal.ai/models/fal-ai/kling-video/ai-avatar/v2/pro',
                'blurb' => 'Sharper face and mouth — the avatar-face upsell. Pair with Veo Fast B-roll on a $40 video budget at two Reels a week.',
            ),
        );
    }

    private function social_reel_tts_voices() {
        return array(
            'cedar' => array(
                'label' => 'Cedar',
                'gender' => 'male',
                'short' => 'Default news desk',
            ),
            'ash' => array(
                'label' => 'Ash',
                'gender' => 'male',
                'short' => 'Clear and lower',
            ),
            'echo' => array(
                'label' => 'Echo',
                'gender' => 'male',
                'short' => 'Steady, mid',
            ),
            'onyx' => array(
                'label' => 'Onyx',
                'gender' => 'male',
                'short' => 'Deeper, grounded',
            ),
            'verse' => array(
                'label' => 'Verse',
                'gender' => 'male',
                'short' => 'Warm presenter',
            ),
            'fable' => array(
                'label' => 'Fable',
                'gender' => 'male',
                'short' => 'British, story-like',
            ),
            'ballad' => array(
                'label' => 'Ballad',
                'gender' => 'male',
                'short' => 'Warm, expressive',
            ),
            'marin' => array(
                'label' => 'Marin',
                'gender' => 'female',
                'short' => 'Bright presenter',
            ),
            'sage' => array(
                'label' => 'Sage',
                'gender' => 'female',
                'short' => 'Calm, measured',
            ),
            'coral' => array(
                'label' => 'Coral',
                'gender' => 'female',
                'short' => 'Clear, conversational',
            ),
            'nova' => array(
                'label' => 'Nova',
                'gender' => 'female',
                'short' => 'Crisp, upbeat',
            ),
            'shimmer' => array(
                'label' => 'Shimmer',
                'gender' => 'female',
                'short' => 'Bright, energetic',
            ),
            'alloy' => array(
                'label' => 'Alloy',
                'gender' => 'neutral',
                'short' => 'Androgynous news',
            ),
        );
    }

    private function social_reel_tts_voice_groups() {
        return array(
            'male' => 'Male voices',
            'female' => 'Female voices',
            'neutral' => 'Neutral voices',
        );
    }

    private function social_reel_tts_voice_spec($voice) {
        $voices = $this->social_reel_tts_voices();
        $voice = sanitize_key((string) $voice);
        if (!isset($voices[$voice]) || !is_array($voices[$voice])) {
            $voice = self::SOCIAL_TTS_VOICE;
        }
        return $voices[$voice];
    }

    private function social_reel_tts_voice_label($voice) {
        $spec = $this->social_reel_tts_voice_spec($voice);
        $gender = (string) ($spec['gender'] ?? 'neutral');
        $label = (string) ($spec['label'] ?? $voice);
        return $label . ' (' . $gender . ')';
    }

    private function social_reel_tts_instructions($settings = null) {
        $spec = $this->social_reel_tts_voice_spec($this->social_reel_tts_voice($settings));
        $gender = (string) ($spec['gender'] ?? 'neutral');
        if ($gender === 'female') {
            $cast = 'Female news presenter.';
        } elseif ($gender === 'male') {
            $cast = 'Male news presenter.';
        } else {
            $cast = 'News presenter.';
        }
        return $cast . ' Clear, calm, slightly urgent. No theatrical announcer. Speak so a listener can repeat the takeaway.';
    }

    private function social_reel_recipe($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $recipe = sanitize_key((string) ($settings['social_reel_recipe'] ?? 'broll'));
        return $recipe === 'story' ? 'story' : 'broll';
    }

    private function social_reel_host_model($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $model = sanitize_key((string) ($settings['social_reel_host_model'] ?? 'kling-avatar-pro'));
        $models = $this->social_reel_host_models();
        return isset($models[$model]) ? $model : 'kling-avatar-pro';
    }

    private function social_reel_tts_voice($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $voice = sanitize_key((string) ($settings['social_reel_tts_voice'] ?? self::SOCIAL_TTS_VOICE));
        $voices = $this->social_reel_tts_voices();
        return isset($voices[$voice]) ? $voice : self::SOCIAL_TTS_VOICE;
    }

    private function social_reel_target_seconds($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $target = absint($settings['social_reel_target_seconds'] ?? 18);
        return in_array($target, array(15, 18, 30, 45), true) ? $target : 18;
    }

    private function social_reel_script_brief($settings = null) {
        $target = $this->social_reel_target_seconds($settings);
        if ($target >= 40) {
            return '110–140 spoken words, about 40–45 seconds.';
        }
        if ($target >= 28) {
            return '80–110 spoken words, about 30 seconds.';
        }
        return '40–55 spoken words, about 15–18 seconds.';
    }

    private function social_reel_host_seconds($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        return min(45, max(8, absint($settings['social_reel_host_seconds'] ?? 20)));
    }

    private function social_reel_sting_seconds($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        return min(4, max(1, absint($settings['social_reel_sting_seconds'] ?? 2)));
    }

    private function social_reel_fact_slides($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        return min(4, max(1, absint($settings['social_reel_fact_slides'] ?? 2)));
    }

    private function social_reel_layout_key($settings = null) {
        return $this->social_reel_recipe($settings) === 'story' ? self::SOCIAL_REEL_STORY_LAYOUT : self::SOCIAL_REEL_LAYOUT;
    }

    private function social_reel_host_estimate_usd($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        if ($this->social_reel_recipe($settings) !== 'story' || empty($settings['social_reel_include_host'])) {
            return 0.0;
        }
        $models = $this->social_reel_host_models();
        $model = $this->social_reel_host_model($settings);
        $rate = (float) ($models[$model]['usd_per_second'] ?? 0.115);
        $face = !empty($settings['social_reel_enhance_host_face']) ? self::SOCIAL_REEL_FACE_ENHANCE_USD : 0.0;
        return round(($this->social_reel_host_seconds($settings) * $rate) + $face, 4);
    }

    private function social_reel_host_signature($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        return $this->social_reel_host_model($settings)
            . '|' . (!empty($settings['social_reel_enhance_host_face']) ? 'enhance' : 'raw')
            . '|' . absint($settings['social_reel_host_image_id'] ?? 0)
            . '|' . (!empty($settings['social_reel_generate_host_face']) ? 'gen' : 'upload');
    }

    private function social_reel_total_estimate_usd($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $motion = 0.0;
        $recipe = $this->social_reel_recipe($settings);
        if ($recipe === 'broll' || !empty($settings['social_reel_include_broll'])) {
            $motion = $this->social_reel_video_estimate_usd($settings);
        }
        return round($motion + $this->social_reel_host_estimate_usd($settings), 4);
    }

    private function social_reel_recipe_summary($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $recipe = $this->social_reel_recipe($settings);
        $models = $this->social_reel_video_models();
        $model = $this->social_reel_video_model($settings);
        $voice = $this->social_reel_tts_voice($settings);
        $parts = array();
        if ($recipe === 'story') {
            $parts[] = 'Story edit';
            if (!empty($settings['social_reel_include_host'])) {
                $hosts = $this->social_reel_host_models();
                $host = $this->social_reel_host_model($settings);
                $parts[] = (string) ($hosts[$host]['label'] ?? 'Talking host');
                $parts[] = 'same host face';
                if (!empty($settings['social_reel_enhance_host_face'])) {
                    $parts[] = 'face enhance';
                }
            } else {
                $parts[] = 'no talking host';
            }
            if (!isset($settings['social_reel_include_broll']) || !empty($settings['social_reel_include_broll'])) {
                $parts[] = 'captioned B-roll cuts';
            }
            if (!isset($settings['social_reel_include_sting']) || !empty($settings['social_reel_include_sting'])) {
                $parts[] = 'sting';
            }
        } else {
            $parts[] = 'B-roll spoken Reel';
        }
        $parts[] = (string) ($models[$model]['label'] ?? $model);
        $parts[] = (string) $this->social_reel_target_seconds($settings) . 's · ' . $this->social_reel_tts_voice_label($voice);
        return implode(' · ', $parts);
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

    private function build_social_reel_timeline($copy, $duration, $settings = null) {
        $copy = is_array($copy) ? $copy : array();
        $max = (float) $this->social_reel_target_seconds($settings) + 12;
        $max = max(24.0, min(60.0, $max));
        $duration = max(12.0, min($max, (float) $duration));
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
        $end = (float) $this->social_reel_sting_seconds($settings);
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
        } elseif ($kind === 'slide') {
            $this->draw_social_brand_mark($canvas, $font, $white, $cyan, 48, 96);
            $kicker = trim(wp_strip_all_tags((string) ($copy['on_image_kicker'] ?? 'TECH NEWS')));
            if ($kicker !== '') {
                $this->draw_social_centered_text($canvas, $kicker, $font ? 28 : 5, 280, $cyan, $font, $width, $stroke, 2);
            }
            $title = $caption !== '' ? $caption : trim((string) ($copy['on_image_headline'] ?? $copy['hook'] ?? ''));
            $size = $font ? 56 : 5;
            $lines = $this->wrap_social_lines($title, 920, $size, $font, 18, 4);
            $y = 380;
            foreach ($lines as $line) {
                $this->draw_social_centered_text($canvas, $line, $size, $y, $white, $font, $width, $stroke, 3);
                $y += (int) round($size * 1.14);
            }
            $y += 36;
            foreach ($this->social_reel_slide_bullets($copy) as $bullet) {
                $this->draw_social_centered_text($canvas, $bullet, $font ? 32 : 5, $y, $muted, $font, $width, $stroke, 2);
                $y += 56;
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
        if ($this->social_reel_recipe($settings) === 'story') {
            return $this->ensure_social_reel_story($post, $settings, $copy, $broll_path);
        }
        if (!$this->social_reel_can_mux()) {
            return $this->social_reel_mux_error();
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
        $this->store_social_voice($post, $audio, $copy);
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
        $duration = $this->social_audio_duration($voice_path, $script, $settings);
        $timeline = $this->build_social_reel_timeline($copy, $duration, $settings);
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
            $kit = $this->store_social_reel_laptop_kit($post, array(
                'motion.mp4' => $motion['path'],
                'voice.mp3' => $voice_path,
                'end.jpg' => $end_path,
            ), $duration, $copy);
            $this->social_reel_cleanup($tmp);
            if (!is_wp_error($kit) && $this->social_ffmpeg_path() === '') {
                return $this->social_reel_laptop_hold_error();
            }
            return $muxed;
        }
        $bytes = file_get_contents($out_path);
        $this->social_reel_cleanup($tmp);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('tr_ai_wire_reel_mux', 'ffmpeg did not produce a Reel video.');
        }
        $stored = $this->store_social_video($post, $bytes, $copy, $script, $settings);
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

    private function social_reel_slide_bullets($copy) {
        $copy = is_array($copy) ? $copy : array();
        $bullets = array();
        foreach ((array) ($copy['on_image_bullets'] ?? array()) as $bullet) {
            $line = trim(wp_strip_all_tags(str_replace('*', '', (string) $bullet)));
            if ($line !== '') {
                $bullets[] = $this->clip_social_text($line, 72);
            }
            if (count($bullets) >= 3) {
                break;
            }
        }
        if (count($bullets) < 2) {
            foreach ((array) ($copy['reel_captions'] ?? array()) as $line) {
                $line = trim(wp_strip_all_tags((string) $line));
                if ($line !== '' && !in_array($line, $bullets, true)) {
                    $bullets[] = $this->clip_social_text($line, 72);
                }
                if (count($bullets) >= 3) {
                    break;
                }
            }
        }
        return array_slice($bullets, 0, 3);
    }

    private function social_reel_slide_titles($copy) {
        $copy = is_array($copy) ? $copy : array();
        $titles = array();
        foreach (array($copy['on_image_headline'] ?? '', $copy['on_image_subhead'] ?? '', $copy['hook'] ?? '') as $line) {
            $line = trim(wp_strip_all_tags((string) $line));
            if ($line !== '' && !in_array($line, $titles, true)) {
                $titles[] = $line;
            }
        }
        if (!$titles) {
            $titles[] = 'Here is what changed';
        }
        return $titles;
    }

    private function ensure_social_reel_story($post, $settings, $copy, $broll_path) {
        if (!$this->social_reel_can_mux()) {
            return $this->social_reel_mux_error();
        }
        $want_host = !empty($settings['social_reel_include_host']);
        $want_slides = !empty($settings['social_reel_include_slides']);
        $want_broll = !empty($settings['social_reel_include_broll']);
        $want_sting = array_key_exists('social_reel_include_sting', $settings) ? !empty($settings['social_reel_include_sting']) : true;
        if (!$want_host && !$want_slides && !$want_broll) {
            $want_broll = true;
        }
        if ($want_host && absint($settings['social_reel_host_image_id'] ?? 0) < 1 && empty($settings['social_reel_generate_host_face'])) {
            return new WP_Error(
                'tr_ai_wire_reel_host_image',
                'Story Reels need an avatar face. Upload a portrait, turn on Generate a presenter face, or turn the talking host off. One saved face is used on every Reel.'
            );
        }
        if (($want_host || $want_broll) && $this->fal_api_key() === '') {
            return new WP_Error(
                'tr_ai_wire_reel_fal_key',
                'Story Reels need a Fal.ai API key for the talking host and B-roll motion.'
            );
        }
        if ($want_slides) {
            $want_broll = true;
        }
        if ($want_broll && (!is_string($broll_path) || $broll_path === '' || !file_exists($broll_path))) {
            return new WP_Error('tr_ai_wire_reel_broll', 'A text-free 9:16 B-roll frame is required before rendering the Reel.');
        }
        $video_budget = (float) ($settings['social_video_budget_usd'] ?? 0);
        $estimate = $this->social_reel_total_estimate_usd($settings);
        $month_key = 'tr_ai_wire_social_video_usage_' . gmdate('Ym');
        $usage = (float) get_option($month_key, 0);
        if ($video_budget <= 0 || ($usage + $estimate) > $video_budget) {
            return new WP_Error(
                'tr_ai_wire_reel_video_budget',
                'Reel video generation is paused because the monthly video budget is exhausted. Raise it on Social settings.'
            );
        }
        $script = (string) ($copy['reel_script'] ?? '');
        if ($script === '') {
            $script = $this->fallback_reel_script($copy, $post);
        }
        $audio = $this->generate_social_tts($script, $settings);
        if (is_wp_error($audio)) {
            return $audio;
        }
        $this->store_social_voice($post, $audio, $copy);
        $host = null;
        if ($want_host) {
            $host = $this->generate_social_reel_host($post, $settings, $copy, $audio);
            if (is_wp_error($host)) {
                return $host;
            }
        }
        $motion = null;
        if ($want_broll) {
            $motion = $this->generate_social_reel_motion($post, $settings, $copy, $broll_path);
            if (is_wp_error($motion)) {
                return $motion;
            }
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
        $duration = $this->social_audio_duration($voice_path, $script, $settings);
        $timeline = $this->build_social_reel_story_timeline($copy, $duration, $settings);
        $clips = array();
        foreach ($timeline as $index => $shot) {
            $kind = (string) ($shot['kind'] ?? '');
            if ($kind === 'host') {
                if (!$host || empty($host['path'])) {
                    $this->social_reel_cleanup($tmp);
                    return new WP_Error('tr_ai_wire_reel_host', 'The talking-host clip is missing.');
                }
                $clips[] = array(
                    'kind' => 'host',
                    'path' => $host['path'],
                    'duration' => (float) $shot['duration'],
                    'crop' => (string) ($shot['crop'] ?? 'medium'),
                    'overlay' => $this->social_reel_write_caption_overlay($tmp, $index, $copy, (string) ($shot['caption'] ?? '')),
                );
                continue;
            }
            if ($kind === 'broll') {
                if (!$motion || empty($motion['path'])) {
                    $this->social_reel_cleanup($tmp);
                    return new WP_Error('tr_ai_wire_reel_video', 'Generated Reel motion is missing.');
                }
                $clips[] = array(
                    'kind' => 'broll',
                    'path' => $motion['path'],
                    'duration' => (float) $shot['duration'],
                    'crop' => (string) ($shot['crop'] ?? 'full'),
                    'overlay' => $this->social_reel_write_caption_overlay($tmp, $index, $copy, (string) ($shot['caption'] ?? '')),
                );
                continue;
            }
            if ($kind !== 'end') {
                continue;
            }
            $jpeg = $this->render_social_reel_frame('end', $copy, '', (string) ($shot['caption'] ?? ''));
            if (is_wp_error($jpeg)) {
                $this->social_reel_cleanup($tmp);
                return $jpeg;
            }
            $frame_path = $tmp . '/shot-' . (string) $index . '.jpg';
            if (file_put_contents($frame_path, $jpeg) === false) {
                $this->social_reel_cleanup($tmp);
                return new WP_Error('tr_ai_wire_reel_temp', 'Could not write a Reel story frame.');
            }
            $clips[] = array(
                'kind' => 'end',
                'path' => $frame_path,
                'duration' => (float) $shot['duration'],
                'crop' => 'full',
            );
        }
        if ($want_sting) {
            $has_end = false;
            foreach ($clips as $clip) {
                if (($clip['kind'] ?? '') === 'end') {
                    $has_end = true;
                    break;
                }
            }
            if (!$has_end) {
                $this->social_reel_cleanup($tmp);
                return new WP_Error('tr_ai_wire_reel_frames', 'A Reel must end on a brand sting.');
            }
        }
        $muxed = $this->compose_social_reel_story($clips, $voice_path, $out_path);
        if (is_wp_error($muxed)) {
            $kit_files = array('voice.mp3' => $voice_path);
            if ($host && !empty($host['path'])) {
                $kit_files['host.mp4'] = $host['path'];
            }
            if ($motion && !empty($motion['path'])) {
                $kit_files['motion.mp4'] = $motion['path'];
            }
            foreach ($clips as $clip) {
                if (($clip['kind'] ?? '') === 'end' && !empty($clip['path'])) {
                    $kit_files['end.jpg'] = $clip['path'];
                    break;
                }
            }
            $kit = $this->store_social_reel_laptop_kit($post, $kit_files, $duration, $copy);
            $this->social_reel_cleanup($tmp);
            if (!is_wp_error($kit) && $this->social_ffmpeg_path() === '') {
                return $this->social_reel_laptop_hold_error();
            }
            return $muxed;
        }
        $bytes = file_get_contents($out_path);
        $this->social_reel_cleanup($tmp);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('tr_ai_wire_reel_mux', 'ffmpeg did not produce a Reel video.');
        }
        $stored = $this->store_social_video($post, $bytes, $copy, $script, $settings);
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

    private function social_reel_cutaway_captions($copy) {
        $copy = is_array($copy) ? $copy : array();
        $lines = array();
        foreach ((array) ($copy['reel_captions'] ?? array()) as $line) {
            $line = trim(wp_strip_all_tags((string) $line));
            if ($line !== '' && !in_array($line, $lines, true)) {
                $lines[] = $this->clip_social_text($line, 72);
            }
        }
        if (count($lines) < 2) {
            foreach ($this->social_reel_slide_titles($copy) as $title) {
                if ($title !== '' && !in_array($title, $lines, true)) {
                    $lines[] = $title;
                }
            }
        }
        return array_slice($lines, 0, 4);
    }

    private function social_reel_write_caption_overlay($tmp, $index, $copy, $caption) {
        $caption = trim(wp_strip_all_tags((string) $caption));
        if ($caption === '' || !is_string($tmp) || $tmp === '') {
            return '';
        }
        $overlay = $this->render_social_reel_caption_overlay($copy, $caption);
        if (is_wp_error($overlay) || !is_string($overlay) || $overlay === '') {
            return '';
        }
        $path = $tmp . '/caption-' . (string) $index . '.png';
        if (file_put_contents($path, $overlay) === false) {
            return '';
        }
        return $path;
    }

    private function build_social_reel_story_timeline($copy, $duration, $settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $copy = is_array($copy) ? $copy : array();
        $max = max(24.0, min(60.0, (float) $this->social_reel_target_seconds($settings) + 12));
        $duration = max(12.0, min($max, (float) $duration));
        $want_host = !empty($settings['social_reel_include_host']);
        $want_slides = !empty($settings['social_reel_include_slides']);
        $want_broll = !empty($settings['social_reel_include_broll']) || $want_slides;
        $want_sting = array_key_exists('social_reel_include_sting', $settings) ? !empty($settings['social_reel_include_sting']) : true;
        $close = $want_host && !empty($settings['social_reel_close_camera']);
        $medium = $want_host && !empty($settings['social_reel_medium_camera']);
        if ($want_host && !$close && !$medium) {
            $close = true;
        }
        $sting = $want_sting ? (float) $this->social_reel_sting_seconds($settings) : 0.0;
        $left = max(8.0, $duration - $sting);
        $captions = $this->social_reel_cutaway_captions($copy);
        $weights = array();
        if ($close) {
            $weights[] = array('kind' => 'host', 'crop' => 'close', 'weight' => 1.1, 'caption' => '');
        }
        $broll_n = 0;
        if ($want_broll) {
            $broll_n = max(2, count($captions));
            if ($want_slides) {
                $broll_n = max($broll_n, $this->social_reel_fact_slides($settings));
            }
            $broll_n = min(4, $broll_n);
        }
        $inserted_medium = false;
        for ($i = 0; $i < $broll_n; $i++) {
            if ($medium && !$inserted_medium && $i === 1) {
                $weights[] = array('kind' => 'host', 'crop' => 'medium', 'weight' => 1.2, 'caption' => '');
                $inserted_medium = true;
            }
            $weights[] = array(
                'kind' => 'broll',
                'crop' => ($i % 2 === 1) ? 'close' : 'full',
                'weight' => $i === 0 ? 1.4 : 1.0,
                'caption' => $captions[$i] ?? ($captions[0] ?? ''),
            );
        }
        if ($medium && !$inserted_medium) {
            $weights[] = array('kind' => 'host', 'crop' => 'medium', 'weight' => 1.2, 'caption' => '');
        }
        if (!$weights && $want_host) {
            $weights[] = array('kind' => 'host', 'crop' => $close ? 'close' : 'medium', 'weight' => 1, 'caption' => '');
        }
        if (!$weights) {
            $weights[] = array('kind' => 'broll', 'crop' => 'full', 'weight' => 1, 'caption' => $captions[0] ?? '');
        }
        $total_weight = 0.0;
        foreach ($weights as $row) {
            $total_weight += (float) $row['weight'];
        }
        $clips = array();
        foreach ($weights as $row) {
            $clips[] = array(
                'kind' => $row['kind'],
                'crop' => $row['crop'] ?? 'full',
                'caption' => $row['caption'] ?? '',
                'duration' => round(max(1.2, $left * ((float) $row['weight'] / max(0.01, $total_weight))), 2),
            );
        }
        if ($want_sting) {
            $clips[] = array(
                'kind' => 'end',
                'caption' => (string) ($copy['cta'] ?? ('Full story on ' . self::site_brand_name())),
                'duration' => $sting,
            );
        }
        return $clips;
    }

    private function generate_social_reel_host($post, $settings, $copy, $audio_bytes) {
        $existing = $this->social_host_id($post->ID);
        if ($existing > 0 && (string) get_post_meta($post->ID, '_tr_ai_wire_social_regenerate_image', true) !== '1') {
            $path = get_attached_file($existing);
            if ($path && file_exists($path)) {
                return array('id' => $existing, 'path' => $path);
            }
        }
        $pending = json_decode((string) get_post_meta($post->ID, '_tr_ai_wire_fal_host_request', true), true);
        if (is_array($pending) && !empty($pending['request_id']) && !empty($pending['endpoint'])) {
            $polled = $this->social_fal_await($pending['endpoint'], (string) $pending['request_id']);
            if (is_wp_error($polled)) {
                if ($polled->get_error_code() !== 'tr_ai_wire_reel_video_wait') {
                    delete_post_meta($post->ID, '_tr_ai_wire_fal_host_request');
                }
                return $polled;
            }
            return $this->store_social_host($post, $polled, $copy);
        }
        $estimate = $this->social_reel_host_estimate_usd($settings);
        $month_key = 'tr_ai_wire_social_video_usage_' . gmdate('Ym');
        $usage = (float) get_option($month_key, 0);
        $budget = (float) ($settings['social_video_budget_usd'] ?? 0);
        if ($budget <= 0 || ($usage + $estimate) > $budget) {
            return new WP_Error('tr_ai_wire_reel_video_budget', 'Reel video generation is paused because its monthly video budget is exhausted.');
        }
        $image_url = $this->social_reel_host_face_url($settings);
        if (is_wp_error($image_url)) {
            return $image_url;
        }
        $audio_url = $this->social_reel_host_audio_url($audio_bytes);
        if (is_wp_error($audio_url)) {
            return $audio_url;
        }
        $model = $this->social_reel_host_model($settings);
        $models = $this->social_reel_host_models();
        $endpoint = (string) $models[$model]['endpoint'];
        $submitted = $this->social_fal_submit($endpoint, array(
            'image_url' => $image_url,
            'audio_url' => $audio_url,
            'prompt' => 'News presenter speaking to camera, natural blinks, subtle head motion, no extra people, no text, no logos.',
        ));
        if (is_wp_error($submitted)) {
            return $submitted;
        }
        update_option($month_key, round($usage + $estimate, 6), false);
        update_post_meta($post->ID, '_tr_ai_wire_fal_host_request', wp_json_encode(array(
            'request_id' => (string) $submitted['request_id'],
            'endpoint' => $endpoint,
            'estimated_usd' => $estimate,
        )));
        $polled = $this->social_fal_await($endpoint, (string) $submitted['request_id']);
        if (is_wp_error($polled)) {
            if ($polled->get_error_code() !== 'tr_ai_wire_reel_video_wait') {
                delete_post_meta($post->ID, '_tr_ai_wire_fal_host_request');
            }
            return $polled;
        }
        return $this->store_social_host($post, $polled, $copy);
    }

    private function social_reel_host_face_url($settings) {
        $image_id = $this->ensure_social_reel_host_portrait($settings);
        if (is_wp_error($image_id)) {
            return $image_id;
        }
        if (!empty($settings['social_reel_enhance_host_face'])) {
            $enhanced = $this->ensure_social_reel_host_face_enhanced((int) $image_id, $settings);
            if (is_wp_error($enhanced)) {
                return $enhanced;
            }
            $image_id = (int) $enhanced;
        }
        return $this->social_reel_attachment_data_url((int) $image_id, 'tr_ai_wire_reel_host_image', 'The host portrait could not be read.');
    }

    private function saved_social_reel_host_image_id() {
        $stored = get_option(self::OPTION, array());
        $image_id = absint(is_array($stored) ? ($stored['social_reel_host_image_id'] ?? 0) : 0);
        return ($image_id > 0 && $this->social_image_file_exists($image_id)) ? $image_id : 0;
    }

    private function persist_social_reel_host_image_id($image_id) {
        $image_id = absint($image_id);
        $stored = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        $stored['social_reel_host_image_id'] = $image_id;
        update_option(self::OPTION, $stored, false);
    }

    private function ensure_social_reel_host_portrait($settings) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $image_id = $this->saved_social_reel_host_image_id();
        if ($image_id > 0) {
            return $image_id;
        }
        $fallback = absint($settings['social_reel_host_image_id'] ?? 0);
        if ($fallback > 0 && $this->social_image_file_exists($fallback)) {
            $this->persist_social_reel_host_image_id($fallback);
            return $fallback;
        }
        if (empty($settings['social_reel_generate_host_face'])) {
            return new WP_Error(
                'tr_ai_wire_reel_host_image',
                'Story Reels need an avatar face. Upload a portrait on Social settings, or turn on Generate a presenter face. That one face is reused on every Reel.'
            );
        }
        $lock = 'tr_ai_wire_host_face_lock';
        if (!add_option($lock, time(), '', false)) {
            $locked = (int) get_option($lock, 0);
            if ($locked && $locked < time() - 120) {
                delete_option($lock);
            }
            if (!add_option($lock, time(), '', false)) {
                $image_id = $this->saved_social_reel_host_image_id();
                if ($image_id > 0) {
                    return $image_id;
                }
                return new WP_Error('tr_ai_wire_reel_host_image', 'Another worker is saving the show avatar. Retry this Reel in a minute.');
            }
        }
        try {
            $image_id = $this->saved_social_reel_host_image_id();
            if ($image_id > 0) {
                return $image_id;
            }
            $generated = $this->generate_social_reel_host_portrait($settings);
            if (is_wp_error($generated)) {
                return $generated;
            }
            $this->persist_social_reel_host_image_id((int) $generated);
            return (int) $generated;
        } finally {
            delete_option($lock);
        }
    }

    private function generate_social_reel_host_portrait($settings) {
        $month_key = 'tr_ai_wire_social_image_usage_' . gmdate('Ym');
        $usage = (float) get_option($month_key, 0);
        $budget = (float) ($settings['social_image_budget_usd'] ?? 0);
        if ($budget <= 0 || ($usage + self::SOCIAL_IMAGE_ESTIMATED_USD) > $budget) {
            return new WP_Error('tr_ai_wire_social_image_budget', 'Social image generation is paused because its monthly image budget is exhausted.');
        }
        $key = $this->api_key();
        if (!$key) {
            return new WP_Error('tr_ai_wire_social_image_key', 'Add the OpenAI API key before generating an avatar face.');
        }
        $prompt = $this->social_reel_host_portrait_prompt($settings);
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
            return new WP_Error('tr_ai_wire_reel_host_image', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($data['data'][0]['b64_json'])) {
            $message = sanitize_text_field($data['error']['message'] ?? ('Image API returned HTTP ' . $status . '.'));
            return new WP_Error('tr_ai_wire_reel_host_image', $message);
        }
        $bytes = base64_decode($data['data'][0]['b64_json'], true);
        if (!$bytes) {
            return new WP_Error('tr_ai_wire_reel_host_image', 'OpenAI returned an invalid avatar-face image.');
        }
        update_option($month_key, round($usage + self::SOCIAL_IMAGE_ESTIMATED_USD, 6), false);
        return $this->store_social_host_face($bytes, 'technorapper-social-host-face.jpg', 'Generated news presenter');
    }

    private function social_reel_host_portrait_prompt($settings = null) {
        $spec = $this->social_reel_tts_voice_spec($this->social_reel_tts_voice($settings));
        $gender = (string) ($spec['gender'] ?? 'neutral');
        if ($gender === 'female') {
            $who = 'a fictional adult female news presenter';
        } elseif ($gender === 'male') {
            $who = 'a fictional adult male news presenter';
        } else {
            $who = 'a fictional adult news presenter';
        }
        return 'Photoreal head-and-shoulders portrait of ' . $who . ', 28-42 years old, looking straight at camera, even studio lighting, plain dark backdrop, wearing a simple fully clothed blazer. Neutral expression ready to speak. This is a single recurring show host, not a new person. No logos, no text, no watermarks, no lower-third, no celebrity likeness, no real person. Vertical 4:5 photograph, sharp face, natural skin.';
    }

    private function ensure_social_reel_host_face_enhanced($image_id, $settings) {
        $image_id = (int) $image_id;
        $existing = (int) get_post_meta($image_id, '_tr_ai_wire_host_face_enhanced_id', true);
        if ($existing > 0 && $this->social_image_file_exists($existing)) {
            return $existing;
        }
        $pending = json_decode((string) get_post_meta($image_id, '_tr_ai_wire_fal_face_request', true), true);
        if (is_array($pending) && !empty($pending['request_id']) && !empty($pending['endpoint'])) {
            $polled = $this->social_fal_await($pending['endpoint'], (string) $pending['request_id']);
            if (is_wp_error($polled)) {
                if ($polled->get_error_code() !== 'tr_ai_wire_reel_video_wait') {
                    delete_post_meta($image_id, '_tr_ai_wire_fal_face_request');
                }
                return $polled;
            }
            return $this->store_enhanced_host_face($image_id, $polled);
        }
        $source = $this->social_reel_attachment_data_url($image_id, 'tr_ai_wire_reel_host_image', 'The host portrait could not be read for face enhancement.');
        if (is_wp_error($source)) {
            return $source;
        }
        $submitted = $this->social_fal_submit('fal-ai/codeformer', array(
            'image_url' => $source,
            'fidelity' => 0.6,
            'upscale_factor' => 2,
            'face_upscale' => true,
            'only_center_face' => true,
        ));
        if (is_wp_error($submitted)) {
            return $submitted;
        }
        update_post_meta($image_id, '_tr_ai_wire_fal_face_request', wp_json_encode(array(
            'request_id' => (string) $submitted['request_id'],
            'endpoint' => 'fal-ai/codeformer',
            'estimated_usd' => self::SOCIAL_REEL_FACE_ENHANCE_USD,
        )));
        $polled = $this->social_fal_await('fal-ai/codeformer', (string) $submitted['request_id']);
        if (is_wp_error($polled)) {
            if ($polled->get_error_code() !== 'tr_ai_wire_reel_video_wait') {
                delete_post_meta($image_id, '_tr_ai_wire_fal_face_request');
            }
            return $polled;
        }
        return $this->store_enhanced_host_face($image_id, $polled);
    }

    private function store_enhanced_host_face($source_id, $bytes) {
        $stored = $this->store_social_host_face($bytes, 'technorapper-social-host-face-enhanced.jpg', 'Enhanced news presenter');
        if (is_wp_error($stored)) {
            return $stored;
        }
        update_post_meta((int) $source_id, '_tr_ai_wire_host_face_enhanced_id', (int) $stored);
        delete_post_meta((int) $source_id, '_tr_ai_wire_fal_face_request');
        return (int) $stored;
    }

    private function store_social_host_face($bytes, $filename, $title) {
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('tr_ai_wire_reel_host_image', 'The avatar face file was empty.');
        }
        $upload = wp_upload_bits($filename, null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('tr_ai_wire_reel_host_image', sanitize_text_field($upload['error']));
        }
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'image/jpeg',
            'post_title' => sanitize_text_field($title),
            'post_status' => 'inherit',
            'post_parent' => 0,
        ), $upload['file'], 0, true);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        update_post_meta($attachment_id, '_tr_ai_wire_social_host', '1');
        update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
        return (int) $attachment_id;
    }

    private function social_reel_attachment_data_url($image_id, $error_code, $missing_message) {
        $image_id = (int) $image_id;
        $url = wp_get_attachment_url($image_id);
        if (is_string($url) && $url !== '' && wp_http_validate_url($url) && strpos($url, 'https://') === 0) {
            return $url;
        }
        $path = get_attached_file($image_id);
        if (!$path || !file_exists($path)) {
            return new WP_Error($error_code, $missing_message);
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error($error_code, $missing_message);
        }
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($path) : 'image/jpeg';
        if (strpos($mime, 'image/') !== 0) {
            $mime = 'image/jpeg';
        }
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    private function social_reel_host_audio_url($audio_bytes) {
        if (!is_string($audio_bytes) || $audio_bytes === '') {
            return new WP_Error('tr_ai_wire_reel_tts', 'A spoken Reel script is required.');
        }
        return 'data:audio/mpeg;base64,' . base64_encode($audio_bytes);
    }

    private function store_social_host($post, $bytes, $copy) {
        $name = 'technorapper-social-host-' . (int) $post->ID . '.mp4';
        $upload = wp_upload_bits($name, null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('tr_ai_wire_social_video_upload', sanitize_text_field($upload['error']));
        }
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'video/mp4',
            'post_title' => sanitize_text_field((string) ($copy['hook'] ?? $post->post_title) . ' — reel host'),
            'post_status' => 'inherit',
            'post_parent' => 0,
        ), $upload['file'], 0, true);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
        update_post_meta($attachment_id, '_tr_ai_wire_social_host_model', $this->social_reel_host_signature());
        update_post_meta($post->ID, '_tr_ai_wire_social_host_id', (int) $attachment_id);
        delete_post_meta($post->ID, '_tr_ai_wire_fal_host_request');
        $path = get_attached_file((int) $attachment_id);
        if (!$path || !file_exists($path)) {
            return new WP_Error('tr_ai_wire_reel_host', 'The talking-host clip could not be stored.');
        }
        return array('id' => (int) $attachment_id, 'path' => $path);
    }

    private function compose_social_reel_story($clips, $audio_path, $out_path) {
        $ffmpeg = $this->social_ffmpeg_path();
        if ($ffmpeg === '') {
            return $this->compose_social_reel_story_fal($clips, $audio_path, $out_path);
        }
        $clips = array_values((array) $clips);
        if (count($clips) < 2) {
            return new WP_Error('tr_ai_wire_reel_frames', 'A story Reel needs at least two shots.');
        }
        if (!is_string($audio_path) || $audio_path === '' || !file_exists($audio_path)) {
            return new WP_Error('tr_ai_wire_reel_tts', 'A spoken voiceover is required. Silent poster clips are a slideshow.');
        }
        $dir = dirname($out_path);
        $parts = array();
        foreach ($clips as $index => $clip) {
            if (empty($clip['path']) || !file_exists($clip['path'])) {
                return new WP_Error('tr_ai_wire_reel_frames', 'A Reel story shot file is missing.');
            }
            $shot = $dir . '/part-' . (string) $index . '.mp4';
            $duration = max(0.8, (float) ($clip['duration'] ?? 2));
            $crop = (string) ($clip['crop'] ?? 'full');
            $kind = (string) ($clip['kind'] ?? '');
            $vf = 'scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,setsar=1,format=yuv420p';
            if ($crop === 'close') {
                $vf = 'scale=1460:2596:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,setsar=1,format=yuv420p';
            }
            $overlay = (string) ($clip['overlay'] ?? '');
            $clip_t = number_format($duration, 2, '.', '');
            $args = array('-y');
            if ($kind === 'end') {
                $args = array_merge($args, array('-loop', '1', '-t', $clip_t, '-i', (string) $clip['path']));
            } else {
                $args = array_merge($args, array('-stream_loop', '-1', '-t', $clip_t, '-i', (string) $clip['path']));
            }
            if ($overlay !== '' && file_exists($overlay) && $kind !== 'end') {
                $args = array_merge($args, array(
                    '-loop', '1',
                    '-t', $clip_t,
                    '-i', $overlay,
                    '-filter_complex', '[0:v]' . $vf . '[v0];[v0][1:v]overlay=0:0[v]',
                    '-map', '[v]',
                    '-t', $clip_t,
                ));
            } else {
                $args = array_merge($args, array('-vf', $vf, '-t', $clip_t));
            }
            $args = array_merge($args, array(
                '-an',
                '-c:v', 'libx264',
                '-pix_fmt', 'yuv420p',
                '-profile:v', 'high',
                '-level', '4.0',
                $shot,
            ));
            $result = $this->social_run_binary($ffmpeg, $args, 120);
            if (is_wp_error($result) || !file_exists($shot) || filesize($shot) < 1024) {
                return is_wp_error($result) ? $result : new WP_Error('tr_ai_wire_reel_mux', 'ffmpeg could not render a story shot.');
            }
            $parts[] = $shot;
        }
        $list = $dir . '/story-concat.txt';
        $lines = array();
        foreach ($parts as $part) {
            $lines[] = "file '" . str_replace("'", "'\\''", $part) . "'";
        }
        if (file_put_contents($list, implode("\n", $lines) . "\n") === false) {
            return new WP_Error('tr_ai_wire_reel_temp', 'Could not write the ffmpeg concat list.');
        }
        $args = array(
            '-y',
            '-f', 'concat',
            '-safe', '0',
            '-i', $list,
            '-i', $audio_path,
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
        $result = $this->social_run_binary($ffmpeg, $args, 150);
        if (is_wp_error($result)) {
            return $result;
        }
        if (!file_exists($out_path) || filesize($out_path) < 1024) {
            return new WP_Error('tr_ai_wire_reel_mux', 'ffmpeg did not produce a usable Reel video.');
        }
        return $out_path;
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
        if ($this->post_is_comic($post)) {
            $prompt .= ' Keep any sly visual beat photoreal.';
        }
        $prompt .= ' Continuous camera motion (push-in, orbit, or handheld), photoreal product or screen in frame, no freeze, no text, no logos, no watermarks, no subtitles, no captions.';
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

    private function social_fal_result_file_url($result) {
        if (!is_array($result)) {
            return '';
        }
        foreach (array('video_url', 'image_url') as $key) {
            $url = (string) ($result[$key] ?? '');
            if ($url !== '') {
                return $url;
            }
        }
        foreach (array('video', 'image') as $key) {
            $url = (string) ($result[$key]['url'] ?? '');
            if ($url !== '') {
                return $url;
            }
        }
        if (!empty($result['images'][0]['url'])) {
            return (string) $result['images'][0]['url'];
        }
        return '';
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
                $file_url = $this->social_fal_result_file_url(is_array($result) ? $result : array());
                if ($file_url === '' || !wp_http_validate_url($file_url)) {
                    return new WP_Error('tr_ai_wire_reel_video', 'Fal completed without a media URL.');
                }
                $download = wp_remote_get($file_url, array('timeout' => 60));
                if (is_wp_error($download)) {
                    return new WP_Error('tr_ai_wire_reel_video', $download->get_error_message());
                }
                $bytes = wp_remote_retrieve_body($download);
                if (!is_string($bytes) || strlen($bytes) < 512) {
                    return new WP_Error('tr_ai_wire_reel_video', 'Fal returned an empty media file.');
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

    private function store_social_voice($post, $bytes, $copy) {
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('tr_ai_wire_reel_tts', 'A spoken Reel script is required.');
        }
        $name = 'technorapper-social-voice-' . (int) $post->ID . '.mp3';
        $upload = wp_upload_bits($name, null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('tr_ai_wire_social_video_upload', sanitize_text_field($upload['error']));
        }
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'audio/mpeg',
            'post_title' => sanitize_text_field((string) ($copy['hook'] ?? $post->post_title) . ' — reel voice'),
            'post_status' => 'inherit',
            'post_parent' => 0,
        ), $upload['file'], 0, true);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
        update_post_meta($post->ID, '_tr_ai_wire_social_voice_id', (int) $attachment_id);
        return array('id' => (int) $attachment_id, 'path' => (string) $upload['file']);
    }

    private function social_reel_laptop_hold_error() {
        return new WP_Error(
            'tr_ai_wire_reel_kit',
            'This WordPress host has no ffmpeg, so the MP4 cannot be finished here. Download the Reel files on this row, install ffmpeg on your laptop, run make-reel, then upload reel.mp4 and click Publish.'
        );
    }

    private function social_reel_laptop_readme($duration) {
        $duration = max(8, (int) round((float) $duration));
        return "AI Wire Reel kit\n"
            . "================\n\n"
            . "This WordPress host cannot run ffmpeg. Finish the spoken Reel on your laptop.\n\n"
            . "1. Install ffmpeg:\n"
            . "   Windows: https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip\n"
            . "            Unzip and put ffmpeg.exe in this folder (or add it to PATH).\n"
            . "   Mac:     https://evermeet.cx/ffmpeg/getrelease/zip\n"
            . "            or: brew install ffmpeg\n"
            . "   Official: https://ffmpeg.org/download.html\n\n"
            . "2. Put motion.mp4, voice.mp3, and end.jpg in one folder with make-reel.bat (Windows) or make-reel.sh (Mac/Linux).\n\n"
            . "3. Run make-reel.bat or: bash make-reel.sh\n"
            . "   Spoken length used: {$duration} seconds.\n\n"
            . "4. Upload reel.mp4 on the Social queue row, then Publish.\n";
    }

    private function social_reel_laptop_script($duration, $windows = false) {
        $duration = max(8, (int) round((float) $duration));
        $trim = number_format((float) $duration, 2, '.', '');
        $cmd = 'ffmpeg -y -stream_loop -1 -i motion.mp4 -i voice.mp3 -loop 1 -t 2 -i end.jpg'
            . ' -filter_complex "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,setsar=1,format=yuv420p,trim=duration=' . $trim . ',setpts=PTS-STARTPTS[base];'
            . '[2:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,setsar=1,format=yuv420p[end];'
            . '[base][end]concat=n=2:v=1:a=0[v]"'
            . ' -map "[v]" -map 1:a -c:v libx264 -pix_fmt yuv420p -profile:v high -level 4.0 -c:a aac -b:a 128k -ar 44100 -shortest -movflags +faststart reel.mp4';
        if ($windows) {
            return "@echo off\r\n"
                . "REM Install ffmpeg first: https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip\r\n"
                . $cmd . "\r\n"
                . "echo Created reel.mp4 — upload it on the Social queue, then Publish.\r\n"
                . "pause\r\n";
        }
        return "#!/bin/bash\nset -euo pipefail\n"
            . "# brew install ffmpeg   OR   https://evermeet.cx/ffmpeg/getrelease/zip\n"
            . $cmd . "\n"
            . "echo \"Created reel.mp4 — upload it on the Social queue, then Publish.\"\n";
    }

    private function store_social_reel_laptop_kit($post, $files, $duration, $copy) {
        $files = is_array($files) ? $files : array();
        $usable = array();
        foreach ($files as $name => $path) {
            $name = sanitize_file_name((string) $name);
            if ($name === '' || !is_string($path) || $path === '' || !file_exists($path)) {
                continue;
            }
            $usable[$name] = $path;
        }
        if (empty($usable['motion.mp4']) && empty($usable['host.mp4'])) {
            return new WP_Error('tr_ai_wire_reel_kit', 'Reel motion was not stored for laptop download.');
        }
        if (empty($usable['voice.mp3']) && $this->social_voice_id($post->ID) > 0) {
            $voice_path = get_attached_file($this->social_voice_id($post->ID));
            if ($voice_path && file_exists($voice_path)) {
                $usable['voice.mp3'] = $voice_path;
            }
        }
        update_post_meta($post->ID, '_tr_ai_wire_reel_audio_seconds', (string) max(8, (int) round((float) $duration)));
        if (!class_exists('ZipArchive')) {
            return new WP_Error('tr_ai_wire_reel_kit', 'PHP ZipArchive is not available to pack the laptop kit. Download motion.mp4 and voice.mp3 from this row instead.');
        }
        $tmp = get_temp_dir() . 'tr-ai-wire-kit-' . wp_generate_password(8, false, false) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('tr_ai_wire_reel_kit', 'Could not create the laptop Reel zip.');
        }
        foreach ($usable as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->addFromString('README.txt', $this->social_reel_laptop_readme($duration));
        $zip->addFromString('make-reel.sh', $this->social_reel_laptop_script($duration, false));
        $zip->addFromString('make-reel.bat', $this->social_reel_laptop_script($duration, true));
        $zip->close();
        if (!file_exists($tmp) || filesize($tmp) < 64) {
            @unlink($tmp);
            return new WP_Error('tr_ai_wire_reel_kit', 'The laptop Reel zip was empty.');
        }
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error('tr_ai_wire_reel_kit', 'The laptop Reel zip could not be read.');
        }
        $upload = wp_upload_bits('technorapper-reel-kit-' . (int) $post->ID . '.zip', null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('tr_ai_wire_social_video_upload', sanitize_text_field($upload['error']));
        }
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'application/zip',
            'post_title' => sanitize_text_field((string) ($copy['hook'] ?? $post->post_title) . ' — reel kit'),
            'post_status' => 'inherit',
            'post_parent' => 0,
        ), $upload['file'], 0, true);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }
        update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
        update_post_meta($post->ID, '_tr_ai_wire_social_reel_kit_id', (int) $attachment_id);
        return (int) $attachment_id;
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

    private function social_reel_can_mux() {
        return $this->social_ffmpeg_path() !== '' || $this->fal_api_key() !== '';
    }

    private function social_reel_mux_error() {
        return new WP_Error(
            'tr_ai_wire_reel_ffmpeg',
            'Instagram Reels need a Fal.ai API key to assemble the video on this host (no local ffmpeg). Save it on Social settings. The plugin will not fall back to a slideshow of posters.'
        );
    }

    private function social_reel_file_data_url($path, $mime, $error_code, $message) {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return new WP_Error($error_code, $message);
        }
        $uploads = wp_upload_dir();
        $basedir = (string) ($uploads['basedir'] ?? '');
        $baseurl = (string) ($uploads['baseurl'] ?? '');
        if ($basedir !== '' && $baseurl !== '' && strpos($path, $basedir) === 0) {
            $url = $baseurl . str_replace('\\', '/', substr($path, strlen($basedir)));
            if (is_string($url) && $url !== '' && wp_http_validate_url($url) && strpos($url, 'https://') === 0) {
                return $url;
            }
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return new WP_Error($error_code, $message);
        }
        $detected = function_exists('mime_content_type') ? (string) mime_content_type($path) : '';
        if ($detected !== '' && strpos($detected, '/') !== false) {
            $mime = $detected;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    private function social_fal_loop_keyframes($url, $source_seconds, $need_seconds, $start_seconds = 0) {
        $source_seconds = max(1.0, (float) $source_seconds);
        $need_seconds = max(0.4, (float) $need_seconds);
        $start_seconds = max(0.0, (float) $start_seconds);
        $frames = array();
        $clock = 0.0;
        while ($clock < $need_seconds - 0.05) {
            $chunk = min($source_seconds, $need_seconds - $clock);
            if ($chunk < 0.4) {
                break;
            }
            $frames[] = array(
                'timestamp' => (int) round(($start_seconds + $clock) * 1000),
                'duration' => (int) round($chunk * 1000),
                'url' => $url,
            );
            $clock += $chunk;
        }
        return $frames;
    }

    private function social_fal_compose_video($tracks) {
        if ($this->fal_api_key() === '') {
            return $this->social_reel_mux_error();
        }
        $submitted = $this->social_fal_submit(self::SOCIAL_REEL_MUX_ENDPOINT, array(
            'tracks' => array_values((array) $tracks),
        ));
        if (is_wp_error($submitted)) {
            return $submitted;
        }
        $polled = $this->social_fal_await(self::SOCIAL_REEL_MUX_ENDPOINT, (string) ($submitted['request_id'] ?? ''));
        if (is_wp_error($polled)) {
            return $polled;
        }
        if (!is_string($polled) || strlen($polled) < 512) {
            return new WP_Error('tr_ai_wire_reel_mux', 'Fal did not return a usable Reel video.');
        }
        return $polled;
    }

    private function compose_social_reel_motion_fal($motion_path, $end_path, $audio_path, $out_path, $audio_duration) {
        if (!is_string($motion_path) || $motion_path === '' || !file_exists($motion_path)) {
            return new WP_Error('tr_ai_wire_reel_video', 'Generated Reel motion is missing.');
        }
        if (!is_string($end_path) || $end_path === '' || !file_exists($end_path)) {
            return new WP_Error('tr_ai_wire_reel_frames', 'A Reel must end on a brand sting.');
        }
        if (!is_string($audio_path) || $audio_path === '' || !file_exists($audio_path)) {
            return new WP_Error('tr_ai_wire_reel_tts', 'A spoken voiceover is required. Silent poster clips are a slideshow.');
        }
        $audio_duration = max(8.0, (float) $audio_duration);
        $end = 2.0;
        $motion_url = $this->social_reel_file_data_url($motion_path, 'video/mp4', 'tr_ai_wire_reel_video', 'Generated Reel motion is missing.');
        if (is_wp_error($motion_url)) {
            return $motion_url;
        }
        $end_url = $this->social_reel_file_data_url($end_path, 'image/jpeg', 'tr_ai_wire_reel_frames', 'A Reel must end on a brand sting.');
        if (is_wp_error($end_url)) {
            return $end_url;
        }
        $audio_url = $this->social_reel_file_data_url($audio_path, 'audio/mpeg', 'tr_ai_wire_reel_tts', 'A spoken voiceover is required. Silent poster clips are a slideshow.');
        if (is_wp_error($audio_url)) {
            return $audio_url;
        }
        $source = $this->social_video_duration($motion_path);
        $visual = $this->social_fal_loop_keyframes($motion_url, $source, $audio_duration, 0);
        $visual[] = array(
            'timestamp' => (int) round($audio_duration * 1000),
            'duration' => (int) round($end * 1000),
            'url' => $end_url,
        );
        $bytes = $this->social_fal_compose_video(array(
            array(
                'id' => 'visual',
                'type' => 'video',
                'keyframes' => $visual,
            ),
            array(
                'id' => 'voice',
                'type' => 'audio',
                'keyframes' => array(
                    array(
                        'timestamp' => 0,
                        'duration' => (int) round($audio_duration * 1000),
                        'url' => $audio_url,
                    ),
                ),
            ),
        ));
        if (is_wp_error($bytes)) {
            return $bytes;
        }
        if (!is_string($out_path) || $out_path === '' || file_put_contents($out_path, $bytes) === false) {
            return new WP_Error('tr_ai_wire_reel_temp', 'Could not write the Fal-assembled Reel.');
        }
        if (!file_exists($out_path) || filesize($out_path) < 1024) {
            return new WP_Error('tr_ai_wire_reel_mux', 'Fal did not produce a usable Reel video.');
        }
        return $out_path;
    }

    private function compose_social_reel_story_fal($clips, $audio_path, $out_path) {
        $clips = array_values((array) $clips);
        if (count($clips) < 2) {
            return new WP_Error('tr_ai_wire_reel_frames', 'A story Reel needs at least two shots.');
        }
        if (!is_string($audio_path) || $audio_path === '' || !file_exists($audio_path)) {
            return new WP_Error('tr_ai_wire_reel_tts', 'A spoken voiceover is required. Silent poster clips are a slideshow.');
        }
        $audio_url = $this->social_reel_file_data_url($audio_path, 'audio/mpeg', 'tr_ai_wire_reel_tts', 'A spoken voiceover is required. Silent poster clips are a slideshow.');
        if (is_wp_error($audio_url)) {
            return $audio_url;
        }
        $visual = array();
        $clock = 0.0;
        $total = 0.0;
        foreach ($clips as $clip) {
            if (empty($clip['path']) || !file_exists($clip['path'])) {
                return new WP_Error('tr_ai_wire_reel_frames', 'A Reel story shot file is missing.');
            }
            $duration = max(0.8, (float) ($clip['duration'] ?? 2));
            $kind = (string) ($clip['kind'] ?? '');
            $mime = ($kind === 'end') ? 'image/jpeg' : 'video/mp4';
            $url = $this->social_reel_file_data_url((string) $clip['path'], $mime, 'tr_ai_wire_reel_frames', 'A Reel story shot file is missing.');
            if (is_wp_error($url)) {
                return $url;
            }
            if ($kind === 'end') {
                $visual[] = array(
                    'timestamp' => (int) round($clock * 1000),
                    'duration' => (int) round($duration * 1000),
                    'url' => $url,
                );
            } else {
                $source = $this->social_video_duration((string) $clip['path']);
                $visual = array_merge($visual, $this->social_fal_loop_keyframes($url, $source, $duration, $clock));
            }
            $clock += $duration;
            $total += $duration;
        }
        if (!$visual) {
            return new WP_Error('tr_ai_wire_reel_frames', 'A story Reel needs at least two shots.');
        }
        $bytes = $this->social_fal_compose_video(array(
            array(
                'id' => 'visual',
                'type' => 'video',
                'keyframes' => $visual,
            ),
            array(
                'id' => 'voice',
                'type' => 'audio',
                'keyframes' => array(
                    array(
                        'timestamp' => 0,
                        'duration' => (int) round(max(8.0, $total) * 1000),
                        'url' => $audio_url,
                    ),
                ),
            ),
        ));
        if (is_wp_error($bytes)) {
            return $bytes;
        }
        if (!is_string($out_path) || $out_path === '' || file_put_contents($out_path, $bytes) === false) {
            return new WP_Error('tr_ai_wire_reel_temp', 'Could not write the Fal-assembled Reel.');
        }
        if (!file_exists($out_path) || filesize($out_path) < 1024) {
            return new WP_Error('tr_ai_wire_reel_mux', 'Fal did not produce a usable Reel video.');
        }
        return $out_path;
    }

    private function compose_social_reel_motion($motion_path, $overlays, $end_path, $audio_path, $out_path, $audio_duration) {
        $ffmpeg = $this->social_ffmpeg_path();
        if ($ffmpeg === '') {
            return $this->compose_social_reel_motion_fal($motion_path, $end_path, $audio_path, $out_path, $audio_duration);
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
        $audio_duration = max(8.0, (float) $audio_duration);
        $end = 2.0;
        $args = array(
            '-y',
            '-stream_loop', '-1',
            '-i', $motion_path,
            '-i', $audio_path,
            '-loop', '1',
            '-t', number_format($end, 2, '.', ''),
            '-i', $end_path,
        );
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
        $filters[] = '[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,setsar=1,format=yuv420p,trim=duration=' . number_format($audio_duration, 2, '.', '') . ',setpts=PTS-STARTPTS[base]';
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
                'voice' => $this->social_reel_tts_voice($settings),
                'input' => $script,
                'instructions' => $this->social_reel_tts_instructions($settings),
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

    private function social_audio_duration($path, $script, $settings = null) {
        $max = max(24.0, min(60.0, (float) $this->social_reel_target_seconds($settings) + 12));
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
        return max(10.0, min($max, count($words) / 2.4));
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

    private function store_social_video($post, $bytes, $copy, $script, $settings = null) {
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
        update_post_meta($attachment_id, '_tr_ai_wire_social_layout', $this->social_reel_layout_key($settings));
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
