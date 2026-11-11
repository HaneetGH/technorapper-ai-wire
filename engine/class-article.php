<?php
/**
 * Approved-article contract. Destinations (WordPress, webhook, static site,
 * later Ghost) all consume this array. They do not re-run Writer or Reviewer.
 */
class TR_AI_Wire_Engine_Article {
    public static function empty_payload() {
        return array(
            'id' => '',
            'slug' => '',
            'title' => '',
            'lede' => '',
            'body_html' => '',
            'category' => '',
            'tags' => array(),
            'seo_title' => '',
            'meta_description' => '',
            'focus_keyphrase' => '',
            'social_title' => '',
            'social_description' => '',
            'source_url' => '',
            'source_title' => '',
            'featured_image_url' => '',
            'permalink' => '',
            'status' => 'draft',
            'published_at' => '',
            'experiment_id' => '',
            'variant' => '',
            'site_name' => '',
        );
    }

    public static function from_array($input) {
        $base = self::empty_payload();
        if (!is_array($input)) {
            return $base;
        }
        foreach ($base as $key => $default) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            if ($key === 'tags') {
                $tags = array();
                foreach ((array) $input['tags'] as $tag) {
                    $tag = self::plain((string) $tag);
                    if ($tag !== '') {
                        $tags[] = $tag;
                    }
                }
                $base['tags'] = array_values(array_unique($tags));
                continue;
            }
            if ($key === 'status') {
                $status = strtolower(trim((string) $input['status']));
                $base['status'] = in_array($status, array('draft', 'publish'), true) ? $status : 'draft';
                continue;
            }
            if ($key === 'variant') {
                $variant = strtoupper(trim((string) $input['variant']));
                $base['variant'] = in_array($variant, array('A', 'B'), true) ? $variant : '';
                continue;
            }
            if ($key === 'body_html') {
                $base['body_html'] = (string) $input['body_html'];
                continue;
            }
            $base[$key] = self::plain((string) $input[$key]);
        }
        if ($base['slug'] === '') {
            $base['slug'] = self::slug($base['title'] !== '' ? $base['title'] : $base['id']);
        } else {
            $base['slug'] = self::slug($base['slug']);
        }
        if ($base['id'] === '') {
            $base['id'] = $base['slug'] !== '' ? $base['slug'] : substr(sha1($base['source_url'] . '|' . $base['title']), 0, 16);
        }
        if ($base['seo_title'] === '') {
            $base['seo_title'] = $base['title'];
        }
        if ($base['social_title'] === '') {
            $base['social_title'] = $base['seo_title'];
        }
        return $base;
    }

    public static function from_json($json) {
        $decoded = json_decode((string) $json, true);
        return self::from_array(is_array($decoded) ? $decoded : array());
    }

    public static function to_json($article) {
        $payload = self::from_array($article);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '{}';
    }

    public static function slug($value) {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim((string) $value, '-');
        if (strlen($value) > 80) {
            $value = rtrim(substr($value, 0, 80), '-');
        }
        return $value !== '' ? $value : 'article';
    }

    public static function plain($value) {
        $value = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\s+/', ' ', strip_tags($value));
        return trim((string) $value);
    }
}
