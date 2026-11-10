<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Settings {
    public static function defaults() {
        return array(
            'feeds' => '',
            'cron_frequency' => 'manual',
            'max_posts_per_day' => 0,
            'max_items_per_run' => 10,
            'monthly_budget_usd' => 5,
            // 200M tokens is about $20–$100 of GPT-6 Luna. The old 1M default
            // is only $0.10–$0.50, so the queue held items while OpenAI credit remained.
            'monthly_token_limit' => 200000000,
            'max_output_tokens' => 4000,
            'max_source_chars' => 12000,
            'writer_model' => 'gpt-6-luna',
            'reviewer_model' => 'gpt-6-luna',
            'enable_web_search' => 0,
            'enable_experiment' => 1,
            'experiment_id' => 'editorial-v1',
            'draft_min_score' => 50,
            'draft_allow_revise' => 1,
            'draft_require_grounded' => 1,
            'draft_require_useful' => 0,
            'draft_require_new' => 0,
            'draft_max_similarity' => 'high',
            'draft_block_policy_flags' => 1,
            'draft_block_fact_flags' => 0,
            'auto_publish' => 1,
            'social_distribution_enabled' => 0,
            'social_x_enabled' => 0,
            'social_instagram_enabled' => 0,
            'social_daily_limit' => 10,
            'social_image_budget_usd' => 5,
            'social_video_budget_usd' => 10,
            'social_reel_video_model' => 'veo-lite',
            'social_reel_clip_seconds' => 8,
            'social_post_format' => 'single',
            'social_slide_count' => 5,
            'social_carousel_daily_limit' => 2,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 3,
            'social_reel_daily_limit' => 0,
            'social_mix_saved' => 0,
            'social_news_formats' => array_keys(self::social_news_formats()),
            'seo_min_score' => 50,
            'seo_max_readability_errors' => 3,
            'seo_require_keyphrase' => 1,
            'seo_require_social' => 1,
            'pin_to_homepage' => 1,
            'map_post_type' => 'post',
            'map_taxonomy' => 'category',
            'map_terms' => array(),
            'map_auto_desk' => 1,
            'map_post_status' => 'auto',
            'map_title' => 'rewrite',
            'map_date' => 'now',
            'map_content' => 'rewrite',
            'tenants' => array(),
        );
    }

    private function settings() {
        $s = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        if (empty($s['tenants']) || !is_array($s['tenants'])) {
            $s['tenants'] = $this->tenants_from_feed_lines($s['feeds'] ?? '', $s);
        }
        return self::apply_social_platform_toggles($s);
    }

    public static function social_setting_keys() {
        return array(
            'social_distribution_enabled',
            'social_x_enabled',
            'social_instagram_enabled',
            'social_daily_limit',
            'social_image_budget_usd',
            'social_video_budget_usd',
            'social_reel_video_model',
            'social_reel_clip_seconds',
            'social_post_format',
            'social_slide_count',
            'social_carousel_daily_limit',
            'social_collage_daily_limit',
            'social_single_daily_limit',
            'social_reel_daily_limit',
            'social_mix_saved',
            'social_news_formats',
        );
    }

    public static function apply_social_platform_toggles($settings) {
        $settings = is_array($settings) ? $settings : array();
        $x = empty($settings['social_x_enabled']) ? 0 : 1;
        $instagram = empty($settings['social_instagram_enabled']) ? 0 : 1;
        $master = empty($settings['social_distribution_enabled']) ? 0 : 1;
        if ($master && !$x && !$instagram) {
            $x = 1;
            $instagram = 1;
        }
        $settings['social_x_enabled'] = $x;
        $settings['social_instagram_enabled'] = $instagram;
        $settings['social_distribution_enabled'] = ($x || $instagram) ? 1 : 0;
        return $settings;
    }

    public function register_settings() {
        register_setting('tr_ai_wire', self::OPTION, array($this, 'sanitize_settings'));
    }

    public function cron_schedules($schedules) {
        $schedules['every_3_hours'] = array('interval' => 3 * HOUR_IN_SECONDS, 'display' => __('Every 3 hours', 'technorapper-ai-wire'));
        $schedules['every_6_hours'] = array('interval' => 6 * HOUR_IN_SECONDS, 'display' => __('Every 6 hours', 'technorapper-ai-wire'));
        $schedules['every_12_hours'] = array('interval' => 12 * HOUR_IN_SECONDS, 'display' => __('Every 12 hours', 'technorapper-ai-wire'));
        return $schedules;
    }

    public function settings_updated($old_value, $value) {
        $old_frequency = $old_value['cron_frequency'] ?? 'hourly';
        $new_frequency = $value['cron_frequency'] ?? 'hourly';
        if ($old_frequency !== $new_frequency) {
            self::schedule_cron();
        }
        $old = self::apply_social_platform_toggles(wp_parse_args(is_array($old_value) ? $old_value : array(), self::defaults()));
        $new = self::apply_social_platform_toggles(wp_parse_args(is_array($value) ? $value : array(), self::defaults()));
        $connected = self::social_credentials_connected();
        $now_on = !empty($new['social_distribution_enabled']);
        $was_on = !empty($old['social_distribution_enabled']);
        if ($now_on && $connected) {
            if (!$was_on) {
                $this->kick_social_queue(5, true);
                $this->queue_site_context_job(true);
            } else {
                $this->kick_social_queue(5);
            }
            if (empty($old['social_x_enabled']) && !empty($new['social_x_enabled'])) {
                $this->enqueue_missing_platform_posts('x');
            }
            if (empty($old['social_instagram_enabled']) && !empty($new['social_instagram_enabled'])) {
                $this->enqueue_missing_platform_posts('instagram');
            }
        }
    }

    public function sanitize_settings($input) {
        $defaults = self::defaults();
        $current = wp_parse_args(get_option(self::OPTION, array()), $defaults);
        $out = array();
        $lines = isset($input['feeds']) ? preg_split('/\r\n|\r|\n/', trim(wp_unslash($input['feeds']))) : array();
        $clean_lines = array();
        foreach ($lines as $line) {
            $line = trim(sanitize_text_field($line));
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $url = esc_url_raw($parts[0], array('http', 'https'));
            if (!$url || !wp_http_validate_url($url)) {
                continue;
            }
            $label = isset($parts[1]) ? sanitize_text_field($parts[1]) : '';
            $clean_lines[] = $url . ($label !== '' ? ' | ' . $label : '');
        }
        $out['feeds'] = implode("\n", array_unique($clean_lines));
        $frequencies = array('hourly', 'every_3_hours', 'every_6_hours', 'every_12_hours', 'daily', 'manual');
        $out['cron_frequency'] = in_array($input['cron_frequency'] ?? '', $frequencies, true) ? $input['cron_frequency'] : $defaults['cron_frequency'];
        $out['max_posts_per_day'] = min(2000, max(0, absint($input['max_posts_per_day'] ?? $defaults['max_posts_per_day'])));
        $out['max_items_per_run'] = min(self::DAILY_POST_CAP, max(1, absint($input['max_items_per_run'] ?? $defaults['max_items_per_run'])));
        $out['monthly_budget_usd'] = min(10000, max(0, (float) ($input['monthly_budget_usd'] ?? $defaults['monthly_budget_usd'])));
        $out['monthly_token_limit'] = min(1000000000, max(0, absint($input['monthly_token_limit'] ?? $defaults['monthly_token_limit'])));
        $out['max_output_tokens'] = min(8000, max(200, absint($input['max_output_tokens'] ?? $defaults['max_output_tokens'])));
        $out['max_source_chars'] = min(50000, max(1000, absint($input['max_source_chars'] ?? $defaults['max_source_chars'])));
        $models = array('gpt-6-luna', 'gpt-6.1-terra');
        $out['writer_model'] = in_array($input['writer_model'] ?? '', $models, true) ? $input['writer_model'] : $defaults['writer_model'];
        $out['reviewer_model'] = in_array($input['reviewer_model'] ?? '', $models, true) ? $input['reviewer_model'] : $defaults['reviewer_model'];
        $out['enable_web_search'] = empty($input['enable_web_search']) ? 0 : 1;
        $out['enable_experiment'] = empty($input['enable_experiment']) ? 0 : 1;
        $experiment_id = sanitize_key($input['experiment_id'] ?? $defaults['experiment_id']);
        $out['experiment_id'] = $experiment_id !== '' ? substr($experiment_id, 0, 64) : $defaults['experiment_id'];
        $out['draft_min_score'] = min(100, max(0, absint($input['draft_min_score'] ?? $defaults['draft_min_score'])));
        $out['draft_allow_revise'] = empty($input['draft_allow_revise']) ? 0 : 1;
        $out['draft_require_grounded'] = empty($input['draft_require_grounded']) ? 0 : 1;
        $out['draft_require_useful'] = empty($input['draft_require_useful']) ? 0 : 1;
        $out['draft_require_new'] = empty($input['draft_require_new']) ? 0 : 1;
        $similarities = array('low', 'medium', 'high');
        $out['draft_max_similarity'] = in_array($input['draft_max_similarity'] ?? '', $similarities, true) ? $input['draft_max_similarity'] : $defaults['draft_max_similarity'];
        $out['draft_block_policy_flags'] = empty($input['draft_block_policy_flags']) ? 0 : 1;
        $out['draft_block_fact_flags'] = empty($input['draft_block_fact_flags']) ? 0 : 1;
        $out['auto_publish'] = empty($input['auto_publish']) ? 0 : 1;
        $out = array_merge($out, $this->sanitize_social_settings_from_input($input, $current, $defaults));
        $out['seo_min_score'] = min(100, max(0, absint($input['seo_min_score'] ?? $defaults['seo_min_score'])));
        $out['seo_max_readability_errors'] = min(20, max(0, absint($input['seo_max_readability_errors'] ?? $defaults['seo_max_readability_errors'])));
        $out['seo_require_keyphrase'] = empty($input['seo_require_keyphrase']) ? 0 : 1;
        $out['seo_require_social'] = empty($input['seo_require_social']) ? 0 : 1;
        $out['pin_to_homepage'] = empty($input['pin_to_homepage']) ? 0 : 1;
        $types = $this->mappable_post_types();
        $type = sanitize_key($input['map_post_type'] ?? $defaults['map_post_type']);
        $out['map_post_type'] = isset($types[$type]) ? $type : $defaults['map_post_type'];
        $taxes = $this->mappable_taxonomies();
        $taxonomy = sanitize_key($input['map_taxonomy'] ?? $defaults['map_taxonomy']);
        $out['map_taxonomy'] = isset($taxes[$taxonomy]) ? $taxonomy : $defaults['map_taxonomy'];
        $terms = array();
        foreach ((array) ($input['map_terms'] ?? array()) as $term_id) {
            $term_id = absint($term_id);
            if ($term_id > 0 && term_exists($term_id, $out['map_taxonomy'])) {
                $terms[] = $term_id;
            }
        }
        $out['map_terms'] = array_values(array_unique($terms));
        $out['map_auto_desk'] = empty($input['map_auto_desk']) ? 0 : 1;
        $statuses = array('auto', 'publish', 'draft', 'pending');
        $out['map_post_status'] = in_array($input['map_post_status'] ?? '', $statuses, true) ? $input['map_post_status'] : $defaults['map_post_status'];
        $out['map_title'] = (($input['map_title'] ?? '') === 'source') ? 'source' : 'rewrite';
        $out['map_date'] = (($input['map_date'] ?? '') === 'source') ? 'source' : 'now';
        $out['map_content'] = (($input['map_content'] ?? '') === 'source') ? 'source' : 'rewrite';
        if (isset($input['tenants']) || isset($input['new_tenant'])) {
            $out['tenants'] = $this->sanitize_tenants($input['tenants'] ?? array(), $input['new_tenant'] ?? array());
            $out['feeds'] = $this->feeds_from_tenants($out['tenants']);
        } elseif (array_key_exists('feeds', $input)) {
            $out['tenants'] = $this->tenants_from_feed_lines($out['feeds'], $out);
            $out['feeds'] = $this->feeds_from_tenants($out['tenants']);
        } else {
            $out['tenants'] = !empty($current['tenants']) && is_array($current['tenants']) ? $current['tenants'] : $this->tenants_from_feed_lines($current['feeds'] ?? '', $out);
            $out['feeds'] = $this->feeds_from_tenants($out['tenants']);
        }
        return $out;
    }

    private function sanitize_social_settings_from_input($input, $current, $defaults) {
        $input = is_array($input) ? $input : array();
        $has_social = false;
        foreach (self::social_setting_keys() as $key) {
            if (array_key_exists($key, $input) || !empty($input['social_platform_toggles'])) {
                $has_social = true;
                break;
            }
        }
        if (!$has_social) {
            $out = array();
            foreach (self::social_setting_keys() as $key) {
                $out[$key] = $current[$key] ?? $defaults[$key];
            }
            return self::apply_social_platform_toggles($out);
        }
        $explicit_toggles = !empty($input['social_platform_toggles'])
            || array_key_exists('social_x_enabled', $input)
            || array_key_exists('social_instagram_enabled', $input);
        $out = array();
        $out['social_x_enabled'] = empty($input['social_x_enabled']) ? 0 : 1;
        $out['social_instagram_enabled'] = empty($input['social_instagram_enabled']) ? 0 : 1;
        if ($explicit_toggles) {
            $out['social_distribution_enabled'] = ($out['social_x_enabled'] || $out['social_instagram_enabled']) ? 1 : 0;
        } else {
            $out['social_distribution_enabled'] = empty($input['social_distribution_enabled']) ? 0 : 1;
        }
        $out['social_daily_limit'] = min(20, max(1, absint($input['social_daily_limit'] ?? $defaults['social_daily_limit'])));
        $out['social_image_budget_usd'] = min(10000, max(0, (float) ($input['social_image_budget_usd'] ?? $defaults['social_image_budget_usd'])));
        $out['social_video_budget_usd'] = min(10000, max(0, (float) ($input['social_video_budget_usd'] ?? $defaults['social_video_budget_usd'])));
        $video_models = array_keys($this->social_reel_video_models());
        $video_model = sanitize_key((string) ($input['social_reel_video_model'] ?? $defaults['social_reel_video_model']));
        $out['social_reel_video_model'] = in_array($video_model, $video_models, true) ? $video_model : 'veo-lite';
        $clip = absint($input['social_reel_clip_seconds'] ?? $defaults['social_reel_clip_seconds']);
        $out['social_reel_clip_seconds'] = in_array($clip, array(4, 5, 6, 8, 10, 15), true) ? $clip : 8;
        $formats = array('single', 'carousel', 'collage', 'reel');
        $format = sanitize_key((string) ($input['social_post_format'] ?? $defaults['social_post_format']));
        $out['social_post_format'] = in_array($format, $formats, true) ? $format : 'single';
        $pack = absint($input['social_slide_count'] ?? $defaults['social_slide_count']);
        $out['social_slide_count'] = in_array($pack, array(4, 5, 6), true) ? $pack : 5;
        $out['social_carousel_daily_limit'] = min(10, max(0, absint($input['social_carousel_daily_limit'] ?? $defaults['social_carousel_daily_limit'])));
        $out['social_collage_daily_limit'] = min(10, max(0, absint($input['social_collage_daily_limit'] ?? $defaults['social_collage_daily_limit'])));
        $out['social_single_daily_limit'] = min(10, max(0, absint($input['social_single_daily_limit'] ?? $defaults['social_single_daily_limit'])));
        $out['social_reel_daily_limit'] = min(10, max(0, absint($input['social_reel_daily_limit'] ?? $defaults['social_reel_daily_limit'])));
        $out['social_mix_saved'] = empty($input['social_mix_saved']) ? 0 : 1;
        if ($out['social_mix_saved']) {
            if ($out['social_reel_daily_limit'] > 0 && $out['social_carousel_daily_limit'] < 1 && $out['social_collage_daily_limit'] < 1 && $out['social_single_daily_limit'] < 1) {
                $out['social_post_format'] = 'reel';
            } elseif ($out['social_carousel_daily_limit'] > 0) {
                $out['social_post_format'] = 'carousel';
            } elseif ($out['social_collage_daily_limit'] > 0) {
                $out['social_post_format'] = 'collage';
            } else {
                $out['social_post_format'] = 'single';
            }
        }
        $out['social_news_formats'] = self::sanitize_social_news_format_ids($input['social_news_formats'] ?? array());
        return self::apply_social_platform_toggles($out);
    }

    /**
     * Built-in news graphic formats. Labels describe the layout, not a specific story.
     * Do not add example headlines, brands, or accounts from sample posts.
     */
    public static function social_news_formats() {
        return array(
            'editorial_poster' => array(
                'label' => 'Editorial news poster',
                'group' => 'Single story',
                'role' => 'story',
                'family' => 'photo',
                'description' => 'Photo-led 4:5 poster with a large headline and a supporting line. Best for company, policy, and location news.',
            ),
            'photo_brief' => array(
                'label' => 'Photo news brief',
                'group' => 'Single story',
                'role' => 'story',
                'family' => 'photo',
                'description' => 'Photograph with a short title and three highlighted bullet points. Best for launches, programs, and feature rundowns.',
            ),
            'breaking_kicker' => array(
                'label' => 'Breaking kicker poster',
                'group' => 'Single story',
                'role' => 'story',
                'family' => 'photo',
                'description' => 'Photo with a colored kicker bar above a stacked headline. Best for urgent company or market news.',
            ),
            'rumor_card' => array(
                'label' => 'Rumor and product card',
                'group' => 'Single story',
                'role' => 'story',
                'family' => 'photo',
                'description' => 'Light card with clustered product imagery, a short kicker, and a headline. Use when the article is a rumor or leak.',
            ),
            'device_mockup' => array(
                'label' => 'Device mockup news',
                'group' => 'Single story',
                'role' => 'story',
                'family' => 'photo',
                'description' => 'Clean product photography of a phone, wearable, or laptop with the news headline. Best for hardware and app-interface stories.',
            ),
            'weekly_recap' => array(
                'label' => 'Weekly recap cover',
                'group' => 'Cover / roundup',
                'role' => 'cover',
                'family' => 'title_card',
                'description' => 'Bold stacked title card on a gradient. Used as the first slide of a weekly roundup carousel, with a swipe cue so people keep going.',
            ),
            'numbered_roundup' => array(
                'label' => 'Numbered list cover',
                'group' => 'Cover / roundup',
                'role' => 'cover',
                'family' => 'title_card',
                'description' => 'Category chip plus a large “N shifts this week” title. Used for themed recap covers.',
            ),
            'product_roundup' => array(
                'label' => 'Product roundup cover',
                'group' => 'Cover / roundup',
                'role' => 'cover',
                'family' => 'photo',
                'description' => 'Dark scene with floating devices and a “N big stories” title. Used for gadget and launch roundups.',
            ),
        );
    }

    public static function sanitize_social_news_format_ids($ids) {
        $allowed = array_keys(self::social_news_formats());
        $selected = array();
        foreach ((array) $ids as $id) {
            $id = sanitize_key((string) $id);
            if ($id !== '' && in_array($id, $allowed, true) && !in_array($id, $selected, true)) {
                $selected[] = $id;
            }
        }
        if (!$selected) {
            $selected[] = 'editorial_poster';
        }
        return $selected;
    }

    private function api_key() {
        $dashboard_key = get_option(self::API_KEY_OPTION, '');
        if (is_string($dashboard_key) && $dashboard_key !== '') {
            return $dashboard_key;
        }
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) {
            return OPENAI_API_KEY;
        }
        if (defined('TR_AI_WIRE_OPENAI_API_KEY') && TR_AI_WIRE_OPENAI_API_KEY) {
            return TR_AI_WIRE_OPENAI_API_KEY;
        }
        return '';
    }

    private function fal_api_key() {
        $dashboard_key = get_option(self::FAL_API_KEY_OPTION, '');
        if (is_string($dashboard_key) && $dashboard_key !== '') {
            return $dashboard_key;
        }
        if (defined('TR_AI_WIRE_FAL_API_KEY') && TR_AI_WIRE_FAL_API_KEY) {
            return TR_AI_WIRE_FAL_API_KEY;
        }
        if (defined('FAL_KEY') && FAL_KEY) {
            return FAL_KEY;
        }
        return '';
    }

    public function save_api_key() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage API credentials.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_save_api_key');
        $remove = !empty($_POST['remove_api_key']);
        $submitted = isset($_POST['api_key']) ? trim((string) wp_unslash($_POST['api_key'])) : '';
        if ($remove) {
            delete_option(self::API_KEY_OPTION);
        } elseif ($submitted !== '') {
            if (strlen($submitted) > 512 || preg_match('/\\s/', $submitted)) {
                wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire&api_key_error=1'));
                exit;
            }
            if (get_option(self::API_KEY_OPTION, null) === null) {
                add_option(self::API_KEY_OPTION, $submitted, '', false);
            } else {
                update_option(self::API_KEY_OPTION, $submitted, false);
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire&api_key_saved=' . ($remove ? 'removed' : '1')));
        exit;
    }
}
