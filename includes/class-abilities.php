<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Abilities {
    public function register_ability_category() {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category(self::ABILITY_CATEGORY, array(
            'label' => __('TechnoRapper AI Wire', 'technorapper-ai-wire'),
            'description' => __('Abilities for inspecting and operating AI Wire feed tenants, the rewrite queue, runs, usage, and scores.', 'technorapper-ai-wire'),
        ));
    }

    public function register_abilities() {
        if (!function_exists('wp_register_ability') || !class_exists('WP_Ability')) {
            return;
        }
        foreach ($this->ability_catalog() as $slug => $ability) {
            $name = self::ABILITY_CATEGORY . '/' . $slug;
            if (function_exists('wp_has_ability') && wp_has_ability($name)) {
                continue;
            }
            $args = array(
                'label' => $ability['label'],
                'description' => $ability['description'],
                'category' => self::ABILITY_CATEGORY,
                'output_schema' => $ability['output_schema'],
                'execute_callback' => array($this, $ability['callback']),
                'permission_callback' => array($this, 'ability_permission'),
                'meta' => array(
                    'show_in_rest' => true,
                    'annotations' => array(
                        'readonly' => !empty($ability['readonly']),
                        'destructive' => !empty($ability['destructive']),
                        'idempotent' => !empty($ability['idempotent']),
                        'instructions' => isset($ability['instructions']) ? $ability['instructions'] : '',
                    ),
                ),
            );
            if (!empty($ability['input_schema'])) {
                $args['input_schema'] = $ability['input_schema'];
            }
            wp_register_ability($name, $args);
        }
    }

    public function ability_permission($input = null) {
        unset($input);
        if (current_user_can('manage_options')) {
            return true;
        }
        return new WP_Error(
            'tr_ai_wire_forbidden',
            __('You do not have permission to use AI Wire from an AI agent.', 'technorapper-ai-wire'),
            array('status' => 403)
        );
    }

    public function ability_list_tenants($input = null) {
        unset($input);
        $tenants = array();
        foreach ((array) ($this->settings()['tenants'] ?? array()) as $tenant) {
            $tenants[] = $this->ability_present_tenant($tenant);
        }
        return array(
            'count' => count($tenants),
            'tenants' => $tenants,
        );
    }

    public function ability_get_tenant($input = null) {
        $tenant = $this->ability_find_tenant($input);
        if (is_wp_error($tenant)) {
            return $tenant;
        }
        return array('tenant' => $this->ability_present_tenant($tenant));
    }

    public function ability_create_tenant($input = null) {
        $input = is_array($input) ? $input : array();
        $built = $this->ability_build_tenant($input, $this->tenant_defaults());
        if (is_wp_error($built)) {
            return $built;
        }
        $settings = $this->settings();
        foreach ((array) ($settings['tenants'] ?? array()) as $existing) {
            if (!empty($existing['url']) && $existing['url'] === $built['url']) {
                return new WP_Error('tr_ai_wire_duplicate_tenant', 'A tenant with this feed URL already exists.', array('status' => 409));
            }
        }
        $tenants = array_values((array) ($settings['tenants'] ?? array()));
        $tenants[] = $built;
        $saved = $this->persist_tenants($tenants);
        $created = $this->tenant_by_id($built['id'], $saved);
        return array(
            'created' => true,
            'tenant' => $this->ability_present_tenant($created ? $created : $built),
        );
    }

    public function ability_update_tenant($input = null) {
        $input = is_array($input) ? $input : array();
        $current = $this->ability_find_tenant($input);
        if (is_wp_error($current)) {
            return $current;
        }
        $built = $this->ability_build_tenant($input, $current, true);
        if (is_wp_error($built)) {
            return $built;
        }
        $settings = $this->settings();
        $tenants = array();
        foreach ((array) ($settings['tenants'] ?? array()) as $tenant) {
            if (($tenant['id'] ?? '') === $current['id'] || ($tenant['url'] ?? '') === $current['url']) {
                $tenants[] = $built;
            } else {
                $tenants[] = $tenant;
            }
        }
        $saved = $this->persist_tenants($tenants);
        $updated = $this->tenant_by_id($built['id'], $saved);
        return array(
            'updated' => true,
            'tenant' => $this->ability_present_tenant($updated ? $updated : $built),
        );
    }

    public function ability_delete_tenant($input = null) {
        $current = $this->ability_find_tenant($input);
        if (is_wp_error($current)) {
            return $current;
        }
        $remaining = array();
        foreach ((array) ($this->settings()['tenants'] ?? array()) as $tenant) {
            if (($tenant['id'] ?? '') === $current['id'] || ($tenant['url'] ?? '') === $current['url']) {
                continue;
            }
            $remaining[] = $tenant;
        }
        $this->persist_tenants($remaining);
        return array(
            'deleted' => true,
            'id' => (string) $current['id'],
            'url' => (string) $current['url'],
        );
    }

    public function ability_list_queue_items($input = null) {
        global $wpdb;
        $input = is_array($input) ? $input : array();
        $page = max(1, absint($input['page'] ?? 1));
        $per_page = min(50, max(1, absint($input['per_page'] ?? 20)));
        $status = sanitize_key($input['status'] ?? '');
        $allowed = array('new', 'waiting_budget', 'retry_wait', 'rewritten', 'reviewed', 'draft', 'published', 'quarantined', 'error');
        $clauses = array('1=1');
        $args = array();
        if ($status !== '' && in_array($status, $allowed, true)) {
            $clauses[] = 'status = %s';
            $args[] = $status;
        }
        $tenant = null;
        if (!empty($input['tenant_id']) || !empty($input['feed_url'])) {
            $tenant = $this->ability_find_tenant($input);
            if (is_wp_error($tenant)) {
                return $tenant;
            }
            $clauses[] = 'feed_url = %s';
            $args[] = $tenant['url'];
        }
        $where = implode(' AND ', $clauses);
        $count_sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}";
        $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_sql, $args)) : $wpdb->get_var($count_sql));
        $offset = ($page - 1) * $per_page;
        $list_sql = "SELECT id, feed_name, feed_url, source_title, source_url, status, wp_post_id, created_at, updated_at, error_text, review_json, rewrite_json FROM {$this->table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $list_args = array_merge($args, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_args), ARRAY_A);
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = $this->ability_present_queue_item($row, false);
        }
        return array(
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'items' => $items,
        );
    }

    public function ability_get_queue_item($input = null) {
        $input = is_array($input) ? $input : array();
        $id = absint($input['id'] ?? 0);
        if ($id <= 0) {
            return new WP_Error('tr_ai_wire_invalid_id', 'A queue item id is required.', array('status' => 400));
        }
        $item = $this->get_item($id);
        if (!$item) {
            return new WP_Error('tr_ai_wire_not_found', 'Queue item not found.', array('status' => 404));
        }
        return array('item' => $this->ability_present_queue_item($item, true));
    }

    public function ability_get_run_status($input = null) {
        unset($input);
        $last = get_option(self::LAST_RUN_OPTION, array());
        if (!is_array($last)) {
            $last = array();
        }
        $at = absint($last['at'] ?? 0);
        return array(
            'run_in_progress' => $this->is_run_in_progress(),
            'api_key_configured' => (bool) $this->api_key(),
            'remaining_posts_today' => $this->remaining_posts_today($this->settings()),
            'last_run' => array(
                'at' => $at,
                'at_utc' => $at ? gmdate('Y-m-d H:i:s', $at) : '',
                'reason' => sanitize_key($last['reason'] ?? ''),
                'feeds_checked' => absint($last['feeds_checked'] ?? 0),
                'new_items' => absint($last['new_items'] ?? 0),
                'processed' => absint($last['processed'] ?? 0),
                'drafts' => absint($last['drafts'] ?? 0),
                'published' => absint($last['published'] ?? 0),
                'quarantined' => absint($last['quarantined'] ?? 0),
                'skipped' => sanitize_text_field($last['skipped'] ?? ''),
                'feed_errors' => array_values(array_map('sanitize_text_field', (array) ($last['feed_errors'] ?? array()))),
            ),
        );
    }

    public function ability_get_usage($input = null) {
        unset($input);
        $s = $this->settings();
        $usage = $this->usage();
        return array(
            'month' => gmdate('Ym'),
            'cost_usd' => round((float) $usage['cost_usd'], 4),
            'monthly_budget_usd' => (float) $s['monthly_budget_usd'],
            'input_tokens' => (int) $usage['input_tokens'],
            'output_tokens' => (int) $usage['output_tokens'],
            'token_total' => (int) $usage['input_tokens'] + (int) $usage['output_tokens'],
            'monthly_token_limit' => (int) $s['monthly_token_limit'],
            'search_calls' => (int) $usage['search_calls'],
            'max_posts_per_day' => (int) $s['max_posts_per_day'],
            'remaining_posts_today' => $this->remaining_posts_today($s),
            'api_key_configured' => (bool) $this->api_key(),
        );
    }

    public function ability_get_settings($input = null) {
        unset($input);
        $s = $this->settings();
        return array(
            'cron_frequency' => (string) $s['cron_frequency'],
            'max_posts_per_day' => (int) $s['max_posts_per_day'],
            'max_items_per_run' => (int) $s['max_items_per_run'],
            'monthly_budget_usd' => (float) $s['monthly_budget_usd'],
            'monthly_token_limit' => (int) $s['monthly_token_limit'],
            'writer_model' => (string) $s['writer_model'],
            'reviewer_model' => (string) $s['reviewer_model'],
            'auto_publish' => !empty($s['auto_publish']),
            'pin_to_homepage' => !empty($s['pin_to_homepage']),
            'enable_experiment' => !empty($s['enable_experiment']),
            'experiment_id' => (string) $s['experiment_id'],
            'draft_min_score' => (int) $s['draft_min_score'],
            'seo_min_score' => (int) $s['seo_min_score'],
            'tenant_count' => count((array) ($s['tenants'] ?? array())),
            'api_key_configured' => (bool) $this->api_key(),
        );
    }

    public function ability_get_scores($input = null) {
        $input = is_array($input) ? $input : array();
        $report = $this->score_report($input);
        $recent = array();
        foreach (array_slice((array) ($report['recent'] ?? array()), 0, 10) as $item) {
            $recent[] = array(
                'title' => (string) ($item['title'] ?? ''),
                'status' => (string) ($item['status'] ?? ''),
                'created_at' => (string) ($item['created_at'] ?? ''),
                'score' => isset($item['score']) ? (int) $item['score'] : null,
                'seo_score' => isset($item['seo_score']) ? (int) $item['seo_score'] : null,
            );
        }
        return array(
            'range' => (string) ($report['filters']['range'] ?? '30d'),
            'scope' => (string) ($report['filters']['scope'] ?? 'posts'),
            'min_score' => (int) ($report['min_score'] ?? 0),
            'count' => (int) ($report['count'] ?? 0),
            'average' => (float) ($report['average'] ?? 0),
            'seo_average' => $report['seo_average'] === null ? null : (float) $report['seo_average'],
            'above_min_rate' => (float) ($report['above_min_rate'] ?? 0),
            'distribution' => (array) ($report['distribution'] ?? array()),
            'recent' => $recent,
        );
    }

    public function ability_get_feed_health($input = null) {
        global $wpdb;
        unset($input);
        $last = get_option(self::LAST_RUN_OPTION, array());
        $feed_errors = array_values(array_map('sanitize_text_field', (array) ($last['feed_errors'] ?? array())));
        $tenants = array();
        foreach ((array) ($this->settings()['tenants'] ?? array()) as $tenant) {
            $url = (string) ($tenant['url'] ?? '');
            $counts = $wpdb->get_results($wpdb->prepare(
                "SELECT status, COUNT(*) AS total FROM {$this->table} WHERE feed_url = %s GROUP BY status",
                $url
            ), ARRAY_A);
            $by_status = array();
            $total = 0;
            foreach ((array) $counts as $row) {
                $by_status[(string) $row['status']] = (int) $row['total'];
                $total += (int) $row['total'];
            }
            $latest = $wpdb->get_row($wpdb->prepare(
                "SELECT created_at, status, error_text FROM {$this->table} WHERE feed_url = %s ORDER BY id DESC LIMIT 1",
                $url
            ), ARRAY_A);
            $error_hint = '';
            foreach ($feed_errors as $message) {
                if ($url !== '' && strpos($message, $url) !== false) {
                    $error_hint = $message;
                    break;
                }
            }
            $tenants[] = array(
                'id' => (string) ($tenant['id'] ?? ''),
                'name' => (string) ($tenant['name'] ?? ''),
                'url' => $url,
                'enabled' => !empty($tenant['enabled']),
                'queue_total' => $total,
                'queue_by_status' => $by_status,
                'latest_status' => (string) ($latest['status'] ?? ''),
                'latest_at' => (string) ($latest['created_at'] ?? ''),
                'latest_error' => sanitize_text_field($latest['error_text'] ?? ''),
                'feed_error' => $error_hint,
            );
        }
        return array(
            'run_in_progress' => $this->is_run_in_progress(),
            'last_run_skipped' => sanitize_text_field($last['skipped'] ?? ''),
            'tenants' => $tenants,
        );
    }

    public function ability_run_now($input = null) {
        unset($input);
        if ($this->is_run_in_progress()) {
            return array(
                'queued' => false,
                'run_in_progress' => true,
                'message' => 'A run is already in progress. Wait for it to finish or stop it first.',
            );
        }
        if (!$this->api_key()) {
            return new WP_Error('tr_ai_wire_no_api_key', 'No OpenAI API key is configured.', array('status' => 400));
        }
        $token = wp_generate_uuid4();
        $scheduled = wp_schedule_single_event(time() + 1, self::CRON_HOOK, array('mcp', $token));
        if ($scheduled === false) {
            return new WP_Error('tr_ai_wire_schedule_failed', 'Could not queue a processing run.', array('status' => 500));
        }
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
        return array(
            'queued' => true,
            'run_in_progress' => $this->is_run_in_progress(),
            'run_token' => $token,
            'message' => 'A processing run was queued. Ask for run status in a moment.',
        );
    }

    public function ability_stop_run($input = null) {
        unset($input);
        $this->stop_running_jobs();
        return array(
            'stopped' => true,
            'run_in_progress' => $this->is_run_in_progress(),
            'message' => 'The current run lock was released. The item already in flight may still finish.',
        );
    }

    public function ability_retry_item($input = null) {
        $input = is_array($input) ? $input : array();
        return $this->retry_queue_item(absint($input['id'] ?? 0));
    }

    public function ability_delete_queue_item($input = null) {
        $input = is_array($input) ? $input : array();
        $id = absint($input['id'] ?? 0);
        if ($id <= 0) {
            return new WP_Error('tr_ai_wire_invalid_id', 'A queue item id is required.', array('status' => 400));
        }
        $item = $this->get_item($id);
        if (!$item) {
            return new WP_Error('tr_ai_wire_not_found', 'Queue item not found.', array('status' => 404));
        }
        $this->delete_queue_item($id);
        return array(
            'deleted' => true,
            'id' => $id,
            'wordpress_post_kept' => !empty($item['wp_post_id']),
        );
    }

    public function mcp_endpoint_url() {
        return rest_url('easy-mcp-ai/v1/mcp');
    }

    public function easy_mcp_is_active() {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ($this->easy_mcp_plugin_files() as $file) {
            if (function_exists('is_plugin_active') && is_plugin_active($file)) {
                return true;
            }
        }
        return false;
    }

    private function ability_catalog() {
        $tenant = $this->ability_object_schema();
        $queue_item = $this->ability_queue_item_schema();
        return array(
            'list-tenants' => array(
                'label' => __('List feed tenants', 'technorapper-ai-wire'),
                'description' => __('List AI Wire feed tenants (RSS/Atom sources), including whether each is enabled and how it maps to WordPress.', 'technorapper-ai-wire'),
                'readonly' => true,
                'idempotent' => true,
                'callback' => 'ability_list_tenants',
                'input_schema' => array(),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'count' => array('type' => 'integer'),
                        'tenants' => array('type' => 'array', 'items' => $tenant),
                    ),
                    'additionalProperties' => true,
                ),
            ),
            'get-tenant' => array(
                'label' => __('Get feed tenant', 'technorapper-ai-wire'),
                'description' => __('Fetch one AI Wire tenant by id, name, or feed URL.', 'technorapper-ai-wire'),
                'readonly' => true,
                'idempotent' => true,
                'callback' => 'ability_get_tenant',
                'input_schema' => $this->ability_tenant_lookup_schema(),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array('tenant' => $tenant),
                    'additionalProperties' => true,
                ),
            ),
            'create-tenant' => array(
                'label' => __('Create feed tenant', 'technorapper-ai-wire'),
                'description' => __('Onboard a new RSS/Atom feed as an AI Wire tenant. Optional category/terms and post status (draft, publish, auto) control how rewritten items are stored.', 'technorapper-ai-wire'),
                'callback' => 'ability_create_tenant',
                'input_schema' => $this->ability_tenant_write_schema(true),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'created' => array('type' => 'boolean'),
                        'tenant' => $tenant,
                    ),
                    'additionalProperties' => true,
                ),
            ),
            'update-tenant' => array(
                'label' => __('Update feed tenant', 'technorapper-ai-wire'),
                'description' => __('Change a tenant name, enabled flag, mapping, or pause/resume fetching. Identify the tenant with id, name, or feed URL.', 'technorapper-ai-wire'),
                'idempotent' => true,
                'callback' => 'ability_update_tenant',
                'input_schema' => $this->ability_tenant_write_schema(false),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'updated' => array('type' => 'boolean'),
                        'tenant' => $tenant,
                    ),
                    'additionalProperties' => true,
                ),
            ),
            'delete-tenant' => array(
                'label' => __('Delete feed tenant', 'technorapper-ai-wire'),
                'description' => __('Remove an AI Wire feed tenant. Existing queue rows and WordPress posts are kept.', 'technorapper-ai-wire'),
                'destructive' => true,
                'callback' => 'ability_delete_tenant',
                'input_schema' => $this->ability_tenant_lookup_schema(),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'deleted' => array('type' => 'boolean'),
                        'id' => array('type' => 'string'),
                        'url' => array('type' => 'string'),
                    ),
                    'additionalProperties' => true,
                ),
            ),
            'list-queue-items' => array(
                'label' => __('List queue items', 'technorapper-ai-wire'),
                'description' => __('List AI Wire queue items with optional status and tenant filters. Use this to find errors, quarantined items, or imports that stopped producing posts.', 'technorapper-ai-wire'),
                'readonly' => true,
                'idempotent' => true,
                'callback' => 'ability_list_queue_items',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'status' => array('type' => 'string', 'description' => 'Queue status filter such as error, quarantined, published, or draft.'),
                        'tenant_id' => array('type' => 'string'),
                        'feed_url' => array('type' => 'string'),
                        'page' => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
                        'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20),
                    ),
                    'additionalProperties' => true,
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'total' => array('type' => 'integer'),
                        'page' => array('type' => 'integer'),
                        'per_page' => array('type' => 'integer'),
                        'items' => array('type' => 'array', 'items' => $queue_item),
                    ),
                    'additionalProperties' => true,
                ),
            ),
            'get-queue-item' => array(
                'label' => __('Get queue item', 'technorapper-ai-wire'),
                'description' => __('Fetch one queue item, including review/SEO summaries and a short source excerpt. Does not return the OpenAI API key or full model payloads.', 'technorapper-ai-wire'),
                'readonly' => true,
                'idempotent' => true,
                'callback' => 'ability_get_queue_item',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'integer', 'minimum' => 1),
                    ),
                    'required' => array('id'),
                    'additionalProperties' => true,
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'properties' => array('item' => $queue_item),
                    'additionalProperties' => true,
                ),
            ),
            'get-run-status' => array(
                'label' => __('Get run status', 'technorapper-ai-wire'),
                'description' => __('Report whether a processing run is in progress and summarize the last AI Wire run.', 'technorapper-ai-wire'),
                'readonly' => true,
                'idempotent' => true,
                'callback' => 'ability_get_run_status',
                'input_schema' => array(),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
            ),
            'get-usage' => array(
                'label' => __('Get usage', 'technorapper-ai-wire'),
                'description' => __('Return this month\'s tracked API spend, tokens, search calls, and remaining daily post cap. This is plugin-local usage, not the provider account total.', 'technorapper-ai-wire'),
                'readonly' => true,
                'idempotent' => true,
                'callback' => 'ability_get_usage',
                'input_schema' => array(),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
            ),
            'get-settings' => array(
                'label' => __('Get settings', 'technorapper-ai-wire'),
                'description' => __('Return non-secret AI Wire settings: schedule, caps, models, auto-publish, and whether an API key is configured. The API key value is never returned.', 'technorapper-ai-wire'),
                'readonly' => true,
                'idempotent' => true,
                'callback' => 'ability_get_settings',
                'input_schema' => array(),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
            ),
            'get-scores' => array(
                'label' => __('Get scores', 'technorapper-ai-wire'),
                'description' => __('Return average review and SEO scores for a date range. Optional range (7d, 30d, 90d, month, year, all) and scope (posts, published, reviewed, quarantined).', 'technorapper-ai-wire'),
                'readonly' => true,
                'idempotent' => true,
                'callback' => 'ability_get_scores',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'range' => array('type' => 'string'),
                        'group' => array('type' => 'string'),
                        'scope' => array('type' => 'string'),
                    ),
                    'additionalProperties' => true,
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
            ),
            'get-feed-health' => array(
                'label' => __('Get feed health', 'technorapper-ai-wire'),
                'description' => __('Summarize each tenant\'s enabled state, queue counts by status, latest item, and last-run feed errors. Use this to find tenants that stopped bringing in posts.', 'technorapper-ai-wire'),
                'readonly' => true,
                'idempotent' => true,
                'callback' => 'ability_get_feed_health',
                'input_schema' => array(),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
            ),
            'run-now' => array(
                'label' => __('Run processing now', 'technorapper-ai-wire'),
                'description' => __('Queue an immediate AI Wire run: fetch enabled feeds, rewrite, review, and publish or save drafts according to current settings.', 'technorapper-ai-wire'),
                'callback' => 'ability_run_now',
                'input_schema' => array(),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
            ),
            'stop-run' => array(
                'label' => __('Stop current run', 'technorapper-ai-wire'),
                'description' => __('Release the processing lock so a stuck or in-progress run can stop after the item already in flight.', 'technorapper-ai-wire'),
                'destructive' => true,
                'callback' => 'ability_stop_run',
                'input_schema' => array(),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
            ),
            'retry-item' => array(
                'label' => __('Retry queue item', 'technorapper-ai-wire'),
                'description' => __('Retry one error, retry_wait, or quarantined queue item.', 'technorapper-ai-wire'),
                'callback' => 'ability_retry_item',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'integer', 'minimum' => 1),
                    ),
                    'required' => array('id'),
                    'additionalProperties' => true,
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
            ),
            'delete-queue-item' => array(
                'label' => __('Delete queue item', 'technorapper-ai-wire'),
                'description' => __('Delete one queue row. Any WordPress post created from it is left in place.', 'technorapper-ai-wire'),
                'destructive' => true,
                'callback' => 'ability_delete_queue_item',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'id' => array('type' => 'integer', 'minimum' => 1),
                    ),
                    'required' => array('id'),
                    'additionalProperties' => true,
                ),
                'output_schema' => array(
                    'type' => 'object',
                    'additionalProperties' => true,
                ),
            ),
        );
    }

    private function ability_tenant_lookup_schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'id' => array('type' => 'string', 'description' => 'Stable tenant id derived from the feed URL.'),
                'tenant_id' => array('type' => 'string'),
                'url' => array('type' => 'string', 'description' => 'Feed URL.'),
                'feed_url' => array('type' => 'string'),
                'name' => array('type' => 'string', 'description' => 'Tenant display name.'),
            ),
            'additionalProperties' => true,
        );
    }

    private function ability_tenant_write_schema($creating) {
        $schema = $this->ability_tenant_lookup_schema();
        $schema['properties']['enabled'] = array('type' => 'boolean');
        $schema['properties']['pin_to_homepage'] = array('type' => 'boolean');
        $schema['properties']['map_post_status'] = array('type' => 'string', 'description' => 'auto, publish, draft, or pending.');
        $schema['properties']['map_title'] = array('type' => 'string', 'enum' => array('rewrite', 'source'));
        $schema['properties']['map_content'] = array('type' => 'string', 'enum' => array('rewrite', 'source'));
        $schema['properties']['map_date'] = array('type' => 'string', 'enum' => array('now', 'source'));
        $schema['properties']['map_taxonomy'] = array('type' => 'string');
        $schema['properties']['category'] = array('type' => 'string', 'description' => 'Category name or slug to assign.');
        $schema['properties']['terms'] = array(
            'type' => 'array',
            'items' => array('type' => 'string'),
            'description' => 'Category/term names, slugs, or IDs.',
        );
        $schema['properties']['map_auto_desk'] = array('type' => 'boolean');
        unset($creating);
        return $schema;
    }

    private function ability_object_schema() {
        return array(
            'type' => 'object',
            'additionalProperties' => true,
        );
    }

    private function ability_queue_item_schema() {
        return array(
            'type' => 'object',
            'additionalProperties' => true,
        );
    }

    private function ability_present_tenant($tenant) {
        $tenant = is_array($tenant) ? $tenant : array();
        $term_ids = array_map('intval', (array) ($tenant['map_terms'] ?? array()));
        $terms = array();
        $taxonomy = sanitize_key($tenant['map_taxonomy'] ?? 'category');
        foreach ($term_ids as $term_id) {
            $term = get_term($term_id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $terms[] = array(
                    'id' => (int) $term->term_id,
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                );
            }
        }
        return array(
            'id' => (string) ($tenant['id'] ?? ''),
            'name' => (string) ($tenant['name'] ?? ''),
            'url' => (string) ($tenant['url'] ?? ''),
            'enabled' => !empty($tenant['enabled']),
            'pin_to_homepage' => !empty($tenant['pin_to_homepage']),
            'map_post_type' => (string) ($tenant['map_post_type'] ?? 'post'),
            'map_taxonomy' => $taxonomy,
            'map_terms' => $term_ids,
            'terms' => $terms,
            'map_auto_desk' => !empty($tenant['map_auto_desk']),
            'map_post_status' => (string) ($tenant['map_post_status'] ?? 'auto'),
            'map_title' => (string) ($tenant['map_title'] ?? 'rewrite'),
            'map_date' => (string) ($tenant['map_date'] ?? 'now'),
            'map_content' => (string) ($tenant['map_content'] ?? 'rewrite'),
        );
    }

    private function ability_present_queue_item($row, $detailed) {
        $review = !empty($row['review_json']) ? json_decode($row['review_json'], true) : null;
        $rewrite = !empty($row['rewrite_json']) ? json_decode($row['rewrite_json'], true) : null;
        $item = array(
            'id' => (int) ($row['id'] ?? 0),
            'feed_name' => (string) ($row['feed_name'] ?? ''),
            'feed_url' => (string) ($row['feed_url'] ?? ''),
            'source_title' => (string) ($row['source_title'] ?? ''),
            'source_url' => (string) ($row['source_url'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'wp_post_id' => isset($row['wp_post_id']) ? (int) $row['wp_post_id'] : 0,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'error_text' => sanitize_text_field($row['error_text'] ?? ''),
            'review_score' => is_array($review) && isset($review['score']) ? (int) $review['score'] : null,
            'review_verdict' => is_array($review) ? sanitize_text_field($review['verdict'] ?? '') : '',
            'seo_score' => is_array($rewrite) ? $this->extract_seo_score($rewrite) : null,
        );
        if (!empty($item['wp_post_id'])) {
            $edit = get_edit_post_link((int) $item['wp_post_id'], 'raw');
            $item['edit_url'] = is_string($edit) ? $edit : '';
            $item['permalink'] = get_permalink((int) $item['wp_post_id']);
        }
        if ($detailed) {
            $body = (string) ($row['source_body'] ?? '');
            $item['source_excerpt'] = function_exists('mb_substr') ? mb_substr($body, 0, 500) : substr($body, 0, 500);
        }
        return $item;
    }

    private function ability_find_tenant($input) {
        $input = is_array($input) ? $input : array();
        $settings = $this->settings();
        $id = sanitize_text_field((string) ($input['id'] ?? $input['tenant_id'] ?? ''));
        $url = esc_url_raw((string) ($input['url'] ?? $input['feed_url'] ?? ''), array('http', 'https'));
        $name = strtolower(trim((string) ($input['name'] ?? '')));
        foreach ((array) ($settings['tenants'] ?? array()) as $tenant) {
            if ($id !== '' && ($tenant['id'] ?? '') === $id) {
                return $tenant;
            }
            if ($url && ($tenant['url'] ?? '') === $url) {
                return $tenant;
            }
        }
        if ($name !== '') {
            $matches = array();
            foreach ((array) ($settings['tenants'] ?? array()) as $tenant) {
                if (strtolower((string) ($tenant['name'] ?? '')) === $name) {
                    $matches[] = $tenant;
                }
            }
            if (count($matches) === 1) {
                return $matches[0];
            }
            if (count($matches) > 1) {
                return new WP_Error('tr_ai_wire_ambiguous_tenant', 'More than one tenant uses that name. Pass id or feed URL.', array('status' => 400));
            }
        }
        return new WP_Error('tr_ai_wire_not_found', 'Tenant not found.', array('status' => 404));
    }

    private function ability_build_tenant($input, $base, $updating = false) {
        $row = $base;
        if (isset($input['url']) || isset($input['feed_url'])) {
            $row['url'] = (string) ($input['url'] ?? $input['feed_url']);
        }
        if (array_key_exists('name', $input) && $input['name'] !== '') {
            $row['name'] = $input['name'];
        }
        if (array_key_exists('enabled', $input)) {
            $row['enabled'] = !empty($input['enabled']) ? 1 : 0;
        }
        if (array_key_exists('pin_to_homepage', $input)) {
            $row['pin_to_homepage'] = !empty($input['pin_to_homepage']) ? 1 : 0;
        }
        foreach (array('map_title', 'map_content', 'map_date', 'map_taxonomy', 'map_post_type', 'map_auto_desk') as $key) {
            if (array_key_exists($key, $input)) {
                $row[$key] = $input[$key];
            }
        }
        if (isset($input['map_post_status'])) {
            $status = sanitize_key((string) $input['map_post_status']);
            $aliases = array('drafts' => 'draft', 'published' => 'publish');
            $row['map_post_status'] = $aliases[$status] ?? $status;
        }
        $taxonomy = sanitize_key($row['map_taxonomy'] ?? 'category');
        $wanted_terms = array();
        if (isset($input['terms']) && is_array($input['terms'])) {
            $wanted_terms = $input['terms'];
        }
        if (!empty($input['category'])) {
            $wanted_terms[] = $input['category'];
        }
        if ($wanted_terms) {
            $resolved = $this->ability_resolve_terms($wanted_terms, $taxonomy);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $row['map_terms'] = $resolved;
        }
        $tenant = $this->sanitize_tenant($row);
        if (!$tenant) {
            return new WP_Error('tr_ai_wire_invalid_feed', $updating ? 'The tenant feed URL is not a valid HTTP(S) URL.' : 'A valid HTTP(S) feed URL is required.', array('status' => 400));
        }
        if ($updating && array_key_exists('enabled', $input)) {
            $tenant['enabled'] = !empty($input['enabled']) ? 1 : 0;
        }
        return $tenant;
    }

    private function ability_resolve_terms($values, $taxonomy) {
        $ids = array();
        foreach ((array) $values as $value) {
            if (is_numeric($value)) {
                $term = get_term((int) $value, $taxonomy);
            } else {
                $label = sanitize_text_field((string) $value);
                $term = get_term_by('slug', sanitize_title($label), $taxonomy);
                if (!$term) {
                    $term = get_term_by('name', $label, $taxonomy);
                }
            }
            if (!$term || is_wp_error($term)) {
                return new WP_Error('tr_ai_wire_unknown_term', 'Could not find a matching category/term: ' . sanitize_text_field((string) $value), array('status' => 400));
            }
            $ids[] = (int) $term->term_id;
        }
        return array_values(array_unique($ids));
    }

    private function persist_tenants($tenants) {
        $current = $this->settings();
        $current['tenants'] = $tenants;
        $clean = $this->sanitize_settings($current);
        update_option(self::OPTION, $clean);
        return $this->settings();
    }

    private function easy_mcp_plugin_files() {
        $files = array('easy-mcp-ai/easy-mcp-ai.php', 'easy-mcp-ai/easy-mcp.php');
        if (!function_exists('get_plugins')) {
            return $files;
        }
        foreach (get_plugins() as $file => $data) {
            $slug = dirname($file);
            $name = (string) ($data['Name'] ?? '');
            $domain = (string) ($data['TextDomain'] ?? '');
            if ($slug === self::EASY_MCP_SLUG || $domain === 'easy-mcp-ai' || stripos($name, 'Easy MCP') !== false) {
                $files[] = $file;
            }
        }
        return array_values(array_unique($files));
    }

    private function easy_mcp_plugin_file() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = function_exists('get_plugins') ? get_plugins() : array();
        foreach ($this->easy_mcp_plugin_files() as $file) {
            if (isset($plugins[$file])) {
                return $file;
            }
        }
        return 'easy-mcp-ai/easy-mcp-ai.php';
    }

    private function mcp_connect_links() {
        $url = $this->mcp_endpoint_url();
        $config = rawurlencode(base64_encode(wp_json_encode(array('url' => $url))));
        return array(
            'claude' => 'https://claude.ai/settings/connectors',
            'chatgpt' => 'https://chatgpt.com/#settings',
            'cursor' => 'https://cursor.com/link/mcp?name=' . rawurlencode('technorapper-ai-wire') . '&config=' . $config,
            'easy_mcp' => admin_url('admin.php?page=easy-mcp-ai'),
            'docs' => 'https://docs.easymcpai.com/connect-other-mcp-clients-to-wordpress',
        );
    }
}
