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
        $this->repair_home_posts();
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-queue&queue_notice=repaired'));
        exit;
    }

    public function repair_home_posts($limit = 80) {
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
        $this->assign_home_categories($post_id, $rewrite, $s);
        $this->maybe_set_featured_image($post_id, is_array($item) ? $item : array(), $rewrite);
        $show_home = !empty($s['pin_to_homepage']);
        update_post_meta($post_id, '_tr_ai_wire_show_on_home', $show_home ? '1' : '0');
        if ($show_home && get_post_status($post_id) === 'publish' && get_post_type($post_id) === 'post') {
            stick_post($post_id);
        } else {
            unstick_post($post_id);
        }
        $this->refresh_front_page($post_id);
    }

    public function exclude_from_homepage($query) {
        if (is_admin() || !($query instanceof WP_Query) || $query->get('tr_ai_wire_include_home')) {
            return;
        }
        if ($query->is_singular() || $query->is_feed() || $query->is_search()) {
            return;
        }
        if ($query->get('p') || $query->get('name') || $query->get('post__in') || $query->get('meta_key')) {
            return;
        }
        $this_query_is_home = $query->is_home() || $query->is_front_page();
        $secondary_home_loop = !$query->is_main_query() && (is_front_page() || is_home());
        if (!$this_query_is_home && !$secondary_home_loop) {
            return;
        }
        $type = $query->get('post_type');
        if ($type && $type !== 'post' && $type !== 'any' && !(is_array($type) && in_array('post', $type, true))) {
            return;
        }
        $existing = $query->get('meta_query');
        if (!is_array($existing)) {
            $existing = array();
        }
        $existing[] = array(
            'relation' => 'OR',
            array(
                'key' => '_tr_ai_wire_show_on_home',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key' => '_tr_ai_wire_show_on_home',
                'value' => '1',
            ),
        );
        $query->set('meta_query', $existing);
    }

    private function source_image_url($html) {
        $html = (string) $html;
        foreach (array(
            '/<img[^>]+src=["\'](https?:\/\/[^"\']+)/i',
            '/<img[^>]+data-src=["\'](https?:\/\/[^"\']+)/i',
            '/property=["\']og:image["\'][^>]+content=["\'](https?:\/\/[^"\']+)/i',
            '/content=["\'](https?:\/\/[^"\']+)["\'][^>]+property=["\']og:image["\']/i',
        ) as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                return esc_url_raw($match[1]);
            }
        }
        return '';
    }

    private function maybe_set_featured_image($post_id, $item, $rewrite) {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || has_post_thumbnail($post_id)) {
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $alt = sanitize_text_field($rewrite['featured_image_alt'] ?? ($rewrite['title'] ?? ''));
        $url = $this->source_image_url($item['source_body'] ?? '');
        $attachment_id = 0;
        if ($url) {
            $sideload = media_sideload_image($url, $post_id, $alt, 'id');
            if (!is_wp_error($sideload) && $sideload) {
                $attachment_id = (int) $sideload;
            }
        }
        if ($attachment_id <= 0) {
            $attachment_id = $this->generate_featured_image($post_id, (string) ($rewrite['title'] ?? ''), $alt);
        }
        if ($attachment_id <= 0) {
            return;
        }
        set_post_thumbnail($post_id, $attachment_id);
        if ($alt !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        }
    }

    private function generate_featured_image($post_id, $title, $alt) {
        $png = $this->featured_image_png((string) $title);
        if ($png === '') {
            return 0;
        }
        $upload = wp_upload_bits('technorapper-' . (int) $post_id . '-' . time() . '.png', null, $png);
        if (!empty($upload['error']) || empty($upload['file'])) {
            return 0;
        }
        $filetype = wp_check_filetype($upload['file'], null);
        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/png',
            'post_title' => $alt !== '' ? $alt : 'TechnoRapper',
            'post_content' => '',
            'post_status' => 'inherit',
        ), $upload['file'], (int) $post_id);
        if (is_wp_error($attachment_id) || !$attachment_id) {
            return 0;
        }
        $meta = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        if (is_array($meta)) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }
        return (int) $attachment_id;
    }

    private function featured_image_png($title) {
        if (function_exists('imagecreatetruecolor')) {
            $width = 1200;
            $height = 630;
            $image = imagecreatetruecolor($width, $height);
            if ($image) {
                $bg = imagecolorallocate($image, 16, 24, 40);
                $accent = imagecolorallocate($image, 56, 189, 248);
                $white = imagecolorallocate($image, 248, 250, 252);
                $muted = imagecolorallocate($image, 148, 163, 184);
                imagefilledrectangle($image, 0, 0, $width, $height, $bg);
                imagefilledrectangle($image, 0, 0, 18, $height, $accent);
                imagestring($image, 5, 56, 72, 'TechnoRapper', $accent);
                $y = 150;
                foreach (explode("\n", wordwrap(wp_strip_all_tags($title), 36)) as $line) {
                    imagestring($image, 5, 56, $y, $line, $white);
                    $y += 36;
                    if ($y > 500) {
                        break;
                    }
                }
                imagestring($image, 3, 56, 560, 'technorapper.com', $muted);
                ob_start();
                $ok = imagepng($image);
                $png = ob_get_clean();
                imagedestroy($image);
                if ($ok && $png !== '') {
                    return $png;
                }
            }
        }
        if (!function_exists('gzcompress')) {
            return '';
        }
        $width = 1200;
        $height = 630;
        $row = "\x00" . str_repeat("\x10\x18\x28", $width);
        $raw = str_repeat($row, $height);
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        return "\x89PNG\r\n\x1a\n" . $this->png_chunk('IHDR', $ihdr) . $this->png_chunk('IDAT', gzcompress($raw, 9)) . $this->png_chunk('IEND', '');
    }

    private function png_chunk($type, $data) {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private function home_desk_needles() {
        return array(
            'android' => array('android', 'samsung', 'pixel', 'one ui', 'galaxy', 'motorola'),
            'apple' => array('apple', 'iphone', 'ipad', 'ios', 'macbook', 'icloud', 'macos', 'airpods'),
            'ai' => array(' openai', 'chatgpt', 'gemini', ' llm', 'artificial intelligence', ' ai '),
            'auto' => array(' tesla', ' ev ', 'electric vehicle', 'automotive'),
            'pc-hardware' => array('nvidia', 'intel', 'amd', 'gpu', 'laptop', 'graphics card', 'pc hardware'),
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
        $content = '<p class="tr-ai-wire-byline"><em>By TechnoRapper editorial desk. This is an attributed analysis of the linked source, not original reporting or an independent test.</em></p>';
        $content .= ($lede ? '<p><strong>' . esc_html($lede) . '</strong></p>' : '') . $body;
        $content .= '<p class="tr-ai-wire-source"><strong>Source:</strong> <a href="' . esc_url($item['source_url'] ?? '') . '" rel="nofollow noopener" target="_blank">' . esc_html($item['feed_name'] ?? 'Source') . '</a></p>';
        $content .= '<p class="tr-ai-wire-method">How we used the source: facts, quotes, and numbers below come only from that link. We added structure, search intent, and reader context. We did not independently verify the reporting.</p>';
        return $content;
    }

    private function resolve_post_categories($rewrite, $s = null) {
        return $this->resolve_mapped_term_ids($rewrite, $s);
    }

    private function resolve_mapped_term_ids($rewrite, $s = null) {
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
            $matched = $this->match_existing_category($rewrite);
            if ($matched > 0 && !in_array($matched, $ids, true)) {
                $ids[] = $matched;
            }
        }
        if ($taxonomy === 'category') {
            $default = (int) get_option('default_category');
            if ($default > 0 && term_exists($default, 'category') && !in_array($default, $ids, true)) {
                $ids[] = $default;
            }
        }
        return $ids;
    }

    private function assign_home_categories($post_id, $rewrite, $s = null) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $ids = $this->resolve_mapped_term_ids($rewrite, $s);
        $taxonomy = $this->mapped_taxonomy($s);
        if ($ids && taxonomy_exists($taxonomy)) {
            wp_set_object_terms((int) $post_id, $ids, $taxonomy, false);
        }
    }

    private function match_existing_category($rewrite) {
        $wanted = trim(sanitize_text_field($rewrite['category'] ?? ''));
        $hay = ' ' . strtolower(trim($wanted . ' ' . ($rewrite['title'] ?? '') . ' ' . implode(' ', (array) ($rewrite['tags'] ?? array())))) . ' ';
        $best = 0;
        $best_hits = 0;
        foreach (get_categories(array('hide_empty' => false, 'number' => 200)) as $cat) {
            $slug = strtolower((string) $cat->slug);
            $name = strtolower((string) $cat->name);
            foreach ($this->home_desk_needles() as $desk => $words) {
                if ($slug !== $desk && $name !== $desk && $slug !== str_replace('-', '', $desk)) {
                    continue;
                }
                $hits = 0;
                foreach ($words as $word) {
                    if (strpos($hay, $word) !== false) {
                        $hits++;
                    }
                }
                if ($hits > $best_hits) {
                    $best_hits = $hits;
                    $best = (int) $cat->term_id;
                }
            }
        }
        if ($best > 0) {
            return $best;
        }
        if ($wanted === '') {
            return 0;
        }
        $term = get_term_by('name', $wanted, 'category');
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($wanted), 'category');
        }
        if ($term && !is_wp_error($term)) {
            return (int) $term->term_id;
        }
        return 0;
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
        if (!empty($existing_posts)) {
            $existing_id = (int) $existing_posts[0];
            if ($publish && in_array(get_post_status($existing_id), array('draft', 'pending', 'future'), true)) {
                wp_update_post(array(
                    'ID' => $existing_id,
                    'post_status' => 'publish',
                ));
            }
            $this->apply_seo_meta($existing_id, $rewrite);
            $this->prepare_homepage_visibility($existing_id, $item, $rewrite, $s);
            $this->update_item($id, array('status' => $this->queue_status_for_post($existing_id, $publish), 'wp_post_id' => $existing_id, 'error_text' => null, 'rewrite_json' => wp_json_encode($rewrite)));
            return;
        }
        $title = $this->mapped_title($rewrite, $item, $s);
        $lede = sanitize_text_field($rewrite['lede'] ?? '');
        $body = wp_kses_post($rewrite['body_html'] ?? '');
        if (!$title || ($body === '' && ($s['map_content'] ?? 'rewrite') === 'rewrite')) {
            $this->update_item($id, array('status' => 'quarantined', 'error_text' => 'Rewrite did not include a title and body.'));
            return;
        }
        if ($body === '' && ($s['map_content'] ?? 'rewrite') === 'source' && trim((string) ($item['source_body'] ?? '')) === '') {
            $this->update_item($id, array('status' => 'quarantined', 'error_text' => 'Rewrite did not include a title and body.'));
            return;
        }
        $content = $this->mapped_content($rewrite, $item, $s);
        $slug = $this->seo_slug($rewrite);
        $now = $this->mapped_post_date($item, $s);
        $insert = array(
            'post_type' => $this->mapped_post_type($s),
            'post_status' => $status,
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
                '_tr_ai_wire_source_guid' => sanitize_text_field($item['source_guid']),
                '_tr_ai_wire_queue_id' => (int) $id,
                '_tr_ai_wire_tenant_id' => $this->tenant_id_for_url($item['feed_url'] ?? ''),
                '_tr_ai_wire_review' => wp_json_encode($review),
            ),
        );
        if ($this->mapped_taxonomy($s) === 'category') {
            $insert['post_category'] = $this->resolve_mapped_term_ids($rewrite, $s);
        }
        $post_id = wp_insert_post($insert, true);
        if (is_wp_error($post_id) || !$post_id) {
            $error = is_wp_error($post_id) ? $post_id->get_error_message() : 'WordPress did not return a post ID.';
            $this->update_item($id, array('status' => 'error', 'error_text' => $error));
            return;
        }
        if (!empty($rewrite['tags']) && is_array($rewrite['tags'])) {
            $tags = array_slice(array_filter(array_map('sanitize_text_field', $rewrite['tags'])), 0, 10);
            if ($tags) {
                wp_set_post_tags((int) $post_id, $tags, false);
            }
        }
        $this->apply_seo_meta((int) $post_id, $rewrite);
        $this->prepare_homepage_visibility((int) $post_id, $item, $rewrite, $s);
        $this->update_item($id, array(
            'status' => $this->queue_status_for_post((int) $post_id, $publish),
            'wp_post_id' => (int) $post_id,
            'error_text' => null,
            'rewrite_json' => wp_json_encode($rewrite),
        ));
    }

    private function queue_status_for_post($post_id, $publish) {
        if ($publish || get_post_status($post_id) === 'publish') {
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
                    'name' => 'TechnoRapper editorial desk',
                    'url' => home_url('/'),
                ),
                'publisher' => array(
                    '@type' => 'Organization',
                    'name' => 'TechnoRapper',
                    'url' => home_url('/'),
                ),
            ),
        );
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
        $limits_table = $wpdb->prefix . 'tr_ai_wire_view_limits';
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$limits_table} (rate_key, window_started, requests) VALUES (%s, UTC_TIMESTAMP(), 1)
             ON DUPLICATE KEY UPDATE
             requests = IF(window_started < (UTC_TIMESTAMP() - INTERVAL 1 MINUTE), 1, requests + 1),
             window_started = IF(window_started < (UTC_TIMESTAMP() - INTERVAL 1 MINUTE), UTC_TIMESTAMP(), window_started)",
            $rate_key
        ));
        $rate_count = (int) $wpdb->get_var($wpdb->prepare("SELECT requests FROM {$limits_table} WHERE rate_key = %s", $rate_key));
        if ($rate_count > 20) {
            return new WP_Error('tr_ai_wire_view_rate_limited', 'Too many view events.', array('status' => 429));
        }
        if (wp_rand(1, 100) === 1) {
            $wpdb->query("DELETE FROM {$limits_table} WHERE window_started < (UTC_TIMESTAMP() - INTERVAL 1 DAY)");
        }
        $table = $wpdb->prefix . self::VIEWS_TABLE_SUFFIX;
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (post_id, view_date, views) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1",
            $post_id,
            gmdate('Y-m-d')
        ));
        if ($result === false) {
            return new WP_Error('tr_ai_wire_view_store_failed', 'Could not record article view.', array('status' => 500));
        }
        return rest_ensure_response(array('recorded' => true));
    }
}
