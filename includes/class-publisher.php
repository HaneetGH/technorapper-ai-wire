<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Publisher {
    public function repair_home_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to repair homepage posts.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_repair_home');
        $this->enqueue_background_job('repair');
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-queue'));
        exit;
    }

    public function maybe_backfill_home_media() {
        if (get_option('tr_ai_wire_home_media_21') === '1') {
            return;
        }
        $this->repair_home_posts(40);
        update_option('tr_ai_wire_home_media_21', '1', false);
    }

    public function repair_home_posts($limit = 120) {
        $s = $this->settings();
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft'),
            'posts_per_page' => max(1, (int) $limit),
            'meta_key' => '_tr_ai_wire_queue_id',
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ));
        $count = 0;
        foreach ($posts as $post) {
            $qid = (int) get_post_meta($post->ID, '_tr_ai_wire_queue_id', true);
            $item = $qid > 0 ? $this->get_item($qid) : null;
            $rewrite = array();
            if (is_array($item) && !empty($item['rewrite_json'])) {
                $decoded = json_decode($item['rewrite_json'], true);
                if (is_array($decoded)) {
                    $rewrite = $decoded;
                }
            }
            if (!$rewrite) {
                $rewrite = array(
                    'title' => $post->post_title,
                    'category' => '',
                    'tags' => wp_get_post_tags($post->ID, array('fields' => 'names')),
                );
            }
            $this->prepare_homepage_visibility((int) $post->ID, is_array($item) ? $item : array(), $rewrite, $this->settings_for_item(is_array($item) ? $item : array(), $s));
            $count++;
            if ($count === 1 || $count % 10 === 0) {
                $this->note_background_progress(25 + (int) (70 * $count / max(1, count($posts))), 'Repairing homepage post ' . $count);
            }
        }
        return $count;
    }

    private function prepare_homepage_visibility($post_id, $item, $rewrite, $s = null) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }
        wp_update_post(array(
            'ID' => $post_id,
            'post_author' => $this->publishing_author_id(),
        ));
        $this->assign_home_categories($post_id, $rewrite, $s, $item);
        if (!wp_get_post_tags($post_id)) {
            $tags = $this->resolve_post_tags($rewrite, is_array($item) ? $item : array());
            if ($tags) {
                wp_set_post_tags($post_id, $tags, false);
            }
        }
        $this->maybe_set_featured_image($post_id, is_array($item) ? $item : array(), $rewrite);
        $show_home = !empty($s['pin_to_homepage']) && $this->post_has_featured_image($post_id);
        update_post_meta($post_id, '_tr_ai_wire_show_on_home', $show_home ? '1' : '0');
        if ($show_home && get_post_status($post_id) === 'publish' && get_post_type($post_id) === 'post') {
            stick_post($post_id);
        } else {
            unstick_post($post_id);
        }
        $this->clear_homepage_excluded_cache();
        $this->refresh_front_page($post_id);
    }

    public function exclude_from_homepage($query) {
        if (is_admin() || !($query instanceof WP_Query) || $query->get('tr_ai_wire_include_home')) {
            return;
        }
        if ($query->is_singular() || $query->is_feed() || $query->is_search()) {
            return;
        }
        if ($query->get('p') || $query->get('name') || $query->get('post__in') || $query->get('meta_key') || $query->get('meta_query')) {
            return;
        }
        if (!$query->is_home() && !$query->is_front_page()) {
            return;
        }
        $type = $query->get('post_type');
        if ($type && $type !== 'post' && $type !== 'any' && !(is_array($type) && in_array('post', $type, true))) {
            return;
        }
        $excluded = $this->homepage_excluded_ids();
        if (!$excluded) {
            return;
        }
        $not_in = array_merge(array_map('intval', (array) $query->get('post__not_in')), $excluded);
        $query->set('post__not_in', array_values(array_unique(array_filter($not_in))));
    }

    private function homepage_excluded_ids() {
        $cached = get_transient('tr_ai_wire_home_excluded_ids');
        if (is_array($cached)) {
            return array_values(array_filter(array_map('intval', $cached)));
        }
        $found = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 300,
            'fields' => 'ids',
            'meta_key' => '_tr_ai_wire_show_on_home',
            'meta_value' => '0',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tr_ai_wire_include_home' => true,
        ));
        $ids = array_values(array_filter(array_map('intval', (array) $found)));
        set_transient('tr_ai_wire_home_excluded_ids', $ids, 10 * MINUTE_IN_SECONDS);
        return $ids;
    }

    private function clear_homepage_excluded_cache() {
        delete_transient('tr_ai_wire_home_excluded_ids');
    }

    private function source_image_url($html) {
        $html = (string) $html;
        foreach (array(
            '/<img[^>]+src=["\'](https?:\/\/[^"\']+)/i',
            '/<img[^>]+data-src=["\'](https?:\/\/[^"\']+)/i',
            '/property=["\']og:image["\'][^>]+content=["\'](https?:\/\/[^"\']+)/i',
            '/content=["\'](https?:\/\/[^"\']+)["\'][^>]+property=["\']og:image["\']/i',
            '/<media:content[^>]+url=["\'](https?:\/\/[^"\']+)/i',
            '/<media:thumbnail[^>]+url=["\'](https?:\/\/[^"\']+)/i',
        ) as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                return esc_url_raw($match[1]);
            }
        }
        return '';
    }

    private $reading_thumbnail_meta = false;

    private function maybe_set_featured_image($post_id, $item, $rewrite) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }
        if ($this->real_thumbnail_id($post_id) > 0) {
            return;
        }
        $url = $this->source_image_url($item['source_body'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return;
        }
        $alt = sanitize_text_field($rewrite['featured_image_alt'] ?? ($rewrite['title'] ?? ''));
        update_post_meta($post_id, '_tr_ai_wire_source_image', $url);
        if ($alt !== '') {
            update_post_meta($post_id, '_tr_ai_wire_featured_image_alt', $alt);
        }
        update_post_meta($post_id, 'rank_math_facebook_image', $url);
        update_post_meta($post_id, 'rank_math_twitter_image', $url);
        update_post_meta($post_id, '_yoast_wpseo_opengraph-image', $url);
        update_post_meta($post_id, '_yoast_wpseo_twitter-image', $url);
        update_post_meta($post_id, '_aioseo_og_image', $url);
    }

    private function upstream_image_url($post_id) {
        $url = esc_url_raw((string) get_post_meta((int) $post_id, '_tr_ai_wire_source_image', true));
        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    private function display_image_url($post_id) {
        $url = $this->upstream_image_url($post_id);
        if ($url === '') {
            return '';
        }
        if (function_exists('jetpack_photon_url')) {
            $url = jetpack_photon_url($url);
        }
        return $url;
    }

    private function virtual_thumbnail_id($post_id) {
        $post_id = (int) $post_id;
        return $post_id > 0 ? -(1000000 + $post_id) : 0;
    }

    private function post_id_from_virtual_thumb($thumb_id) {
        $thumb_id = (int) $thumb_id;
        if ($thumb_id >= -1000000) {
            return 0;
        }
        return abs($thumb_id) - 1000000;
    }

    private function real_thumbnail_id($post_id) {
        $this->reading_thumbnail_meta = true;
        $thumb = (int) get_post_meta((int) $post_id, '_thumbnail_id', true);
        $this->reading_thumbnail_meta = false;
        return $thumb > 0 && get_post($thumb) ? $thumb : 0;
    }

    private function post_id_from($post) {
        if ($post instanceof WP_Post) {
            return (int) $post->ID;
        }
        if (is_numeric($post) && (int) $post > 0) {
            return (int) $post;
        }
        $resolved = get_post($post);
        return $resolved instanceof WP_Post ? (int) $resolved->ID : 0;
    }

    public function filter_thumbnail_id_meta($value, $object_id, $meta_key, $single) {
        if ($meta_key !== '_thumbnail_id' || $this->reading_thumbnail_meta) {
            return $value;
        }
        if ($this->real_thumbnail_id($object_id) > 0 || $this->upstream_image_url($object_id) === '') {
            return $value;
        }
        $virtual = $this->virtual_thumbnail_id($object_id);
        return $single ? $virtual : array($virtual);
    }

    public function filter_has_post_thumbnail($has, $post, $thumbnail_id = 0) {
        if ($has) {
            return $has;
        }
        return $this->upstream_image_url($this->post_id_from($post)) !== '';
    }

    public function filter_post_thumbnail_html($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if ($html !== '') {
            return $html;
        }
        $url = $this->display_image_url($post_id);
        if ($url === '') {
            return $html;
        }
        $alt = sanitize_text_field((string) get_post_meta((int) $post_id, '_tr_ai_wire_featured_image_alt', true));
        if ($alt === '') {
            $alt = get_the_title($post_id);
        }
        $attr = is_array($attr) ? $attr : array();
        $class = isset($attr['class']) ? $attr['class'] : 'attachment-post-thumbnail size-post-thumbnail wp-post-image';
        return '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" class="' . esc_attr($class) . '" />';
    }

    public function filter_post_thumbnail_url($url, $post, $size) {
        if ($url) {
            return $url;
        }
        return $this->display_image_url($this->post_id_from($post));
    }

    public function filter_image_downsize($out, $id, $size) {
        if ($out) {
            return $out;
        }
        $post_id = $this->post_id_from_virtual_thumb($id);
        $url = $post_id > 0 ? $this->display_image_url($post_id) : '';
        if ($url === '') {
            return $out;
        }
        return array($url, 0, 0, false);
    }

    public function filter_attachment_url($url, $attachment_id) {
        if ($url) {
            return $url;
        }
        $post_id = $this->post_id_from_virtual_thumb($attachment_id);
        return $post_id > 0 ? $this->display_image_url($post_id) : $url;
    }

    private function post_has_featured_image($post_id) {
        if ($this->upstream_image_url($post_id) !== '') {
            return true;
        }
        return $this->real_thumbnail_id($post_id) > 0;
    }

    private function skip_item_without_image($id, $reason = 'No source image; skipped.') {
        $this->quarantine_item((int) $id, $reason, null, array(
            'wp_post_id' => null,
        ));
    }

    private function item_with_source_body($id, $item) {
        $item = is_array($item) ? $item : array();
        if (trim((string) ($item['source_body'] ?? '')) !== '') {
            return $item;
        }
        $stored = $this->get_item((int) $id);
        if (is_array($stored) && !empty($stored['source_body'])) {
            $item['source_body'] = $stored['source_body'];
        }
        return $item;
    }

    private function home_desk_needles() {
        return array(
            'android' => array('android', 'samsung', 'pixel', 'one ui', 'galaxy', 'motorola', ' phone', ' phones'),
            'apple' => array('apple', 'iphone', 'ipad', 'ios', 'macbook', 'icloud', 'macos', 'airpods'),
            'ai' => array(' openai', 'chatgpt', 'gemini', ' llm', 'artificial intelligence', ' ai ', 'machine learning', 'data center'),
            'auto' => array(' tesla', ' ev ', 'electric vehicle', 'automotive'),
            'pc-hardware' => array('nvidia', 'intel', 'amd', 'gpu', 'laptop', 'graphics card', 'pc hardware', ' ryzen', 'vmware'),
            'reviews' => array('review', 'hands-on'),
            'guides' => array('guide', 'how to', 'deal', 'best '),
        );
    }

    private function mappable_post_types() {
        $skip = array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face');
        $out = array();
        foreach (get_post_types(array('show_ui' => true), 'objects') as $name => $obj) {
            if (in_array($name, $skip, true)) {
                continue;
            }
            $out[$name] = $obj->labels->singular_name ? $obj->labels->singular_name : $name;
        }
        if (!isset($out['post'])) {
            $out = array('post' => 'Post') + $out;
        }
        return $out;
    }

    private function mappable_taxonomies() {
        $out = array();
        foreach (get_taxonomies(array('show_ui' => true), 'objects') as $name => $obj) {
            $out[$name] = !empty($obj->labels->name) ? $obj->labels->name : $name;
        }
        if (!isset($out['category'])) {
            $out = array('category' => 'Categories') + $out;
        }
        return $out;
    }

    private function mapped_post_type($s) {
        $types = $this->mappable_post_types();
        $type = sanitize_key($s['map_post_type'] ?? 'post');
        return isset($types[$type]) ? $type : 'post';
    }

    private function mapped_taxonomy($s) {
        $taxes = $this->mappable_taxonomies();
        $taxonomy = sanitize_key($s['map_taxonomy'] ?? 'category');
        return isset($taxes[$taxonomy]) ? $taxonomy : 'category';
    }

    private function mapped_post_status($s) {
        $wanted = $s['map_post_status'] ?? 'auto';
        if (in_array($wanted, array('publish', 'draft', 'pending'), true)) {
            return $wanted;
        }
        return !empty($s['auto_publish']) ? 'publish' : 'draft';
    }

    private function mapped_title($rewrite, $item, $s) {
        if (($s['map_title'] ?? 'rewrite') === 'source') {
            $title = sanitize_text_field($item['source_title'] ?? '');
            if ($title !== '') {
                return $title;
            }
        }
        return sanitize_text_field($rewrite['title'] ?? '');
    }

    private function mapped_post_date($item, $s) {
        if (($s['map_date'] ?? 'now') === 'source') {
            $raw = $item['source_published_at'] ?? '';
            $mysql = $this->normalize_source_date($raw);
            if ($mysql) {
                return $mysql;
            }
        }
        return current_time('mysql');
    }

    private function normalize_source_date($value) {
        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($value);
        if (!$ts) {
            return '';
        }
        return wp_date('Y-m-d H:i:s', $ts);
    }

    private function mapped_content($rewrite, $item, $s) {
        if (($s['map_content'] ?? 'rewrite') === 'source') {
            $body = trim((string) ($item['source_body'] ?? ''));
            if ($body !== '') {
                return wpautop(esc_html($body));
            }
        }
        $lede = sanitize_text_field($rewrite['lede'] ?? '');
        $body = wp_kses_post($rewrite['body_html'] ?? '');
        $content = '<p class="tr-ai-wire-byline"><em>By ' . esc_html(self::site_brand_name()) . ' editorial desk. This is an attributed analysis of the linked source, not original reporting or an independent test.</em></p>';
        $content .= ($lede ? '<p><strong>' . esc_html($lede) . '</strong></p>' : '') . $body;
        $content .= '<p class="tr-ai-wire-source"><strong>Source:</strong> <a href="' . esc_url($item['source_url'] ?? '') . '" rel="nofollow noopener" target="_blank">' . esc_html($item['feed_name'] ?? 'Source') . '</a></p>';
        $content .= '<p class="tr-ai-wire-method">How we used the source: facts, quotes, and numbers below come only from that link. We added structure, search intent, and reader context. We did not independently verify the reporting.</p>';
        return $content;
    }

    private function generic_category_names() {
        return array('news', 'technology', 'tech', 'uncategorized', 'general', 'latest');
    }

    private function is_generic_category($name, $slug = '') {
        $name = strtolower(trim((string) $name));
        $slug = strtolower(trim((string) $slug));
        $generic = $this->generic_category_names();
        return in_array($name, $generic, true) || ($slug !== '' && in_array($slug, $generic, true));
    }

    private function site_category_names() {
        $names = array();
        foreach (get_categories(array('hide_empty' => false, 'number' => 200)) as $cat) {
            if ($this->is_generic_category($cat->name, $cat->slug)) {
                continue;
            }
            $names[] = $cat->name;
        }
        return $names;
    }

    private function site_tag_names($limit = 40) {
        $tags = get_tags(array(
            'hide_empty' => false,
            'number' => max(1, (int) $limit),
            'orderby' => 'count',
            'order' => 'DESC',
        ));
        if (is_wp_error($tags) || empty($tags)) {
            return array();
        }
        $names = array();
        foreach ($tags as $tag) {
            $name = trim((string) $tag->name);
            if ($name !== '' && !$this->is_generic_category($name, $tag->slug)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    private function classification_haystack($rewrite, $item = array()) {
        $body = wp_strip_all_tags((string) ($item['source_body'] ?? ''));
        if (function_exists('mb_substr')) {
            $body = mb_substr($body, 0, 4000);
        } else {
            $body = substr($body, 0, 4000);
        }
        $parts = array(
            $rewrite['category'] ?? '',
            $rewrite['title'] ?? '',
            $rewrite['lede'] ?? '',
            $item['source_title'] ?? '',
            $body,
            implode(' ', (array) ($rewrite['tags'] ?? array())),
        );
        $hay = strtolower(preg_replace('/\s+/', ' ', implode(' ', $parts)));
        return ' ' . trim((string) $hay) . ' ';
    }

    private function text_mentions($hay, $needle) {
        $needle = strtolower(trim((string) $needle));
        if ($needle === '' || strlen($needle) < 2) {
            return false;
        }
        return (bool) preg_match('/(^|[^a-z0-9])' . preg_quote($needle, '/') . '([^a-z0-9]|$)/', $hay);
    }

    private function resolve_post_categories($rewrite, $s = null, $item = array()) {
        return $this->resolve_mapped_term_ids($rewrite, $s, $item);
    }

    private function resolve_mapped_term_ids($rewrite, $s = null, $item = array()) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $taxonomy = $this->mapped_taxonomy($s);
        $ids = array();
        foreach ((array) ($s['map_terms'] ?? array()) as $term_id) {
            $term_id = absint($term_id);
            if ($term_id > 0 && term_exists($term_id, $taxonomy) && !in_array($term_id, $ids, true)) {
                $ids[] = $term_id;
            }
        }
        if (!empty($s['map_auto_desk']) && $taxonomy === 'category') {
            $matched = $this->match_existing_category($rewrite, $item);
            if ($matched > 0 && !in_array($matched, $ids, true)) {
                $ids[] = $matched;
            }
        }
        if ($taxonomy === 'category' && !$ids) {
            $default = (int) get_option('default_category');
            if ($default > 0 && term_exists($default, 'category')) {
                $ids[] = $default;
            }
        }
        return $ids;
    }

    private function assign_home_categories($post_id, $rewrite, $s = null, $item = array()) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $ids = $this->resolve_mapped_term_ids($rewrite, $s, $item);
        $taxonomy = $this->mapped_taxonomy($s);
        if ($ids && taxonomy_exists($taxonomy)) {
            wp_set_object_terms((int) $post_id, $ids, $taxonomy, false);
        }
    }

    private function match_existing_category($rewrite, $item = array()) {
        $wanted = trim(sanitize_text_field($rewrite['category'] ?? ''));
        if ($this->is_generic_category($wanted, sanitize_title($wanted))) {
            $wanted = '';
        }
        $hay = $this->classification_haystack($rewrite, is_array($item) ? $item : array());
        $best = 0;
        $best_hits = 0;
        foreach (get_categories(array('hide_empty' => false, 'number' => 200)) as $cat) {
            if ($this->is_generic_category($cat->name, $cat->slug)) {
                continue;
            }
            $hits = $this->category_match_score($cat, $hay, $wanted);
            if ($hits > $best_hits) {
                $best_hits = $hits;
                $best = (int) $cat->term_id;
            }
        }
        if ($best > 0 && $best_hits > 0) {
            return $best;
        }
        if ($wanted === '') {
            return 0;
        }
        $term = get_term_by('name', $wanted, 'category');
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($wanted), 'category');
        }
        if ($term && !is_wp_error($term) && !$this->is_generic_category($term->name, $term->slug)) {
            return (int) $term->term_id;
        }
        return 0;
    }

    private function category_match_score($cat, $hay, $wanted) {
        $slug = strtolower((string) $cat->slug);
        $name = strtolower((string) $cat->name);
        $hits = 0;
        if ($wanted !== '' && (strcasecmp($wanted, $cat->name) === 0 || strcasecmp(sanitize_title($wanted), $slug) === 0)) {
            $hits += 8;
        }
        $needles = array($name, str_replace('-', ' ', $slug));
        foreach ($this->home_desk_needles() as $desk => $words) {
            if ($slug === $desk || $name === $desk || $slug === str_replace('-', '', $desk) || str_replace('-', ' ', $slug) === str_replace('-', ' ', $desk)) {
                $needles = array_merge($needles, $words);
            }
        }
        foreach (array_unique($needles) as $word) {
            $word = strtolower((string) $word);
            $trimmed = trim($word);
            if ($trimmed === '') {
                continue;
            }
            $matched = ($word !== $trimmed)
                ? strpos($hay, $word) !== false
                : $this->text_mentions($hay, $trimmed);
            if (!$matched && strlen($trimmed) >= 5 && strpos($hay, $trimmed) !== false) {
                $matched = true;
            }
            if ($matched) {
                $hits += strlen($trimmed) >= 5 ? 2 : 1;
            }
        }
        return $hits;
    }

    private function resolve_post_tags($rewrite, $item = array()) {
        $tags = array();
        $seen = array();
        foreach (array_merge((array) ($rewrite['tags'] ?? array()), $this->tags_from_source($rewrite, $item)) as $tag) {
            $tag = sanitize_text_field($tag);
            if ($tag === '' || $this->is_generic_category($tag, sanitize_title($tag))) {
                continue;
            }
            $key = strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $tags[] = $tag;
        }
        return array_slice($tags, 0, 8);
    }

    private function tags_from_source($rewrite, $item = array()) {
        $hay = $this->classification_haystack($rewrite, is_array($item) ? $item : array());
        $tags = array();
        foreach ($this->site_tag_names(80) as $name) {
            if ($this->text_mentions($hay, $name)) {
                $tags[] = $name;
            }
        }
        foreach ($this->home_desk_needles() as $words) {
            foreach ($words as $word) {
                $label = trim((string) $word);
                if (strlen($label) < 4) {
                    continue;
                }
                if ($this->text_mentions($hay, $label) || (strlen($label) >= 5 && strpos($hay, strtolower($label)) !== false)) {
                    $tags[] = ucwords($label);
                }
            }
        }
        return $tags;
    }

    private function publishing_author_id() {
        $users = get_users(array(
            'role' => 'administrator',
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ID',
        ));
        if (!empty($users[0])) {
            return (int) $users[0];
        }
        return max(1, (int) get_current_user_id());
    }

    private function refresh_front_page($post_id) {
        $post_id = (int) $post_id;
        if ($post_id > 0) {
            clean_post_cache($post_id);
        }
        delete_transient('wp_latest_posts');
        wp_cache_delete('wp_get_archives', 'general');
        if (function_exists('wp_cache_post_change')) {
            wp_cache_post_change($post_id);
        }
        if (function_exists('rocket_clean_home')) {
            rocket_clean_home();
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        if (function_exists('litepeed_purge_url')) {
            litepeed_purge_url(home_url('/'));
        }
    }

    private function create_draft($id, $item, $rewrite, $review, $s = null) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $item = $this->item_with_source_body($id, $item);
        $status = $this->mapped_post_status($s);
        $publish = ($status === 'publish');
        // Recover a post created just before a worker crash; never duplicate it.
        $existing_posts = get_posts(array(
            'post_type' => 'any',
            'post_status' => array('draft', 'pending', 'publish', 'private', 'future'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_tr_ai_wire_queue_id',
            'meta_value' => (int) $id,
            'no_found_rows' => true,
        ));
        $rewrite = $this->prepare_rewrite_seo($rewrite);
        $guard = $this->policy_guard_rewrite($rewrite);
        if (is_wp_error($guard)) {
            if ($guard->get_error_code() === 'tr_ai_wire_policy_unavailable') {
                $this->handle_stage_error($id, $guard);
                return;
            }
            $this->quarantine_item($id, $guard->get_error_message(), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
                'review_json' => wp_json_encode($review),
            ));
            return;
        }
        if (!empty($existing_posts)) {
            $existing_id = (int) $existing_posts[0];
            $this->maybe_set_featured_image($existing_id, $item, $rewrite);
            if (!$this->post_has_featured_image($existing_id)) {
                if (get_post_status($existing_id) === 'publish') {
                    $this->prepare_homepage_visibility($existing_id, $item, $rewrite, $s);
                    $this->update_item($id, array('status' => $this->queue_status_for_post($existing_id, $publish), 'wp_post_id' => $existing_id, 'error_text' => 'No source image; kept off the home board.', 'rewrite_json' => wp_json_encode($rewrite)));
                    return;
                }
                wp_delete_post($existing_id, true);
                $this->skip_item_without_image($id);
                return;
            }
            $this->apply_seo_meta($existing_id, $rewrite);
            update_post_meta($existing_id, '_tr_ai_wire_voice', $this->item_is_comic($item) ? 'comic' : 'straight');
            $this->prepare_homepage_visibility($existing_id, $item, $rewrite, $s);
            $this->maybe_promote_post_status($existing_id, $s);
            $this->prepare_homepage_visibility($existing_id, $item, $rewrite, $s);
            $this->update_item($id, array('status' => $this->queue_status_for_post($existing_id, $publish), 'wp_post_id' => $existing_id, 'error_text' => null, 'rewrite_json' => wp_json_encode($rewrite)));
            return;
        }
        $title = $this->mapped_title($rewrite, $item, $s);
        $lede = sanitize_text_field($rewrite['lede'] ?? '');
        $body = wp_kses_post($rewrite['body_html'] ?? '');
        if (!$title || ($body === '' && ($s['map_content'] ?? 'rewrite') === 'rewrite')) {
            $this->quarantine_item($id, 'Rewrite did not include a title and body.', $item);
            return;
        }
        if ($this->source_image_url($item['source_body'] ?? '') === '') {
            $this->skip_item_without_image($id);
            return;
        }
        if ($body === '' && ($s['map_content'] ?? 'rewrite') === 'source' && trim((string) ($item['source_body'] ?? '')) === '') {
            $this->quarantine_item($id, 'Rewrite did not include a title and body.', $item);
            return;
        }
        $content = $this->mapped_content($rewrite, $item, $s);
        $slug = $this->seo_slug($rewrite);
        $now = $this->mapped_post_date($item, $s);
        $insert = array(
            'post_type' => $this->mapped_post_type($s),
            'post_status' => 'draft',
            'post_author' => $this->publishing_author_id(),
            'post_title' => $title,
            'post_name' => $slug,
            'post_date' => $now,
            'post_date_gmt' => get_gmt_from_date($now),
            'post_content' => $content,
            'post_excerpt' => $lede,
            'meta_input' => array(
                '_tr_ai_wire_source_url' => esc_url_raw($item['source_url']),
                '_tr_ai_wire_experiment_id' => sanitize_key($item['experiment_id'] ?? 'editorial-v1'),
                '_tr_ai_wire_variant' => in_array(($item['variant'] ?? 'A'), array('A', 'B'), true) ? $item['variant'] : 'A',
                '_tr_ai_wire_voice' => $this->item_is_comic($item) ? 'comic' : 'straight',
                '_tr_ai_wire_source_guid' => sanitize_text_field($item['source_guid']),
                '_tr_ai_wire_queue_id' => (int) $id,
                '_tr_ai_wire_tenant_id' => $this->tenant_id_for_url($item['feed_url'] ?? ''),
                '_tr_ai_wire_review' => wp_json_encode($review),
            ),
        );
        if ($this->mapped_taxonomy($s) === 'category') {
            $insert['post_category'] = $this->resolve_mapped_term_ids($rewrite, $s, $item);
        }
        $post_id = wp_insert_post($insert, true);
        if (is_wp_error($post_id) || !$post_id) {
            $error = is_wp_error($post_id) ? $post_id->get_error_message() : 'WordPress did not return a post ID.';
            $this->update_item($id, array('status' => 'error', 'error_text' => $error));
            return;
        }
        $tags = $this->resolve_post_tags($rewrite, $item);
        if ($tags) {
            wp_set_post_tags((int) $post_id, $tags, false);
        }
        $this->apply_seo_meta((int) $post_id, $rewrite);
        $this->prepare_homepage_visibility((int) $post_id, $item, $rewrite, $s);
        if (!$this->post_has_featured_image((int) $post_id)) {
            wp_delete_post((int) $post_id, true);
            $this->skip_item_without_image($id);
            return;
        }
        $this->maybe_promote_post_status((int) $post_id, $s);
        $this->prepare_homepage_visibility((int) $post_id, $item, $rewrite, $s);
        $this->update_item($id, array(
            'status' => $this->queue_status_for_post((int) $post_id, $publish),
            'wp_post_id' => (int) $post_id,
            'error_text' => null,
            'rewrite_json' => wp_json_encode($rewrite),
        ));
    }

    /**
     * WordPress posts are always inserted as drafts. Promotion to publish (or
     * pending) happens only after content, metadata, and a permalink exist, so
     * social adapters never see a premature post.
     */
    private function maybe_promote_post_status($post_id, $s) {
        $post_id = (int) $post_id;
        $wanted = $this->mapped_post_status($s);
        if ($post_id <= 0 || !in_array($wanted, array('publish', 'pending'), true)) {
            return;
        }
        if (get_post_status($post_id) === $wanted) {
            return;
        }
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_status' => $wanted,
        ), true);
        if (is_wp_error($result)) {
            return;
        }
    }

    private function queue_status_for_post($post_id, $publish) {
        unset($publish);
        if (get_post_status($post_id) === 'publish') {
            return 'published';
        }
        return 'draft';
    }

    public function print_article_schema() {
        if (!is_singular('post')) {
            return;
        }
        $post_id = get_queried_object_id();
        if (!$post_id || !get_post_meta($post_id, '_tr_ai_wire_queue_id', true)) {
            return;
        }
        $faq = json_decode((string) get_post_meta($post_id, '_tr_ai_wire_faq', true), true);
        $graph = array(
            array(
                '@type' => 'NewsArticle',
                'headline' => get_the_title($post_id),
                'description' => wp_strip_all_tags(get_the_excerpt($post_id)),
                'datePublished' => get_the_date('c', $post_id),
                'dateModified' => get_the_modified_date('c', $post_id),
                'mainEntityOfPage' => get_permalink($post_id),
                'author' => array(
                    '@type' => 'Organization',
                    'name' => self::site_brand_name() . ' editorial desk',
                    'url' => home_url('/'),
                ),
                'publisher' => array(
                    '@type' => 'Organization',
                    'name' => self::site_brand_name(),
                    'url' => home_url('/'),
                ),
            ),
        );
        $image = $this->display_image_url($post_id);
        if ($image !== '') {
            $graph[0]['image'] = array($image);
        }
        if (is_array($faq) && $faq) {
            $questions = array();
            foreach ($faq as $row) {
                if (empty($row['question']) || empty($row['answer'])) {
                    continue;
                }
                $questions[] = array(
                    '@type' => 'Question',
                    'name' => $row['question'],
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text' => $row['answer'],
                    ),
                );
            }
            if ($questions) {
                $graph[] = array(
                    '@type' => 'FAQPage',
                    'mainEntity' => $questions,
                );
            }
        }
        echo '<script type="application/ld+json">' . wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $graph)) . '</script>' . "\n";
    }

    public function track_article_view() {
        if (!is_singular('post')) {
            return;
        }
        $post_id = get_queried_object_id();
        if (get_post_status($post_id) !== 'publish') {
            return;
        }
        $experiment_id = get_post_meta($post_id, '_tr_ai_wire_experiment_id', true);
        $variant = get_post_meta($post_id, '_tr_ai_wire_variant', true);
        if (!$experiment_id || !in_array($variant, array('A', 'B'), true)) {
            return;
        }
        $payload = array(
            'experiment_id' => sanitize_key($experiment_id),
            'variant' => $variant,
            'voice' => $this->post_is_comic($post_id) ? 'comic' : 'straight',
            'article_id' => (string) $post_id,
            'article_title' => get_the_title($post_id),
            'view_token' => hash_hmac('sha256', $post_id . '|' . gmdate('Y-m-d'), wp_salt('auth')),
        );
        $tracking_url = esc_url_raw(rest_url('tr-ai-wire/v1/view'));
        echo "\n<script>\n(function(){var p=" . wp_json_encode($payload) . ";var endpoint=" . wp_json_encode($tracking_url) . ";p.page_location=window.location.href;p.page_title=document.title;var eventData=Object.assign({},p);delete eventData.view_token;var elapsed=0;var visibleSince=null;var sent=false;function startVisible(){if(document.visibilityState==='visible'&&visibleSince===null){visibleSince=Date.now();}}function pauseVisible(){if(visibleSince!==null){elapsed+=Date.now()-visibleSince;visibleSince=null;}}function emit(){if(sent||document.visibilityState!=='visible')return;sent=true;var gaAllowed=true;try{var gaKey='tr_ai_wire_ga_' + p.article_id;if(window.sessionStorage.getItem(gaKey)){gaAllowed=false;}else{window.sessionStorage.setItem(gaKey,'1');}}catch(e){}if(gaAllowed){if(typeof window.gtag==='function'){window.gtag('event','tr_ai_wire_article_view',eventData);}else{window.dataLayer=window.dataLayer||[];window.dataLayer.push(Object.assign({event:'tr_ai_wire_article_view'},eventData));}}var firstPartyAllowed=true;try{var viewKey='tr_ai_wire_view_' + p.article_id;if(window.sessionStorage.getItem(viewKey)){firstPartyAllowed=false;}else{window.sessionStorage.setItem(viewKey,'1');}}catch(e){}if(firstPartyAllowed){var body=JSON.stringify({post_id:parseInt(p.article_id,10),view_token:p.view_token});if(navigator.sendBeacon){navigator.sendBeacon(endpoint,new Blob([body],{type:'application/json'}));}else{fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true,credentials:'same-origin'});}}}function tick(){if(sent)return;startVisible();var total=elapsed+(visibleSince===null?0:Date.now()-visibleSince);if(total>=3000){emit();}}document.addEventListener('visibilitychange',function(){if(document.visibilityState==='visible'){startVisible();tick();}else{pauseVisible();}});startVisible();var timer=window.setInterval(tick,250);window.setTimeout(function(){window.clearInterval(timer);},30000);})();\n</script>\n";
    }

    public function register_tracking_route() {
        register_rest_route('tr-ai-wire/v1', '/view', array(
            'methods' => 'POST',
            'callback' => array($this, 'record_article_view'),
            'permission_callback' => '__return_true',
            'args' => array(
                'post_id' => array('required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'),
                'view_token' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            ),
        ));
    }

    public function record_article_view($request) {
        global $wpdb;
        $post_id = absint($request->get_param('post_id'));
        $expected_token = hash_hmac('sha256', $post_id . '|' . gmdate('Y-m-d'), wp_salt('auth'));
        $provided_token = (string) $request->get_param('view_token');
        if (!$post_id || get_post_status($post_id) !== 'publish' || !get_post_meta($post_id, '_tr_ai_wire_experiment_id', true) || !hash_equals($expected_token, $provided_token)) {
            return new WP_Error('tr_ai_wire_invalid_view', 'Invalid experiment article view.', array('status' => 404));
        }
        // Atomic per-IP/post throttling; only a keyed hash is persisted, never the IP.
        $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $rate_key = hash_hmac('sha256', $post_id . '|' . $remote_addr, wp_salt('auth'));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}tr_ai_wire_view_limits (rate_key, window_started, requests) VALUES (%s, UTC_TIMESTAMP(), 1)
             ON DUPLICATE KEY UPDATE
             requests = IF(window_started < (UTC_TIMESTAMP() - INTERVAL 1 MINUTE), 1, requests + 1),
             window_started = IF(window_started < (UTC_TIMESTAMP() - INTERVAL 1 MINUTE), UTC_TIMESTAMP(), window_started)",
            $rate_key
        ));
        $rate_count = (int) $wpdb->get_var($wpdb->prepare("SELECT requests FROM {$wpdb->prefix}tr_ai_wire_view_limits WHERE rate_key = %s", $rate_key));
        if ($rate_count > 20) {
            return new WP_Error('tr_ai_wire_view_rate_limited', 'Too many view events.', array('status' => 429));
        }
        if (wp_rand(1, 100) === 1) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}tr_ai_wire_view_limits WHERE window_started < (UTC_TIMESTAMP() - INTERVAL 1 DAY)");
        }
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}tr_ai_wire_views (post_id, view_date, views) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1",
            $post_id,
            gmdate('Y-m-d')
        ));
        if ($result === false) {
            return new WP_Error('tr_ai_wire_view_store_failed', 'Could not record article view.', array('status' => 500));
        }
        return rest_ensure_response(array('recorded' => true));
    }
}
