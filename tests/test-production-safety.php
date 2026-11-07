<?php
/**
 * Production-safety integration tests for TechnoRapper AI Wire.
 */

class Test_TechnoRapper_AI_Wire_Production_Safety extends WP_UnitTestCase {
    private $plugin;

    public function set_up() {
        parent::set_up();
        TechnoRapper_AI_Wire::activate();
        $this->plugin = new TechnoRapper_AI_Wire();
        delete_option(TechnoRapper_AI_Wire::API_KEY_OPTION);
        delete_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION);
        delete_option(TechnoRapper_AI_Wire::LAST_RUN_OPTION);
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function tear_down() {
        remove_all_filters('pre_http_request');
        delete_option(TechnoRapper_AI_Wire::API_KEY_OPTION);
        delete_option('tr_ai_wire_usage_' . gmdate('Ym'));
        parent::tear_down();
    }

    private function invoke_private($method, $args = array()) {
        $reflection = new ReflectionMethod('TechnoRapper_AI_Wire', $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($this->plugin, $args);
    }

    private function draft_settings() {
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['auto_publish'] = 0;
        return $settings;
    }

    private function create_queue_item($status = 'reviewed') {
        return $this->invoke_private('insert_item', array(
            hash('sha256', wp_generate_uuid4()),
            'test-experiment',
            'A',
            'https://example.com/feed.xml',
            'Example Feed',
            'guid-' . wp_generate_uuid4(),
            'https://example.com/story',
            'Source title',
            'Source body <img src="https://cdn.example.com/photo.jpg" alt="photo">',
        ));
    }

    private function assert_plugin_table_exists($table) {
        global $wpdb;
        $like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($table) : $table;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if ($found === $table) {
            $this->assertSame($table, $found);
            return;
        }
        // WP PHPUnit rewrites CREATE TABLE to CREATE TEMPORARY TABLE during a
        // test. SHOW TABLES omits those, so probe columns on the session table.
        $was_suppressing = $wpdb->suppress_errors();
        $safe_table = str_replace('`', '', (string) $table);
        $columns = $wpdb->get_results("SHOW COLUMNS FROM `{$safe_table}`");
        $wpdb->suppress_errors($was_suppressing);
        $this->assertNotEmpty($columns, 'Missing plugin table: ' . $table);
    }

    public function test_activation_creates_all_required_tables() {
        global $wpdb;
        $expected = array(
            $wpdb->prefix . 'tr_ai_wire_items',
            $wpdb->prefix . 'tr_ai_wire_views',
            $wpdb->prefix . 'tr_ai_wire_view_limits',
        );
        foreach ($expected as $table) {
            $this->assert_plugin_table_exists($table);
        }
        $this->assertSame('6', get_option('tr_ai_wire_schema_version'));
        $this->assertNotEmpty($wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}tr_ai_wire_items LIKE 'source_published_at'"));
    }

    public function test_schema_upgrade_is_hooked_on_frontend_init() {
        $this->assertSame(5, has_action('init', array($this->plugin, 'maybe_upgrade_schema')));
        $this->assertSame(5, has_action('admin_init', array($this->plugin, 'maybe_upgrade_schema')));
    }

    public function test_draft_creation_recovers_existing_post_after_interrupted_queue_update() {
        $id = $this->create_queue_item();
        $item = array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-test',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        );
        $rewrite = array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        );
        $review = array('verdict' => 'pass', 'safe_to_publish' => true);

        $this->invoke_private('create_draft', array($id, $item, $rewrite, $review, $this->draft_settings()));
        $first_posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $first_posts);
        $first_post_id = (int) $first_posts[0];

