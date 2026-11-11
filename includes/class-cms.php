<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress adapter for the CMS-agnostic engine. After an AI Wire article is
 * actually published, optional webhook and static-export destinations run.
 * Failures here never unpublish the WordPress post.
 */
trait TechnoRapper_AI_Wire_Cms {
    public function fanout_cms_destinations_for_post($new_status, $old_status, $post) {
        try {
            $this->fanout_cms_on_verified_publish($new_status, $old_status, $post);
        } catch (Throwable $e) {
            // Destination errors are isolated from WordPress integrity.
        }
    }

    private function fanout_cms_on_verified_publish($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || !($post instanceof WP_Post)) {
            return;
        }
        if (!in_array((string) $old_status, array('draft', 'pending', 'future', 'auto-draft', 'new'), true)) {
            return;
        }
        if (wp_is_post_revision($post->ID) || !get_post_meta($post->ID, '_tr_ai_wire_queue_id', true)) {
            return;
        }
        if (get_post_status($post) !== 'publish') {
            return;
        }
        $settings = $this->settings();
        $destinations = $this->cms_destinations_from_settings($settings);
        if (!$destinations) {
            return;
        }
        $article = $this->cms_article_from_post($post);
        if ($article['title'] === '' || $article['status'] !== 'publish') {
            return;
        }
        $dispatcher = new TR_AI_Wire_Engine_Dispatcher();
        $dispatcher->publish($article, $destinations);
    }

    private function cms_destinations_from_settings($settings) {
        $settings = is_array($settings) ? $settings : array();
        $destinations = array();
        $url = trim((string) ($settings['cms_webhook_url'] ?? ''));
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $secret = (string) ($settings['cms_webhook_secret'] ?? '');
            $destinations['webhook'] = new TR_AI_Wire_Engine_Webhook($url, $secret, array($this, 'cms_webhook_transport'));
        }
        if (!empty($settings['cms_static_export'])) {
            $dir = $this->cms_static_export_dir();
            if ($dir !== '') {
                $destinations['static'] = new TR_AI_Wire_Engine_Static_Site($dir);
            }
        }
        $filtered = apply_filters('tr_ai_wire_cms_destinations', $destinations, $settings);
        return is_array($filtered) ? $filtered : $destinations;
    }

    public function cms_webhook_transport($url, $headers, $body) {
        $response = wp_remote_post($url, array(
            'timeout' => 20,
            'headers' => is_array($headers) ? $headers : array(),
            'body' => (string) $body,
        ));
        if (is_wp_error($response)) {
            return array('code' => 0, 'error' => $response->get_error_message(), 'body' => '');
        }
        return array(
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
            'error' => '',
        );
    }

    public function cms_static_export_dir() {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }
        return trailingslashit((string) $uploads['basedir']) . 'tr-ai-wire-export';
    }

    public function cms_article_from_post($post) {
        $post = $post instanceof WP_Post ? $post : get_post($post);
        if (!($post instanceof WP_Post)) {
            return TR_AI_Wire_Engine_Article::empty_payload();
        }
        $queue_id = (int) get_post_meta($post->ID, '_tr_ai_wire_queue_id', true);
        $item = $queue_id > 0 ? $this->get_item($queue_id) : array();
        $rewrite = array();
        if (is_array($item) && !empty($item['rewrite_json'])) {
            $decoded = json_decode($item['rewrite_json'], true);
            if (is_array($decoded)) {
                $rewrite = $decoded;
            }
        }
        $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));
        if (!is_array($tags) || !$tags) {
            $tags = isset($rewrite['tags']) ? (array) $rewrite['tags'] : array();
        }
        $categories = array();
        foreach (get_the_category($post->ID) as $category) {
            $categories[] = $category->name;
        }
        $image = get_the_post_thumbnail_url($post, 'full');
        $permalink = get_permalink($post);
        return TR_AI_Wire_Engine_Article::from_array(array(
            'id' => $queue_id > 0 ? 'item-' . $queue_id : 'post-' . (int) $post->ID,
            'slug' => (string) $post->post_name,
            'title' => (string) $post->post_title,
            'lede' => (string) ($rewrite['lede'] ?? $post->post_excerpt),
            'body_html' => (string) $post->post_content,
            'category' => isset($categories[0]) ? $categories[0] : (string) ($rewrite['category'] ?? ''),
            'tags' => $tags,
            'seo_title' => (string) ($rewrite['seo_title'] ?? $post->post_title),
            'meta_description' => (string) ($rewrite['meta_description'] ?? $post->post_excerpt),
            'focus_keyphrase' => (string) ($rewrite['focus_keyphrase'] ?? ''),
            'social_title' => (string) ($rewrite['social_title'] ?? ''),
            'social_description' => (string) ($rewrite['social_description'] ?? ''),
            'source_url' => (string) get_post_meta($post->ID, '_tr_ai_wire_source_url', true),
            'source_title' => is_array($item) ? (string) ($item['source_title'] ?? '') : '',
            'featured_image_url' => is_string($image) ? $image : '',
            'permalink' => is_string($permalink) ? $permalink : '',
            'status' => get_post_status($post) === 'publish' ? 'publish' : 'draft',
            'published_at' => get_post_time('c', true, $post),
            'experiment_id' => (string) get_post_meta($post->ID, '_tr_ai_wire_experiment_id', true),
            'variant' => (string) get_post_meta($post->ID, '_tr_ai_wire_variant', true),
            'site_name' => self::site_brand_name(),
        ));
    }
}
