<?php
/**
 * MCP / Abilities API coverage for TechnoRapper AI Wire.
 */

class Test_TechnoRapper_AI_Wire_Abilities extends WP_UnitTestCase {
    private $plugin;
    private $admin_id;

    public function set_up() {
        parent::set_up();
        TechnoRapper_AI_Wire::activate();
        $this->plugin = new TechnoRapper_AI_Wire();
        $this->admin_id = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($this->admin_id);
        delete_option(TechnoRapper_AI_Wire::API_KEY_OPTION);
        delete_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION);
        delete_option(TechnoRapper_AI_Wire::LAST_RUN_OPTION);
        update_option(TechnoRapper_AI_Wire::OPTION, TechnoRapper_AI_Wire::defaults());
    }

    public function tear_down() {
        wp_set_current_user(0);
        delete_option(TechnoRapper_AI_Wire::API_KEY_OPTION);
        delete_option(TechnoRapper_AI_Wire::OPTION);
        parent::tear_down();
    }

    private function invoke_private($method, $args = array()) {
        $reflection = new ReflectionMethod('TechnoRapper_AI_Wire', $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($this->plugin, $args);
    }

    private function ensure_abilities_registered() {
        if (!function_exists('wp_register_ability') || !class_exists('WP_Ability')) {
            $this->markTestSkipped('Abilities API requires WordPress 6.9+');
        }
        if (function_exists('wp_has_ability') && wp_has_ability('tr-ai-wire/list-tenants')) {
            return;
        }
        if (function_exists('wp_has_ability_category') && !wp_has_ability_category('tr-ai-wire')) {
            do_action('wp_abilities_api_categories_init');
        }
        do_action('wp_abilities_api_init');
        $this->assertTrue(function_exists('wp_has_ability') && wp_has_ability('tr-ai-wire/list-tenants'));
    }

    public function test_mcp_connect_card_renders_endpoint_and_prompts() {
        ob_start();
        $this->invoke_private('render_mcp_panel');
        $html = ob_get_clean();
        $this->assertStringContainsString('Use AI Wire from your AI agent', $html);
        $this->assertStringContainsString('easy-mcp-ai/v1/mcp', $html);
        $this->assertStringContainsString('Install and activate Easy MCP', $html);
        $this->assertStringContainsString('Connect to Claude', $html);
        $this->assertStringContainsString('Connect to ChatGPT', $html);
        $this->assertStringContainsString('Add to Cursor', $html);
        $this->assertStringContainsString('Find the tenants that have stopped bringing in posts', $html);
        $this->assertStringNotContainsString('sk-test-secret', $html);
    }

    public function test_get_settings_never_returns_the_api_key() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'sk-test-secret-key', false);
        $settings = $this->plugin->ability_get_settings();
        $this->assertTrue($settings['api_key_configured']);
        $encoded = wp_json_encode($settings);
        $this->assertStringNotContainsString('sk-test-secret-key', $encoded);
        $this->assertArrayNotHasKey('api_key', $settings);
        $this->assertArrayHasKey('cron_frequency', $settings);
        $this->assertArrayHasKey('auto_publish', $settings);
    }

    public function test_create_update_and_delete_tenant() {
        $term = self::factory()->term->create_and_get(array(
            'taxonomy' => 'category',
            'name' => 'News',
        ));
        $created = $this->plugin->ability_create_tenant(array(
            'url' => 'https://example.com/feed',
            'name' => 'Example News',
            'category' => 'News',
            'map_post_status' => 'drafts',
        ));
        $this->assertFalse(is_wp_error($created), is_wp_error($created) ? $created->get_error_message() : '');
        $this->assertTrue($created['created']);
        $this->assertSame('https://example.com/feed', $created['tenant']['url']);
        $this->assertSame('draft', $created['tenant']['map_post_status']);
        $this->assertContains((int) $term->term_id, $created['tenant']['map_terms']);

        $listed = $this->plugin->ability_list_tenants();
        $this->assertSame(1, $listed['count']);

        $duplicate = $this->plugin->ability_create_tenant(array(
            'url' => 'https://example.com/feed',
            'name' => 'Again',
        ));
        $this->assertWPError($duplicate);
        $this->assertSame('tr_ai_wire_duplicate_tenant', $duplicate->get_error_code());

        $paused = $this->plugin->ability_update_tenant(array(
            'id' => $created['tenant']['id'],
            'enabled' => false,
        ));
        $this->assertFalse($paused['tenant']['enabled']);

        $deleted = $this->plugin->ability_delete_tenant(array('name' => 'Example News'));
        $this->assertTrue($deleted['deleted']);
        $this->assertSame(0, $this->plugin->ability_list_tenants()['count']);
    }

    public function test_create_tenant_rejects_invalid_feed_url() {
        $result = $this->plugin->ability_create_tenant(array(
            'url' => 'not-a-url',
            'name' => 'Bad',
        ));
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_invalid_feed', $result->get_error_code());
    }

    public function test_queue_list_and_health_include_status_without_source_body() {
        $this->plugin->ability_create_tenant(array(
            'url' => 'https://example.com/feed.xml',
            'name' => 'Example Feed',
        ));
        $id = $this->invoke_private('insert_item', array(
            hash('sha256', 'ability-queue-1'),
            'editorial-v1',
            'A',
            'https://example.com/feed.xml',
            'Example Feed',
            'guid-1',
            'https://example.com/story',
            'Source title',
            'Secret source body that should stay truncated',
        ));
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'error',
            'error_text' => 'feed timed out',
            'review_json' => wp_json_encode(array('score' => 40, 'verdict' => 'reject')),
        ), array('id' => $id));

        $list = $this->plugin->ability_list_queue_items(array('status' => 'error'));
        $this->assertSame(1, $list['total']);
        $this->assertSame('error', $list['items'][0]['status']);
        $this->assertSame(40, $list['items'][0]['review_score']);
        $this->assertArrayNotHasKey('source_excerpt', $list['items'][0]);
        $this->assertArrayNotHasKey('source_body', $list['items'][0]);

        $item = $this->plugin->ability_get_queue_item(array('id' => $id));
        $this->assertStringContainsString('Secret source body', $item['item']['source_excerpt']);
        $this->assertArrayNotHasKey('source_body', $item['item']);
        $this->assertArrayNotHasKey('rewrite_json', $item['item']);

        $health = $this->plugin->ability_get_feed_health();
        $this->assertSame('error', $health['tenants'][0]['latest_status']);
        $this->assertSame(1, $health['tenants'][0]['queue_by_status']['error']);
    }

    public function test_run_now_requires_api_key_and_queues_when_present() {
        $missing = $this->plugin->ability_run_now();
        $this->assertWPError($missing);
        $this->assertSame('tr_ai_wire_no_api_key', $missing->get_error_code());

        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'sk-test-key', false);
        $queued = $this->plugin->ability_run_now();
        $this->assertTrue($queued['queued']);
        $this->assertNotEmpty($queued['run_token']);
        $this->assertNotFalse(wp_next_scheduled(TechnoRapper_AI_Wire::CRON_HOOK, array('mcp', $queued['run_token'])));

        update_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION, array(
            'created_at' => time(),
            'token' => wp_generate_uuid4(),
        ), false);
        $busy = $this->plugin->ability_run_now();
        $this->assertFalse($busy['queued']);
        $this->assertTrue($busy['run_in_progress']);

        $stopped = $this->plugin->ability_stop_run();
        $this->assertTrue($stopped['stopped']);
        $this->assertFalse($this->plugin->is_run_in_progress());
    }

    public function test_retry_and_delete_queue_item_guards() {
        $missing = $this->plugin->ability_retry_item(array('id' => 999999));
        $this->assertWPError($missing);

        $id = $this->invoke_private('insert_item', array(
            hash('sha256', 'ability-retry-1'),
            'editorial-v1',
            'A',
            'https://example.com/feed.xml',
            'Example Feed',
            'guid-retry',
            'https://example.com/story-retry',
            'Retry title',
            'Body',
        ));
        $wrong_status = $this->plugin->ability_retry_item(array('id' => $id));
        $this->assertWPError($wrong_status);
        $this->assertSame('tr_ai_wire_invalid_status', $wrong_status->get_error_code());

        $deleted = $this->plugin->ability_delete_queue_item(array('id' => $id));
        $this->assertTrue($deleted['deleted']);
        $this->assertEmpty($this->invoke_private('get_item', array($id)));
    }

    public function test_usage_and_run_status_are_safe_summaries() {
        $usage = $this->plugin->ability_get_usage();
        $this->assertArrayHasKey('cost_usd', $usage);
        $this->assertArrayHasKey('remaining_posts_today', $usage);
        $this->assertFalse($usage['api_key_configured']);

        $status = $this->plugin->ability_get_run_status();
        $this->assertArrayHasKey('run_in_progress', $status);
        $this->assertArrayHasKey('last_run', $status);
        $this->assertFalse($status['run_in_progress']);
    }

    public function test_ability_permission_rejects_subscribers() {
        $subscriber = self::factory()->user->create(array('role' => 'subscriber'));
        wp_set_current_user($subscriber);
        $denied = $this->plugin->ability_permission(array());
        $this->assertWPError($denied);
        wp_set_current_user($this->admin_id);
        $this->assertTrue($this->plugin->ability_permission(array()));
    }

    public function test_abilities_register_with_readonly_annotations() {
        $this->ensure_abilities_registered();
        $expected = array(
            'tr-ai-wire/list-tenants',
            'tr-ai-wire/get-tenant',
            'tr-ai-wire/create-tenant',
            'tr-ai-wire/update-tenant',
            'tr-ai-wire/delete-tenant',
            'tr-ai-wire/list-queue-items',
            'tr-ai-wire/get-queue-item',
            'tr-ai-wire/get-run-status',
            'tr-ai-wire/get-usage',
            'tr-ai-wire/get-settings',
            'tr-ai-wire/get-scores',
            'tr-ai-wire/get-feed-health',
            'tr-ai-wire/run-now',
            'tr-ai-wire/stop-run',
            'tr-ai-wire/retry-item',
            'tr-ai-wire/delete-queue-item',
        );
        foreach ($expected as $name) {
            $this->assertNotNull(wp_get_ability($name), 'Missing ability ' . $name);
        }
        $list = wp_get_ability('tr-ai-wire/list-tenants');
        $meta = $list->get_meta();
        $this->assertTrue(!empty($meta['annotations']['readonly']));
        $this->assertTrue(!empty($meta['show_in_rest']));

        $create = wp_get_ability('tr-ai-wire/create-tenant');
        $result = $create->execute(array(
            'url' => 'https://example.org/rss',
            'name' => 'Org Feed',
        ));
        $this->assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertTrue($result['created']);
    }
}
