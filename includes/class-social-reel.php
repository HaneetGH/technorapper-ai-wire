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
 * Motion loops instead of freezing. Default story edit is no-face movie
 * storytelling: a pattern-interrupt hook on screen, captioned news-detail
 * beats and B-roll while the voice continues, then a short brand sting.
 * Talking-host lip-sync is an optional paid upsell. Without local ffmpeg a
 * host (when enabled) only appears at the start — Fal cannot seek mid-clip.
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
                'short' => 'Default — conversational host',
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
            $cast = 'Female tech creator speaking to camera.';
        } elseif ($gender === 'male') {
            $cast = 'Male tech creator speaking to camera.';
        } else {
            $cast = 'Tech creator speaking to camera.';
        }
        return $cast
            . ' Sound like a person stopping a friend mid-scroll — never a teleprompter, never a flat robot newsreader, never a TV announcer.'
            . ' Punch the first line as a hook. Short phrases. Natural pauses. Slight urgency and a hint of a smile.'
            . ' Emphasize the named change and the stake. Speak so they can repeat the takeaway.';
    }

    private function social_reel_dev_cheap_on($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        return !empty($settings['social_reel_dev_cheap']);
    }

    /**
     * Production picks stay saved. Dev cheap mode temporarily forces the lowest-cost
     * Fal path so you can test the pipeline without burning Avatar Pro / Veo Fast.
     */
    private function social_reel_effective_settings($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        if (!$this->social_reel_dev_cheap_on($settings)) {
            return $settings;
        }
        // Cheap path is no-face by design — Avatar is the expensive line item.
        return array_merge($settings, array(
            'social_reel_recipe' => 'story',
            'social_reel_include_host' => 0,
            'social_reel_include_slides' => 1,
            'social_reel_include_broll' => 1,
            'social_reel_host_model' => 'kling-avatar-std',
            'social_reel_video_model' => 'veo-lite',
            'social_reel_clip_seconds' => 4,
            'social_reel_enhance_host_face' => 0,
            'social_reel_vary_host_scene' => 0,
            'social_reel_host_seconds' => 8,
            'social_reel_target_seconds' => 15,
            'social_reel_sting_seconds' => 1,
        ));
    }

    private function social_reel_dev_cheap_summary($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        $est = $this->social_reel_total_estimate_usd($settings);
        return 'Dev cheap test: no talking face · story hooks + Veo Lite 4s + 15s voice. About $'
            . number_format((float) $est, 2)
            . ' estimated. Production model picks (including host) stay saved and apply when you turn Dev off.';
    }

    private function social_reel_max_audio_seconds($settings = null) {
        return max(24.0, min(60.0, (float) $this->social_reel_target_seconds($settings) + 12));
    }

    private function social_reel_clamp_audio_seconds($seconds, $settings = null) {
        $max = $this->social_reel_max_audio_seconds($settings);
        $seconds = (float) $seconds;
        if ($seconds <= 0) {
            return min($max, 10.0);
        }
        // Keep real short takes (do not pad a 3s hook into a fake 6s timeline).
        return max(3.0, min($max, $seconds));
    }

    private function social_reel_script_word_budget($settings = null) {
        $target = $this->social_reel_target_seconds($settings);
        if ($target >= 40) {
            return 140;
        }
        if ($target >= 28) {
            return 110;
        }
        return 55;
    }

    private function social_reel_trim_script($script, $settings = null) {
        $script = trim(wp_strip_all_tags((string) $script));
        $words = preg_split('/\s+/', $script, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || !$words) {
            return '';
        }
        $budget = $this->social_reel_script_word_budget($settings);
        if (count($words) <= $budget) {
            return implode(' ', $words);
        }
        return implode(' ', array_slice($words, 0, $budget));
    }

    /**
     * Fal compose holds the last frame when a video keyframe duration is longer
     * than the source clip. Never trust a measured length past the clip we paid for.
     */
    private function social_reel_motion_source_seconds($path, $settings = null) {
        $clip = (float) $this->social_reel_clip_seconds($settings);
        $measured = (float) $this->social_video_duration($path, $clip);
        if ($measured < 1.0) {
            return max(1.0, $clip);
        }
        return max(1.0, min($clip + 0.35, $measured));
    }

    private function social_reel_recipe($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        $recipe = sanitize_key((string) ($settings['social_reel_recipe'] ?? 'broll'));
        return $recipe === 'story' ? 'story' : 'broll';
    }

    private function social_reel_host_model($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
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
        $settings = $this->social_reel_effective_settings($settings);
        $target = absint($settings['social_reel_target_seconds'] ?? 18);
        return in_array($target, array(15, 18, 30, 45), true) ? $target : 18;
    }

    private function social_reel_script_brief($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        $target = $this->social_reel_target_seconds($settings);
        if ($target >= 40) {
            $length = '110–140 spoken words, about 40–45 seconds.';
        } elseif ($target >= 28) {
            $length = '80–110 spoken words, about 30 seconds.';
        } else {
            $length = '40–55 spoken words, about 15–18 seconds.';
        }
        $face = !empty($settings['social_reel_include_host'])
            ? ' Host may appear briefly; most of the watch time is news visuals.'
            : ' No talking face — the voice narrates over B-roll and big on-screen lines.';
        return $length
            . ' Write for a scroll-stopping Reel, not a press readout.' . $face
            . ' First 1–2 seconds: a pattern-interrupt hook that names the change (question, contradiction, or concrete stake).'
            . ' Then 2–4 short punchy sentences — one idea each. Talk like you are telling one friend the one thing that matters for their phone, game, job, or money.'
            . ' Ban: today we discuss, in this video, according to reports, experts say, let’s dive in, here’s what you need to know, moving on, in conclusion.';
    }

    private function social_reel_host_seconds($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        return min(45, max(8, absint($settings['social_reel_host_seconds'] ?? 20)));
    }

    private function social_reel_sting_seconds($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        return min(4, max(1, absint($settings['social_reel_sting_seconds'] ?? 2)));
    }

    private function social_reel_fact_slides($settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        return min(4, max(1, absint($settings['social_reel_fact_slides'] ?? 2)));
    }

    private function social_reel_layout_key($settings = null) {
        return $this->social_reel_recipe($settings) === 'story' ? self::SOCIAL_REEL_STORY_LAYOUT : self::SOCIAL_REEL_LAYOUT;
    }

    private function social_reel_host_estimate_usd($settings = null, $seconds = 0.0) {
        $settings = $this->social_reel_effective_settings($settings);
        if ($this->social_reel_recipe($settings) !== 'story' || empty($settings['social_reel_include_host'])) {
            return 0.0;
        }
        $models = $this->social_reel_host_models();
        $model = $this->social_reel_host_model($settings);
        $rate = (float) ($models[$model]['usd_per_second'] ?? 0.115);
        $face = !empty($settings['social_reel_enhance_host_face']) ? self::SOCIAL_REEL_FACE_ENHANCE_USD : 0.0;
        $billed = (float) $seconds > 0 ? ceil((float) $seconds) : (float) $this->social_reel_host_seconds($settings);
        return round(($billed * $rate) + $face, 4);
    }

    private function social_reel_host_signature($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        return $this->social_reel_host_model($settings)
            . '|' . (!empty($settings['social_reel_enhance_host_face']) ? 'enhance' : 'raw')
            . '|' . absint($settings['social_reel_host_image_id'] ?? 0)
            . '|' . (!empty($settings['social_reel_generate_host_face']) ? 'gen' : 'upload')
            . '|' . ($this->social_reel_dev_cheap_on($settings) ? 'devcheap' : 'prod');
    }

    private function social_reel_total_estimate_usd($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        $motion = 0.0;
        if (!empty($this->social_reel_plan($settings)['motion'])) {
            $motion = $this->social_reel_video_estimate_usd($settings);
        }
        return round($motion + $this->social_reel_host_estimate_usd($settings), 4);
    }

    private function social_reel_recipe_summary($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        $recipe = $this->social_reel_recipe($settings);
        $models = $this->social_reel_video_models();
        $model = $this->social_reel_video_model($settings);
        $voice = $this->social_reel_tts_voice($settings);
        $parts = array();
        if ($recipe === 'story') {
            if (!empty($settings['social_reel_include_host'])) {
                $parts[] = 'Story edit';
                $hosts = $this->social_reel_host_models();
                $host = $this->social_reel_host_model($settings);
                $parts[] = (string) ($hosts[$host]['label'] ?? 'Talking host');
                $parts[] = 'same host face';
                if (!isset($settings['social_reel_vary_host_scene']) || !empty($settings['social_reel_vary_host_scene'])) {
                    $parts[] = 'new outfit/place each Reel';
                }
                if (!empty($settings['social_reel_enhance_host_face'])) {
                    $parts[] = 'face enhance';
                }
            } else {
                $parts[] = 'No-face hook story';
            }
            $parts[] = 'movie-style news details';
            if (!isset($settings['social_reel_include_broll']) || !empty($settings['social_reel_include_broll'])) {
                $parts[] = 'B-roll and photos';
            }
            if (!isset($settings['social_reel_include_sting']) || !empty($settings['social_reel_include_sting'])) {
                $parts[] = 'sting';
            }
        } else {
            $parts[] = 'B-roll spoken Reel';
        }
        if ($this->social_reel_dev_cheap_on($settings)) {
            array_unshift($parts, 'DEV CHEAP TEST');
        }
        $parts[] = (string) ($models[$model]['label'] ?? $model);
        $parts[] = (string) $this->social_reel_target_seconds($settings) . 's · ' . $this->social_reel_tts_voice_label($voice);
        return implode(' · ', $parts);
    }

    private function social_reel_video_model($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        $model = sanitize_key((string) ($settings['social_reel_video_model'] ?? self::SOCIAL_REEL_VIDEO_DEFAULT));
        $models = $this->social_reel_video_models();
        return isset($models[$model]) ? $model : self::SOCIAL_REEL_VIDEO_DEFAULT;
    }

    private function social_reel_clip_seconds($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
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
        $hook = rtrim($hook, '.');
        $lesson = rtrim($lesson, '.');
        if ($hook === '' && $lesson === '') {
            return $this->clip_social_block('Something just shifted in this story. Here is the one change that matters.', 700);
        }
        if ($hook !== '' && $lesson !== '' && strcasecmp($hook, $lesson) !== 0) {
            $script = $hook . '? ' . $lesson . '. That is the part that hits your next session.';
        } elseif ($hook !== '') {
            $script = $hook . '. That is the change — and it lands on what you use next.';
        } else {
            $script = $lesson . '. That is the part that matters for you.';
        }
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

    private function social_reel_plan($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        $local = $this->social_ffmpeg_path() !== '';
        if ($this->social_reel_recipe($settings) !== 'story') {
            return array(
                'story' => false,
                'host' => false,
                'motion' => true,
                'details' => true,
                'sting' => true,
                'local' => $local,
                'cutaways_skipped' => false,
                'host_seek' => false,
            );
        }
        $host = !empty($settings['social_reel_include_host']);
        $motion = !empty($settings['social_reel_include_broll']) || !empty($settings['social_reel_include_slides']);
        if (!$host && !$motion) {
            $motion = true;
        }
        $sting = array_key_exists('social_reel_include_sting', $settings) ? !empty($settings['social_reel_include_sting']) : true;
        // Movie storytelling always keeps detail beats (B-roll and/or captioned news
        // photos) after the opening host. Without local ffmpeg we cannot seek into the
        // middle of the talking-host clip, so the host only appears up front.
        return array(
            'story' => true,
            'host' => $host,
            'motion' => $motion,
            'details' => true,
            'sting' => $sting,
            'local' => $local,
            'cutaways_skipped' => false,
            'host_seek' => $local && $host,
        );
    }

    private function social_reel_voice($post, $script, $settings, $copy) {
        $script = $this->social_reel_trim_script($script, $settings);
        if ($script === '') {
            return new WP_Error('tr_ai_wire_reel_script', 'A spoken Reel script is required.');
        }
        $key = md5(implode('|', array($script, $this->social_reel_tts_voice($settings), $this->social_reel_tts_instructions($settings), self::SOCIAL_TTS_MODEL)));
        $existing = $this->social_voice_id($post->ID);
        if ($existing > 0 && (string) get_post_meta($existing, '_tr_ai_wire_voice_key', true) === $key) {
            $path = get_attached_file($existing);
            if ($path && file_exists($path) && filesize($path) > 512) {
                $seconds = (float) get_post_meta($existing, '_tr_ai_wire_voice_seconds', true);
                if ($seconds <= 0) {
                    $seconds = $this->social_audio_duration($path, $script, $settings);
                }
                $seconds = $this->social_reel_clamp_audio_seconds($seconds, $settings);
                return array('id' => $existing, 'path' => $path, 'key' => $key, 'seconds' => $seconds);
            }
        }
        $this->social_reel_progress_note($post->ID, 'Recording the voiceover.');
        $audio = $this->generate_social_tts($script, $settings);
        if (is_wp_error($audio)) {
            return $audio;
        }
        $stored = $this->store_social_voice($post, $audio, $copy);
        if (is_wp_error($stored)) {
            return $stored;
        }
        $seconds = $this->social_reel_clamp_audio_seconds(
            $this->social_audio_duration((string) $stored['path'], $script, $settings),
            $settings
        );
        update_post_meta((int) $stored['id'], '_tr_ai_wire_voice_key', $key);
        update_post_meta((int) $stored['id'], '_tr_ai_wire_voice_seconds', (string) $seconds);
        if ($existing > 0 && $existing !== (int) $stored['id'] && get_post_meta($existing, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment($existing, true);
        }
        return array('id' => (int) $stored['id'], 'path' => (string) $stored['path'], 'key' => $key, 'seconds' => $seconds);
    }

    private function social_reel_gather($post, $settings, $copy, $broll_path, $plan, $script) {
        $video_budget = (float) ($settings['social_video_budget_usd'] ?? 0);
        $month_key = 'tr_ai_wire_social_video_usage_' . gmdate('Ym');
        $usage = (float) get_option($month_key, 0);
        $need = 0.0;
        if (!empty($plan['host']) && $this->social_host_id($post->ID) < 1 && !$this->social_fal_pending_get($post->ID, '_tr_ai_wire_fal_host_request')) {
            $need += $this->social_reel_host_estimate_usd($settings);
        }
        if (!empty($plan['motion']) && $this->social_motion_id($post->ID) < 1 && !$this->social_fal_pending_get($post->ID, '_tr_ai_wire_fal_request')) {
            $need += $this->social_reel_video_estimate_usd($settings);
        }
        if ($need > 0 && ($video_budget <= 0 || ($usage + $need) > $video_budget)) {
            return new WP_Error('tr_ai_wire_reel_video_budget', $this->social_reel_budget_message($need, $usage, $video_budget));
        }
        $voice = $this->social_reel_voice($post, $script, $settings, $copy);
        if (is_wp_error($voice)) {
            return $voice;
        }
        $host = null;
        $motion = null;
        $steps = array();
        if (!empty($plan['host'])) {
            $steps['host'] = array($this, 'generate_social_reel_host');
        }
        if (!empty($plan['motion'])) {
            $steps['motion'] = array($this, 'generate_social_reel_motion');
        }
        $results = array();
        foreach (array(true, false) as $submit_only) {
            foreach ($steps as $name => $unused) {
                if (isset($results[$name]) && !is_wp_error($results[$name])) {
                    continue;
                }
                $results[$name] = $name === 'host'
                    ? $this->generate_social_reel_host($post, $settings, $copy, $voice, $submit_only)
                    : $this->generate_social_reel_motion($post, $settings, $copy, $broll_path, $submit_only);
                if (is_wp_error($results[$name]) && $results[$name]->get_error_code() !== 'tr_ai_wire_reel_video_wait') {
                    return $results[$name];
                }
            }
        }
        $waiting = array();
        foreach ($results as $result) {
            if (is_wp_error($result)) {
                $waiting[] = $result->get_error_message();
            }
        }
        if ($waiting) {
            return new WP_Error('tr_ai_wire_reel_video_wait', implode(' ', $waiting));
        }
        return array(
            'voice' => $voice,
            'host' => $results['host'] ?? null,
            'motion' => $results['motion'] ?? null,
        );
    }

    private function social_reel_store_asset_image($post, $bytes, $filename, $title, $meta_key, $key) {
        $upload = wp_upload_bits($filename, null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('tr_ai_wire_social_video_upload', sanitize_text_field($upload['error']));
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
        update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
        update_post_meta($attachment_id, '_tr_ai_wire_endcard_key', $key);
        update_post_meta($post->ID, $meta_key, (int) $attachment_id);
        return array('id' => (int) $attachment_id, 'path' => (string) $upload['file']);
    }

    private function social_reel_endcard($post, $copy, $photo_bytes, $caption) {
        $caption = trim((string) $caption);
        $key = md5($caption . '|' . ($photo_bytes !== '' ? 'photo' : 'plain') . '|' . self::site_brand_name());
        $existing = (int) get_post_meta($post->ID, '_tr_ai_wire_social_endcard_id', true);
        if ($existing > 0 && (string) get_post_meta($existing, '_tr_ai_wire_endcard_key', true) === $key) {
            $path = get_attached_file($existing);
            if ($path && file_exists($path)) {
                return array('id' => $existing, 'path' => $path);
            }
        }
        $jpeg = $this->render_social_reel_frame('end', $copy, $photo_bytes, $caption);
        if (is_wp_error($jpeg)) {
            return $jpeg;
        }
        return $this->social_reel_store_asset_image($post, $jpeg, 'technorapper-social-endcard-' . (int) $post->ID . '.jpg', 'Reel end card', '_tr_ai_wire_social_endcard_id', $key);
    }

    private function social_reel_ms($seconds) {
        return (int) round(max(0.0, (float) $seconds) * 1000);
    }

    private function social_reel_detail_frame($post, $copy, $photo_bytes, $caption, $index) {
        $caption = trim(wp_strip_all_tags((string) $caption));
        if ($caption === '') {
            $caption = trim(wp_strip_all_tags((string) ($copy['hook'] ?? $post->post_title)));
        }
        $kind = (is_string($photo_bytes) && $photo_bytes !== '') ? 'broll' : 'slide';
        $key = md5($kind . '|' . $caption . '|' . (string) $index . '|' . self::site_brand_name());
        $meta = '_tr_ai_wire_reel_detail_' . (string) ((int) $index);
        $existing = (int) get_post_meta($post->ID, $meta, true);
        if ($existing > 0 && (string) get_post_meta($existing, '_tr_ai_wire_endcard_key', true) === $key) {
            $path = get_attached_file($existing);
            if ($path && file_exists($path)) {
                return array('id' => $existing, 'path' => $path);
            }
        }
        $jpeg = $this->render_social_reel_frame($kind, $copy, is_string($photo_bytes) ? $photo_bytes : '', $caption);
        if (is_wp_error($jpeg)) {
            return $jpeg;
        }
        return $this->social_reel_store_asset_image(
            $post,
            $jpeg,
            'technorapper-social-detail-' . (int) $post->ID . '-' . (string) ((int) $index) . '.jpg',
            'Reel news detail',
            $meta,
            $key
        );
    }

    private function social_reel_fal_append_detail_beat(&$visual, &$clock, $post, $copy, $photo_bytes, $caption, $index, $seconds, $motion_url, $motion_source, $prefer_motion) {
        $seconds = max(1.2, (float) $seconds);
        if ($prefer_motion && $motion_url !== '') {
            $loops = $this->social_fal_loop_keyframes($motion_url, $motion_source, $seconds, $clock);
            if ($loops) {
                foreach ($loops as $frame) {
                    $visual[] = $frame;
                }
                $clock += $seconds;
                return true;
            }
        }
        $card = $this->social_reel_detail_frame($post, $copy, $photo_bytes, $caption, $index);
        if (is_wp_error($card)) {
            return $card;
        }
        $card_url = $this->social_reel_file_data_url((string) $card['path'], 'image/jpeg', 'tr_ai_wire_reel_frames', 'A Reel news detail frame is missing.');
        if (is_wp_error($card_url)) {
            return $card_url;
        }
        $visual[] = array(
            'timestamp' => $this->social_reel_ms($clock),
            'duration' => $this->social_reel_ms($seconds),
            'url' => $card_url,
        );
        $clock += $seconds;
        return true;
    }

    private function social_reel_fal_movie_keyframes($post, $settings, $copy, $plan, $parts, $photo_bytes, $audio_seconds, $use_sting, $sting_seconds, $end_caption) {
        $host = is_array($parts['host'] ?? null) ? $parts['host'] : null;
        $motion = is_array($parts['motion'] ?? null) ? $parts['motion'] : null;
        $visual = array();
        $clock = 0.0;
        $photo_bytes = is_string($photo_bytes) ? $photo_bytes : '';
        $audio_seconds = $this->social_reel_clamp_audio_seconds($audio_seconds, $settings);
        $captions = $this->social_reel_cutaway_captions($copy);
        if (!$captions) {
            foreach (array($copy['hook'] ?? '', $copy['on_image_headline'] ?? '', $copy['cta'] ?? '') as $line) {
                $line = $this->clip_social_text(trim(wp_strip_all_tags((string) $line)), 72);
                if ($line !== '' && !in_array($line, $captions, true)) {
                    $captions[] = $line;
                }
            }
        }
        if (!$captions) {
            $captions[] = $this->clip_social_text((string) ($copy['hook'] ?? $post->post_title), 42);
        }

        $motion_url = '';
        $motion_source = 0.0;
        if ($motion) {
            $motion_url = $this->social_reel_file_data_url((string) $motion['path'], 'video/mp4', 'tr_ai_wire_reel_video', 'Generated Reel motion is missing.');
            if (is_wp_error($motion_url)) {
                return $motion_url;
            }
            $motion_source = $this->social_reel_motion_source_seconds((string) $motion['path'], $settings);
        }

        $host_url = '';
        $host_len = 0.0;
        if ($host) {
            $host_url = $this->social_reel_file_data_url((string) $host['path'], 'video/mp4', 'tr_ai_wire_reel_host', 'The talking-host clip is missing.');
            if (is_wp_error($host_url)) {
                return $host_url;
            }
            $host_len = min(
                (float) $this->social_reel_host_seconds($settings),
                (float) $this->social_video_duration((string) $host['path'], min(8.0, $audio_seconds))
            );
        }

        $has_host = $host_url !== '';
        $can_detail = ($motion_url !== '' || $photo_bytes !== '');

        // No-face path: big on-screen hook first (thumb stopper), then motion + caption beats.
        // With host: optional cold-open detail, short lip-sync intro, then news beats.
        if (!$has_host && $can_detail) {
            $cold = min(2.4, max(1.6, $audio_seconds * 0.12));
            $added = $this->social_reel_fal_append_detail_beat(
                $visual,
                $clock,
                $post,
                $copy,
                $photo_bytes,
                $captions[0],
                0,
                $cold,
                $motion_url,
                $motion_source,
                false
            );
            if (is_wp_error($added)) {
                return $added;
            }
        } elseif ($can_detail && $audio_seconds >= 8.0) {
            $cold = min(2.6, max(1.8, $audio_seconds * 0.14));
            $added = $this->social_reel_fal_append_detail_beat(
                $visual,
                $clock,
                $post,
                $copy,
                $photo_bytes,
                $captions[0],
                0,
                $cold,
                $motion_url,
                $motion_source,
                $motion_url !== ''
            );
            if (is_wp_error($added)) {
                return $added;
            }
        }

        if ($has_host) {
            $intro = min($host_len, max(2.4, min(3.6, $audio_seconds * 0.18)));
            if ($clock < 0.4) {
                $intro = min($host_len, max(3.0, min(5.0, $audio_seconds * 0.28)));
            }
            $visual[] = array('timestamp' => $this->social_reel_ms($clock), 'duration' => $this->social_reel_ms($intro), 'url' => $host_url);
            $clock += $intro;
        }

        $left = max(0.0, $audio_seconds - $clock);
        $beat_n = max(2, min(5, count($captions)));
        if (!empty($settings['social_reel_include_slides'])) {
            $beat_n = max($beat_n, $this->social_reel_fact_slides($settings));
        }
        // No-face Reels need more cutaways so one 4s B-roll loop is never the whole video.
        if (!$has_host) {
            $beat_n = max($beat_n, 3);
        }
        $beat_n = min(5, $beat_n);
        if ($left < 1.8) {
            $beat_n = 1;
        }
        if ($left >= 0.8) {
            $slice = $left / max(1, $beat_n);
            // Cap each beat so Fal never gets one long keyframe that freezes after motion ends.
            $max_slice = $motion_source > 0 ? max(2.2, $motion_source) : 3.2;
            $slice = min($slice, $max_slice);
            for ($i = 0; $i < $beat_n; $i++) {
                $remain = max(0.0, $audio_seconds - $clock);
                if ($remain < 0.8) {
                    break;
                }
                $this_slice = min($slice, $remain);
                $caption = $captions[min($i, count($captions) - 1)];
                // Alternate motion and caption cards. No-face starts with a text hook, so first beat prefers motion.
                $prefer_motion = ($motion_url !== '') && (
                    $photo_bytes === '' || ((!$has_host ? $i : ($i + 1)) % 2) === 0
                );
                $added = $this->social_reel_fal_append_detail_beat(
                    $visual,
                    $clock,
                    $post,
                    $copy,
                    $photo_bytes,
                    $caption,
                    $i + 1,
                    $this_slice,
                    $motion_url,
                    $motion_source,
                    $prefer_motion
                );
                if (is_wp_error($added)) {
                    return $added;
                }
            }
        }

        if ($clock + 0.4 < $audio_seconds && $motion_url !== '') {
            $tail = $this->social_fal_loop_keyframes($motion_url, $motion_source, $audio_seconds - $clock, $clock);
            foreach ($tail as $frame) {
                $visual[] = $frame;
            }
            $clock = $audio_seconds;
        }

        if (!$visual && $motion_url !== '') {
            $visual = $this->social_fal_loop_keyframes($motion_url, $motion_source, $audio_seconds, 0);
            $clock = $audio_seconds;
        }
        if (!$visual) {
            return new WP_Error('tr_ai_wire_reel_frames', 'The Reel has no video clip to assemble.');
        }
        if ($use_sting) {
            $endcard = $this->social_reel_endcard($post, $copy, $photo_bytes, $end_caption);
            if (is_wp_error($endcard)) {
                return $endcard;
            }
            $end_url = $this->social_reel_file_data_url((string) $endcard['path'], 'image/jpeg', 'tr_ai_wire_reel_frames', 'The Reel end card is missing.');
            if (is_wp_error($end_url)) {
                return $end_url;
            }
            $visual[] = array(
                'timestamp' => $this->social_reel_ms(max($clock, $audio_seconds)),
                'duration' => $this->social_reel_ms($sting_seconds),
                'url' => $end_url,
            );
        }
        return $visual;
    }

    private function social_reel_assemble_with_fal($post, $settings, $copy, $script, $plan, $parts, $photo_bytes, $end_caption) {
        $voice = $parts['voice'];
        $host = is_array($parts['host'] ?? null) ? $parts['host'] : null;
        $motion = is_array($parts['motion'] ?? null) ? $parts['motion'] : null;
        $audio_seconds = $this->social_reel_clamp_audio_seconds((float) $voice['seconds'], $settings);
        $sting_seconds = !empty($plan['sting']) ? ($plan['story'] ? (float) $this->social_reel_sting_seconds($settings) : 2.0) : 0.0;
        $use_sting = $sting_seconds > 0 && (string) get_post_meta($post->ID, '_tr_ai_wire_reel_no_sting', true) !== '1';
        $movie = !empty($plan['story']);
        $input_key = md5(implode('|', array(
            'movie-v3-noface-hook',
            (string) $voice['key'],
            (int) ($host['id'] ?? 0),
            (int) ($motion['id'] ?? 0),
            $movie ? 'story' : 'broll',
            $use_sting ? '1' : '0',
            md5((string) $photo_bytes),
            $end_caption,
            (string) (int) round($audio_seconds),
        )));
        $payload = function () use ($post, $settings, $copy, $plan, $parts, $voice, $host, $motion, $audio_seconds, $use_sting, $sting_seconds, $photo_bytes, $end_caption, $movie) {
            $voice_url = $this->social_reel_file_data_url((string) $voice['path'], 'audio/mpeg', 'tr_ai_wire_reel_tts', 'The Reel voiceover file is missing.');
            if (is_wp_error($voice_url)) {
                return $voice_url;
            }
            if ($movie) {
                $visual = $this->social_reel_fal_movie_keyframes(
                    $post,
                    $settings,
                    $copy,
                    $plan,
                    $parts,
                    $photo_bytes,
                    $audio_seconds,
                    $use_sting,
                    $sting_seconds,
                    $end_caption
                );
                if (is_wp_error($visual)) {
                    return $visual;
                }
            } else {
                $visual = array();
                $visual_seconds = $audio_seconds;
                if ($host) {
                    $host_url = $this->social_reel_file_data_url((string) $host['path'], 'video/mp4', 'tr_ai_wire_reel_host', 'The talking-host clip is missing.');
                    if (is_wp_error($host_url)) {
                        return $host_url;
                    }
                    $host_seconds = min(
                        (float) $this->social_reel_host_seconds($settings),
                        (float) $this->social_video_duration((string) $host['path'], min(8.0, $audio_seconds))
                    );
                    $visual_seconds = max(3.0, min($host_seconds, $audio_seconds + 1.0));
                    $visual[] = array('timestamp' => 0, 'duration' => $this->social_reel_ms($visual_seconds), 'url' => $host_url);
                } elseif ($motion) {
                    $motion_url = $this->social_reel_file_data_url((string) $motion['path'], 'video/mp4', 'tr_ai_wire_reel_video', 'Generated Reel motion is missing.');
                    if (is_wp_error($motion_url)) {
                        return $motion_url;
                    }
                    $source = $this->social_reel_motion_source_seconds((string) $motion['path'], $settings);
                    $visual = $this->social_fal_loop_keyframes($motion_url, $source, $audio_seconds, 0);
                    $visual_seconds = $audio_seconds;
                }
                if (!$visual) {
                    return new WP_Error('tr_ai_wire_reel_frames', 'The Reel has no video clip to assemble.');
                }
                if ($use_sting) {
                    $endcard = $this->social_reel_endcard($post, $copy, $photo_bytes, $end_caption);
                    if (is_wp_error($endcard)) {
                        return $endcard;
                    }
                    $end_url = $this->social_reel_file_data_url((string) $endcard['path'], 'image/jpeg', 'tr_ai_wire_reel_frames', 'The Reel end card is missing.');
                    if (is_wp_error($end_url)) {
                        return $end_url;
                    }
                    $visual[] = array('timestamp' => $this->social_reel_ms($visual_seconds), 'duration' => $this->social_reel_ms($sting_seconds), 'url' => $end_url);
                }
            }
            return array('tracks' => array(
                array('id' => 'visual', 'type' => 'video', 'keyframes' => $visual),
                array('id' => 'voice', 'type' => 'audio', 'keyframes' => array(
                    array('timestamp' => 0, 'duration' => $this->social_reel_ms($audio_seconds), 'url' => $voice_url),
                )),
            ));
        };
        $store = function ($bytes) use ($post, $copy, $script, $settings) {
            if (!is_string($bytes) || strlen($bytes) < 1024) {
                return new WP_Error('tr_ai_wire_reel_mux', 'Fal did not return a usable Reel video.');
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
        };
        $result = $this->social_fal_step(array(
            'owner_id' => $post->ID,
            'post_id' => $post->ID,
            'meta_key' => '_tr_ai_wire_fal_compose_request',
            'endpoint' => self::SOCIAL_REEL_MUX_ENDPOINT,
            'label' => 'finished MP4',
            'estimate' => round(0.0002 * ($audio_seconds + $sting_seconds), 4),
            'enforce_budget' => false,
            'settings' => $settings,
            'input_key' => $input_key,
            'payload' => $payload,
            'store' => $store,
        ));
        if (!is_wp_error($result) || $result->get_error_code() !== 'tr_ai_wire_reel_video_failed') {
            return $result;
        }
        if ($use_sting) {
            update_post_meta($post->ID, '_tr_ai_wire_reel_no_sting', '1');
            return new WP_Error('tr_ai_wire_reel_video_wait', 'Fal could not place the end card, so the video is being assembled again without it.');
        }
        $kit_files = array('voice.mp3' => (string) $voice['path']);
        if ($host) {
            $kit_files['host.mp4'] = (string) $host['path'];
        }
        if ($motion) {
            $kit_files['motion.mp4'] = (string) $motion['path'];
        }
        $this->store_social_reel_laptop_kit($post, $kit_files, $audio_seconds, $copy);
        return new WP_Error(
            'tr_ai_wire_reel_mux',
            $result->get_error_message() . ' Your voiceover and clips are saved on this row, so Resume only retries the assembly and does not pay for them again.'
        );
    }

    private function social_reel_build_steps($post_id, $settings = null) {
        $post_id = (int) $post_id;
        $settings = is_array($settings) ? $settings : $this->settings();
        $plan = $this->social_reel_plan($settings);
        $steps = array();
        $describe = function ($meta_key) use ($post_id) {
            $pending = $this->social_fal_pending_get($post_id, $meta_key);
            if (!$pending) {
                return array('active' => false, 'detail' => '');
            }
            $detail = 'at Fal for ' . $this->social_fal_elapsed_label($pending) . ' · request ' . substr((string) $pending['request_id'], 0, 8);
            if (!empty($pending['errors'])) {
                $detail .= ' · last status check failed ' . (int) $pending['errors'] . '×';
            }
            return array('active' => true, 'detail' => $detail);
        };
        $steps[] = array(
            'key' => 'voice',
            'label' => 'Voiceover',
            'state' => $this->social_voice_id($post_id) > 0 ? 'done' : 'todo',
            'detail' => '',
        );
        if ($plan['host']) {
            $info = $describe('_tr_ai_wire_fal_host_request');
            $done = $this->social_host_id($post_id) > 0;
            $steps[] = array('key' => 'host', 'label' => 'Talking host', 'state' => $done ? 'done' : ($info['active'] ? 'active' : 'todo'), 'detail' => $done ? '' : $info['detail']);
        }
        if ($plan['motion']) {
            $info = $describe('_tr_ai_wire_fal_request');
            $done = $this->social_motion_id($post_id) > 0;
            $steps[] = array('key' => 'motion', 'label' => 'B-roll motion', 'state' => $done ? 'done' : ($info['active'] ? 'active' : 'todo'), 'detail' => $done ? '' : $info['detail']);
        }
        $info = $describe('_tr_ai_wire_fal_compose_request');
        $done = $this->social_reel_id($post_id) > 0;
        $steps[] = array(
            'key' => 'assemble',
            'label' => !empty($plan['story']) ? 'Assemble the story (speech + news details)' : 'Assemble the MP4',
            'state' => $done ? 'done' : ($info['active'] ? 'active' : 'todo'),
            'detail' => $done ? '' : $info['detail'],
        );
        return $steps;
    }

    private function ensure_social_reel($post, $settings, $copy, $broll_path) {
        if (!$post instanceof WP_Post) {
            return new WP_Error('tr_ai_wire_social_post', 'The source article is no longer published.');
        }
        $settings = $this->social_reel_effective_settings($settings);
        if ($this->social_reel_dev_cheap_on($settings)) {
            $this->social_reel_progress_note($post->ID, 'Dev cheap test is on — using Avatar Standard + Veo Lite 4s.');
        }
        $copy = is_array($copy) ? $copy : array();
        $script = (string) ($copy['reel_script'] ?? '');
        if ($script === '') {
            $script = $this->fallback_reel_script($copy, $post);
        }
        $existing = $this->social_reel_id($post->ID);
        if ($existing > 0 && (string) get_post_meta($existing, '_tr_ai_wire_reel_script', true) === sanitize_textarea_field($script)) {
            $url = wp_get_attachment_url($existing);
            $path = get_attached_file($existing);
            if ($url && $path) {
                return array('id' => $existing, 'url' => $url, 'path' => $path);
            }
        }
        if ($this->social_reel_recipe($settings) === 'story') {
            return $this->ensure_social_reel_story($post, $settings, $copy, $broll_path, $script);
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
        if ((float) ($settings['social_video_budget_usd'] ?? 0) <= 0 && $this->social_motion_id($post->ID) < 1 && !$this->social_fal_pending_get($post->ID, '_tr_ai_wire_fal_request')) {
            return new WP_Error(
                'tr_ai_wire_reel_video_budget',
                'Reel video generation is paused because the monthly video budget is 0. Raise it (default $10) or switch Instagram back to a still format.'
            );
        }
        $plan = $this->social_reel_plan($settings);
        $parts = $this->social_reel_gather($post, $settings, $copy, $broll_path, $plan, $script);
        if (is_wp_error($parts)) {
            return $parts;
        }
        $photo = file_get_contents($broll_path);
        if (!is_string($photo) || $photo === '') {
            return new WP_Error('tr_ai_wire_reel_broll', 'The Reel B-roll file could not be read.');
        }
        $voice = $parts['voice'];
        $motion = $parts['motion'];
        $duration = (float) $voice['seconds'];
        $timeline = $this->build_social_reel_timeline($copy, $duration, $settings);
        $end_caption = '';
        foreach ($timeline as $clip) {
            if (($clip['kind'] ?? '') === 'end') {
                $end_caption = (string) ($clip['caption'] ?? '');
            }
        }
        if (!$plan['local']) {
            return $this->social_reel_assemble_with_fal($post, $settings, $copy, $script, $plan, $parts, $photo, $end_caption);
        }
        $this->social_reel_progress_note($post->ID, 'Assembling the MP4 on this server.');
        $tmp = get_temp_dir() . 'tr-ai-wire-reel-' . wp_generate_password(8, false, false);
        if (!wp_mkdir_p($tmp)) {
            return new WP_Error('tr_ai_wire_reel_temp', 'Could not create a temporary directory for the Reel.');
        }
        $out_path = $tmp . '/reel.mp4';
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
        $muxed = $this->compose_social_reel_motion($motion['path'], $overlays, $end_path, $voice['path'], $out_path, $duration);
        if (is_wp_error($muxed)) {
            $this->social_reel_cleanup($tmp);
            return $muxed;
        }
        return $this->social_reel_store_finished($post, $settings, $copy, $script, $out_path, $tmp);
    }

    private function social_reel_store_finished($post, $settings, $copy, $script, $out_path, $tmp) {
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

    private function ensure_social_reel_story($post, $settings, $copy, $broll_path, $script = '') {
        $settings = $this->social_reel_effective_settings($settings);
        if (!$this->social_reel_can_mux()) {
            return $this->social_reel_mux_error();
        }
        $plan = $this->social_reel_plan($settings);
        if ($plan['host'] && absint($settings['social_reel_host_image_id'] ?? 0) < 1 && empty($settings['social_reel_generate_host_face'])) {
            return new WP_Error(
                'tr_ai_wire_reel_host_image',
                'Story Reels need an avatar face. Upload a portrait, turn on Generate a presenter face, or turn the talking host off. One saved face is used on every Reel.'
            );
        }
        if (($plan['host'] || $plan['motion']) && $this->fal_api_key() === '') {
            return new WP_Error(
                'tr_ai_wire_reel_fal_key',
                'Story Reels need a Fal.ai API key for the talking host and B-roll motion.'
            );
        }
        if ($plan['motion'] && (!is_string($broll_path) || $broll_path === '' || !file_exists($broll_path))) {
            return new WP_Error('tr_ai_wire_reel_broll', 'A text-free 9:16 B-roll frame is required before rendering the Reel.');
        }
        $copy = is_array($copy) ? $copy : array();
        if ($script === '') {
            $script = (string) ($copy['reel_script'] ?? '');
            if ($script === '') {
                $script = $this->fallback_reel_script($copy, $post);
            }
        }
        $parts = $this->social_reel_gather($post, $settings, $copy, $broll_path, $plan, $script);
        if (is_wp_error($parts)) {
            return $parts;
        }
        $voice = $parts['voice'];
        $host = is_array($parts['host']) ? $parts['host'] : null;
        $motion = is_array($parts['motion']) ? $parts['motion'] : null;
        $duration = (float) $voice['seconds'];
        $end_caption = (string) ($copy['cta'] ?? ('Full story on ' . self::site_brand_name()));
        if (!$plan['local']) {
            $photo = '';
            if (is_string($broll_path) && $broll_path !== '' && file_exists($broll_path)) {
                $bytes = file_get_contents($broll_path);
                if (is_string($bytes)) {
                    $photo = $bytes;
                }
            }
            $note = 'Assembling a scroll-stop story: hook on screen, news-detail beats, then sting.';
            if (!empty($plan['host']) && empty($plan['host_seek'])) {
                $note = 'Assembling a scroll-stop story: cold-open details, short host hook, then news beats.';
            } elseif (!empty($plan['host_seek'])) {
                $note = 'Assembling the story Reel at Fal.';
            }
            $this->social_reel_progress_note($post->ID, $note);
            return $this->social_reel_assemble_with_fal($post, $settings, $copy, $script, $plan, $parts, $photo, $end_caption);
        }
        $this->social_reel_progress_note($post->ID, 'Assembling the MP4 on this server.');
        $tmp = get_temp_dir() . 'tr-ai-wire-reel-' . wp_generate_password(8, false, false);
        if (!wp_mkdir_p($tmp)) {
            return new WP_Error('tr_ai_wire_reel_temp', 'Could not create a temporary directory for the Reel.');
        }
        $out_path = $tmp . '/reel.mp4';
        $timeline = $this->build_social_reel_story_timeline($copy, $duration, $settings);
        $clips = array();
        $clock = 0.0;
        foreach ($timeline as $index => $shot) {
            $kind = (string) ($shot['kind'] ?? '');
            $shot_seconds = (float) ($shot['duration'] ?? 0);
            if ($kind === 'host') {
                if (!$host || empty($host['path'])) {
                    $this->social_reel_cleanup($tmp);
                    return new WP_Error('tr_ai_wire_reel_host', 'The talking-host clip is missing.');
                }
                $clips[] = array(
                    'kind' => 'host',
                    'path' => $host['path'],
                    'duration' => $shot_seconds,
                    'offset' => $clock,
                    'crop' => (string) ($shot['crop'] ?? 'medium'),
                    'overlay' => $this->social_reel_write_caption_overlay($tmp, $index, $copy, (string) ($shot['caption'] ?? '')),
                );
                $clock += $shot_seconds;
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
                    'duration' => $shot_seconds,
                    'crop' => (string) ($shot['crop'] ?? 'full'),
                    'overlay' => $this->social_reel_write_caption_overlay($tmp, $index, $copy, (string) ($shot['caption'] ?? '')),
                );
                $clock += $shot_seconds;
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
                'duration' => $shot_seconds,
                'crop' => 'full',
            );
        }
        if ($plan['sting']) {
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
        $muxed = $this->compose_social_reel_story($clips, $voice['path'], $out_path);
        if (is_wp_error($muxed)) {
            $this->social_reel_cleanup($tmp);
            return $muxed;
        }
        return $this->social_reel_store_finished($post, $settings, $copy, $script, $out_path, $tmp);
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

    private function generate_social_reel_host($post, $settings, $copy, $voice, $submit_only = false) {
        $voice_key = (string) ($voice['key'] ?? '');
        $scene_key = $this->social_reel_host_scene_key($post, $copy, $settings);
        $input_key = md5($voice_key . '|' . $scene_key);
        $existing = $this->social_host_id($post->ID);
        if ($existing > 0) {
            $path = get_attached_file($existing);
            $made_for = (string) get_post_meta($existing, '_tr_ai_wire_voice_key', true);
            $made_scene = (string) get_post_meta($existing, '_tr_ai_wire_host_scene_key', true);
            if (
                $path && file_exists($path)
                && ($made_for === '' || $voice_key === '' || $made_for === $voice_key)
                && ($made_scene === '' || $made_scene === $scene_key)
            ) {
                return array('id' => $existing, 'path' => $path);
            }
        }
        $models = $this->social_reel_host_models();
        $endpoint = (string) $models[$this->social_reel_host_model($settings)]['endpoint'];
        return $this->social_fal_step(array(
            'owner_id' => $post->ID,
            'post_id' => $post->ID,
            'meta_key' => '_tr_ai_wire_fal_host_request',
            'endpoint' => $endpoint,
            'label' => 'talking host',
            'estimate' => $this->social_reel_host_estimate_usd($settings, (float) ($voice['seconds'] ?? 0)),
            'enforce_budget' => true,
            'settings' => $settings,
            'input_key' => $input_key,
            'submit_only' => $submit_only,
            'payload' => function () use ($post, $settings, $copy, $voice, $scene_key) {
                $image_url = $this->social_reel_host_face_url($settings, $post, $copy);
                if (is_wp_error($image_url)) {
                    return $image_url;
                }
                $audio_url = $this->social_reel_file_data_url((string) ($voice['path'] ?? ''), 'audio/mpeg', 'tr_ai_wire_reel_tts', 'A spoken Reel script is required.');
                if (is_wp_error($audio_url)) {
                    return $audio_url;
                }
                $look = $this->social_reel_host_scene_look($post, $copy);
                return array(
                    'image_url' => $image_url,
                    'audio_url' => $audio_url,
                    'prompt' => 'The same news presenter speaking to camera in this exact wardrobe and place: '
                        . $look['outfit'] . ', ' . $look['place']
                        . '. Natural blinks, subtle head motion, no extra people, no text, no logos.',
                );
            },
            'store' => function ($bytes) use ($post, $copy, $voice_key, $scene_key) {
                $stored = $this->store_social_host($post, $bytes, $copy);
                if (!is_wp_error($stored)) {
                    if ($voice_key !== '') {
                        update_post_meta((int) $stored['id'], '_tr_ai_wire_voice_key', $voice_key);
                    }
                    update_post_meta((int) $stored['id'], '_tr_ai_wire_host_scene_key', $scene_key);
                }
                return $stored;
            },
        ));
    }

    private function social_reel_host_face_url($settings, $post = null, $copy = null) {
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
        if ($post instanceof WP_Post && !empty($settings['social_reel_vary_host_scene'])) {
            $scene = $this->ensure_social_reel_host_scene($post, $settings, is_array($copy) ? $copy : array(), (int) $image_id);
            if (!is_wp_error($scene)) {
                $image_id = (int) $scene;
            }
        }
        return $this->social_reel_attachment_data_url((int) $image_id, 'tr_ai_wire_reel_host_image', 'The host portrait could not be read.');
    }

    private function social_reel_host_scene_look($post, $copy = array()) {
        $copy = is_array($copy) ? $copy : array();
        $seed = abs(crc32((string) ((int) ($post->ID ?? 0)) . '|' . (string) ($copy['hook'] ?? $post->post_title ?? '') . '|' . (string) ($copy['visual_brief'] ?? '')));
        $outfits = array(
            'charcoal tailored blazer over a plain shirt',
            'navy knit polo under an open jacket',
            'cream crewneck sweater',
            'black turtleneck',
            'soft grey shirt with the top button open',
            'deep green blazer over a white tee',
        );
        $places = array(
            'modern news desk with soft bokeh screens behind',
            'bright loft studio with daylight windows',
            'quiet city balcony at dusk with soft skyline bokeh',
            'warm library corner with shelves softly blurred',
            'minimal concrete studio with a single key light',
            'rooftop terrace with distant city lights',
            'glass office lounge with plants in the background',
            'night street café awning softly out of focus',
        );
        $lights = array(
            'soft key light from camera left',
            'clean daylight',
            'warm golden-hour rim light',
            'cool evening practical lights',
        );
        return array(
            'outfit' => $outfits[$seed % count($outfits)],
            'place' => $places[$seed % count($places)],
            'lighting' => $lights[$seed % count($lights)],
        );
    }

    private function social_reel_host_scene_key($post, $copy, $settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        if (empty($settings['social_reel_vary_host_scene'])) {
            return 'canonical';
        }
        $look = $this->social_reel_host_scene_look($post, $copy);
        $face = (string) $this->saved_social_reel_host_image_id();
        return md5(implode('|', array(
            $face,
            $look['outfit'],
            $look['place'],
            $look['lighting'],
            $this->social_image_scene_brief((string) (($copy['visual_brief'] ?? ''))),
        )));
    }

    private function social_reel_host_scene_prompt($post, $copy, $settings = null) {
        $look = $this->social_reel_host_scene_look($post, $copy);
        $brief = $this->social_image_scene_brief((string) (($copy['visual_brief'] ?? '')));
        $hook = $this->clip_social_text((string) ($copy['hook'] ?? $post->post_title), 80);
        $prompt = 'Keep this exact person\'s face, facial features, skin tone, age, hairline, and identity. Do not invent a different person or celebrity. '
            . 'Change only wardrobe, location, and backdrop. Photoreal vertical head-and-shoulders portrait looking straight at camera, neutral expression ready to speak. '
            . 'Wardrobe: ' . $look['outfit'] . '. Place/backdrop: ' . $look['place'] . '. Lighting: ' . $look['lighting'] . '. ';
        if ($brief !== '') {
            $prompt .= 'Story mood (do not put text in the image): ' . $brief . '. ';
        } elseif ($hook !== '') {
            $prompt .= 'Story mood (do not put text in the image): ' . $hook . '. ';
        }
        $prompt .= 'Fully clothed adult news presenter, sharp face, natural skin, no logos, no text, no watermarks, no lower-third.';
        return $prompt;
    }

    private function ensure_social_reel_host_scene($post, $settings, $copy, $face_id) {
        if (!$post instanceof WP_Post) {
            return new WP_Error('tr_ai_wire_social_post', 'The source article is no longer published.');
        }
        $face_id = (int) $face_id;
        if ($face_id < 1) {
            return new WP_Error('tr_ai_wire_reel_host_image', 'The host portrait could not be read.');
        }
        $key = $this->social_reel_host_scene_key($post, $copy, $settings);
        $existing = (int) get_post_meta($post->ID, '_tr_ai_wire_social_host_scene_id', true);
        if ($existing > 0 && $this->social_image_file_exists($existing) && (string) get_post_meta($existing, '_tr_ai_wire_host_scene_key', true) === $key) {
            return $existing;
        }
        $this->social_reel_progress_note($post->ID, 'Dressing the host for this story (same face, new place).');
        $generated = $this->generate_social_reel_host_scene($post, $settings, $copy, $face_id, $key);
        if (is_wp_error($generated)) {
            return $generated;
        }
        update_post_meta($post->ID, '_tr_ai_wire_social_host_scene_id', (int) $generated);
        return (int) $generated;
    }

    private function generate_social_reel_host_scene($post, $settings, $copy, $face_id, $key) {
        $month_key = 'tr_ai_wire_social_image_usage_' . gmdate('Ym');
        $usage = (float) get_option($month_key, 0);
        $budget = (float) ($settings['social_image_budget_usd'] ?? 0);
        if ($budget <= 0 || ($usage + self::SOCIAL_IMAGE_ESTIMATED_USD) > $budget) {
            return new WP_Error('tr_ai_wire_social_image_budget', 'Social image generation is paused because its monthly image budget is exhausted.');
        }
        $api_key = $this->api_key();
        if (!$api_key) {
            return new WP_Error('tr_ai_wire_social_image_key', 'Add the OpenAI API key before restyling the host for a new place.');
        }
        $source = $this->social_reel_attachment_data_url((int) $face_id, 'tr_ai_wire_reel_host_image', 'The host portrait could not be read for a new scene.');
        if (is_wp_error($source)) {
            return $source;
        }
        $prompt = $this->social_reel_host_scene_prompt($post, $copy, $settings);
        $response = wp_remote_post('https://api.openai.com/v1/images/edits', array(
            'timeout' => 120,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => self::SOCIAL_IMAGE_MODEL,
                'prompt' => $prompt,
                'images' => array(array('image_url' => $source)),
                'size' => '1024x1536',
                'quality' => 'low',
                'output_format' => 'jpeg',
                'n' => 1,
            )),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_reel_host_scene', $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || empty($data['data'][0]['b64_json'])) {
            $message = sanitize_text_field($data['error']['message'] ?? ('Image edit API returned HTTP ' . $status . '.'));
            return new WP_Error('tr_ai_wire_reel_host_scene', $message);
        }
        $bytes = base64_decode($data['data'][0]['b64_json'], true);
        if (!$bytes) {
            return new WP_Error('tr_ai_wire_reel_host_scene', 'OpenAI returned an invalid host-scene image.');
        }
        update_option($month_key, round($usage + self::SOCIAL_IMAGE_ESTIMATED_USD, 6), false);
        $stored = $this->store_social_host_face(
            $bytes,
            'technorapper-social-host-scene-' . (int) $post->ID . '.jpg',
            sanitize_text_field((string) ($copy['hook'] ?? $post->post_title) . ' — host scene')
        );
        if (is_wp_error($stored)) {
            return $stored;
        }
        update_post_meta((int) $stored, '_tr_ai_wire_host_scene_key', $key);
        update_post_meta((int) $stored, '_tr_ai_wire_host_scene_face_id', (int) $face_id);
        return (int) $stored;
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
        $result = $this->social_fal_step(array(
            'owner_id' => $image_id,
            'post_id' => 0,
            'meta_key' => '_tr_ai_wire_fal_face_request',
            'endpoint' => 'fal-ai/codeformer',
            'label' => 'host face',
            'estimate' => self::SOCIAL_REEL_FACE_ENHANCE_USD,
            'enforce_budget' => false,
            'track_usage' => false,
            'settings' => $settings,
            'payload' => function () use ($image_id) {
                $source = $this->social_reel_attachment_data_url($image_id, 'tr_ai_wire_reel_host_image', 'The host portrait could not be read for face enhancement.');
                if (is_wp_error($source)) {
                    return $source;
                }
                return array(
                    'image_url' => $source,
                    'fidelity' => 0.6,
                    'upscale_factor' => 2,
                    'face_upscale' => true,
                    'only_center_face' => true,
                );
            },
            'store' => function ($bytes) use ($image_id) {
                return $this->store_enhanced_host_face($image_id, $bytes);
            },
        ));
        return $result;
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
            return $this->social_reel_mux_error();
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
                $input = array('-stream_loop', '-1');
                if ($kind === 'host') {
                    $offset = max(0.0, (float) ($clip['offset'] ?? 0));
                    $length = $this->social_video_duration((string) $clip['path'], 0);
                    if ($length > 0 && ($offset + $duration) > $length) {
                        $offset = max(0.0, $length - $duration);
                    }
                    if ($offset > 0.05) {
                        $input[] = '-ss';
                        $input[] = number_format($offset, 2, '.', '');
                    }
                }
                $args = array_merge($args, $input, array('-t', $clip_t, '-i', (string) $clip['path']));
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

    private function generate_social_reel_motion($post, $settings, $copy, $broll_path, $submit_only = false) {
        $existing = $this->social_motion_id($post->ID);
        if ($existing > 0) {
            return array('id' => $existing, 'path' => (string) get_attached_file($existing));
        }
        $model = $this->social_reel_video_model($settings);
        $models = $this->social_reel_video_models();
        $endpoint = (string) $models[$model]['endpoint'];
        return $this->social_fal_step(array(
            'owner_id' => $post->ID,
            'post_id' => $post->ID,
            'meta_key' => '_tr_ai_wire_fal_request',
            'endpoint' => $endpoint,
            'label' => 'B-roll motion',
            'estimate' => $this->social_reel_video_estimate_usd($settings),
            'enforce_budget' => true,
            'settings' => $settings,
            'submit_only' => $submit_only,
            'payload' => function () use ($post, $settings, $copy, $broll_path, $model) {
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
                return $this->social_fal_motion_payload($model, $image_url, $prompt, $this->social_reel_clip_seconds($settings));
            },
            'store' => function ($bytes) use ($post, $copy) {
                return $this->store_social_motion($post, $bytes, $copy);
            },
        ));
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
        foreach (array($result, $result['data'] ?? array(), $result['output'] ?? array(), $result['response'] ?? array()) as $node) {
            if (!is_array($node) || !$node) {
                continue;
            }
            foreach (array('video_url', 'image_url', 'url') as $key) {
                $url = (string) ($node[$key] ?? '');
                if ($url !== '' && wp_http_validate_url($url)) {
                    return $url;
                }
            }
            foreach (array('video', 'image', 'audio') as $key) {
                $url = (string) ($node[$key]['url'] ?? '');
                if ($url !== '' && wp_http_validate_url($url)) {
                    return $url;
                }
            }
            if (!empty($node['images'][0]['url'])) {
                $url = (string) $node['images'][0]['url'];
                if ($url !== '' && wp_http_validate_url($url)) {
                    return $url;
                }
            }
        }
        return '';
    }

    private function social_fal_queue_app($endpoint) {
        $parts = array_values(array_filter(explode('/', trim((string) $endpoint, '/'))));
        if (count($parts) >= 2) {
            return $parts[0] . '/' . $parts[1];
        }
        return implode('/', $parts);
    }

    private function social_fal_poll_seconds() {
        return max(0, (int) apply_filters('tr_ai_wire_fal_poll_seconds', 12));
    }

    private function social_fal_pending_meta($submitted, $endpoint, $estimate, $extra = array()) {
        $submitted = is_array($submitted) ? $submitted : array();
        return array_merge(array(
            'request_id' => (string) ($submitted['request_id'] ?? ''),
            'endpoint' => (string) $endpoint,
            'status_url' => (string) ($submitted['status_url'] ?? ''),
            'response_url' => (string) ($submitted['response_url'] ?? ''),
            'estimated_usd' => (float) $estimate,
            'submitted_at' => time(),
            'checks' => 0,
            'errors' => 0,
        ), is_array($extra) ? $extra : array());
    }

    private function social_fal_pending_get($owner_id, $meta_key) {
        $data = json_decode((string) get_post_meta((int) $owner_id, (string) $meta_key, true), true);
        if (!is_array($data) || empty($data['request_id']) || empty($data['endpoint'])) {
            return array();
        }
        return $data;
    }

    private function social_fal_pending_save($owner_id, $meta_key, $pending) {
        update_post_meta((int) $owner_id, (string) $meta_key, wp_json_encode($pending));
    }

    private function social_fal_pending_clear($owner_id, $meta_key) {
        delete_post_meta((int) $owner_id, (string) $meta_key);
    }

    private function social_fal_https_url($url) {
        $url = is_string($url) ? $url : '';
        return ($url !== '' && wp_http_validate_url($url) && strpos($url, 'https://') === 0) ? $url : '';
    }

    private function social_fal_queue_urls($endpoint, $request_id, $urls = array()) {
        $urls = is_array($urls) ? $urls : array();
        $app = $this->social_fal_queue_app($endpoint);
        $id = rawurlencode((string) $request_id);
        $status = $this->social_fal_https_url($urls['status_url'] ?? '');
        $response = $this->social_fal_https_url($urls['response_url'] ?? '');
        if ($status === '') {
            $status = 'https://queue.fal.run/' . $app . '/requests/' . $id . '/status';
        }
        if ($response === '') {
            $response = 'https://queue.fal.run/' . $app . '/requests/' . $id;
        }
        return array($status, $response);
    }

    private function social_fal_download_media($file_url) {
        if ($file_url === '' || !wp_http_validate_url($file_url)) {
            return new WP_Error('tr_ai_wire_reel_fal_hiccup', 'Fal finished but did not return a media file URL.');
        }
        $download = wp_remote_get($file_url, array('timeout' => 90));
        if (is_wp_error($download)) {
            return new WP_Error('tr_ai_wire_reel_fal_hiccup', 'Could not download the finished file from Fal: ' . $download->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($download);
        $bytes = wp_remote_retrieve_body($download);
        if ($code < 200 || $code >= 300 || !is_string($bytes) || strlen($bytes) < 512) {
            return new WP_Error('tr_ai_wire_reel_fal_hiccup', 'Fal returned an empty media file (HTTP ' . $code . ').');
        }
        return $bytes;
    }

    private function social_fal_submit($endpoint, $payload) {
        $key = $this->fal_api_key();
        if ($key === '') {
            return new WP_Error('tr_ai_wire_reel_fal_key', 'Instagram Reels need a Fal.ai API key to generate motion.');
        }
        $response = wp_remote_post('https://queue.fal.run/' . ltrim((string) $endpoint, '/'), array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Key ' . $key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_reel_video', 'Could not reach Fal to start the job: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['request_id'])) {
            $message = sanitize_text_field($data['detail'] ?? ($data['error'] ?? ('Fal queue returned HTTP ' . $status . '.')));
            if (is_array($data['detail'] ?? null)) {
                $message = sanitize_text_field(wp_json_encode($data['detail']));
            }
            if ($status === 401 || $status === 403) {
                return new WP_Error('tr_ai_wire_reel_fal_auth', 'Fal refused the API key (HTTP ' . $status . '). Check the Fal key on Social settings and that it can use the queue API.');
            }
            return new WP_Error('tr_ai_wire_reel_video', $message !== '' ? $message : 'Fal did not accept the video job.');
        }
        return $data;
    }

    private function social_fal_failure_message($payload, $response_url, $headers) {
        $message = '';
        foreach (array('error', 'detail', 'message') as $field) {
            if (is_string($payload[$field] ?? null) && trim((string) $payload[$field]) !== '') {
                $message = (string) $payload[$field];
                break;
            }
        }
        if ($message === '' && $response_url !== '') {
            $response = wp_remote_get($response_url, array('timeout' => 20, 'headers' => $headers));
            if (!is_wp_error($response)) {
                $data = json_decode((string) wp_remote_retrieve_body($response), true);
                $detail = is_array($data) ? ($data['detail'] ?? ($data['error'] ?? '')) : '';
                if (is_array($detail)) {
                    $first = reset($detail);
                    $detail = is_array($first) ? (string) ($first['msg'] ?? wp_json_encode($first)) : (string) $first;
                }
                $message = (string) $detail;
            }
        }
        $message = trim(sanitize_text_field($message));
        return $message !== '' ? mb_substr($message, 0, 240) : 'no reason given';
    }

    /**
     * One bounded look at a Fal queue request. It never blocks longer than the
     * poll window, and it never decides to resubmit: callers keep the request id.
     *
     * Error codes: tr_ai_wire_reel_video_wait (still running), tr_ai_wire_reel_video_failed
     * (Fal says it failed), tr_ai_wire_reel_fal_auth (key refused), tr_ai_wire_reel_fal_hiccup
     * (could not read the answer; the job may well be fine).
     */
    private function social_fal_await($endpoint, $request_id, $urls = array(), $label = '') {
        $key = $this->fal_api_key();
        if ($key === '') {
            return new WP_Error('tr_ai_wire_reel_fal_key', 'Instagram Reels need a Fal.ai API key to generate motion.');
        }
        $request_id = (string) $request_id;
        if ($request_id === '') {
            return new WP_Error('tr_ai_wire_reel_fal_hiccup', 'Fal did not return a request id.');
        }
        list($status_url, $response_url) = $this->social_fal_queue_urls($endpoint, $request_id, $urls);
        $headers = array(
            'Authorization' => 'Key ' . $key,
            'Accept' => 'application/json',
        );
        $what = $label !== '' ? $label : 'Reel motion';
        $deadline = time() + $this->social_fal_poll_seconds();
        do {
            $this->social_heartbeat();
            $status_response = wp_remote_get($status_url, array('timeout' => 20, 'headers' => $headers));
            if (is_wp_error($status_response)) {
                return new WP_Error('tr_ai_wire_reel_fal_hiccup', 'Could not reach Fal to check the ' . $what . ': ' . $status_response->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($status_response);
            $payload = json_decode((string) wp_remote_retrieve_body($status_response), true);
            $payload = is_array($payload) ? $payload : array();
            if ($code === 401 || $code === 403) {
                return new WP_Error('tr_ai_wire_reel_fal_auth', 'Fal refused the API key (HTTP ' . $code . ') while checking the ' . $what . '. Check the Fal key on Social settings and that it can use the queue API.');
            }
            if ($code < 200 || $code >= 300) {
                $detail = is_string($payload['detail'] ?? null) ? sanitize_text_field((string) $payload['detail']) : '';
                return new WP_Error('tr_ai_wire_reel_fal_hiccup', 'Fal answered HTTP ' . $code . ' while checking the ' . $what . ($detail !== '' ? (': ' . $detail) : '.'));
            }
            $state = strtoupper((string) ($payload['status'] ?? ''));
            $inline = $this->social_fal_result_file_url($payload);
            if ($inline !== '' && ($state === '' || in_array($state, array('COMPLETED', 'SUCCESS', 'OK'), true))) {
                return $this->social_fal_download_media($inline);
            }
            if (in_array($state, array('COMPLETED', 'SUCCESS', 'OK'), true)) {
                $result_url = $this->social_fal_https_url($payload['response_url'] ?? '') ?: $response_url;
                $result_response = wp_remote_get($result_url, array('timeout' => 45, 'headers' => $headers));
                if (is_wp_error($result_response)) {
                    return new WP_Error('tr_ai_wire_reel_fal_hiccup', 'Could not read the finished ' . $what . ' from Fal: ' . $result_response->get_error_message());
                }
                $result_code = (int) wp_remote_retrieve_response_code($result_response);
                $result = json_decode((string) wp_remote_retrieve_body($result_response), true);
                if ($result_code < 200 || $result_code >= 300) {
                    return new WP_Error('tr_ai_wire_reel_fal_hiccup', 'Fal result HTTP ' . $result_code . ' for the ' . $what . '.');
                }
                return $this->social_fal_download_media($this->social_fal_result_file_url(is_array($result) ? $result : array()));
            }
            if (in_array($state, array('FAILED', 'CANCELLED', 'ERROR'), true)) {
                return new WP_Error(
                    'tr_ai_wire_reel_video_failed',
                    'Fal could not render the ' . $what . ': ' . $this->social_fal_failure_message($payload, $response_url, $headers)
                );
            }
            if (time() >= $deadline) {
                return new WP_Error(
                    'tr_ai_wire_reel_video_wait',
                    'Fal is still generating the ' . $what . '.',
                    array(
                        'state' => $state,
                        'position' => isset($payload['queue_position']) ? (int) $payload['queue_position'] : null,
                    )
                );
            }
            sleep(3);
        } while (true);
    }

    private function social_fal_elapsed_label($pending) {
        $elapsed = max(0, time() - (int) ($pending['submitted_at'] ?? time()));
        if ($elapsed < 60) {
            return (string) $elapsed . 's';
        }
        return (string) floor($elapsed / 60) . 'm ' . sprintf('%02d', $elapsed % 60) . 's';
    }

    private function social_fal_wait_message($pending, $label, $data = null) {
        $state = is_array($data) ? (string) ($data['state'] ?? '') : '';
        $where = 'rendering at Fal';
        if ($state === 'IN_QUEUE') {
            $position = is_array($data) && isset($data['position']) && $data['position'] !== null ? (' (queue position ' . (int) $data['position'] . ')') : '';
            $where = 'waiting in the Fal queue' . $position;
        }
        return ucfirst((string) $label) . ' ' . $where . ' for ' . $this->social_fal_elapsed_label($pending) . '.';
    }

    private function social_fal_submit_locked($args) {
        $owner = (int) $args['owner_id'];
        $meta = (string) $args['meta_key'];
        $label = (string) $args['label'];
        $lock = 'tr_ai_wire_fal_submit_' . md5($owner . '|' . $meta);
        if (!add_option($lock, time(), '', false)) {
            $locked = (int) get_option($lock, 0);
            if ($locked && $locked < time() - 120) {
                delete_option($lock);
            }
            if (!add_option($lock, time(), '', false)) {
                return new WP_Error('tr_ai_wire_reel_video_wait', 'Another worker is starting the ' . $label . '.');
            }
        }
        try {
            $pending = $this->social_fal_pending_get($owner, $meta);
            if ($pending) {
                return $pending;
            }
            $estimate = (float) ($args['estimate'] ?? 0);
            $month_key = 'tr_ai_wire_social_video_usage_' . gmdate('Ym');
            $usage = (float) get_option($month_key, 0);
            if (!empty($args['enforce_budget'])) {
                $budget = (float) (($args['settings']['social_video_budget_usd'] ?? 0));
                if ($budget <= 0 || ($usage + $estimate) > $budget) {
                    return new WP_Error('tr_ai_wire_reel_video_budget', $this->social_reel_budget_message($estimate, $usage, $budget));
                }
            }
            $payload = call_user_func($args['payload']);
            if (is_wp_error($payload)) {
                return $payload;
            }
            $this->social_reel_progress_note((int) $args['post_id'], 'Starting the ' . $label . ' at Fal.');
            $submitted = $this->social_fal_submit((string) $args['endpoint'], $payload);
            if (is_wp_error($submitted)) {
                return $submitted;
            }
            if (!empty($args['track_usage'])) {
                update_option($month_key, round($usage + $estimate, 6), false);
            }
            $pending = $this->social_fal_pending_meta($submitted, (string) $args['endpoint'], $estimate, array(
                'label' => $label,
                'input_key' => (string) ($args['input_key'] ?? ''),
            ));
            $this->social_fal_pending_save($owner, $meta, $pending);
            $this->social_reel_ledger_add((int) $args['post_id'], $label, $estimate, (string) $pending['request_id']);
            return $pending;
        } finally {
            delete_option($lock);
        }
    }

    /**
     * Resumable Fal job: submit once, remember the request id, look at it for a
     * short window, store the finished file. Calling it again never pays twice.
     */
    private function social_fal_step($args) {
        $owner = (int) $args['owner_id'];
        $meta = (string) $args['meta_key'];
        $label = (string) $args['label'];
        $post_id = (int) ($args['post_id'] ?? $owner);
        $args['post_id'] = $post_id;
        $args['track_usage'] = array_key_exists('track_usage', $args) ? !empty($args['track_usage']) : true;
        $input_key = (string) ($args['input_key'] ?? '');
        $pending = $this->social_fal_pending_get($owner, $meta);
        if ($pending && $input_key !== '' && isset($pending['input_key']) && (string) $pending['input_key'] !== $input_key) {
            $this->social_fal_pending_clear($owner, $meta);
            $pending = array();
        }
        if (!$pending) {
            $pending = $this->social_fal_submit_locked($args);
            if (is_wp_error($pending)) {
                return $pending;
            }
        }
        if (!empty($args['submit_only'])) {
            return new WP_Error('tr_ai_wire_reel_video_wait', $this->social_fal_wait_message($pending, $label));
        }
        $polled = $this->social_fal_await((string) $pending['endpoint'], (string) $pending['request_id'], $pending, $label);
        if (!is_wp_error($polled)) {
            $stored = call_user_func($args['store'], $polled);
            if (is_wp_error($stored)) {
                return $stored;
            }
            $this->social_fal_pending_clear($owner, $meta);
            return $stored;
        }
        $code = $polled->get_error_code();
        $pending['checks'] = (int) ($pending['checks'] ?? 0) + 1;
        $pending['last_checked'] = time();
        if ($code === 'tr_ai_wire_reel_video_wait') {
            $pending['errors'] = 0;
            $this->social_fal_pending_save($owner, $meta, $pending);
            $message = $this->social_fal_wait_message($pending, $label, $polled->get_error_data());
            $this->social_reel_progress_note($post_id, $message);
            if ((time() - (int) ($pending['submitted_at'] ?? time())) > 1800) {
                return new WP_Error(
                    'tr_ai_wire_reel_fal_slow',
                    ucfirst($label) . ' has been at Fal for over 30 minutes (request id ' . (string) $pending['request_id'] . '). It may still finish: click Resume to keep waiting, or check that request on the Fal dashboard.'
                );
            }
            return new WP_Error('tr_ai_wire_reel_video_wait', $message);
        }
        if ($code === 'tr_ai_wire_reel_video_failed') {
            $this->social_fal_pending_clear($owner, $meta);
            return $polled;
        }
        $pending['errors'] = (int) ($pending['errors'] ?? 0) + 1;
        $pending['last_error'] = $polled->get_error_message();
        $this->social_fal_pending_save($owner, $meta, $pending);
        if ($code === 'tr_ai_wire_reel_fal_auth' || (int) $pending['errors'] >= 6) {
            return new WP_Error(
                $code,
                $polled->get_error_message() . ' Fal request id ' . (string) $pending['request_id'] . ' is saved, so clicking Resume checks it again without paying twice.'
            );
        }
        $message = ucfirst($label) . ' is with Fal; the last status check did not answer cleanly. Checking again shortly.';
        $this->social_reel_progress_note($post_id, $message);
        return new WP_Error('tr_ai_wire_reel_video_wait', $message);
    }

    private function social_reel_budget_message($estimate, $usage, $budget) {
        $left = max(0.0, (float) $budget - (float) $usage);
        return 'Reel video generation is paused. The next step needs about $' . number_format((float) $estimate, 2) . ' at Fal but only $' . number_format($left, 2) . ' of the $' . number_format((float) $budget, 2) . ' monthly video budget is left. Raise the budget on Social settings, then click Resume. Everything already rendered is kept.';
    }

    private function social_reel_progress_note($post_id, $detail) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return;
        }
        update_post_meta($post_id, '_tr_ai_wire_reel_progress', wp_json_encode(array(
            'detail' => sanitize_text_field((string) $detail),
            'at' => time(),
        )));
        $this->social_heartbeat();
    }

    private function social_reel_progress($post_id) {
        $data = json_decode((string) get_post_meta((int) $post_id, '_tr_ai_wire_reel_progress', true), true);
        return is_array($data) ? $data : array();
    }

    private function social_reel_ledger_add($post_id, $label, $usd, $request_id) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return;
        }
        $ledger = $this->social_reel_ledger($post_id);
        $ledger[] = array(
            'label' => sanitize_text_field((string) $label),
            'usd' => round((float) $usd, 4),
            'request_id' => sanitize_text_field((string) $request_id),
            'at' => time(),
        );
        update_post_meta($post_id, '_tr_ai_wire_reel_ledger', wp_json_encode(array_slice($ledger, -12)));
    }

    private function social_reel_ledger($post_id) {
        $data = json_decode((string) get_post_meta((int) $post_id, '_tr_ai_wire_reel_ledger', true), true);
        return is_array($data) ? array_values($data) : array();
    }

    private function social_reel_spent_usd($post_id) {
        $total = 0.0;
        foreach ($this->social_reel_ledger($post_id) as $entry) {
            $total += (float) ($entry['usd'] ?? 0);
        }
        return round($total, 4);
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
            'Instagram Reels need a Fal.ai API key to assemble the video on this host (no local ffmpeg). Save it on Social settings, then click Resume. Download video and Post appear when the MP4 is ready. The plugin will not fall back to a slideshow of posters.'
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
        // Hard-cap each chunk: a keyframe longer than the real clip freezes on the last frame at Fal.
        $source_seconds = max(1.0, min(15.0, (float) $source_seconds));
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

    private function compose_social_reel_motion($motion_path, $overlays, $end_path, $audio_path, $out_path, $audio_duration) {
        $ffmpeg = $this->social_ffmpeg_path();
        if ($ffmpeg === '') {
            return $this->social_reel_mux_error();
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

    private function social_video_duration($path, $fallback = null) {
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
                if ($seconds >= 1 && $seconds <= 90) {
                    return $seconds;
                }
            }
        }
        $seconds = $this->social_mp4_duration($path);
        if ($seconds >= 1 && $seconds <= 90) {
            return $seconds;
        }
        return $fallback !== null ? (float) $fallback : (float) $this->social_reel_clip_seconds();
    }

    private function social_mp4_duration($path) {
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return 0.0;
        }
        $size = (int) filesize($path);
        if ($size < 64) {
            return 0.0;
        }
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return 0.0;
        }
        $chunks = array((string) fread($handle, 1048576));
        if ($size > 1048576) {
            fseek($handle, max(0, $size - 4194304));
            $chunks[] = (string) fread($handle, 4194304);
        }
        fclose($handle);
        foreach ($chunks as $data) {
            $at = strpos($data, 'mvhd');
            if ($at === false || strlen($data) < $at + 28) {
                continue;
            }
            $version = ord($data[$at + 4]);
            if ($version === 1 && strlen($data) >= $at + 36) {
                $scale = unpack('N', substr($data, $at + 24, 4));
                $high = unpack('N', substr($data, $at + 28, 4));
                $low = unpack('N', substr($data, $at + 32, 4));
                $units = ($high[1] * 4294967296) + $low[1];
            } else {
                $scale = unpack('N', substr($data, $at + 16, 4));
                $units = unpack('N', substr($data, $at + 20, 4));
                $units = $units[1];
            }
            if (!empty($scale[1]) && $units > 0) {
                return round($units / $scale[1], 3);
            }
        }
        return 0.0;
    }

    private function social_mp3_duration($path) {
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return 0.0;
        }
        $size = (int) filesize($path);
        if ($size < 512 || $size > 20971520) {
            return 0.0;
        }
        $data = file_get_contents($path);
        if (!is_string($data)) {
            return 0.0;
        }
        $length = strlen($data);
        $pos = 0;
        if (substr($data, 0, 3) === 'ID3' && $length > 10) {
            $pos = 10 + ((ord($data[6]) & 0x7f) << 21) + ((ord($data[7]) & 0x7f) << 14) + ((ord($data[8]) & 0x7f) << 7) + (ord($data[9]) & 0x7f);
        }
        $rates = array(
            3 => array(44100, 48000, 32000),
            2 => array(22050, 24000, 16000),
            0 => array(11025, 12000, 8000),
        );
        $bitrates_v1 = array(0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320);
        $bitrates_v2 = array(0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160);
        $seconds = 0.0;
        $frames = 0;
        while ($pos + 4 <= $length) {
            $header = (ord($data[$pos]) << 24) | (ord($data[$pos + 1]) << 16) | (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
            if (($header & 0xFFE00000) !== 0xFFE00000) {
                $pos++;
                continue;
            }
            $version = ($header >> 19) & 3;
            $layer = ($header >> 17) & 3;
            $bitrate_index = ($header >> 12) & 15;
            $rate_index = ($header >> 10) & 3;
            $padding = ($header >> 9) & 1;
            if ($version === 1 || $layer !== 1 || $bitrate_index === 0 || $bitrate_index === 15 || $rate_index === 3) {
                $pos++;
                continue;
            }
            $v1 = $version === 3;
            $bitrate = ($v1 ? $bitrates_v1[$bitrate_index] : $bitrates_v2[$bitrate_index]) * 1000;
            $rate = $rates[$version][$rate_index];
            $frame_length = (int) floor(($v1 ? 144 : 72) * $bitrate / $rate) + $padding;
            if ($frame_length < 24) {
                $pos++;
                continue;
            }
            $seconds += ($v1 ? 1152 : 576) / $rate;
            $pos += $frame_length;
            $frames++;
        }
        return $frames >= 10 ? round($seconds, 3) : 0.0;
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
        $max = $this->social_reel_max_audio_seconds($settings);
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
                if ($seconds >= 3 && $seconds <= 90) {
                    return max(3.0, min($max, $seconds));
                }
            }
        }
        $seconds = $this->social_mp3_duration($path);
        if ($seconds >= 3 && $seconds <= 90) {
            return max(3.0, min($max, $seconds));
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