        // Simulate a crash window by reverting only the queue state.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array('status' => 'reviewed', 'wp_post_id' => null), array('id' => $id));
        $this->invoke_private('create_draft', array($id, $item, $rewrite, $review, $this->draft_settings()));

        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $this->assertSame('draft', get_post_status($posts[0]));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('draft', $row['status']);
        $this->assertSame((string) $posts[0], (string) $row['wp_post_id']);
    }

    public function test_schema_upgrade_from_v4_preserves_existing_queue_rows() {
        $id = $this->create_queue_item();
        update_option('tr_ai_wire_schema_version', '4');
        $this->plugin->maybe_upgrade_schema();

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, source_title FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame((string) $id, (string) $row['id']);
        $this->assertSame('Source title', $row['source_title']);
        $this->assertSame('6', get_option('tr_ai_wire_schema_version'));
        $this->assert_plugin_table_exists($wpdb->prefix . 'tr_ai_wire_view_limits');
    }

    public function test_old_worker_cannot_overwrite_a_replacement_lease() {
        global $wpdb;
        $old_token = wp_generate_uuid4();
        $new_token = wp_generate_uuid4();
        update_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION, array(
            'created_at' => time(),
            'token' => $old_token,
        ), false);
        $replacement = array('created_at' => microtime(true), 'token' => $new_token);
        $wpdb->update(
            $wpdb->options,
            array('option_value' => maybe_serialize($replacement)),
            array('option_name' => TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION),
            array('%s'),
            array('%s')
        );
        wp_cache_delete(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION, 'options');
        wp_cache_delete('alloptions', 'options');

        $this->assertFalse($this->invoke_private('refresh_processing_lock', array($old_token)));
        $current = get_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION);
        $this->assertSame($new_token, $current['token']);
        delete_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION);
    }

    public function test_lock_heartbeat_succeeds_within_the_same_second() {
        $token = wp_generate_uuid4();
        update_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION, array(
            'created_at' => time(),
            'token' => $token,
        ), false);
        $this->assertTrue($this->invoke_private('refresh_processing_lock', array($token)));
        $current = get_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION);
        $this->assertSame($token, $current['token']);
        delete_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION);
    }

    public function test_writer_and_reviewer_run_as_separate_queue_steps() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $id = $this->create_queue_item();
        $calls = 0;
        $writer = array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
            'category' => '',
            'tags' => array(),
        );
        $review = array(
            'verdict' => 'pass',
            'score' => 90,
            'safe_to_publish' => true,
            'fact_flags' => array(),
            'policy_flags' => array(),
            'pragmatism' => array(
                'is_new' => true,
                'is_useful' => true,
                'is_grounded' => true,
                'similarity_risk' => 'low',
            ),
        );
        $callback = function ($preempt, $args, $url) use (&$calls, $writer, $review) {
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            $calls++;
            $output = $calls === 1 ? $writer : $review;
            return array(
                'headers' => array(),
                'body' => wp_json_encode(array(
                    'output' => array(array(
                        'type' => 'message',
                        'content' => array(array('type' => 'output_text', 'text' => wp_json_encode($output))),
                    )),
                    'usage' => array('input_tokens' => 20, 'output_tokens' => 30),
                )),
                'response' => array('code' => 200, 'message' => 'OK'),
                'cookies' => array(),
                'filename' => null,
            );
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $settings = $this->draft_settings();

        $this->invoke_private('process_item', array($id, $settings));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('rewritten', $row['status']);
        $this->assertSame(1, $calls);

        $this->invoke_private('process_item', array($id, $settings));
        remove_filter('pre_http_request', $callback, 10);
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('draft', $row['status']);
        $this->assertGreaterThan(0, (int) $row['wp_post_id']);
        $this->assertSame(2, $calls);
    }

    public function test_transient_failure_is_scheduled_for_retry() {
        $id = $this->create_queue_item();
        $this->invoke_private('schedule_retry', array($id, 'Temporary upstream failure'));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, attempts, next_retry_at FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('retry_wait', $row['status']);
        $this->assertSame('1', (string) $row['attempts']);
        $this->assertNotEmpty($row['next_retry_at']);
        $this->assertNotFalse(wp_next_scheduled(TechnoRapper_AI_Wire::CRON_HOOK, array('retry', (int) $id, 1)));
    }

    public function test_api_authentication_error_is_not_retried() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $captured_timeout = null;
        $callback = function ($preempt, $args, $url) use (&$captured_timeout) {
            if (strpos($url, 'api.openai.com/v1/responses') !== false) {
                $captured_timeout = $args['timeout'];
                return array(
                    'headers' => array(),
                    'body' => wp_json_encode(array('error' => array('message' => 'Invalid API key'))),
                    'response' => array('code' => 401, 'message' => 'Unauthorized'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            return $preempt;
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $result = $this->invoke_private('ai_json', array(
            'gpt-6-luna',
            'Return JSON.',
            'Test input.',
            TechnoRapper_AI_Wire::defaults(),
            false,
        ));
        remove_filter('pre_http_request', $callback, 10);
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_api_permanent', $result->get_error_code());
        $this->assertSame(TechnoRapper_AI_Wire::API_TIMEOUT, $captured_timeout);
    }

    public function test_schema_upgrade_does_not_clear_scheduled_retry_events() {
        $args = array('retry', 123, 1);
        wp_schedule_single_event(time() + 600, TechnoRapper_AI_Wire::CRON_HOOK, $args);
        $this->assertNotFalse(wp_next_scheduled(TechnoRapper_AI_Wire::CRON_HOOK, $args));
        update_option('tr_ai_wire_schema_version', '4');
        $this->plugin->maybe_upgrade_schema();
        $this->assertSame('6', get_option('tr_ai_wire_schema_version'));
        $this->assertNotFalse(wp_next_scheduled(TechnoRapper_AI_Wire::CRON_HOOK, $args));
        wp_unschedule_event(wp_next_scheduled(TechnoRapper_AI_Wire::CRON_HOOK, $args), TechnoRapper_AI_Wire::CRON_HOOK, $args);
    }

    public function test_consecutive_lock_heartbeats_keep_ownership() {
        $token = wp_generate_uuid4();
        update_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION, array(
            'created_at' => time(),
            'token' => $token,
        ), false);
        $this->assertTrue($this->invoke_private('refresh_processing_lock', array($token)));
        $this->assertTrue($this->invoke_private('refresh_processing_lock', array($token)));
        $current = get_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION);
        $this->assertSame($token, $current['token']);
        delete_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION);
    }

    public function test_incomplete_api_response_is_retryable() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $callback = function ($preempt, $args, $url) {
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            $payload = json_decode($args['body'], true);
            $this->assertSame('none', $payload['reasoning']['effort'] ?? null);
            return array(
                'headers' => array(),
                'body' => wp_json_encode(array(
                    'status' => 'incomplete',
                    'output' => array(),
                    'usage' => array('input_tokens' => 10, 'output_tokens' => 5),
                )),
                'response' => array('code' => 200, 'message' => 'OK'),
                'cookies' => array(),
                'filename' => null,
            );
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $result = $this->invoke_private('ai_json', array(
            'gpt-6-luna',
            'Return JSON.',
            'Test input.',
            TechnoRapper_AI_Wire::defaults(),
            false,
        ));
        remove_filter('pre_http_request', $callback, 10);
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_api', $result->get_error_code());
    }

    public function test_rate_limited_api_error_is_retryable() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $callback = function ($preempt, $args, $url) {
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            return array(
                'headers' => array(),
                'body' => wp_json_encode(array('error' => array('message' => 'Rate limited'))),
                'response' => array('code' => 429, 'message' => 'Too Many Requests'),
                'cookies' => array(),
                'filename' => null,
            );
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $result = $this->invoke_private('ai_json', array(
            'gpt-6-luna',
            'Return JSON.',
            'Test input.',
            TechnoRapper_AI_Wire::defaults(),
            false,
        ));
        remove_filter('pre_http_request', $callback, 10);
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_api', $result->get_error_code());
    }

    public function test_create_draft_never_publishes_and_quarantines_empty_rewrite() {
        $id = $this->create_queue_item();
        $item = array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-empty',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        );
        $this->invoke_private('create_draft', array($id, $item, array('title' => '', 'body_html' => ''), array('verdict' => 'pass'), $this->draft_settings()));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertEmpty($row['wp_post_id']);

        $rewrite = array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        );
        $this->invoke_private('create_draft', array($id, $item, $rewrite, array('verdict' => 'pass'), $this->draft_settings()));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $this->assertSame('draft', $posts[0]->post_status);
        $this->assertNotSame('publish', $posts[0]->post_status);
    }

    public function test_invalid_or_unpublished_view_is_rejected() {
        $draft_id = self::factory()->post->create(array('post_status' => 'draft'));
        update_post_meta($draft_id, '_tr_ai_wire_experiment_id', 'test-experiment');
        $token = hash_hmac('sha256', $draft_id . '|' . gmdate('Y-m-d'), wp_salt('auth'));
        $request = new WP_REST_Request('POST', '/tr-ai-wire/v1/view');
        $request->set_param('post_id', $draft_id);
        $request->set_param('view_token', $token);
        $draft_result = $this->plugin->record_article_view($request);
        $this->assertWPError($draft_result);
        $this->assertSame(404, $draft_result->get_error_data()['status']);

        $post_id = self::factory()->post->create(array('post_status' => 'publish'));
        update_post_meta($post_id, '_tr_ai_wire_experiment_id', 'test-experiment');
        $request->set_param('post_id', $post_id);
        $request->set_param('view_token', 'not-a-valid-token');
        $invalid = $this->plugin->record_article_view($request);
        $this->assertWPError($invalid);
        $this->assertSame('tr_ai_wire_invalid_view', $invalid->get_error_code());
    }

    public function test_fresh_install_is_paused_without_an_api_key() {
        $settings = TechnoRapper_AI_Wire::defaults();
        $this->assertSame('manual', $settings['cron_frequency']);
        $this->assertSame(0, $settings['max_posts_per_day']);
        $this->assertSame(1, $settings['auto_publish']);
        $this->assertSame(1, $settings['pin_to_homepage']);
        $this->assertSame('post', $settings['map_post_type']);
        $this->assertSame('category', $settings['map_taxonomy']);
        $this->assertSame('auto', $settings['map_post_status']);
        $this->assertSame('rewrite', $settings['map_title']);
        $this->assertSame('now', $settings['map_date']);
        $this->assertSame('rewrite', $settings['map_content']);
        $this->assertSame(4000, $settings['max_output_tokens']);
        $this->assertSame('', $settings['feeds']);
        $this->assertSame(array(), $settings['tenants']);
        $this->assertSame('', $this->invoke_private('api_key'));
        $this->assertSame(0, $this->invoke_private('remaining_posts_today', array($settings)));
        $this->assertFalse(wp_next_scheduled(TechnoRapper_AI_Wire::CRON_HOOK));
    }

    public function test_existing_installs_turn_auto_publish_on_once() {
        delete_option('tr_ai_wire_auto_publish_on');
        update_option(TechnoRapper_AI_Wire::OPTION, array(
            'auto_publish' => 0,
            'feeds' => 'https://example.com/feed.xml',
        ), false);
        TechnoRapper_AI_Wire::enable_auto_publish_once();
        $saved = get_option(TechnoRapper_AI_Wire::OPTION);
        $this->assertSame(1, (int) $saved['auto_publish']);
        $this->assertSame('https://example.com/feed.xml', $saved['feeds']);
        $saved['auto_publish'] = 0;
        update_option(TechnoRapper_AI_Wire::OPTION, $saved, false);
        TechnoRapper_AI_Wire::enable_auto_publish_once();
        $this->assertSame(0, (int) get_option(TechnoRapper_AI_Wire::OPTION)['auto_publish']);
    }

    public function test_existing_installs_raise_output_tokens_for_detailed_articles() {
        delete_option('tr_ai_wire_detailed_output_on');
        update_option(TechnoRapper_AI_Wire::OPTION, array(
            'max_output_tokens' => 1200,
            'feeds' => 'https://example.com/feed.xml',
        ), false);
        TechnoRapper_AI_Wire::enable_detailed_output_once();
        $saved = get_option(TechnoRapper_AI_Wire::OPTION);
        $this->assertSame(4000, (int) $saved['max_output_tokens']);
        $saved['max_output_tokens'] = 2500;
        update_option(TechnoRapper_AI_Wire::OPTION, $saved, false);
        TechnoRapper_AI_Wire::enable_detailed_output_once();
        $this->assertSame(2500, (int) get_option(TechnoRapper_AI_Wire::OPTION)['max_output_tokens']);
    }

    public function test_existing_installs_raise_daily_volume_to_350_once() {
        delete_option('tr_ai_wire_daily_350_on');
        update_option(TechnoRapper_AI_Wire::OPTION, array(
            'max_posts_per_day' => 10,
            'max_items_per_run' => 10,
            'feeds' => 'https://example.com/feed.xml',
        ), false);
        TechnoRapper_AI_Wire::enable_daily_volume_once();
        $saved = get_option(TechnoRapper_AI_Wire::OPTION);
        $this->assertSame(350, (int) $saved['max_posts_per_day']);
        $this->assertSame(350, (int) $saved['max_items_per_run']);
        $saved['max_posts_per_day'] = 25;
        $saved['max_items_per_run'] = 5;
        update_option(TechnoRapper_AI_Wire::OPTION, $saved, false);
        TechnoRapper_AI_Wire::enable_daily_volume_once();
        $again = get_option(TechnoRapper_AI_Wire::OPTION);
        $this->assertSame(25, (int) $again['max_posts_per_day']);
        $this->assertSame(5, (int) $again['max_items_per_run']);
    }

    public function test_existing_installs_raise_low_token_ceiling_once() {
        delete_option('tr_ai_wire_token_ceiling_on');
        update_option(TechnoRapper_AI_Wire::OPTION, array(
            'monthly_token_limit' => 1000000,
            'feeds' => 'https://example.com/feed.xml',
        ), false);
        TechnoRapper_AI_Wire::raise_token_ceiling_once();
        $saved = get_option(TechnoRapper_AI_Wire::OPTION);
        $this->assertSame(200000000, (int) $saved['monthly_token_limit']);
        $this->assertSame('https://example.com/feed.xml', $saved['feeds']);
        $saved['monthly_token_limit'] = 5000000;
        update_option(TechnoRapper_AI_Wire::OPTION, $saved, false);
        TechnoRapper_AI_Wire::raise_token_ceiling_once();
        $this->assertSame(5000000, (int) get_option(TechnoRapper_AI_Wire::OPTION)['monthly_token_limit']);
    }

    public function test_zero_token_ceiling_stays_paused() {
        delete_option('tr_ai_wire_token_ceiling_on');
        update_option(TechnoRapper_AI_Wire::OPTION, array(
            'monthly_token_limit' => 0,
            'feeds' => 'https://example.com/feed.xml',
        ), false);
        TechnoRapper_AI_Wire::raise_token_ceiling_once();
        $saved = get_option(TechnoRapper_AI_Wire::OPTION);
        $this->assertSame(0, (int) $saved['monthly_token_limit']);
        $this->assertSame('https://example.com/feed.xml', $saved['feeds']);
    }

    public function test_revise_review_creates_a_draft_for_human_editing() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'quarantined',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'revise',
                'score' => 82,
                'safe_to_publish' => false,
                'fact_flags' => array(),
                'policy_flags' => array(),
                'pragmatism' => array(
                    'is_new' => false,
                    'is_useful' => true,
                    'is_grounded' => true,
                    'similarity_risk' => 'high',
                ),
            )),
        ), array('id' => $id));
        $this->invoke_private('process_item', array($id, $this->draft_settings()));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('draft', $row['status']);
        $this->assertGreaterThan(0, (int) $row['wp_post_id']);
        $this->assertSame('draft', get_post_status((int) $row['wp_post_id']));
    }

    public function test_reject_or_policy_review_stays_quarantined() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'reviewed',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'reject',
                'safe_to_publish' => false,
                'policy_flags' => array('hate'),
                'pragmatism' => array('is_grounded' => true),
            )),
        ), array('id' => $id));
        $this->invoke_private('process_item', array($id, TechnoRapper_AI_Wire::defaults()));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id, error_text FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
        $this->assertStringContainsString('verdict=reject', (string) $row['error_text']);
    }

    public function test_min_score_setting_can_hold_a_revise_item() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'reviewed',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'revise',
                'score' => 49,
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            )),
        ), array('id' => $id));
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['draft_min_score'] = 70;
        $this->invoke_private('process_item', array($id, $settings));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, error_text FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertStringContainsString('score 49 < 70', (string) $row['error_text']);
    }

    public function test_pass_only_setting_blocks_revise() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'reviewed',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'revise',
                'score' => 88,
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            )),
        ), array('id' => $id));
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['draft_allow_revise'] = 0;
        $this->invoke_private('process_item', array($id, $settings));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, error_text FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertStringContainsString('verdict=revise', (string) $row['error_text']);
    }

    public function test_zero_budget_blocks_api_spend() {
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['monthly_budget_usd'] = 0;
        $this->assertFalse($this->invoke_private('can_spend', array('gpt-6-luna', 100, $settings, false)));
        $settings['monthly_budget_usd'] = 5;
        $settings['monthly_token_limit'] = 0;
        $this->assertFalse($this->invoke_private('can_spend', array('gpt-6-luna', 100, $settings, false)));
        $this->assertStringContainsString('token limit is 0', $this->invoke_private('spend_block_reason', array('gpt-6-luna', 100, $settings, false)));
    }

    public function test_recorded_luna_usage_under_provider_balance_can_still_spend() {
        update_option('tr_ai_wire_usage_' . gmdate('Ym'), array(
            'input_tokens' => 400000,
            'output_tokens' => 287632,
            'search_calls' => 0,
            'cost_usd' => 0.24,
        ), false);
        $settings = TechnoRapper_AI_Wire::defaults();
        $this->assertSame(200000000, (int) $settings['monthly_token_limit']);
        $this->assertTrue($this->invoke_private('can_spend', array('gpt-6-luna', 8000, $settings, false)));
        $this->assertSame('', $this->invoke_private('spend_block_reason', array('gpt-6-luna', 8000, $settings, false)));
    }

    public function test_low_token_ceiling_names_plugin_counts() {
        update_option('tr_ai_wire_usage_' . gmdate('Ym'), array(
            'input_tokens' => 900000,
            'output_tokens' => 80000,
            'search_calls' => 0,
            'cost_usd' => 0.24,
        ), false);
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['monthly_token_limit'] = 1000000;
        $reason = $this->invoke_private('spend_block_reason', array('gpt-6-luna', 50000, $settings, false));
        $this->assertStringContainsString('980000 used + 54000 reserved > 1000000', $reason);
        $this->assertStringContainsString('OpenAI can still show remaining credit', $reason);
        $this->assertFalse($this->invoke_private('can_spend', array('gpt-6-luna', 50000, $settings, false)));
    }

    public function test_dollar_ceiling_names_plugin_amounts() {
        update_option('tr_ai_wire_usage_' . gmdate('Ym'), array(
            'input_tokens' => 1000,
            'output_tokens' => 1000,
            'search_calls' => 0,
            'cost_usd' => 4.99,
        ), false);
        $settings = TechnoRapper_AI_Wire::defaults();
        $reason = $this->invoke_private('spend_block_reason', array('gpt-6.1-terra', 200000, $settings, true));
        $this->assertStringContainsString('AI Wire monthly API budget reached ($4.9900 used + $0.2300 reserved > $5.00)', $reason);
        $this->assertFalse($this->invoke_private('can_spend', array('gpt-6.1-terra', 200000, $settings, true)));
    }

    public function test_public_view_endpoint_enforces_atomic_rate_limit() {
        $post_id = self::factory()->post->create(array('post_status' => 'publish'));
        update_post_meta($post_id, '_tr_ai_wire_experiment_id', 'test-experiment');
        $token = hash_hmac('sha256', $post_id . '|' . gmdate('Y-m-d'), wp_salt('auth'));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $request = new WP_REST_Request('POST', '/tr-ai-wire/v1/view');
        $request->set_param('post_id', $post_id);
        $request->set_param('view_token', $token);

        for ($i = 0; $i < 20; $i++) {
            $response = $this->plugin->record_article_view($request);
            $this->assertNotWPError($response);
        }
        $limited = $this->plugin->record_article_view($request);
        $this->assertWPError($limited);
        $this->assertSame(429, $limited->get_error_data()['status']);
    }

    public function test_raw_rewrite_seo_labels_match_rank_math_style_checks() {
        $seo = $this->invoke_private('analyze_rewrite_seo', array(array(
            'title' => 'Some news headline here today',
            'lede' => 'A lede that is long enough to look like a summary of the story for readers.',
            'body_html' => '<p>One paragraph without headings or a focus keyphrase. Words about the story were published. More words were added. ' . str_repeat('Extra words continue. ', 30) . '</p>',
        )));
        $this->assertSame('Focus Keyphrase: No focus keyphrase!', $seo['keyphrase_label']);
        $this->assertSame('Social: Missing social markup!', $seo['social_label']);
        $this->assertStringStartsWith('Visibility: ', $seo['visibility_label']);
        $this->assertStringEndsWith('!', $seo['visibility_label']);
        $this->assertMatchesRegularExpression('/^SEO Analysis: \d+\/100$/', $seo['seo_analysis_label']);
        $this->assertMatchesRegularExpression('/^Readability: \d+ errors found!$/', $seo['readability_label']);
        $this->assertGreaterThanOrEqual(3, count($seo['readability_errors']));
    }

    public function test_prepare_rewrite_seo_fixes_keyphrase_social_and_visibility() {
        $prepared = $this->invoke_private('prepare_rewrite_seo', array(array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        )));
        $seo = $prepared['seo'];
        $this->assertSame('Visibility: Good!', $seo['visibility_label']);
        $this->assertMatchesRegularExpression('/^SEO Analysis: \d+\/100$/', $seo['seo_analysis_label']);
        $this->assertGreaterThanOrEqual(85, (int) $seo['seo_score']);
        $this->assertLessThanOrEqual(3, count($seo['readability_errors']));
        $this->assertStringContainsString('Readability:', $seo['readability_label']);
        $this->assertNotSame('Focus Keyphrase: No focus keyphrase!', $seo['keyphrase_label']);
        $this->assertSame('useful technology update', $prepared['focus_keyphrase']);
        $this->assertSame('useful-technology-update', $prepared['slug']);
        $this->assertCount(3, $prepared['key_takeaways']);
        $this->assertCount(3, $prepared['faq']);
        $this->assertStringContainsString('Key takeaways', $prepared['body_html']);
        $this->assertStringContainsString('Frequently asked questions', $prepared['body_html']);
        $this->assertSame('Social: Social markup ready!', $seo['social_label']);
        $this->assertNotEmpty($prepared['social_title']);
        $this->assertNotEmpty($prepared['social_description']);
        $this->assertEmpty($this->invoke_private('rewrite_seo_blockers', array($prepared, TechnoRapper_AI_Wire::defaults())));
    }

    public function test_create_draft_writes_rank_math_yoast_and_aioseo_meta() {
        $id = $this->create_queue_item();
        $item = array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-seo',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        );
        $this->invoke_private('create_draft', array($id, $item, array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        ), array('verdict' => 'pass'), $this->draft_settings()));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $post_id = (int) $posts[0]->ID;
        $this->assertSame('useful technology update', get_post_meta($post_id, 'rank_math_focus_keyword', true));
        $this->assertNotEmpty(get_post_meta($post_id, 'rank_math_description', true));
        $this->assertNotEmpty(get_post_meta($post_id, 'rank_math_facebook_title', true));
        $this->assertNotEmpty(get_post_meta($post_id, 'rank_math_facebook_description', true));
        $this->assertSame('useful technology update', get_post_meta($post_id, '_yoast_wpseo_focuskw', true));
        $this->assertNotEmpty(get_post_meta($post_id, '_yoast_wpseo_metadesc', true));
        $this->assertNotEmpty(get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true));
        $this->assertNotEmpty(get_post_meta($post_id, '_aioseo_title', true));
        $this->assertNotEmpty(get_post_meta($post_id, '_aioseo_og_description', true));
        $this->assertSame('draft', get_post_status($post_id));
        $this->assertStringStartsWith('useful-technology-update', $posts[0]->post_name);
    }

    public function test_published_post_lands_on_home_category_with_image() {
        $android = self::factory()->category->create_and_get(array(
            'name' => 'Android',
            'slug' => 'android',
        ));
        self::factory()->category->create(array(
            'name' => 'News',
            'slug' => 'tech',
        ));
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-home',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_body' => 'Source body <img src="https://cdn.example.com/photo.jpg" alt="photo">',
        ), array(
            'title' => 'Samsung Android update lands today',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
            'category' => 'News',
            'tags' => array('Android', 'Samsung'),
        ), array('verdict' => 'pass'), TechnoRapper_AI_Wire::defaults()));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $this->assertSame('publish', get_post_status($posts[0]));
        $cats = wp_get_post_categories((int) $posts[0]->ID);
        $this->assertContains((int) $android->term_id, $cats);
        $this->assertNotContains((int) get_option('default_category'), $cats);
        $this->assertTrue(has_post_thumbnail((int) $posts[0]->ID));
        $this->assertSame('https://cdn.example.com/photo.jpg', get_post_meta((int) $posts[0]->ID, '_tr_ai_wire_source_image', true));
        $this->assertLessThan(0, (int) get_post_thumbnail_id((int) $posts[0]->ID));
        $this->assertStringContainsString('https://cdn.example.com/photo.jpg', get_the_post_thumbnail((int) $posts[0]->ID));
        $this->assertSame('https://cdn.example.com/photo.jpg', get_the_post_thumbnail_url((int) $posts[0]->ID));
        $this->assertCount(0, get_children(array(
            'post_parent' => (int) $posts[0]->ID,
            'post_type' => 'attachment',
            'numberposts' => 5,
        )));
        $this->assertTrue(is_sticky((int) $posts[0]->ID));
        $this->assertSame('1', get_post_meta((int) $posts[0]->ID, '_tr_ai_wire_show_on_home', true));
        $this->assertGreaterThan(0, (int) $posts[0]->post_author);
        $this->assertContains('index', (array) get_post_meta((int) $posts[0]->ID, 'rank_math_robots', true));
    }

    public function test_reads_source_document_for_category_and_tags() {
        $android = self::factory()->category->create_and_get(array(
            'name' => 'Android',
            'slug' => 'android',
        ));
        self::factory()->category->create(array(
            'name' => 'News',
            'slug' => 'tech',
        ));
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-classify',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_title' => 'Galaxy S25 Ultra leak',
            'source_body' => 'Samsung is testing One UI 7 on the Galaxy S25 Ultra for Android phones. <img src="https://cdn.example.com/photo.jpg" alt="photo">',
        ), array(
            'title' => 'A faster wireless gadget update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
            'category' => 'News',
            'tags' => array(),
        ), array('verdict' => 'pass'), TechnoRapper_AI_Wire::defaults()));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $cats = wp_get_post_categories((int) $posts[0]->ID);
        $this->assertContains((int) $android->term_id, $cats);
        $this->assertNotContains((int) get_option('default_category'), $cats);
        $tag_names = wp_get_post_tags((int) $posts[0]->ID, array('fields' => 'names'));
        $this->assertNotEmpty($tag_names);
        $joined = strtolower(implode(' ', $tag_names));
        $this->assertTrue(strpos($joined, 'samsung') !== false || strpos($joined, 'galaxy') !== false || strpos($joined, 'android') !== false);
    }

    public function test_writer_input_lists_live_site_categories() {
        self::factory()->category->create(array(
            'name' => 'Android',
            'slug' => 'android',
        ));
        self::factory()->category->create(array(
            'name' => 'News',
            'slug' => 'tech',
        ));
        $input = $this->invoke_private('build_writer_input', array(array(
            'feed_name' => 'Example Feed',
            'source_title' => 'Galaxy leak',
            'source_url' => 'https://example.com/story',
            'source_body' => 'Samsung Android update',
        )));
        $this->assertStringContainsString('Site categories', $input);
        $this->assertStringContainsString('Android', $input);
        $this->assertStringContainsString('Do not ask a human', $input);
        $this->assertStringContainsString('Never pick News', $input);
    }

    public function test_repair_puts_existing_plugin_posts_on_home_desks() {
        $apple = self::factory()->category->create_and_get(array(
            'name' => 'Apple',
            'slug' => 'apple',
        ));
        $id = $this->create_queue_item();
        $post_id = self::factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'iPhone camera update',
            'post_author' => 0,
            'meta_input' => array(
                '_tr_ai_wire_queue_id' => $id,
            ),
        ));
        $this->invoke_private('update_item', array($id, array(
            'rewrite_json' => wp_json_encode(array(
                'title' => 'iPhone camera update',
                'category' => 'News',
                'tags' => array('iPhone', 'Apple'),
            )),
        )));
        $this->assertFalse(has_post_thumbnail($post_id));
        $this->assertSame(1, $this->plugin->repair_home_posts(20));
        $this->assertContains((int) $apple->term_id, wp_get_post_categories($post_id));
        $this->assertTrue(has_post_thumbnail($post_id));
        $this->assertSame('https://cdn.example.com/photo.jpg', get_post_meta($post_id, '_tr_ai_wire_source_image', true));
        $this->assertLessThan(0, (int) get_post_thumbnail_id($post_id));
        $this->assertTrue(is_sticky($post_id));
        $this->assertSame('1', get_post_meta($post_id, '_tr_ai_wire_show_on_home', true));
        $this->assertGreaterThan(0, (int) get_post($post_id)->post_author);
    }

    public function test_item_without_source_image_is_skipped() {
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-no-image',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_body' => 'No image in this source.',
        ), array(
            'title' => 'Bluetooth HDT could make wireless gadgets faster',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
            'category' => 'News',
            'tags' => array(),
        ), array('verdict' => 'pass'), TechnoRapper_AI_Wire::defaults()));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id, error_text FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
        $this->assertStringContainsString('No source image', (string) $row['error_text']);
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(0, $posts);
    }

    public function test_homepage_toggle_off_keeps_posts_off_the_home_feed() {
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['pin_to_homepage'] = 0;
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-home-off',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        ), array(
            'title' => 'Off-home Android update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
            'category' => 'News',
            'tags' => array('Android'),
        ), array('verdict' => 'pass'), $settings));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $hidden_id = (int) $posts[0]->ID;
        $this->assertFalse(is_sticky($hidden_id));
        $this->assertSame('0', get_post_meta($hidden_id, '_tr_ai_wire_show_on_home', true));
        $this->assertSame('publish', get_post_status($hidden_id));

        $visible_id = self::factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Regular home post',
        ));
        $this->go_to(home_url('/'));
        $home_ids = wp_list_pluck($GLOBALS['wp_query']->posts, 'ID');
        $this->assertNotContains($hidden_id, $home_ids);
        $this->assertContains($visible_id, $home_ids);
        $query = $GLOBALS['wp_query'];
        $this->assertContains($hidden_id, array_map('intval', (array) $query->get('post__not_in')));
        $this->assertEmpty($query->get('meta_query'));
    }

    public function test_homepage_exclusion_does_not_add_a_meta_query() {
        $query = new WP_Query();
        $query->is_home = true;
        $query->is_front_page = false;
        $this->plugin->exclude_from_homepage($query);
        $this->assertEmpty($query->get('meta_query'));
    }

    public function test_map_content_uses_forced_terms_source_title_and_source_date() {
        $guides = self::factory()->category->create_and_get(array(
            'name' => 'Guides',
            'slug' => 'guides',
        ));
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['map_terms'] = array((int) $guides->term_id);
        $settings['map_auto_desk'] = 0;
        $settings['map_title'] = 'source';
        $settings['map_date'] = 'source';
        $settings['map_content'] = 'source';
        $settings['map_post_status'] = 'publish';
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-map',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_title' => 'Feed item title',
            'source_body' => 'Plain source body from the feed. <img src="https://cdn.example.com/photo.jpg" alt="photo">',
            'source_published_at' => '2026-01-15 09:30:00',
        ), array(
            'title' => 'Rewritten AI headline',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
            'category' => 'News',
            'tags' => array('Android'),
        ), array('verdict' => 'pass'), $settings));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $this->assertSame('Feed item title', $posts[0]->post_title);
        $this->assertStringContainsString('Plain source body from the feed.', $posts[0]->post_content);
        $this->assertStringNotContainsString('Grounded article body', $posts[0]->post_content);
        $this->assertSame('2026-01-15 09:30:00', $posts[0]->post_date);
        $this->assertContains((int) $guides->term_id, wp_get_post_categories((int) $posts[0]->ID));
        $this->assertNotContains('android', wp_get_post_categories((int) $posts[0]->ID, array('fields' => 'slugs')));
    }

    public function test_map_content_can_force_draft_status() {
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['auto_publish'] = 1;
        $settings['map_post_status'] = 'draft';
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-map-draft',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        ), array(
            'title' => 'Mapped draft article',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        ), array('verdict' => 'pass'), $settings));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'draft',
            'posts_per_page' => 1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $this->assertSame('draft', get_post_status($posts[0]));
    }

    public function test_sanitize_keeps_map_content_fields() {
        $android = self::factory()->category->create_and_get(array(
            'name' => 'Android',
            'slug' => 'android',
        ));
        $clean = $this->plugin->sanitize_settings(array(
            'feeds' => '',
            'map_post_type' => 'page',
            'map_taxonomy' => 'category',
            'map_terms' => array((int) $android->term_id, 'nope'),
            'map_auto_desk' => 1,
            'map_post_status' => 'pending',
            'map_title' => 'source',
            'map_date' => 'source',
            'map_content' => 'source',
        ));
        $this->assertSame('page', $clean['map_post_type']);
        $this->assertSame('category', $clean['map_taxonomy']);
        $this->assertSame(array((int) $android->term_id), $clean['map_terms']);
        $this->assertSame(1, $clean['map_auto_desk']);
        $this->assertSame('pending', $clean['map_post_status']);
        $this->assertSame('source', $clean['map_title']);
        $this->assertSame('source', $clean['map_date']);
        $this->assertSame('source', $clean['map_content']);
        $this->assertSame(array(), $clean['tenants']);
    }

    public function test_each_feed_onboards_as_its_own_tenant() {
        $android = self::factory()->category->create_and_get(array(
            'name' => 'Android',
            'slug' => 'android',
        ));
        $apple = self::factory()->category->create_and_get(array(
            'name' => 'Apple',
            'slug' => 'apple',
        ));
        $clean = $this->plugin->sanitize_settings(array(
            'feeds' => "https://example.com/android.xml | Android desk\nhttps://example.com/apple.xml | Apple desk",
            'pin_to_homepage' => 1,
        ));
        $this->assertCount(2, $clean['tenants']);
        $this->assertSame('https://example.com/android.xml', $clean['tenants'][0]['url']);
        $this->assertSame('Android desk', $clean['tenants'][0]['name']);
        $this->assertSame(1, $clean['tenants'][0]['enabled']);
        $this->assertSame('https://example.com/apple.xml', $clean['tenants'][1]['url']);
        $this->assertStringContainsString('Android desk', $clean['feeds']);

        $saved = $this->plugin->sanitize_settings(array(
            'tenants' => array(
                array(
                    'url' => 'https://example.com/android.xml',
                    'name' => 'Android desk',
                    'enabled' => 1,
                    'pin_to_homepage' => 1,
                    'map_terms' => array((int) $android->term_id),
                    'map_auto_desk' => 0,
                ),
                array(
                    'url' => 'https://example.com/apple.xml',
                    'name' => 'Apple desk',
                    'enabled' => 1,
                    'pin_to_homepage' => 0,
                    'map_terms' => array((int) $apple->term_id),
                    'map_auto_desk' => 0,
                ),
                array(
                    'url' => 'https://example.com/off.xml',
                    'name' => 'Paused',
                    'enabled' => 0,
                ),
            ),
        ));
        $this->assertCount(3, $saved['tenants']);
        $enabled = $this->invoke_private('enabled_tenants', array($saved));
        $this->assertCount(2, $enabled);
        $android_s = $this->invoke_private('settings_for_item', array(array('feed_url' => 'https://example.com/android.xml'), $saved));
        $apple_s = $this->invoke_private('settings_for_item', array(array('feed_url' => 'https://example.com/apple.xml'), $saved));
        $this->assertSame(1, $android_s['pin_to_homepage']);
        $this->assertSame(array((int) $android->term_id), $android_s['map_terms']);
        $this->assertSame(0, $apple_s['pin_to_homepage']);
        $this->assertSame(array((int) $apple->term_id), $apple_s['map_terms']);
    }

    public function test_disabled_tenant_is_not_ingested() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['max_posts_per_day'] = 5;
        $settings['tenants'] = array(
            array(
                'id' => 'offtenant',
                'url' => 'https://example.com/off-feed.xml',
                'name' => 'Off tenant',
                'enabled' => 0,
                'pin_to_homepage' => 1,
                'map_post_type' => 'post',
                'map_taxonomy' => 'category',
                'map_terms' => array(),
                'map_auto_desk' => 1,
                'map_post_status' => 'auto',
                'map_title' => 'rewrite',
                'map_date' => 'now',
                'map_content' => 'rewrite',
            ),
        );
        $settings['feeds'] = 'https://example.com/off-feed.xml | Off tenant';
        update_option(TechnoRapper_AI_Wire::OPTION, $settings, false);
        $fetched = false;
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$fetched) {
            if (strpos($url, 'off-feed.xml') !== false) {
                $fetched = true;
            }
            return $preempt;
        }, 10, 3);
        $summary = $this->plugin->process_feeds(array('foreground' => true, 'reason' => 'manual'));
        $this->assertFalse($fetched);
        $this->assertSame(0, (int) $summary['feeds_checked']);
        $this->assertSame(0, (int) $summary['new_items']);
    }

    public function test_writer_and_reviewer_prompts_include_google_ranking_steps() {
        $writer = $this->invoke_private('writer_prompt', array('A'));
        $reviewer = $this->invoke_private('reviewer_prompt', array(TechnoRapper_AI_Wire::defaults()));
        foreach (array($writer, $reviewer) as $prompt) {
            $this->assertStringContainsString('brief', $prompt);
            $this->assertStringContainsString('focus_keyphrase', str_replace('focus keyphrase', 'focus_keyphrase', $prompt));
            $this->assertStringContainsString('slug', $prompt);
            $this->assertStringContainsString('internal', $prompt);
        }
        $this->assertStringContainsString('India', $writer);
        $this->assertStringContainsString('search_intent', $writer);
        $this->assertStringContainsString('featured_image_alt', $writer);
        $this->assertStringContainsString('qualcomm-data-center-push', $writer);
        $this->assertStringContainsString('key_takeaways', $writer);
        $this->assertStringContainsString('faq', $writer);
        $this->assertStringContainsString('featured snippet', $writer);
        $this->assertStringContainsString('editorial desk', $writer);
        $this->assertStringContainsString('FAQ', $reviewer);
        $this->assertStringContainsString('85-92', $reviewer);
        $this->assertStringContainsString('Do not deduct below 85', $reviewer);
        $this->assertStringContainsString('fact_flags must be an empty array', $reviewer);
        $this->assertStringContainsString('do not park a publishable article at 78-84', strtolower($reviewer));
        $this->assertStringContainsString('labeled analysis', strtolower($writer));
        $this->assertStringContainsString('none were supplied', $reviewer);
        $this->assertStringContainsString('that is not a failure', $writer);
        $this->assertStringContainsString('Never use News or Technology', $writer);
        $this->assertStringContainsString('Site categories', $writer);
        $this->assertStringContainsString('Do not wait for a human', $writer);
    }

    public function test_review_input_does_not_punish_missing_internal_links() {
        $input = $this->invoke_private('build_review_input', array(
            array(
                'source_title' => 'A useful technology update',
                'source_url' => 'https://example.com/story',
                'source_body' => 'Source body',
            ),
            array('title' => 'A useful technology update'),
        ));
        $this->assertStringContainsString('none supplied. Do not lower the score', $input);
        $this->assertStringContainsString('Publish gate: 85', $input);
        $this->assertStringContainsString('fact_flags as []', $input);
    }

    public function test_writer_input_and_draft_include_internal_links() {
        $related = self::factory()->post->create_and_get(array(
            'post_status' => 'publish',
            'post_title' => 'Older chipset explainer',
            'post_name' => 'older-chipset-explainer',
        ));
        $item = array(
            'feed_name' => 'Example Feed',
            'source_title' => 'A useful technology update',
            'source_url' => 'https://example.com/story',
            'source_body' => 'Source body',
        );
        $input = $this->invoke_private('build_writer_input', array($item));
        $this->assertStringContainsString(get_permalink($related), $input);
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-links',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        ), array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        ), array('verdict' => 'pass'), $this->draft_settings()));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $this->assertStringContainsString(get_permalink($related), $posts[0]->post_content);
        $this->assertStringContainsString('Related on TechnoRapper', $posts[0]->post_content);
        $this->assertStringContainsString('TechnoRapper editorial desk', $posts[0]->post_content);
        $this->assertStringContainsString('Key takeaways', $posts[0]->post_content);
        $this->assertStringContainsString('Frequently asked questions', $posts[0]->post_content);
        $this->assertNotEmpty(get_post_meta($posts[0]->ID, '_tr_ai_wire_faq', true));
    }

    public function test_source_image_url_reads_https_img() {
        $url = $this->invoke_private('source_image_url', array('<p>Hi <img src="https://cdn.example.com/photo.jpg" alt="x"></p>'));
        $this->assertSame('https://cdn.example.com/photo.jpg', $url);
    }

    public function test_rewrite_seo_gate_can_hold_an_item_before_review() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'reviewed',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'pass',
                'score' => 90,
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            )),
        ), array('id' => $id));
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['seo_min_score'] = 101;
        $this->invoke_private('process_item', array($id, $settings));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id, error_text, rewrite_json FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
        $this->assertStringContainsString('Held after rewrite SEO tests', (string) $row['error_text']);
        $this->assertStringContainsString('SEO score', (string) $row['error_text']);
        $rewrite = json_decode($row['rewrite_json'], true);
        $this->assertSame('Visibility: Good!', $rewrite['seo']['visibility_label']);
        $this->assertSame('Focus Keyphrase: useful technology update', $rewrite['seo']['keyphrase_label']);
        $this->assertSame('Social: Social markup ready!', $rewrite['seo']['social_label']);
    }

    public function test_readability_errors_do_not_block_auto_publish() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'rewritten',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
                'seo' => array(
                    'seo_score' => 90,
                    'visibility' => 'Good',
                    'has_keyphrase' => true,
                    'has_social' => true,
                    'readability_errors' => array('a', 'b', 'c', 'd'),
                ),
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'pass',
                'score' => 90,
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            )),
        ), array('id' => $id));
        $this->invoke_private('process_item', array($id, TechnoRapper_AI_Wire::defaults()));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('published', $row['status']);
        $this->assertGreaterThan(0, (int) $row['wp_post_id']);
    }

    public function test_retry_publishes_a_quarantined_seo_hold_when_auto_publish_is_on() {
        $id = $this->create_queue_item();
        $item = array(
            'status' => 'quarantined',
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-force',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        );
        $rewrite = array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        );
        $this->assertTrue($this->invoke_private('should_force_publish_retry', array($item, $rewrite, null, TechnoRapper_AI_Wire::defaults())));
        $this->invoke_private('force_publish_item', array($id, $item, $rewrite, null, TechnoRapper_AI_Wire::defaults()));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('published', $row['status']);
        $this->assertGreaterThan(0, (int) $row['wp_post_id']);
    }

    public function test_retry_does_not_publish_below_min_review_score() {
        $item = array(
            'status' => 'quarantined',
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-low-score',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        );
        $rewrite = array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        );
        $review = array(
            'verdict' => 'revise',
            'score' => 12,
            'policy_flags' => array(),
            'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
        );
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['draft_min_score'] = 70;
        $this->assertFalse($this->invoke_private('should_force_publish_retry', array($item, $rewrite, $review, $settings)));
    }

    public function test_retry_rescores_a_score_and_fact_flag_hold() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['draft_min_score'] = 85;
        $settings['draft_block_fact_flags'] = 1;
        update_option(TechnoRapper_AI_Wire::OPTION, $settings, false);
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'quarantined',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'pass',
                'score' => 84,
                'fact_flags' => array('Could not independently verify the launch date'),
                'policy_flags' => array(),
                'pragmatism' => array(
                    'is_new' => true,
                    'is_useful' => true,
                    'is_grounded' => true,
                    'similarity_risk' => 'low',
                ),
            )),
            'error_text' => 'Held for editorial review: score 84 < 85, fact flags',
        ), array('id' => $id));
        $calls = 0;
        $callback = function ($preempt, $args, $url) use (&$calls) {
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            $calls++;
            $payload = json_decode($args['body'], true);
            $system = $payload['input'][0]['content'][0]['text'] ?? '';
            $user = $payload['input'][1]['content'][0]['text'] ?? '';
            $this->assertStringContainsString('fact_flags must be an empty array', $system);
            $this->assertStringContainsString('Publish gate: 85', $user);
            return array(
                'headers' => array(),
                'body' => wp_json_encode(array(
                    'output' => array(array(
                        'type' => 'message',
                        'content' => array(array(
                            'type' => 'output_text',
                            'text' => wp_json_encode(array(
                                'verdict' => 'pass',
                                'score' => 88,
                                'fact_flags' => array(),
                                'policy_flags' => array(),
                                'pragmatism' => array(
                                    'is_new' => true,
                                    'is_useful' => true,
                                    'is_grounded' => true,
                                    'similarity_risk' => 'low',
                                ),
                            )),
                        )),
                    )),
                    'usage' => array('input_tokens' => 20, 'output_tokens' => 30),
                )),
                'response' => array('code' => 200, 'message' => 'OK'),
                'cookies' => array(),
                'filename' => null,
            );
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $this->invoke_private('retry_queue_item', array($id));
        remove_filter('pre_http_request', $callback, 10);
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id, review_json FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame(1, $calls);
        $this->assertSame('published', $row['status']);
        $this->assertGreaterThan(0, (int) $row['wp_post_id']);
        $review = json_decode($row['review_json'], true);
        $this->assertSame(88, (int) $review['score']);
        $this->assertSame(array(), $review['fact_flags']);
    }

    public function test_retry_publishes_a_clearing_score_without_another_review_call() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['draft_min_score'] = 85;
        $settings['draft_block_fact_flags'] = 1;
        update_option(TechnoRapper_AI_Wire::OPTION, $settings, false);
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'quarantined',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'pass',
                'score' => 90,
                'fact_flags' => array(),
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            )),
        ), array('id' => $id));
        $calls = 0;
        $callback = function ($preempt, $args, $url) use (&$calls) {
            if (strpos($url, 'api.openai.com/v1/responses') !== false) {
                $calls++;
            }
            return $preempt;
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $this->invoke_private('retry_queue_item', array($id));
        remove_filter('pre_http_request', $callback, 10);
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame(0, $calls);
        $this->assertSame('published', $row['status']);
        $this->assertGreaterThan(0, (int) $row['wp_post_id']);
    }

    public function test_run_keeps_a_quarantined_score_hold() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'quarantined',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'revise',
                'score' => 12,
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            )),
            'error_text' => 'Held for editorial review: score 12 < 70',
        ), array('id' => $id));
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['draft_min_score'] = 70;
        $this->invoke_private('process_item', array($id, $settings));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
    }

    public function test_auto_publish_creates_a_live_post() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'reviewed',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'pass',
                'score' => 90,
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            )),
        ), array('id' => $id));
        $this->invoke_private('process_item', array($id, TechnoRapper_AI_Wire::defaults()));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('published', $row['status']);
        $this->assertGreaterThan(0, (int) $row['wp_post_id']);
        $this->assertSame('publish', get_post_status((int) $row['wp_post_id']));
    }

    public function test_auto_publish_promotes_an_existing_draft() {
        $id = $this->create_queue_item();
        $item = array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-promote',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        );
        $rewrite = array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        );
        $this->invoke_private('create_draft', array($id, $item, $rewrite, array('verdict' => 'pass'), $this->draft_settings()));
        $post_id = (int) get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ))[0];
        $this->assertSame('draft', get_post_status($post_id));
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['auto_publish'] = 1;
        $this->invoke_private('create_draft', array($id, $item, $rewrite, array('verdict' => 'pass'), $settings));
        $this->assertSame('publish', get_post_status($post_id));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('published', $row['status']);
        $this->assertSame((string) $post_id, (string) $row['wp_post_id']);
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
    }

    public function test_daily_cap_counts_auto_published_posts() {
        $id = $this->create_queue_item();
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['max_posts_per_day'] = 1;
        $settings['auto_publish'] = 1;
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-cap',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        ), array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        ), array('verdict' => 'pass'), $settings));
        $this->assertSame(0, $this->invoke_private('remaining_posts_today', array($settings)));
    }

    public function test_foreground_process_item_completes_review_in_one_step() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $id = $this->create_queue_item('new');
        $calls = 0;
        $callback = function ($preempt, $args, $url) use (&$calls) {
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            $calls++;
            $output = $calls === 1 ? array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            ) : array(
                'verdict' => 'pass',
                'score' => 90,
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            );
            return array(
                'headers' => array(),
                'body' => wp_json_encode(array(
                    'output' => array(array(
                        'type' => 'message',
                        'content' => array(array('type' => 'output_text', 'text' => wp_json_encode($output))),
                    )),
                    'usage' => array('input_tokens' => 20, 'output_tokens' => 30),
                )),
                'response' => array('code' => 200, 'message' => 'OK'),
                'cookies' => array(),
                'filename' => null,
            );
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $this->invoke_private('process_item', array($id, $this->draft_settings(), true));
        remove_filter('pre_http_request', $callback, 10);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('draft', $row['status']);
        $this->assertGreaterThan(0, (int) $row['wp_post_id']);
        $this->assertSame(2, $calls);
    }

    public function test_process_feeds_records_why_a_manual_run_did_nothing() {
        delete_option(TechnoRapper_AI_Wire::API_KEY_OPTION);
        $summary = $this->plugin->process_feeds(array('foreground' => true, 'reason' => 'manual'));
        $this->assertSame('No API key is configured.', $summary['skipped']);
        $stored = get_option(TechnoRapper_AI_Wire::LAST_RUN_OPTION);
        $this->assertSame('No API key is configured.', $stored['skipped']);
    }

    public function test_new_feed_items_are_processed_before_old_quarantined_items() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $old_id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'quarantined',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'Old quarantined item',
                'lede' => 'Old lede.',
                'body_html' => '<p>Old body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'reject',
                'score' => 10,
                'policy_flags' => array('hate'),
            )),
        ), array('id' => $old_id));

        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['feeds'] = 'https://example.com/tech-feed.xml | Example Tech';
        $settings['max_posts_per_day'] = 5;
        $settings['max_items_per_run'] = 1;
        $settings['auto_publish'] = 1;
        $settings['monthly_budget_usd'] = 50;
        update_option(TechnoRapper_AI_Wire::OPTION, $settings, false);

        $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>Example Tech</title><item><title>New chip announcement</title><link>https://example.com/new-chip</link><guid>https://example.com/new-chip</guid><description><![CDATA[A grounded paragraph about a chip announcement with enough source text for the plugin. <img src="https://cdn.example.com/photo.jpg" alt="chip" />]]></description><enclosure url="https://cdn.example.com/photo.jpg" type="image/jpeg" /></item></channel></rss>';
        $calls = 0;
        $callback = function ($preempt, $args, $url) use (&$calls, $rss) {
            if (strpos($url, 'example.com/tech-feed.xml') !== false) {
                return array(
                    'headers' => array('content-type' => 'application/rss+xml'),
                    'body' => $rss,
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            $calls++;
            $output = $calls === 1 ? array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            ) : array(
                'verdict' => 'pass',
                'score' => 90,
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            );
            return array(
                'headers' => array(),
                'body' => wp_json_encode(array(
                    'output' => array(array(
                        'type' => 'message',
                        'content' => array(array('type' => 'output_text', 'text' => wp_json_encode($output))),
                    )),
                    'usage' => array('input_tokens' => 20, 'output_tokens' => 30),
                )),
                'response' => array('code' => 200, 'message' => 'OK'),
                'cookies' => array(),
                'filename' => null,
            );
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $summary = $this->plugin->process_feeds(array('foreground' => true, 'reason' => 'manual'));
        remove_filter('pre_http_request', $callback, 10);

        $this->assertSame('', (string) ($summary['skipped'] ?? ''));
        $this->assertSame(1, (int) $summary['new_items']);
        $new = $wpdb->get_row("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE source_url = 'https://example.com/new-chip'", ARRAY_A);
        $this->assertNotEmpty($new);
        $this->assertSame('published', $new['status']);
        $this->assertGreaterThan(0, (int) $new['wp_post_id']);
        $old = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $old_id), ARRAY_A);
        $this->assertSame('quarantined', $old['status']);
    }

    public function test_atom_feed_queues_every_entry_not_just_the_first() {
        $atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Example Atom</title>'
            . '<entry><title>First Atom story</title><link rel="alternate" href="https://example.com/atom-1"/><id>tag:example.com,2026:1</id><summary>First body with enough source text for the plugin to accept.</summary></entry>'
            . '<entry><title>Second Atom story</title><link rel="alternate" href="https://example.com/atom-2"/><id>tag:example.com,2026:2</id><summary>Second body with enough source text for the plugin to accept.</summary></entry>'
            . '<entry><title>Third Atom story</title><link rel="alternate" href="https://example.com/atom-3"/><id>tag:example.com,2026:3</id><summary>Third body with enough source text for the plugin to accept.</summary></entry>'
            . '</feed>';
        $parsed = $this->invoke_private('parse_feed_xml', array($atom));
        $this->assertCount(3, $parsed);
        $this->assertSame('https://example.com/atom-1', $parsed[0]['url']);
        $this->assertSame('https://example.com/atom-2', $parsed[1]['url']);
        $this->assertSame('https://example.com/atom-3', $parsed[2]['url']);

        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['feeds'] = 'https://example.com/atom-feed.xml | Example Atom';
        $settings['max_posts_per_day'] = 5;
        $settings['max_items_per_run'] = 1;
        $settings['monthly_budget_usd'] = 50;
        update_option(TechnoRapper_AI_Wire::OPTION, $settings, false);
        $callback = function ($preempt, $args, $url) use ($atom) {
            if (strpos($url, 'example.com/atom-feed.xml') !== false) {
                return array(
                    'headers' => array('content-type' => 'application/atom+xml'),
                    'body' => $atom,
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            if (strpos($url, 'api.openai.com/v1/responses') !== false) {
                return array(
                    'headers' => array(),
                    'body' => wp_json_encode(array(
                        'output' => array(array(
                            'type' => 'message',
                            'content' => array(array('type' => 'output_text', 'text' => wp_json_encode(array(
                                'title' => 'A useful technology update',
                                'lede' => 'A concise summary.',
                                'body_html' => '<p>Grounded article body.</p>',
                            )))),
                        )),
                        'usage' => array('input_tokens' => 20, 'output_tokens' => 30),
                    )),
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            return $preempt;
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $summary = $this->plugin->process_feeds(array('foreground' => true, 'reason' => 'manual'));
        remove_filter('pre_http_request', $callback, 10);
        $this->assertSame(3, (int) $summary['new_items']);
        global $wpdb;
        $urls = $wpdb->get_col("SELECT source_url FROM {$wpdb->prefix}tr_ai_wire_items WHERE source_url LIKE 'https://example.com/atom-%' ORDER BY source_url ASC");
        $this->assertSame(array('https://example.com/atom-1', 'https://example.com/atom-2', 'https://example.com/atom-3'), $urls);
    }

    private function insert_scored_queue_item($status, $score, $seo_score, $created_at) {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'tr_ai_wire_items', array(
            'source_key' => hash('sha256', wp_generate_uuid4()),
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'feed_url' => 'https://example.com/feed.xml',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-' . wp_generate_uuid4(),
            'source_url' => 'https://example.com/' . wp_generate_uuid4(),
            'source_title' => 'Scored story',
            'source_body' => 'Source body',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'Scored story',
                'seo' => array('seo_score' => $seo_score),
            )),
            'review_json' => wp_json_encode(array('verdict' => 'pass', 'score' => $score)),
            'status' => $status,
            'created_at' => $created_at,
            'updated_at' => $created_at,
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
        $this->assertNotFalse($ok);
        return (int) $wpdb->insert_id;
    }

    public function test_score_report_averages_posts_by_day_and_month() {
        $this->insert_scored_queue_item('published', 80, 70, '2026-11-01 10:00:00');
        $this->insert_scored_queue_item('published', 60, 50, '2026-11-01 12:00:00');
        $this->insert_scored_queue_item('draft', 90, 80, '2026-11-02 09:00:00');
        $this->insert_scored_queue_item('quarantined', 20, 10, '2026-11-02 11:00:00');

        $day = $this->invoke_private('score_report', array(array(
            'range' => 'all',
            'group' => 'day',
            'scope' => 'posts',
            'now' => '2026-11-05 12:00:00',
        )));
        $this->assertSame(3, $day['count']);
        $this->assertSame(76.7, $day['average']);
        $this->assertSame(66.7, $day['seo_average']);
        $this->assertSame(3, $day['above_min']);
        $this->assertSame(1, $day['distribution']['50-69']);
        $this->assertSame(1, $day['distribution']['70-84']);
        $this->assertSame(1, $day['distribution']['85-100']);

        $by_label = array();
        foreach ($day['series'] as $point) {
            $by_label[$point['label']] = $point;
        }
        $this->assertSame(2, $by_label['2026-11-01']['count']);
        $this->assertSame(70.0, $by_label['2026-11-01']['average']);
        $this->assertSame(1, $by_label['2026-11-02']['count']);
        $this->assertSame(90.0, $by_label['2026-11-02']['average']);

        $month = $this->invoke_private('score_report', array(array(
            'range' => 'month',
            'group' => 'month',
            'scope' => 'posts',
            'now' => '2026-11-05 12:00:00',
        )));
        $this->assertSame(3, $month['count']);
        $this->assertSame(76.7, $month['average']);
        $this->assertSame(3, $month['series'][0]['count']);

        $published = $this->invoke_private('score_report', array(array(
            'range' => 'all',
            'group' => 'day',
            'scope' => 'published',
            'now' => '2026-11-05 12:00:00',
        )));
        $this->assertSame(2, $published['count']);
        $this->assertSame(70.0, $published['average']);

        $chart = $this->invoke_private('render_score_chart', array($day));
        $this->assertStringContainsString('Average review score chart', $chart);
        $this->assertStringContainsString('2026-11-01', $chart);
        $this->assertStringContainsString('polyline', $chart);
    }

    public function test_score_report_range_excludes_older_days() {
        $this->insert_scored_queue_item('published', 40, 40, '2026-10-01 10:00:00');
        $this->insert_scored_queue_item('published', 80, 80, '2026-11-04 10:00:00');
        $week = $this->invoke_private('score_report', array(array(
            'range' => '7d',
            'group' => 'day',
            'scope' => 'posts',
            'now' => '2026-11-05 12:00:00',
        )));
        $this->assertSame(1, $week['count']);
        $this->assertSame(80.0, $week['average']);
        $this->assertSame(0, $week['distribution']['0-49']);
    }

    public function test_stop_running_jobs_releases_the_lock() {
        $token = wp_generate_uuid4();
        update_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION, array(
            'created_at' => time(),
            'token' => $token,
        ), false);
        $this->assertTrue($this->plugin->is_run_in_progress());
        $this->assertTrue($this->plugin->stop_running_jobs());
        $this->assertFalse($this->plugin->is_run_in_progress());
        $this->assertFalse($this->invoke_private('refresh_processing_lock', array($token)));
        $last = get_option(TechnoRapper_AI_Wire::LAST_RUN_OPTION);
        $this->assertSame('stopped', $last['reason']);
        $this->assertSame('Stopped by administrator.', $last['skipped']);
    }

    public function test_delete_queue_item_removes_only_that_row() {
        $keep = $this->create_queue_item();
        $gone = $this->create_queue_item();
        $this->assertTrue($this->plugin->delete_queue_item($gone));
        $this->assertEmpty($this->invoke_private('get_item', array($gone)));
        $this->assertNotEmpty($this->invoke_private('get_item', array($keep)));
    }

    public function test_clean_unfinished_keeps_published_queue_rows() {
        $open = $this->create_queue_item();
        $done = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array('status' => 'published'), array('id' => $done));
        $deleted = $this->plugin->clean_queue('unfinished');
        $this->assertSame(1, $deleted);
        $this->assertEmpty($this->invoke_private('get_item', array($open)));
        $kept = $this->invoke_private('get_item', array($done));
        $this->assertSame('published', $kept['status']);
    }

    public function test_clean_board_empties_the_queue() {
        $this->create_queue_item();
        $this->create_queue_item();
        update_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION, array(
            'created_at' => time(),
            'token' => wp_generate_uuid4(),
        ), false);
        $deleted = $this->plugin->clean_queue('all');
        $this->assertSame(2, $deleted);
        $this->assertFalse($this->plugin->is_run_in_progress());
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_items");
        $this->assertSame(0, $count);
        $last = get_option(TechnoRapper_AI_Wire::LAST_RUN_OPTION);
        $this->assertSame('cleaned', $last['reason']);
    }
}
