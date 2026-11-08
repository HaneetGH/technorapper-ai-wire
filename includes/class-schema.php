<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Schema {
    public static function activate() {
        self::ensure_schema();
        if (!get_option(self::OPTION)) {
            add_option(self::OPTION, self::defaults());
        }
        self::schedule_cron();
        $settings = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        if (!empty($settings['social_distribution_enabled']) && self::social_credentials_connected()) {
            if (!wp_next_scheduled(self::SOCIAL_CRON_HOOK)) {
                wp_schedule_single_event(time() + 300, self::SOCIAL_CRON_HOOK);
            }
            if (!wp_next_scheduled(self::SOCIAL_CONTEXT_CRON_HOOK)) {
                wp_schedule_single_event(time() + 15, self::SOCIAL_CONTEXT_CRON_HOOK);
            }
        }
        self::enable_auto_publish_once();
        self::enable_detailed_output_once();
        self::enable_daily_volume_once();
    }

    /**
     * Create or upgrade plugin tables without touching cron events.
     * Schema upgrades must not clear retry/continue/manual single events.
     */
    public static function ensure_schema() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_key char(64) NOT NULL,
            experiment_id varchar(64) NOT NULL DEFAULT 'editorial-v1',
            variant varchar(8) NOT NULL DEFAULT 'A',
            feed_url text NOT NULL,
            feed_name varchar(190) NOT NULL DEFAULT '',
            source_guid text NOT NULL,
            source_url text NOT NULL,
            source_title text NOT NULL,
            source_body longtext NOT NULL,
            source_published_at datetime NULL,
            rewrite_json longtext NULL,
            review_json longtext NULL,
            status varchar(24) NOT NULL DEFAULT 'new',
            wp_post_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            error_text text NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            next_retry_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY source_key (source_key),
            KEY status_created (status, created_at)
        ) {$charset};";
        dbDelta($sql);
        $views_table = $wpdb->prefix . self::VIEWS_TABLE_SUFFIX;
        $views_sql = "CREATE TABLE {$views_table} (
            post_id bigint(20) unsigned NOT NULL,
            view_date date NOT NULL,
            views bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (post_id, view_date),
            KEY view_date (view_date)
        ) {$charset};";
        dbDelta($views_sql);
        $limits_table = $wpdb->prefix . 'tr_ai_wire_view_limits';
        $limits_sql = "CREATE TABLE {$limits_table} (
            rate_key char(64) NOT NULL,
            window_started datetime NOT NULL,
            requests int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (rate_key),
            KEY window_started (window_started)
        ) {$charset};";
        dbDelta($limits_sql);
        $social_table = $wpdb->prefix . 'tr_ai_wire_social';
        $social_sql = "CREATE TABLE {$social_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            platform varchar(16) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'queued',
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            image_attachment_id bigint(20) unsigned NULL,
            copy_json longtext NULL,
            remote_id varchar(128) NOT NULL DEFAULT '',
            remote_url text NULL,
            error_text text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_platform (post_id, platform),
            KEY status_created (status, created_at)
        ) {$charset};";
        dbDelta($social_sql);
        update_option('tr_ai_wire_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function deactivate() {
        // Clear every scheduled instance, not only the next occurrence.
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::SOCIAL_CRON_HOOK);
        wp_clear_scheduled_hook(self::SOCIAL_CONTEXT_CRON_HOOK);
    }

    public function maybe_upgrade_schema() {
        if (get_option('tr_ai_wire_schema_version') !== self::SCHEMA_VERSION) {
            self::ensure_schema();
        }
    }

    public function maybe_enable_auto_publish() {
        self::enable_auto_publish_once();
    }

    public static function enable_auto_publish_once() {
        if (get_option('tr_ai_wire_auto_publish_on') === '1') {
            return;
        }
        $opt = get_option(self::OPTION, array());
        if (!is_array($opt)) {
            $opt = array();
        }
        $opt['auto_publish'] = 1;
        update_option(self::OPTION, $opt, false);
        update_option('tr_ai_wire_auto_publish_on', '1', false);
    }

    public function maybe_enable_detailed_output() {
        self::enable_detailed_output_once();
    }

    public static function enable_detailed_output_once() {
        if (get_option('tr_ai_wire_detailed_output_on') === '1') {
            return;
        }
        $opt = get_option(self::OPTION, array());
        if (!is_array($opt)) {
            $opt = array();
        }
        if (absint($opt['max_output_tokens'] ?? 0) < 3000) {
            $opt['max_output_tokens'] = 4000;
            update_option(self::OPTION, $opt, false);
        }
        update_option('tr_ai_wire_detailed_output_on', '1', false);
    }

    public function maybe_enable_daily_volume() {
        self::enable_daily_volume_once();
    }

    public function maybe_raise_token_ceiling() {
        self::raise_token_ceiling_once();
    }

    public static function enable_daily_volume_once() {
        if (get_option('tr_ai_wire_daily_350_on') === '1') {
            return;
        }
        $opt = get_option(self::OPTION, array());
        if (!is_array($opt)) {
            $opt = array();
        }
        $changed = false;
        if (absint($opt['max_posts_per_day'] ?? 0) < self::DAILY_POST_CAP) {
            $opt['max_posts_per_day'] = self::DAILY_POST_CAP;
            $changed = true;
        }
        if (absint($opt['max_items_per_run'] ?? 0) < self::DAILY_POST_CAP) {
            $opt['max_items_per_run'] = self::DAILY_POST_CAP;
            $changed = true;
        }
        if ($changed) {
            update_option(self::OPTION, $opt, false);
        }
        update_option('tr_ai_wire_daily_350_on', '1', false);
    }

    public static function raise_token_ceiling_once() {
        if (get_option('tr_ai_wire_token_ceiling_on') === '1') {
            return;
        }
        $opt = get_option(self::OPTION, array());
        if (!is_array($opt)) {
            $opt = array();
        }
        $next = (int) self::defaults()['monthly_token_limit'];
        if (array_key_exists('monthly_token_limit', $opt)) {
            $limit = absint($opt['monthly_token_limit']);
            // The shipped 1,000,000 ceiling is a few dimes of GPT-6 Luna, so a
            // normal week of calls held the queue while provider credit remained.
            if ($limit > 0 && $limit <= 1000000 && $next > $limit) {
                $opt['monthly_token_limit'] = $next;
                update_option(self::OPTION, $opt, false);
            }
        }
        update_option('tr_ai_wire_token_ceiling_on', '1', false);
    }

    private static function schedule_cron() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $settings = wp_parse_args(get_option(self::OPTION, array()), self::defaults());
        $frequency = $settings['cron_frequency'];
        if ($frequency !== 'manual') {
            wp_schedule_event(time() + 300, $frequency, self::CRON_HOOK);
        }
    }
}
