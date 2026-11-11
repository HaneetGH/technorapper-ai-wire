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
        delete_option(TechnoRapper_AI_Wire::GEMINI_API_KEY_OPTION);
        delete_option(TechnoRapper_AI_Wire::PROCESSING_LOCK_OPTION);
        delete_option(TechnoRapper_AI_Wire::JOB_OPTION);
        delete_option(TechnoRapper_AI_Wire::JOB_LOCK_OPTION);
        wp_clear_scheduled_hook(TechnoRapper_AI_Wire::JOB_HOOK);
        delete_option(TechnoRapper_AI_Wire::LAST_RUN_OPTION);
        unset($_SERVER['REMOTE_ADDR']);
        add_filter('tr_ai_wire_moderation_result', array($this, 'allow_moderation_in_tests'), 10, 2);
        add_filter('pre_http_request', array($this, 'stub_source_image_http'), 1, 3);
    }

    public function stub_source_image_http($preempt, $args, $url) {
        if ($preempt) {
            return $preempt;
        }
        if (strpos((string) $url, 'cdn.example.com/photo') === false) {
            return $preempt;
        }
        $jpeg = $this->fixture_jpeg_bytes();
        if ($jpeg === '') {
            return $preempt;
        }
        return array(
            'headers' => array(
                'content-type' => 'image/jpeg',
            ),
            'body' => $jpeg,
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    }

    private function fixture_jpeg_bytes() {
        $bytes = $this->tiny_jpeg_bytes();
        if (is_string($bytes) && $bytes !== '') {
            return $bytes;
        }
        if (!defined('DIR_TESTDATA')) {
            return '';
        }
        foreach (array('/images/test-image.jpg', '/images/canola.jpg') as $rel) {
            $path = DIR_TESTDATA . $rel;
            if (is_readable($path)) {
                $data = file_get_contents($path);
                if (is_string($data) && $data !== '') {
                    return $data;
                }
            }
        }
        return '';
    }

    private function assert_featured_image_is_hosted($post_id, $source_url = 'https://cdn.example.com/photo.jpg') {
        $post_id = (int) $post_id;
        $thumb = (int) get_post_thumbnail_id($post_id);
        $this->assertGreaterThan(0, $thumb);
        $hosted = wp_get_attachment_url($thumb);
        $this->assertNotFalse($hosted);
        $this->assertStringContainsString('/wp-content/uploads/', $hosted);
        $this->assertStringNotContainsString('cdn.example.com', $hosted);
        $this->assertSame($hosted, $this->invoke_private('display_image_url', array($post_id)));
        $thumb_url = get_the_post_thumbnail_url($post_id);
        $this->assertNotFalse($thumb_url);
        $this->assertStringContainsString('/wp-content/uploads/', $thumb_url);
        $this->assertStringNotContainsString('cdn.example.com', $thumb_url);
        $html = get_the_post_thumbnail($post_id);
        $this->assertStringContainsString('/wp-content/uploads/', $html);
        $this->assertStringNotContainsString('cdn.example.com', $html);
        $this->assertSame($hosted, get_post_meta($post_id, 'rank_math_facebook_image', true));
        $this->assertSame($hosted, get_post_meta($post_id, 'rank_math_twitter_image', true));
        $this->assertSame($hosted, get_post_meta($post_id, '_yoast_wpseo_opengraph-image', true));
        $this->assertSame($hosted, get_post_meta($post_id, '_yoast_wpseo_twitter-image', true));
        $this->assertSame($hosted, get_post_meta($post_id, '_aioseo_og_image', true));
        $this->assertSame($source_url, get_post_meta($post_id, '_tr_ai_wire_source_image', true));
        $this->assertSame('1', (string) get_post_meta($thumb, '_tr_ai_wire_hosted_source_image', true));
        $this->assertSame($post_id, (int) get_post($thumb)->post_parent);
    }

    public function allow_moderation_in_tests($result, $text) {
        if (is_array($result)) {
            return $result;
        }
        return array('flagged' => false, 'categories' => array());
    }

    public function tear_down() {
        remove_all_filters('pre_http_request');
        remove_all_filters('tr_ai_wire_moderation_result');
        delete_option(TechnoRapper_AI_Wire::API_KEY_OPTION);
        delete_option(TechnoRapper_AI_Wire::GEMINI_API_KEY_OPTION);
        delete_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION);
        delete_option(TechnoRapper_AI_Wire::SOCIAL_BATCH_OPTION);
        delete_option(TechnoRapper_AI_Wire::SOCIAL_MIX_OPTION . '_' . gmdate('Ymd'));
        delete_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION);
        delete_option(TechnoRapper_AI_Wire::SOCIAL_IDEAS_OPTION);
        delete_option('tr_ai_wire_usage_' . gmdate('Ym'));
        delete_option('tr_ai_wire_social_image_usage_' . gmdate('Ym'));
        delete_option('tr_ai_wire_social_video_usage_' . gmdate('Ym'));
        delete_option(TechnoRapper_AI_Wire::FAL_API_KEY_OPTION);
        delete_option('tr_ai_wire_social_worker_lock');
        remove_all_filters('tr_ai_wire_ffmpeg_path');
        remove_all_filters('tr_ai_wire_fal_poll_seconds');
        remove_all_filters('tr_ai_wire_youtube_poll_seconds');
        wp_clear_scheduled_hook(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_CRON_HOOK);
        wp_clear_scheduled_hook(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        parent::tear_down();
    }

    private function invoke_private($method, $args = array()) {
        $reflection = new ReflectionMethod('TechnoRapper_AI_Wire', $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($this->plugin, $args);
    }

    private function onboard_social($platforms = array('x')) {
        $credentials = array(
            'x_user_access_token' => in_array('x', $platforms, true) ? 'x-token' : '',
            'instagram_user_access_token' => in_array('instagram', $platforms, true) ? 'ig-token' : '',
            'instagram_user_id' => in_array('instagram', $platforms, true) ? '17841400000000000' : '',
            'youtube_refresh_token' => in_array('youtube', $platforms, true) ? 'yt-refresh' : '',
            'youtube_access_token' => in_array('youtube', $platforms, true) ? 'yt-token' : '',
            'youtube_access_expires' => in_array('youtube', $platforms, true) ? (string) (time() + 3600) : '',
            'youtube_channel_id' => in_array('youtube', $platforms, true) ? 'UCtestchannel' : '',
        );
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_x_enabled' => in_array('x', $platforms, true) ? 1 : 0,
            'social_instagram_enabled' => in_array('instagram', $platforms, true) ? 1 : 0,
            'social_youtube_enabled' => in_array('youtube', $platforms, true) ? 1 : 0,
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 10,
        )), false);
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, $credentials, false);
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
    }

    private function openai_json_http($payload) {
        return array(
            'headers' => array(),
            'body' => wp_json_encode(array(
                'output' => array(array(
                    'type' => 'message',
                    'content' => array(array('type' => 'output_text', 'text' => wp_json_encode($payload))),
                )),
                'usage' => array('input_tokens' => 20, 'output_tokens' => 30),
            )),
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    }

    private function tiny_jpeg_bytes() {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return '';
        }
        $im = imagecreatetruecolor(64, 96);
        imagefilledrectangle($im, 0, 0, 63, 95, imagecolorallocate($im, 9, 20, 43));
        ob_start();
        imagejpeg($im, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($im);
        return is_string($bytes) ? $bytes : '';
    }

    private function mock_http_ok($body) {
        return array(
            'headers' => array(),
            'body' => is_string($body) ? $body : wp_json_encode($body),
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    }

    private function instagram_account_probe_ok() {
        return $this->mock_http_ok(array('id' => '17841400000000000', 'username' => 'techno.rapper'));
    }

    private function stub_http_for_social_delete(&$deleted_urls) {
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$deleted_urls) {
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            $url = (string) $url;
            if ($method === 'DELETE') {
                $deleted_urls[] = $url;
                if (strpos($url, 'graph.facebook.com') !== false || strpos($url, 'api.x.com/2/tweets/') !== false) {
                    return $this->mock_http_ok(array('success' => true));
                }
            }
            if (strpos($url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            return $preempt;
        }, 5, 3);
    }

    private function graph_error_http($message, $http = 400) {
        return array(
            'headers' => array(),
            'body' => wp_json_encode(array(
                'error' => array(
                    'message' => $message,
                    'type' => 'OAuthException',
                    'code' => 100,
                ),
            )),
            'response' => array('code' => $http, 'message' => 'Bad Request'),
            'cookies' => array(),
            'filename' => null,
        );
    }

    private function draft_settings() {
        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['auto_publish'] = 0;
        return $settings;
    }

    private function fire_social_publish($post_id, $old_status = 'draft') {
        $this->plugin->queue_social_for_post('publish', $old_status, get_post($post_id));
    }

    private function attach_review_score($post_id, $score) {
        $post_id = (int) $post_id;
        $qid = (int) get_post_meta($post_id, '_tr_ai_wire_queue_id', true);
        $item = $qid > 0 ? $this->invoke_private('get_item', array($qid)) : null;
        if (!is_array($item)) {
            $qid = $this->create_queue_item('published');
            update_post_meta($post_id, '_tr_ai_wire_queue_id', $qid);
        }
        $this->invoke_private('update_item', array($qid, array(
            'status' => 'published',
            'wp_post_id' => $post_id,
            'review_json' => wp_json_encode(array(
                'verdict' => 'pass',
                'score' => (int) $score,
                'safe_to_publish' => true,
            )),
        )));
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
            'Apple announced the M5 chip on March 4, 2026, with shipping in April at $999. The chip raises memory bandwidth and adds an on-device model for photos. Developers can use the new API in the beta released the same day. Source body <img src="https://cdn.example.com/photo.jpg" alt="photo">',
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

    public function test_plugin_header_version_is_semver_and_matches_constant() {
        $header = file_get_contents(dirname(__DIR__) . '/technorapper-ai-wire.php');
        $this->assertTrue((bool) preg_match('/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)\s*$/m', $header, $matches));
        $this->assertSame(TechnoRapper_AI_Wire::VERSION, $matches[1]);
        $this->assertMatchesRegularExpression('/^\s*\*\s*License:\s*GPLv2 or later\s*$/m', $header);
        $this->assertMatchesRegularExpression('/^\s*\*\s*License URI:\s*https:\/\/www\.gnu\.org\/licenses\/gpl-2\.0\.html\s*$/m', $header);
        $this->assertDoesNotMatchRegularExpression('/^\s*\*\s*Tested up to:/m', $header);
    }

    public function test_wordpress_org_readme_declares_gplv2_or_later() {
        $readme = file_get_contents(dirname(__DIR__) . '/readme.txt');
        $this->assertNotFalse($readme);
        $this->assertMatchesRegularExpression('/^License:\s*GPLv2 or later\s*$/m', $readme);
        $this->assertMatchesRegularExpression('/^License URI:\s*https:\/\/www\.gnu\.org\/licenses\/gpl-2\.0\.html\s*$/m', $readme);
        $this->assertMatchesRegularExpression('/^Tested up to:\s*7\.1\s*$/m', $readme);
        $this->assertTrue((bool) preg_match('/^Stable tag:\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)\s*$/m', $readme, $matches));
        $this->assertSame(TechnoRapper_AI_Wire::VERSION, $matches[1]);
    }

    public function test_release_package_includes_readme_txt_license() {
        $root = dirname(__DIR__);
        $script = $root . '/scripts/package-plugin.sh';
        $this->assertFileExists($script);
        $dest = sys_get_temp_dir() . '/tr-ai-wire-pack-' . uniqid('', true);
        $cmd = 'bash ' . escapeshellarg($script) . ' ' . escapeshellarg($dest);
        exec($cmd . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertFileExists($dest . '/readme.txt');
        $this->assertFileExists($dest . '/LICENSE');
        $packed = file_get_contents($dest . '/readme.txt');
        $this->assertMatchesRegularExpression('/^License:\s*GPLv2 or later\s*$/m', $packed);
        $tag_workflow = file_get_contents($root . '/.github/workflows/tag-version.yml');
        $this->assertStringContainsString('scripts/package-plugin.sh', $tag_workflow);
        $this->assertStringNotContainsString('cp technorapper-ai-wire.php README.md dist/technorapper-ai-wire/', $tag_workflow);
    }

    public function test_activation_creates_all_required_tables() {
        global $wpdb;
        $expected = array(
            $wpdb->prefix . 'tr_ai_wire_items',
            $wpdb->prefix . 'tr_ai_wire_views',
            $wpdb->prefix . 'tr_ai_wire_view_limits',
            $wpdb->prefix . 'tr_ai_wire_social',
            $wpdb->prefix . 'tr_ai_wire_quarantine',
        );
        foreach ($expected as $table) {
            $this->assert_plugin_table_exists($table);
        }
        $this->assertSame('10', get_option('tr_ai_wire_schema_version'));
        $this->assertNotEmpty($wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}tr_ai_wire_social LIKE 'copy_json'"));
        $this->assertNotEmpty($wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}tr_ai_wire_items LIKE 'source_published_at'"));
        $this->assertNotEmpty($wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}tr_ai_wire_items LIKE 'voice'"));
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
        $this->assertSame('10', get_option('tr_ai_wire_schema_version'));
        $this->assert_plugin_table_exists($wpdb->prefix . 'tr_ai_wire_view_limits');
        $this->assert_plugin_table_exists($wpdb->prefix . 'tr_ai_wire_quarantine');
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
            'story_value' => array(
                'publish' => true,
                'substance' => 'major',
                'reader_demand' => 'high',
                'adds_value' => true,
                'reason' => 'Named chip launch readers will search.',
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
        $this->assertSame('generated', $row['status']);
        $this->assertSame(1, $calls);

        $this->invoke_private('process_item', array($id, $settings));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('reviewed', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
        $this->assertSame(2, $calls);

        $this->invoke_private('process_item', array($id, $settings));
        remove_filter('pre_http_request', $callback, 10);
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('draft', $row['status']);
        $this->assertGreaterThan(0, (int) $row['wp_post_id']);
        $this->assertSame(2, $calls);
        $this->assertSame('draft', get_post_status((int) $row['wp_post_id']));
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
        $this->assertSame('10', get_option('tr_ai_wire_schema_version'));
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

    public function test_writer_prompts_do_not_name_disallowed_categories() {
        $writer = $this->invoke_private('writer_prompt', array('A'));
        $reviewer = $this->invoke_private('reviewer_prompt', array(array()));
        $social = $this->invoke_private('social_copy_user_prompt', array('TechnoRapper', array(), array('title' => 'Chip news')));
        foreach (array($writer, $reviewer, $social) as $prompt) {
            $this->assertStringNotContainsString('self-harm', strtolower($prompt));
            $this->assertStringNotContainsString('illegal advice', strtolower($prompt));
            $this->assertStringNotContainsString('sexual content', strtolower($prompt));
        }
        $this->assertStringContainsString('family-friendly', $writer);
        $this->assertStringNotContainsString('pinch of dry comic sense', $writer);
        $this->assertStringContainsString('do not require jokes', strtolower($reviewer));
        $this->assertStringNotContainsString('One dry wink is enough', $social);
        $comic_writer = $this->invoke_private('writer_prompt', array('A', true));
        $this->assertStringContainsString('pinch of dry comic sense', $comic_writer);
        $comic_social = $this->invoke_private('social_copy_user_prompt', array('TechnoRapper', array(), array('title' => 'Chip news'), true));
        $this->assertStringContainsString('One dry wink is enough', $comic_social);
    }

    public function test_house_style_asks_for_restrained_comic_sense() {
        foreach (array('A', 'B') as $variant) {
            $straight = $this->invoke_private('writer_prompt', array($variant));
            $this->assertStringNotContainsString('pinch of dry comic sense', $straight);
            $writer = $this->invoke_private('writer_prompt', array($variant, true));
            $this->assertStringContainsString('pinch of dry comic sense', $writer);
            $this->assertStringContainsString('Keep that humor restrained', $writer);
            $this->assertStringContainsString('family-friendly', $writer);
            $this->assertStringContainsString('light wry aside', $writer);
        }
        $this->assertStringNotContainsString('pinch of dry comic sense', $this->invoke_private('polish_writer_prompt', array('A')));
        $polish = $this->invoke_private('polish_writer_prompt', array('A', true));
        $this->assertStringContainsString('pinch of dry comic sense', $polish);
        $reviewer = $this->invoke_private('reviewer_prompt', array(array()));
        $this->assertStringContainsString('do not require jokes', strtolower($reviewer));
        $this->assertStringContainsString('Do not list missing humor', $reviewer);
        $this->assertStringContainsString('for missing jokes', $reviewer);
        $cameras = $this->invoke_private('social_image_camera', array(1, 0));
        $this->assertNotSame('', $cameras);
        $straight_photo = $this->invoke_private('social_image_scroll_stop_clause');
        $this->assertStringNotContainsString('sly photoreal visual beat', $straight_photo);
        $prompt = $this->invoke_private('social_image_scroll_stop_clause', array(true));
        $this->assertStringContainsString('sly photoreal visual beat', $prompt);
        $this->assertStringContainsString('Never a drawing or a joke poster', $prompt);
    }

    public function test_comic_daily_limit_fills_then_stays_straight() {
        $settings = array_merge(TechnoRapper_AI_Wire::defaults(), array('comic_daily_limit' => 1));
        $first = $this->create_queue_item();
        $second = $this->create_queue_item();
        $item1 = $this->invoke_private('assign_item_voice', array($first, $this->invoke_private('get_item', array($first)), $settings));
        $this->assertSame('comic', $item1['voice']);
        $again = $this->invoke_private('assign_item_voice', array($first, $item1, $settings));
        $this->assertSame('comic', $again['voice']);
        $item2 = $this->invoke_private('assign_item_voice', array($second, $this->invoke_private('get_item', array($second)), $settings));
        $this->assertSame('straight', $item2['voice']);
        $this->assertSame(1, $this->invoke_private('comic_assigned_today'));
        $off = $this->invoke_private('assign_item_voice', array(
            $this->create_queue_item(),
            array(),
            array_merge(TechnoRapper_AI_Wire::defaults(), array('comic_daily_limit' => 0)),
        ));
        $this->assertSame('straight', $off['voice']);
        $this->assertSame(0, (int) $this->plugin->sanitize_settings(array())['comic_daily_limit']);
        $this->assertSame(3, (int) $this->plugin->sanitize_settings(array('comic_daily_limit' => 3))['comic_daily_limit']);
        $this->assertSame(100, (int) $this->plugin->sanitize_settings(array('comic_daily_limit' => 999))['comic_daily_limit']);
    }

    public function test_existing_rewrite_is_not_relabeled_comic() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'rewrite_json' => wp_json_encode($this->complete_rewrite_for_score()),
        ), array('id' => $id));
        $item = $this->invoke_private('get_item', array($id));
        $assigned = $this->invoke_private('assign_item_voice', array($id, $item, array_merge(TechnoRapper_AI_Wire::defaults(), array('comic_daily_limit' => 10))));
        $this->assertSame('straight', $assigned['voice']);
    }

    public function test_create_draft_stores_comic_voice_meta() {
        $id = $this->create_queue_item();
        $item = array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-comic',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_body' => 'Apple announced the M5 chip on March 4, 2026, with shipping in April at $999. The chip raises memory bandwidth and adds an on-device model for photos. Developers can use the new API in the beta released the same day. Source body <img src="https://cdn.example.com/photo.jpg" alt="photo">',
            'voice' => 'comic',
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
        $this->assertSame('comic', get_post_meta($posts[0]->ID, '_tr_ai_wire_voice', true));
    }

    public function test_flagged_prompt_retries_once_with_a_shorter_source() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $lengths = array();
        $calls = 0;
        $callback = function ($preempt, $args, $url) use (&$calls, &$lengths) {
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            $calls++;
            $payload = json_decode($args['body'], true);
            $user = $payload['input'][1]['content'][0]['text'] ?? '';
            $lengths[] = strlen($user);
            if ($calls === 1) {
                return array(
                    'headers' => array(),
                    'body' => wp_json_encode(array(
                        'error' => array(
                            'message' => 'Invalid prompt: your prompt was flagged as potentially violating our usage policy.',
                            'type' => 'invalid_request_error',
                            'code' => 'invalid_prompt',
                        ),
                    )),
                    'response' => array('code' => 400, 'message' => 'Bad Request'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            return $this->openai_json_http(array('title' => 'Shorter pass', 'body_html' => '<p>Ok</p>'));
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $source = "Feed: Example\nSource title: Chip\nSource URL: https://example.com\nSource text:\n"
            . str_repeat('Chip news. ', 800)
            . "\n\nSite categories — pick one\n- AI";
        $result = $this->invoke_private('ai_json', array(
            'gpt-6-luna',
            'Return JSON.',
            $source,
            TechnoRapper_AI_Wire::defaults(),
            false,
        ));
        remove_filter('pre_http_request', $callback, 10);
        $this->assertSame(2, $calls);
        $this->assertLessThan($lengths[0], $lengths[1]);
        $this->assertTrue(is_array($result));
        $this->assertSame('Shorter pass', $result['title']);
    }

    public function test_flagged_short_prompt_is_not_retried() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $calls = 0;
        $callback = function ($preempt, $args, $url) use (&$calls) {
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            $calls++;
            return array(
                'headers' => array(),
                'body' => wp_json_encode(array(
                    'error' => array(
                        'message' => 'Invalid prompt: your prompt was flagged as potentially violating our usage policy.',
                        'code' => 'invalid_prompt',
                    ),
                )),
                'response' => array('code' => 400, 'message' => 'Bad Request'),
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
        $this->assertSame(1, $calls);
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_api_permanent', $result->get_error_code());
        $this->assertStringContainsString('usage-policy check', $result->get_error_message());
        $this->assertStringContainsString('Gemini API key', $result->get_error_message());
    }

    public function test_flagged_prompt_falls_back_to_gemini_after_the_shorter_excerpt_fails() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        update_option(TechnoRapper_AI_Wire::GEMINI_API_KEY_OPTION, 'gemini-test-key', false);
        $urls = array();
        $callback = function ($preempt, $args, $url) use (&$urls) {
            if (strpos($url, 'api.openai.com/v1/responses') !== false) {
                $urls[] = 'openai';
                return array(
                    'headers' => array(),
                    'body' => wp_json_encode(array(
                        'error' => array(
                            'message' => 'Invalid prompt: your prompt was flagged as potentially violating our usage policy.',
                            'code' => 'invalid_prompt',
                        ),
                    )),
                    'response' => array('code' => 400, 'message' => 'Bad Request'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            if (strpos($url, 'generativelanguage.googleapis.com') !== false) {
                $urls[] = 'gemini';
                $this->assertStringNotContainsString('gemini-test-key', $url);
                $this->assertSame('gemini-test-key', $args['headers']['x-goog-api-key'] ?? '');
                $payload = json_decode($args['body'], true);
                $this->assertSame('application/json', $payload['generationConfig']['responseMimeType'] ?? '');
                $this->assertLessThan(4500, strlen($payload['contents'][0]['parts'][0]['text'] ?? ''));
                return array(
                    'headers' => array(),
                    'body' => wp_json_encode(array(
                        'candidates' => array(array(
                            'content' => array(
                                'parts' => array(array('text' => wp_json_encode(array(
                                    'title' => 'Gemini pass',
                                    'body_html' => '<p>Ok</p>',
                                )))),
                            ),
                        )),
                        'usageMetadata' => array('promptTokenCount' => 40, 'candidatesTokenCount' => 20),
                    )),
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            return $preempt;
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $source = "Feed: Example\nSource title: Chip\nSource URL: https://example.com\nSource text:\n"
            . str_repeat('Chip news. ', 800)
            . "\n\nSite categories — pick one\n- AI";
        $result = $this->invoke_private('ai_json', array(
            'gpt-6-luna',
            'Return JSON.',
            $source,
            TechnoRapper_AI_Wire::defaults(),
            false,
        ));
        remove_filter('pre_http_request', $callback, 10);
        $this->assertSame(array('openai', 'openai', 'gemini'), $urls);
        $this->assertTrue(is_array($result));
        $this->assertSame('Gemini pass', $result['title']);
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
        $this->assertSame(0, $settings['comic_daily_limit']);
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
        $this->assertSame(0, (int) $settings['social_distribution_enabled']);
        $this->assertSame(0, (int) $settings['social_x_enabled']);
        $this->assertSame(0, (int) $settings['social_instagram_enabled']);
        $this->assertSame(0, (int) $settings['social_youtube_enabled']);
        $this->assertSame('28', $settings['social_youtube_category_id']);
        $this->assertSame(0, (int) $settings['social_youtube_notify_subscribers']);
        $this->assertSame('single', $settings['social_post_format']);
        $this->assertSame(5, $settings['social_slide_count']);
        $this->assertSame(3, $settings['social_carousel_daily_limit']);
        $this->assertSame(0, $settings['social_collage_daily_limit']);
        $this->assertSame(2, $settings['social_single_daily_limit']);
        $this->assertSame(0, $settings['social_reel_daily_limit']);
        $this->assertSame(90, (int) $settings['social_min_review_score']);
        $this->assertSame(0, (int) $settings['social_mix_saved']);
        $this->assertSame(array_keys(TechnoRapper_AI_Wire::social_news_formats()), $settings['social_news_formats']);
        $this->assertArrayNotHasKey('social_pack_wait_minutes', $settings);
    }

    public function test_activation_keeps_a_fresh_install_paused() {
        delete_option('tr_ai_wire_daily_350_on');
        delete_option(TechnoRapper_AI_Wire::OPTION);
        TechnoRapper_AI_Wire::activate();
        $saved = wp_parse_args(get_option(TechnoRapper_AI_Wire::OPTION, array()), TechnoRapper_AI_Wire::defaults());
        $this->assertSame(0, (int) $saved['max_posts_per_day']);
        $this->assertSame(0, (int) $saved['social_distribution_enabled']);
        $this->assertSame('manual', $saved['cron_frequency']);
        $this->assertFalse(wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK));
        $this->assertFalse(wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_CRON_HOOK));
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
            'status' => 'reviewed',
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

    public function test_policy_local_hits_catch_profanity_not_class() {
        $this->assertNotEmpty($this->invoke_private('policy_local_hits', array('This is fucking news')));
        $this->assertNotEmpty($this->invoke_private('policy_local_hits', array('This is f*cking news')));
        $this->assertSame(array(), $this->invoke_private('policy_local_hits', array('The assistant class assignment passed on the shell.')));
        $this->assertStringContainsString('family-friendly', $this->invoke_private('writer_prompt', array('A')));
        $this->assertStringNotContainsString('pinch of dry comic sense', $this->invoke_private('writer_prompt', array('A')));
        $this->assertStringContainsString('pinch of dry comic sense', $this->invoke_private('writer_prompt', array('A', true)));
        $guard = $this->invoke_private('policy_guard_text', array('A grounded phone-chip update.', 'social'));
        $this->assertTrue($guard);
    }

    public function test_profane_rewrite_stays_quarantined() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'reviewed',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>This is a fucking disaster for the chip roadmap.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'pass',
                'score' => 90,
                'safe_to_publish' => true,
                'policy_flags' => array(),
                'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
            )),
        ), array('id' => $id));
        $this->invoke_private('process_item', array($id, TechnoRapper_AI_Wire::defaults()));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id, error_text FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
        $this->assertStringContainsString('profanity', (string) $row['error_text']);
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
        $this->assert_featured_image_is_hosted((int) $posts[0]->ID);
        $this->assertCount(1, get_children(array(
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
        $this->assert_featured_image_is_hosted($post_id);
        $this->assertTrue(is_sticky($post_id));
        $this->assertSame('1', get_post_meta($post_id, '_tr_ai_wire_show_on_home', true));
        $this->assertGreaterThan(0, (int) get_post($post_id)->post_author);
    }

    public function test_hosted_image_backfill_does_not_block_wp_admin() {
        delete_option('tr_ai_wire_hosted_images_22');
        delete_option(TechnoRapper_AI_Wire::JOB_OPTION);
        $id = $this->create_queue_item();
        $post_id = self::factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Admin hang check',
            'meta_input' => array(
                '_tr_ai_wire_queue_id' => $id,
                '_tr_ai_wire_source_image' => 'https://cdn.example.com/photo.jpg',
            ),
        ));
        set_current_screen('dashboard');
        $this->plugin->maybe_backfill_home_media();
        $this->assertSame('1', get_option('tr_ai_wire_hosted_images_22'));
        $this->assertSame(0, (int) $this->invoke_private('real_thumbnail_id', array($post_id)));
        $job = get_option(TechnoRapper_AI_Wire::JOB_OPTION, array());
        $this->assertSame('repair', $job['kind'] ?? '');
        $this->assertSame('queued', $job['status'] ?? '');
        unset($GLOBALS['current_screen']);
    }

    public function test_hosted_image_backfill_does_not_run_inline_on_frontend_cron() {
        delete_option('tr_ai_wire_hosted_images_22');
        delete_option(TechnoRapper_AI_Wire::JOB_OPTION);
        $id = $this->create_queue_item();
        $post_id = self::factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Frontend cron hang check',
            'meta_input' => array(
                '_tr_ai_wire_queue_id' => $id,
                '_tr_ai_wire_source_image' => 'https://cdn.example.com/photo.jpg',
            ),
        ));
        $this->plugin->maybe_backfill_home_media();
        $this->assertSame('1', get_option('tr_ai_wire_hosted_images_22'));
        $this->assertSame(0, (int) $this->invoke_private('real_thumbnail_id', array($post_id)));
        $job = get_option(TechnoRapper_AI_Wire::JOB_OPTION, array());
        $this->assertSame('repair', $job['kind'] ?? '');
        $this->assertSame('queued', $job['status'] ?? '');
    }

    public function test_migrate_older_images_hosts_remote_featured_and_body() {
        $id = $this->create_queue_item();
        $post_id = self::factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Old hotlinked article',
            'post_content' => '<p>Read <a href="https://example.com/story">the original</a>. <img src="https://cdn.example.com/photo.jpg" alt="chip"></p><p class="tr-ai-wire-source"><strong>Source:</strong> <a href="https://example.com/story" rel="nofollow noopener" target="_blank">Example Feed</a></p>',
            'meta_input' => array(
                '_tr_ai_wire_queue_id' => $id,
                '_tr_ai_wire_source_image' => 'https://cdn.example.com/photo.jpg',
            ),
        ));
        $this->invoke_private('update_item', array($id, array(
            'rewrite_json' => wp_json_encode(array(
                'title' => 'Old hotlinked article',
                'featured_image_alt' => 'chip photo',
            )),
        )));
        $this->assertSame(0, $this->invoke_private('real_thumbnail_id', array($post_id)));
        $result = $this->plugin->migrate_hosted_images(20);
        $this->assertSame(1, (int) $result['scanned']);
        $this->assertSame(1, (int) $result['hosted']);
        $this->assert_featured_image_is_hosted($post_id, 'https://cdn.example.com/photo.jpg');
        $content = (string) get_post($post_id)->post_content;
        $this->assertStringContainsString('/wp-content/uploads/', $content);
        $this->assertStringNotContainsString('<img src="https://cdn.example.com/photo.jpg"', $content);
        $this->assertStringNotContainsString('<a href="https://example.com/story">the original</a>', $content);
        $this->assertStringContainsString('the original', $content);
        $this->assertSame(1, substr_count($content, 'https://example.com/story'));
        $this->assertStringContainsString('tr-ai-wire-source', $content);
    }

    public function test_pre_publish_gate_holds_clickbait_and_placeholders() {
        $gate = $this->invoke_private('evaluate_pre_publish_gate', array(
            array(
                'title' => "You won't believe this chip trick",
                'lede' => 'A concise summary for the excerpt field of this story.',
                'body_html' => '<p>TODO: insert the rest of the article here.</p><h2></h2>',
            ),
            $this->passing_review_for_score(90),
            array('source_title' => 'Source title'),
            TechnoRapper_AI_Wire::defaults(),
        ));
        $this->assertSame('HOLD', $gate['status']);
        $this->assertNotEmpty($gate['blocking_issues']);
        $this->assertStringContainsString('Status: HOLD', $this->invoke_private('format_pre_publish_gate', array($gate)));
    }

    public function test_pre_publish_gate_passes_a_named_change_article() {
        $gate = $this->invoke_private('evaluate_pre_publish_gate', array(
            $this->complete_rewrite_for_score(),
            $this->passing_review_for_score(90),
            array('source_title' => 'Apple announced the M5 chip'),
            TechnoRapper_AI_Wire::defaults(),
        ));
        $this->assertNotSame('HOLD', $gate['status']);
        $this->assertEmpty($gate['blocking_issues']);
    }

    public function test_pre_publish_gate_holds_auto_publish_on_a_live_duplicate() {
        $first = $this->create_queue_item();
        $this->invoke_private('create_draft', array($first, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-dup-a',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_body' => 'Source body <img src="https://cdn.example.com/photo.jpg" alt="photo">',
        ), $this->complete_rewrite_for_score(), $this->passing_review_for_score(90), TechnoRapper_AI_Wire::defaults()));
        $second = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'reviewed',
            'rewrite_json' => wp_json_encode($this->complete_rewrite_for_score()),
            'review_json' => wp_json_encode($this->passing_review_for_score(90)),
        ), array('id' => $second));
        $this->invoke_private('process_item', array($second, TechnoRapper_AI_Wire::defaults()));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id, error_text FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $second), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
        $this->assertStringContainsString('Held by pre-publish gate', (string) $row['error_text']);
        $this->assertStringContainsString('UPDATE EXISTING ARTICLE', (string) $row['error_text']);
    }

    public function test_print_article_schema_does_not_inject_json_ld() {
        ob_start();
        $this->plugin->print_article_schema();
        $html = ob_get_clean();
        $this->assertSame('', $html);
        $this->assertStringNotContainsString('application/ld+json', $html);
    }

    public function test_source_image_is_hosted_on_this_site() {
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-hosted-image',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_body' => 'Source body <img src="https://cdn.example.com/photo.jpg" alt="photo">',
        ), array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
            'featured_image_alt' => 'Hosted chip photo',
        ), array('verdict' => 'pass'), $this->draft_settings()));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $this->assert_featured_image_is_hosted((int) $posts[0]->ID);
        $this->assertSame('Hosted chip photo', get_post_meta((int) get_post_thumbnail_id((int) $posts[0]->ID), '_wp_attachment_image_alt', true));
        $this->assertStringNotContainsString('cdn.example.com', get_the_post_thumbnail((int) $posts[0]->ID));
    }

    public function test_body_keeps_upstream_link_only_in_inspired_by() {
        $related = self::factory()->post->create_and_get(array(
            'post_status' => 'publish',
            'post_title' => 'Older chipset explainer',
            'post_name' => 'older-chipset-explainer',
        ));
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-inspired',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_body' => 'Source body <img src="https://cdn.example.com/photo.jpg" alt="photo">',
        ), array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Read <a href="https://example.com/story">the original</a> and <a href="https://www.example.com/other">more from them</a>. <img src="https://cdn.example.com/photo.jpg" alt="chip"> Official: <a href="https://www.apple.com/newsroom">Apple</a> and <a href="' . esc_url(get_permalink($related)) . '">our explainer</a>.</p>',
        ), array('verdict' => 'pass'), $this->draft_settings()));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(1, $posts);
        $content = (string) $posts[0]->post_content;
        $this->assertStringContainsString('Inspired by:', $content);
        $this->assertStringContainsString('class="tr-ai-wire-source"', $content);
        $this->assertSame(1, substr_count($content, 'https://example.com/story'));
        $this->assertMatchesRegularExpression('/<p class="tr-ai-wire-source">.*<a href="https:\/\/example\.com\/story"/s', $content);
        $before = explode('tr-ai-wire-source', $content, 2);
        $this->assertStringNotContainsString('href="https://example.com', $before[0]);
        $this->assertStringNotContainsString('href="https://www.example.com', $before[0]);
        $this->assertStringNotContainsString('cdn.example.com/photo.jpg', $content);
        $this->assertStringContainsString('the original', $content);
        $this->assertStringContainsString('https://www.apple.com/newsroom', $content);
        $this->assertStringContainsString(get_permalink($related), $content);
        $this->assertStringNotContainsString('<a href="https://example.com/story">the original</a>', $content);
    }

    public function test_source_image_download_failure_retries() {
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            if (strpos((string) $url, 'cdn.example.com/photo') !== false) {
                return new WP_Error('http_request_failed', 'cdn timeout');
            }
            return $preempt;
        }, 0, 3);
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-image-fail',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_body' => 'Source body <img src="https://cdn.example.com/photo.jpg" alt="photo">',
        ), array(
            'title' => 'Bluetooth HDT could make wireless gadgets faster',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
            'category' => 'News',
            'tags' => array(),
        ), array('verdict' => 'pass'), TechnoRapper_AI_Wire::defaults()));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id, error_text FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('retry_wait', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
        $this->assertStringContainsString('source image', strtolower((string) $row['error_text']));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'any',
            'posts_per_page' => -1,
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => $id,
        ));
        $this->assertCount(0, $posts);
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
        $this->assertStringContainsString('brief', $writer);
        $this->assertStringContainsString('focus_keyphrase', $writer);
        $this->assertStringContainsString('slug', $writer);
        $this->assertStringContainsString('internal', $writer);
        $this->assertStringContainsString('site\'s audience', $writer);
        $this->assertStringContainsString('search_intent', $writer);
        $this->assertStringContainsString('featured_image_alt', $writer);
        $this->assertStringContainsString('qualcomm-data-center-push', $writer);
        $this->assertStringContainsString('key_takeaways', $writer);
        $this->assertStringContainsString('faq', $writer);
        $this->assertStringContainsString('featured snippet', $writer);
        $this->assertStringContainsString('editorial desk', $writer);
        $this->assertStringContainsString('Do not grade, score, or reject your own article', $writer);
        $this->assertStringNotContainsString('target 8/10', $writer);
        $this->assertStringContainsString('independent editorial reviewer', $reviewer);
        $this->assertStringContainsString('You did not write this article', $reviewer);
        $this->assertStringContainsString('Fail closed', $reviewer);
        $this->assertStringContainsString('hallucinations', $reviewer);
        $this->assertStringContainsString('FAQ', $reviewer);
        $this->assertStringContainsString('85-89', $reviewer);
        $this->assertStringContainsString('90-100', $reviewer);
        $this->assertStringContainsString('Do not park a 90-checklist article at 85-89', $reviewer);
        $this->assertStringContainsString('Instagram and X stills require 90', $reviewer);
        $this->assertStringContainsString('Do not deduct below 85', $reviewer);
        $this->assertStringContainsString('for missing jokes', $reviewer);
        $this->assertStringContainsString('fact_flags must be an empty array', $reviewer);
        $this->assertStringContainsString('If a human editor would publish it, score 85', $reviewer);
        $this->assertStringContainsString('do not park a publishable article at 78-84', strtolower($reviewer));
        $this->assertStringContainsString('Title names the product, company, or policy AND what changed', $writer);
        $this->assertStringContainsString('Never clone', $writer);
        $this->assertStringContainsString('Inspired by', $writer);
        $this->assertStringContainsString('Never link to the source article', $writer);
        $this->assertStringContainsString('labeled analysis', strtolower($writer));
        $this->assertStringContainsString('that is not a failure', $writer);
        $this->assertStringContainsString('Never use News or Technology', $writer);
        $this->assertStringContainsString('Site categories', $writer);
        $this->assertStringContainsString('Do not wait for a human', $writer);
        $this->assertStringNotContainsString('pinch of dry comic sense', $writer);
        $comic = $this->invoke_private('writer_prompt', array('A', true));
        $this->assertStringContainsString('pinch of dry comic sense', $comic);
        $this->assertStringContainsString('light wry aside', $comic);
        $this->assertStringContainsString('punchline-only headline', $comic);
        $this->assertStringContainsString('do not require jokes', strtolower($reviewer));
        $this->assertStringContainsString('punchline-only headlines', $reviewer);
        $this->assertStringContainsString('independent technology publication', $writer);
        $this->assertStringContainsString('AI-generated content farm', $writer);
        $this->assertStringContainsString('2-5 dofollow internal links', $writer);
        $this->assertStringContainsString('Never add links merely to hit a number', $writer);
        $this->assertStringContainsString('gate_status', $reviewer);
        $this->assertStringContainsString('PUBLISH_WITH_WARNINGS', $reviewer);
        $this->assertStringContainsString('Never raise the SEO score', $reviewer);
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
        $this->assertStringContainsString('You are not the author', $input);
        $this->assertStringContainsString('none supplied. Do not lower the score', $input);
        $this->assertStringContainsString('Publish gate: 85', $input);
        $this->assertStringContainsString('Social stills gate: 90', $input);
        $this->assertStringContainsString('fact_flags as []', $input);
        $this->assertStringContainsString('If a human editor would publish this, score 85', $input);
        $this->assertStringContainsString('If the 90 checklist is met, score 90 or higher', $input);
        $this->assertStringNotContainsString('_tr_score_polish', $input);
    }

    private function complete_rewrite_for_score($title = 'Apple M5 chip memory bandwidth jumps') {
        return array(
            'title' => $title,
            'lede' => 'Apple M5 chip adds on-device photo models this spring.',
            'body_html' => '<p>Grounded article body about the M5 chip launch.</p>',
            'focus_keyphrase' => 'apple m5 chip',
            'key_takeaways' => array(
                'Apple M5 chip raises memory bandwidth.',
                'On-device photo model ships in April.',
                'Developers get a beta API the same day.',
            ),
            'faq' => array(
                array('question' => 'When does the M5 chip ship?', 'answer' => 'April 2026 according to the source.'),
                array('question' => 'How much does it cost?', 'answer' => 'The source lists $999.'),
                array('question' => 'What changed for developers?', 'answer' => 'A new API in the same-day beta.'),
            ),
        );
    }

    private function passing_review_for_score($score, $extra = array()) {
        return array_merge(array(
            'verdict' => 'pass',
            'score' => (int) $score,
            'safe_to_publish' => true,
            'fact_flags' => array(),
            'policy_flags' => array(),
            'issues' => array(),
            'suggested_fixes' => array(),
            'pragmatism' => array(
                'is_new' => true,
                'is_useful' => true,
                'is_grounded' => true,
                'similarity_risk' => 'low',
            ),
            'story_value' => array(
                'publish' => true,
                'substance' => 'major',
                'reader_demand' => 'high',
                'adds_value' => true,
                'reason' => 'Named chip launch readers will search.',
            ),
        ), is_array($extra) ? $extra : array());
    }

    public function test_clustered_complete_review_is_raised_to_social_floor() {
        $rewrite = $this->complete_rewrite_for_score();
        $item = array('source_title' => 'Apple announced the M5 chip');
        $review = $this->invoke_private('normalize_review_score', array(
            $this->passing_review_for_score(86),
            $rewrite,
            $item,
        ));
        $this->assertSame(90, (int) $review['score']);
        $this->assertStringContainsString('clustering below 90', (string) $review['pragmatism']['notes']);
    }

    public function test_generic_headline_review_stays_below_social_floor() {
        $rewrite = $this->complete_rewrite_for_score('Apple announced the M5 chip');
        $review = $this->invoke_private('normalize_review_score', array(
            $this->passing_review_for_score(86, array(
                'issues' => array('Generic headline restates the source headline'),
                'suggested_fixes' => array('Name the bandwidth change in the title'),
            )),
            $rewrite,
            array('source_title' => 'Apple announced the M5 chip'),
        ));
        $this->assertSame(86, (int) $review['score']);
        $this->assertTrue($this->invoke_private('review_should_polish', array($review, $rewrite)));
    }

    public function test_style_nits_do_not_block_a_90_lift() {
        $review = $this->invoke_private('normalize_review_score', array(
            $this->passing_review_for_score(87, array(
                'issues' => array('Readability nit: one long sentence in Why it matters'),
            )),
            $this->complete_rewrite_for_score(),
            array('source_title' => 'Apple announced the M5 chip'),
        ));
        $this->assertSame(90, (int) $review['score']);
    }

    public function test_missing_humor_nit_does_not_block_a_90_lift() {
        $review = $this->invoke_private('normalize_review_score', array(
            $this->passing_review_for_score(87, array(
                'issues' => array('Could use a wry aside in the lede'),
            )),
            $this->complete_rewrite_for_score(),
            array('source_title' => 'Apple announced the M5 chip'),
        ));
        $this->assertSame(90, (int) $review['score']);
        $this->assertFalse($this->invoke_private('review_has_social_blocking_defect', array(
            $this->passing_review_for_score(87, array(
                'issues' => array('Missing a joke in Why it matters'),
            )),
            $this->complete_rewrite_for_score(),
            array('source_title' => 'Apple announced the M5 chip'),
        )));
        $this->assertTrue($this->invoke_private('review_has_social_blocking_defect', array(
            $this->passing_review_for_score(86, array(
                'issues' => array('Generic headline restates the source headline'),
            )),
            $this->complete_rewrite_for_score('Apple announced the M5 chip'),
            array('source_title' => 'Apple announced the M5 chip'),
        )));
    }

    public function test_clustered_pass_does_not_spend_a_polish_call() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $id = $this->create_queue_item();
        $calls = 0;
        $writer = $this->complete_rewrite_for_score();
        $review = $this->passing_review_for_score(86);
        $callback = function ($preempt, $args, $url) use (&$calls, $writer, $review) {
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            $calls++;
            $payload = json_decode($args['body'], true);
            $system = (string) ($payload['input'][0]['content'][0]['text'] ?? '');
            $this->assertStringNotContainsString('revising a', $system);
            return $this->openai_json_http($calls === 1 ? $writer : $review);
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $settings = $this->draft_settings();
        $this->invoke_private('process_item', array($id, $settings));
        $this->invoke_private('process_item', array($id, $settings));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, review_json FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('reviewed', $row['status']);
        $this->assertSame(2, $calls);
        $decoded = json_decode($row['review_json'], true);
        $this->assertSame(90, (int) $decoded['score']);
        $this->assertStringContainsString('clustering below 90', (string) ($decoded['pragmatism']['notes'] ?? ''));
    }

    public function test_near_miss_review_polishes_once_then_can_hit_90() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $id = $this->create_queue_item();
        $calls = 0;
        $writer = $this->complete_rewrite_for_score('Apple announced the M5 chip');
        $first_review = $this->passing_review_for_score(86, array(
            'issues' => array('Generic headline restates the source headline'),
            'suggested_fixes' => array('Name the M5 bandwidth change in the title'),
        ));
        $polished = $this->complete_rewrite_for_score('Apple M5 chip memory bandwidth jumps');
        $second_review = $this->passing_review_for_score(92);
        $callback = function ($preempt, $args, $url) use (&$calls, $writer, $first_review, $polished, $second_review) {
            if (strpos($url, 'api.openai.com/v1/responses') === false) {
                return $preempt;
            }
            $calls++;
            $payload = json_decode($args['body'], true);
            $system = (string) ($payload['input'][0]['content'][0]['text'] ?? '');
            if ($calls === 1) {
                $this->assertStringContainsString('Write a detailed', $system);
                $output = $writer;
            } elseif ($calls === 2) {
                $this->assertStringContainsString('independent editorial reviewer', $system);
                $output = $first_review;
            } elseif ($calls === 3) {
                $this->assertStringContainsString('revising a', $system);
                $this->assertStringContainsString('suggested_fixes', $system);
                $output = $polished;
            } else {
                $this->assertStringContainsString('independent editorial reviewer', $system);
                $output = $second_review;
            }
            return $this->openai_json_http($output);
        };
        add_filter('pre_http_request', $callback, 10, 3);
        $settings = $this->draft_settings();
        $this->invoke_private('process_item', array($id, $settings));
        $this->invoke_private('process_item', array($id, $settings));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, rewrite_json, review_json, error_text FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('generated', $row['status']);
        $this->assertSame(3, $calls);
        $this->assertStringContainsString('Polished once', (string) $row['error_text']);
        $rewrite = json_decode($row['rewrite_json'], true);
        $this->assertSame(1, (int) ($rewrite['_tr_score_polish'] ?? 0));
        $this->assertSame('Apple M5 chip memory bandwidth jumps', $rewrite['title']);
        $this->assertSame('', (string) $row['review_json']);

        $this->invoke_private('process_item', array($id, $settings));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, review_json FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('reviewed', $row['status']);
        $this->assertSame(4, $calls);
        $review = json_decode($row['review_json'], true);
        $this->assertSame(92, (int) $review['score']);
        $this->assertFalse($this->invoke_private('review_should_polish', array($review, $rewrite)));
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
        $this->assertStringContainsString('Related on ' . get_bloginfo('name'), $posts[0]->post_content);
        $this->assertStringContainsString(get_bloginfo('name') . ' editorial desk', $posts[0]->post_content);
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
            'status' => 'reviewed',
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
        $review = array(
            'verdict' => 'pass',
            'score' => 90,
            'policy_flags' => array(),
            'fact_flags' => array(),
            'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low'),
        );
        $this->assertTrue($this->invoke_private('should_force_publish_retry', array($item, $rewrite, $review, TechnoRapper_AI_Wire::defaults())));
        $this->invoke_private('force_publish_item', array($id, $item, $rewrite, $review, TechnoRapper_AI_Wire::defaults()));
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
            $this->assertStringContainsString('independent editorial reviewer', $system);
            $this->assertStringContainsString('You are not the author', $user);
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
                                'story_value' => array(
                                    'publish' => true,
                                    'substance' => 'major',
                                    'reader_demand' => 'high',
                                    'adds_value' => true,
                                    'reason' => 'Named chip launch readers will search.',
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
        $this->assertSame(90, (int) $review['score']);
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

    public function test_process_item_does_not_chain_review_in_one_step() {
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
                'story_value' => array(
                    'publish' => true,
                    'substance' => 'major',
                    'reader_demand' => 'high',
                    'adds_value' => true,
                    'reason' => 'Named chip launch readers will search.',
                ),
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
        $this->invoke_private('process_item', array($id, $this->draft_settings(), false));
        remove_filter('pre_http_request', $callback, 10);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('generated', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
        $this->assertSame(1, $calls);
    }

    public function test_manual_process_item_still_finishes_rewrite_review_and_draft() {
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
                'story_value' => array(
                    'publish' => true,
                    'substance' => 'major',
                    'reader_demand' => 'high',
                    'adds_value' => true,
                    'reason' => 'Named chip launch readers will search.',
                ),
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
        $this->assertSame('draft', get_post_status((int) $row['wp_post_id']));
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
                'story_value' => array(
                    'publish' => true,
                    'substance' => 'major',
                    'reader_demand' => 'high',
                    'adds_value' => true,
                    'reason' => 'Named chip launch readers will search.',
                ),
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
        $this->assertSame(90, (int) $day['social_min_score']);
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

    public function test_social_queue_stays_off_by_default() {
        $this->assertSame(0, (int) TechnoRapper_AI_Wire::defaults()['social_distribution_enabled']);
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Chip news'));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 99);
        $this->fire_social_publish($post_id);
        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social");
        $this->assertSame(0, $count);
    }

    public function test_social_queue_starts_only_after_publish_when_enabled() {
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 10,
        )), false);
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array(
            'x_user_access_token' => 'x-token',
            'instagram_user_access_token' => '',
            'instagram_user_id' => '',
        ), false);
        $draft_id = $this->factory()->post->create(array('post_status' => 'draft', 'post_title' => 'Draft chip'));
        update_post_meta($draft_id, '_tr_ai_wire_queue_id', 11);
        $this->plugin->queue_social_for_post('draft', 'new', get_post($draft_id));

        $plain_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Manual post'));
        $this->fire_social_publish($plain_id);

        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Published chip',
            'post_excerpt' => 'A phone just got faster wireless charging.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 12);
        $this->fire_social_publish($post_id);
        $this->plugin->queue_social_for_post('publish', 'publish', get_post($post_id));

        global $wpdb;
        $rows = $wpdb->get_results("SELECT post_id, platform, status FROM {$wpdb->prefix}tr_ai_wire_social", ARRAY_A);
        $this->assertCount(1, $rows);
        $this->assertSame((string) $post_id, (string) $rows[0]['post_id']);
        $this->assertSame('x', $rows[0]['platform']);
        $this->assertSame('queued', $rows[0]['status']);
    }

    public function test_social_copy_puts_hook_on_image_and_url_in_captions() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Qualcomm pushes into data centers',
            'post_excerpt' => 'The chipmaker wants a larger share of AI servers this year.',
        ));
        $copy = $this->invoke_private('sanitize_social_copy', array(array(
            'hook' => 'Your next phone chip has a server cousin',
            'on_image_headline' => 'Qualcomm wants the data center',
            'on_image_subhead' => 'AI servers are the new battleground',
            'cta' => 'Read the full story on TechnoRapper',
            'x_text' => 'Qualcomm is chasing AI servers, not just phones.',
            'instagram_caption' => "Your next phone chip has a server cousin\n\nWhat changed: a bigger data-center push.\nhttps://example.com/do-not-post",
            'hashtags' => array('AI', 'Qualcomm', 'bad tag!'),
        ), get_post($post_id)));
        $permalink = get_permalink($post_id);
        $this->assertSame('Qualcomm wants the data center', $copy['on_image_headline']);
        $this->assertSame('Your next phone chip has a server cousin', $copy['hook']);
        $this->assertStringContainsString($permalink, $copy['x']);
        $this->assertStringNotContainsString($permalink, $copy['instagram']);
        $this->assertStringNotContainsString('http://', $copy['instagram']);
        $this->assertStringNotContainsString('https://', $copy['instagram']);
        $this->assertContains('ai', $copy['hashtags']);
        $this->assertContains('qualcomm', $copy['hashtags']);
        $this->assertStringContainsString('Read the full story on TechnoRapper', $copy['instagram']);
        $this->assertStringContainsString('Link in bio.', $copy['instagram']);
        $this->assertNotSame('', $copy['reel_script']);
        $this->assertGreaterThanOrEqual(3, count($copy['reel_captions']));
        $this->assertSame('Your next phone chip has a server cousin', $copy['youtube_title']);
        $this->assertStringContainsString($permalink, $copy['youtube_description']);
        $this->assertContains('ai', $copy['youtube_tags']);
        $this->assertStringNotContainsString('•', $copy['reel_script']);
    }

    public function test_social_copy_strips_instagram_poison_phrases() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Qualcomm data-center push',
            'post_excerpt' => 'The chipmaker wants a larger share of AI servers.',
        ));
        $copy = $this->invoke_private('sanitize_social_copy', array(array(
            'hook' => 'You won’t believe this Qualcomm server chip',
            'on_image_headline' => 'Wait for it: Qualcomm servers',
            'on_image_subhead' => 'This changes everything for phones',
            'instagram_caption' => "Must see: Qualcomm wants AI servers.\n\nWait until the end.",
            'x_text' => 'Shocking Qualcomm data-center push',
            'on_image_bullets' => array('Gone wrong? No — bandwidth is up.', 'This will blow your mind: 2x memory.'),
            'reel_script' => 'Wait for it. Qualcomm is chasing AI servers, not just phones, and that shift is why memory bandwidth is the story.',
        ), get_post($post_id)));
        $this->assertStringNotContainsString('won’t believe', $copy['hook']);
        $this->assertStringNotContainsString('Wait for it', $copy['on_image_headline']);
        $this->assertStringNotContainsString('changes everything', $copy['on_image_subhead']);
        $this->assertStringNotContainsString('Must see', $copy['instagram']);
        $this->assertStringNotContainsString('Wait until the end', $copy['instagram']);
        $this->assertStringNotContainsString('Shocking', $copy['x']);
        $this->assertStringContainsString('Qualcomm', $copy['hook']);
        $this->assertStringContainsString('Qualcomm', $copy['instagram']);
        $this->assertStringNotContainsString('blow your mind', implode(' ', $copy['on_image_bullets']));
        $this->assertStringNotContainsString('Wait for it', $copy['reel_script']);
        $this->assertStringContainsString('Qualcomm is chasing AI servers', $copy['reel_script']);
        $this->assertStringContainsString('memory bandwidth is the story', $copy['reel_script']);
    }

    public function test_weak_review_scores_do_not_queue_instagram_stills() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_mix_saved' => 1,
            'social_reel_daily_limit' => 0,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 3,
            'social_min_review_score' => 90,
        )), false);
        $settings = $this->plugin->sanitize_settings(get_option(TechnoRapper_AI_Wire::OPTION, array()));
        $weak = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Okay story stays on the website',
        ));
        $this->attach_review_score($weak, 86);
        $this->invoke_private('collect_social_article', array($weak, $settings));
        global $wpdb;
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d", $weak)));
        $this->assertStringContainsString('86', (string) get_post_meta($weak, '_tr_ai_wire_social_skip', true));
        $this->assertSame('', (string) get_post_meta($weak, '_tr_ai_wire_social_mode', true));

        $strong = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Strong story can go to Instagram',
        ));
        $this->attach_review_score($strong, 92);
        $this->invoke_private('collect_social_article', array($strong, $settings));
        $this->assertSame('single', (string) get_post_meta($strong, '_tr_ai_wire_social_mode', true));
        $this->assertSame('', (string) get_post_meta($strong, '_tr_ai_wire_social_skip', true));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'instagram' AND status = 'queued'", $strong)));
    }

    public function test_social_score_floor_zero_posts_every_published_article() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_mix_saved' => 1,
            'social_reel_daily_limit' => 0,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 3,
            'social_min_review_score' => 0,
        )), false);
        $settings = $this->plugin->sanitize_settings(get_option(TechnoRapper_AI_Wire::OPTION, array()));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Low score still posts when the floor is off',
        ));
        $this->attach_review_score($post_id, 70);
        $this->invoke_private('collect_social_article', array($post_id, $settings));
        global $wpdb;
        $this->assertSame('single', (string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d", $post_id)));
    }

    public function test_instagram_stills_wait_after_a_recent_post() {
        $this->onboard_social(array('instagram'));
        global $wpdb;
        $old_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Already posted still',
        ));
        $now = current_time('mysql', true);
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $old_id,
            'platform' => 'instagram',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ), array('%d', '%s', '%s', '%s', '%s'));
        $this->assertSame(10800, $this->invoke_private('instagram_stills_spacing_seconds'));
        $this->assertGreaterThan(0, $this->invoke_private('instagram_spacing_wait_seconds'));

        $next_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Next still should wait',
        ));
        update_post_meta($next_id, '_tr_ai_wire_queue_id', 940);
        $this->invoke_private('collect_social_article', array($next_id, $this->plugin->sanitize_settings(get_option(TechnoRapper_AI_Wire::OPTION, array()))));
        $publish_calls = 0;
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$publish_calls) {
            if (strpos((string) $url, '/media_publish') !== false) {
                $publish_calls++;
            }
            return new WP_Error('blocked', 'Instagram should not publish while spacing.');
        }, 10, 3);
        $this->plugin->process_social_queue();
        $row = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'instagram'", $next_id), ARRAY_A);
        $this->assertSame('queued', $row['status']);
        $this->assertSame(0, $publish_calls);
    }

    public function test_zero_social_image_budget_blocks_generation() {
        $this->onboard_social(array('x'));
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Budget test'));
        $settings = array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_image_budget_usd' => 0,
        ));
        $result = $this->invoke_private('generate_social_image_locked', array(get_post($post_id), $settings, array()));
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_social_image_budget', $result->get_error_code());
        $this->assertSame(0, (int) get_post_thumbnail_id($post_id));
    }

    public function test_branded_social_image_is_not_the_featured_image() {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is required to render social cards.');
        }
        $im = imagecreatetruecolor(64, 96);
        $bg = imagecolorallocate($im, 9, 20, 43);
        imagefilledrectangle($im, 0, 0, 63, 95, $bg);
        ob_start();
        imagejpeg($im, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($im);
        $branded = $this->invoke_private('brand_social_image', array($bytes, array(
            'on_image_headline' => 'Stop scrolling',
            'on_image_subhead' => 'This chip changes laptops',
            'cta' => 'Read the full story on TechnoRapper',
        )));
        $this->assertTrue(is_string($branded) && $branded !== '');
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Article stays itself'));
        update_post_meta($post_id, '_tr_ai_wire_source_image', 'https://cdn.example.com/photo.jpg');
        $this->assertSame('https://cdn.example.com/photo.jpg', $this->invoke_private('display_image_url', array($post_id)));
        $this->assertSame(0, $this->invoke_private('real_thumbnail_id', array($post_id)));
    }

    public function test_social_copy_keeps_poster_length_headline() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Tech layoffs accelerate',
            'post_excerpt' => 'More than 6,300 tech jobs were cut in 10 days.',
        ));
        $copy = $this->invoke_private('sanitize_social_copy', array(array(
            'on_image_headline' => '6,300 tech jobs gone in 10 days. Is AI the biggest threat?',
            'on_image_subhead' => 'Layoffs hit offices from coast to coast',
            'visual_brief' => 'Photoreal office workers carrying boxes toward glass elevators at dusk',
        ), get_post($post_id)));
        $this->assertSame('6,300 tech jobs gone in 10 days. Is AI the biggest threat?', $copy['on_image_headline']);
        $this->assertStringContainsString('Photoreal office workers', $copy['visual_brief']);
        $this->assertSame('WHAT JUST HAPPENED IN 24 HOURS?', $this->invoke_private('social_poster_headline', array('What just happened in 24 hours?')));
    }

    public function test_social_poster_headline_uses_large_display_type() {
        if (!function_exists('imagettfbbox')) {
            $this->markTestSkipped('FreeType is required to size poster headlines.');
        }
        $font = $this->invoke_private('social_font_path');
        $this->assertNotSame('', $font);
        $this->assertStringContainsString('Anton-Regular.ttf', $font);
        $short = $this->invoke_private('social_poster_headline_layout', array(
            'What just happened in 24 hours?',
            $font,
            1000,
            620,
            4,
        ));
        $this->assertGreaterThanOrEqual(90, $short['size']);
        $this->assertLessThanOrEqual(3, count($short['lines']));
        $this->assertSame('WHAT JUST HAPPENED IN 24 HOURS?', implode(' ', $short['lines']));
        $long = $this->invoke_private('social_poster_headline_layout', array(
            '6,300 tech jobs gone in 10 days. Is AI the biggest threat?',
            $font,
            1000,
            620,
            4,
        ));
        $this->assertGreaterThanOrEqual(64, $long['size']);
        $this->assertLessThanOrEqual(4, count($long['lines']));
        $this->assertFileExists(dirname(__DIR__) . '/assets/fonts/Anton-Regular.ttf');
    }

    public function test_branded_social_image_is_instagram_portrait_with_large_type() {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is required to render social posters.');
        }
        $im = imagecreatetruecolor(1080, 1350);
        for ($y = 0; $y < 1350; $y++) {
            $t = $y / 1350;
            $color = imagecolorallocate($im, (int) (40 + (1 - $t) * 80), (int) (18 + (1 - $t) * 30), (int) (12 + $t * 20));
            imageline($im, 0, $y, 1079, $y, $color);
        }
        ob_start();
        imagejpeg($im, null, 88);
        $bytes = ob_get_clean();
        imagedestroy($im);
        $branded = $this->invoke_private('brand_social_image', array($bytes, array(
            'on_image_headline' => 'What just happened in 24 hours?',
            'on_image_subhead' => 'The week Big Tech could not ignore',
        )));
        $this->assertTrue(is_string($branded) && $branded !== '');
        $info = getimagesizefromstring($branded);
        $this->assertSame(1080, $info[0]);
        $this->assertSame(1350, $info[1]);
        $dir = '/opt/cursor/artifacts';
        if (is_dir($dir) || mkdir($dir, 0755, true)) {
            file_put_contents($dir . '/instagram_poster_overlay_short.jpg', $branded);
            $long = $this->invoke_private('brand_social_image', array($bytes, array(
                'on_image_headline' => '6,300 tech jobs gone in 10 days. Is AI the biggest threat?',
                'on_image_subhead' => 'Layoffs hit offices from coast to coast',
            )));
            if (is_string($long) && $long !== '') {
                file_put_contents($dir . '/instagram_poster_overlay_long.jpg', $long);
            }
        }
    }

    public function test_branded_social_image_covers_tiny_baked_in_captions() {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !function_exists('imagecreatefromstring') || !function_exists('imagecolorsforindex')) {
            $this->markTestSkipped('GD is required to cover baked-in image captions.');
        }
        $im = imagecreatetruecolor(1080, 1350);
        for ($y = 0; $y < 1350; $y++) {
            $t = $y / 1350;
            $color = imagecolorallocate($im, (int) (20 + (1 - $t) * 40), (int) (18 + (1 - $t) * 24), (int) (22 + $t * 18));
            imageline($im, 0, $y, 1079, $y, $color);
        }
        $white = imagecolorallocate($im, 230, 230, 230);
        imagestring($im, 2, 430, 1080, 'WHEN FAKE IDS BECOME', $white);
        imagestring($im, 2, 440, 1100, 'EASIER TO SCALE', $white);
        imagestring($im, 1, 400, 1130, 'OnlyFake raises questions about digital', $white);
        ob_start();
        imagejpeg($im, null, 88);
        $raw = ob_get_clean();
        imagedestroy($im);
        $branded = $this->invoke_private('brand_social_image', array($raw, array(
            'on_image_headline' => 'When fake IDs become easier to scale',
            'on_image_subhead' => 'OnlyFake raises hard questions',
        )));
        $this->assertTrue(is_string($branded) && $branded !== '');
        $result = imagecreatefromstring($branded);
        $this->assertNotFalse($result);
        $sample = imagecolorat($result, 36, 1240);
        $rgb = imagecolorsforindex($result, $sample);
        imagedestroy($result);
        $this->assertLessThan(40, (int) $rgb['red']);
        $this->assertLessThan(40, (int) $rgb['green']);
        $this->assertLessThan(40, (int) $rgb['blue']);
        $dir = '/opt/cursor/artifacts';
        if (is_dir($dir) || mkdir($dir, 0755, true)) {
            file_put_contents($dir . '/instagram_poster_covers_model_text.jpg', $branded);
        }
    }

    public function test_social_layout_version_stays_poster_v2() {
        $this->assertSame('poster-v2', TechnoRapper_AI_Wire::SOCIAL_IMAGE_LAYOUT);
        $this->assertSame(
            array('bottom-center', 'left-stack', 'kicker-bar', 'bottom-left', 'cover-top'),
            $this->invoke_private('social_poster_variants')
        );
        $this->assertSame('bottom-center', $this->invoke_private('normalize_poster_variant', array('not-a-layout')));
    }

    public function test_social_poster_variants_rotate_across_posts() {
        $seen = array();
        $cameras = array();
        for ($i = 0; $i < 24; $i++) {
            $post_id = $this->factory()->post->create(array(
                'post_status' => 'publish',
                'post_title' => 'Poster variant ' . $i,
            ));
            $post = get_post($post_id);
            $seen[] = $this->invoke_private('social_poster_variant_for_post', array($post));
            $cameras[] = $this->invoke_private('social_image_camera', array($post, 0));
        }
        $this->assertGreaterThanOrEqual(3, count(array_unique($seen)));
        $this->assertGreaterThanOrEqual(3, count(array_unique($cameras)));
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Revision variant'));
        $first = $this->invoke_private('social_poster_variant_for_post', array(get_post($post_id)));
        update_post_meta($post_id, '_tr_ai_wire_social_image_revision', 7);
        $second = $this->invoke_private('social_poster_variant_for_post', array(get_post($post_id)));
        $this->assertContains($first, $this->invoke_private('social_poster_variants'));
        $this->assertContains($second, $this->invoke_private('social_poster_variants'));
    }

    public function test_social_image_prompt_uses_variant_safe_zone_and_camera() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Safe zone article',
        ));
        $post = get_post($post_id);
        $left = $this->invoke_private('social_image_prompt', array($post, array('_poster_variant' => 'left-stack')));
        $this->assertStringContainsString('Keep the left third of the frame naturally dark', $left);
        $this->assertStringContainsString('Camera:', $left);
        $cover = $this->invoke_private('social_image_prompt', array($post, array('_poster_variant' => 'cover-top')));
        $this->assertStringContainsString('Keep the upper third of the frame naturally darker', $cover);
        $default = $this->invoke_private('social_image_prompt', array($post, array('_poster_variant' => 'bottom-center')));
        $this->assertStringContainsString('The lower half of the frame is naturally dark', $default);
    }

    public function test_branded_social_image_variants_keep_instagram_portrait() {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !function_exists('imagecreatefromstring') || !function_exists('imagecolorsforindex')) {
            $this->markTestSkipped('GD is required to render poster variants.');
        }
        $im = imagecreatetruecolor(1080, 1350);
        for ($y = 0; $y < 1350; $y++) {
            $t = $y / 1350;
            $color = imagecolorallocate($im, (int) (28 + (1 - $t) * 70), (int) (16 + (1 - $t) * 22), (int) (12 + $t * 28));
            imageline($im, 0, $y, 1079, $y, $color);
        }
        $glow = imagecolorallocate($im, 80, 120, 160);
        imagefilledellipse($im, 760, 430, 420, 320, $glow);
        ob_start();
        imagejpeg($im, null, 88);
        $bytes = ob_get_clean();
        imagedestroy($im);
        $examples = array(
            'bottom-center' => array(
                'on_image_headline' => '13-inch or 15-inch Air?',
                'on_image_subhead' => 'Both M5 models are $200 off',
            ),
            'left-stack' => array(
                'on_image_headline' => 'When fake IDs become easier to scale',
                'on_image_subhead' => 'OnlyFake raises questions about digital identity',
            ),
            'kicker-bar' => array(
                'on_image_headline' => 'When big tech becomes politics',
                'on_image_subhead' => 'Tech News',
            ),
            'bottom-left' => array(
                'on_image_headline' => 'Siri could edit photos and organize Safari tabs',
                'on_image_subhead' => 'A reported roadmap points to action inside Apple apps',
            ),
            'cover-top' => array(
                'on_image_headline' => 'Midterm politics put AI stocks under pressure',
                'on_image_subhead' => 'Washington fears meet deals already building around models',
            ),
        );
        $dir = '/opt/cursor/artifacts';
        foreach ($examples as $variant => $copy) {
            $copy['_poster_variant'] = $variant;
            $branded = $this->invoke_private('brand_social_image', array($bytes, $copy));
            $this->assertTrue(is_string($branded) && $branded !== '', $variant . ' should render');
            $info = getimagesizefromstring($branded);
            $this->assertSame(1080, $info[0], $variant);
            $this->assertSame(1350, $info[1], $variant);
            if (is_dir($dir) || mkdir($dir, 0755, true)) {
                file_put_contents($dir . '/instagram_poster_variant_' . $variant . '.jpg', $branded);
            }
        }
        $default = $this->invoke_private('brand_social_image', array($bytes, array(
            'on_image_headline' => 'What just happened in 24 hours?',
            'on_image_subhead' => 'The week Big Tech could not ignore',
        )));
        $result = imagecreatefromstring($default);
        $this->assertNotFalse($result);
        $sample = imagecolorat($result, 36, 1240);
        $rgb = imagecolorsforindex($result, $sample);
        imagedestroy($result);
        $this->assertLessThan(40, (int) $rgb['red']);
        $this->assertLessThan(40, (int) $rgb['green']);
        $this->assertLessThan(40, (int) $rgb['blue']);
        $left = $this->invoke_private('brand_social_image', array($bytes, array(
            '_poster_variant' => 'left-stack',
            'on_image_headline' => 'When fake IDs become easier to scale',
            'on_image_subhead' => 'OnlyFake raises questions',
        )));
        $left_im = imagecreatefromstring($left);
        $rail = imagecolorsforindex($left_im, imagecolorat($left_im, 4, 640));
        imagedestroy($left_im);
        $this->assertGreaterThan(30, (int) $rail['blue']);
    }

    public function test_social_post_format_settings_are_sanitized() {
        $clean = $this->plugin->sanitize_settings(array(
            'social_post_format' => 'carousel',
            'social_slide_count' => 4,
        ));
        $this->assertSame('carousel', $clean['social_post_format']);
        $this->assertSame(4, $clean['social_slide_count']);
        $five = $this->plugin->sanitize_settings(array(
            'social_post_format' => 'collage',
            'social_slide_count' => 5,
        ));
        $this->assertSame(5, $five['social_slide_count']);
        $six = $this->plugin->sanitize_settings(array(
            'social_post_format' => 'carousel',
            'social_slide_count' => 6,
        ));
        $this->assertSame(6, $six['social_slide_count']);
        $reel = $this->plugin->sanitize_settings(array(
            'social_post_format' => 'reel',
        ));
        $this->assertSame('reel', $reel['social_post_format']);
        $this->assertSame(1, $this->invoke_private('social_slide_count', array($reel)));
        $this->assertSame(0, (int) $reel['social_reel_daily_limit']);
        $this->assertSame(array('carousel' => 0, 'collage' => 0, 'single' => 0, 'reel' => 10), $this->invoke_private('social_daily_mix_limits', array($reel)));
        $this->assertSame(10.0, (float) $reel['social_video_budget_usd']);
        $this->assertSame('veo-lite', $reel['social_reel_video_model']);
        $this->assertSame(8, (int) $reel['social_reel_clip_seconds']);
        $upgraded = $this->plugin->sanitize_settings(array(
            'social_post_format' => 'reel',
            'social_video_budget_usd' => 40,
            'social_reel_video_model' => 'veo-fast',
            'social_reel_clip_seconds' => 6,
        ));
        $this->assertSame(40.0, (float) $upgraded['social_video_budget_usd']);
        $this->assertSame('veo-fast', $upgraded['social_reel_video_model']);
        $this->assertSame(6, (int) $upgraded['social_reel_clip_seconds']);
        $this->assertSame(0.24, $this->invoke_private('social_reel_video_estimate_usd', array(TechnoRapper_AI_Wire::defaults())));
        $this->assertLessThan(10.0, $this->invoke_private('social_reel_video_estimate_usd', array(TechnoRapper_AI_Wire::defaults())) * 30);
        $kling = $this->plugin->sanitize_settings(array(
            'social_post_format' => 'reel',
            'social_reel_video_model' => 'kling-pro',
            'social_reel_clip_seconds' => 15,
        ));
        $this->assertSame('kling-pro', $kling['social_reel_video_model']);
        $this->assertSame(15, (int) $kling['social_reel_clip_seconds']);
        $this->assertTrue(!empty($this->invoke_private('social_reel_video_models')['veo-lite']['recommended']));
        $this->assertSame(5, $this->invoke_private('social_reel_clip_seconds', array(array(
            'social_reel_video_model' => 'kling-std',
            'social_reel_clip_seconds' => 4,
        ))));
        $kling_payload = $this->invoke_private('social_fal_motion_payload', array(
            'kling-std',
            'https://example.org/broll.jpg',
            'Slow cinematic push-in',
            8,
        ));
        $this->assertSame('https://example.org/broll.jpg', $kling_payload['start_image_url'] ?? '');
        $this->assertArrayNotHasKey('image_url', $kling_payload);
        $this->assertSame('8', $kling_payload['duration'] ?? '');
        $this->assertFalse($kling_payload['generate_audio'] ?? true);
        $rejected = $this->plugin->sanitize_settings(array(
            'social_post_format' => 'album',
            'social_slide_count' => 9,
        ));
        $this->assertSame('single', $rejected['social_post_format']);
        $this->assertSame(5, $rejected['social_slide_count']);
        $this->assertSame(5, $this->invoke_private('social_slide_count', array(TechnoRapper_AI_Wire::defaults())));
        $this->assertSame(4, $this->invoke_private('social_slide_count', array($clean)));
        $this->assertArrayNotHasKey('social_pack_wait_minutes', $clean);
        $mix = $this->plugin->sanitize_settings(array(
            'social_carousel_daily_limit' => 2,
            'social_single_daily_limit' => 4,
        ));
        $this->assertSame(2, $mix['social_carousel_daily_limit']);
        $this->assertSame(4, $mix['social_single_daily_limit']);
        $counts = $this->plugin->sanitize_settings(array(
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 3,
            'social_collage_daily_limit' => 1,
            'social_single_daily_limit' => 4,
        ));
        $this->assertSame(1, (int) $counts['social_mix_saved']);
        $this->assertSame(3, $counts['social_carousel_daily_limit']);
        $this->assertSame(1, $counts['social_collage_daily_limit']);
        $this->assertSame(4, $counts['social_single_daily_limit']);
        $this->assertSame('carousel', $counts['social_post_format']);
        $singles_only = $this->plugin->sanitize_settings(array(
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 6,
        ));
        $this->assertSame('single', $singles_only['social_post_format']);
        $this->assertSame(1, $this->invoke_private('social_slide_count', array($singles_only)));
        $clamped = $this->plugin->sanitize_settings(array(
            'social_carousel_daily_limit' => 99,
            'social_collage_daily_limit' => 99,
            'social_single_daily_limit' => 99,
        ));
        $this->assertSame(10, $clamped['social_carousel_daily_limit']);
        $this->assertSame(0, $clamped['social_collage_daily_limit']);
        $this->assertSame(0, $clamped['social_single_daily_limit']);
        $split = $this->plugin->sanitize_settings(array(
            'social_mix_saved' => 1,
            'social_daily_limit' => 5,
            'social_carousel_daily_limit' => 2,
            'social_collage_daily_limit' => 1,
            'social_single_daily_limit' => 2,
        ));
        $this->assertSame(5, (int) $split['social_daily_limit']);
        $this->assertSame(2, (int) $split['social_carousel_daily_limit']);
        $this->assertSame(1, (int) $split['social_collage_daily_limit']);
        $this->assertSame(2, (int) $split['social_single_daily_limit']);
        $this->assertSame(array('carousel' => 2, 'collage' => 1, 'single' => 2, 'reel' => 0), $this->invoke_private('social_daily_mix_limits', array($split)));
        $over = $this->plugin->sanitize_settings(array(
            'social_mix_saved' => 1,
            'social_daily_limit' => 5,
            'social_carousel_daily_limit' => 3,
            'social_collage_daily_limit' => 1,
            'social_single_daily_limit' => 4,
        ));
        $this->assertSame(3, (int) $over['social_carousel_daily_limit']);
        $this->assertSame(1, (int) $over['social_collage_daily_limit']);
        $this->assertSame(1, (int) $over['social_single_daily_limit']);
        $this->assertSame(17, $this->invoke_private('social_article_daily_limit', array($split)));
        $this->assertSame(array('carousel' => 3, 'collage' => 0, 'single' => 2, 'reel' => 0), $this->invoke_private('social_daily_mix_limits', array(TechnoRapper_AI_Wire::defaults())));
        $this->assertSame(array('carousel' => 3, 'collage' => 1, 'single' => 4, 'reel' => 0), $this->invoke_private('social_daily_mix_limits', array($counts)));
        $reel_mix = $this->plugin->sanitize_settings(array(
            'social_mix_saved' => 1,
            'social_reel_daily_limit' => 1,
            'social_carousel_daily_limit' => 2,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 3,
        ));
        $this->assertSame(1, $reel_mix['social_reel_daily_limit']);
        $this->assertSame(90, (int) $this->plugin->sanitize_settings(array('social_post_format' => 'single'))['social_min_review_score']);
        $this->assertSame(85, (int) $this->plugin->sanitize_settings(array('social_min_review_score' => 85))['social_min_review_score']);
        $this->assertSame(0, (int) $this->plugin->sanitize_settings(array('social_min_review_score' => 0))['social_min_review_score']);
        $this->assertSame(100, (int) $this->plugin->sanitize_settings(array('social_min_review_score' => 140))['social_min_review_score']);
        $this->assertSame('carousel', $reel_mix['social_post_format']);
        $this->assertSame(array('carousel' => 2, 'collage' => 0, 'single' => 3, 'reel' => 1), $this->invoke_private('social_daily_mix_limits', array($reel_mix)));
        $reels_only = $this->plugin->sanitize_settings(array(
            'social_mix_saved' => 1,
            'social_reel_daily_limit' => 2,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 0,
        ));
        $this->assertSame('reel', $reels_only['social_post_format']);
        $this->assertSame('collage', $this->invoke_private('social_next_pack_kind', array(array(
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 2,
            'social_single_daily_limit' => 1,
            'social_post_format' => 'single',
        ))));
    }

    public function test_social_news_formats_are_types_not_example_headlines() {
        $catalog = TechnoRapper_AI_Wire::social_news_formats();
        $this->assertArrayHasKey('editorial_poster', $catalog);
        $this->assertArrayHasKey('photo_brief', $catalog);
        $this->assertArrayHasKey('weekly_recap', $catalog);
        $this->assertArrayHasKey('numbered_roundup', $catalog);
        $this->assertArrayHasKey('product_roundup', $catalog);
        $this->assertArrayHasKey('rumor_card', $catalog);
        $this->assertArrayHasKey('device_mockup', $catalog);
        $this->assertArrayHasKey('breaking_kicker', $catalog);
        $blob = wp_json_encode($catalog);
        foreach (array(
            'Google for Startups',
            'Ray-Ban',
            'Anthropic',
            'Bengaluru',
            'ChatGPT',
            'iPhone Duo',
            'itsmangalore',
            'aadhunik',
            'TechSavvy',
            'BetaProfiles',
            'Siri Could Act',
        ) as $banned) {
            $this->assertStringNotContainsString($banned, $blob);
        }
        $this->assertSame('cover', $catalog['weekly_recap']['role']);
        $this->assertSame('story', $catalog['photo_brief']['role']);
    }

    public function test_social_news_format_settings_are_multi_select_and_sanitized() {
        $clean = $this->plugin->sanitize_settings(array(
            'social_platform_toggles' => '1',
            'social_news_formats' => array('photo_brief', 'weekly_recap', 'not-a-format', 'photo_brief'),
        ));
        $this->assertSame(array('photo_brief', 'weekly_recap'), $clean['social_news_formats']);
        $empty = $this->plugin->sanitize_settings(array(
            'social_platform_toggles' => '1',
            'social_news_formats' => array(),
        ));
        $this->assertSame(array('editorial_poster'), $empty['social_news_formats']);
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_news_formats' => array('device_mockup', 'rumor_card'),
        )), false);
        $preserved = $this->plugin->sanitize_settings(array(
            'auto_publish' => 1,
            'max_posts_per_day' => 10,
        ));
        $this->assertSame(array('device_mockup', 'rumor_card'), $preserved['social_news_formats']);
    }

    public function test_custom_social_idea_joins_selected_story_formats() {
        $this->invoke_private('save_social_custom_ideas', array(array(
            array(
                'id' => 'idea_neon01',
                'title' => 'Night neon recap',
                'description' => 'Dark gradient, huge stacked title, one cyan accent bar.',
                'image_id' => 0,
                'enabled' => 1,
            ),
            array(
                'id' => 'idea_off02',
                'title' => 'Unused sketch',
                'description' => 'Should stay out of rotation while disabled.',
                'enabled' => 0,
            ),
        )));
        $settings = array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_news_formats' => array('editorial_poster'),
        ));
        $story = $this->invoke_private('selected_social_news_formats', array($settings, 'story'));
        $this->assertContains('editorial_poster', $story);
        $this->assertContains('idea_neon01', $story);
        $this->assertNotContains('idea_off02', $story);
        $cover = $this->invoke_private('selected_social_news_formats', array($settings, 'cover'));
        $this->assertNotContains('idea_neon01', $cover);
        $rejected = $this->invoke_private('sanitize_social_idea_item', array(array(
            'title' => 'Missing description',
            'description' => '',
        )));
        $this->assertNull($rejected);
        $settings = array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_news_formats' => array('editorial_poster', 'photo_brief'),
        ));
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'No cover wanted'));
        $cover_id = $this->invoke_private('maybe_social_pack_cover', array(array(
            array('post' => get_post($post_id), 'copy' => array(), 'image_path' => ''),
            array('post' => get_post($post_id), 'copy' => array(), 'image_path' => ''),
        ), $settings));
        $this->assertSame(0, $cover_id);
        $_POST['social_idea_title'] = 'Studio stack';
        $_POST['social_idea_description'] = 'White background, clustered generic devices, headline at the top.';
        $this->invoke_private('process_social_ideas_from_request');
        unset($_POST['social_idea_title'], $_POST['social_idea_description']);
        $uploaded = $this->invoke_private('social_custom_ideas');
        $titles = array();
        foreach ($uploaded as $idea) {
            $titles[] = $idea['title'];
        }
        $this->assertContains('Studio stack', $titles);
        $this->assertContains('Night neon recap', $titles);
    }

    public function test_social_copy_keeps_highlighted_bullets() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'A new cloud grant for founders',
            'post_excerpt' => 'The program adds credits. Mentors join next month. Early tool access is included.',
        ));
        $copy = $this->invoke_private('sanitize_social_copy', array(array(
            'on_image_headline' => 'Cloud grants for founders',
            'on_image_kicker' => 'AI NEWS',
            'on_image_bullets' => array(
                'New program to support **AI-first founders**.',
                'Offers **cloud credits** and mentorship.',
                'Focus on healthcare, education, and SMEs.',
            ),
        ), get_post($post_id)));
        $this->assertSame('AI NEWS', $copy['on_image_kicker']);
        $this->assertCount(3, $copy['on_image_bullets']);
        $this->assertStringContainsString('**AI-first founders**', $copy['on_image_bullets'][0]);
        $segments = $this->invoke_private('social_highlight_segments', array($copy['on_image_bullets'][0]));
        $this->assertTrue($segments[1]['highlight']);
        $this->assertSame('AI-first founders', $segments[1]['text']);
    }

    public function test_photo_brief_and_weekly_recap_render_instagram_portrait() {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg') || !function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is required to render news graphic formats.');
        }
        $im = imagecreatetruecolor(64, 96);
        imagefilledrectangle($im, 0, 0, 63, 95, imagecolorallocate($im, 12, 28, 64));
        ob_start();
        imagejpeg($im, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($im);
        $brief = $this->invoke_private('brand_social_image', array($bytes, array(
            '_news_format' => 'photo_brief',
            'on_image_headline' => 'A new program for founders',
            'on_image_bullets' => array(
                'Support **AI-first founders**.',
                'Offers **cloud credits** and mentors.',
                'Focus on healthcare and education.',
            ),
        )));
        $this->assertTrue(is_string($brief) && $brief !== '');
        $info = getimagesizefromstring($brief);
        $this->assertSame(1080, $info[0]);
        $this->assertSame(1350, $info[1]);
        $cover = $this->invoke_private('render_local_social_graphic', array(array(
            '_news_format' => 'weekly_recap',
            'on_image_headline' => 'THIS WEEK IN TECH',
            'on_image_subhead' => 'Stories from this site',
        )));
        $this->assertTrue(is_string($cover) && $cover !== '');
        $cover_info = getimagesizefromstring($cover);
        $this->assertSame(1080, $cover_info[0]);
        $this->assertSame(1350, $cover_info[1]);
        $dir = '/opt/cursor/artifacts';
        if (is_dir($dir) || mkdir($dir, 0755, true)) {
            file_put_contents($dir . '/instagram_photo_brief.jpg', $brief);
            file_put_contents($dir . '/instagram_weekly_recap.jpg', $cover);
        }
    }

    public function test_device_mockup_prompt_allows_product_photography() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Foldable phone display news',
        ));
        $prompt = $this->invoke_private('social_image_prompt', array(get_post($post_id), array(
            '_news_format' => 'device_mockup',
            'visual_brief' => 'A generic foldable phone on a pale desk',
        )));
        $this->assertStringContainsString('product photograph', $prompt);
        $this->assertStringContainsString('Do not render any text', $prompt);
        $this->assertStringNotContainsString('or product mockup', $prompt);
        $this->assertStringContainsString('A generic foldable phone', $prompt);
        $idea_prompt = $this->invoke_private('social_image_prompt', array(get_post($post_id), array(
            '_news_format' => 'idea_missing',
            'visual_brief' => 'Night city from a rooftop',
        )));
        $this->assertStringContainsString('Untitled photoreal cinematic photograph', $idea_prompt);
    }

    public function test_carousel_cover_and_curiosity_slide_ask_for_a_swipe() {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is required to render the curiosity slide.');
        }
        $posts = array();
        foreach (array('First story', 'Second story', 'Third story') as $title) {
            $posts[] = array(
                'post' => get_post($this->factory()->post->create(array(
                    'post_status' => 'publish',
                    'post_title' => $title,
                    'post_excerpt' => $title,
                ))),
                'copy' => array('on_image_headline' => $title, 'cta' => 'Read more'),
                'image_url' => 'https://example.org/' . sanitize_title($title) . '.jpg',
            );
        }
        $cover = $this->invoke_private('social_cover_copy', array($posts, 'weekly_recap'));
        $this->assertStringContainsString('THIS WEEK IN', $cover['on_image_headline']);
        $this->assertStringContainsString('SWIPE', $cover['on_image_subhead']);
        $numbered = $this->invoke_private('social_cover_copy', array($posts, 'numbered_roundup'));
        $this->assertStringContainsString('Wait for the last one', $numbered['on_image_headline']);
        $product = $this->invoke_private('social_cover_copy', array($posts, 'product_roundup'));
        $this->assertSame('SWIPE TO THE LAST ONE', $product['on_image_subhead']);
        $curiosity = $this->invoke_private('social_curiosity_slide_copy', array($posts));
        $this->assertSame('THE NEXT SLIDE IS THE ONE', $curiosity['on_image_headline']);
        $this->assertStringContainsString('SWIPE', $curiosity['on_image_subhead']);
        $caption = $this->invoke_private('instagram_pack_caption', array($posts));
        $this->assertStringContainsString('Don\'t skip the last slide.', $caption);
        $this->assertStringContainsString('First story', $caption);
        $this->assertStringContainsString('Link in bio.', $caption);
        $this->assertStringNotContainsString('http://', $caption);
        $this->assertStringNotContainsString('https://', $caption);
        $urls = $this->invoke_private('instagram_carousel_urls', array($posts, array(
            'social_news_formats' => array('editorial_poster'),
        )));
        $this->assertCount(4, $urls);
        $this->assertSame($posts[0]['image_url'], $urls[0]);
        $this->assertSame($posts[1]['image_url'], $urls[1]);
        $this->assertSame($posts[2]['image_url'], $urls[3]);
        $this->assertNotSame($posts[2]['image_url'], $urls[2]);
    }

    public function test_instagram_job_packs_three_website_articles_into_one_carousel() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_post_format' => 'carousel',
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 3,
            'social_slide_count' => 3,
            'social_daily_limit' => 10,
            'social_news_formats' => array('editorial_poster', 'weekly_recap'),
        )), false);
        $headlines = array(
            '13-inch or 15-inch Air?',
            'When fake IDs become easier to scale',
            'When big tech becomes politics',
        );
        $post_ids = array();
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($headlines as $index => $headline) {
            $attachment_id = $this->create_social_jpeg_attachment();
            if ($attachment_id < 1) {
                $this->markTestSkipped('GD and uploads are required to pack Instagram carousels.');
            }
            $post_id = $this->factory()->post->create(array(
                'post_status' => 'publish',
                'post_title' => $headline,
                'post_excerpt' => 'Story ' . (string) ($index + 1),
            ));
            update_post_meta($post_id, '_tr_ai_wire_queue_id', 80 + $index);
            update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
            update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
                'hook' => $headline,
                'on_image_headline' => $headline,
                'x' => $headline,
                'instagram' => $headline,
                'instagram_alt' => $headline,
                'cta' => 'Read the full stories on TechnoRapper',
                'hashtags' => array('tech'),
            )));
            $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
                'post_id' => $post_id,
                'platform' => 'instagram',
                'status' => 'queued',
                'image_attachment_id' => $attachment_id,
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $post_ids[] = $post_id;
        }
        $requests = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$requests) {
            $requests[] = array('url' => (string) $url, 'body' => $args['body'] ?? array());
            if (strpos((string) $url, 'api.openai.com') !== false) {
                return new WP_Error('blocked', 'Pack should reuse cached copy and graphics.');
            }
            if (strpos((string) $url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos((string) $url, 'content_publishing_limit') !== false) {
                return $this->mock_http_ok(array('quota_usage' => 1, 'config' => array('quota_total' => 100)));
            }
            if (strpos((string) $url, '/media_publish') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-pack-media'));
            }
            if (strpos((string) $url, '/media') !== false) {
                $body = is_array($args['body'] ?? null) ? $args['body'] : array();
                $id = (!empty($body['media_type']) && $body['media_type'] === 'CAROUSEL') ? 'ig-pack-parent' : ('ig-pack-child-' . (string) count($requests));
                return $this->mock_http_ok(array('id' => $id));
            }
            if (strpos((string) $url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos((string) $url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/p/pack/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        $rows = $wpdb->get_results("SELECT post_id, status, remote_id, remote_url FROM {$wpdb->prefix}tr_ai_wire_social WHERE platform = 'instagram' ORDER BY post_id ASC", ARRAY_A);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('published', $row['status']);
            $this->assertSame('ig-pack-media', $row['remote_id']);
            $this->assertSame('https://www.instagram.com/p/pack/', $row['remote_url']);
        }
        $child_items = 0;
        $parents = 0;
        $pack_caption = '';
        foreach ($requests as $request) {
            if (strpos($request['url'], '/media_publish') !== false || strpos($request['url'], '/media') === false) {
                continue;
            }
            $body = is_array($request['body']) ? $request['body'] : array();
            if (($body['is_carousel_item'] ?? '') === 'true') {
                $child_items++;
            }
            if (($body['media_type'] ?? '') === 'CAROUSEL') {
                $parents++;
                $pack_caption = (string) ($body['caption'] ?? '');
            }
        }
        $this->assertSame(5, $child_items);
        $this->assertSame(1, $parents);
        $this->assertStringContainsString('Don\'t skip the last slide.', $pack_caption);
        $this->assertStringContainsString('Today on ' . get_bloginfo('name'), $pack_caption);
        $this->assertStringContainsString('13-inch or 15-inch Air?', $pack_caption);
        $this->assertStringContainsString('When fake IDs become easier to scale', $pack_caption);
        $this->assertStringContainsString('When big tech becomes politics', $pack_caption);
        $this->assertStringContainsString('Link in bio.', $pack_caption);
        $this->assertStringNotContainsString('http://', $pack_caption);
        $this->assertStringNotContainsString('https://', $pack_caption);
        $this->assertSame(3, $this->invoke_private('social_published_today'));
    }

    public function test_social_batch_holds_until_configured_count_then_resets() {
        $this->onboard_social(array('x', 'instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_post_format' => 'carousel',
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 3,
            'social_slide_count' => 5,
            'social_daily_limit' => 10,
        )), false);
        global $wpdb;
        $post_ids = array();
        for ($i = 1; $i <= 4; $i++) {
            $post_id = $this->factory()->post->create(array(
                'post_status' => 'publish',
                'post_title' => 'Waiting story ' . (string) $i,
            ));
            update_post_meta($post_id, '_tr_ai_wire_queue_id', 90 + $i);
            $this->fire_social_publish($post_id);
            $post_ids[] = $post_id;
        }
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
        $this->assertCount(4, $this->invoke_private('social_batch_ids'));
        $this->assertFalse(wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK));

        $fifth = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Waiting story 5',
        ));
        update_post_meta($fifth, '_tr_ai_wire_queue_id', 95);
        $this->fire_social_publish($fifth);
        $this->assertSame(array(), $this->invoke_private('social_batch_ids'));
        $queued = $wpdb->get_results("SELECT post_id, platform, status FROM {$wpdb->prefix}tr_ai_wire_social ORDER BY post_id ASC, platform ASC", ARRAY_A);
        $this->assertCount(10, $queued);
        foreach (array_merge($post_ids, array($fifth)) as $post_id) {
            $platforms = $wpdb->get_col($wpdb->prepare("SELECT platform FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d ORDER BY platform ASC", $post_id));
            $this->assertSame(array('instagram', 'x'), $platforms);
        }
        $this->assertNotFalse(wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK));

        $sixth = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Waiting story 6',
        ));
        update_post_meta($sixth, '_tr_ai_wire_queue_id', 96);
        $this->fire_social_publish($sixth);
        $this->assertSame(array($sixth), $this->invoke_private('social_batch_ids'));
        $this->assertSame(10, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
    }

    public function test_process_now_flushes_an_incomplete_social_batch() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_post_format' => 'carousel',
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 3,
            'social_slide_count' => 5,
        )), false);
        $post_ids = array();
        for ($i = 1; $i <= 2; $i++) {
            $post_id = $this->factory()->post->create(array(
                'post_status' => 'publish',
                'post_title' => 'Leftover story ' . (string) $i,
            ));
            update_post_meta($post_id, '_tr_ai_wire_queue_id', 80 + $i);
            $this->fire_social_publish($post_id);
            $post_ids[] = $post_id;
        }
        global $wpdb;
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
        $this->assertCount(2, $this->invoke_private('social_batch_ids'));
        $this->assertTrue($this->invoke_private('flush_social_batch', array(true)));
        $this->assertSame(array(), $this->invoke_private('social_batch_ids'));
        $this->assertSame(2, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE platform = 'instagram' AND status = 'queued'"));
        $this->assertSame($post_ids, array_map('intval', $wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}tr_ai_wire_social ORDER BY post_id ASC")));
    }

    public function test_independent_posts_start_after_the_daily_carousel_quota() {
        $this->onboard_social(array('x', 'instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_post_format' => 'carousel',
            'social_mix_saved' => 1,
            'social_slide_count' => 5,
            'social_carousel_daily_limit' => 1,
            'social_single_daily_limit' => 3,
            'social_daily_limit' => 10,
        )), false);
        global $wpdb;
        for ($i = 1; $i <= 5; $i++) {
            $post_id = $this->factory()->post->create(array(
                'post_status' => 'publish',
                'post_title' => 'Carousel story ' . (string) $i,
            ));
            update_post_meta($post_id, '_tr_ai_wire_queue_id', 70 + $i);
            $this->fire_social_publish($post_id);
        }
        $this->assertSame(10, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
        $this->assertSame('pack', (string) get_post_meta((int) $wpdb->get_var("SELECT post_id FROM {$wpdb->prefix}tr_ai_wire_social ORDER BY post_id ASC LIMIT 1"), '_tr_ai_wire_social_mode', true));
        $this->assertSame(array('carousel' => 1, 'collage' => 0, 'single' => 0, 'reel' => 0), $this->invoke_private('social_mix_today'));

        $independent = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Independent story',
        ));
        update_post_meta($independent, '_tr_ai_wire_queue_id', 76);
        $this->fire_social_publish($independent);
        $this->assertSame(array(), $this->invoke_private('social_batch_ids'));
        $this->assertSame('single', (string) get_post_meta($independent, '_tr_ai_wire_social_mode', true));
        $this->assertSame(12, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
        $this->assertSame(array('carousel' => 1, 'collage' => 0, 'single' => 1, 'reel' => 0), $this->invoke_private('social_mix_today'));
    }

    public function test_mix_counts_can_run_only_single_stories() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_mix_saved' => 1,
            'social_post_format' => 'carousel',
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 2,
            'social_slide_count' => 5,
        )), false);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Just one story',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 81);
        $this->fire_social_publish($post_id);
        $this->assertSame(array(), $this->invoke_private('social_batch_ids'));
        $this->assertSame('single', (string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
        global $wpdb;
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = " . (int) $post_id));
    }

    public function test_default_mix_holds_articles_for_carousel_instead_of_posting_singles() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_instagram_enabled' => 1,
            'social_x_enabled' => 0,
            'social_youtube_enabled' => 0,
        )), false);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'First carousel story',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 201);
        $this->fire_social_publish($post_id);
        $this->assertSame(array($post_id), $this->invoke_private('social_batch_ids'));
        $this->assertSame('', (string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
        global $wpdb;
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
        $this->assertFalse($this->invoke_private('flush_social_batch', array(true, true)));
        $this->assertSame(array($post_id), $this->invoke_private('social_batch_ids'));
    }

    public function test_unsaved_mix_still_honors_collage_quota() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_instagram_enabled' => 1,
            'social_x_enabled' => 0,
            'social_youtube_enabled' => 0,
            'social_mix_saved' => 0,
            'social_post_format' => 'single',
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 1,
            'social_single_daily_limit' => 2,
            'social_slide_count' => 4,
        )), false);
        $this->assertSame('collage', $this->invoke_private('social_next_pack_kind', array(get_option(TechnoRapper_AI_Wire::OPTION, array()))));
        $ids = array();
        for ($i = 1; $i <= 4; $i++) {
            $post_id = $this->factory()->post->create(array(
                'post_status' => 'publish',
                'post_title' => 'Collage story ' . (string) $i,
            ));
            update_post_meta($post_id, '_tr_ai_wire_queue_id', 210 + $i);
            $this->fire_social_publish($post_id);
            $ids[] = $post_id;
        }
        $this->assertSame(array(), $this->invoke_private('social_batch_ids'));
        $this->assertSame('collage', (string) get_post_meta($ids[0], '_tr_ai_wire_social_mode', true));
        global $wpdb;
        $this->assertSame(4, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE platform = 'instagram' AND status = 'queued'"));
        $this->assertSame(array('carousel' => 0, 'collage' => 1, 'single' => 0, 'reel' => 0), $this->invoke_private('social_mix_today'));
    }

    public function test_x_keys_and_tokens_sign_oauth1_requests() {
        $credentials = array(
            'x_api_key' => 'api-key',
            'x_api_secret' => 'api-secret',
            'x_access_token' => 'access-token',
            'x_access_token_secret' => 'access-secret',
            'x_user_access_token' => '',
        );
        $this->assertTrue(TechnoRapper_AI_Wire::x_oauth1_ready($credentials));
        $header = $this->invoke_private('x_authorization_header', array('POST', 'https://api.x.com/2/tweets', $credentials));
        $this->assertStringStartsWith('OAuth ', $header);
        $this->assertStringContainsString('oauth_consumer_key=', $header);
        $this->assertStringNotContainsString('Bearer ', $header);
        $bearer = $this->invoke_private('x_authorization_header', array('POST', 'https://api.x.com/2/tweets', array(
            'x_user_access_token' => 'oauth2-user-token',
        )));
        $this->assertSame('Bearer oauth2-user-token', $bearer);
        $error = $this->invoke_private('publish_x_post', array('Hello', '/tmp/missing.jpg', array(
            'x_user_access_token' => '996743132-NotABearerToken',
        )));
        $this->assertTrue(is_wp_error($error));
        $this->assertSame('tr_ai_wire_x_oauth1_incomplete', $error->get_error_code());
    }

    public function test_x_pack_posts_one_tweet_with_multiple_images() {
        $this->onboard_social(array('x'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_post_format' => 'carousel',
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 3,
            'social_slide_count' => 5,
            'social_daily_limit' => 10,
        )), false);
        $headlines = array('13-inch or 15-inch Air?', 'When fake IDs become easier to scale', 'When big tech becomes politics');
        $post_ids = array();
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($headlines as $index => $headline) {
            $attachment_id = $this->create_social_jpeg_attachment();
            if ($attachment_id < 1) {
                $this->markTestSkipped('GD and uploads are required to pack X carousels.');
            }
            $post_id = $this->factory()->post->create(array(
                'post_status' => 'publish',
                'post_title' => $headline,
            ));
            update_post_meta($post_id, '_tr_ai_wire_queue_id', 60 + $index);
            update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
            update_post_meta($post_id, '_tr_ai_wire_social_mode', 'pack');
            update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
                'hook' => $headline,
                'on_image_headline' => $headline,
                'x' => $headline,
                'instagram' => $headline,
                'instagram_alt' => $headline,
            )));
            $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
                'post_id' => $post_id,
                'platform' => 'x',
                'status' => 'queued',
                'image_attachment_id' => $attachment_id,
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $post_ids[] = $post_id;
        }
        $requests = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$requests) {
            $requests[] = array('url' => (string) $url, 'headers' => $args['headers'] ?? array(), 'body' => $args['body'] ?? array());
            if (strpos((string) $url, 'api.openai.com') !== false) {
                return new WP_Error('blocked', 'Pack should reuse cached copy and graphics.');
            }
            if (strpos((string) $url, 'media/upload/initialize') !== false) {
                return $this->mock_http_ok(array('data' => array('id' => 'x-media-' . (string) count($requests))));
            }
            if (strpos((string) $url, '/append') !== false || strpos((string) $url, '/finalize') !== false) {
                return $this->mock_http_ok(array('data' => array('processing_info' => array('state' => 'succeeded'))));
            }
            if (strpos((string) $url, 'api.x.com/2/tweets') !== false) {
                return $this->mock_http_ok(array('data' => array('id' => 'x-pack-tweet')));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        $rows = $wpdb->get_results("SELECT post_id, status, remote_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE platform = 'x' ORDER BY post_id ASC", ARRAY_A);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('published', $row['status']);
            $this->assertSame('x-pack-tweet', $row['remote_id']);
        }
        $tweet = null;
        $uploads = 0;
        foreach ($requests as $request) {
            if (strpos($request['url'], 'media/upload/initialize') !== false) {
                $uploads++;
            }
            if (strpos($request['url'], 'api.x.com/2/tweets') !== false) {
                $tweet = $request;
            }
        }
        $this->assertSame(3, $uploads);
        $this->assertNotNull($tweet);
        $body = is_string($tweet['body']) ? json_decode($tweet['body'], true) : $tweet['body'];
        $this->assertCount(3, $body['media']['media_ids']);
        $this->assertStringContainsString('Today on ' . get_bloginfo('name'), $body['text']);
        $auth = is_array($tweet['headers']) ? ($tweet['headers']['Authorization'] ?? '') : '';
        $this->assertStringStartsWith('Bearer ', $auth);
    }

    public function test_social_collage_is_one_instagram_portrait() {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is required to render a collage.');
        }
        $tiles = array();
        foreach (array('bottom-center', 'left-stack', 'kicker-bar', 'cover-top') as $variant) {
            $im = imagecreatetruecolor(1080, 1350);
            imagefilledrectangle($im, 0, 0, 1079, 1349, imagecolorallocate($im, 20, 18, 22));
            ob_start();
            imagejpeg($im, null, 80);
            $bytes = ob_get_clean();
            imagedestroy($im);
            $branded = $this->invoke_private('brand_social_image', array($bytes, array(
                '_poster_variant' => $variant,
                'on_image_headline' => 'When fake IDs become easier to scale',
                'on_image_subhead' => 'OnlyFake raises questions',
            )));
            $this->assertTrue(is_string($branded) && $branded !== '');
            $tiles[] = $branded;
        }
        $collage = $this->invoke_private('compose_social_collage', array($tiles));
        $this->assertTrue(is_string($collage) && $collage !== '');
        $info = getimagesizefromstring($collage);
        $this->assertSame(1080, $info[0]);
        $this->assertSame(1350, $info[1]);
        $five = $this->invoke_private('social_collage_cells', array(5, 1080, 1350, 8));
        $this->assertCount(5, $five);
        $six = $this->invoke_private('social_collage_cells', array(6, 1080, 1350, 8));
        $this->assertCount(6, $six);
        $this->assertSame(1080, $six[2]['x'] + $six[2]['w']);
        $this->assertSame(1350, $six[5]['y'] + $six[5]['h']);
        $dir = '/opt/cursor/artifacts';
        if (is_dir($dir) || mkdir($dir, 0755, true)) {
            file_put_contents($dir . '/instagram_poster_collage.jpg', $collage);
        }
    }

    public function test_instagram_carousel_publishes_child_containers_then_parent() {
        $this->onboard_social(array('instagram'));
        $requests = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$requests) {
            $requests[] = array('url' => (string) $url, 'body' => $args['body'] ?? array());
            if (strpos((string) $url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos((string) $url, 'content_publishing_limit') !== false) {
                return $this->mock_http_ok(array('quota_usage' => 1, 'config' => array('quota_total' => 100)));
            }
            if (strpos((string) $url, '/media_publish') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-carousel-media'));
            }
            if (strpos((string) $url, '/media') !== false) {
                $body = is_array($args['body'] ?? null) ? $args['body'] : array();
                $id = (!empty($body['media_type']) && $body['media_type'] === 'CAROUSEL') ? 'ig-carousel-parent' : ('ig-child-' . (string) (count($requests)));
                return $this->mock_http_ok(array('id' => $id));
            }
            if (strpos((string) $url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos((string) $url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/p/carousel/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $result = $this->invoke_private('publish_instagram_post', array(
            'Caption for the carousel',
            'Alt text',
            array(
                'https://example.org/slide-1.jpg',
                'https://example.org/slide-2.jpg',
                'https://example.org/slide-3.jpg',
            ),
            get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION),
        ));
        $this->assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('ig-carousel-media', $result['id']);
        $this->assertSame('https://www.instagram.com/p/carousel/', $result['url']);
        $child_items = 0;
        $parents = 0;
        foreach ($requests as $request) {
            if (strpos($request['url'], '/media_publish') !== false || strpos($request['url'], '/media') === false) {
                continue;
            }
            $body = is_array($request['body']) ? $request['body'] : array();
            if (($body['is_carousel_item'] ?? '') === 'true') {
                $child_items++;
                $this->assertArrayNotHasKey('caption', $body);
            }
            if (($body['media_type'] ?? '') === 'CAROUSEL') {
                $parents++;
                $this->assertSame("Caption for the carousel\n\nLink in bio.", $body['caption'] ?? '');
                $this->assertStringContainsString(',', (string) ($body['children'] ?? ''));
            }
        }
        $this->assertSame(3, $child_items);
        $this->assertSame(1, $parents);
    }

    public function test_reel_copy_is_one_spoken_story_not_a_pack() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Qualcomm pushes into data centers',
            'post_excerpt' => 'The chipmaker wants a larger share of AI servers this year.',
        ));
        $copy = $this->invoke_private('sanitize_social_copy', array(array(
            'hook' => 'Your next phone chip has a server cousin',
            'on_image_headline' => 'Qualcomm wants the data center',
            'on_image_subhead' => 'AI servers are the new battleground',
            'reel_script' => 'Your next phone chip has a server cousin. Qualcomm is chasing data-center silicon, not just handsets. That shift is why the next laptop and the next rack look related. Full story on TechnoRapper.',
            'reel_captions' => array(
                'Your next phone chip',
                'has a server cousin',
                'Data-center silicon now',
                'Full story on TechnoRapper',
            ),
        ), get_post($post_id)));
        $this->assertStringContainsString('server cousin', $copy['reel_script']);
        $this->assertStringNotContainsString('Today on', $copy['reel_script']);
        $this->assertCount(4, $copy['reel_captions']);
        $timeline = $this->invoke_private('build_social_reel_timeline', array($copy, 16));
        $kinds = array();
        foreach ($timeline as $clip) {
            $kinds[] = $clip['kind'];
            $this->assertArrayHasKey('caption', $clip);
            $this->assertGreaterThan(0.4, $clip['duration']);
        }
        $this->assertSame('broll', $kinds[0]);
        $this->assertSame('end', $kinds[count($kinds) - 1]);
        $this->assertContains('broll', $kinds);
        $this->assertNotContains('open', $kinds);
        $this->assertNotContains('slide', $kinds);
        $this->assertSame(1, substr_count(implode(' ', $kinds), 'end'));
        $broll_count = 0;
        foreach ($kinds as $kind) {
            if ($kind === 'broll') {
                $broll_count++;
            }
        }
        $this->assertGreaterThanOrEqual(3, $broll_count);
    }

    public function test_reel_script_fallback_teaches_the_article_takeaway() {
        $queue_id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'rewrite_json' => wp_json_encode(array(
                'lede' => 'A lede that is not the takeaway.',
                'key_takeaways' => array('The same silicon family now ships in phones and AI racks.'),
            )),
        ), array('id' => $queue_id));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Qualcomm pushes into data centers',
            'post_excerpt' => 'The chipmaker wants a larger share of AI servers this year.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', $queue_id);
        $script = $this->invoke_private('fallback_reel_script', array(array(
            'hook' => 'Your next phone chip has a server cousin',
        ), get_post($post_id)));
        $this->assertStringContainsString('Your next phone chip has a server cousin', $script);
        $this->assertStringContainsString('same silicon family', $script);
        $this->assertStringNotContainsString('Today on', $script);
        $this->assertSame('gpt-4o-mini-tts', TechnoRapper_AI_Wire::SOCIAL_TTS_MODEL);
        $this->assertSame('cedar', TechnoRapper_AI_Wire::SOCIAL_TTS_VOICE);
    }

    public function test_reel_script_is_written_by_the_current_writer_model() {
        $this->onboard_social(array('instagram'));
        $settings = array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'writer_model' => 'gpt-6.1-terra',
            'social_post_format' => 'reel',
        ));
        update_option(TechnoRapper_AI_Wire::OPTION, $settings, false);
        $queue_id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'rewrite_json' => wp_json_encode(array(
                'lede' => 'Qualcomm is putting phone silicon into AI servers.',
                'key_takeaways' => array('The same chip family now ships in phones and racks.'),
            )),
        ), array('id' => $queue_id));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Qualcomm pushes into data centers',
            'post_excerpt' => 'The chipmaker wants a larger share of AI servers this year.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', $queue_id);
        $captured = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$captured) {
            if (strpos((string) $url, 'api.openai.com/v1/responses') === false) {
                return new WP_Error('blocked', 'Unexpected URL ' . $url);
            }
            $payload = json_decode((string) ($args['body'] ?? ''), true);
            $captured['model'] = is_array($payload) ? (string) ($payload['model'] ?? '') : '';
            $captured['user'] = '';
            foreach ((array) ($payload['input'] ?? array()) as $message) {
                if (($message['role'] ?? '') !== 'user') {
                    continue;
                }
                foreach ((array) ($message['content'] ?? array()) as $content) {
                    $captured['user'] .= (string) ($content['text'] ?? '');
                }
            }
            return $this->openai_json_http(array(
                'hook' => 'Your next phone chip has a server cousin',
                'on_image_headline' => 'Qualcomm wants the rack',
                'on_image_subhead' => 'AI servers are the new battleground',
                'visual_brief' => 'Night server hall with a phone in the foreground',
                'cta' => 'Read the full story on TechnoRapper',
                'x_text' => 'Qualcomm is chasing data-center silicon, not just handsets.',
                'instagram_caption' => 'Your next phone chip has a server cousin.',
                'instagram_alt' => 'A phone in front of a server hall',
                'hashtags' => array('AI', 'Qualcomm'),
                'reel_script' => 'Your next phone chip has a server cousin. The same silicon family now ships in phones and AI racks, so the next laptop and the next server look related. That is why this push matters for your next device. Full story on TechnoRapper.',
                'reel_captions' => array(
                    'Your next phone chip',
                    'has a server cousin',
                    'Same silicon in racks',
                    'That is the shift',
                ),
            ));
        }, 10, 3);
        $raw = $this->invoke_private('generate_social_copy', array(get_post($post_id), $settings));
        $this->assertFalse(is_wp_error($raw), is_wp_error($raw) ? $raw->get_error_message() : '');
        $this->assertSame('gpt-6.1-terra', $captured['model']);
        $this->assertStringContainsString('same Writer pass', $captured['user']);
        $this->assertStringContainsString('suitable for a general audience', $captured['user']);
        $this->assertStringContainsString('reel_script', $captured['user']);
        $this->assertStringContainsString('youtube_title', $captured['user']);
        $this->assertStringContainsString('youtube_description', $captured['user']);
        $this->assertStringContainsString('youtube_tags', $captured['user']);
        $this->assertStringContainsString('key_takeaways', $captured['user']);
        $this->assertStringContainsString('same chip family', $captured['user']);
        $copy = $this->invoke_private('sanitize_social_copy', array($raw, get_post($post_id)));
        $this->assertStringContainsString('same silicon family now ships', $copy['reel_script']);
        $this->assertGreaterThanOrEqual(40, count(preg_split('/\s+/', $copy['reel_script'], -1, PREG_SPLIT_NO_EMPTY)));
    }

    public function test_reel_format_asks_writer_again_when_cached_copy_has_no_script() {
        $this->onboard_social(array('instagram'));
        $settings = array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'writer_model' => 'gpt-6.1-terra',
            'social_post_format' => 'reel',
        ));
        update_option(TechnoRapper_AI_Wire::OPTION, $settings, false);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Qualcomm pushes into data centers',
            'post_excerpt' => 'The chipmaker wants a larger share of AI servers this year.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'Your next phone chip has a server cousin',
            'on_image_headline' => 'Qualcomm wants the rack',
            'x' => 'Cached X caption',
            'instagram' => 'Cached Instagram caption',
            'instagram_alt' => 'Alt',
        )));
        $captured = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$captured) {
            if (strpos((string) $url, 'api.openai.com/v1/responses') === false) {
                return new WP_Error('blocked', 'Unexpected URL ' . $url);
            }
            $payload = json_decode((string) ($args['body'] ?? ''), true);
            $captured['model'] = is_array($payload) ? (string) ($payload['model'] ?? '') : '';
            return $this->openai_json_http(array(
                'hook' => 'Your next phone chip has a server cousin',
                'on_image_headline' => 'Qualcomm wants the rack',
                'on_image_subhead' => 'AI servers are the new battleground',
                'cta' => 'Read the full story on TechnoRapper',
                'x_text' => 'Writer X caption',
                'instagram_caption' => 'Writer Instagram caption',
                'instagram_alt' => 'Alt',
                'reel_script' => 'Your next phone chip has a server cousin. Qualcomm is putting that same family into AI racks, not just pockets. That is the takeaway before you tap through. Full story on TechnoRapper.',
                'reel_captions' => array('Phone chip, server cousin', 'Same family in racks', 'That is the takeaway'),
            ));
        }, 10, 3);
        $copy = $this->invoke_private('social_copy', array(get_post($post_id), $settings));
        $this->assertSame('gpt-6.1-terra', $captured['model']);
        $this->assertStringContainsString('putting that same family into AI racks', $copy['reel_script']);
    }

    public function test_reel_format_never_packs_multiple_articles() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_post_format' => 'reel',
            'social_mix_saved' => 0,
            'social_slide_count' => 5,
            'social_carousel_daily_limit' => 2,
        )), false);
        $settings = $this->plugin->sanitize_settings(get_option(TechnoRapper_AI_Wire::OPTION, array()));
        $post_ids = array();
        for ($i = 0; $i < 3; $i++) {
            $post_id = $this->factory()->post->create(array(
                'post_status' => 'publish',
                'post_title' => 'Reel story ' . (string) ($i + 1),
            ));
            update_post_meta($post_id, '_tr_ai_wire_queue_id', 800 + $i);
            $this->invoke_private('collect_social_article', array($post_id, $settings));
            $post_ids[] = $post_id;
        }
        global $wpdb;
        $rows = $wpdb->get_results("SELECT post_id, platform, status FROM {$wpdb->prefix}tr_ai_wire_social ORDER BY post_id ASC", ARRAY_A);
        $this->assertCount(3, $rows);
        foreach ($post_ids as $post_id) {
            $this->assertSame('reel', (string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
        }
        $this->assertFalse($this->invoke_private('social_row_is_pack', array($rows[0], $settings)));
        $this->assertSame(array(), $this->invoke_private('social_batch_ids'));
        $this->assertSame(array('carousel' => 0, 'collage' => 0, 'single' => 0, 'reel' => 3), $this->invoke_private('social_mix_today'));
    }

    public function test_reel_daily_limit_zero_keeps_reels_off() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_mix_saved' => 1,
            'social_reel_daily_limit' => 0,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 2,
        )), false);
        $settings = $this->plugin->sanitize_settings(get_option(TechnoRapper_AI_Wire::OPTION, array()));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Still story',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 910);
        $this->invoke_private('collect_social_article', array($post_id, $settings));
        $this->assertSame('single', (string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true));
        $this->assertFalse($this->invoke_private('social_wants_reel', array($settings, $post_id)));
    }

    public function test_reel_daily_limit_one_uses_the_days_lead() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_mix_saved' => 1,
            'social_reel_daily_limit' => 1,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 2,
        )), false);
        $settings = $this->plugin->sanitize_settings(get_option(TechnoRapper_AI_Wire::OPTION, array()));
        $lead = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Lead story',
        ));
        $follow = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Follow story',
        ));
        update_post_meta($lead, '_tr_ai_wire_queue_id', 911);
        update_post_meta($follow, '_tr_ai_wire_queue_id', 912);
        $this->invoke_private('collect_social_article', array($lead, $settings));
        $this->invoke_private('collect_social_article', array($follow, $settings));
        $this->assertSame('reel', (string) get_post_meta($lead, '_tr_ai_wire_social_mode', true));
        $this->assertSame('single', (string) get_post_meta($follow, '_tr_ai_wire_social_mode', true));
        $this->assertTrue($this->invoke_private('social_wants_reel', array($settings, $lead)));
        $this->assertFalse($this->invoke_private('social_wants_reel', array($settings, $follow)));
        $this->assertSame(array('carousel' => 0, 'collage' => 0, 'single' => 1, 'reel' => 1), $this->invoke_private('social_mix_today'));
    }

    public function test_instagram_reel_publishes_video_container_not_carousel() {
        $this->onboard_social(array('instagram'));
        $requests = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$requests) {
            $requests[] = array('url' => (string) $url, 'body' => $args['body'] ?? array());
            if (strpos((string) $url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos((string) $url, 'content_publishing_limit') !== false) {
                return $this->mock_http_ok(array('quota_usage' => 1, 'config' => array('quota_total' => 100)));
            }
            if (strpos((string) $url, '/media_publish') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-reel-media'));
            }
            if (strpos((string) $url, '/media') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-reel-container'));
            }
            if (strpos((string) $url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos((string) $url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/reel/spoken/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $result = $this->invoke_private('publish_instagram_post', array(
            'Spoken reel caption',
            'Alt text',
            'https://example.org/poster.jpg',
            get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION),
            'https://example.org/story.mp4',
        ));
        $this->assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('ig-reel-media', $result['id']);
        $this->assertSame('https://www.instagram.com/reel/spoken/', $result['url']);
        $reels = 0;
        $carousels = 0;
        foreach ($requests as $request) {
            if (strpos($request['url'], '/media_publish') !== false || strpos($request['url'], '/media') === false) {
                continue;
            }
            $body = is_array($request['body']) ? $request['body'] : array();
            if (($body['media_type'] ?? '') === 'REELS') {
                $reels++;
                $this->assertSame('https://example.org/story.mp4', $body['video_url'] ?? '');
                $this->assertSame("Spoken reel caption\n\nLink in bio.", $body['caption'] ?? '');
                $this->assertSame('true', (string) ($body['is_ai_generated'] ?? ''));
                $this->assertArrayNotHasKey('image_url', $body);
                $this->assertArrayNotHasKey('children', $body);
            }
            if (($body['media_type'] ?? '') === 'CAROUSEL') {
                $carousels++;
            }
        }
        $this->assertSame(1, $reels);
        $this->assertSame(0, $carousels);
    }

    public function test_youtube_is_queued_only_for_reel_posts() {
        $this->onboard_social(array('instagram', 'youtube'));
        $settings = $this->plugin->sanitize_settings(array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_post_format' => 'reel',
            'social_mix_saved' => 0,
        )));
        $reel_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'YouTube Short story',
        ));
        update_post_meta($reel_id, '_tr_ai_wire_queue_id', 920);
        $this->invoke_private('collect_social_article', array($reel_id, $settings));
        global $wpdb;
        $platforms = $wpdb->get_col($wpdb->prepare("SELECT platform FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d ORDER BY platform", $reel_id));
        $this->assertSame(array('instagram', 'youtube'), $platforms);
        $this->assertSame('reel', (string) get_post_meta($reel_id, '_tr_ai_wire_social_mode', true));

        $still_settings = $this->plugin->sanitize_settings(array_merge($settings, array(
            'social_post_format' => 'single',
            'social_reel_daily_limit' => 0,
            'social_mix_saved' => 1,
            'social_carousel_daily_limit' => 0,
            'social_collage_daily_limit' => 0,
            'social_single_daily_limit' => 3,
        )));
        $still_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Still story stays off YouTube',
        ));
        update_post_meta($still_id, '_tr_ai_wire_queue_id', 921);
        $this->invoke_private('collect_social_article', array($still_id, $still_settings));
        $still_platforms = $wpdb->get_col($wpdb->prepare("SELECT platform FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d ORDER BY platform", $still_id));
        $this->assertSame(array('instagram'), $still_platforms);
        $this->assertFalse(TechnoRapper_AI_Wire::youtube_credentials_ready(array()));
        $this->assertTrue(TechnoRapper_AI_Wire::youtube_credentials_ready());
    }

    public function test_youtube_upload_sends_title_description_and_tags() {
        $this->onboard_social(array('youtube'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Qualcomm pushes into data centers',
            'post_excerpt' => 'The chipmaker wants a larger share of AI servers this year.',
        ));
        $copy = $this->invoke_private('sanitize_social_copy', array(array(
            'hook' => 'Your next phone chip has a server cousin',
            'youtube_title' => 'Qualcomm wants the rack, not just the pocket',
            'youtube_description' => 'The same silicon family now ships in phones and AI racks.',
            'youtube_tags' => array('Qualcomm', 'AI chips', 'data center'),
        ), get_post($post_id)));
        $resource = $this->invoke_private('youtube_video_resource', array($copy, get_post($post_id), TechnoRapper_AI_Wire::defaults()));
        $this->assertSame('Qualcomm wants the rack, not just the pocket', $resource['snippet']['title']);
        $this->assertStringContainsString('same silicon family', $resource['snippet']['description']);
        $this->assertStringContainsString(get_permalink($post_id), $resource['snippet']['description']);
        $this->assertContains('qualcomm', $resource['snippet']['tags']);
        $this->assertContains('ai chips', $resource['snippet']['tags']);
        $this->assertSame('28', $resource['snippet']['categoryId']);
        $this->assertNotEmpty($resource['snippet']['defaultLanguage']);
        $this->assertStringContainsString('#Shorts', $resource['snippet']['description']);
        $this->assertContains('shorts', $resource['snippet']['tags']);
        $this->assertSame('public', $resource['status']['privacyStatus']);
        $this->assertFalse($resource['status']['selfDeclaredMadeForKids']);
        $this->assertTrue($resource['status']['containsSyntheticMedia']);
        $this->assertTrue($resource['status']['embeddable']);
        $this->assertTrue($resource['status']['publicStatsViewable']);
        $this->assertSame('youtube', $resource['status']['license']);
        $this->assertArrayNotHasKey('publishAt', $resource['status']);
        $this->assertNotEmpty($resource['recordingDetails']['recordingDate'] ?? '');

        $video = sys_get_temp_dir() . '/tr-yt-' . wp_generate_uuid4() . '.mp4';
        file_put_contents($video, str_repeat('m', 2048));
        $captured = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$captured) {
            $url = (string) $url;
            if (strpos($url, 'upload/youtube/v3/videos') !== false) {
                $captured['init'] = json_decode((string) ($args['body'] ?? ''), true);
                $captured['notify'] = (strpos($url, 'notifySubscribers=false') !== false);
                return array(
                    'headers' => array('location' => 'https://example.org/youtube-upload-session'),
                    'body' => '',
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            if (strpos($url, 'example.org/youtube-upload-session') !== false) {
                $captured['bytes'] = strlen((string) ($args['body'] ?? ''));
                return $this->mock_http_ok(array('id' => 'yt-short-1'));
            }
            if (strpos($url, 'googleapis.com/youtube/v3/videos') !== false && strpos($url, 'part=status') !== false) {
                return $this->mock_http_ok(array(
                    'items' => array(array(
                        'id' => 'yt-short-1',
                        'status' => array('uploadStatus' => 'processed', 'privacyStatus' => 'public'),
                        'processingDetails' => array('processingStatus' => 'succeeded'),
                    )),
                ));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        add_filter('tr_ai_wire_youtube_poll_seconds', function () {
            return 0;
        });
        $result = $this->invoke_private('publish_youtube_short', array(
            $copy,
            $video,
            get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION),
            TechnoRapper_AI_Wire::defaults(),
            get_post($post_id),
        ));
        @unlink($video);
        $this->assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('yt-short-1', $result['id']);
        $this->assertSame('https://www.youtube.com/shorts/yt-short-1', $result['url']);
        $this->assertSame('Qualcomm wants the rack, not just the pocket', $captured['init']['snippet']['title'] ?? '');
        $this->assertContains('qualcomm', $captured['init']['snippet']['tags'] ?? array());
        $this->assertSame('public', $captured['init']['status']['privacyStatus'] ?? '');
        $this->assertArrayNotHasKey('publishAt', $captured['init']['status'] ?? array());
        $this->assertStringContainsString('#Shorts', $captured['init']['snippet']['description'] ?? '');
        $this->assertTrue($captured['notify']);
        $this->assertSame(2048, $captured['bytes']);
    }

    public function test_youtube_forces_public_if_channel_default_is_private() {
        $this->onboard_social(array('youtube'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Channel defaulted to private',
        ));
        $copy = $this->invoke_private('sanitize_social_copy', array(array(
            'youtube_title' => 'This must go live now',
            'youtube_description' => 'No draft, no schedule.',
            'youtube_tags' => array('ai'),
        ), get_post($post_id)));
        $video = sys_get_temp_dir() . '/tr-yt-priv-' . wp_generate_uuid4() . '.mp4';
        file_put_contents($video, str_repeat('m', 1024));
        $captured = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$captured) {
            $url = (string) $url;
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            if (strpos($url, 'upload/youtube/v3/videos') !== false) {
                return array(
                    'headers' => array('location' => 'https://example.org/youtube-upload-session'),
                    'body' => '',
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            if (strpos($url, 'example.org/youtube-upload-session') !== false) {
                return $this->mock_http_ok(array(
                    'id' => 'yt-was-private',
                    'status' => array('privacyStatus' => 'private', 'uploadStatus' => 'uploaded'),
                ));
            }
            if ($method === 'PUT' && strpos($url, 'googleapis.com/youtube/v3/videos') !== false) {
                $captured['update'] = json_decode((string) ($args['body'] ?? ''), true);
                return $this->mock_http_ok(array(
                    'id' => 'yt-was-private',
                    'status' => array('privacyStatus' => 'public', 'uploadStatus' => 'processed'),
                ));
            }
            if (strpos($url, 'googleapis.com/youtube/v3/videos') !== false) {
                $privacy = empty($captured['update']) ? 'private' : 'public';
                return $this->mock_http_ok(array(
                    'items' => array(array(
                        'id' => 'yt-was-private',
                        'status' => array('uploadStatus' => 'processed', 'privacyStatus' => $privacy),
                        'processingDetails' => array('processingStatus' => 'succeeded'),
                    )),
                ));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        add_filter('tr_ai_wire_youtube_poll_seconds', function () {
            return 0;
        });
        $result = $this->invoke_private('publish_youtube_short', array(
            $copy,
            $video,
            get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION),
            TechnoRapper_AI_Wire::defaults(),
            get_post($post_id),
        ));
        @unlink($video);
        $this->assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('yt-was-private', $result['id']);
        $this->assertSame('public', $captured['update']['status']['privacyStatus'] ?? '');
        $this->assertArrayNotHasKey('publishAt', $captured['update']['status'] ?? array());
        $this->assertFalse($captured['update']['status']['selfDeclaredMadeForKids'] ?? true);
    }

    public function test_youtube_does_not_post_a_still_image() {
        $this->onboard_social(array('youtube'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'No stills on YouTube',
        ));
        $result = $this->invoke_private('publish_youtube_short', array(
            array('youtube_title' => 'A title'),
            '',
            get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION),
            TechnoRapper_AI_Wire::defaults(),
            get_post($post_id),
        ));
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_yt_video_file', $result->get_error_code());
        $this->assertStringContainsString('still image', strtolower($result->get_error_message()));
    }

    public function test_youtube_connect_is_google_signin_and_accepts_a_refresh_token() {
        $this->assertFalse(TechnoRapper_AI_Wire::youtube_credentials_ready());
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array(
            'youtube_refresh_token' => '1//pasted-refresh-token',
        ), false);
        $this->assertTrue(TechnoRapper_AI_Wire::youtube_credentials_ready());
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array(
            'youtube_client_id' => 'yt-client.apps.googleusercontent.com',
            'youtube_client_secret' => 'yt-secret',
            'youtube_refresh_token' => '1//pasted-refresh-token',
        ), false);
        $url = TechnoRapper_AI_Wire_YouTube_OAuth::connect_url();
        $this->assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('youtube.upload', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringNotContainsString('key=', $url);
    }

    public function test_youtube_only_does_not_force_x_or_instagram() {
        $settings = TechnoRapper_AI_Wire::apply_social_platform_toggles(array(
            'social_distribution_enabled' => 1,
            'social_x_enabled' => 0,
            'social_instagram_enabled' => 0,
            'social_youtube_enabled' => 1,
        ));
        $this->assertSame(0, (int) $settings['social_x_enabled']);
        $this->assertSame(0, (int) $settings['social_instagram_enabled']);
        $this->assertSame(1, (int) $settings['social_youtube_enabled']);
        $this->assertSame(1, (int) $settings['social_distribution_enabled']);
    }

    public function test_youtube_processing_wait_polls_existing_id_without_uploading_again() {
        $this->onboard_social(array('youtube'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Already uploaded Short',
        ));
        $copy = $this->invoke_private('sanitize_social_copy', array(array(
            'youtube_title' => 'Already uploaded Short',
            'youtube_description' => 'Poll processing, do not PUT bytes again.',
            'youtube_tags' => array('youtube', 'shorts'),
        ), get_post($post_id)));
        $calls = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$calls) {
            $url = (string) $url;
            $calls[] = $url;
            if (strpos($url, 'upload/youtube/v3/videos') !== false || strpos($url, 'youtube-upload-session') !== false) {
                return new WP_Error('blocked', 'Must not start a second YouTube upload');
            }
            if (strpos($url, 'googleapis.com/youtube/v3/videos') !== false) {
                return $this->mock_http_ok(array(
                    'items' => array(array(
                        'id' => 'yt-short-existing',
                        'status' => array('uploadStatus' => 'processed', 'privacyStatus' => 'public'),
                        'processingDetails' => array('processingStatus' => 'succeeded'),
                    )),
                ));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $result = $this->invoke_private('publish_youtube_short', array(
            $copy,
            '',
            get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION),
            TechnoRapper_AI_Wire::defaults(),
            get_post($post_id),
            'yt-short-existing',
        ));
        $this->assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('yt-short-existing', $result['id']);
        $this->assertSame('https://www.youtube.com/shorts/yt-short-existing', $result['url']);
        $this->assertNotEmpty($calls);
    }

    public function test_reel_renderer_uses_captions_not_poster_slides() {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is required to render Reel frames.');
        }
        $photo = $this->tiny_jpeg_bytes();
        $copy = array(
            'hook' => 'Your next phone chip has a server cousin',
            'cta' => 'Read the full story on TechnoRapper',
            'reel_script' => 'Your next phone chip has a server cousin. Qualcomm wants the rack, not just the pocket.',
            'reel_captions' => array('Phone chip, server cousin', 'Qualcomm wants the rack'),
        );
        $open = $this->invoke_private('render_social_reel_frame', array('open', $copy, $photo, 'Your next phone chip'));
        $broll = $this->invoke_private('render_social_reel_frame', array('broll', $copy, $photo, 'Qualcomm wants the rack'));
        $end = $this->invoke_private('render_social_reel_frame', array('end', $copy, $photo, $copy['cta']));
        $this->assertTrue(is_string($open) && $open !== '');
        $this->assertTrue(is_string($broll) && $broll !== '');
        $this->assertTrue(is_string($end) && $end !== '');
        $open_im = imagecreatefromstring($open);
        $broll_im = imagecreatefromstring($broll);
        $end_im = imagecreatefromstring($end);
        $this->assertSame(1080, imagesx($open_im));
        $this->assertSame(1920, imagesy($open_im));
        $this->assertSame(1080, imagesx($broll_im));
        $this->assertSame(1920, imagesy($broll_im));
        $this->assertSame(1080, imagesx($end_im));
        $this->assertSame(1920, imagesy($end_im));
        imagedestroy($open_im);
        imagedestroy($broll_im);
        imagedestroy($end_im);
        $broll_crop = $this->invoke_private('crop_social_broll', array($photo));
        $this->assertTrue(is_string($broll_crop) && $broll_crop !== '');
        $crop_im = imagecreatefromstring($broll_crop);
        $this->assertSame(1080, imagesx($crop_im));
        $this->assertSame(1920, imagesy($crop_im));
        imagedestroy($crop_im);
    }

    public function test_reel_without_ffmpeg_does_not_fall_back_to_slideshow() {
        add_filter('tr_ai_wire_ffmpeg_path', function () {
            return '/tmp/missing-ffmpeg-binary';
        });
        $this->assertSame('', $this->invoke_private('social_ffmpeg_path'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'No slideshow fallback',
        ));
        $result = $this->invoke_private('ensure_social_reel', array(
            get_post($post_id),
            array_merge(TechnoRapper_AI_Wire::defaults(), array('social_post_format' => 'reel')),
            array('reel_script' => 'Your next phone chip has a server cousin and that is the whole story.'),
            '/tmp/missing-broll.jpg',
        ));
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_reel_ffmpeg', $result->get_error_code());
        $this->assertStringContainsString('slideshow', strtolower($result->get_error_message()));
        remove_all_filters('tr_ai_wire_ffmpeg_path');
    }

    public function test_reel_without_fal_key_does_not_fall_back_to_slideshow() {
        if ($this->invoke_private('social_ffmpeg_path') === '') {
            $this->markTestSkipped('ffmpeg is required to reach the Fal key gate.');
        }
        delete_option(TechnoRapper_AI_Wire::FAL_API_KEY_OPTION);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'No Fal key',
        ));
        $broll = sys_get_temp_dir() . '/tr-broll-' . wp_generate_uuid4() . '.jpg';
        file_put_contents($broll, $this->tiny_jpeg_bytes());
        $result = $this->invoke_private('ensure_social_reel', array(
            get_post($post_id),
            array_merge(TechnoRapper_AI_Wire::defaults(), array('social_post_format' => 'reel')),
            array('reel_script' => 'Your next phone chip has a server cousin and that is the whole story.'),
            $broll,
        ));
        @unlink($broll);
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_reel_fal_key', $result->get_error_code());
        $this->assertStringContainsString('slideshow', strtolower($result->get_error_message()));
    }

    public function test_reel_without_video_budget_does_not_fall_back_to_slideshow() {
        if ($this->invoke_private('social_ffmpeg_path') === '') {
            $this->markTestSkipped('ffmpeg is required to reach the video budget gate.');
        }
        update_option(TechnoRapper_AI_Wire::FAL_API_KEY_OPTION, 'fal-test-key', false);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'No video budget',
        ));
        $broll = sys_get_temp_dir() . '/tr-broll-' . wp_generate_uuid4() . '.jpg';
        file_put_contents($broll, $this->tiny_jpeg_bytes());
        $result = $this->invoke_private('ensure_social_reel', array(
            get_post($post_id),
            array_merge(TechnoRapper_AI_Wire::defaults(), array(
                'social_post_format' => 'reel',
                'social_video_budget_usd' => 0,
            )),
            array('reel_script' => 'Your next phone chip has a server cousin and that is the whole story.'),
            $broll,
        ));
        @unlink($broll);
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_reel_video_budget', $result->get_error_code());
    }

    public function test_reel_motion_submit_is_silent_vertical_lite() {
        update_option(TechnoRapper_AI_Wire::FAL_API_KEY_OPTION, 'fal-test-key', false);
        add_filter('tr_ai_wire_fal_poll_seconds', function () {
            return 0;
        });
        $captured = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$captured) {
            $url = (string) $url;
            if (strpos($url, 'queue.fal.run') !== false && strpos($url, '/requests/') === false) {
                $captured['submit'] = json_decode((string) ($args['body'] ?? ''), true);
                $captured['auth'] = (string) (($args['headers']['Authorization'] ?? ''));
                return $this->mock_http_ok(array(
                    'request_id' => 'fal-req-1',
                    'status' => 'IN_QUEUE',
                ));
            }
            if (strpos($url, '/status') !== false) {
                return $this->mock_http_ok(array('status' => 'COMPLETED'));
            }
            if (strpos($url, 'queue.fal.run') !== false) {
                return $this->mock_http_ok(array('video' => array('url' => 'https://example.org/fal-motion.mp4')));
            }
            if (strpos($url, 'example.org/fal-motion.mp4') !== false) {
                return array(
                    'headers' => array(),
                    'body' => str_repeat('m', 2048),
                    'response' => array('code' => 200, 'message' => 'OK'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Lite motion',
        ));
        $broll = sys_get_temp_dir() . '/tr-broll-' . wp_generate_uuid4() . '.jpg';
        file_put_contents($broll, $this->tiny_jpeg_bytes() !== '' ? $this->tiny_jpeg_bytes() : 'jpeg');
        $result = $this->invoke_private('generate_social_reel_motion', array(
            get_post($post_id),
            TechnoRapper_AI_Wire::defaults(),
            array(
                'hook' => 'Your next phone chip has a server cousin',
                'visual_brief' => 'Night server hall with a phone in the foreground',
            ),
            $broll,
        ));
        @unlink($broll);
        $this->assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame('9:16', $captured['submit']['aspect_ratio'] ?? '');
        $this->assertSame('8s', $captured['submit']['duration'] ?? '');
        $this->assertSame('720p', $captured['submit']['resolution'] ?? '');
        $this->assertFalse($captured['submit']['generate_audio'] ?? true);
        $this->assertSame('Key fal-test-key', $captured['auth']);
        $this->assertStringContainsString('no text', (string) ($captured['submit']['prompt'] ?? ''));
        $this->assertSame(0.24, (float) get_option('tr_ai_wire_social_video_usage_' . gmdate('Ym'), 0));
        $this->assertGreaterThan(0, $this->invoke_private('social_motion_id', array($post_id)));
    }

    public function test_reel_video_wait_stays_queued() {
        update_option(TechnoRapper_AI_Wire::FAL_API_KEY_OPTION, 'fal-test-key', false);
        add_filter('tr_ai_wire_fal_poll_seconds', function () {
            return 0;
        });
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            if (strpos((string) $url, '/status') !== false) {
                return $this->mock_http_ok(array('status' => 'IN_PROGRESS'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $result = $this->invoke_private('social_fal_await', array('fal-ai/veo3.1/lite/image-to-video', 'fal-req-wait'));
        $this->assertWPError($result);
        $this->assertSame('tr_ai_wire_reel_video_wait', $result->get_error_code());
    }

    public function test_reel_mux_is_hard_cuts_with_voiceover() {
        $ffmpeg = $this->invoke_private('social_ffmpeg_path');
        if ($ffmpeg === '' || !function_exists('imagejpeg')) {
            $this->markTestSkipped('ffmpeg and GD are required to mux a Reel.');
        }
        $copy = array(
            'hook' => 'Your next phone chip has a server cousin',
            'cta' => 'Read the full story on TechnoRapper',
            'reel_captions' => array('Phone chip, server cousin', 'The rack is the prize', 'Full story on TechnoRapper'),
        );
        $photo = $this->tiny_jpeg_bytes();
        $tmp = sys_get_temp_dir() . '/tr-reel-test-' . wp_generate_uuid4();
        $this->assertTrue(mkdir($tmp, 0755, true));
        $frames = array();
        foreach (array(
            array('broll', 'Your next phone chip', 2.0),
            array('broll', 'Phone chip, server cousin', 2.0),
            array('broll', 'The rack is the prize', 2.0),
            array('end', $copy['cta'], 1.2),
        ) as $index => $spec) {
            $jpeg = $this->invoke_private('render_social_reel_frame', array($spec[0], $copy, $photo, $spec[1]));
            $path = $tmp . '/frame-' . (string) $index . '.jpg';
            file_put_contents($path, $jpeg);
            $frames[] = array('path' => $path, 'duration' => $spec[2], 'kind' => $spec[0]);
        }
        $audio = $tmp . '/voice.wav';
        $out = $tmp . '/reel.mp4';
        $silence = $this->invoke_private('social_run_binary', array($ffmpeg, array(
            '-y', '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=mono', '-t', '6', '-c:a', 'pcm_s16le', $audio,
        )));
        if (is_wp_error($silence) || !file_exists($audio)) {
            foreach ((array) glob($tmp . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($tmp);
            $this->markTestSkipped('ffmpeg could not write a silent voiceover fixture.');
        }
        $result = $this->invoke_private('compose_social_reel_video', array($frames, $audio, $out));
        $this->assertFalse(is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertFileExists($out);
        $this->assertGreaterThan(4000, filesize($out));
        foreach ((array) glob($tmp . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($tmp);
    }

    public function test_site_context_needs_refresh_until_a_successful_read() {
        delete_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION);
        $this->assertTrue($this->plugin->site_context_needs_refresh());
        $view = $this->plugin->site_context_view();
        $this->assertSame('idle', $view['state']);
        $this->assertSame('', $view['last']);
        $this->assertFalse($view['can_refresh']);
    }

    public function test_site_context_job_does_not_run_without_social_credentials() {
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array('social_distribution_enabled' => 1)), false);
        delete_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION);
        $this->assertFalse(TechnoRapper_AI_Wire::social_credentials_connected());
        $this->assertFalse($this->plugin->queue_site_context_job(true));
        $this->assertFalse(wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_CRON_HOOK));
        $this->plugin->process_site_context();
        $record = get_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION, array());
        $this->assertNotSame('running', $record['status'] ?? '');
        $view = $this->plugin->site_context_view();
        $this->assertSame('idle', $view['state']);
        $this->assertSame('Connect X or Instagram first', $view['title']);
        $this->assertFalse($view['can_refresh']);
    }

    public function test_enabling_social_queues_a_site_context_job() {
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array('social_distribution_enabled' => 1)), false);
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array(
            'x_user_access_token' => 'x-token',
            'instagram_user_access_token' => '',
            'instagram_user_id' => '',
        ), false);
        $this->assertTrue($this->plugin->queue_site_context_job(true));
        $record = get_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION);
        $this->assertSame('queued', $record['status']);
        $this->assertNotFalse(wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_CRON_HOOK));
        $view = $this->plugin->site_context_view();
        $this->assertSame('learning', $view['state']);
        $this->assertSame('We are understanding you', $view['title']);
        $this->assertTrue($view['can_refresh']);
    }

    public function test_ready_site_context_is_current_until_fingerprint_or_age_changes() {
        $profile = $this->invoke_private('sanitize_site_context_profile', array(array(
            'site_name' => 'TechnoRapper',
            'one_line' => 'AI, mobiles, gadgets, and India tech news.',
            'audience' => 'People who follow phones and AI.',
            'desks' => array('AI', 'Android'),
            'voice' => 'Editorial desk, not hype.',
            'priorities' => array('Explain what changed', 'Who in India is affected'),
            'avoid' => array('Fake reviews'),
            'cta_style' => 'Read the full story on TechnoRapper',
            'visual_style' => 'Navy and cyan magazine art',
        ), array('name' => 'TechnoRapper', 'tagline' => 'Tech news')));
        $snapshot = $this->invoke_private('gather_site_context_snapshot');
        update_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION, array(
            'status' => 'ready',
            'started_at' => gmdate('Y-m-d H:i:s', time() - 60),
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'fingerprint' => $this->invoke_private('site_context_fingerprint', array($snapshot)),
            'error' => '',
            'profile' => $profile,
        ), false);
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array('social_distribution_enabled' => 1)), false);
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array(
            'x_user_access_token' => 'x-token',
            'instagram_user_access_token' => '',
            'instagram_user_id' => '',
        ), false);
        $this->assertFalse($this->plugin->site_context_needs_refresh());
        $view = $this->plugin->site_context_view();
        $this->assertSame('ready', $view['state']);
        $this->assertNotSame('', $view['last']);
        $this->assertFalse($view['needs_refresh']);
        $this->assertStringContainsString('AI, mobiles, gadgets, and India tech news.', $view['body']);

        $stale = get_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION);
        $stale['completed_at'] = gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS);
        update_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION, $stale, false);
        $this->assertTrue($this->plugin->site_context_needs_refresh());
        $this->assertSame('stale', $this->plugin->site_context_view()['state']);
    }

    public function test_incomplete_instagram_credentials_are_not_onboarded() {
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array(
            'x_user_access_token' => '',
            'instagram_user_access_token' => 'ig-token',
            'instagram_user_id' => '',
        ), false);
        $this->assertFalse(TechnoRapper_AI_Wire::social_credentials_connected());
        $this->assertSame(array(), TechnoRapper_AI_Wire::connected_social_platforms());
    }

    public function test_force_refresh_does_not_run_when_social_is_off() {
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array(
            'x_user_access_token' => 'x-token',
            'instagram_user_access_token' => '',
            'instagram_user_id' => '',
        ), false);
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array('social_distribution_enabled' => 0)), false);
        $this->assertFalse($this->plugin->queue_site_context_job(true));
        $this->assertFalse(wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_CRON_HOOK));
    }

    public function test_enabling_social_without_credentials_does_not_schedule_jobs() {
        wp_clear_scheduled_hook(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_CRON_HOOK);
        wp_clear_scheduled_hook(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        delete_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION);
        $old = TechnoRapper_AI_Wire::defaults();
        $new = $old;
        $new['social_distribution_enabled'] = 1;
        $this->plugin->settings_updated($old, $new);
        $this->assertFalse(wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_CRON_HOOK));
        $this->assertFalse(wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK));
    }

    public function test_disconnected_social_makes_no_openai_or_platform_http_calls() {
        $openai = 0;
        $platform = 0;
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$openai, &$platform) {
            if (strpos((string) $url, 'openai.com') !== false) {
                $openai++;
            }
            if (strpos((string) $url, 'api.x.com') !== false || strpos((string) $url, 'graph.facebook.com') !== false || strpos((string) $url, 'googleapis.com') !== false) {
                $platform++;
            }
            return new WP_Error('blocked', 'HTTP blocked in test');
        }, 10, 3);
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array('social_distribution_enabled' => 1)), false);
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'No social spend'));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 77);
        $this->fire_social_publish($post_id);
        $this->plugin->process_site_context();
        $this->plugin->process_social_queue();
        $this->invoke_private('generate_social_copy', array(get_post($post_id), TechnoRapper_AI_Wire::defaults()));
        $this->invoke_private('generate_social_image_locked', array(get_post($post_id), TechnoRapper_AI_Wire::defaults(), array()));
        $this->assertSame(0, $openai);
        $this->assertSame(0, $platform);
        global $wpdb;
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
    }

    public function test_queue_only_connected_platforms() {
        $this->onboard_social(array('x', 'instagram'));
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Both networks'));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 21);
        $this->fire_social_publish($post_id);
        global $wpdb;
        $platforms = $wpdb->get_col($wpdb->prepare("SELECT platform FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d ORDER BY platform", $post_id));
        $this->assertSame(array('instagram', 'x'), $platforms);
    }

    public function test_platform_toggle_can_disable_x_while_instagram_stays_on() {
        $this->onboard_social(array('x', 'instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_x_enabled' => 0,
            'social_instagram_enabled' => 1,
            'social_distribution_enabled' => 1,
        )), false);
        $this->assertSame(array('instagram'), TechnoRapper_AI_Wire::connected_social_platforms());
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Instagram only after X off'));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 88);
        $this->fire_social_publish($post_id);
        global $wpdb;
        $platforms = $wpdb->get_col($wpdb->prepare("SELECT platform FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d ORDER BY platform", $post_id));
        $this->assertSame(array('instagram'), $platforms);
    }

    public function test_queue_page_offers_saved_graphics_backfill_when_x_is_on() {
        $this->onboard_social(array('x', 'instagram'));
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required to reuse a saved social graphic.');
        }
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Instagram only so far',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 52);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'published',
            'image_attachment_id' => $attachment_id,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $this->assertSame(1, $this->invoke_private('missing_platform_post_count', array('x')));
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        ob_start();
        $this->plugin->social_distribution_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('Queue saved graphics for X', $html);
        $this->assertStringContainsString('already went to another network', $html);
        $this->assertStringContainsString('does not generate a new image', $html);
        $this->assertStringNotContainsString('Queue saved graphics for Instagram', $html);
    }

    public function test_social_settings_live_on_their_own_page() {
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        ob_start();
        $this->plugin->settings_page();
        $main = ob_get_clean();
        $this->assertStringNotContainsString('Onboard social publishing', $main);
        $this->assertStringNotContainsString('name="social[x_api_key]"', $main);
        $this->assertStringContainsString('Social settings', $main);
        $this->assertStringContainsString('Comic posts per day', $main);
        $this->assertStringContainsString('Migrate older images to this site', $main);
        $this->assertStringContainsString('tr_ai_wire_migrate_images', $main);
        ob_start();
        $this->plugin->experiments_page();
        $experiments = ob_get_clean();
        $this->assertStringContainsString('Comic vs straight', $experiments);
        $this->assertStringContainsString('voice', $experiments);
        ob_start();
        $this->plugin->social_settings_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('Post to X', $html);
        $this->assertStringContainsString('Post to Instagram', $html);
        $this->assertStringContainsString('Post to YouTube Shorts', $html);
        $this->assertStringContainsString('Sign in with Google', $html);
        $this->assertStringContainsString('API key cannot upload', $html);
        $this->assertStringContainsString('name="social[youtube_refresh_token]"', $html);
        $this->assertStringContainsString('API Key Secret', $html);
        $this->assertStringContainsString('Access Token Secret', $html);
        $this->assertStringContainsString('Bearer is not needed', $html);
        $this->assertStringContainsString('Split that total', $html);
        $this->assertStringContainsString('Social posts per day', $html);
        $this->assertStringContainsString('Carousel posts per day', $html);
        $this->assertStringContainsString('Collage posts per day', $html);
        $this->assertStringContainsString('Normal / single-story posts per day', $html);
        $this->assertStringNotContainsString('Instagram post format', $html);
        $this->assertStringContainsString('News graphics we can produce', $html);
        $this->assertStringContainsString('Editorial news poster', $html);
        $this->assertStringContainsString('Photo news brief', $html);
        $this->assertStringContainsString('Weekly recap cover', $html);
        $this->assertStringContainsString('Your ideas', $html);
        $this->assertStringContainsString('name="social_idea_image"', $html);
        $this->assertStringNotContainsString('Google for Startups', $html);
        $this->assertStringNotContainsString('Ray-Ban', $html);
        $this->assertStringNotContainsString('Anthropic', $html);
        $this->assertStringNotContainsString('iPhone Duo', $html);
        $this->assertStringNotContainsString('itsmangalore', $html);
        $this->assertStringNotContainsString('access token saved', strtolower($html));
        $this->assertSame(
            'Keys and tokens saved (API Key, API Key Secret, Access Token, Access Token Secret). Bearer is not needed.',
            TechnoRapper_AI_Wire::x_status_text(array(
                'x_api_key' => 'k',
                'x_api_secret' => 's',
                'x_access_token' => 't',
                'x_access_token_secret' => 'ts',
            ))
        );
        $this->assertStringContainsString('not connected — save', TechnoRapper_AI_Wire::x_status_text(array()));
    }

    public function test_inactive_feature_settings_are_hidden() {
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);

        ob_start();
        $this->plugin->social_settings_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('id="tr-social-reel-enabled"', $html);
        $this->assertStringContainsString('Enable Reels / YouTube Shorts video', $html);
        $this->assertStringContainsString('data-tr-reveal="tr-social-reel-enabled"', $html);
        $this->assertStringContainsString('data-tr-reveal="tr-social-yt-enabled"', $html);
        $this->assertTrue((bool) preg_match('/data-tr-reveal="tr-social-reel-enabled"[^>]*\bhidden\b/', $html));
        $this->assertStringContainsString('Reel posts per day', $html);
        $this->assertStringContainsString('Monthly Reel video budget', $html);
        $this->assertStringContainsString('name="' . TechnoRapper_AI_Wire::OPTION . '[social_reel_daily_limit]"', $html);

        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_reel_daily_limit' => 2,
        )), false);
        ob_start();
        $this->plugin->social_settings_page();
        $reel_on = ob_get_clean();
        $this->assertFalse((bool) preg_match('/data-tr-reveal="tr-social-reel-enabled"[^>]*\bhidden\b/', $reel_on));
        $this->assertTrue((bool) preg_match('/id="tr-social-reel-enabled"[^>]*\bchecked\b/', $reel_on));

        ob_start();
        $this->plugin->settings_page();
        $main = ob_get_clean();
        $this->assertStringContainsString('id="tr-comic-enabled"', $main);
        $this->assertStringContainsString('Comic posts per day', $main);
        $this->assertTrue((bool) preg_match('/data-tr-reveal="tr-comic-enabled"[^>]*\bhidden\b/', $main));
        $this->assertStringContainsString('id="tr-enable-experiment"', $main);
        $this->assertFalse((bool) preg_match('/data-tr-reveal="tr-enable-experiment"[^>]*\bhidden\b/', $main));

        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'enable_experiment' => 0,
            'comic_daily_limit' => 3,
        )), false);
        ob_start();
        $this->plugin->settings_page();
        $toggled = ob_get_clean();
        $this->assertTrue((bool) preg_match('/data-tr-reveal="tr-enable-experiment"[^>]*\bhidden\b/', $toggled));
        $this->assertFalse((bool) preg_match('/data-tr-reveal="tr-comic-enabled"[^>]*\bhidden\b/', $toggled));

        $off = $this->plugin->sanitize_settings(array(
            'social_platform_toggles' => 1,
            'social_reel_enabled' => 0,
            'social_reel_daily_limit' => 5,
            'social_mix_saved' => 1,
        ));
        $this->assertSame(0, (int) $off['social_reel_daily_limit']);

        $on = $this->plugin->sanitize_settings(array(
            'social_platform_toggles' => 1,
            'social_reel_enabled' => 1,
            'social_reel_daily_limit' => 0,
            'social_mix_saved' => 1,
        ));
        $this->assertSame(1, (int) $on['social_reel_daily_limit']);

        $comic_off = $this->plugin->sanitize_settings(array(
            'comic_enabled' => 0,
            'comic_daily_limit' => 4,
        ));
        $this->assertSame(0, (int) $comic_off['comic_daily_limit']);
        $comic_on = $this->plugin->sanitize_settings(array(
            'comic_enabled' => 1,
            'comic_daily_limit' => 0,
        ));
        $this->assertSame(1, (int) $comic_on['comic_daily_limit']);
    }

    public function test_main_settings_save_does_not_clear_social_toggles() {
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_x_enabled' => 1,
            'social_instagram_enabled' => 0,
            'social_daily_limit' => 7,
        )), false);
        $clean = $this->plugin->sanitize_settings(array(
            'auto_publish' => 1,
            'max_posts_per_day' => 10,
        ));
        $this->assertSame(1, (int) $clean['social_x_enabled']);
        $this->assertSame(0, (int) $clean['social_instagram_enabled']);
        $this->assertSame(1, (int) $clean['social_distribution_enabled']);
        $this->assertSame(7, (int) $clean['social_daily_limit']);
        $this->assertSame(array_keys(TechnoRapper_AI_Wire::social_news_formats()), $clean['social_news_formats']);
    }

    public function test_switch_off_pauses_queued_delivery() {
        $this->onboard_social(array('x'));
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Paused post'));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 22);
        $this->fire_social_publish($post_id);
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array('social_distribution_enabled' => 0)), false);
        add_filter('pre_http_request', function () {
            return new WP_Error('blocked', 'should not run');
        }, 10, 3);
        $this->plugin->process_social_queue();
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d", $post_id));
        $this->assertSame('queued', $status);
    }

    public function test_daily_unique_article_cap_still_allows_second_platform() {
        $this->onboard_social(array('x', 'instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_daily_limit' => 1,
        )), false);
        global $wpdb;
        $table = $wpdb->prefix . 'tr_ai_wire_social';
        $now = gmdate('Y-m-d H:i:s');
        $first = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'First of day'));
        $second = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Second of day'));
        $wpdb->insert($table, array('post_id' => $first, 'platform' => 'x', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now));
        $wpdb->insert($table, array('post_id' => $first, 'platform' => 'instagram', 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now));
        $wpdb->insert($table, array('post_id' => $second, 'platform' => 'x', 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now));
        $this->assertSame(1, $this->invoke_private('social_published_today'));
        $this->assertTrue($this->invoke_private('social_post_published_today', array($first)));
        $this->assertFalse($this->invoke_private('social_post_published_today', array($second)));
    }

    public function test_failed_x_still_runs_when_instagram_already_filled_the_daily_cap() {
        $jpeg = $this->tiny_jpeg_bytes();
        if ($jpeg === '') {
            $this->markTestSkipped('GD is required for the social publish path.');
        }
        $this->onboard_social(array('x', 'instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_distribution_enabled' => 1,
            'social_daily_limit' => 1,
        )), false);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'IG ok X retry',
            'post_excerpt' => 'Finish X after the article cap.',
        ));
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required to finish X after Instagram.');
        }
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 41);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'IG ok X retry',
            'on_image_headline' => 'IG ok X retry',
            'x' => 'IG ok X retry',
            'instagram' => 'IG ok X retry',
            'instagram_alt' => 'Alt',
        )));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'published',
            'image_attachment_id' => $attachment_id,
            'remote_id' => 'ig-already',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'x',
            'status' => 'queued',
            'image_attachment_id' => $attachment_id,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $other = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'New article behind the cap'));
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $other,
            'platform' => 'x',
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            if (strpos((string) $url, 'api.openai.com') !== false) {
                return new WP_Error('blocked', 'Should reuse the cached graphic.');
            }
            if (strpos((string) $url, 'graph.facebook.com') !== false) {
                return new WP_Error('blocked', 'Instagram already published.');
            }
            if (strpos((string) $url, 'media/upload/initialize') !== false) {
                return $this->mock_http_ok(array('data' => array('id' => 'x-media-cap')));
            }
            if (strpos((string) $url, '/append') !== false || strpos((string) $url, '/finalize') !== false) {
                return $this->mock_http_ok(array('data' => array('processing_info' => array('state' => 'succeeded'))));
            }
            if (strpos((string) $url, 'api.x.com/2/tweets') !== false) {
                return $this->mock_http_ok(array('data' => array('id' => 'x-after-cap')));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        $x = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'x'", $post_id), ARRAY_A);
        $this->assertSame('published', $x['status']);
        $this->assertSame('x-after-cap', $x['remote_id']);
        $blocked = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'x'", $other));
        $this->assertSame('queued', $blocked);
        $this->assertSame(1, $this->invoke_private('social_published_today'));
    }

    public function test_retry_kicks_failed_x_even_when_the_worker_was_deferred_until_tomorrow() {
        $this->onboard_social(array('x', 'instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_distribution_enabled' => 1,
            'social_daily_limit' => 1,
        )), false);
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Retry X after cap'));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'x',
            'status' => 'needs_review',
            'error_text' => 'X rejected the token.',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $x_id = (int) $wpdb->insert_id;
        $tomorrow = strtotime('tomorrow 00:20:00 UTC');
        wp_clear_scheduled_hook(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        wp_schedule_single_event($tomorrow, TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        $this->assertTrue($this->invoke_private('requeue_social_item', array($x_id, false)));
        $next = wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        $this->assertNotFalse($next);
        $this->assertLessThan($tomorrow, $next);
        $this->assertLessThanOrEqual(time() + 20, $next);
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $x_id));
        $this->assertSame('queued', $status);
    }

    public function test_site_context_job_stores_profile_from_openai() {
        $this->onboard_social(array('x'));
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            if (strpos((string) $url, 'api.openai.com/v1/responses') === false) {
                return new WP_Error('blocked', 'Unexpected URL ' . $url);
            }
            return $this->openai_json_http(array(
                'site_name' => 'TechnoRapper',
                'one_line' => 'India tech desk for phones and AI.',
                'audience' => 'People who follow mobiles and AI.',
                'desks' => array('AI', 'Android'),
                'voice' => 'Editorial, not hype.',
                'priorities' => array('What changed', 'Who in India is affected'),
                'avoid' => array('Fake reviews'),
                'cta_style' => 'Read the full story on TechnoRapper',
                'visual_style' => 'Navy cyan magazine art',
            ));
        }, 10, 3);
        $this->plugin->process_site_context();
        $record = get_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION);
        $this->assertSame('ready', $record['status']);
        $this->assertSame('TechnoRapper', $record['profile']['site_name']);
        $this->assertSame('ready', $this->plugin->site_context_view()['state']);
        $this->assertNotSame('', $this->plugin->site_context_view()['last']);
    }

    public function test_x_worker_publishes_without_replacing_featured_image() {
        $jpeg = $this->tiny_jpeg_bytes();
        if ($jpeg === '') {
            $this->markTestSkipped('GD is required for the social publish path.');
        }
        $this->onboard_social(array('x'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Chip news for X',
            'post_excerpt' => 'A phone chip just got faster.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 31);
        update_post_meta($post_id, '_tr_ai_wire_source_image', 'https://cdn.example.com/photo.jpg');
        $this->fire_social_publish($post_id);

        $copy_payload = array(
            'hook' => 'This chip changes phones',
            'on_image_headline' => 'Faster phone chip',
            'on_image_subhead' => 'Charging just got serious',
            'cta' => 'Read the full story on TechnoRapper',
            'visual_brief' => 'Symbolic chip glow',
            'x_text' => 'A phone chip just got faster.',
            'instagram_caption' => 'A phone chip just got faster.',
            'instagram_alt' => 'Editorial chip graphic',
            'hashtags' => array('AI', 'Android'),
        );
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($jpeg, $copy_payload) {
            if (strpos((string) $url, 'api.openai.com/v1/responses') !== false) {
                return $this->openai_json_http($copy_payload);
            }
            if (strpos((string) $url, 'api.openai.com/v1/images/generations') !== false) {
                return $this->mock_http_ok(array('data' => array(array('b64_json' => base64_encode($jpeg)))));
            }
            if (strpos((string) $url, 'media/upload/initialize') !== false) {
                return $this->mock_http_ok(array('data' => array('id' => 'media-1')));
            }
            if (strpos((string) $url, '/append') !== false) {
                return $this->mock_http_ok(array());
            }
            if (strpos((string) $url, '/finalize') !== false) {
                return $this->mock_http_ok(array('data' => array('processing_info' => array('state' => 'succeeded'))));
            }
            if (strpos((string) $url, 'api.x.com/2/tweets') !== false) {
                return $this->mock_http_ok(array('data' => array('id' => '12345')));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);

        $this->plugin->process_social_queue();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id, image_attachment_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'x'", $post_id), ARRAY_A);
        $this->assertSame('published', $row['status']);
        $this->assertSame('12345', $row['remote_id']);
        $this->assertGreaterThan(0, (int) $row['image_attachment_id']);
        $this->assertSame('1', (string) get_post_meta((int) $row['image_attachment_id'], '_tr_ai_wire_social_asset', true));
        $this->assertSame(TechnoRapper_AI_Wire::SOCIAL_IMAGE_LAYOUT, (string) get_post_meta((int) $row['image_attachment_id'], '_tr_ai_wire_social_layout', true));
        $this->assertSame(0, $this->invoke_private('real_thumbnail_id', array($post_id)));
        $this->assertNotSame((int) $row['image_attachment_id'], $this->invoke_private('real_thumbnail_id', array($post_id)));
        $this->assertSame('https://cdn.example.com/photo.jpg', $this->invoke_private('display_image_url', array($post_id)));
    }

    public function test_ambiguous_x_response_goes_to_needs_review() {
        $jpeg = $this->tiny_jpeg_bytes();
        if ($jpeg === '') {
            $this->markTestSkipped('GD is required for the social publish path.');
        }
        $this->onboard_social(array('x'));
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Ambiguous X'));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 32);
        $this->fire_social_publish($post_id);
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($jpeg) {
            if (strpos((string) $url, 'api.openai.com/v1/responses') !== false) {
                return $this->openai_json_http(array('hook' => 'Hook', 'on_image_headline' => 'Headline', 'x_text' => 'Text', 'instagram_caption' => 'Caption'));
            }
            if (strpos((string) $url, 'api.openai.com/v1/images/generations') !== false) {
                return $this->mock_http_ok(array('data' => array(array('b64_json' => base64_encode($jpeg)))));
            }
            if (strpos((string) $url, 'media/upload/initialize') !== false) {
                return $this->mock_http_ok(array('data' => array('id' => 'media-1')));
            }
            if (strpos((string) $url, '/append') !== false || strpos((string) $url, '/finalize') !== false) {
                return $this->mock_http_ok(array('data' => array('processing_info' => array('state' => 'succeeded'))));
            }
            if (strpos((string) $url, 'api.x.com/2/tweets') !== false) {
                return new WP_Error('http_request_failed', 'Connection reset after X may have accepted the tweet.');
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, error_text FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d", $post_id), ARRAY_A);
        $this->assertSame('needs_review', $row['status']);
        $this->assertNotEmpty($row['error_text']);
    }

    public function test_instagram_quota_full_stays_queued() {
        $jpeg = $this->tiny_jpeg_bytes();
        if ($jpeg === '') {
            $this->markTestSkipped('GD is required for the social publish path.');
        }
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'IG quota'));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 33);
        $this->fire_social_publish($post_id);
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($jpeg) {
            if (strpos((string) $url, 'api.openai.com/v1/responses') !== false) {
                return $this->openai_json_http(array('hook' => 'Hook', 'on_image_headline' => 'Headline', 'instagram_caption' => 'Caption'));
            }
            if (strpos((string) $url, 'api.openai.com/v1/images/generations') !== false) {
                return $this->mock_http_ok(array('data' => array(array('b64_json' => base64_encode($jpeg)))));
            }
            if (strpos((string) $url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos((string) $url, 'content_publishing_limit') !== false) {
                return $this->mock_http_ok(array('data' => array(array('quota_usage' => 100, 'config' => array('quota_total' => 100)))));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, error_text FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'instagram'", $post_id), ARRAY_A);
        $this->assertSame('queued', $row['status']);
        $this->assertStringContainsString('quota', strtolower((string) $row['error_text']));
    }

    public function test_social_copy_uses_site_context_cta() {
        $this->onboard_social(array('x'));
        update_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION, array(
            'status' => 'ready',
            'started_at' => gmdate('Y-m-d H:i:s'),
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'fingerprint' => 'abc',
            'error' => '',
            'profile' => array('cta_style' => 'Read it on this desk', 'site_name' => 'Desk'),
        ), false);
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Context CTA'));
        $copy = $this->invoke_private('sanitize_social_copy', array(array(), get_post($post_id)));
        $this->assertSame('Read it on this desk', $copy['cta']);
        $this->assertStringContainsString('Read it on this desk', $copy['instagram']);
    }

    public function test_context_and_social_hooks_are_registered() {
        $this->assertSame(10, has_action(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_CRON_HOOK, array($this->plugin, 'process_site_context')));
        $this->assertSame(10, has_action(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK, array($this->plugin, 'process_social_queue')));
        $this->assertSame(10, has_action('transition_post_status', array($this->plugin, 'queue_social_for_post')));
        $this->assertFalse(has_action('wp_after_insert_post', array($this->plugin, 'queue_social_for_post')));
        $this->assertSame(12, has_action('init', array($this->plugin, 'maybe_requeue_instagram_quota_field_failures')));
    }

    private function create_social_jpeg_attachment() {
        $jpeg = $this->tiny_jpeg_bytes();
        if ($jpeg === '') {
            return 0;
        }
        $upload = wp_upload_bits('technorapper-social-test-' . wp_generate_uuid4() . '.jpg', null, $jpeg);
        if (!empty($upload['error'])) {
            return 0;
        }
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => 'image/jpeg',
            'post_title' => 'Social graphic',
            'post_status' => 'inherit',
        ), $upload['file'], 0, true);
        if (is_wp_error($attachment_id)) {
            return 0;
        }
        update_post_meta($attachment_id, '_tr_ai_wire_social_asset', '1');
        update_post_meta($attachment_id, '_tr_ai_wire_social_layout', TechnoRapper_AI_Wire::SOCIAL_IMAGE_LAYOUT);
        return (int) $attachment_id;
    }

    public function test_social_queue_page_shows_generated_image() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required to preview social graphics.');
        }
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'AI Power Politics: What Voter Backlash Could Mean for Big Tech',
        ));
        $copy = array(
            'hook' => 'When AI becomes a campaign issue, the stakes go beyond technology.',
            'on_image_headline' => 'When Big Tech Becomes Politics',
            'on_image_subhead' => 'Voters are rewriting the AI story',
            'instagram' => 'When AI becomes a campaign issue, the stakes go beyond technology.',
            'instagram_alt' => 'Editorial graphic about AI and politics',
        );
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'needs_review',
            'image_attachment_id' => $attachment_id,
            'copy_json' => wp_json_encode($copy),
            'error_text' => '(#100) Tried accessing nonexisting field (content_publishing_limit)',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        ob_start();
        $this->plugin->social_distribution_page();
        $html = ob_get_clean();
        $image_url = wp_get_attachment_url($attachment_id);
        $this->assertStringContainsString('Generated graphic / result', $html);
        $this->assertStringContainsString('Generated graphic', $html);
        $this->assertStringContainsString('tr-ai-wire-social-preview-image', $html);
        $this->assertNotFalse($image_url);
        $this->assertStringContainsString($image_url, $html);
        $this->assertStringContainsString('When Big Tech Becomes Politics', $html);
        $this->assertStringContainsString('branded social card', $html);
        $this->assertStringContainsString('Instagram could not read the daily publishing quota', $html);
        $this->assertStringContainsString('Post existing graphic to Instagram', $html);
        $this->assertStringContainsString('Generate a new graphic and retry', $html);
        $this->assertStringContainsString('name="regenerate_image"', $html);
        $this->assertStringContainsString('Process queued social posts now', $html);
        $this->assertStringContainsString('tr_ai_wire_run_social_queue', $html);
        $this->assertStringNotContainsString('Regenerate graphic and post again', $html);
    }

    public function test_social_queue_page_shows_placeholder_before_image_exists() {
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Waiting for graphic',
        ));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'x',
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        ob_start();
        $this->plugin->social_distribution_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('Graphic not generated yet', $html);
        $this->assertStringContainsString('tr-ai-wire-social-preview-empty', $html);
    }

    public function test_social_queue_image_id_falls_back_to_post_meta() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required to preview social graphics.');
        }
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Fallback graphic'));
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        $found = $this->invoke_private('social_queue_image_id', array(
            array('image_attachment_id' => 0),
            get_post($post_id),
        ));
        $this->assertSame($attachment_id, $found);
    }

    public function test_instagram_missing_quota_field_still_publishes() {
        $jpeg = $this->tiny_jpeg_bytes();
        if ($jpeg === '') {
            $this->markTestSkipped('GD is required for the social publish path.');
        }
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'IG quota field missing',
            'post_excerpt' => 'Quota edge is optional.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 34);
        $this->fire_social_publish($post_id);
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($jpeg) {
            if (strpos((string) $url, 'api.openai.com/v1/responses') !== false) {
                return $this->openai_json_http(array('hook' => 'Hook', 'on_image_headline' => 'Headline', 'instagram_caption' => 'Caption'));
            }
            if (strpos((string) $url, 'api.openai.com/v1/images/generations') !== false) {
                return $this->mock_http_ok(array('data' => array(array('b64_json' => base64_encode($jpeg)))));
            }
            if (strpos((string) $url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos((string) $url, 'content_publishing_limit') !== false) {
                return array(
                    'headers' => array(),
                    'body' => wp_json_encode(array(
                        'error' => array(
                            'message' => '(#100) Tried accessing nonexisting field (content_publishing_limit)',
                            'type' => 'OAuthException',
                            'code' => 100,
                        ),
                    )),
                    'response' => array('code' => 400, 'message' => 'Bad Request'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            if (strpos((string) $url, '/media_publish') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-media-1'));
            }
            if (strpos((string) $url, '/media') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-container-1'));
            }
            if (strpos((string) $url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos((string) $url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/p/abc/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id, remote_url FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'instagram'", $post_id), ARRAY_A);
        $this->assertSame('published', $row['status']);
        $this->assertSame('ig-media-1', $row['remote_id']);
        $this->assertSame('https://www.instagram.com/p/abc/', $row['remote_url']);
    }

    public function test_instagram_quota_field_needs_review_is_requeued_and_published() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required for the Instagram retry path.');
        }
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Stuck IG quota field',
            'post_excerpt' => 'This never reached Instagram.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 35);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'Hook',
            'on_image_headline' => 'Headline',
            'x' => 'X text',
            'instagram' => 'Instagram caption',
            'instagram_alt' => 'Alt',
        )));
        $other_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Ambiguous other'));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'needs_review',
            'image_attachment_id' => $attachment_id,
            'error_text' => '(#100) Tried accessing nonexisting field (content_publishing_limit)',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $other_id,
            'platform' => 'instagram',
            'status' => 'needs_review',
            'error_text' => 'Instagram did not return a media ID.',
            'created_at' => $now,
            'updated_at' => $now,
        ));

        add_filter('pre_http_request', function ($preempt, $args, $url) {
            if (strpos((string) $url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos((string) $url, 'content_publishing_limit') !== false) {
                return array(
                    'headers' => array(),
                    'body' => wp_json_encode(array(
                        'error' => array(
                            'message' => '(#100) Tried accessing nonexisting field (content_publishing_limit)',
                            'type' => 'OAuthException',
                            'code' => 100,
                        ),
                    )),
                    'response' => array('code' => 400, 'message' => 'Bad Request'),
                    'cookies' => array(),
                    'filename' => null,
                );
            }
            if (strpos((string) $url, '/media_publish') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-media-retry'));
            }
            if (strpos((string) $url, '/media') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-container-retry'));
            }
            if (strpos((string) $url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos((string) $url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/p/retry/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);

        $this->plugin->process_social_queue();
        $fixed = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'instagram'", $post_id), ARRAY_A);
        $other = $wpdb->get_row($wpdb->prepare("SELECT status, error_text FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'instagram'", $other_id), ARRAY_A);
        $this->assertSame('published', $fixed['status']);
        $this->assertSame('ig-media-retry', $fixed['remote_id']);
        $this->assertSame('needs_review', $other['status']);
        $this->assertStringContainsString('did not return a media ID', (string) $other['error_text']);
    }

    public function test_instagram_user_token_is_exchanged_for_page_token() {
        $jpeg = $this->tiny_jpeg_bytes();
        if ($jpeg === '') {
            $this->markTestSkipped('GD is required for the social publish path.');
        }
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'User token swap',
            'post_excerpt' => 'Should publish via the Page token.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 36);
        $this->fire_social_publish($post_id);
        $used_page_token = false;
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($jpeg, &$used_page_token) {
            $url = (string) $url;
            $body = is_array($args) && isset($args['body']) && is_array($args['body']) ? $args['body'] : array();
            if (strpos($url, 'api.openai.com/v1/responses') !== false) {
                return $this->openai_json_http(array('hook' => 'Hook', 'on_image_headline' => 'Headline', 'instagram_caption' => 'Caption'));
            }
            if (strpos($url, 'api.openai.com/v1/images/generations') !== false) {
                return $this->mock_http_ok(array('data' => array(array('b64_json' => base64_encode($jpeg)))));
            }
            if (strpos($url, 'fields=id,username') !== false) {
                return $this->graph_error_http("Unsupported post request. Object with ID '17841400000000000' does not exist, cannot be loaded due to missing permissions, or does not support this operation.");
            }
            if (strpos($url, 'me/accounts') !== false) {
                return $this->mock_http_ok(array('data' => array(array(
                    'name' => 'TechnoRapper',
                    'id' => '394236317501802',
                    'access_token' => 'page-token',
                    'instagram_business_account' => array('id' => '17841400000000000'),
                ))));
            }
            if (preg_match('#/me\?#', $url) || substr($url, -3) === '/me') {
                return $this->mock_http_ok(array('id' => 'user-1', 'name' => 'Haneet'));
            }
            if (strpos($url, 'content_publishing_limit') !== false) {
                return $this->graph_error_http('(#100) Tried accessing nonexisting field (content_publishing_limit)');
            }
            if (strpos($url, '/media_publish') !== false) {
                if (($body['access_token'] ?? '') === 'page-token') {
                    $used_page_token = true;
                }
                return $this->mock_http_ok(array('id' => 'ig-media-swap'));
            }
            if (strpos($url, '/media') !== false) {
                if (($body['access_token'] ?? '') === 'page-token') {
                    $used_page_token = true;
                }
                return $this->mock_http_ok(array('id' => 'ig-container-swap'));
            }
            if (strpos($url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos($url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/p/swap/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'instagram'", $post_id), ARRAY_A);
        $this->assertSame('published', $row['status']);
        $this->assertSame('ig-media-swap', $row['remote_id']);
        $this->assertTrue($used_page_token);
        $saved = get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION);
        $this->assertSame('page-token', $saved['instagram_user_access_token']);
    }

    public function test_instagram_missing_object_error_explains_page_token() {
        $copy = $this->invoke_private('social_error_copy', array("Unsupported post request. Object with ID '1784147964186186' does not exist, cannot be loaded due to missing permissions, or does not support this operation."));
        $this->assertStringContainsString('Page access_token', $copy);
        $this->assertStringContainsString('User Token', $copy);
        $this->assertStringContainsString('1784147964186186', $copy);
    }

    public function test_instagram_oauth_notice_is_hidden_when_already_connected() {
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        $_GET['page'] = 'tr-ai-wire-social-settings';
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array(
            'x_user_access_token' => '',
            'instagram_user_access_token' => 'page-token',
            'instagram_user_id' => '1784147964186186',
        ), false);
        ob_start();
        TechnoRapper_AI_Wire_Instagram_OAuth::admin_notice();
        $html = ob_get_clean();
        unset($_GET['page']);
        $this->assertStringNotContainsString('before connecting', $html);
        $this->assertStringNotContainsString('Unified Login Configuration ID', $html);
        $this->assertStringNotContainsString('wp-config.php', $html);
    }

    public function test_instagram_oauth_notice_explains_optional_connect_when_disconnected() {
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        $_GET['page'] = 'tr-ai-wire-social-settings';
        delete_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION);
        ob_start();
        TechnoRapper_AI_Wire_Instagram_OAuth::admin_notice();
        $html = ob_get_clean();
        unset($_GET['page']);
        $this->assertStringContainsString('optional one-click Connect button', $html);
        $this->assertStringContainsString('paste a Facebook Page access token', $html);
    }

    public function test_social_image_prompt_is_photoreal_news_poster() {
        update_option(TechnoRapper_AI_Wire::SOCIAL_CONTEXT_OPTION, array(
            'status' => 'ready',
            'started_at' => gmdate('Y-m-d H:i:s'),
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'fingerprint' => 'prompt-test',
            'error' => '',
            'profile' => array(
                'site_name' => 'TechnoRapper',
                'visual_style' => 'Navy cyan magazine art',
            ),
        ), false);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Prompt article',
        ));
        $prompt = $this->invoke_private('social_image_prompt', array(get_post($post_id), array(
            'on_image_headline' => '6,300 tech jobs gone',
            'visual_brief' => 'Symbolic chip glow',
        )));
        $this->assertStringContainsString('Untitled photoreal cinematic photograph', $prompt);
        $this->assertStringContainsString('vertical 4:5', $prompt);
        $this->assertStringContainsString('not a poster', $prompt);
        $this->assertStringContainsString('Do not render any text', $prompt);
        $this->assertStringContainsString('cinematic photoreal news photography', $prompt);
        $this->assertStringNotContainsString('6,300 tech jobs gone', $prompt);
        $this->assertStringNotContainsString('Navy cyan magazine art', $prompt);
        $this->assertStringNotContainsString('Symbolic chip glow', $prompt);
        $this->assertStringNotContainsString('regeneration attempt', $prompt);
        $this->assertStringNotContainsString('Subject:', $prompt);
        $this->assertStringContainsString('No nudity', $prompt);
        $this->assertStringContainsString('fully clothed', $prompt);
        $this->assertStringContainsString('No children', $prompt);
        $this->assertStringContainsString('stop a thumb mid-scroll', $prompt);
        $this->assertStringContainsString('make someone tap', $prompt);
        $this->assertStringNotContainsString('sly photoreal visual beat', $prompt);
        $this->assertStringNotContainsString('one sly visual beat', $prompt);
        $this->assertStringNotContainsString('standup', $prompt);
        update_post_meta($post_id, '_tr_ai_wire_voice', 'comic');
        $comic_prompt = $this->invoke_private('social_image_prompt', array(get_post($post_id), array(
            'on_image_headline' => '6,300 tech jobs gone',
            'visual_brief' => 'Photoreal office workers carrying boxes at dusk',
        )));
        $this->assertStringContainsString('sly photoreal visual beat', $comic_prompt);
        $this->assertStringContainsString('one sly visual beat', $comic_prompt);
    }

    public function test_unsafe_visual_brief_is_dropped_before_the_image_api() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Safety brief',
        ));
        $prompt = $this->invoke_private('social_image_prompt', array(get_post($post_id), array(
            'visual_brief' => 'A nude model holding a phone on a bed',
        )));
        $this->assertStringNotContainsString('nude model', strtolower($prompt));
        $this->assertStringNotContainsString('on a bed', $prompt);
        $this->assertStringContainsString('No nudity', $prompt);
        $this->assertTrue($this->invoke_private('social_image_brief_is_unsafe', array('sexy woman with a laptop')));
        $this->assertFalse($this->invoke_private('social_image_brief_is_unsafe', array('Photoreal office workers carrying boxes at dusk')));
    }

    public function test_social_copy_policy_holds_explicit_captions_not_news_policy_words() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Policy copy',
            'post_excerpt' => 'A platform update.',
        ));
        $unsafe = $this->invoke_private('sanitize_social_copy', array(array(
            'hook' => 'This is porn bait for clicks',
            'on_image_headline' => 'Porn bait',
            'instagram_caption' => 'This is porn bait for clicks',
        ), get_post($post_id)));
        $blocked = $this->invoke_private('social_copy_policy_error', array($unsafe));
        $this->assertWPError($blocked);
        $this->assertSame('tr_ai_wire_social_policy', $blocked->get_error_code());
        $news = $this->invoke_private('sanitize_social_copy', array(array(
            'hook' => 'Instagram updates nudity rules',
            'on_image_headline' => 'New nudity rules',
            'instagram_caption' => 'Instagram updates nudity rules for apps',
        ), get_post($post_id)));
        $this->assertNull($this->invoke_private('social_copy_policy_error', array($news)));
        $desk = $this->invoke_private('social_copy_system_prompt', array('TechnoRapper'));
        $this->assertStringContainsString('growth lead', $desk);
        $this->assertStringContainsString('generic clickbait', $desk);
        $this->assertStringContainsString('wire dump', $desk);
        $this->assertStringContainsString('no nudity', strtolower($desk));
        $this->assertStringNotContainsString('pinch of dry comic sense', $desk);
        $comic_desk = $this->invoke_private('social_copy_system_prompt', array('TechnoRapper', true));
        $this->assertStringContainsString('pinch of dry comic sense', $comic_desk);
        $this->assertStringContainsString('Never a meme page', $comic_desk);
        $user = $this->invoke_private('social_copy_user_prompt', array('TechnoRapper', array(), array('title' => 'Chip news')));
        $this->assertStringContainsString('reader stake', $user);
        $this->assertStringContainsString('saves, and sends', $user);
        $this->assertStringContainsString('Never use: you won’t believe', $user);
        $this->assertStringContainsString('save or send ask', $user);
        $this->assertStringContainsString('named hook in the first', $user);
        $this->assertStringContainsString('fully clothed', $user);
        $this->assertStringNotContainsString('One dry wink is enough', $user);
        $comic_user = $this->invoke_private('social_copy_user_prompt', array('TechnoRapper', array(), array('title' => 'Chip news'), true));
        $this->assertStringContainsString('One dry wink is enough', $comic_user);
        $this->assertStringContainsString('sly photoreal visual beat', $comic_user);
        $this->assertStringContainsString('do not turn the Reel into a bit', $comic_user);
        $this->assertNull($this->invoke_private('sanitize_social_idea_item', array(array(
            'title' => 'Nude recap cover',
            'description' => 'A naked model as the hero of the graphic.',
        ))));
    }

    public function test_social_image_prompt_adds_variation_after_regenerate() {
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Regenerate prompt',
        ));
        update_post_meta($post_id, '_tr_ai_wire_social_image_revision', 2);
        $prompt = $this->invoke_private('social_image_prompt', array(get_post($post_id), array(
            'on_image_headline' => 'Faster phone chip',
            'visual_brief' => 'Photoreal office workers carrying boxes toward glass elevators at dusk',
        )));
        $this->assertStringContainsString('regeneration attempt 2', $prompt);
        $this->assertStringContainsString('different camera angle', $prompt);
        $this->assertStringContainsString('Photoreal office workers', $prompt);
        $this->assertStringContainsString('No nudity', $prompt);
    }

    public function test_social_retry_without_regenerate_reuses_cached_image() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required for the social retry path.');
        }
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Reuse graphic',
            'post_excerpt' => 'Keep the existing poster.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 41);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'Hook',
            'on_image_headline' => 'Headline',
            'x' => 'X text',
            'instagram' => 'Instagram caption',
            'instagram_alt' => 'Alt',
        )));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'needs_review',
            'image_attachment_id' => $attachment_id,
            'error_text' => 'Temporary Instagram publish failure.',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        $this->assertTrue($this->invoke_private('requeue_social_item', array($id, false)));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, image_attachment_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('queued', $row['status']);
        $this->assertSame($attachment_id, (int) $row['image_attachment_id']);
        $this->assertSame($attachment_id, (int) get_post_meta($post_id, '_tr_ai_wire_social_image_id', true));

        $image_calls = 0;
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$image_calls) {
            if (strpos((string) $url, 'api.openai.com/v1/images/generations') !== false) {
                $image_calls++;
                return new WP_Error('blocked', 'Image generation should not run on a reuse retry.');
            }
            if (strpos((string) $url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos((string) $url, 'content_publishing_limit') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos((string) $url, '/media_publish') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-media-reuse'));
            }
            if (strpos((string) $url, '/media') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-container-reuse'));
            }
            if (strpos((string) $url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos((string) $url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/p/reuse/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        $published = $wpdb->get_row($wpdb->prepare("SELECT status, image_attachment_id, remote_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('published', $published['status']);
        $this->assertSame('ig-media-reuse', $published['remote_id']);
        $this->assertSame($attachment_id, (int) $published['image_attachment_id']);
        $this->assertSame(0, $image_calls);
    }

    public function test_profane_social_copy_is_held_not_posted() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required for the social policy path.');
        }
        $this->onboard_social(array('x'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Chip news',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 91);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'This is fucking news',
            'on_image_headline' => 'This is fucking news',
            'x' => 'This is fucking news',
            'instagram' => 'This is fucking news',
            'instagram_alt' => 'Alt',
        )));
        $this->fire_social_publish($post_id);
        $posted = 0;
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$posted) {
            if (strpos((string) $url, 'api.x.com') !== false) {
                $posted++;
                return new WP_Error('blocked', 'X must not receive policy-violating copy.');
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, error_text FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'x'", $post_id), ARRAY_A);
        $this->assertSame('needs_review', $row['status']);
        $this->assertStringContainsString('profanity', (string) $row['error_text']);
        $this->assertSame(0, $posted);
    }

    public function test_moderation_flag_holds_social_post() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required for the social policy path.');
        }
        $this->onboard_social(array('x'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Chip news',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 92);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'A grounded phone-chip update',
            'on_image_headline' => 'Phone chip update',
            'x' => 'A grounded phone-chip update',
            'instagram' => 'A grounded phone-chip update',
            'instagram_alt' => 'Alt',
        )));
        $this->fire_social_publish($post_id);
        remove_filter('tr_ai_wire_moderation_result', array($this, 'allow_moderation_in_tests'), 10);
        add_filter('tr_ai_wire_moderation_result', function () {
            return array('flagged' => true, 'categories' => array('hate' => true));
        });
        $posted = 0;
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$posted) {
            if (strpos((string) $url, 'api.x.com') !== false) {
                $posted++;
                return new WP_Error('blocked', 'X must not receive moderated copy.');
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, error_text FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'x'", $post_id), ARRAY_A);
        $this->assertSame('needs_review', $row['status']);
        $this->assertStringContainsString('Moderation', (string) $row['error_text']);
        $this->assertSame(0, $posted);
    }

    public function test_late_x_onboard_queues_saved_graphics_without_generating() {
        $this->onboard_social(array('instagram'));
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required to reuse a saved social graphic.');
        }
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Already on Instagram',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 51);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'Already on Instagram',
            'on_image_headline' => 'Already on Instagram',
            'x' => 'Already on Instagram',
            'instagram' => 'Already on Instagram',
            'instagram_alt' => 'Alt',
        )));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'published',
            'image_attachment_id' => $attachment_id,
            'remote_id' => 'ig-live',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $this->assertSame(0, $this->invoke_private('missing_platform_post_count', array('x')));
        update_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array_merge(
            get_option(TechnoRapper_AI_Wire::SOCIAL_CREDENTIALS_OPTION, array()),
            array(
                'x_api_key' => 'api-key',
                'x_api_secret' => 'api-secret',
                'x_access_token' => 'access-token',
                'x_access_token_secret' => 'access-secret',
            )
        ), false);
        $this->assertSame(0, $this->invoke_private('missing_platform_post_count', array('x')));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_x_enabled' => 1,
            'social_instagram_enabled' => 1,
            'social_distribution_enabled' => 1,
        )), false);
        $this->assertSame(0, $this->invoke_private('enqueue_missing_platform_posts', array('x')));
        $x = $wpdb->get_row($wpdb->prepare("SELECT status, image_attachment_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'x'", $post_id), ARRAY_A);
        $this->assertSame('queued', $x['status']);
        $this->assertSame($attachment_id, (int) $x['image_attachment_id']);

        $image_calls = 0;
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$image_calls) {
            if (strpos((string) $url, 'api.openai.com/v1/images/generations') !== false) {
                $image_calls++;
                return new WP_Error('blocked', 'Saved graphic should be reused for late X onboarding.');
            }
            if (strpos((string) $url, 'api.openai.com') !== false) {
                return new WP_Error('blocked', 'Copy is already cached.');
            }
            if (strpos((string) $url, 'media/upload/initialize') !== false) {
                return $this->mock_http_ok(array('data' => array('id' => 'x-media-late')));
            }
            if (strpos((string) $url, '/append') !== false || strpos((string) $url, '/finalize') !== false) {
                return $this->mock_http_ok(array('data' => array('processing_info' => array('state' => 'succeeded'))));
            }
            if (strpos((string) $url, 'api.x.com/2/tweets') !== false) {
                return $this->mock_http_ok(array('data' => array('id' => 'x-late-onboard')));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        $published = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id, image_attachment_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'x'", $post_id), ARRAY_A);
        $this->assertSame('published', $published['status']);
        $this->assertSame('x-late-onboard', $published['remote_id']);
        $this->assertSame($attachment_id, (int) $published['image_attachment_id']);
        $this->assertSame(0, $image_calls);
        $this->assertSame(0, $this->invoke_private('enqueue_missing_platform_posts', array('x')));
    }

    public function test_social_retry_with_regenerate_builds_a_new_image() {
        $jpeg = $this->tiny_jpeg_bytes();
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1 || $jpeg === '') {
            $this->markTestSkipped('GD and uploads are required for the social regenerate path.');
        }
        $this->onboard_social(array('instagram', 'x'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_news_formats' => array('editorial_poster'),
        )), false);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'New graphic please',
            'post_excerpt' => 'Retry should not reuse the old poster.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 42);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'Hook',
            'on_image_headline' => 'Faster phone chip',
            'x' => 'X text',
            'instagram' => 'Instagram caption',
            'instagram_alt' => 'Alt',
            'visual_brief' => 'Photoreal phone on a dark desk at dusk',
        )));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'needs_review',
            'image_attachment_id' => $attachment_id,
            'error_text' => 'Temporary Instagram publish failure.',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $ig_id = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'x',
            'status' => 'queued',
            'image_attachment_id' => $attachment_id,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $this->assertTrue($this->invoke_private('requeue_social_item', array($ig_id, true)));
        $this->assertSame(0, (int) get_post_meta($post_id, '_tr_ai_wire_social_image_id', true));
        $this->assertSame(1, (int) get_post_meta($post_id, '_tr_ai_wire_social_image_revision', true));
        $this->assertSame('1', (string) get_post_meta($post_id, '_tr_ai_wire_social_regenerate_image', true));
        $ig = $wpdb->get_row($wpdb->prepare("SELECT status, image_attachment_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $ig_id), ARRAY_A);
        $x = $wpdb->get_row($wpdb->prepare("SELECT status, image_attachment_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'x'", $post_id), ARRAY_A);
        $this->assertSame('queued', $ig['status']);
        $this->assertSame(0, (int) $ig['image_attachment_id']);
        $this->assertSame('queued', $x['status']);
        $this->assertSame(0, (int) $x['image_attachment_id']);
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_social', array('status' => 'needs_review'), array('post_id' => $post_id, 'platform' => 'x'));

        $prompts = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($jpeg, &$prompts) {
            $url = (string) $url;
            if (strpos($url, 'api.openai.com/v1/images/generations') !== false) {
                $decoded = json_decode((string) ($args['body'] ?? ''), true);
                $prompts[] = (string) ($decoded['prompt'] ?? '');
                return $this->mock_http_ok(array('data' => array(array('b64_json' => base64_encode($jpeg)))));
            }
            if (strpos($url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos($url, 'content_publishing_limit') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos($url, '/media_publish') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-media-regen'));
            }
            if (strpos($url, '/media') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-container-regen'));
            }
            if (strpos($url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos($url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/p/regen/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        $published = $wpdb->get_row($wpdb->prepare("SELECT status, image_attachment_id, remote_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $ig_id), ARRAY_A);
        $this->assertSame('published', $published['status']);
        $this->assertSame('ig-media-regen', $published['remote_id']);
        $this->assertNotSame($attachment_id, (int) $published['image_attachment_id']);
        $this->assertGreaterThan(0, (int) $published['image_attachment_id']);
        $this->assertSame((int) $published['image_attachment_id'], (int) get_post_meta($post_id, '_tr_ai_wire_social_image_id', true));
        $this->assertSame('', (string) get_post_meta($post_id, '_tr_ai_wire_social_regenerate_image', true));
        $this->assertSame(TechnoRapper_AI_Wire::SOCIAL_IMAGE_LAYOUT, (string) get_post_meta((int) $published['image_attachment_id'], '_tr_ai_wire_social_layout', true));
        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('Untitled photoreal cinematic photograph', $prompts[0]);
        $this->assertStringContainsString('Do not render any text', $prompts[0]);
        $this->assertStringContainsString('regeneration attempt 1', $prompts[0]);
        $this->assertStringContainsString('Photoreal phone on a dark desk at dusk', $prompts[0]);
        $this->assertStringNotContainsString('Faster phone chip', $prompts[0]);
    }

    public function test_social_queue_page_offers_repost_on_published_instagram() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required to preview social graphics.');
        }
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Live Instagram graphic',
        ));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'published',
            'image_attachment_id' => $attachment_id,
            'remote_id' => 'ig-old',
            'remote_url' => 'https://www.instagram.com/p/old/',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        ob_start();
        $this->plugin->social_distribution_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('Regenerate graphic and post again', $html);
        $this->assertStringContainsString('Delete the live Instagram post and replace it', $html);
        $this->assertStringContainsString('This deletes the live Instagram post', $html);
        $this->assertStringContainsString('name="regenerate_image"', $html);
        $this->assertStringContainsString('View live post', $html);
        $this->assertStringNotContainsString('Retry this platform post', $html);
    }

    public function test_social_queue_page_labels_stale_graphic() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required to preview social graphics.');
        }
        delete_post_meta($attachment_id, '_tr_ai_wire_social_layout');
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Old tiny caption poster',
        ));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'needs_review',
            'image_attachment_id' => $attachment_id,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        ob_start();
        $this->plugin->social_distribution_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('Saved graphic — retry posts this file unless you generate a new one', $html);
        $this->assertStringContainsString('Post existing graphic to Instagram', $html);
        $this->assertStringContainsString('Generate a new graphic and retry', $html);
        $this->assertStringContainsString('name="regenerate_image" value="1"', $html);
        $this->assertStringNotContainsString('name="regenerate_image" value="1" checked', $html);
    }

    public function test_social_retry_reuses_existing_graphic_unless_regenerate_is_requested() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required for the social retry path.');
        }
        delete_post_meta($attachment_id, '_tr_ai_wire_social_layout');
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Saved graphic',
            'post_excerpt' => 'Token retry should reuse the file.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 43);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'Hook',
            'on_image_headline' => 'Headline',
            'x' => 'X text',
            'instagram' => 'Instagram caption',
            'instagram_alt' => 'Alt',
        )));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'needs_review',
            'image_attachment_id' => $attachment_id,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        $this->assertTrue($this->invoke_private('requeue_social_item', array($id, false)));
        $this->assertSame($attachment_id, (int) get_post_meta($post_id, '_tr_ai_wire_social_image_id', true));
        $this->assertSame('', (string) get_post_meta($post_id, '_tr_ai_wire_social_regenerate_image', true));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, image_attachment_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('queued', $row['status']);
        $this->assertSame($attachment_id, (int) $row['image_attachment_id']);
    }

    public function test_social_published_repost_regenerates_and_publishes_again() {
        $jpeg = $this->tiny_jpeg_bytes();
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1 || $jpeg === '') {
            $this->markTestSkipped('GD and uploads are required for the social repost path.');
        }
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(get_option(TechnoRapper_AI_Wire::OPTION, array()), array(
            'social_news_formats' => array('editorial_poster'),
        )), false);
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Repost with new graphic',
            'post_excerpt' => 'Replace the live Instagram post.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 44);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'Hook',
            'on_image_headline' => 'New huge type',
            'visual_brief' => 'Photoreal trader covering his face at a red market board',
            'x' => 'X text',
            'instagram' => 'Instagram caption',
            'instagram_alt' => 'Alt',
        )));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'published',
            'image_attachment_id' => $attachment_id,
            'remote_id' => '17841400000000001',
            'remote_url' => 'https://www.instagram.com/p/old-live/',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        $deleted_urls = array();
        $this->stub_http_for_social_delete($deleted_urls);
        $this->assertTrue($this->invoke_private('requeue_social_item', array($id, false)));
        $this->assertCount(1, $deleted_urls);
        $this->assertStringContainsString('17841400000000001', $deleted_urls[0]);
        $this->assertSame(0, (int) get_post_meta($post_id, '_tr_ai_wire_social_image_id', true));
        $this->assertSame('1', (string) get_post_meta($post_id, '_tr_ai_wire_social_regenerate_image', true));
        $this->assertSame('1', (string) get_post_meta($post_id, '_tr_ai_wire_social_repost_' . $id, true));
        $queued = $wpdb->get_row($wpdb->prepare("SELECT status, image_attachment_id, remote_id, remote_url FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('queued', $queued['status']);
        $this->assertSame(0, (int) $queued['image_attachment_id']);
        $this->assertSame('', (string) $queued['remote_id']);

        $prompts = array();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($jpeg, &$prompts) {
            $url = (string) $url;
            if (strpos($url, 'api.openai.com/v1/images/generations') !== false) {
                $decoded = json_decode((string) ($args['body'] ?? ''), true);
                $prompts[] = (string) ($decoded['prompt'] ?? '');
                return $this->mock_http_ok(array('data' => array(array('b64_json' => base64_encode($jpeg)))));
            }
            if (strpos($url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos($url, 'content_publishing_limit') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos($url, '/media_publish') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-media-repost'));
            }
            if (strpos($url, '/media') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-container-repost'));
            }
            if (strpos($url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos($url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/p/repost/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        $published = $wpdb->get_row($wpdb->prepare("SELECT status, image_attachment_id, remote_id, remote_url FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('published', $published['status']);
        $this->assertSame('ig-media-repost', $published['remote_id']);
        $this->assertSame('https://www.instagram.com/p/repost/', $published['remote_url']);
        $this->assertNotSame($attachment_id, (int) $published['image_attachment_id']);
        $this->assertGreaterThan(0, (int) $published['image_attachment_id']);
        $this->assertSame(TechnoRapper_AI_Wire::SOCIAL_IMAGE_LAYOUT, (string) get_post_meta((int) $published['image_attachment_id'], '_tr_ai_wire_social_layout', true));
        $this->assertSame('', (string) get_post_meta($post_id, '_tr_ai_wire_social_repost_' . $id, true));
        $this->assertCount(1, $prompts);
        $this->assertStringContainsString('Untitled photoreal cinematic photograph', $prompts[0]);
        $this->assertStringContainsString('Do not render any text', $prompts[0]);
    }

    public function test_social_published_repost_skips_daily_cap() {
        $jpeg = $this->tiny_jpeg_bytes();
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1 || $jpeg === '') {
            $this->markTestSkipped('GD and uploads are required for the social repost path.');
        }
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_daily_limit' => 1,
        )), false);
        $older = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Already used the daily slot'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Replace live graphic',
            'post_excerpt' => 'Same-day Instagram replacement.',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 45);
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        update_post_meta($post_id, '_tr_ai_wire_social_copy', wp_json_encode(array(
            'hook' => 'Hook',
            'on_image_headline' => 'Headline',
            'instagram' => 'Caption',
            'instagram_alt' => 'Alt',
        )));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $older,
            'platform' => 'instagram',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'published',
            'image_attachment_id' => $attachment_id,
            'remote_id' => '17841400000000002',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        $deleted_urls = array();
        $this->stub_http_for_social_delete($deleted_urls);
        $this->assertTrue($this->invoke_private('requeue_social_item', array($id, true)));
        $this->assertCount(1, $deleted_urls);
        $this->assertStringContainsString('17841400000000002', $deleted_urls[0]);
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($jpeg) {
            $url = (string) $url;
            if (strpos($url, 'api.openai.com/v1/images/generations') !== false) {
                return $this->mock_http_ok(array('data' => array(array('b64_json' => base64_encode($jpeg)))));
            }
            if (strpos($url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos($url, 'content_publishing_limit') !== false) {
                return $this->instagram_account_probe_ok();
            }
            if (strpos($url, '/media_publish') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-media-cap-repost'));
            }
            if (strpos($url, '/media') !== false) {
                return $this->mock_http_ok(array('id' => 'ig-container-cap-repost'));
            }
            if (strpos($url, 'fields=status_code') !== false) {
                return $this->mock_http_ok(array('status_code' => 'FINISHED'));
            }
            if (strpos($url, 'fields=permalink') !== false) {
                return $this->mock_http_ok(array('permalink' => 'https://www.instagram.com/p/cap-repost/'));
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->plugin->process_social_queue();
        $published = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('published', $published['status']);
        $this->assertSame('ig-media-cap-repost', $published['remote_id']);
    }

    public function test_raising_social_daily_limit_kicks_queued_work() {
        $this->onboard_social(array('instagram'));
        update_option(TechnoRapper_AI_Wire::OPTION, array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_daily_limit' => 1,
        )), false);
        $done = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Already posted today'));
        $waiting = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Stuck behind the cap'));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $done,
            'platform' => 'instagram',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $waiting,
            'platform' => 'instagram',
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $tomorrow = strtotime('tomorrow 00:20:00 UTC');
        wp_clear_scheduled_hook(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        wp_schedule_single_event($tomorrow, TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        $this->assertSame($tomorrow, wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK));
        $old = array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_daily_limit' => 1,
        ));
        $new = array_merge(TechnoRapper_AI_Wire::defaults(), array(
            'social_distribution_enabled' => 1,
            'social_daily_limit' => 6,
        ));
        $this->plugin->settings_updated($old, $new);
        $next = wp_next_scheduled(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        $this->assertNotFalse($next);
        $this->assertLessThan($tomorrow, $next);
        $this->assertLessThanOrEqual(time() + 20, $next);
    }

    public function test_social_queue_page_warns_when_worker_is_deferred() {
        $admin_id = $this->factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin_id);
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array('post_status' => 'publish', 'post_title' => 'Waiting on cron'));
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        wp_clear_scheduled_hook(TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        wp_schedule_single_event(time() + DAY_IN_SECONDS, TechnoRapper_AI_Wire::SOCIAL_CRON_HOOK);
        ob_start();
        $this->plugin->social_distribution_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('Queued posts are waiting until', $html);
        $this->assertStringContainsString('Process queued social posts now', $html);
        $this->assertStringContainsString('Raising the daily limit does not start them by itself', $html);
    }

    public function test_social_needs_review_retry_does_not_delete_live_post() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required for the social retry path.');
        }
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Ambiguous Instagram outcome',
        ));
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'needs_review',
            'image_attachment_id' => $attachment_id,
            'remote_id' => '17841400000000003',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        $deleted_urls = array();
        $this->stub_http_for_social_delete($deleted_urls);
        $this->assertTrue($this->invoke_private('requeue_social_item', array($id, false)));
        $this->assertSame(array(), $deleted_urls);
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('queued', $row['status']);
        $this->assertSame('', (string) $row['remote_id']);
        $this->assertSame($attachment_id, (int) get_post_meta($post_id, '_tr_ai_wire_social_image_id', true));
    }

    public function test_social_published_repost_continues_if_instagram_already_deleted() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required for the social repost path.');
        }
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Already deleted on Instagram',
        ));
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'published',
            'image_attachment_id' => $attachment_id,
            'remote_id' => '17841400000000004',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            $url = (string) $url;
            if ($method === 'DELETE') {
                return $this->graph_error_http('(#100) Object with ID does not exist', 400);
            }
            if (strpos($url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->assertTrue($this->invoke_private('requeue_social_item', array($id, true)));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('queued', $row['status']);
        $this->assertSame('', (string) $row['remote_id']);
    }

    public function test_social_published_repost_keeps_live_post_if_delete_fails() {
        $attachment_id = $this->create_social_jpeg_attachment();
        if ($attachment_id < 1) {
            $this->markTestSkipped('GD and uploads are required for the social repost path.');
        }
        $this->onboard_social(array('instagram'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Delete blocked',
        ));
        update_post_meta($post_id, '_tr_ai_wire_social_image_id', $attachment_id);
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'tr_ai_wire_social', array(
            'post_id' => $post_id,
            'platform' => 'instagram',
            'status' => 'published',
            'image_attachment_id' => $attachment_id,
            'remote_id' => '17841400000000005',
            'remote_url' => 'https://www.instagram.com/p/still-live/',
            'created_at' => $now,
            'updated_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            $method = strtoupper((string) ($args['method'] ?? 'GET'));
            $url = (string) $url;
            if ($method === 'DELETE') {
                return $this->graph_error_http('(#200) Permissions error', 403);
            }
            if (strpos($url, 'fields=id,username') !== false) {
                return $this->instagram_account_probe_ok();
            }
            return new WP_Error('blocked', 'Unexpected URL ' . $url);
        }, 10, 3);
        $this->assertFalse($this->invoke_private('requeue_social_item', array($id, true)));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, remote_id, remote_url, error_text FROM {$wpdb->prefix}tr_ai_wire_social WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('published', $row['status']);
        $this->assertSame('17841400000000005', $row['remote_id']);
        $this->assertSame('https://www.instagram.com/p/still-live/', $row['remote_url']);
        $this->assertStringContainsString('could not delete the live post', $row['error_text']);
        $this->assertSame($attachment_id, (int) get_post_meta($post_id, '_tr_ai_wire_social_image_id', true));
        $this->assertSame('', (string) get_post_meta($post_id, '_tr_ai_wire_social_repost_' . $id, true));
    }

    public function test_direct_publish_insert_does_not_queue_social() {
        $this->onboard_social(array('x'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Inserted live',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 44);
        $this->plugin->queue_social_for_post('publish', 'new', get_post($post_id));
        global $wpdb;
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
        $this->fire_social_publish($post_id);
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
    }

    public function test_create_draft_does_not_syndicate_until_status_transition() {
        $this->onboard_social(array('x'));
        $id = $this->create_queue_item();
        $item = array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-social-draft',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
        );
        $rewrite = array(
            'title' => 'A useful technology update',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
        );
        $this->invoke_private('create_draft', array($id, $item, $rewrite, array('verdict' => 'pass'), $this->draft_settings()));
        global $wpdb;
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social"));
        $post_id = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id));
        $this->assertSame('draft', get_post_status($post_id));

        $settings = TechnoRapper_AI_Wire::defaults();
        $settings['auto_publish'] = 1;
        $this->invoke_private('create_draft', array($id, $item, $rewrite, array('verdict' => 'pass'), $settings));
        $this->assertSame('publish', get_post_status($post_id));
        $this->assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d", $post_id)));
        $this->assertSame('publish', get_post_status($post_id));
    }

    public function test_auto_publish_still_pins_home_and_queues_social() {
        $this->onboard_social(array('x'));
        $android = self::factory()->category->create_and_get(array(
            'name' => 'Android',
            'slug' => 'android-parity',
        ));
        $id = $this->create_queue_item();
        $this->invoke_private('create_draft', array($id, array(
            'source_url' => 'https://example.com/story',
            'feed_name' => 'Example Feed',
            'source_guid' => 'guid-parity',
            'experiment_id' => 'test-experiment',
            'variant' => 'A',
            'source_body' => 'Source body <img src="https://cdn.example.com/photo.jpg" alt="photo">',
        ), array(
            'title' => 'Samsung Android update lands today',
            'lede' => 'A concise summary.',
            'body_html' => '<p>Grounded article body.</p>',
            'category' => 'Android',
            'tags' => array('Android', 'Samsung'),
        ), array('verdict' => 'pass'), TechnoRapper_AI_Wire::defaults()));
        global $wpdb;
        $post_id = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id));
        $this->assertSame('publish', get_post_status($post_id));
        $this->assertTrue(is_sticky($post_id));
        $this->assertTrue(has_post_thumbnail($post_id));
        $this->assertContains((int) $android->term_id, wp_get_post_categories($post_id));
        $this->assertGreaterThan(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}tr_ai_wire_social WHERE post_id = %d AND platform = 'x'", $post_id)));
        $this->assertSame('publish', get_post_status($post_id));
    }

    public function test_failed_review_is_copied_to_quarantine_table() {
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'generated',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'reject',
                'score' => 10,
                'policy_flags' => array('hate'),
                'pragmatism' => array('is_grounded' => false, 'similarity_risk' => 'high'),
            )),
        ), array('id' => $id));
        $this->invoke_private('process_item', array($id, TechnoRapper_AI_Wire::defaults()));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertEmpty($row['wp_post_id']);
        $ledger = $wpdb->get_row($wpdb->prepare("SELECT queue_item_id, reason FROM {$wpdb->prefix}tr_ai_wire_quarantine WHERE queue_item_id = %d", $id), ARRAY_A);
        $this->assertSame((string) $id, (string) $ledger['queue_item_id']);
        $this->assertStringContainsString('verdict=reject', (string) $ledger['reason']);
    }

    public function test_writer_api_failure_does_not_clear_a_generated_item() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $id = $this->create_queue_item();
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'generated',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'lede' => 'A concise summary.',
                'body_html' => '<p>Grounded article body.</p>',
            )),
        ), array('id' => $id));
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            if (strpos($url, 'api.openai.com/v1/responses') !== false) {
                return new WP_Error('http_request_failed', 'upstream timeout');
            }
            return $preempt;
        }, 10, 3);
        $this->invoke_private('process_item', array($id, $this->draft_settings()));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, rewrite_json, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('retry_wait', $row['status']);
        $rewrite = json_decode($row['rewrite_json'], true);
        $this->assertSame('A useful technology update', $rewrite['title']);
        $this->assertEmpty($row['wp_post_id']);
    }

    public function test_social_hook_errors_do_not_unpublish_the_article() {
        $this->onboard_social(array('x'));
        $post_id = $this->factory()->post->create(array(
            'post_status' => 'publish',
            'post_title' => 'Keep this live',
        ));
        update_post_meta($post_id, '_tr_ai_wire_queue_id', 77);
        $this->plugin->queue_social_for_post('publish', 'draft', null);
        $this->assertSame('publish', get_post_status($post_id));
    }

    public function test_thin_feed_item_is_held_before_a_model_call() {
        update_option(TechnoRapper_AI_Wire::API_KEY_OPTION, 'test-key', false);
        $id = $this->invoke_private('insert_item', array(
            hash('sha256', wp_generate_uuid4()),
            'test-experiment',
            'A',
            'https://example.com/feed.xml',
            'Example Feed',
            'guid-' . wp_generate_uuid4(),
            'https://example.com/thin',
            'Quick look',
            'A short note about a gadget.',
        ));
        $calls = 0;
        add_filter('pre_http_request', function () use (&$calls) {
            $calls++;
            return new WP_Error('tr_ai_wire_test', 'The model should not run for a thin source.');
        });
        $this->invoke_private('process_item', array($id, $this->draft_settings(), true));
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, error_text, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame(0, $calls);
        $this->assertSame('quarantined', $row['status']);
        $this->assertStringContainsString('too thin', $row['error_text']);
        $this->assertEmpty($row['wp_post_id']);
    }

    public function test_deal_list_is_held_even_when_the_review_score_would_pass() {
        $id = $this->invoke_private('insert_item', array(
            hash('sha256', wp_generate_uuid4()),
            'test-experiment',
            'A',
            'https://example.com/feed.xml',
            'Example Feed',
            'guid-' . wp_generate_uuid4(),
            'https://example.com/deals',
            'Best 10 laptop deals under $500 this week',
            'Apple announced the M5 chip on March 4, 2026, with shipping in April at $999. The chip raises memory bandwidth and adds an on-device model for photos. Developers can use the new API in the beta released the same day.',
        ));
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'tr_ai_wire_items', array(
            'status' => 'reviewed',
            'rewrite_json' => wp_json_encode(array(
                'title' => 'A useful technology update',
                'body_html' => '<p>Grounded article body.</p>',
            )),
            'review_json' => wp_json_encode(array(
                'verdict' => 'pass',
                'score' => 96,
                'story_value' => array(
                    'publish' => true,
                    'substance' => 'major',
                    'reader_demand' => 'high',
                    'adds_value' => true,
                ),
            )),
        ), array('id' => $id));
        $this->invoke_private('process_item', array($id, TechnoRapper_AI_Wire::defaults()));
        $row = $wpdb->get_row($wpdb->prepare("SELECT status, error_text, wp_post_id FROM {$wpdb->prefix}tr_ai_wire_items WHERE id = %d", $id), ARRAY_A);
        $this->assertSame('quarantined', $row['status']);
        $this->assertStringContainsString('best-of list', $row['error_text']);
        $this->assertEmpty($row['wp_post_id']);
    }

    public function test_reviewer_story_value_blocks_a_high_score() {
        $settings = TechnoRapper_AI_Wire::defaults();
        $passing = array(
            'verdict' => 'pass',
            'score' => 93,
            'fact_flags' => array(),
            'policy_flags' => array(),
            'pragmatism' => array('is_grounded' => true, 'similarity_risk' => 'low', 'is_useful' => true, 'is_new' => true),
            'story_value' => array(
                'publish' => true,
                'substance' => 'major',
                'reader_demand' => 'high',
                'adds_value' => true,
                'reason' => 'Named chip launch readers will search.',
            ),
        );
        $this->assertSame(array(), $this->invoke_private('review_draft_blockers', array($passing, $settings)));
        $thin = $passing;
        $thin['story_value'] = array(
            'publish' => false,
            'substance' => 'thin',
            'reader_demand' => 'low',
            'adds_value' => false,
            'reason' => 'No named change.',
        );
        $reasons = $this->invoke_private('review_draft_blockers', array($thin, $settings));
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('too thin', implode(' ', $reasons));
        $older = $passing;
        unset($older['story_value']);
        $this->assertSame(array(), $this->invoke_private('review_draft_blockers', array($older, $settings)));
    }

    public function test_short_named_launch_is_not_treated_as_thin() {
        $reason = $this->invoke_private('story_value_local_reason', array(array(
            'source_title' => 'Apple announces M5',
            'source_body' => 'Apple announced the M5 on March 4 for $999.',
        )));
        $this->assertSame('', $reason);
    }

    public function test_background_job_is_claimed_once_and_does_not_call_openai() {
        $calls = 0;
        add_filter('pre_http_request', function () use (&$calls) {
            $calls++;
            return new WP_Error('tr_ai_wire_test', 'OpenAI should not be called while a job is only queued.');
        });
        $job = $this->invoke_private('enqueue_background_job', array('retry', array('item_id' => 5)));
        $this->assertSame('queued', $job['status']);
        $this->assertSame(0, $calls);
        $claimed = $this->invoke_private('claim_background_job', array($job['id']));
        $this->assertSame('running', $claimed['status']);
        $this->assertNull($this->invoke_private('claim_background_job', array($job['id'])));
        delete_option(TechnoRapper_AI_Wire::JOB_OPTION);
        delete_option(TechnoRapper_AI_Wire::JOB_LOCK_OPTION);
    }
}
