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
    use TechnoRapper_AI_Wire_SEO;
    use TechnoRapper_AI_Wire_Publisher;
    use TechnoRapper_AI_Wire_Scores;
    use TechnoRapper_AI_Wire_Social;

    const VERSION = '0.2.40';
    const API_TIMEOUT = 75;
    const DAILY_POST_CAP = 350;
    const LAST_RUN_OPTION = 'tr_ai_wire_last_run';
    const OPTION = 'tr_ai_wire_settings';
    const API_KEY_OPTION = 'tr_ai_wire_openai_api_key';
    const SOCIAL_CREDENTIALS_OPTION = 'tr_ai_wire_social_credentials';
    const SOCIAL_CONTEXT_OPTION = 'tr_ai_wire_site_context';
    const CRON_HOOK = 'tr_ai_wire_process_feeds';
    const SOCIAL_CRON_HOOK = 'tr_ai_wire_process_social_queue';
    const SOCIAL_CONTEXT_CRON_HOOK = 'tr_ai_wire_learn_site_context';
    const SOCIAL_IMAGE_MODEL = 'gpt-image-2';
    const SOCIAL_IMAGE_ESTIMATED_USD = 0.006;
    const SOCIAL_IMAGE_LAYOUT = 'poster-v2';
    const INSTAGRAM_GRAPH_VERSION = 'v22.0';
    const SOCIAL_CONTEXT_TTL = 1209600;
    const TABLE_SUFFIX = 'tr_ai_wire_items';
    const API_URL = 'https://api.openai.com/v1/responses';
    const SEARCH_CALL_USD = 0.01;
    const PROCESSING_LOCK_OPTION = 'tr_ai_wire_processing_lock';
    const PROCESSING_LOCK_TTL = 2700;
    const SCHEMA_VERSION = '8';
    const VIEWS_TABLE_SUFFIX = 'tr_ai_wire_views';

    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_SUFFIX;
        add_action('admin_menu', array($this, 'admin_menu'));
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
        add_action('wp_after_insert_post', array($this, 'queue_social_for_post'), 50, 4);
        add_action(self::SOCIAL_CRON_HOOK, array($this, 'process_social_queue'));
        add_action(self::SOCIAL_CONTEXT_CRON_HOOK, array($this, 'process_site_context'));
        add_action('init', array($this, 'maybe_requeue_instagram_quota_field_failures'), 12);
        add_action('admin_post_tr_ai_wire_save_social_credentials', array($this, 'save_social_credentials'));
        add_action('admin_post_tr_ai_wire_retry_social_item', array($this, 'retry_social_item'));
        add_action('admin_post_tr_ai_wire_refresh_site_context', array($this, 'refresh_site_context_request'));
        add_action('admin_post_tr_ai_wire_run_now', array($this, 'run_now'));
        add_action('admin_post_tr_ai_wire_run_social_queue', array($this, 'run_social_queue_now'));
        add_action('admin_post_tr_ai_wire_retry_item', array($this, 'retry_item'));
        add_action('admin_post_tr_ai_wire_stop_run', array($this, 'stop_run'));
        add_action('admin_post_tr_ai_wire_delete_item', array($this, 'delete_item'));
        add_action('admin_post_tr_ai_wire_clean_queue', array($this, 'clean_queue_request'));
        add_action('admin_post_tr_ai_wire_repair_home', array($this, 'repair_home_request'));
        add_action('pre_get_posts', array($this, 'exclude_from_homepage'));
        add_filter('get_post_metadata', array($this, 'filter_thumbnail_id_meta'), 10, 4);
        add_filter('has_post_thumbnail', array($this, 'filter_has_post_thumbnail'), 10, 3);
        add_filter('post_thumbnail_html', array($this, 'filter_post_thumbnail_html'), 10, 5);
        add_filter('post_thumbnail_url', array($this, 'filter_post_thumbnail_url'), 10, 3);
        add_filter('image_downsize', array($this, 'filter_image_downsize'), 10, 3);
        add_filter('wp_get_attachment_url', array($this, 'filter_attachment_url'), 10, 2);
    }
}
