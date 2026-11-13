<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Instagram Reels are one spoken story, not a slideshow of posters.
 *
 * A carousel packs several article cards. A collage grids those cards.
 * A Ken Burns pass over those JPEGs is still a slideshow. This renderer
 * does none of that: one article, a voiceover as the timeline, lesson cards
 * that follow the spoken beats, short Fal Veo accents from one B-roll still.
 * Every cut (story or B-roll recipe, Fal compose or local ffmpeg) is built from
 * social_reel_teach_slots(): readable lesson cards carry the voice, B-roll motion
 * is at most two short accents, and social_reel_teach_guard() refuses anything
 * else. There is no motion-bed fallback — missing cards stop the build.
 * Fal's compose video track accepts only video files — JPEG stills on that
 * track produced multi-hour broken outputs (still frame + voice), so cards are
 * baked to MP4 clips of their exact slot length first. Best roundups skip B-roll
 * and stitch unique JPEG cards with Fal images-to-video (frames/fps) so each
 * scene is held for seconds, then mux that held video with the voice. The mux
 * duration is the voiceover. If Fal returns a longer hold clip than frames/fps,
 * the picture track is rebuilt at a scaled frame count so leftover freeze is
 * never posted. Compose image-track timestamps were treated
 * as 1-frame flashes. Each roundup card is a unique 2D newsroom illustration of
 * that desk story, not a recycled pose.
 * Talking-host lip-sync remains an optional paid upsell.
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
        if ($this->social_reel_cut_is_stale($video_id)) {
            return 0;
        }
        return $video_id;
    }

    /**
     * A plugin-made Reel from an older cut (the looping B-roll bed) is stale.
     * Laptop uploads are stamped "upload" and always honoured.
     */
    private function social_reel_cut_is_stale($video_id) {
        $video_id = (int) $video_id;
        if ($video_id < 1 || get_post_meta($video_id, '_tr_ai_wire_social_asset', true) !== '1') {
            return false;
        }
        $cut = (string) get_post_meta($video_id, '_tr_ai_wire_reel_cut', true);
        return $cut !== $this->social_reel_cut_name() && $cut !== 'upload';
    }

    /** Delete a stale-cut finished Reel so Resume rebuilds it, and hold the rebuild for review. */
    private function social_reel_drop_stale_cut($post_id) {
        $post_id = (int) $post_id;
        $video_id = (int) get_post_meta($post_id, '_tr_ai_wire_social_reel_id', true);
        if ($video_id < 1 || !$this->social_reel_cut_is_stale($video_id)) {
            return false;
        }
        wp_delete_attachment($video_id, true);
        $kit = (int) get_post_meta($post_id, '_tr_ai_wire_social_reel_kit_id', true);
        if ($kit > 0 && get_post_meta($kit, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment($kit, true);
        }
        $hold = (int) get_post_meta($post_id, '_tr_ai_wire_reel_hold_id', true);
        if ($hold > 0 && get_post_meta($hold, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment($hold, true);
        }
        $trim = (int) get_post_meta($post_id, '_tr_ai_wire_reel_trim_src', true);
        if ($trim > 0 && $trim !== $video_id && get_post_meta($trim, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment($trim, true);
        }
        foreach (array('_tr_ai_wire_social_reel_id', '_tr_ai_wire_social_reel_kit_id', '_tr_ai_wire_fal_compose_request', '_tr_ai_wire_fal_trim_request', '_tr_ai_wire_fal_hold', '_tr_ai_wire_reel_hold_id', '_tr_ai_wire_reel_trim_src', '_tr_ai_wire_hold_scale', '_tr_ai_wire_hold_scale_tries', '_tr_ai_wire_hold_keep', '_tr_ai_wire_reel_no_sting') as $meta) {
            delete_post_meta($post_id, $meta);
        }
        update_post_meta($post_id, '_tr_ai_wire_social_hold_reel', '1');
        return true;
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

    private function social_reel_pipelines() {
        return array(
            'best' => array(
                'label' => 'Best production path',
                'short' => 'viral 2D newsroom; unique illustrated scenes per story; the worker posts it',
                'settings' => array(
                    'social_reel_recipe' => 'story',
                    'social_reel_include_host' => 0,
                    'social_reel_include_slides' => 1,
                    'social_reel_include_broll' => 0,
                    'social_reel_include_sting' => 1,
                    'social_reel_video_model' => 'h3-max',
                    'social_reel_clip_seconds' => 5,
                    'social_reel_target_seconds' => 60,
                ),
            ),
            'budget' => array(
                'label' => 'Budget path',
                'short' => 'Veo Lite 4s; the worker posts on the $10 video budget',
                'settings' => array(
                    'social_reel_recipe' => 'story',
                    'social_reel_include_host' => 0,
                    'social_reel_include_slides' => 1,
                    'social_reel_include_broll' => 1,
                    'social_reel_include_sting' => 1,
                    'social_reel_video_model' => 'veo-lite',
                    'social_reel_clip_seconds' => 4,
                    'social_reel_target_seconds' => 18,
                ),
            ),
            'custom' => array(
                'label' => 'Custom',
                'short' => 'Pick the recipe, host, and Fal model yourself',
                'settings' => array(),
            ),
        );
    }

    private function social_reel_apply_pipeline($settings) {
        $settings = is_array($settings) ? $settings : array();
        $pipelines = $this->social_reel_pipelines();
        $pipeline = sanitize_key((string) ($settings['social_reel_pipeline'] ?? 'custom'));
        if (!isset($pipelines[$pipeline])) {
            $pipeline = 'custom';
        }
        $settings['social_reel_pipeline'] = $pipeline;
        $lock = isset($pipelines[$pipeline]['settings']) ? (array) $pipelines[$pipeline]['settings'] : array();
        if ($lock) {
            $settings = array_merge($settings, $lock);
        }
        return $settings;
    }

    private function social_reel_video_models() {
        return array(
            'h3-max' => array(
                'label' => 'MiniMax H3 Max',
                'short' => 'Best silent B-roll from an approved still',
                'recommended' => false,
                'quality' => true,
                'endpoint' => 'minimax/h3-max/image-to-video',
                'usd_per_second' => 0.035,
                'durations' => array(4, 5, 6, 8),
                'image_field' => 'image_url',
                'duration_suffix' => '',
                'duration_as_number' => true,
                'audio_field' => '',
                'resolution' => '768P',
                'prompt_expansion_mode' => 'balanced',
                'docs' => 'https://fal.ai/models/minimax/h3-max/image-to-video',
                'blurb' => 'Fal’s current default image-to-video for one starting still. Silent 768P, 5s ≈ $0.18. Budget/Custom B-roll only — Best never muxes this leftover 5s clip.',
            ),
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
        if ($this->social_reel_is_roundup($settings)) {
            $cast = $gender === 'female'
                ? 'Female tech news host on a 60-second roundup.'
                : ($gender === 'male' ? 'Male tech news host on a 60-second roundup.' : 'Tech news host on a 60-second roundup.');
            return $cast
                . ' Fast, punchy, one breath per sentence. Hook in the first line. Never a teleprompter or TV announcer.'
                . ' Hit the named change and the number. Leave a beat before Meanwhile, Next up, and Even crazier.';
        }
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

    private function social_reel_is_roundup($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        if ($this->social_reel_dev_cheap_on($settings)) {
            return false;
        }
        return sanitize_key((string) ($settings['social_reel_pipeline'] ?? 'custom')) === 'best';
    }

    private function social_reel_cut_name($settings = null) {
        return $this->social_reel_is_roundup($settings) ? self::SOCIAL_REEL_ROUNDUP_CUT : self::SOCIAL_REEL_CUT;
    }

    private function social_reel_max_cards($settings = null) {
        return $this->social_reel_is_roundup($settings) ? self::SOCIAL_REEL_MAX_ROUNDUP_CARDS : self::SOCIAL_REEL_MAX_CARDS;
    }

    private function social_reel_max_audio_seconds($settings = null) {
        if ($this->social_reel_is_roundup($settings)) {
            return 96.0;
        }
        $target = (float) $this->social_reel_target_seconds($settings);
        return max(24.0, min(72.0, $target + 12.0));
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

    private function social_reel_script_word_floor($settings = null) {
        return $this->social_reel_is_roundup($settings) ? 130 : 12;
    }

    private function social_reel_script_clip_chars($settings = null) {
        return $this->social_reel_is_roundup($settings) ? 4000 : 1200;
    }

    private function social_reel_script_word_count($script) {
        $words = preg_split('/\s+/', trim(wp_strip_all_tags((string) $script)), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }

    private function social_reel_script_word_budget($settings = null) {
        if ($this->social_reel_is_roundup($settings)) {
            return 240;
        }
        $target = $this->social_reel_target_seconds($settings);
        if ($target >= 55) {
            return 150;
        }
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
        return in_array($target, array(15, 18, 30, 45, 60), true) ? $target : 18;
    }

    private function social_reel_script_brief($settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        if ($this->social_reel_is_roundup($settings)) {
            return 'At least 135 spoken words. 150 is a floor, not a cap — use 180–240 words when 4–5 stories need the room. Do not pad, and do not cut a story to stay under 150. '
                . 'You are an elite viral short-form producer for YouTube Shorts, Instagram Reels, and TikTok. '
                . 'Convert the supplied tech stories into a high-retention voiceover for a viral 2D clean vector newsroom. '
                . 'Never open with Hello guys or Welcome back. Hook in the first 3 seconds with a high-stakes curiosity line. '
                . 'Then 3 to 5 stories, exactly two punchy sentences each (what happened, then why it matters or the shocking number). '
                . 'Use Meanwhile, Next up, or Even crazier between stories. '
                . 'End with a polarizing comment question, then a 3-word follow CTA such as Follow for daily tech drops. '
                . 'reel_captions is the VISUAL CUE column: 8–18 short scene lines (max 6 words) in voiceover order, each naming the packed desk story on screen. '
                . 'reel_scene_briefs is one unique illustration brief per caption, grounded in that story (set, prop, mascot action) — never a generic desk twice. reel_script is the VOICEOVER column. '
                . 'Ban: today we discuss, in this video, according to reports, experts say, let’s dive in, moving on, in conclusion.';
        }
        $target = $this->social_reel_target_seconds($settings);
        if ($target >= 40) {
            $length = '110–140 spoken words, about 40–45 seconds.';
        } elseif ($target >= 28) {
            $length = '80–110 spoken words, about 30 seconds.';
        } else {
            $length = '40–55 spoken words, about 15–18 seconds.';
        }
        $face = !empty($settings['social_reel_include_host'])
            ? ' Host may appear briefly; most of the watch time is on-screen lesson cards.'
            : ' No talking face — on-screen lesson cards carry the Reel, with a brief B-roll accent or two.';
        return $length
            . ' Write a mini lesson people can learn from, not a press readout.' . $face
            . ' Structure: (1) pattern-interrupt hook that names the change; (2) plain-language “what this is”; (3) one mechanism or why it matters; (4) one concrete example for their phone, game, job, or money; (5) the takeaway they can repeat.'
            . ' Short punchy sentences — one idea each. Ban: today we discuss, in this video, according to reports, experts say, let’s dive in, here’s what you need to know, moving on, in conclusion.';
    }

    /**
     * Research / teach layer: one Writer pass (optional OpenAI web_search) that
     * turns the article into a learnable Reel script + on-screen lesson beats.
     */
    private function social_reel_lesson_key($post, $settings, $copy, $script) {
        $copy = is_array($copy) ? $copy : array();
        return md5(implode('|', array(
            (string) $post->ID,
            $this->social_reel_trim_script($script, $settings),
            (string) ($copy['hook'] ?? ''),
            $this->social_reel_dev_cheap_on($settings) ? 'cheap' : 'prod',
            !empty($settings['enable_web_search']) ? 'web' : 'local',
            $this->social_reel_is_roundup($settings) ? 'roundup-v4' : 'lesson-v1',
            implode(',', $this->social_reel_roundup_ids($post, $settings)),
        )));
    }

    private function social_reel_roundup_ids($post, $settings = null) {
        $stories = $this->social_reel_roundup_stories($post, $settings);
        $ids = array();
        foreach ($stories as $story) {
            $id = (int) ($story['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Best-path roundup: this article plus 2–4 sibling published AI Wire stories.
     */
    private function social_reel_roundup_stories($post, $settings = null) {
        $settings = is_array($settings) ? $settings : $this->settings();
        $lead_id = ($post instanceof WP_Post) ? (int) $post->ID : (int) $post;
        $stories = array();
        if ($lead_id > 0) {
            $lead = $post instanceof WP_Post ? $post : get_post($lead_id);
            if ($lead instanceof WP_Post) {
                $snap = $this->social_article_snapshot($lead);
                $snap['id'] = $lead_id;
                $stories[] = $snap;
            }
        }
        if (!$this->social_reel_is_roundup($settings) || $lead_id < 1) {
            return $stories;
        }
        $query = new WP_Query(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 12,
            'post__not_in' => array($lead_id),
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_compare' => 'EXISTS',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));
        foreach ((array) $query->posts as $sibling) {
            if (!$sibling instanceof WP_Post) {
                continue;
            }
            if (!$this->social_article_clears_quality_gate((int) $sibling->ID, $settings)) {
                continue;
            }
            $snap = $this->social_article_snapshot($sibling);
            $snap['id'] = (int) $sibling->ID;
            $stories[] = $snap;
            if (count($stories) >= 5) {
                break;
            }
        }
        return $stories;
    }

    private function social_reel_enrich_lesson($post, $settings, $copy, $script) {
        $copy = is_array($copy) ? $copy : array();
        $script = $this->sanitize_reel_script($script, $copy, $post, $settings);
        $fingerprint = $this->social_reel_lesson_key($post, $settings, $copy, $script);
        $cached = json_decode((string) get_post_meta($post->ID, '_tr_ai_wire_reel_lesson', true), true);
        if (is_array($cached) && (string) ($cached['key'] ?? '') === $fingerprint && !empty($cached['reel_script'])) {
            $copy['reel_script'] = $this->sanitize_reel_script($cached['reel_script'], $copy, $post, $settings);
            if (!empty($cached['reel_captions']) && is_array($cached['reel_captions'])) {
                $copy['reel_captions'] = $this->sanitize_reel_captions($cached['reel_captions'], $copy['reel_script'], $settings);
            }
            if (!empty($cached['reel_scene_briefs']) && is_array($cached['reel_scene_briefs'])) {
                $copy['reel_scene_briefs'] = $this->sanitize_reel_scene_briefs($cached['reel_scene_briefs'], $copy['reel_captions'] ?? array());
            }
            return array('copy' => $copy, 'script' => (string) $copy['reel_script']);
        }

        $roundup = $this->social_reel_is_roundup($settings);
        $this->social_reel_progress_note($post->ID, $roundup ? 'Writing a 2D newsroom roundup with a unique scene per story…' : 'Researching a teach script for this Reel…');
        $stories = $this->social_reel_roundup_stories($post, $settings);
        $web = !empty($settings['enable_web_search']);
        if ($roundup) {
            $system = 'You are an elite viral short-form video producer for ' . self::site_brand_name() . ', specializing in fast-paced tech news roundups for YouTube Shorts, Instagram Reels, and TikTok. Length follows the stories — never cut a desk item to stay under 150 words. '
                . 'Viral 2D clean vector newsroom. Ground every claim in the supplied stories. Never invent prices, quotes, dates, or extra companies. Return JSON only.';
            $user = 'Build the two-column roundup: VISUAL CUE (reel_captions) and VOICEOVER (reel_script), plus reel_scene_briefs.'
                . "\n" . $this->social_reel_script_brief($settings)
                . "\nVisual style: a locked viral 2D newsroom (midnight navy, cyan light, one yellow-head stick mascot). Each reel_captions line must match the story being spoken at that beat. Each reel_scene_briefs line is a unique illustrated set for that story — different room, prop, and mascot action. No photoreal people, no letters in the artwork, no generic empty desk twice."
                . "\nCurrent draft script:\n" . $script
                . "\nStories JSON (use 3–5; if fewer, stay on those facts — do not invent a fifth company):\n" . wp_json_encode($stories)
                . "\nReturn JSON keys: reel_script, reel_captions (array), reel_scene_briefs (array).";
        } else {
            $snapshot = $this->social_article_snapshot($post);
            $system = 'You are a tech explainer desk for ' . self::site_brand_name() . '. '
                . 'Turn one published article into a scroll-stopping educational Reel people actually learn from. '
                . 'No talking-head host. Ground every claim in the article'
                . ($web ? ' and one quick web search for plain-language context (do not invent breaking news).' : '.')
                . ' Never invent prices, quotes, or dates. Return JSON only.';
            $user = 'Build a no-face teaching Reel.'
                . "\n" . $this->social_reel_script_brief($settings)
                . "\nAlso return reel_captions: 4–5 short on-screen lesson lines (max 6 words) that match the spoken beats — hook, what it is, why it matters, example, takeaway."
                . "\nCurrent draft script (improve it into a clear mini lesson):\n" . $script
                . "\nHook: " . (string) ($copy['hook'] ?? '')
                . "\nArticle JSON:\n" . wp_json_encode($snapshot)
                . "\nReturn JSON keys: reel_script, reel_captions (array).";
        }
        $lesson_settings = $settings;
        if ($roundup) {
            $lesson_settings['max_output_tokens'] = max(2800, (int) ($settings['max_output_tokens'] ?? 2800));
        }
        $parsed = $this->ai_json($settings['writer_model'] ?? 'gpt-6-luna', $system, $user, $lesson_settings, $web);
        if (is_wp_error($parsed)) {
            // Keep the existing script if research fails — still assemble.
            return array('copy' => $copy, 'script' => $script !== '' ? $script : $this->fallback_reel_script($copy, $post, $settings));
        }
        $next_script = $this->sanitize_reel_script($parsed['reel_script'] ?? $script, $copy, $post, $settings);
        if ($this->social_reel_script_word_count($next_script) < $this->social_reel_script_word_floor($settings)) {
            $next_script = $this->sanitize_reel_script($this->fallback_reel_script($copy, $post, $settings), $copy, $post, $settings);
        }
        $next_caps = $this->sanitize_reel_captions($parsed['reel_captions'] ?? ($copy['reel_captions'] ?? array()), $next_script, $settings);
        $next_briefs = $this->sanitize_reel_scene_briefs($parsed['reel_scene_briefs'] ?? ($copy['reel_scene_briefs'] ?? array()), $next_caps);
        $copy['reel_script'] = $next_script;
        $copy['reel_captions'] = $next_caps;
        $copy['reel_scene_briefs'] = $next_briefs;
        $ids = array();
        foreach ($stories as $story) {
            $ids[] = (int) ($story['id'] ?? 0);
        }
        update_post_meta($post->ID, '_tr_ai_wire_reel_roundup_ids', array_values(array_filter($ids)));
        update_post_meta($post->ID, '_tr_ai_wire_reel_lesson', wp_json_encode(array(
            'key' => $fingerprint,
            'reel_script' => $next_script,
            'reel_captions' => $next_caps,
            'reel_scene_briefs' => $next_briefs,
            'at' => time(),
        )));
        return array('copy' => $copy, 'script' => $next_script);
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
        if ($this->social_reel_is_roundup($settings)) {
            return self::SOCIAL_REEL_ROUNDUP_LAYOUT;
        }
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
        $scenes = 0.0;
        if ($this->social_reel_is_roundup($settings)) {
            $scenes = round(12 * self::SOCIAL_REEL_SCENE_USD, 4);
        }
        return round($motion + $scenes + $this->social_reel_host_estimate_usd($settings), 4);
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
                $parts[] = $this->social_reel_is_roundup($settings) ? 'Viral 2D newsroom roundup' : 'No-face teach Reel';
            }
            $parts[] = $this->social_reel_is_roundup($settings) ? 'unique illustrated scenes that match each story' : 'lesson cards carry the Reel';
            if (!$this->social_reel_is_roundup($settings) && (!isset($settings['social_reel_include_broll']) || !empty($settings['social_reel_include_broll']))) {
                $parts[] = 'at most 2 short B-roll accents';
            }
            if (!$this->social_reel_is_roundup($settings) && (!isset($settings['social_reel_include_sting']) || !empty($settings['social_reel_include_sting']))) {
                $parts[] = 'sting';
            }
        } else {
            $parts[] = 'Lesson-card Reel (no research pass)';
            $parts[] = 'at most 2 short B-roll accents';
        }
        if ($this->social_reel_dev_cheap_on($settings)) {
            array_unshift($parts, 'DEV CHEAP TEST');
        }
        $pipeline = sanitize_key((string) ($settings['social_reel_pipeline'] ?? 'custom'));
        $pipelines = $this->social_reel_pipelines();
        if ($pipeline !== 'custom' && isset($pipelines[$pipeline]['label'])) {
            array_unshift($parts, (string) $pipelines[$pipeline]['label']);
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
        $seconds = (int) $seconds;
        $payload = array(
            'prompt' => $this->clip_social_text($prompt, 1200),
            $field => $image_url,
        );
        if (!empty($spec['duration_as_number'])) {
            $payload['duration'] = $seconds;
        } else {
            $payload['duration'] = (string) $seconds . $suffix;
        }
        if (array_key_exists('audio_field', $spec)) {
            if ((string) $spec['audio_field'] !== '') {
                $payload[(string) $spec['audio_field']] = false;
            }
        } else {
            $payload['generate_audio'] = false;
        }
        if (!empty($spec['aspect_ratio'])) {
            $payload['aspect_ratio'] = (string) $spec['aspect_ratio'];
        }
        if (!empty($spec['resolution'])) {
            $payload['resolution'] = (string) $spec['resolution'];
        }
        if (!empty($spec['auto_fix'])) {
            $payload['auto_fix'] = true;
        }
        if (!empty($spec['prompt_expansion_mode'])) {
            $payload['prompt_expansion_mode'] = (string) $spec['prompt_expansion_mode'];
        }
        return $payload;
    }

    private function social_reel_motion_prompt($copy, $post = null, $settings = null) {
        $copy = is_array($copy) ? $copy : array();
        $settings = is_array($settings) ? $settings : $this->settings();
        $brief = $this->social_image_scene_brief((string) ($copy['visual_brief'] ?? ''));
        $moves = array(
            'slow push-in, locked horizon, natural parallax',
            'gentle handheld drift, no whip pans',
            'slight orbit around the hero object, horizon level',
        );
        $id = ($post instanceof WP_Post) ? (int) $post->ID : (int) $post;
        $move = $moves[(int) (sprintf('%u', crc32('reel-cam:' . $id)) % count($moves))];
        if ($this->social_reel_is_roundup($settings)) {
            $prompt = '2D clean vector newsroom, vertical 9:16. Animate this exact still; do not redesign the yellow-head stick mascot. Camera: '
                . $move
                . '. Flat colour, thin line art, same identity. ';
            $prompt .= $brief !== '' ? ('Action: ' . $brief . '. ') : 'Subtle newsroom motion only — ticker, papers, screen glow. ';
        } else {
            $prompt = 'Photoreal vertical 9:16 news B-roll. Animate this exact still; do not redesign the scene. Camera: '
                . $move
                . '. Lighting and identity stay the same. ';
            if ($brief !== '') {
                $prompt .= 'Action: ' . $brief . '. ';
            } else {
                $prompt .= 'Subtle real-world motion only — fabric, glass, screens, weather. ';
            }
            if ($post instanceof WP_Post && $this->post_is_comic($post)) {
                $prompt .= 'Keep any sly visual beat photoreal. ';
            }
        }
        $prompt .= 'no text, no logos, no watermarks, no subtitles, no captions, no extra people. Ending state matches the opening frame, closer.';
        return $this->clip_social_text($prompt, 1200);
    }

    private function fallback_reel_script($copy, $post, $settings = null) {
        $copy = is_array($copy) ? $copy : array();
        if ($this->social_reel_is_roundup($settings) && $post instanceof WP_Post) {
            $joins = array('', 'Meanwhile, ', 'Next up, ', 'Even crazier, ', 'And this one: ');
            $parts = array('Tech just moved faster in the last 24 hours than all of last month — here are the updates you need to know.');
            foreach ($this->social_reel_roundup_stories($post, $settings) as $i => $story) {
                $title = trim(wp_strip_all_tags((string) ($story['title'] ?? '')));
                $take = trim(wp_strip_all_tags((string) (($story['key_takeaways'][0] ?? $story['lede'] ?? ''))));
                $excerpt = trim(wp_strip_all_tags((string) ($story['excerpt'] ?? '')));
                if ($title === '') {
                    continue;
                }
                $parts[] = ($joins[$i] ?? 'Next up, ') . rtrim($title, '.') . '.';
                if ($take !== '' && strcasecmp($take, $title) !== 0) {
                    $parts[] = rtrim($this->clip_social_text($take, 180), '.') . '.';
                }
                if ($excerpt !== '' && strcasecmp($excerpt, $take) !== 0 && strcasecmp($excerpt, $title) !== 0) {
                    $parts[] = rtrim($this->clip_social_text($excerpt, 220), '.') . '.';
                }
            }
            $parts[] = 'Which update changes how you work? Follow for daily tech drops.';
            $roundup = $this->social_reel_trim_script(implode(' ', $parts), $settings);
            if ($this->social_reel_script_word_count($roundup) >= 12) {
                return $roundup;
            }
        }
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

    private function writer_reel_script_present($raw, $settings = null) {
        $script = $this->clip_social_block(is_array($raw) ? ($raw['reel_script'] ?? '') : '', $this->social_reel_script_clip_chars($settings));
        return $this->social_reel_script_word_count($script) >= 12;
    }

    private function social_reel_caption_limit($settings = null) {
        return $this->social_reel_is_roundup($settings) ? self::SOCIAL_REEL_MAX_ROUNDUP_CARDS : 5;
    }

    private function fallback_reel_captions($script, $settings = null) {
        $limit = $this->social_reel_caption_limit($settings);
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
                if (count($captions) >= $limit) {
                    return $captions;
                }
            }
            if ($chunk) {
                $captions[] = implode(' ', $chunk);
            }
            if (count($captions) >= $limit) {
                break;
            }
        }
        return array_slice(array_values(array_filter($captions)), 0, $limit);
    }

    private function sanitize_reel_scene_briefs($raw, $captions = array()) {
        $limit = max(1, count((array) $captions));
        if ($limit < 1) {
            $limit = self::SOCIAL_REEL_MAX_ROUNDUP_CARDS;
        }
        $briefs = array();
        foreach (array_values((array) $raw) as $line) {
            $line = $this->clip_social_text($line, 160);
            if ($line === '' || $this->social_image_brief_is_unsafe($line)) {
                continue;
            }
            $briefs[] = $line;
            if (count($briefs) >= $limit) {
                break;
            }
        }
        return $briefs;
    }

    /** Locked viral 2D newsroom look shared by every Best roundup illustration. */
    private function social_reel_scene_look() {
        return '2D clean vector illustration, viral tech-news Instagram Reel, midnight navy newsroom, cyan rim light, one yellow-head stick mascot, flat colour, bold graphic shapes, high contrast, no photoreal people, no photograph, no text, no letters, no numbers, no logos, no watermarks';
    }

    private function social_reel_scene_prompt($caption, $brief, $story, $index) {
        $caption = $this->clip_social_text($caption, 72);
        $brief = $this->clip_social_text($brief, 160);
        $title = $this->clip_social_text((string) ($story['title'] ?? ''), 90);
        $take = '';
        if (!empty($story['key_takeaways'][0])) {
            $take = $this->clip_social_text((string) $story['key_takeaways'][0], 90);
        }
        $scene = $brief !== '' ? $brief : trim($caption . ($title !== '' ? ('. Story: ' . $title) : ''));
        if ($take !== '' && stripos($scene, $take) === false) {
            $scene .= '. Stake: ' . $take;
        }
        $beat = ((int) $index % 6 === 0) ? 'wide newsroom establishing shot' : (((int) $index % 2 === 0) ? 'medium desk shot' : 'close graphic insert');
        return $this->social_reel_scene_look()
            . '. Unique scene ' . (string) ((int) $index + 1) . ', ' . $beat . ': ' . $scene
            . '. Do not repeat a previous set. Vertical 9:16, keep the top 12% and bottom 22% simple so type can sit there later.';
    }

    private function social_reel_scene_story($copy, $post, $index) {
        $stories = array();
        if (!empty($copy['roundup_stories']) && is_array($copy['roundup_stories'])) {
            $stories = array_values($copy['roundup_stories']);
        } elseif ($post instanceof WP_Post) {
            $stories = $this->social_reel_roundup_stories($post);
        }
        if (!$stories) {
            return array(
                'title' => (string) ($copy['hook'] ?? ($post instanceof WP_Post ? $post->post_title : '')),
                'key_takeaways' => array(),
            );
        }
        $index = max(0, (int) $index);
        if ($index <= 0) {
            return $stories[0];
        }
        return $stories[($index - 1) % count($stories)];
    }

    /**
     * One unique Fal FLUX illustration per roundup beat. Resume reuses the
     * request id so the same scene is never paid twice.
     */
    private function social_reel_ensure_scene_art($post, $copy, $caption, $index, $submit_only = false, $settings = null) {
        $index = (int) $index;
        $settings = $this->social_reel_effective_settings($settings);
        $caption = $this->clip_social_text($caption, 42);
        $briefs = array_values((array) ($copy['reel_scene_briefs'] ?? array()));
        $brief = (string) ($briefs[$index] ?? '');
        $prompt = $this->social_reel_scene_prompt($caption, $brief, $this->social_reel_scene_story($copy, $post, $index), $index);
        $key = md5(implode('|', array(
            self::SOCIAL_REEL_SCENE_ENDPOINT,
            $prompt,
            (string) $index,
            self::SOCIAL_REEL_ROUNDUP_CUT,
        )));
        $meta_img = '_tr_ai_wire_reel_scene_' . (string) $index;
        $meta_fal = '_tr_ai_wire_fal_scene_' . (string) $index;
        $existing = (int) get_post_meta($post->ID, $meta_img, true);
        if ($existing > 0 && (string) get_post_meta($existing, '_tr_ai_wire_scene_key', true) === $key) {
            $path = get_attached_file($existing);
            if ($path && file_exists($path) && filesize($path) > 512) {
                $bytes = file_get_contents($path);
                if (is_string($bytes) && $bytes !== '') {
                    return array('id' => $existing, 'path' => $path, 'bytes' => $bytes, 'key' => $key);
                }
            }
        }
        $label = 'newsroom scene ' . (string) ($index + 1);
        return $this->social_fal_step(array(
            'owner_id' => $post->ID,
            'post_id' => $post->ID,
            'meta_key' => $meta_fal,
            'endpoint' => self::SOCIAL_REEL_SCENE_ENDPOINT,
            'label' => $label,
            'estimate' => self::SOCIAL_REEL_SCENE_USD,
            'enforce_budget' => true,
            'track_usage' => true,
            'settings' => $settings,
            'input_key' => $key,
            'submit_only' => $submit_only,
            'payload' => function () use ($prompt) {
                return array(
                    'prompt' => $prompt,
                    'image_size' => 'portrait_16_9',
                    'num_images' => 1,
                    'enable_safety_checker' => true,
                    'output_format' => 'jpeg',
                );
            },
            'store' => function ($bytes) use ($post, $meta_img, $meta_fal, $key, $index, $label) {
                if (!is_string($bytes) || strlen($bytes) < 512) {
                    return new WP_Error('tr_ai_wire_reel_cards', 'Fal did not return a usable ' . $label . '.');
                }
                $old = (int) get_post_meta($post->ID, $meta_img, true);
                $name = 'technorapper-social-scene-' . (int) $post->ID . '-' . (string) $index . '.jpg';
                $upload = wp_upload_bits($name, null, $bytes);
                if (!empty($upload['error'])) {
                    return new WP_Error('tr_ai_wire_social_video_upload', sanitize_text_field($upload['error']));
                }
                $attachment_id = wp_insert_attachment(array(
                    'post_mime_type' => 'image/jpeg',
                    'post_title' => 'Reel ' . $label,
                    'post_status' => 'inherit',
                    'post_parent' => 0,
                ), $upload['file'], 0, true);
                if (is_wp_error($attachment_id)) {
                    return $attachment_id;
                }
                update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
                update_post_meta($attachment_id, '_tr_ai_wire_scene_key', $key);
                update_post_meta($post->ID, $meta_img, (int) $attachment_id);
                $this->social_fal_pending_clear($post->ID, $meta_fal);
                if ($old > 0 && $old !== (int) $attachment_id && get_post_meta($old, '_tr_ai_wire_social_asset', true) === '1') {
                    wp_delete_attachment($old, true);
                }
                return array(
                    'id' => (int) $attachment_id,
                    'path' => (string) $upload['file'],
                    'bytes' => $bytes,
                    'key' => $key,
                );
            },
        ));
    }

    private function sanitize_reel_script($raw, $copy, $post, $settings = null) {
        $script = $this->clip_social_block($raw, $this->social_reel_script_clip_chars($settings));
        if ($this->social_reel_script_word_count($script) < $this->social_reel_script_word_floor($settings)) {
            $script = $this->fallback_reel_script($copy, $post, $settings);
        }
        return $this->social_reel_trim_script($script, $settings);
    }

    private function sanitize_reel_captions($raw, $script, $settings = null) {
        $limit = $this->social_reel_caption_limit($settings);
        $min = $this->social_reel_is_roundup($settings) ? 8 : 3;
        $captions = array();
        foreach ((array) $raw as $line) {
            $line = $this->clip_social_text($line, 42);
            if ($line !== '' && !in_array($line, $captions, true)) {
                $captions[] = $line;
            }
            if (count($captions) >= $limit) {
                break;
            }
        }
        if (count($captions) < $min) {
            $captions = $this->fallback_reel_captions($script, $settings);
        }
        return array_slice($captions, 0, $limit);
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
        $max = $this->social_reel_max_audio_seconds($settings);
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

    private function render_social_reel_frame($kind, $copy, $photo_bytes, $caption, $meta = array()) {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return new WP_Error('tr_ai_wire_social_gd', 'PHP GD is required to render a Reel frame.');
        }
        $width = 1080;
        $height = 1920;
        $kind = sanitize_key((string) $kind);
        $copy = is_array($copy) ? $copy : array();
        $caption = trim(wp_strip_all_tags((string) $caption));
        $font = $this->social_font_path();
        if ($kind === 'lesson') {
            if ($font === '') {
                return new WP_Error('tr_ai_wire_reel_cards', 'Lesson cards need PHP GD with FreeType so the text is readable. Ask your host to enable FreeType for GD, then click Resume.');
            }
            $meta = is_array($meta) ? $meta : array();
            if (!empty($meta['roundup']) || $this->social_reel_is_roundup()) {
                return $this->render_social_reel_stick_news_card($copy, $caption, $meta, $font, $photo_bytes);
            }
            return $this->render_social_reel_lesson_card($copy, $photo_bytes, $caption, $meta, $font);
        }
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

    /**
     * Lesson card: big centred caption on a blurred, darkened backdrop so the
     * text — not the B-roll still — is what people see. Instagram's Reel chrome
     * covers roughly the top 250px and bottom 450px, so all text stays between.
     */
    private function render_social_reel_lesson_card($copy, $photo_bytes, $caption, $meta, $font) {
        $width = 1080;
        $height = 1920;
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 7, 11, 20));
        $photo = (is_string($photo_bytes) && $photo_bytes !== '') ? @imagecreatefromstring($photo_bytes) : false;
        if ($photo) {
            // Downscale then upscale: a cheap heavy blur that keeps colour but drops detail.
            $small = imagecreatetruecolor(36, 64);
            imagecopyresampled($small, $photo, 0, 0, 0, 0, 36, 64, imagesx($photo), imagesy($photo));
            $mid = imagecreatetruecolor(135, 240);
            imagecopyresampled($mid, $small, 0, 0, 0, 0, 135, 240, 36, 64);
            for ($pass = 0; $pass < 4; $pass++) {
                imagefilter($mid, IMG_FILTER_GAUSSIAN_BLUR);
            }
            imagecopyresampled($canvas, $mid, 0, 0, 0, 0, $width, $height, 135, 240);
            imagedestroy($small);
            imagedestroy($mid);
            imagedestroy($photo);
            imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocatealpha($canvas, 4, 8, 18, 28));
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $muted = imagecolorallocate($canvas, 190, 202, 214);
        $dim = imagecolorallocate($canvas, 70, 84, 102);
        $cyan = imagecolorallocate($canvas, 43, 213, 255);
        $stroke = imagecolorallocate($canvas, 0, 0, 0);
        $total = max(1, (int) ($meta['total'] ?? 1));
        $step = max(1, min($total, (int) ($meta['step'] ?? 1)));
        $label = trim(wp_strip_all_tags((string) ($meta['label'] ?? '')));
        $label = function_exists('mb_strtoupper') ? mb_strtoupper($label, 'UTF-8') : strtoupper($label);

        $this->draw_social_centered_text($canvas, self::site_brand_mark(), 30, 330, $muted, $font, $width, $stroke, 2);
        $this->draw_social_centered_text($canvas, $step . ' / ' . $total, 32, 500, $muted, $font, $width, $stroke, 2);
        if ($label !== '') {
            $this->draw_social_centered_text($canvas, $label, 44, 570, $cyan, $font, $width, $stroke, 2);
        }
        imagefilledrectangle($canvas, 460, 600, 620, 608, $cyan);

        $text = $caption !== '' ? $caption : trim(wp_strip_all_tags((string) ($copy['hook'] ?? '')));
        $text = function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
        $size = 60;
        $lines = array();
        for ($try = 104; $try >= 60; $try -= 4) {
            $lines = $this->wrap_social_lines($text, 920, $try, $font, 16, 12);
            $size = $try;
            if (count($lines) <= 5 && count($lines) * (int) round($try * 1.14) <= 600) {
                break;
            }
        }
        $lines = array_slice($lines, 0, 5);
        $line_height = (int) round($size * 1.14);
        $block = count($lines) * $line_height;
        $y = max(720, (int) round(980 - ($block / 2) + $size));
        foreach ($lines as $line) {
            $this->draw_social_centered_text($canvas, $line, $size, $y, $white, $font, $width, $stroke, 4);
            $y += $line_height;
        }

        $gap = 46;
        $x = (int) round(($width - (($total - 1) * $gap)) / 2);
        for ($i = 1; $i <= $total; $i++) {
            $dot = $i === $step ? 22 : 14;
            imagefilledellipse($canvas, $x, 1380, $dot, $dot, $i === $step ? $cyan : $dim);
            $x += $gap;
        }
        ob_start();
        imagejpeg($canvas, null, 90);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render a lesson card.');
    }

    /**
     * Viral 2D newsroom card. Unique illustrated art (when supplied) fills the
     * frame; type sits in the Instagram safe zone. GD stick-figure is only the
     * local fallback when no illustration was generated yet.
     */
    private function render_social_reel_stick_news_card($copy, $caption, $meta, $font, $photo_bytes = '') {
        $width = 1080;
        $height = 1920;
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, true);
        $navy = imagecolorallocate($canvas, 12, 22, 44);
        $panel = imagecolorallocate($canvas, 20, 36, 68);
        $white = imagecolorallocate($canvas, 248, 250, 252);
        $muted = imagecolorallocate($canvas, 186, 198, 214);
        $cyan = imagecolorallocate($canvas, 43, 213, 255);
        $yellow = imagecolorallocate($canvas, 255, 209, 72);
        $line = imagecolorallocate($canvas, 250, 250, 250);
        $stroke = imagecolorallocate($canvas, 4, 10, 22);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $navy);
        $illustrated = false;
        if (is_string($photo_bytes) && $photo_bytes !== '') {
            $photo = @imagecreatefromstring($photo_bytes);
            if ($photo) {
                $pw = imagesx($photo);
                $ph = imagesy($photo);
                $ratio = $width / $height;
                $src_ratio = $pw / max(1, $ph);
                if ($src_ratio > $ratio) {
                    $cw = (int) round($ph * $ratio);
                    $ch = $ph;
                    $sx = (int) (($pw - $cw) / 2);
                    $sy = 0;
                } else {
                    $cw = $pw;
                    $ch = (int) round($pw / $ratio);
                    $sx = 0;
                    $sy = (int) (($ph - $ch) / 2);
                }
                imagecopyresampled($canvas, $photo, 0, 0, $sx, $sy, $width, $height, $cw, $ch);
                imagedestroy($photo);
                $illustrated = true;
            }
        }
        imagefilledrectangle($canvas, 0, 0, $width, 250, $navy);
        imagefilledrectangle($canvas, 0, 1480, $width, $height, $navy);
        if (!$illustrated) {
            imagefilledrectangle($canvas, 0, 250, $width, 318, $cyan);
            $this->draw_social_centered_text($canvas, '2D NEWSROOM', 22, 298, $navy, $font, $width, $stroke, 1);
            imagefilledrectangle($canvas, 70, 360, 1010, 860, $panel);
            $total = max(1, (int) ($meta['total'] ?? 1));
            $step = max(1, min($total, (int) ($meta['step'] ?? 1)));
            $this->draw_social_stick_figure($canvas, 540, 640, $step, $line, $yellow);
        } else {
            imagefilledrectangle($canvas, 0, 250, $width, 318, $cyan);
            $this->draw_social_centered_text($canvas, '2D NEWSROOM', 22, 298, $navy, $font, $width, $stroke, 1);
        }
        $total = max(1, (int) ($meta['total'] ?? 1));
        $step = max(1, min($total, (int) ($meta['step'] ?? 1)));
        $this->draw_social_centered_text($canvas, self::site_brand_mark(), 28, 400, $muted, $font, $width, $stroke, 2);
        $label = trim(wp_strip_all_tags((string) ($meta['label'] ?? '')));
        $label = function_exists('mb_strtoupper') ? mb_strtoupper($label, 'UTF-8') : strtoupper($label);
        if ($label !== '') {
            $this->draw_social_centered_text($canvas, $label, 36, 920, $cyan, $font, $width, $stroke, 2);
        }
        $this->draw_social_centered_text($canvas, $step . ' / ' . $total, 28, 980, $muted, $font, $width, $stroke, 2);
        $text = $caption !== '' ? $caption : trim(wp_strip_all_tags((string) ($copy['hook'] ?? '')));
        $text = function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
        $size = 52;
        $lines = $this->wrap_social_lines($text, 920, $size, $font, 16, 6);
        $y = 1060;
        foreach ($lines as $row) {
            $this->draw_social_centered_text($canvas, $row, $size, $y, $white, $font, $width, $stroke, 3);
            $y += (int) round($size * 1.16);
        }
        ob_start();
        imagejpeg($canvas, null, 90);
        $result = ob_get_clean();
        imagedestroy($canvas);
        return $result ? $result : new WP_Error('tr_ai_wire_social_image_render', 'Could not render a stick-figure news card.');
    }

    private function draw_social_stick_figure($canvas, $cx, $cy, $pose, $ink, $head) {
        $pose = ((int) $pose) % 6;
        imagefilledellipse($canvas, $cx, $cy - 90, 88, 88, $head);
        imagesetthickness($canvas, 8);
        imageline($canvas, $cx, $cy - 46, $cx, $cy + 70, $ink);
        if ($pose === 0) {
            imageline($canvas, $cx, $cy - 10, $cx - 70, $cy + 20, $ink);
            imageline($canvas, $cx, $cy - 10, $cx + 90, $cy - 50, $ink);
        } elseif ($pose === 1) {
            imageline($canvas, $cx, $cy - 10, $cx - 55, $cy + 40, $ink);
            imageline($canvas, $cx, $cy - 10, $cx + 55, $cy + 40, $ink);
        } elseif ($pose === 2) {
            imageline($canvas, $cx, $cy - 10, $cx - 80, $cy - 70, $ink);
            imageline($canvas, $cx, $cy - 10, $cx + 80, $cy - 70, $ink);
        } elseif ($pose === 3) {
            imageline($canvas, $cx, $cy - 10, $cx - 40, $cy + 30, $ink);
            imageline($canvas, $cx, $cy - 10, $cx + 70, $cy - 10, $ink);
            imagefilledrectangle($canvas, $cx + 68, $cy - 28, $cx + 100, $cy + 8, $ink);
        } elseif ($pose === 4) {
            imageline($canvas, $cx, $cy - 10, $cx - 80, $cy, $ink);
            imageline($canvas, $cx, $cy - 10, $cx + 50, $cy + 30, $ink);
        } else {
            imageline($canvas, $cx, $cy - 10, $cx - 70, $cy - 20, $ink);
            imageline($canvas, $cx, $cy - 10, $cx + 70, $cy - 20, $ink);
        }
        imageline($canvas, $cx, $cy + 70, $cx - 50, $cy + 150, $ink);
        imageline($canvas, $cx, $cy + 70, $cx + 50, $cy + 150, $ink);
        imagesetthickness($canvas, 1);
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
        $roundup = $this->social_reel_is_roundup($settings);
        $host = !$roundup && !empty($settings['social_reel_include_host']);
        $motion = !$roundup && (!empty($settings['social_reel_include_broll']) || !empty($settings['social_reel_include_slides']));
        if (!$roundup && !$host && !$motion) {
            $motion = true;
        }
        $sting = !$roundup && (array_key_exists('social_reel_include_sting', $settings) ? !empty($settings['social_reel_include_sting']) : true);
        // Fal compose cannot seek into the talking-host clip, so only the local
        // ffmpeg cut keeps the host in sync after a cold-open card.
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
                $seconds = $this->social_reel_clamp_audio_seconds(
                    $this->social_audio_duration($path, $script, $settings),
                    $settings
                );
                $expected = $this->social_reel_script_word_count($script) / 2.5;
                $max = $this->social_reel_max_audio_seconds($settings);
                // Never reuse an overlong file, or a short leftover from an old 18s teach cut.
                if ($seconds > 0 && $seconds <= ($max + 5.0) && ($expected < 20 || $seconds >= ($expected * 0.55))) {
                    update_post_meta($existing, '_tr_ai_wire_voice_seconds', (string) $seconds);
                    return array('id' => $existing, 'path' => $path, 'key' => $key, 'seconds' => $seconds);
                }
                if (get_post_meta($existing, '_tr_ai_wire_social_asset', true) === '1') {
                    wp_delete_attachment($existing, true);
                }
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
        if ($this->social_reel_is_roundup($settings)) {
            $missing_scenes = 0;
            for ($i = 0; $i < 12; $i++) {
                if ((int) get_post_meta($post->ID, '_tr_ai_wire_reel_scene_' . (string) $i, true) > 0) {
                    continue;
                }
                if ($this->social_fal_pending_get($post->ID, '_tr_ai_wire_fal_scene_' . (string) $i)) {
                    continue;
                }
                $missing_scenes++;
            }
            $need += round($missing_scenes * self::SOCIAL_REEL_SCENE_USD, 4);
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
        $old = (int) get_post_meta($post->ID, $meta_key, true);
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
        if ($old > 0 && $old !== (int) $attachment_id && get_post_meta($old, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment($old, true);
        }
        return array('id' => (int) $attachment_id, 'path' => (string) $upload['file'], 'key' => (string) $key);
    }

    private function social_reel_endcard($post, $copy, $photo_bytes, $caption) {
        $caption = trim((string) $caption);
        $key = md5($caption . '|' . ($photo_bytes !== '' ? 'photo' : 'plain') . '|' . self::site_brand_name());
        $existing = (int) get_post_meta($post->ID, '_tr_ai_wire_social_endcard_id', true);
        if ($existing > 0 && (string) get_post_meta($existing, '_tr_ai_wire_endcard_key', true) === $key) {
            $path = get_attached_file($existing);
            if ($path && file_exists($path)) {
                return array('id' => $existing, 'path' => $path, 'key' => $key);
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

    /** Fal images-to-video holds an image for `frames / fps` seconds (max 1000 frames). */
    private function social_reel_hold_frames($ms, $fps = 0) {
        $fps = $fps > 0 ? (int) $fps : (int) self::SOCIAL_REEL_HOLD_FPS;
        $fps = max(1, min(120, $fps));
        return max(1, min(1000, (int) round(max(1, (int) $ms) * $fps / 1000.0)));
    }

    private function social_reel_detail_frame($post, $copy, $photo_bytes, $caption, $index, $meta = array()) {
        $caption = trim(wp_strip_all_tags((string) $caption));
        if ($caption === '') {
            $caption = trim(wp_strip_all_tags((string) ($copy['hook'] ?? $post->post_title)));
        }
        $meta = is_array($meta) ? $meta : array();
        $photo_bytes = is_string($photo_bytes) ? $photo_bytes : '';
        $key = md5(implode('|', array(
            !empty($meta['roundup']) ? 'scene-v1' : 'lesson-v2',
            $caption,
            (string) ((int) $index),
            (string) ((int) ($meta['step'] ?? 0)),
            (string) ((int) ($meta['total'] ?? 0)),
            (string) ($meta['label'] ?? ''),
            md5($photo_bytes),
            self::site_brand_name(),
        )));
        $meta_key = '_tr_ai_wire_reel_detail_' . (string) ((int) $index);
        $existing = (int) get_post_meta($post->ID, $meta_key, true);
        if ($existing > 0 && (string) get_post_meta($existing, '_tr_ai_wire_endcard_key', true) === $key) {
            $path = get_attached_file($existing);
            if ($path && file_exists($path)) {
                return array('id' => $existing, 'path' => $path, 'key' => $key);
            }
        }
        $jpeg = $this->render_social_reel_frame('lesson', $copy, $photo_bytes, $caption, $meta);
        if (is_wp_error($jpeg)) {
            return $jpeg;
        }
        return $this->social_reel_store_asset_image(
            $post,
            $jpeg,
            'technorapper-social-card-' . (int) $post->ID . '-' . (string) ((int) $index) . '.jpg',
            'Reel lesson card ' . (string) ((int) $index + 1),
            $meta_key,
            $key
        );
    }

    /**
     * Fal video tracks must only reference video files. Turn a JPEG card into an
     * MP4 that actually lasts its slot: Fal images-to-video holds the still for
     * `frames / fps` seconds. Compose image-track timestamps were 1-frame flashes.
     */
    private function social_reel_ensure_card_video($post, $card, $seconds, $index, $submit_only = false) {
        $seconds = round(max(1.0, min(30.0, (float) $seconds)), 3);
        $index = (int) $index;
        $path = (string) ($card['path'] ?? '');
        if ($path === '' || !file_exists($path)) {
            return new WP_Error('tr_ai_wire_reel_cards', 'A Reel lesson card image is missing. Click Resume to draw it again.');
        }
        $meta_vid = '_tr_ai_wire_reel_card_vid_' . (string) $index;
        $meta_fal = '_tr_ai_wire_fal_card_' . (string) $index;
        $key = md5((string) ($card['key'] ?? $path) . '|' . number_format($seconds, 3, '.', '') . '|card-v3');
        $existing = (int) get_post_meta($post->ID, $meta_vid, true);
        if ($existing > 0 && (string) get_post_meta($existing, '_tr_ai_wire_card_key', true) === $key) {
            $have = get_attached_file($existing);
            if ($have && file_exists($have) && filesize($have) > 1024) {
                $got = (float) $this->social_reel_output_seconds($have);
                if ($got >= ($seconds * 0.9) && $got <= ($seconds + 3.0)) {
                    return array('id' => $existing, 'path' => $have, 'seconds' => $seconds);
                }
            }
        }
        $image_url = $this->social_reel_file_data_url($path, 'image/jpeg', 'tr_ai_wire_reel_cards', 'A Reel lesson card image is missing.');
        if (is_wp_error($image_url)) {
            return $image_url;
        }
        $label = $index === 90 ? 'end card' : ('lesson card ' . (string) ($index + 1));
        return $this->social_fal_step(array(
            'owner_id' => $post->ID,
            'post_id' => $post->ID,
            'meta_key' => $meta_fal,
            'endpoint' => self::SOCIAL_REEL_HOLD_ENDPOINT,
            'label' => $label,
            'estimate' => 0.0002,
            'enforce_budget' => false,
            'track_usage' => false,
            'settings' => $this->settings(),
            'input_key' => $key,
            'submit_only' => $submit_only,
            'payload' => function () use ($image_url, $seconds) {
                $fps = (int) self::SOCIAL_REEL_HOLD_FPS;
                return array(
                    'fps' => $fps,
                    'images' => array(
                        array(
                            'url' => $image_url,
                            'frames' => $this->social_reel_hold_frames($this->social_reel_ms($seconds), $fps),
                        ),
                    ),
                );
            },
            'store' => function ($bytes) use ($post, $meta_vid, $meta_fal, $key, $index, $seconds, $label) {
                if (!is_string($bytes) || strlen($bytes) < 1024) {
                    return new WP_Error('tr_ai_wire_reel_cards', 'Fal did not return a usable ' . $label . ' clip.');
                }
                $old = (int) get_post_meta($post->ID, $meta_vid, true);
                $name = 'technorapper-social-card-' . (int) $post->ID . '-' . (string) $index . '.mp4';
                $upload = wp_upload_bits($name, null, $bytes);
                if (!empty($upload['error'])) {
                    return new WP_Error('tr_ai_wire_social_video_upload', sanitize_text_field($upload['error']));
                }
                $got = (float) $this->social_reel_output_seconds((string) $upload['file']);
                if ($got <= 0 || $got < ($seconds * 0.9) || $got > $seconds + 3.0) {
                    @unlink((string) $upload['file']);
                    $this->social_fal_pending_clear($post->ID, $meta_fal);
                    return new WP_Error(
                        'tr_ai_wire_reel_cards',
                        'Fal returned a broken ' . $label . ' clip (' . number_format($got, 2) . 's for a ' . number_format($seconds, 1) . 's card). A 1-frame flash would race through slides in milliseconds. The build stopped instead of muxing it. Click Resume to render that card again.'
                    );
                }
                $attachment_id = wp_insert_attachment(array(
                    'post_mime_type' => 'video/mp4',
                    'post_title' => 'Reel ' . $label,
                    'post_status' => 'inherit',
                    'post_parent' => 0,
                ), $upload['file'], 0, true);
                if (is_wp_error($attachment_id)) {
                    return $attachment_id;
                }
                update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
                update_post_meta($attachment_id, '_tr_ai_wire_card_key', $key);
                update_post_meta($post->ID, $meta_vid, (int) $attachment_id);
                if ($old > 0 && $old !== (int) $attachment_id && get_post_meta($old, '_tr_ai_wire_social_asset', true) === '1') {
                    wp_delete_attachment($old, true);
                }
                return array('id' => (int) $attachment_id, 'path' => (string) $upload['file'], 'seconds' => $seconds);
            },
        ));
    }

    /** Lesson lines: the spoken-beat captions (hook … takeaway) plus extra bullets/titles. */
    private function social_reel_lesson_pool($copy, $post = null, $settings = null) {
        $copy = is_array($copy) ? $copy : array();
        $seen = array();
        $clean = function ($rows) use (&$seen) {
            $out = array();
            foreach ((array) $rows as $row) {
                $line = $this->clip_social_text(trim(wp_strip_all_tags(str_replace('*', '', (string) $row))), 72);
                $fold = strtolower($line);
                if ($line === '' || isset($seen[$fold])) {
                    continue;
                }
                $seen[$fold] = true;
                $out[] = $line;
            }
            return $out;
        };
        $core = $clean($copy['reel_captions'] ?? array());
        $extra = $clean(array_merge(
            (array) ($copy['on_image_bullets'] ?? array()),
            array($copy['on_image_headline'] ?? '', $copy['on_image_subhead'] ?? '', $copy['hook'] ?? '')
        ));
        $extra = array_merge($extra, $clean($this->fallback_reel_captions((string) ($copy['reel_script'] ?? ''), $settings)));
        if (!$core && !$extra) {
            $extra = $clean(array($post instanceof WP_Post ? $post->post_title : '', 'Here is what changed'));
        }
        if (!$core) {
            $core = $extra;
            $extra = array();
        }
        return array('core' => $core, 'extra' => $extra);
    }

    /** Card captions in spoken order: hook first, takeaway last, extras in between. */
    private function social_reel_card_lines($pool, $count) {
        $core = array_values((array) ($pool['core'] ?? array()));
        $extra = array_values((array) ($pool['extra'] ?? array()));
        $count = max(1, (int) $count);
        if (!$core) {
            $core = array('Here is what changed');
        }
        if (count($core) >= $count) {
            return $count === 1 ? array($core[0]) : array_merge(array_slice($core, 0, $count - 1), array($core[count($core) - 1]));
        }
        $last = null;
        if (count($core) > 1) {
            $last = array_pop($core);
        }
        $first = array_shift($core);
        $middle = array_merge($core, $extra);
        if (!$middle) {
            $middle = array($first);
        }
        $lines = array($first);
        $need = $count - 1 - ($last !== null ? 1 : 0);
        for ($i = 0; $i < $need; $i++) {
            $lines[] = $middle[$i % count($middle)];
        }
        if ($last !== null) {
            $lines[] = $last;
        }
        return $lines;
    }

    /**
     * Best roundup: on-screen lines come from Writer visual cues that name each
     * packed story. Voiceover slices are only a fallback when captions are thin.
     */
    private function social_reel_roundup_scene_lines($copy, $count, $settings = null) {
        $count = max(1, (int) $count);
        $captions = array();
        foreach ((array) ($copy['reel_captions'] ?? array()) as $line) {
            $line = $this->clip_social_text($line, 42);
            if ($line !== '' && !in_array($line, $captions, true)) {
                $captions[] = $line;
            }
        }
        if (count($captions) >= 8) {
            return array_slice($captions, 0, $count);
        }
        $spoken = $this->social_reel_spoken_scene_lines((string) ($copy['reel_script'] ?? ''), $count);
        if ($spoken) {
            return $spoken;
        }
        return array_slice($captions, 0, $count);
    }

    /**
     * On-screen lines taken from the spoken voiceover, in order, so the card
     * the viewer sees is the beat they are hearing.
     */
    private function social_reel_spoken_scene_lines($script, $count) {
        $count = max(1, (int) $count);
        $words = preg_split('/\s+/', trim(wp_strip_all_tags((string) $script)), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || !$words) {
            return array();
        }
        $per = max(4, (int) ceil(count($words) / $count));
        $lines = array();
        for ($i = 0; $i < count($words) && count($lines) < $count; $i += $per) {
            $chunk = implode(' ', array_slice($words, $i, $per));
            if ($chunk !== '' && !in_array($chunk, $lines, true)) {
                $lines[] = $chunk;
            }
        }
        return $lines;
    }

    private function social_reel_card_label($index, $total, $settings = null) {
        $index = (int) $index;
        $total = max(1, (int) $total);
        if ($this->social_reel_is_roundup($settings)) {
            if ($index === 0) {
                return 'Hook';
            }
            if ($index === $total - 1) {
                return 'Follow';
            }
            $middle = array('What happened', 'Why it matters', 'Meanwhile', 'Next up', 'Even crazier');
            return $middle[($index - 1) % count($middle)];
        }
        if ($index === 0) {
            return 'The change';
        }
        if ($index === $total - 1) {
            return 'Takeaway';
        }
        $middle = array('What it is', 'Why it matters', 'For you');
        return $middle[$index - 1] ?? 'Key point';
    }

    /**
     * The one timeline every Reel cut uses (Fal compose and local ffmpeg).
     * Lesson cards carry the voice; B-roll motion is at most two short accents;
     * a talking host (if any) is one short hook. Nothing here repeats or
     * stretches a motion clip to fill the voice.
     *
     * Each slot: kind card|motion|host, ms, at_ms, seconds; cards also carry
     * card (index), caption, label, step, total; host carries offset_ms, crop.
     */
    private function social_reel_teach_slots($settings, $copy, $post, $audio_seconds, $motion_seconds = 0.0, $host_seconds = 0.0, $host_seek = false) {
        $settings = $this->social_reel_effective_settings($settings);
        $audio_ms = $this->social_reel_ms($this->social_reel_clamp_audio_seconds($audio_seconds, $settings));
        $audio = $audio_ms / 1000;
        $pool = $this->social_reel_lesson_pool($copy, $post, $settings);
        $available = count($pool['core']) + count($pool['extra']);
        if ($this->social_reel_is_roundup($settings)) {
            // A reused B-roll URL is a repeated frame (Fal cannot seek). Roundups
            // are unique stick-figure scenes only — never a looped person clip.
            $motion_seconds = 0.0;
            $host_seconds = 0.0;
        }
        $accent_ms = 0;
        if ((float) $motion_seconds >= 1.0) {
            $accent_ms = $this->social_reel_ms(min(self::SOCIAL_REEL_ACCENT_MAX_SECONDS, (float) $motion_seconds));
        }
        $slides = !empty($settings['social_reel_include_slides']) ? $this->social_reel_fact_slides($settings) : 0;
        $host_ms = (float) $host_seconds >= 1.0 ? $this->social_reel_ms($host_seconds) : 0;
        $rows = array();
        if ($host_ms > 0) {
            $host_len = $host_ms / 1000;
            $cold_ms = $audio >= 8.0 ? $this->social_reel_ms(min(2.6, max(1.8, $audio * 0.14))) : 0;
            $intro_ms = $cold_ms > 0
                ? $this->social_reel_ms(min($host_len, max(2.4, min(3.6, $audio * 0.18))))
                : $this->social_reel_ms(min($host_len, max(3.0, min(5.0, $audio * 0.28))));
            // Always leave room for at least one lesson card after the host hook.
            $intro_ms = max(0, min($intro_ms, $audio_ms - $cold_ms - 1200));
            $left = $audio_ms - $cold_ms - $intro_ms;
            $beat_n = min(5, max(2, min(5, $available), $slides));
            $beat_n = min($beat_n, max(1, $available - ($cold_ms > 0 ? 1 : 0)));
            $beat_n = max(1, min($beat_n, intdiv($left, 1500)));
            $accent_n = ($accent_ms > 0 && $beat_n >= 2 && ($left - $accent_ms) >= $beat_n * 1500) ? 1 : 0;
            $beat_total = $left - ($accent_n * $accent_ms);
            $lines = $this->social_reel_card_lines($pool, max(1, ($cold_ms > 0 ? 1 : 0) + $beat_n));
            $card = 0;
            if ($cold_ms > 0) {
                $rows[] = array('kind' => 'card', 'ms' => $cold_ms, 'card' => $card, 'caption' => $lines[$card]);
                $card++;
            }
            $crop = (!empty($settings['social_reel_medium_camera']) && isset($settings['social_reel_close_camera']) && empty($settings['social_reel_close_camera'])) ? 'medium' : 'close';
            if ($intro_ms > 0) {
                $rows[] = array('kind' => 'host', 'ms' => $intro_ms, 'offset_ms' => $host_seek ? $cold_ms : 0, 'crop' => $crop);
            }
            $each = $beat_n > 0 ? intdiv($beat_total, $beat_n) : 0;
            for ($i = 0; $i < $beat_n; $i++) {
                if ($accent_n > 0 && $i === 1) {
                    $rows[] = array('kind' => 'motion', 'ms' => $accent_ms);
                }
                $ms = ($i === $beat_n - 1) ? ($beat_total - ($each * ($beat_n - 1))) : $each;
                $rows[] = array('kind' => 'card', 'ms' => $ms, 'card' => $card, 'caption' => $lines[$card]);
                $card++;
            }
        } else {
            $accent_n = 0;
            if ($accent_ms > 0) {
                $accent_n = $audio_ms >= 12000 ? self::SOCIAL_REEL_MAX_ACCENTS : 1;
                $accent_n = min($accent_n, (int) floor(($audio_ms * self::SOCIAL_REEL_MOTION_SHARE) / $accent_ms));
                // Fal compose cannot seek; a second use of the same clip is a repeated frame.
                if (!$host_seek) {
                    $accent_n = min(1, $accent_n);
                }
            }
            $card_total = $audio_ms - ($accent_n * $accent_ms);
            $scene_ms = $this->social_reel_is_roundup($settings) ? self::SOCIAL_REEL_HOLD_MIN_SCENE_MS : 4500;
            $min_cards = $this->social_reel_is_roundup($settings) ? 8 : 3;
            if ($this->social_reel_is_roundup($settings)) {
                $card_n = max((int) ceil($card_total / $scene_ms), $min_cards, $slides);
                $card_n = max($min_cards, min($this->social_reel_max_cards($settings), $card_n));
            } else {
                $card_n = max((int) ceil($card_total / $scene_ms), min(4, $available), $slides);
                $card_n = max($min_cards, min($this->social_reel_max_cards($settings), $card_n, $available));
            }
            $card_n = max(1, min($card_n, intdiv($card_total, 1500)));
            if ($this->social_reel_is_roundup($settings)) {
                // Fal images-to-video pads sub-4s stills. Fewer longer holds stay
                // inside the voice; 17×3.3s cards were coming back as a 64s clip.
                $fit = intdiv($card_total, self::SOCIAL_REEL_HOLD_MIN_SCENE_MS);
                if ($fit >= $min_cards) {
                    $card_n = min($card_n, $fit);
                }
            }
            // Accents after the first card and near the middle — never at the end, never back to back.
            $accent_after = array();
            if ($accent_n >= 1 && $card_n >= 2) {
                $accent_after[0] = true;
            }
            if ($accent_n >= 2) {
                $second = max(1, intdiv($card_n, 2));
                if ($second < $card_n - 1) {
                    $accent_after[$second] = true;
                }
            }
            $card_total = $audio_ms - (count($accent_after) * $accent_ms);
            $lines = $this->social_reel_is_roundup($settings)
                ? $this->social_reel_roundup_scene_lines($copy, $card_n, $settings)
                : $this->social_reel_card_lines($pool, $card_n);
            if ($this->social_reel_is_roundup($settings)) {
                if (!$lines) {
                    $lines = $this->social_reel_spoken_scene_lines((string) ($copy['hook'] ?? 'Tech just moved.'), max(8, $min_cards));
                }
                if (!$lines) {
                    $lines = array('Tech just moved.');
                }
                if (count($lines) < $card_n) {
                    $card_n = max(1, min(count($lines), intdiv(max(1500, $card_total), 1500)));
                    $lines = array_slice($lines, 0, $card_n);
                }
            } elseif (count($lines) < $card_n) {
                $lines = $this->social_reel_card_lines($pool, $card_n);
            }
            $each = intdiv($card_total, $card_n);
            $tail_ms = max(0, $this->social_reel_ms($motion_seconds) - $accent_ms);
            $accents = 0;
            for ($i = 0; $i < $card_n; $i++) {
                $ms = ($i === $card_n - 1) ? ($card_total - ($each * ($card_n - 1))) : $each;
                $rows[] = array('kind' => 'card', 'ms' => $ms, 'card' => $i, 'caption' => $lines[$i]);
                if (!empty($accent_after[$i])) {
                    // Local ffmpeg starts the second accent from the clip's tail; Fal compose cannot seek.
                    $rows[] = array('kind' => 'motion', 'ms' => $accent_ms, 'offset_ms' => $accents > 0 ? $tail_ms : 0);
                    $accents++;
                }
            }
        }
        $cards = 0;
        foreach ($rows as $row) {
            if ($row['kind'] === 'card') {
                $cards++;
            }
        }
        $slots = array();
        $at = 0;
        foreach ($rows as $row) {
            $row['at_ms'] = $at;
            $row['seconds'] = $row['ms'] / 1000;
            if ($row['kind'] === 'card') {
                $row['label'] = $this->social_reel_card_label($row['card'], $cards, $settings);
                $row['step'] = $row['card'] + 1;
                $row['total'] = $cards;
                $row['roundup'] = $this->social_reel_is_roundup($settings);
            }
            $at += (int) $row['ms'];
            $slots[] = $row;
        }
        return $slots;
    }

    private function social_reel_guard_error($why) {
        return new WP_Error(
            'tr_ai_wire_reel_cards',
            'Reel build stopped before muxing: ' . $why . '. Lesson cards must carry the voice — the plugin will not fall back to a looping B-roll clip. Click Start over or Generate script again.'
        );
    }

    /**
     * Last check before any mux: lesson cards must carry the voice and motion
     * may only be short accents. Anything else is refused, never rendered.
     */
    private function social_reel_teach_guard($slots, $audio_seconds, $host_seconds = 0.0) {
        $audio_ms = $this->social_reel_ms($audio_seconds);
        $total = 0;
        $cards = 0;
        $card_ms = 0;
        $motion_n = 0;
        $motion_ms = 0;
        $host_ms = 0;
        $roundup = false;
        $captions_seen = array();
        $accent_cap = $this->social_reel_ms(self::SOCIAL_REEL_ACCENT_MAX_SECONDS);
        foreach ((array) $slots as $slot) {
            $ms = (int) ($slot['ms'] ?? 0);
            $kind = (string) ($slot['kind'] ?? '');
            if ($ms <= 0) {
                return $this->social_reel_guard_error('a shot in the timeline has no length');
            }
            $total += $ms;
            if (!empty($slot['roundup'])) {
                $roundup = true;
            }
            if ($kind === 'card') {
                $cards++;
                $card_ms += $ms;
                $fold = strtolower(trim((string) ($slot['caption'] ?? '')));
                if ($fold !== '' && !empty($slot['roundup'])) {
                    if (isset($captions_seen[$fold])) {
                        return $this->social_reel_guard_error('a scene repeats on screen');
                    }
                    $captions_seen[$fold] = true;
                }
            } elseif ($kind === 'motion') {
                $motion_n++;
                $motion_ms += $ms;
                if ($ms > $accent_cap + 1) {
                    return $this->social_reel_guard_error('a B-roll accent runs ' . number_format($ms / 1000, 1) . 's (max ' . number_format(self::SOCIAL_REEL_ACCENT_MAX_SECONDS, 1) . 's)');
                }
            } elseif ($kind === 'host') {
                $host_ms += $ms;
            } else {
                return $this->social_reel_guard_error('unknown shot "' . sanitize_key($kind) . '"');
            }
        }
        if ($cards < 1) {
            return $this->social_reel_guard_error('there are no lesson cards');
        }
        $slack = $roundup ? 50 : 250;
        if (abs($total - $audio_ms) > $slack) {
            return $this->social_reel_guard_error('the cut covers ' . number_format($total / 1000, 1) . 's of a ' . number_format($audio_ms / 1000, 1) . 's voiceover');
        }
        if ($roundup && $total < $audio_ms) {
            return $this->social_reel_guard_error('the pictures stop ' . number_format(($audio_ms - $total) / 1000, 1) . 's before the voiceover, which would leave a blank screen');
        }
        if ($roundup && $motion_n > 0) {
            return $this->social_reel_guard_error('a roundup cannot reuse a B-roll clip');
        }
        if ($motion_n > self::SOCIAL_REEL_MAX_ACCENTS) {
            return $this->social_reel_guard_error('B-roll would appear ' . $motion_n . ' times (max ' . self::SOCIAL_REEL_MAX_ACCENTS . ')');
        }
        if ($motion_ms > (int) round($audio_ms * self::SOCIAL_REEL_MOTION_SHARE) + 1) {
            return $this->social_reel_guard_error('B-roll would fill ' . (int) round(100 * $motion_ms / max(1, $audio_ms)) . '% of the voice');
        }
        if ($host_ms > 0 && $host_ms > $this->social_reel_ms($host_seconds) + 250) {
            return $this->social_reel_guard_error('the host shot is longer than the talking-host clip');
        }
        if ($host_ms === 0 && $card_ms < (int) round($audio_ms * 0.6)) {
            return $this->social_reel_guard_error('lesson cards fill only ' . (int) round(100 * $card_ms / max(1, $audio_ms)) . '% of the voice');
        }
        return true;
    }

    /**
     * Shared prep for every assembler: measure the pieces, plan and guard the
     * teach slots, then draw (and on Fal, bake) every card before any final mux.
     */
    private function social_reel_teach_cut($post, $settings, $copy, $plan, $parts, $photo_bytes, $end_caption, $bake) {
        $voice = is_array($parts['voice'] ?? null) ? $parts['voice'] : array();
        if (empty($voice['path'])) {
            return new WP_Error('tr_ai_wire_reel_tts', 'The Reel voiceover file is missing.');
        }
        $host = (is_array($parts['host'] ?? null) && !empty($parts['host']['path'])) ? $parts['host'] : null;
        $motion = (is_array($parts['motion'] ?? null) && !empty($parts['motion']['path'])) ? $parts['motion'] : null;
        if ($this->social_reel_is_roundup($settings)) {
            // A leftover 5s H3 clip must never lead the Best cut.
            $host = null;
            $motion = null;
        }
        $declared = (float) ($voice['seconds'] ?? 0);
        $measured = $this->social_audio_duration((string) ($voice['path'] ?? ''), (string) ($copy['reel_script'] ?? ''), $settings);
        // Prefer the real file when it is longer than a stale stored length (that
        // froze the end card). Keep a declared length when the probe is a stub.
        $audio = $this->social_reel_clamp_audio_seconds(max($measured, $declared), $settings);
        $motion_seconds = $motion ? $this->social_reel_motion_source_seconds((string) $motion['path'], $settings) : 0.0;
        $host_seconds = 0.0;
        if ($host) {
            $host_seconds = min(
                (float) $this->social_reel_host_seconds($settings),
                (float) $this->social_video_duration((string) $host['path'], min(8.0, $audio))
            );
        }
        $slots = $this->social_reel_teach_slots($settings, $copy, $post, $audio, $motion_seconds, $host_seconds, !empty($plan['host_seek']) && !$bake);
        $guard = $this->social_reel_teach_guard($slots, $audio, $host_seconds);
        if (is_wp_error($guard)) {
            return $guard;
        }
        $image_track = $this->social_reel_is_roundup($settings);
        if ($image_track) {
            $bake = false;
        }
        $sting_seconds = 0.0;
        if (!empty($plan['sting']) && !$image_track) {
            $sting_seconds = !empty($plan['story']) ? (float) $this->social_reel_sting_seconds($settings) : 2.0;
        }
        $use_sting = $sting_seconds > 0 && (string) get_post_meta($post->ID, '_tr_ai_wire_reel_no_sting', true) !== '1';
        $assets = $this->social_reel_teach_assets($post, $copy, is_string($photo_bytes) ? $photo_bytes : '', $slots, $use_sting, $sting_seconds, $end_caption, $bake, $settings);
        if (is_wp_error($assets)) {
            return $assets;
        }
        return array(
            'voice' => $voice,
            'host' => $host,
            'motion' => $motion,
            'audio' => $audio,
            'slots' => $slots,
            'assets' => $assets,
            'use_sting' => $use_sting,
            'sting_seconds' => $use_sting ? $sting_seconds : 0.0,
            'visual_type' => $image_track ? 'image' : 'video',
        );
    }

    /**
     * Draw every lesson card (and the end card) as a readable JPEG; on the Fal
     * path also bake each into an MP4 of exactly its slot length. Any failure
     * stops the build — there is no motion-only fallback.
     */
    private function social_reel_teach_assets($post, $copy, $photo_bytes, $slots, $use_sting, $sting_seconds, $end_caption, $bake, $settings = null) {
        $settings = $this->social_reel_effective_settings($settings);
        $roundup = $this->social_reel_is_roundup($settings);
        $arts = array();
        if ($roundup) {
            $indexes = array();
            $captions = array();
            foreach ((array) $slots as $slot) {
                if (($slot['kind'] ?? '') !== 'card') {
                    continue;
                }
                $index = (int) $slot['card'];
                $indexes[$index] = true;
                $captions[$index] = (string) ($slot['caption'] ?? '');
            }
            $indexes = array_keys($indexes);
            $done = array();
            foreach (array(true, false) as $submit_only) {
                foreach ($indexes as $index) {
                    if (isset($done[$index])) {
                        continue;
                    }
                    $art = $this->social_reel_ensure_scene_art($post, $copy, (string) ($captions[$index] ?? ''), $index, $submit_only, $settings);
                    if (is_wp_error($art)) {
                        if ($art->get_error_code() !== 'tr_ai_wire_reel_video_wait') {
                            return $art;
                        }
                        if (!$submit_only) {
                            break;
                        }
                        continue;
                    }
                    $done[$index] = $art;
                }
            }
            if (count($done) < count($indexes)) {
                return new WP_Error(
                    'tr_ai_wire_reel_video_wait',
                    'Newsroom scenes rendering at Fal: ' . count($done) . ' of ' . count($indexes) . ' ready.'
                );
            }
            $arts = $done;
        }
        $cards = array();
        foreach ((array) $slots as $slot) {
            if (($slot['kind'] ?? '') !== 'card') {
                continue;
            }
            $index = (int) $slot['card'];
            $scene_bytes = $photo_bytes;
            if ($roundup) {
                $scene_bytes = (string) ($arts[$index]['bytes'] ?? '');
            }
            $frame = $this->social_reel_detail_frame($post, $copy, $scene_bytes, (string) ($slot['caption'] ?? ''), $index, array(
                'step' => (int) ($slot['step'] ?? ($index + 1)),
                'total' => (int) ($slot['total'] ?? 1),
                'label' => (string) ($slot['label'] ?? ''),
                'roundup' => !empty($slot['roundup']),
            ));
            if (is_wp_error($frame)) {
                return $frame;
            }
            $cards[$index] = array('frame' => $frame, 'jpeg' => (string) $frame['path'], 'seconds' => (float) $slot['seconds']);
        }
        if (!$cards) {
            return $this->social_reel_guard_error('no lesson cards could be drawn');
        }
        $end = null;
        if ($use_sting) {
            $endcard = $this->social_reel_endcard($post, $copy, $photo_bytes, $end_caption);
            if (is_wp_error($endcard)) {
                return $endcard;
            }
            $end = array('frame' => $endcard, 'jpeg' => (string) $endcard['path'], 'seconds' => max(1.0, (float) $sting_seconds));
        }
        $ready = count($cards);
        $missing = 0;
        if ($bake) {
            $jobs = $cards;
            if ($end) {
                $jobs[90] = $end;
            }
            $done = array();
            foreach (array(true, false) as $submit_only) {
                foreach ($jobs as $index => $job) {
                    if (isset($done[$index])) {
                        continue;
                    }
                    $baked = $this->social_reel_ensure_card_video($post, $job['frame'], $job['seconds'], $index, $submit_only);
                    if (is_wp_error($baked)) {
                        if ($baked->get_error_code() !== 'tr_ai_wire_reel_video_wait') {
                            return $baked;
                        }
                        // Cards were queued together: check the rest on the next tick.
                        if (!$submit_only) {
                            break;
                        }
                        continue;
                    }
                    $done[$index] = $baked;
                }
            }
            foreach ($done as $index => $baked) {
                if ((int) $index === 90 && $end) {
                    $end['video'] = (string) $baked['path'];
                    $end['id'] = (int) $baked['id'];
                } elseif (isset($cards[$index])) {
                    $cards[$index]['video'] = (string) $baked['path'];
                    $cards[$index]['id'] = (int) $baked['id'];
                }
            }
            $ready = count(array_intersect_key($done, $cards));
            $missing = count($jobs) - count($done);
        }
        update_post_meta($post->ID, '_tr_ai_wire_reel_cards_state', wp_json_encode(array(
            'total' => count($cards),
            'ready' => $ready,
            'at' => time(),
        )));
        if ($missing > 0) {
            return new WP_Error('tr_ai_wire_reel_video_wait', 'Lesson cards rendering at Fal: ' . $ready . ' of ' . count($cards) . ' ready.');
        }
        return array('cards' => $cards, 'end' => $end);
    }

    /**
     * Stitch unique roundup JPEGs into one MP4 that holds each scene for its
     * spoken beat. Fal compose image-track timestamps were 1-frame flashes.
     */
    private function social_reel_ensure_scene_hold($post, $teach, $settings) {
        $fps = (int) self::SOCIAL_REEL_HOLD_FPS;
        $images = array();
        $seen = array();
        foreach ((array) ($teach['slots'] ?? array()) as $slot) {
            if (($slot['kind'] ?? '') !== 'card') {
                return $this->social_reel_guard_error('a roundup cannot reuse a B-roll clip');
            }
            $card = $teach['assets']['cards'][(int) $slot['card']] ?? array();
            if (empty($card['jpeg'])) {
                return $this->social_reel_guard_error('lesson card ' . ((int) $slot['card'] + 1) . ' has no still');
            }
            $url = $this->social_reel_file_data_url((string) $card['jpeg'], 'image/jpeg', 'tr_ai_wire_reel_cards', 'A Reel lesson card is missing.');
            if (is_wp_error($url)) {
                return $url;
            }
            $url = (string) $url;
            if ($url === '' || isset($seen[$url])) {
                return $this->social_reel_guard_error('a scene repeats on screen');
            }
            $seen[$url] = true;
            $images[] = array(
                'url' => $url,
                'frames' => $this->social_reel_hold_frames((int) ($slot['ms'] ?? 0), $fps),
            );
        }
        if (!$images) {
            return $this->social_reel_guard_error('the picture track is empty');
        }
        $audio = (float) ($teach['audio'] ?? 0);
        $need = max(1, (int) round($audio * $fps));
        $keep = (int) get_post_meta($post->ID, '_tr_ai_wire_hold_keep', true);
        if ($keep >= 8 && $keep < count($images)) {
            $images = array_slice($images, 0, $keep);
        }
        $images = $this->social_reel_pad_hold_frames($images, $need);
        if (is_wp_error($images)) {
            return $images;
        }
        $scale = (float) get_post_meta($post->ID, '_tr_ai_wire_hold_scale', true);
        if ($scale > 0.35 && $scale < 0.999) {
            $images = $this->social_reel_scale_hold_frames($images, $scale);
            $images = $this->social_reel_pad_hold_frames($images, max(1, (int) round($need * $scale)));
            if (is_wp_error($images)) {
                return $images;
            }
        }
        $frames = 0;
        foreach ($images as $image) {
            $frames += (int) $image['frames'];
        }
        $seconds = round($frames / max(1, $fps), 3);
        $signature = array();
        foreach ($images as $image) {
            $signature[] = md5((string) $image['url']) . ':' . (string) (int) $image['frames'];
        }
        $key = md5(implode('|', array(
            self::SOCIAL_REEL_HOLD_ENDPOINT,
            (string) $fps,
            implode(',', $signature),
            $this->social_reel_cut_name($settings),
        )));
        $meta_vid = '_tr_ai_wire_reel_hold_id';
        $meta_fal = '_tr_ai_wire_fal_hold';
        $n = count($images);
        $existing = (int) get_post_meta($post->ID, $meta_vid, true);
        if ($existing > 0 && (string) get_post_meta($existing, '_tr_ai_wire_hold_key', true) === $key) {
            $have = get_attached_file($existing);
            if ($have && file_exists($have) && filesize($have) > 1024) {
                $got = (float) $this->social_reel_output_seconds($have);
                if ($got >= ($audio * 0.95) && $got <= ($audio + 0.5)) {
                    return array('id' => $existing, 'path' => $have, 'seconds' => $audio > 0 ? $audio : $seconds);
                }
            }
        }
        return $this->social_fal_step(array(
            'owner_id' => $post->ID,
            'post_id' => $post->ID,
            'meta_key' => $meta_fal,
            'endpoint' => self::SOCIAL_REEL_HOLD_ENDPOINT,
            'label' => 'held illustrated scenes',
            'estimate' => 0.0004,
            'enforce_budget' => false,
            'track_usage' => false,
            'settings' => $settings,
            'input_key' => $key,
            'payload' => function () use ($images, $fps) {
                return array(
                    'fps' => $fps,
                    'images' => $images,
                );
            },
            'store' => function ($bytes) use ($post, $meta_vid, $meta_fal, $key, $seconds, $audio, $n) {
                if (!is_string($bytes) || strlen($bytes) < 1024) {
                    return new WP_Error('tr_ai_wire_reel_mux', 'Fal did not return a usable held-scenes clip.');
                }
                $old = (int) get_post_meta($post->ID, $meta_vid, true);
                $name = 'technorapper-social-hold-' . (int) $post->ID . '.mp4';
                $upload = wp_upload_bits($name, null, $bytes);
                if (!empty($upload['error'])) {
                    return new WP_Error('tr_ai_wire_social_video_upload', sanitize_text_field($upload['error']));
                }
                $got = (float) $this->social_reel_output_seconds((string) $upload['file']);
                if ($got <= 0 || $got < ($audio * 0.95)) {
                    @unlink((string) $upload['file']);
                    $this->social_fal_pending_clear($post->ID, $meta_fal);
                    return new WP_Error(
                        'tr_ai_wire_reel_mux',
                        'Fal returned a broken held-scenes clip (' . number_format($got, 2) . 's for ' . number_format($audio, 1) . 's of voice). That would flash slides in milliseconds then go blank. Click Start over — do not Resume the bad file.'
                    );
                }
                if ($got > ($audio + 0.5)) {
                    @unlink((string) $upload['file']);
                    $this->social_fal_pending_clear($post->ID, $meta_fal);
                    if ($this->social_reel_note_hold_scale($post->ID, $audio, $got, $n)) {
                        return new WP_Error(
                            'tr_ai_wire_reel_video_wait',
                            'Fal held scenes longer than the voiceover; rebuilding the picture track to the spoken length.'
                        );
                    }
                    return new WP_Error(
                        'tr_ai_wire_reel_mux',
                        'Fal returned a held-scenes clip (' . number_format($got, 1) . 's) longer than the ' . number_format($audio, 1) . 's voiceover. Click Start over or Generate script again.'
                    );
                }
                $attachment_id = wp_insert_attachment(array(
                    'post_mime_type' => 'video/mp4',
                    'post_title' => 'Reel held scenes',
                    'post_status' => 'inherit',
                    'post_parent' => 0,
                ), $upload['file'], 0, true);
                if (is_wp_error($attachment_id)) {
                    return $attachment_id;
                }
                update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
                update_post_meta($attachment_id, '_tr_ai_wire_hold_key', $key);
                update_post_meta($post->ID, $meta_vid, (int) $attachment_id);
                if ($old > 0 && $old !== (int) $attachment_id && get_post_meta($old, '_tr_ai_wire_social_asset', true) === '1') {
                    wp_delete_attachment($old, true);
                }
                return array('id' => (int) $attachment_id, 'path' => (string) $upload['file'], 'seconds' => $audio > 0 ? $audio : $seconds);
            },
        ));
    }

    /** Fit unique cards to the voice: pad leftover frames, then trim overflow. */
    private function social_reel_pad_hold_frames($images, $need) {
        $images = array_values((array) $images);
        $need = max(1, (int) $need);
        if (!$images) {
            return $this->social_reel_guard_error('the picture track is empty');
        }
        $sum = 0;
        foreach ($images as $image) {
            $sum += (int) ($image['frames'] ?? 0);
        }
        $i = count($images) - 1;
        while ($sum < $need && $i >= 0) {
            $room = 1000 - (int) $images[$i]['frames'];
            if ($room > 0) {
                $add = min($room, $need - $sum);
                $images[$i]['frames'] = (int) $images[$i]['frames'] + $add;
                $sum += $add;
            }
            $i--;
        }
        if ($sum < $need) {
            return $this->social_reel_guard_error('held scenes cannot cover the voice without repeating a frame');
        }
        $i = count($images) - 1;
        while ($sum > $need && $i >= 0) {
            $have = (int) $images[$i]['frames'];
            $room = $have - 1;
            if ($room > 0) {
                $cut = min($room, $sum - $need);
                $images[$i]['frames'] = $have - $cut;
                $sum -= $cut;
            }
            $i--;
        }
        return $images;
    }

    /** Shrink each card's hold so Fal's padded output lands on the voiceover. */
    private function social_reel_scale_hold_frames($images, $scale) {
        $scale = max(0.35, min(1.0, (float) $scale));
        foreach ($images as $i => $image) {
            $images[$i]['frames'] = max(1, min(1000, (int) round((int) ($image['frames'] ?? 1) * $scale)));
        }
        return $images;
    }

    /**
     * Fal images-to-video often returns a longer clip than frames/fps. Remember
     * the inflation, optionally drop trailing cards, and drop the bad hold so
     * Resume rebuilds at the spoken length.
     */
    private function social_reel_note_hold_scale($post_id, $audio, $got, $image_n = 0) {
        $post_id = (int) $post_id;
        $audio = max(1.0, (float) $audio);
        $got = max(0.01, (float) $got);
        $tries = (int) get_post_meta($post_id, '_tr_ai_wire_hold_scale_tries', true);
        if ($tries >= 3) {
            return false;
        }
        $prev = (float) get_post_meta($post_id, '_tr_ai_wire_hold_scale', true);
        if ($prev <= 0 || $prev > 1) {
            $prev = 1.0;
        }
        $scale = max(0.4, min(0.98, $prev * ($audio / $got)));
        update_post_meta($post_id, '_tr_ai_wire_hold_scale', $scale);
        update_post_meta($post_id, '_tr_ai_wire_hold_scale_tries', $tries + 1);
        $n = max(1, (int) $image_n);
        if ($tries >= 1 && $n > 8) {
            $per = $got / $n;
            $keep = max(8, min($n - 1, (int) floor($audio / max(0.4, $per))));
            if ($keep < $n) {
                update_post_meta($post_id, '_tr_ai_wire_hold_keep', $keep);
            }
        }
        $this->social_reel_drop_hold($post_id);
        $this->social_fal_pending_clear($post_id, '_tr_ai_wire_fal_compose_request');
        $this->social_fal_pending_clear($post_id, '_tr_ai_wire_fal_trim_request');
        $this->social_reel_clear_trim_src($post_id, 0);
        $this->social_reel_progress_note($post_id, 'Rebuilding held scenes so the pictures stop with the voiceover.');
        return true;
    }

    private function social_reel_drop_hold($post_id) {
        $post_id = (int) $post_id;
        $hold = (int) get_post_meta($post_id, '_tr_ai_wire_reel_hold_id', true);
        if ($hold > 0 && get_post_meta($hold, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment($hold, true);
        }
        delete_post_meta($post_id, '_tr_ai_wire_reel_hold_id');
        $this->social_fal_pending_clear($post_id, '_tr_ai_wire_fal_hold');
    }

    private function social_reel_clear_hold_scale($post_id) {
        foreach (array('_tr_ai_wire_hold_scale', '_tr_ai_wire_hold_scale_tries', '_tr_ai_wire_hold_keep') as $meta) {
            delete_post_meta((int) $post_id, $meta);
        }
    }

    /** Fal compose keyframes, one unique file per teach slot, then the end card. */
    private function social_reel_fal_teach_keyframes($teach) {
        if (!empty($teach['hold']['path'])) {
            $url = $this->social_reel_file_data_url((string) $teach['hold']['path'], 'video/mp4', 'tr_ai_wire_reel_mux', 'The held illustrated scenes clip is missing.');
            if (is_wp_error($url)) {
                return $url;
            }
            $audio_ms = $this->social_reel_ms((float) ($teach['audio'] ?? 0));
            $ms = $audio_ms > 0 ? $audio_ms : $this->social_reel_ms((float) ($teach['hold']['seconds'] ?? 0));
            return $this->social_reel_fill_visual_to_audio(array(
                array('timestamp' => 0, 'duration' => $ms, 'url' => (string) $url),
            ), $ms);
        }
        $image_track = (($teach['visual_type'] ?? 'video') === 'image');
        $motion_url = '';
        $host_url = '';
        $visual = array();
        $clock = 0;
        foreach ((array) $teach['slots'] as $slot) {
            $kind = (string) $slot['kind'];
            if ($kind === 'card') {
                $card = $teach['assets']['cards'][(int) $slot['card']] ?? array();
                if ($image_track) {
                    if (empty($card['jpeg'])) {
                        return $this->social_reel_guard_error('lesson card ' . ((int) $slot['card'] + 1) . ' has no still');
                    }
                    $url = $this->social_reel_file_data_url((string) $card['jpeg'], 'image/jpeg', 'tr_ai_wire_reel_cards', 'A Reel lesson card is missing.');
                } else {
                    if (empty($card['video'])) {
                        return $this->social_reel_guard_error('lesson card ' . ((int) $slot['card'] + 1) . ' has no video clip');
                    }
                    $url = $this->social_reel_file_data_url((string) $card['video'], 'video/mp4', 'tr_ai_wire_reel_cards', 'A Reel lesson-card clip is missing.');
                }
            } elseif ($kind === 'motion') {
                if ($image_track) {
                    return $this->social_reel_guard_error('a roundup cannot reuse a B-roll clip');
                }
                if ($motion_url === '') {
                    $motion_url = $this->social_reel_file_data_url((string) ($teach['motion']['path'] ?? ''), 'video/mp4', 'tr_ai_wire_reel_video', 'Generated Reel motion is missing.');
                }
                $url = $motion_url;
            } else {
                if ($image_track) {
                    return $this->social_reel_guard_error('a roundup cannot reuse a host clip');
                }
                if ($host_url === '') {
                    $host_url = $this->social_reel_file_data_url((string) ($teach['host']['path'] ?? ''), 'video/mp4', 'tr_ai_wire_reel_host', 'The talking-host clip is missing.');
                }
                $url = $host_url;
            }
            if (is_wp_error($url)) {
                return $url;
            }
            $visual[] = array('timestamp' => $clock, 'duration' => (int) $slot['ms'], 'url' => (string) $url);
            $clock += (int) $slot['ms'];
        }
        if (!empty($teach['use_sting'])) {
            $end = $teach['assets']['end'] ?? array();
            $end_path = $image_track ? (string) ($end['jpeg'] ?? '') : (string) ($end['video'] ?? '');
            if ($end_path === '') {
                return $this->social_reel_guard_error('the end card has no ' . ($image_track ? 'still' : 'video clip'));
            }
            $end_url = $this->social_reel_file_data_url($end_path, $image_track ? 'image/jpeg' : 'video/mp4', 'tr_ai_wire_reel_frames', 'The Reel end card is missing.');
            if (is_wp_error($end_url)) {
                return $end_url;
            }
            $visual[] = array('timestamp' => $clock, 'duration' => $this->social_reel_ms($teach['sting_seconds']), 'url' => (string) $end_url);
        }
        $seen = array();
        foreach ($visual as $frame) {
            $url = (string) ($frame['url'] ?? '');
            if ($url === '') {
                return $this->social_reel_guard_error('a scene file is missing');
            }
            if (isset($seen[$url])) {
                return $this->social_reel_guard_error('a scene repeats on screen');
            }
            $seen[$url] = true;
            if ($image_track) {
                continue;
            }
            if (strpos($url, 'data:image/') === 0 || preg_match('/\.jpe?g(\?|$)/i', $url)) {
                return new WP_Error(
                    'tr_ai_wire_reel_frames',
                    'Reel assemble blocked a still image on the Fal video track (that caused multi-hour frozen files). Start over to rebuild with animated lesson cards.'
                );
            }
        }
        $audio_ms = $this->social_reel_ms((float) ($teach['audio'] ?? 0));
        return $this->social_reel_fill_visual_to_audio($visual, $audio_ms);
    }

    /** Last timestamp plus duration, in milliseconds. */
    private function social_reel_visual_coverage_ms($visual) {
        $end = 0;
        foreach ((array) $visual as $frame) {
            $end = max($end, (int) ($frame['timestamp'] ?? 0) + (int) ($frame['duration'] ?? 0));
        }
        return $end;
    }

    /**
     * Fal pads a short picture track with black. Refuse gaps, then hold the
     * last unique card through the rest of the voiceover.
     */
    private function social_reel_fill_visual_to_audio($visual, $audio_ms) {
        $visual = array_values((array) $visual);
        $audio_ms = (int) $audio_ms;
        if (!$visual) {
            return $this->social_reel_guard_error('the picture track is empty');
        }
        $clock = 0;
        foreach ($visual as $i => $frame) {
            $at = (int) ($frame['timestamp'] ?? -1);
            $ms = (int) ($frame['duration'] ?? 0);
            if ($ms <= 0) {
                return $this->social_reel_guard_error('a scene has no length');
            }
            if ($at !== $clock) {
                return $this->social_reel_guard_error('the picture track has a blank gap at ' . number_format($clock / 1000, 1) . 's');
            }
            $visual[$i]['timestamp'] = $at;
            $visual[$i]['duration'] = $ms;
            $clock += $ms;
        }
        if ($audio_ms > 0 && $clock < $audio_ms) {
            $last = count($visual) - 1;
            $visual[$last]['duration'] = (int) $visual[$last]['duration'] + ($audio_ms - $clock);
        }
        return $visual;
    }

    private function social_reel_kit_files($teach) {
        $files = array('voice.mp3' => (string) ($teach['voice']['path'] ?? ''));
        if (!empty($teach['host']['path'])) {
            $files['host.mp4'] = (string) $teach['host']['path'];
        }
        if (!empty($teach['motion']['path'])) {
            $files['motion.mp4'] = (string) $teach['motion']['path'];
        }
        foreach ((array) ($teach['assets']['cards'] ?? array()) as $index => $card) {
            $files['card-' . (string) ((int) $index + 1) . '.jpg'] = (string) ($card['jpeg'] ?? '');
        }
        if (!empty($teach['use_sting']) && !empty($teach['assets']['end']['jpeg'])) {
            $files['end.jpg'] = (string) $teach['assets']['end']['jpeg'];
        }
        return $files;
    }

    private function social_reel_kit_slots($teach) {
        $slots = (array) ($teach['slots'] ?? array());
        if (!empty($teach['use_sting'])) {
            $slots[] = array('kind' => 'end', 'ms' => $this->social_reel_ms($teach['sting_seconds']));
        }
        return $slots;
    }

    private function social_reel_assemble_with_fal($post, $settings, $copy, $script, $plan, $parts, $photo_bytes, $end_caption) {
        $teach = $this->social_reel_teach_cut($post, $settings, $copy, $plan, $parts, $photo_bytes, $end_caption, true);
        if (is_wp_error($teach)) {
            return $teach;
        }
        if (($teach['visual_type'] ?? '') === 'image') {
            $hold = $this->social_reel_ensure_scene_hold($post, $teach, $settings);
            if (is_wp_error($hold)) {
                return $hold;
            }
            $teach['hold'] = $hold;
            $teach['visual_type'] = 'video';
            $teach['motion'] = null;
            $teach['host'] = null;
        }
        $trim_src = (int) get_post_meta($post->ID, '_tr_ai_wire_reel_trim_src', true);
        if ($trim_src > 0) {
            $path = get_attached_file($trim_src);
            $got = ($path && file_exists($path)) ? (float) $this->social_reel_output_seconds($path) : 0.0;
            $cards = 0;
            foreach ((array) ($teach['slots'] ?? array()) as $slot) {
                if (($slot['kind'] ?? '') === 'card') {
                    $cards++;
                }
            }
            $audio = (float) ($teach['audio'] ?? 0);
            if ($got > ($audio + 0.5) && $this->social_reel_note_hold_scale($post->ID, $audio, $got, $cards)) {
                if (get_post_meta($trim_src, '_tr_ai_wire_social_asset', true) === '1') {
                    wp_delete_attachment($trim_src, true);
                }
                return new WP_Error('tr_ai_wire_reel_video_wait', 'Fal held scenes longer than the voiceover; rebuilding the picture track to the spoken length.');
            }
            if (get_post_meta($trim_src, '_tr_ai_wire_social_asset', true) === '1') {
                wp_delete_attachment($trim_src, true);
            }
            delete_post_meta($post->ID, '_tr_ai_wire_reel_trim_src');
        }
        $voice = $teach['voice'];
        $audio_seconds = (float) $teach['audio'];
        $signature = array();
        foreach ($teach['slots'] as $slot) {
            $signature[] = $slot['kind'] . ':' . (int) $slot['ms']
                . ($slot['kind'] === 'card' ? ':' . (int) ($teach['assets']['cards'][(int) $slot['card']]['id'] ?? 0) : '');
        }
        $input_key = md5(implode('|', array(
            $this->social_reel_cut_name($settings),
            (string) ($voice['key'] ?? ''),
            (int) ($teach['hold']['id'] ?? 0),
            (int) ($teach['host']['id'] ?? 0),
            (int) ($teach['motion']['id'] ?? 0),
            $teach['use_sting'] ? (string) (int) ($teach['assets']['end']['id'] ?? 0) : 'no-sting',
            implode(',', $signature),
        )));
        $payload = function () use ($teach, $voice, $audio_seconds) {
            $voice_url = $this->social_reel_file_data_url((string) $voice['path'], 'audio/mpeg', 'tr_ai_wire_reel_tts', 'The Reel voiceover file is missing.');
            if (is_wp_error($voice_url)) {
                return $voice_url;
            }
            $visual = $this->social_reel_fal_teach_keyframes($teach);
            if (is_wp_error($visual)) {
                return $visual;
            }
            $image_track = (($teach['visual_type'] ?? 'video') === 'image');
            $cover_ms = $this->social_reel_visual_coverage_ms($visual);
            $voice_ms = $this->social_reel_ms($audio_seconds);
            if ($image_track) {
                // Same length as the cards so Fal cannot pad leftover audio with black.
                $voice_ms = max($voice_ms, $cover_ms);
            } else {
                // Never ask Fal for pictures longer than the voice (leftover freeze).
                $voice_ms = min($voice_ms, $this->social_reel_ms($audio_seconds));
            }
            return array('tracks' => array(
                array('id' => 'visual', 'type' => $image_track ? 'image' : 'video', 'keyframes' => $visual),
                array('id' => 'voice', 'type' => 'audio', 'keyframes' => array(
                    array('timestamp' => 0, 'duration' => $voice_ms, 'url' => $voice_url),
                )),
            ));
        };
        $store = $this->social_reel_fal_mux_store($post, $copy, $script, $settings, $audio_seconds, $teach);
        $result = $this->social_fal_step(array(
            'owner_id' => $post->ID,
            'post_id' => $post->ID,
            'meta_key' => '_tr_ai_wire_fal_compose_request',
            'endpoint' => self::SOCIAL_REEL_MUX_ENDPOINT,
            'label' => 'finished MP4',
            'estimate' => round(0.0002 * ($audio_seconds + (float) $teach['sting_seconds']), 4),
            'enforce_budget' => false,
            'settings' => $settings,
            'input_key' => $input_key,
            'payload' => $payload,
            'store' => $store,
        ));
        if (!is_wp_error($result) || $result->get_error_code() !== 'tr_ai_wire_reel_video_failed') {
            return $result;
        }
        if ($teach['use_sting']) {
            update_post_meta($post->ID, '_tr_ai_wire_reel_no_sting', '1');
            return new WP_Error('tr_ai_wire_reel_video_wait', 'Fal could not place the end card, so the video is being assembled again without it.');
        }
        $this->store_social_reel_laptop_kit($post, $this->social_reel_kit_files($teach), $audio_seconds, $copy, $this->social_reel_kit_slots($teach));
        return new WP_Error(
            'tr_ai_wire_reel_mux',
            $result->get_error_message() . ' Your voiceover, lesson cards, and clips are saved on this row, so Resume only retries the assembly and does not pay for them again.'
        );
    }

    /** Persist a Fal mux, or rebuild the hold if Fal ignored the voice length. */
    private function social_reel_fal_mux_store($post, $copy, $script, $settings, $audio_seconds, $teach) {
        return function ($bytes) use ($post, $copy, $script, $settings, $audio_seconds, $teach) {
            if (!is_string($bytes) || strlen($bytes) < 1024) {
                return new WP_Error('tr_ai_wire_reel_mux', 'Fal did not return a usable Reel video.');
            }
            $stored = $this->store_social_video($post, $bytes, $copy, $script, $settings);
            if (is_wp_error($stored)) {
                return $stored;
            }
            $path = get_attached_file((int) $stored);
            $got = $path ? (float) $this->social_reel_output_seconds($path) : 0.0;
            $card_n = 0;
            foreach ((array) ($teach['slots'] ?? array()) as $slot) {
                if (($slot['kind'] ?? '') === 'card') {
                    $card_n++;
                }
            }
            $kept = $this->social_reel_keep_or_trim_mux(
                $post,
                (int) $stored,
                $path,
                $got,
                (float) $audio_seconds,
                (float) ($teach['sting_seconds'] ?? 0),
                $settings,
                $card_n
            );
            if (is_wp_error($kept)) {
                return $kept;
            }
            $path = get_attached_file((int) $stored);
            $url = wp_get_attachment_url((int) $stored);
            if (!$url || !$path) {
                return new WP_Error('tr_ai_wire_ig_video_url', 'The Instagram Reel must have a public HTTP(S) Media Library URL.');
            }
            return array('id' => (int) $stored, 'url' => $url, 'path' => $path);
        };
    }

    /**
     * Accept a mux that matches the voice. Leftover freeze is trimmed with
     * ffmpeg when present; otherwise the hold is rebuilt shorter so Fal cannot
     * ignore a compose duration and post blank frames.
     */
    private function social_reel_keep_or_trim_mux($post, $stored, $path, $got, $audio_seconds, $sting_seconds, $settings, $card_n = 0) {
        $max = $this->social_reel_max_audio_seconds($settings) + 8.0;
        $ceiling = $audio_seconds + (float) $sting_seconds + 1.25;
        $problem = '';
        if ($got > $max) {
            $problem = 'Fal returned a broken overlong Reel (' . (string) (int) round($got) . 's). That usually means a still was muxed as video.';
        } elseif ($got > $ceiling) {
            $problem = 'Fal returned a Reel (' . number_format($got, 1) . 's) longer than the ' . number_format($audio_seconds, 1) . 's voiceover, so the leftover would be a blank or frozen screen.';
        } elseif ($got > 0 && $got < ($audio_seconds * 0.95)) {
            $problem = 'Fal returned a Reel (' . number_format($got, 1) . 's) much shorter than the ' . number_format($audio_seconds, 1) . 's voiceover.';
        }
        if ($problem === '') {
            $this->social_reel_clear_trim_src($post->ID, (int) $stored);
            $this->social_reel_clear_hold_scale($post->ID);
            update_post_meta($post->ID, '_tr_ai_wire_social_reel_id', (int) $stored);
            return true;
        }
        $covers = ($got >= ($audio_seconds * 0.95) && $got <= $max && $got > $ceiling);
        if ($covers) {
            $target = $audio_seconds + (float) $sting_seconds;
            $trimmed = $this->social_reel_trim_mp4($path, $target);
            if (!is_wp_error($trimmed) && (float) $trimmed <= $ceiling && (float) $trimmed >= ($audio_seconds * 0.95)) {
                $this->social_reel_clear_trim_src($post->ID, (int) $stored);
                $this->social_reel_clear_hold_scale($post->ID);
                update_post_meta($post->ID, '_tr_ai_wire_social_reel_id', (int) $stored);
                return true;
            }
            if ($this->social_reel_note_hold_scale($post->ID, $audio_seconds, $got, $card_n)) {
                if (get_post_meta((int) $stored, '_tr_ai_wire_social_asset', true) === '1') {
                    wp_delete_attachment((int) $stored, true);
                }
                delete_post_meta($post->ID, '_tr_ai_wire_social_reel_id');
                return new WP_Error(
                    'tr_ai_wire_reel_video_wait',
                    'Fal held scenes longer than the voiceover; rebuilding the picture track to the spoken length.'
                );
            }
        }
        if (get_post_meta((int) $stored, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment((int) $stored, true);
        }
        delete_post_meta($post->ID, '_tr_ai_wire_social_reel_id');
        $this->social_reel_clear_trim_src($post->ID, (int) $stored);
        return new WP_Error('tr_ai_wire_reel_mux', $problem . ' Click Start over or Generate script again — do not Resume the bad file.');
    }

    /** Cut a finished MP4 to the voiceover when this host has ffmpeg. */
    private function social_reel_trim_mp4($path, $seconds) {
        $ffmpeg = $this->social_ffmpeg_path();
        if ($ffmpeg === '' || !is_string($path) || $path === '' || !is_readable($path)) {
            return new WP_Error('tr_ai_wire_reel_ffmpeg', 'This host has no ffmpeg to trim leftover frames.');
        }
        $seconds = max(1.0, (float) $seconds);
        $out = dirname($path) . '/tr-trim-' . wp_generate_password(8, false, false) . '.mp4';
        $t = sprintf('%.3F', $seconds);
        $runs = array(
            array('-y', '-i', $path, '-t', $t, '-c', 'copy', '-movflags', '+faststart', $out),
            array(
                '-y', '-i', $path, '-t', $t,
                '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-level', '4.0',
                '-c:a', 'aac', '-b:a', '128k', '-ar', '44100',
                '-movflags', '+faststart', $out,
            ),
        );
        foreach ($runs as $args) {
            $result = $this->social_run_binary($ffmpeg, $args, 90);
            if (is_wp_error($result) || !is_readable($out) || filesize($out) < 1024) {
                @unlink($out);
                continue;
            }
            $got = (float) $this->social_reel_output_seconds($out);
            if ($got <= 0 || $got > ($seconds + 0.4)) {
                @unlink($out);
                continue;
            }
            if (!@rename($out, $path)) {
                @unlink($out);
                return new WP_Error('tr_ai_wire_social_video_upload', 'Could not replace the overlong Reel with the trimmed file.');
            }
            return $got;
        }
        return new WP_Error('tr_ai_wire_reel_mux', 'ffmpeg could not trim leftover frames from the Reel.');
    }

    private function social_reel_clear_trim_src($post_id, $keep_id = 0) {
        $post_id = (int) $post_id;
        $src = (int) get_post_meta($post_id, '_tr_ai_wire_reel_trim_src', true);
        if ($src > 0 && $src !== (int) $keep_id && get_post_meta($src, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment($src, true);
        }
        delete_post_meta($post_id, '_tr_ai_wire_reel_trim_src');
        $this->social_fal_pending_clear($post_id, '_tr_ai_wire_fal_trim_request');
    }

    /** Local ffmpeg cut from the same teach slots: card stills, short accents, host hook, sting. */
    private function social_reel_assemble_locally($post, $settings, $copy, $script, $plan, $parts, $photo_bytes, $end_caption) {
        $teach = $this->social_reel_teach_cut($post, $settings, $copy, $plan, $parts, $photo_bytes, $end_caption, false);
        if (is_wp_error($teach)) {
            return $teach;
        }
        $this->social_reel_progress_note(
            $post->ID,
            $this->social_reel_is_roundup($settings)
                ? 'Assembling the viral 2D newsroom roundup on this server: each illustrated scene is held for the spoken beat, no blanks.'
                : 'Assembling the teach cut on this server: lesson cards first, short B-roll accents only.'
        );
        $clips = array();
        foreach ($teach['slots'] as $slot) {
            $seconds = (float) $slot['seconds'];
            if ($slot['kind'] === 'card') {
                $clips[] = array('kind' => 'card', 'path' => (string) ($teach['assets']['cards'][(int) $slot['card']]['jpeg'] ?? ''), 'duration' => $seconds);
            } elseif ($slot['kind'] === 'motion') {
                $clips[] = array(
                    'kind' => 'broll',
                    'path' => (string) ($teach['motion']['path'] ?? ''),
                    'duration' => $seconds,
                    'offset' => ((int) ($slot['offset_ms'] ?? 0)) / 1000,
                );
            } else {
                $clips[] = array(
                    'kind' => 'host',
                    'path' => (string) ($teach['host']['path'] ?? ''),
                    'duration' => $seconds,
                    'offset' => ((int) ($slot['offset_ms'] ?? 0)) / 1000,
                    'crop' => (string) ($slot['crop'] ?? 'close'),
                );
            }
        }
        if (!empty($teach['use_sting'])) {
            $clips[] = array('kind' => 'end', 'path' => (string) ($teach['assets']['end']['jpeg'] ?? ''), 'duration' => (float) $teach['sting_seconds']);
        }
        $tmp = get_temp_dir() . 'tr-ai-wire-reel-' . wp_generate_password(8, false, false);
        if (!wp_mkdir_p($tmp)) {
            return new WP_Error('tr_ai_wire_reel_temp', 'Could not create a temporary directory for the Reel.');
        }
        $out_path = $tmp . '/reel.mp4';
        $muxed = $this->compose_social_reel_story($clips, (string) $teach['voice']['path'], $out_path);
        if (is_wp_error($muxed)) {
            $this->social_reel_cleanup($tmp);
            return $muxed;
        }
        return $this->social_reel_store_finished($post, $settings, $copy, $script, $out_path, $tmp);
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
            $steps[] = array('key' => 'motion', 'label' => 'B-roll accent clip (shown at most twice, ~2s each)', 'state' => $done ? 'done' : ($info['active'] ? 'active' : 'todo'), 'detail' => $done ? '' : $info['detail']);
        }
        $finished = $this->social_reel_id($post_id) > 0;
        $cards = json_decode((string) get_post_meta($post_id, '_tr_ai_wire_reel_cards_state', true), true);
        $cards_total = is_array($cards) ? (int) ($cards['total'] ?? 0) : 0;
        $cards_ready = is_array($cards) ? (int) ($cards['ready'] ?? 0) : 0;
        $cards_active = false;
        for ($i = 0; $i < self::SOCIAL_REEL_MAX_ROUNDUP_CARDS; $i++) {
            if ($this->social_fal_pending_get($post_id, '_tr_ai_wire_fal_card_' . (string) $i)
                || $this->social_fal_pending_get($post_id, '_tr_ai_wire_fal_scene_' . (string) $i)
            ) {
                $cards_active = true;
                break;
            }
        }
        $cards_done = $finished || ($cards_total > 0 && $cards_ready >= $cards_total);
        $roundup = $this->social_reel_is_roundup($settings);
        $steps[] = array(
            'key' => 'cards',
            'label' => $roundup ? 'Unique illustrated 2D newsroom scenes (match each story, never repeated, no blanks)' : 'Lesson cards (they carry the Reel)',
            'state' => $cards_done ? 'done' : (($cards_active || $cards_total > 0) ? 'active' : 'todo'),
            'detail' => (!$cards_done && $cards_total > 0) ? ((string) $cards_ready . ' of ' . (string) $cards_total . ' ready') : '',
        );
        if ($roundup) {
            $hold_info = $describe('_tr_ai_wire_fal_hold');
            $hold_done = $finished || (int) get_post_meta($post_id, '_tr_ai_wire_reel_hold_id', true) > 0;
            $steps[] = array(
                'key' => 'hold',
                'label' => 'Hold each illustrated scene for the spoken beat',
                'state' => $hold_done ? 'done' : ($hold_info['active'] ? 'active' : 'todo'),
                'detail' => $hold_done ? '' : $hold_info['detail'],
            );
        }
        $info = $describe('_tr_ai_wire_fal_compose_request');
        $steps[] = array(
            'key' => 'assemble',
            'label' => $roundup ? 'Assemble the viral 2D newsroom roundup (held scenes + voice)' : 'Assemble the teach cut (lesson cards + voice + sting)',
            'state' => $finished ? 'done' : ($info['active'] ? 'active' : 'todo'),
            'detail' => $finished ? '' : $info['detail'],
        );
        return $steps;
    }

    private function ensure_social_reel($post, $settings, $copy, $broll_path) {
        if (!$post instanceof WP_Post) {
            return new WP_Error('tr_ai_wire_social_post', 'The source article is no longer published.');
        }
        $settings = $this->social_reel_effective_settings($settings);
        if ($this->social_reel_dev_cheap_on($settings)) {
            $this->social_reel_progress_note($post->ID, 'Dev cheap test is on — no face, Veo Lite 4s accent, lesson cards carry the Reel.');
        }
        $copy = is_array($copy) ? $copy : array();
        $script = (string) ($copy['reel_script'] ?? '');
        if ($script === '') {
            $script = $this->fallback_reel_script($copy, $post, $settings);
        }
        $this->social_reel_drop_stale_cut($post->ID);
        $existing = $this->social_reel_id($post->ID);
        if ($existing > 0) {
            // Story Reels store the researched script, so accept that one as well.
            $scripts = array(sanitize_textarea_field($script));
            $lesson = json_decode((string) get_post_meta($post->ID, '_tr_ai_wire_reel_lesson', true), true);
            if (
                is_array($lesson) && !empty($lesson['reel_script'])
                && (string) ($lesson['key'] ?? '') === $this->social_reel_lesson_key($post, $settings, $copy, $script)
            ) {
                $scripts[] = sanitize_textarea_field((string) $lesson['reel_script']);
            }
            $url = wp_get_attachment_url($existing);
            $path = get_attached_file($existing);
            if ($url && $path && in_array((string) get_post_meta($existing, '_tr_ai_wire_reel_script', true), $scripts, true)) {
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
        $end_caption = (string) ($copy['cta'] ?? ('Full story on ' . self::site_brand_name()));
        if (!$plan['local']) {
            $this->social_reel_progress_note($post->ID, 'Assembling a lesson-card Reel at Fal: cards first, short B-roll accents only.');
            return $this->social_reel_assemble_with_fal($post, $settings, $copy, $script, $plan, $parts, $photo, $end_caption);
        }
        return $this->social_reel_assemble_locally($post, $settings, $copy, $script, $plan, $parts, $photo, $end_caption);
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
        if (!$plan['local'] && $this->fal_api_key() === '') {
            return new WP_Error(
                'tr_ai_wire_reel_fal_key',
                'Instagram Reels need a Fal.ai API key to assemble the spoken MP4.'
            );
        }
        if ($plan['motion'] && (!is_string($broll_path) || $broll_path === '' || !file_exists($broll_path))) {
            return new WP_Error('tr_ai_wire_reel_broll', 'A text-free 9:16 B-roll frame is required before rendering the Reel.');
        }
        $copy = is_array($copy) ? $copy : array();
        if ($script === '') {
            $script = (string) ($copy['reel_script'] ?? '');
            if ($script === '') {
                $script = $this->fallback_reel_script($copy, $post, $settings);
            }
        }
        $researched = $this->social_reel_enrich_lesson($post, $settings, $copy, $script);
        if (is_wp_error($researched)) {
            return $researched;
        }
        $copy = $researched['copy'];
        $script = $researched['script'];
        $parts = $this->social_reel_gather($post, $settings, $copy, $broll_path, $plan, $script);
        if (is_wp_error($parts)) {
            return $parts;
        }
        $photo = '';
        if (is_string($broll_path) && $broll_path !== '' && file_exists($broll_path)) {
            $bytes = file_get_contents($broll_path);
            if (is_string($bytes)) {
                $photo = $bytes;
            }
        }
        $end_caption = (string) ($copy['cta'] ?? ('Full story on ' . self::site_brand_name()));
        if ($plan['local']) {
            return $this->social_reel_assemble_locally($post, $settings, $copy, $script, $plan, $parts, $photo, $end_caption);
        }
        $note = $this->social_reel_is_roundup($settings)
            ? 'Assembling a viral 2D newsroom roundup at Fal: each illustrated scene is held for the spoken beat, no leftover 5s B-roll, no millisecond slides.'
            : 'Assembling a teach Reel at Fal: lesson cards first, short B-roll accents only.';
        if (!empty($plan['host'])) {
            $note = 'Assembling a story Reel at Fal: cold-open lesson card, short host hook, then lesson cards.';
        }
        $this->social_reel_progress_note($post->ID, $note);
        return $this->social_reel_assemble_with_fal($post, $settings, $copy, $script, $plan, $parts, $photo, $end_caption);
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
            if ($kind === 'end' || $kind === 'card') {
                $args = array_merge($args, array('-loop', '1', '-t', $clip_t, '-i', (string) $clip['path']));
            } elseif ($kind === 'host') {
                $input = array('-stream_loop', '-1');
                $offset = max(0.0, (float) ($clip['offset'] ?? 0));
                $length = $this->social_video_duration((string) $clip['path'], 0);
                if ($length > 0 && ($offset + $duration) > $length) {
                    $offset = max(0.0, $length - $duration);
                }
                if ($offset > 0.05) {
                    $input[] = '-ss';
                    $input[] = number_format($offset, 2, '.', '');
                }
                $args = array_merge($args, $input, array('-t', $clip_t, '-i', (string) $clip['path']));
            } else {
                // B-roll accents play once; they are never looped to fill time.
                $seek = array();
                $offset = max(0.0, (float) ($clip['offset'] ?? 0));
                if ($offset > 0.05) {
                    $length = $this->social_video_duration((string) $clip['path'], 0);
                    $offset = $length > 0 ? max(0.0, min($offset, $length - $duration)) : 0.0;
                }
                if ($offset > 0.05) {
                    $seek = array('-ss', number_format($offset, 2, '.', ''));
                }
                $args = array_merge($args, $seek, array('-t', $clip_t, '-i', (string) $clip['path']));
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
            '-map', '0:v',
            '-map', '1:a',
            // Pad the voice with silence so -shortest ends on the video (keeps the sting).
            '-af', 'apad',
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
                return $this->social_fal_motion_payload(
                    $model,
                    $image_url,
                    $this->social_reel_motion_prompt($copy, $post, $settings),
                    $this->social_reel_clip_seconds($settings)
                );
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

    private function social_reel_laptop_readme($duration, $names = array()) {
        $duration = max(3, (int) round((float) $duration));
        $names = array_values(array_filter((array) $names, function ($name) {
            return !in_array($name, array('README.txt', 'make-reel.sh', 'make-reel.bat'), true);
        }));
        return "AI Wire Reel kit\n"
            . "================\n\n"
            . "Fal could not assemble this Reel, so finish the same cut on your laptop.\n"
            . "Lesson cards carry the Reel; the B-roll clip only appears as one or two short accents.\n\n"
            . "1. Install ffmpeg:\n"
            . "   Windows: https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip\n"
            . "            Unzip and put ffmpeg.exe in this folder (or add it to PATH).\n"
            . "   Mac:     https://evermeet.cx/ffmpeg/getrelease/zip\n"
            . "            or: brew install ffmpeg\n"
            . "   Official: https://ffmpeg.org/download.html\n\n"
            . "2. Keep these files together with make-reel.bat (Windows) or make-reel.sh (Mac/Linux):\n"
            . "   " . implode(', ', $names) . "\n\n"
            . "3. Run make-reel.bat or: bash make-reel.sh\n"
            . "   Spoken length used: {$duration} seconds.\n\n"
            . "4. Upload reel.mp4 on the Social queue row, then Publish.\n";
    }

    /** Laptop ffmpeg script that renders the same teach slots: card stills, single-play accents, sting. */
    private function social_reel_laptop_script($slots, $windows = false) {
        $vf = 'scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,fps=30,setsar=1,format=yuv420p';
        $encode = ' -an -c:v libx264 -pix_fmt yuv420p -profile:v high -level 4.0 ';
        $renders = array();
        $parts = array();
        foreach (array_values((array) $slots) as $i => $slot) {
            $seconds = number_format(max(0.5, ((int) ($slot['ms'] ?? 0)) / 1000), 2, '.', '');
            $kind = (string) ($slot['kind'] ?? '');
            if ($kind === 'card') {
                $input = '-loop 1 -t ' . $seconds . ' -i card-' . (string) ((int) $slot['card'] + 1) . '.jpg';
            } elseif ($kind === 'end') {
                $input = '-loop 1 -t ' . $seconds . ' -i end.jpg';
            } elseif ($kind === 'motion') {
                $input = '-t ' . $seconds . ' -i motion.mp4';
            } elseif ($kind === 'host') {
                $offset = ((int) ($slot['offset_ms'] ?? 0)) / 1000;
                $input = ($offset > 0.05 ? '-ss ' . number_format($offset, 2, '.', '') . ' ' : '') . '-t ' . $seconds . ' -i host.mp4';
            } else {
                continue;
            }
            $part = sprintf('part-%02d.mp4', $i + 1);
            $parts[] = $part;
            $renders[] = 'ffmpeg -y ' . $input . ' -vf "' . ($windows ? '%VF%' : '$VF') . '"' . $encode . $part;
        }
        $final = 'ffmpeg -y -f concat -safe 0 -i parts.txt -i voice.mp3 -map 0:v -map 1:a -af apad -c:v libx264 -pix_fmt yuv420p -profile:v high -level 4.0 -c:a aac -b:a 128k -ar 44100 -shortest -movflags +faststart reel.mp4';
        if ($windows) {
            $out = "@echo off\r\n"
                . "REM Install ffmpeg first: https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip\r\n"
                . 'set "VF=' . $vf . '"' . "\r\n";
            foreach ($renders as $line) {
                $out .= $line . " || goto :fail\r\n";
            }
            foreach ($parts as $i => $part) {
                $out .= ($i === 0 ? '> ' : '>> ') . "parts.txt echo file '" . $part . "'\r\n";
            }
            return $out . $final . " || goto :fail\r\n"
                . "echo Created reel.mp4 — upload it on the Social queue, then Publish.\r\n"
                . "pause\r\n"
                . "exit /b 0\r\n"
                . ":fail\r\n"
                . "echo ffmpeg failed. Check the message above.\r\n"
                . "pause\r\n"
                . "exit /b 1\r\n";
        }
        return "#!/bin/bash\nset -euo pipefail\n"
            . "# brew install ffmpeg   OR   https://evermeet.cx/ffmpeg/getrelease/zip\n"
            . 'VF="' . $vf . '"' . "\n"
            . implode("\n", $renders) . "\n"
            . 'printf "file \'%s\'\\n" ' . implode(' ', $parts) . " > parts.txt\n"
            . $final . "\n"
            . "echo \"Created reel.mp4 — upload it on the Social queue, then Publish.\"\n";
    }

    private function store_social_reel_laptop_kit($post, $files, $duration, $copy, $slots = array()) {
        $files = is_array($files) ? $files : array();
        $slots = array_values((array) $slots);
        $usable = array();
        foreach ($files as $name => $path) {
            $name = sanitize_file_name((string) $name);
            if ($name === '' || !is_string($path) || $path === '' || !file_exists($path)) {
                continue;
            }
            $usable[$name] = $path;
        }
        if (empty($usable['voice.mp3']) && $this->social_voice_id($post->ID) > 0) {
            $voice_path = get_attached_file($this->social_voice_id($post->ID));
            if ($voice_path && file_exists($voice_path)) {
                $usable['voice.mp3'] = $voice_path;
            }
        }
        if (empty($usable['voice.mp3'])) {
            return new WP_Error('tr_ai_wire_reel_kit', 'The Reel voiceover was not stored for laptop download.');
        }
        // The kit renders the same cards-first cut; every file a slot needs must be packed.
        $has_card = false;
        foreach ($slots as $slot) {
            $kind = (string) ($slot['kind'] ?? '');
            $need = '';
            if ($kind === 'card') {
                $has_card = true;
                $need = 'card-' . (string) ((int) ($slot['card'] ?? 0) + 1) . '.jpg';
            } elseif ($kind === 'motion') {
                $need = 'motion.mp4';
            } elseif ($kind === 'host') {
                $need = 'host.mp4';
            } elseif ($kind === 'end') {
                $need = 'end.jpg';
            }
            if ($need !== '' && empty($usable[$need])) {
                return new WP_Error('tr_ai_wire_reel_kit', 'The laptop kit is missing ' . $need . ', so it was not packed.');
            }
        }
        if (!$has_card) {
            return new WP_Error('tr_ai_wire_reel_kit', 'The laptop kit needs lesson cards; it will not pack a looping B-roll script.');
        }
        update_post_meta($post->ID, '_tr_ai_wire_reel_audio_seconds', (string) max(8, (int) round((float) $duration)));
        if (!class_exists('ZipArchive')) {
            return new WP_Error('tr_ai_wire_reel_kit', 'PHP ZipArchive is not available to pack the laptop kit.');
        }
        $tmp = get_temp_dir() . 'tr-ai-wire-kit-' . wp_generate_password(8, false, false) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('tr_ai_wire_reel_kit', 'Could not create the laptop Reel zip.');
        }
        foreach ($usable as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->addFromString('README.txt', $this->social_reel_laptop_readme($duration, array_keys($usable)));
        $zip->addFromString('make-reel.sh', $this->social_reel_laptop_script($slots, false));
        $zip->addFromString('make-reel.bat', $this->social_reel_laptop_script($slots, true));
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

    /**
     * Uncapped duration for finished-Reel validation. social_video_duration()
     * ignores values over 90s (meant for short Fal source clips) — that would
     * let a multi-hour broken mux slip through as "0".
     */
    private function social_reel_output_seconds($path) {
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
                if ($seconds > 0) {
                    return $seconds;
                }
            }
        }
        return max(0.0, (float) $this->social_mp4_duration($path));
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
            'timeout' => $this->social_reel_is_roundup($settings) ? 180 : 60,
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
                if ($seconds >= 3 && $seconds <= 120) {
                    return max(3.0, min($max, $seconds));
                }
            }
        }
        $seconds = $this->social_mp3_duration($path);
        if ($seconds >= 3 && $seconds <= 120) {
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

    private function store_social_video($post, $bytes, $copy, $script, $settings = null, $cut = '') {
        if ($cut === '') {
            $cut = $this->social_reel_cut_name($settings);
        }
        $old = (int) get_post_meta($post->ID, '_tr_ai_wire_social_reel_id', true);
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
        update_post_meta($attachment_id, '_tr_ai_wire_reel_cut', sanitize_key((string) $cut));
        if ($old > 0 && $old !== (int) $attachment_id && get_post_meta($old, '_tr_ai_wire_social_asset', true) === '1') {
            wp_delete_attachment($old, true);
        }
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
        $exit = null;
        $deadline = time() + $timeout;
        do {
            $status = proc_get_status($process);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (empty($status['running'])) {
                // proc_get_status may already have reaped the child; remember that
                // exit code. proc_close() often returns -1 afterward and must not
                // be treated as a failed ffmpeg run (CI flake on PHP 8.2).
                if (array_key_exists('exitcode', $status) && $status['exitcode'] !== -1) {
                    $exit = (int) $status['exitcode'];
                }
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
        $closed = proc_close($process);
        if ($exit === null) {
            $exit = (int) $closed;
        }
        if ($exit !== 0) {
            $message = $this->social_ffmpeg_error_message($stderr, $stdout, $exit);
            return new WP_Error('tr_ai_wire_reel_ffmpeg', $message);
        }
        return $stdout;
    }

    /** Prefer the real ffmpeg failure line over the version banner on stderr. */
    private function social_ffmpeg_error_message($stderr, $stdout, $code) {
        $raw = trim((string) ($stderr !== '' ? $stderr : $stdout));
        $lines = preg_split('/\R/', $raw) ?: array();
        $useful = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (stripos($line, 'ffmpeg version') === 0 || stripos($line, 'built with') === 0 || stripos($line, 'configuration:') === 0) {
                continue;
            }
            if (stripos($line, 'libav') === 0 || stripos($line, 'copyright') !== false) {
                continue;
            }
            $useful[] = $line;
        }
        $message = $useful ? implode(' · ', array_slice($useful, -4)) : $raw;
        if ($message === '') {
            $message = 'ffmpeg exited ' . (string) (int) $code . '.';
        }
        return $this->clip_social_text($message, 240);
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
