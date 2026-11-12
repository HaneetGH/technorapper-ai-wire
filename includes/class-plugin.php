<?php
if (!defined('ABSPATH')) {
    exit;
}

final class TechnoRapper_AI_Wire {
    use TechnoRapper_AI_Wire_Schema;
    use TechnoRapper_AI_Wire_Settings;
    use TechnoRapper_AI_Wire_Tenants;
    use TechnoRapper_AI_Wire_Admin;
    use TechnoRapper_AI_Wire_Queue;
    use TechnoRapper_AI_Wire_Feeds;
    use TechnoRapper_AI_Wire_AI;
    use TechnoRapper_AI_Wire_Pipeline;
    use TechnoRapper_AI_Wire_SEO;
    use TechnoRapper_AI_Wire_Publisher;
    use TechnoRapper_AI_Wire_Scores;
    use TechnoRapper_AI_Wire_Social;
    use TechnoRapper_AI_Wire_Social_Reel;
    use TechnoRapper_AI_Wire_Social_YouTube;
    use TechnoRapper_AI_Wire_Jobs;

    const VERSION = '0.2.82';
    const API_TIMEOUT = 75;
    const DAILY_POST_CAP = 350;
    const LAST_RUN_OPTION = 'tr_ai_wire_last_run';
    const OPTION = 'tr_ai_wire_settings';
    const API_KEY_OPTION = 'tr_ai_wire_openai_api_key';
    const GEMINI_API_KEY_OPTION = 'tr_ai_wire_gemini_api_key';
    const FAL_API_KEY_OPTION = 'tr_ai_wire_fal_api_key';
    const SOCIAL_CREDENTIALS_OPTION = 'tr_ai_wire_social_credentials';
    const SOCIAL_CONTEXT_OPTION = 'tr_ai_wire_site_context';
    const SOCIAL_BATCH_OPTION = 'tr_ai_wire_social_batch';
    const SOCIAL_MIX_OPTION = 'tr_ai_wire_social_mix';
    const SOCIAL_IDEAS_OPTION = 'tr_ai_wire_social_ideas';
    const CRON_HOOK = 'tr_ai_wire_process_feeds';
    const SOCIAL_CRON_HOOK = 'tr_ai_wire_process_social_queue';
    const SOCIAL_CONTEXT_CRON_HOOK = 'tr_ai_wire_learn_site_context';
    const SOCIAL_IMAGE_MODEL = 'gpt-image-2';
    const SOCIAL_IMAGE_ESTIMATED_USD = 0.006;
    const SOCIAL_IMAGE_LAYOUT = 'poster-v2';
    const SOCIAL_REEL_LAYOUT = 'reel-v1';
    const SOCIAL_REEL_STORY_LAYOUT = 'reel-story-v1';
    const SOCIAL_TTS_MODEL = 'gpt-4o-mini-tts';
    const SOCIAL_TTS_VOICE = 'cedar';
    const SOCIAL_TTS_ESTIMATED_USD = 0.004;
    const SOCIAL_INSTAGRAM_STILL_SPACING = 10800;
    const SOCIAL_REEL_VIDEO_DEFAULT = 'veo-lite';
    const SOCIAL_REEL_FACE_ENHANCE_USD = 0.01;
    const SOCIAL_REEL_MUX_ENDPOINT = 'fal-ai/ffmpeg-api/compose';
    const INSTAGRAM_GRAPH_VERSION = 'v22.0';
    const SOCIAL_CONTEXT_TTL = 1209600;
    const TABLE_SUFFIX = 'tr_ai_wire_items';
    const API_URL = 'https://api.openai.com/v1/responses';
    const GEMINI_MODEL = 'gemini-2.5-flash';
    const GEMINI_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
    const SEARCH_CALL_USD = 0.01;
    const PROCESSING_LOCK_OPTION = 'tr_ai_wire_processing_lock';
    const JOB_OPTION = 'tr_ai_wire_background_job';
    const JOB_LOCK_OPTION = 'tr_ai_wire_background_job_lock';
    const JOB_HOOK = 'tr_ai_wire_background_job';
    const PROCESSING_LOCK_TTL = 2700;
    const SCHEMA_VERSION = '10';
    const VIEWS_TABLE_SUFFIX = 'tr_ai_wire_views';
    const QUARANTINE_TABLE_SUFFIX = 'tr_ai_wire_quarantine';

    private $table;

    public static function site_brand_name() {
        $name = trim(wp_strip_all_tags((string) get_bloginfo('name')));
        return $name !== '' ? $name : 'this site';
    }

    public static function site_brand_handle() {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', self::site_brand_name()));
        return $slug !== '' ? '@' . $slug : '';
    }

