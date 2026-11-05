<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Tenants {
    private function tenant_defaults() {
        return array(
            'id' => '',
            'url' => '',
            'name' => '',
            'enabled' => 1,
            'pin_to_homepage' => 1,
            'map_post_type' => 'post',
            'map_taxonomy' => 'category',
            'map_terms' => array(),
            'map_auto_desk' => 1,
            'map_post_status' => 'auto',
            'map_title' => 'rewrite',
            'map_date' => 'now',
            'map_content' => 'rewrite',
        );
    }

    private function tenant_id_for_url($url) {
        return substr(hash('sha256', (string) $url), 0, 12);
    }

    private function tenants_from_feed_lines($feeds, $s = null) {
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        $tenants = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $feeds);
        foreach ((array) $lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $url = esc_url_raw($parts[0], array('http', 'https'));
            if (!$url || !wp_http_validate_url($url)) {
                continue;
            }
            $name = isset($parts[1]) ? sanitize_text_field($parts[1]) : '';
            $tenants[] = $this->sanitize_tenant(array(
                'url' => $url,
                'name' => $name,
                'enabled' => 1,
                'pin_to_homepage' => $s['pin_to_homepage'] ?? 1,
                'map_post_type' => $s['map_post_type'] ?? 'post',
                'map_taxonomy' => $s['map_taxonomy'] ?? 'category',
                'map_terms' => $s['map_terms'] ?? array(),
                'map_auto_desk' => $s['map_auto_desk'] ?? 1,
                'map_post_status' => $s['map_post_status'] ?? 'auto',
                'map_title' => $s['map_title'] ?? 'rewrite',
                'map_date' => $s['map_date'] ?? 'now',
                'map_content' => $s['map_content'] ?? 'rewrite',
            ));
        }
        return array_values(array_filter($tenants));
    }

    private function feeds_from_tenants($tenants) {
        $lines = array();
        foreach ((array) $tenants as $tenant) {
            if (empty($tenant['url'])) {
                continue;
            }
            $line = $tenant['url'];
            if (!empty($tenant['name'])) {
                $line .= ' | ' . $tenant['name'];
            }
            $lines[] = $line;
        }
        return implode("\n", array_unique($lines));
    }

    private function sanitize_tenant($input) {
        $defaults = $this->tenant_defaults();
        $url = esc_url_raw($input['url'] ?? '', array('http', 'https'));
        if (!$url || !wp_http_validate_url($url)) {
            return null;
        }
        $name = sanitize_text_field($input['name'] ?? '');
        if ($name === '') {
            $host = wp_parse_url($url, PHP_URL_HOST);
            $name = is_string($host) ? $host : $url;
        }
        $types = $this->mappable_post_types();
        $type = sanitize_key($input['map_post_type'] ?? $defaults['map_post_type']);
        $taxes = $this->mappable_taxonomies();
        $taxonomy = sanitize_key($input['map_taxonomy'] ?? $defaults['map_taxonomy']);
        $terms = array();
        foreach ((array) ($input['map_terms'] ?? array()) as $term_id) {
            $term_id = absint($term_id);
            if ($term_id > 0 && term_exists($term_id, isset($taxes[$taxonomy]) ? $taxonomy : 'category')) {
                $terms[] = $term_id;
            }
        }
        $statuses = array('auto', 'publish', 'draft', 'pending');
        return array(
            'id' => $this->tenant_id_for_url($url),
            'url' => $url,
            'name' => $name,
            'enabled' => empty($input['enabled']) ? 0 : 1,
            'pin_to_homepage' => empty($input['pin_to_homepage']) ? 0 : 1,
            'map_post_type' => isset($types[$type]) ? $type : $defaults['map_post_type'],
            'map_taxonomy' => isset($taxes[$taxonomy]) ? $taxonomy : $defaults['map_taxonomy'],
            'map_terms' => array_values(array_unique($terms)),
            'map_auto_desk' => empty($input['map_auto_desk']) ? 0 : 1,
            'map_post_status' => in_array($input['map_post_status'] ?? '', $statuses, true) ? $input['map_post_status'] : $defaults['map_post_status'],
            'map_title' => (($input['map_title'] ?? '') === 'source') ? 'source' : 'rewrite',
            'map_date' => (($input['map_date'] ?? '') === 'source') ? 'source' : 'now',
            'map_content' => (($input['map_content'] ?? '') === 'source') ? 'source' : 'rewrite',
        );
    }

    private function sanitize_tenants($rows, $new_tenant = array()) {
        $tenants = array();
        $seen = array();
        foreach ((array) $rows as $row) {
            if (!empty($row['remove'])) {
                continue;
            }
            $tenant = $this->sanitize_tenant($row);
            if (!$tenant || isset($seen[$tenant['url']])) {
                continue;
            }
            $seen[$tenant['url']] = true;
            $tenants[] = $tenant;
        }
        if (is_array($new_tenant) && !empty($new_tenant['url'])) {
            $tenant = $this->sanitize_tenant($new_tenant);
            if ($tenant && empty($seen[$tenant['url']])) {
                $tenants[] = $tenant;
            }
        }
        return $tenants;
    }

    private function enabled_tenants($s) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $tenants = !empty($s['tenants']) && is_array($s['tenants']) ? $s['tenants'] : $this->tenants_from_feed_lines($s['feeds'] ?? '', $s);
        $out = array();
        foreach ($tenants as $tenant) {
            if (!empty($tenant['enabled']) && !empty($tenant['url'])) {
                $out[] = $tenant;
            }
        }
        return $out;
    }

    private function tenant_by_id($id, $s = null) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $id = sanitize_text_field((string) $id);
        if ($id === '') {
            return null;
        }
        foreach ((array) ($s['tenants'] ?? array()) as $tenant) {
            if (($tenant['id'] ?? '') === $id) {
                return $tenant;
            }
        }
        return null;
    }

    private function tenant_for_feed($feed_url, $s = null) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $feed_url = esc_url_raw((string) $feed_url, array('http', 'https'));
        foreach ((array) ($s['tenants'] ?? array()) as $tenant) {
            if (!empty($tenant['url']) && $tenant['url'] === $feed_url) {
                return $tenant;
            }
        }
        foreach ($this->tenants_from_feed_lines($s['feeds'] ?? '', $s) as $tenant) {
            if ($tenant['url'] === $feed_url) {
                return $tenant;
            }
        }
        return null;
    }

    private function settings_for_item($item, $s = null) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $tenant = $this->tenant_for_feed(is_array($item) ? ($item['feed_url'] ?? '') : '', $s);
        if (!$tenant) {
            return $s;
        }
        foreach (array('pin_to_homepage', 'map_post_type', 'map_taxonomy', 'map_terms', 'map_auto_desk', 'map_post_status', 'map_title', 'map_date', 'map_content') as $key) {
            if (array_key_exists($key, $tenant)) {
                $s[$key] = $tenant[$key];
            }
        }
        return $s;
    }

    private function render_tenant_fields($group, $tenant, $is_new = false) {
        $tenant = wp_parse_args(is_array($tenant) ? $tenant : array(), $this->tenant_defaults());
        $base = self::OPTION . '[' . $group . ']';
        $suffix = $is_new ? '-new' : '-' . sanitize_html_class($tenant['id'] !== '' ? $tenant['id'] : (string) wp_unique_id('t'));
        ?>
        <div class="tr-tenant" style="border:1px solid #c3c4c7;padding:12px 14px;background:#fff;max-width:720px;">
            <p><label for="tr-tenant-url<?php echo esc_attr($suffix); ?>"><strong>Feed URL</strong></label><br>
            <input id="tr-tenant-url<?php echo esc_attr($suffix); ?>" class="large-text" type="url" name="<?php echo esc_attr($base); ?>[url]" value="<?php echo esc_attr($tenant['url']); ?>" placeholder="https://example.com/feed.xml"></p>
            <p><label for="tr-tenant-name<?php echo esc_attr($suffix); ?>"><strong>Tenant name</strong></label><br>
            <input id="tr-tenant-name<?php echo esc_attr($suffix); ?>" class="regular-text" type="text" name="<?php echo esc_attr($base); ?>[name]" value="<?php echo esc_attr($tenant['name']); ?>" placeholder="Android Authority"></p>
            <?php if (!$is_new) : ?>
                <p><label><input type="checkbox" name="<?php echo esc_attr($base); ?>[enabled]" value="1" <?php checked(!empty($tenant['enabled'])); ?>> Enabled — fetch and publish this tenant</label></p>
                <p><label><input type="checkbox" name="<?php echo esc_attr($base); ?>[remove]" value="1"> Remove this tenant</label></p>
            <?php else : ?>
                <input type="hidden" name="<?php echo esc_attr($base); ?>[enabled]" value="1">
            <?php endif; ?>
            <p><strong>Show on homepage</strong><br>
            <label><input type="radio" name="<?php echo esc_attr($base); ?>[pin_to_homepage]" value="1" <?php checked(!empty($tenant['pin_to_homepage'])); ?>> On</label>
            &nbsp;
            <label><input type="radio" name="<?php echo esc_attr($base); ?>[pin_to_homepage]" value="0" <?php checked(empty($tenant['pin_to_homepage'])); ?>> Off</label></p>
            <details>
                <summary>Map content for this tenant</summary>
                <p><label>Post type <select name="<?php echo esc_attr($base); ?>[map_post_type]"><?php foreach ($this->mappable_post_types() as $type => $label) : ?><option value="<?php echo esc_attr($type); ?>" <?php selected($tenant['map_post_type'], $type); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></p>
                <p><label>Taxonomy <select name="<?php echo esc_attr($base); ?>[map_taxonomy]"><?php foreach ($this->mappable_taxonomies() as $tax => $label) : ?><option value="<?php echo esc_attr($tax); ?>" <?php selected($tenant['map_taxonomy'], $tax); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></p>
                <div style="max-height:140px;overflow:auto;border:1px solid #dcdcde;padding:8px;margin:8px 0;">
                    <?php
                    $map_terms = array_map('intval', (array) ($tenant['map_terms'] ?? array()));
                    $term_list = get_terms(array('taxonomy' => $tenant['map_taxonomy'] ?: 'category', 'hide_empty' => false, 'number' => 200));
                    if (is_wp_error($term_list) || empty($term_list)) :
                        ?><p class="description">No terms found for this taxonomy yet.</p><?php
                    else :
                        foreach ($term_list as $term) :
                            ?>
                            <label style="display:block;margin:3px 0;"><input type="checkbox" name="<?php echo esc_attr($base); ?>[map_terms][]" value="<?php echo esc_attr((string) $term->term_id); ?>" <?php checked(in_array((int) $term->term_id, $map_terms, true)); ?>> <?php echo esc_html($term->name); ?></label>
                            <?php
                        endforeach;
                    endif;
                    ?>
                </div>
                <p><label><input type="checkbox" name="<?php echo esc_attr($base); ?>[map_auto_desk]" value="1" <?php checked(!empty($tenant['map_auto_desk'])); ?>> Read the source and auto-tag a matching site category</label></p>
                <p><label>Post status <select name="<?php echo esc_attr($base); ?>[map_post_status]"><?php foreach (array('auto' => 'Auto', 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($tenant['map_post_status'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></p>
                <p><label>Title <select name="<?php echo esc_attr($base); ?>[map_title]"><option value="rewrite" <?php selected($tenant['map_title'], 'rewrite'); ?>>Rewrite</option><option value="source" <?php selected($tenant['map_title'], 'source'); ?>>Source</option></select></label>
                &nbsp;<label>Date <select name="<?php echo esc_attr($base); ?>[map_date]"><option value="now" <?php selected($tenant['map_date'], 'now'); ?>>Now</option><option value="source" <?php selected($tenant['map_date'], 'source'); ?>>Source</option></select></label>
                &nbsp;<label>Content <select name="<?php echo esc_attr($base); ?>[map_content]"><option value="rewrite" <?php selected($tenant['map_content'], 'rewrite'); ?>>Rewrite</option><option value="source" <?php selected($tenant['map_content'], 'source'); ?>>Source</option></select></label></p>
            </details>
        </div>
        <?php
    }
}