    public static function site_brand_mark() {
        $name = self::site_brand_name();
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($name, 'UTF-8');
        }
        return strtoupper($name);
    }

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_SUFFIX;
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('update_option_' . self::OPTION, array($this, 'settings_updated'), 10, 2);
        add_action('admin_post_tr_ai_wire_save_api_key', array($this, 'save_api_key'));
        add_action('init', array($this, 'maybe_upgrade_schema'), 5);
        add_action('admin_init', array($this, 'maybe_upgrade_schema'), 5);
        add_action('init', array($this, 'maybe_enable_auto_publish'), 6);
        add_action('init', array($this, 'maybe_enable_detailed_output'), 7);
        add_action('init', array($this, 'maybe_enable_daily_volume'), 8);
        add_action('init', array($this, 'maybe_raise_token_ceiling'), 9);
        add_action('admin_init', array($this, 'maybe_backfill_home_media'), 20);
        add_action('wp_head', array($this, 'print_article_schema'), 5);
        add_action('wp_head', array($this, 'track_article_view'), 20);
        add_action('rest_api_init', array($this, 'register_tracking_route'));
        add_action(self::CRON_HOOK, array($this, 'process_feeds'));
        add_action('transition_post_status', array($this, 'queue_social_for_post'), 10, 3);
        add_action(self::SOCIAL_CRON_HOOK, array($this, 'process_social_queue'));
        add_action(self::SOCIAL_CONTEXT_CRON_HOOK, array($this, 'process_site_context'));
        add_action('init', array($this, 'maybe_requeue_instagram_quota_field_failures'), 12);
        add_action('admin_post_tr_ai_wire_save_social_credentials', array($this, 'save_social_credentials'));
        add_action('admin_post_tr_ai_wire_retry_social_item', array($this, 'retry_social_item'));
        add_action('admin_post_tr_ai_wire_upload_reel', array($this, 'upload_social_reel'));
        add_action('admin_post_tr_ai_wire_download_reel', array($this, 'download_social_reel'));
        add_action('admin_post_tr_ai_wire_resume_reel', array($this, 'resume_social_reel'));
        add_action('admin_post_tr_ai_wire_regen_reel_script', array($this, 'regenerate_social_reel_script'));
        add_action('admin_post_tr_ai_wire_reel_dev_cheap', array($this, 'toggle_reel_dev_cheap'));
        add_action('wp_ajax_tr_ai_wire_reel_tick', array($this, 'ajax_reel_tick'));
        add_action('admin_post_tr_ai_wire_queue_missing_platform', array($this, 'queue_missing_platform_request'));
        add_action('admin_post_tr_ai_wire_refresh_site_context', array($this, 'refresh_site_context_request'));
        add_action('admin_post_tr_ai_wire_run_now', array($this, 'run_now'));
        add_action('admin_post_tr_ai_wire_run_social_queue', array($this, 'run_social_queue_now'));
        add_action('admin_post_tr_ai_wire_generate_social_pack', array($this, 'generate_social_pack_request'));
        add_action('admin_post_tr_ai_wire_retry_item', array($this, 'retry_item'));
        add_action('admin_post_tr_ai_wire_stop_run', array($this, 'stop_run'));
        add_action('admin_post_tr_ai_wire_delete_item', array($this, 'delete_item'));
        add_action('admin_post_tr_ai_wire_clean_queue', array($this, 'clean_queue_request'));
        add_action('admin_post_tr_ai_wire_repair_home', array($this, 'repair_home_request'));
        add_action('admin_post_tr_ai_wire_migrate_images', array($this, 'migrate_images_request'));
        add_action('wp_ajax_tr_ai_wire_start_job', array($this, 'ajax_start_job'));
        add_action('wp_ajax_tr_ai_wire_run_job', array($this, 'ajax_run_job'));
        add_action('wp_ajax_tr_ai_wire_job_status', array($this, 'ajax_job_status'));
        add_action(self::JOB_HOOK, array($this, 'run_background_job'));
        add_action('admin_footer', array($this, 'render_job_ui'));
        add_action('pre_get_posts', array($this, 'exclude_from_homepage'));
        add_filter('get_post_metadata', array($this, 'filter_thumbnail_id_meta'), 10, 4);
        add_filter('has_post_thumbnail', array($this, 'filter_has_post_thumbnail'), 10, 3);
        add_filter('post_thumbnail_html', array($this, 'filter_post_thumbnail_html'), 10, 5);
        add_filter('post_thumbnail_url', array($this, 'filter_post_thumbnail_url'), 10, 3);
        add_filter('image_downsize', array($this, 'filter_image_downsize'), 10, 3);
        add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 10, 2);
    }
}
