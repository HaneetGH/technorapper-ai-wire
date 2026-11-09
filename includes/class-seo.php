<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_SEO {
    private function prepare_rewrite_seo($rewrite) {
        if (!is_array($rewrite)) {
            $rewrite = array();
        }
        $title = trim(sanitize_text_field($rewrite['title'] ?? ''));
        $lede = trim(sanitize_text_field($rewrite['lede'] ?? ''));
        $body = (string) ($rewrite['body_html'] ?? '');
        $keyphrase = trim(sanitize_text_field($rewrite['focus_keyphrase'] ?? $rewrite['focus_keyword'] ?? ''));
        if ($keyphrase === '') {
            $keyphrase = $this->derive_focus_keyphrase($title, $rewrite['tags'] ?? array(), $rewrite['category'] ?? '');
        }
        if ($title !== '' && $keyphrase !== '' && stripos($title, $keyphrase) === false) {
            $title = $this->truncate_seo($keyphrase . ': ' . $title, 80);
        }
        if ($lede !== '' && $keyphrase !== '' && stripos($lede, $keyphrase) === false) {
            $lede = $this->truncate_seo($keyphrase . ' — ' . $lede, 220);
        }
        $body = $this->improve_readability_html($body, $keyphrase);
        $related = $this->related_site_posts($title !== '' ? $title : ($rewrite['focus_keyphrase'] ?? ''));
        $rewrite['internal_links'] = $this->normalize_internal_links($rewrite['internal_links'] ?? array(), $related);
        $rewrite['key_takeaways'] = $this->normalize_takeaways($rewrite['key_takeaways'] ?? array(), $lede, $keyphrase);
        $rewrite['faq'] = $this->normalize_faq($rewrite['faq'] ?? array(), $title, $lede, $keyphrase);
        $body = $this->ensure_internal_links($body, $rewrite['internal_links']);
        $body = $this->ensure_ranking_blocks($body, $rewrite['key_takeaways'], $rewrite['faq']);
        $plain = $this->plain_text($lede . ' ' . $body);
        $seo_title = trim(sanitize_text_field($rewrite['seo_title'] ?? ''));
        if ($seo_title === '') {
            $seo_title = $title;
            if (strlen($seo_title) < 30) {
                $seo_title = trim($seo_title . ' | ' . self::site_brand_name());
            }
            $seo_title = $this->truncate_seo($seo_title, 60);
        }
        $meta = trim(sanitize_text_field($rewrite['meta_description'] ?? ''));
        if ($meta === '' || strlen($meta) < 70) {
            $meta = $this->build_meta_description($lede, $plain, $title, $keyphrase);
        } else {
            $meta = $this->truncate_seo($meta, 155);
        }
        $social_title = trim(sanitize_text_field($rewrite['social_title'] ?? ''));
        if ($social_title === '') {
            $social_title = $seo_title !== '' ? $seo_title : $title;
        }
        $social_description = trim(sanitize_text_field($rewrite['social_description'] ?? ''));
        if ($social_description === '') {
            $social_description = $meta;
        }
        $rewrite['title'] = $title;
        $rewrite['lede'] = $lede;
        $rewrite['body_html'] = $body;
        $rewrite['focus_keyphrase'] = $keyphrase;
        $rewrite['seo_title'] = $seo_title;
        $rewrite['meta_description'] = $meta;
        $rewrite['social_title'] = $social_title;
        $rewrite['social_description'] = $social_description;
        $rewrite['slug'] = $this->seo_slug(array_merge($rewrite, array('focus_keyphrase' => $keyphrase, 'title' => $title)));
        $rewrite['search_intent'] = sanitize_text_field($rewrite['search_intent'] ?? $lede);
        $rewrite['featured_image_alt'] = sanitize_text_field($rewrite['featured_image_alt'] ?? $title);
        $rewrite['seo'] = $this->analyze_rewrite_seo($rewrite);
        return $rewrite;
    }

    private function derive_focus_keyphrase($title, $tags, $category) {
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                $tag = trim(sanitize_text_field((string) $tag));
                if ($tag !== '') {
                    return $this->truncate_seo($tag, 60);
                }
            }
        }
        $stop = array('a', 'an', 'the', 'and', 'or', 'of', 'for', 'to', 'in', 'on', 'at', 'by', 'with', 'from', 'is', 'are', 'as', 'how', 'why', 'what', 'when');
        $words = preg_split('/\s+/', trim((string) $title), -1, PREG_SPLIT_NO_EMPTY);
        $kept = array();
        foreach ($words as $word) {
            $clean = strtolower(preg_replace('/[^a-z0-9\'-]/i', '', $word));
            if ($clean === '' || in_array($clean, $stop, true)) {
                continue;
            }
            $kept[] = $word;
            if (count($kept) >= 3) {
                break;
            }
        }
        if ($kept) {
            return implode(' ', $kept);
        }
        $category = trim(sanitize_text_field((string) $category));
        if ($category !== '') {
            return $this->truncate_seo($category, 60);
        }
        return $this->truncate_seo((string) $title, 60);
    }

    private function improve_readability_html($html, $keyphrase) {
        $html = trim((string) $html);
        if ($html === '') {
            return $html;
        }
        $html = preg_replace_callback('/<p\b[^>]*>(.*?)<\/p>/is', function ($match) {
            $text = $this->plain_text($match[1]);
            if ($this->word_count($text) <= 120) {
                return $match[0];
            }
            $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            $chunks = array();
            $current = '';
            foreach ($sentences as $sentence) {
                $candidate = trim($current . ' ' . $sentence);
                if ($current !== '' && $this->word_count($candidate) > 80) {
                    $chunks[] = '<p>' . esc_html(trim($current)) . '</p>';
                    $current = $sentence;
                } else {
                    $current = $candidate;
                }
            }
            if (trim($current) !== '') {
                $chunks[] = '<p>' . esc_html(trim($current)) . '</p>';
            }
            return $chunks ? implode('', $chunks) : $match[0];
        }, $html);
        if (!preg_match('/<h[2-3][\s>]/i', $html)) {
            $heading = $keyphrase !== '' ? $this->truncate_seo($keyphrase, 70) : 'What changed';
            $html = '<h2>' . esc_html($heading) . '</h2>' . $html . '<h2>What to watch next</h2><p>Meanwhile, readers should watch official follow-up from the attributed source linked below.</p>';
        }
        return $html;
    }

    private function build_meta_description($lede, $plain, $title, $keyphrase) {
        $parts = array();
        if ($lede !== '') {
            $parts[] = $lede;
        }
        if ($plain !== '' && stripos(implode(' ', $parts), $plain) === false) {
            $parts[] = $plain;
        }
        $text = trim(implode(' ', $parts));
        if ($text === '') {
            $text = $title;
        }
        if ($keyphrase !== '' && stripos($text, $keyphrase) === false) {
            $text = $keyphrase . ': ' . $text;
        }
        if (strlen($text) < 70 && $title !== '' && stripos($text, $title) === false) {
            $text .= ' ' . $title;
        }
        if (strlen($text) < 70) {
            $text .= ' Read the attributed Technorapper article for source-linked context.';
        }
        return $this->truncate_seo($text, 155);
    }

    private function analyze_rewrite_seo($rewrite) {
        $title = (string) ($rewrite['title'] ?? '');
        $lede = (string) ($rewrite['lede'] ?? '');
        $body = (string) ($rewrite['body_html'] ?? '');
        $keyphrase = trim((string) ($rewrite['focus_keyphrase'] ?? ''));
        $seo_title = trim((string) ($rewrite['seo_title'] ?? ''));
        $meta = trim((string) ($rewrite['meta_description'] ?? ''));
        $social_title = trim((string) ($rewrite['social_title'] ?? ''));
        $social_description = trim((string) ($rewrite['social_description'] ?? ''));
        $plain = $this->plain_text($lede . ' ' . $body);
        $readability_errors = $this->readability_errors($body, $plain);
        $has_keyphrase = $keyphrase !== '';
        $has_social = $social_title !== '' && $social_description !== '';
        $has_takeaways = is_array($rewrite['key_takeaways'] ?? null) && count($rewrite['key_takeaways']) >= 3;
        $has_faq = is_array($rewrite['faq'] ?? null) && count($rewrite['faq']) >= 3;
        $score = 0;
        if ($has_keyphrase) {
            $score += 15;
        }
        if ($has_keyphrase && stripos($title, $keyphrase) !== false) {
            $score += 15;
        }
        if ($has_keyphrase && stripos($plain, $keyphrase) !== false) {
            $score += 15;
        }
        if (strlen($meta) >= 70) {
            $score += 15;
        }
        if ($has_social) {
            $score += 10;
        }
        if (preg_match('/<h[2-3][\s>]/i', $body)) {
            $score += 10;
        }
        if ($seo_title !== '' && strlen($seo_title) >= 30 && strlen($seo_title) <= 70) {
            $score += 10;
        }
        if ($has_takeaways) {
            $score += 5;
        }
        if ($has_faq) {
            $score += 5;
        }
        if (count($readability_errors) <= 5) {
            $score += 10;
        }
        $score = min(100, $score);
        $visibility = 'Good';
        if (!$has_keyphrase || !$has_social || $score < 50) {
            $visibility = 'Needs work';
        } elseif ($score < 70) {
            $visibility = 'OK';
        }
        $error_count = count($readability_errors);
        return array(
            'visibility' => $visibility,
            'visibility_label' => 'Visibility: ' . $visibility . '!',
            'seo_score' => $score,
            'seo_analysis_label' => 'SEO Analysis: ' . $score . '/100',
            'readability_errors' => $readability_errors,
            'readability_label' => $error_count === 0
                ? 'Readability: No errors found!'
                : 'Readability: ' . $error_count . ' error' . ($error_count === 1 ? '' : 's') . ' found!',
            'has_keyphrase' => $has_keyphrase,
            'keyphrase_label' => $has_keyphrase ? 'Focus Keyphrase: ' . $keyphrase : 'Focus Keyphrase: No focus keyphrase!',
            'has_social' => $has_social,
            'social_label' => $has_social ? 'Social: Social markup ready!' : 'Social: Missing social markup!',
        );
    }

    private function readability_errors($body, $plain) {
        $errors = array();
        if ($this->word_count($plain) < 120) {
            $errors[] = 'Content is shorter than 120 words';
        }
        if (!preg_match('/<h[2-3][\s>]/i', (string) $body)) {
            $errors[] = 'No subheadings found';
        }
        if (preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', (string) $body, $matches)) {
            foreach ($matches[1] as $paragraph) {
                if ($this->word_count($this->plain_text($paragraph)) > 120) {
                    $errors[] = 'A paragraph is longer than 120 words';
                    break;
                }
            }
        }
        $sentences = preg_split('/(?<=[.!?])\s+/', (string) $plain, -1, PREG_SPLIT_NO_EMPTY);
        $long = 0;
        $starts = array();
        $passive = 0;
        foreach ($sentences as $sentence) {
            if ($this->word_count($sentence) > 35) {
                $long++;
            }
            if (preg_match('/^\s*(\w+)/', $sentence, $match)) {
                $starts[] = strtolower($match[1]);
            }
            if (preg_match('/\b(is|are|was|were|be|been|being)\s+\w+ed\b/i', $sentence)) {
                $passive++;
            }
        }
        if ($long > 0) {
            $errors[] = $long . ' sentence' . ($long === 1 ? '' : 's') . ' longer than 35 words';
        }
        for ($i = 1, $count = count($starts); $i < $count; $i++) {
            if ($starts[$i] === $starts[$i - 1]) {
                $errors[] = 'Consecutive sentences start with the same word';
                break;
            }
        }
        if ($passive >= 2) {
            $errors[] = 'Passive voice appears in multiple sentences';
        }
        if ($this->word_count($plain) >= 80 && !preg_match('/\b(however|therefore|because|meanwhile|also|next|then|still|instead|for example|in addition|as a result)\b/i', (string) $plain)) {
            $errors[] = 'Few or no transition words';
        }
        return array_slice($errors, 0, 8);
    }

    private function rewrite_seo_blockers($rewrite, $s) {
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        $seo = is_array($rewrite['seo'] ?? null) ? $rewrite['seo'] : $this->analyze_rewrite_seo($rewrite);
        $reasons = array();
        $score = (int) ($seo['seo_score'] ?? 0);
        $min_score = (int) $s['seo_min_score'];
        if ($score < $min_score) {
            $reasons[] = 'SEO score ' . $score . ' < ' . $min_score;
        }
        if (!empty($s['seo_require_keyphrase']) && empty($seo['has_keyphrase'])) {
            $reasons[] = 'no focus keyphrase';
        }
        if (!empty($s['seo_require_social']) && empty($seo['has_social'])) {
            $reasons[] = 'missing social markup';
        }
        return $reasons;
    }

    private function apply_seo_meta($post_id, $rewrite) {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !is_array($rewrite)) {
            return;
        }
        $keyphrase = sanitize_text_field($rewrite['focus_keyphrase'] ?? '');
        $seo_title = sanitize_text_field($rewrite['seo_title'] ?? '');
        $meta = sanitize_text_field($rewrite['meta_description'] ?? '');
        $social_title = sanitize_text_field($rewrite['social_title'] ?? $seo_title);
        $social_description = sanitize_text_field($rewrite['social_description'] ?? $meta);
        $pairs = array(
            '_tr_ai_wire_focus_keyphrase' => $keyphrase,
            '_tr_ai_wire_slug' => sanitize_title($rewrite['slug'] ?? ''),
            '_tr_ai_wire_search_intent' => sanitize_text_field($rewrite['search_intent'] ?? ''),
            '_tr_ai_wire_featured_image_alt' => sanitize_text_field($rewrite['featured_image_alt'] ?? ''),
            '_tr_ai_wire_faq' => wp_json_encode(is_array($rewrite['faq'] ?? null) ? $rewrite['faq'] : array()),
            '_tr_ai_wire_takeaways' => wp_json_encode(is_array($rewrite['key_takeaways'] ?? null) ? $rewrite['key_takeaways'] : array()),
            '_tr_ai_wire_seo' => wp_json_encode(is_array($rewrite['seo'] ?? null) ? $rewrite['seo'] : array()),
            'rank_math_robots' => array('index', 'follow'),
            '_yoast_wpseo_meta-robots-noindex' => '0',
            'rank_math_focus_keyword' => $keyphrase,
            'rank_math_title' => $seo_title,
            'rank_math_description' => $meta,
            'rank_math_facebook_title' => $social_title,
            'rank_math_facebook_description' => $social_description,
            'rank_math_twitter_title' => $social_title,
            'rank_math_twitter_description' => $social_description,
            '_yoast_wpseo_focuskw' => $keyphrase,
            '_yoast_wpseo_title' => $seo_title,
            '_yoast_wpseo_metadesc' => $meta,
            '_yoast_wpseo_opengraph-title' => $social_title,
            '_yoast_wpseo_opengraph-description' => $social_description,
            '_yoast_wpseo_twitter-title' => $social_title,
            '_yoast_wpseo_twitter-description' => $social_description,
            '_aioseo_title' => $seo_title,
            '_aioseo_description' => $meta,
            '_aioseo_og_title' => $social_title,
            '_aioseo_og_description' => $social_description,
            '_aioseo_twitter_title' => $social_title,
            '_aioseo_twitter_description' => $social_description,
        );
        foreach ($pairs as $key => $value) {
            if ($value === '' || $value === '[]') {
                continue;
            }
            update_post_meta($post_id, $key, $value);
        }
        if ($meta !== '') {
            wp_update_post(array(
                'ID' => $post_id,
                'post_excerpt' => $meta,
            ));
        }
    }

    private function plain_text($html) {
        $text = wp_strip_all_tags(str_ireplace(array('</p>', '</h2>', '</h3>', '<br>', '<br/>', '<br />'), ' ', (string) $html));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function word_count($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return 0;
        }
        return count(preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY));
    }

    private function truncate_seo($text, $max) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text)));
        $max = (int) $max;
        if ($max < 1 || strlen($text) <= $max) {
            return $text;
        }
        $cut = substr($text, 0, $max);
        if (preg_match('/^(.+)\s\S*$/s', $cut, $match) && strlen($match[1]) >= (int) floor($max * 0.5)) {
            return rtrim($match[1], ' ,.;:-');
        }
        return rtrim($cut, ' ,.;:-');
    }

    private function seo_slug($rewrite) {
        $slug = trim((string) ($rewrite['slug'] ?? ''));
        if ($slug === '') {
            $slug = (string) ($rewrite['focus_keyphrase'] ?? $rewrite['title'] ?? '');
        }
        return sanitize_title($this->truncate_seo($slug, 60));
    }

    private function normalize_internal_links($links, $fallback = array()) {
        $home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $clean = array();
        $seen = array();
        foreach (array($links, $fallback) as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $url = esc_url_raw($link['url'] ?? '');
                $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
                if ($url === '' || $host === '' || $host !== $home || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $anchor = sanitize_text_field($link['anchor'] ?? $link['title'] ?? '');
                if ($anchor === '') {
                    $anchor = $url;
                }
                $clean[] = array(
                    'url' => $url,
                    'anchor' => $anchor,
                    'title' => sanitize_text_field($link['title'] ?? $anchor),
                );
                if (count($clean) >= 3) {
                    return $clean;
                }
            }
        }
        return $clean;
    }

    private function count_internal_links($html) {
        $home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $count = 0;
        if (preg_match_all('/href=["\']([^"\']+)/i', (string) $html, $matches)) {
            foreach ($matches[1] as $href) {
                $host = strtolower((string) wp_parse_url($href, PHP_URL_HOST));
                if ($host !== '' && $host === $home) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function ensure_internal_links($html, $links) {
        $html = (string) $html;
        $links = $this->normalize_internal_links($links);
        if (!$links || $this->count_internal_links($html) >= 2) {
            return $html;
        }
        $items = '';
        foreach ($links as $link) {
            $items .= '<li><a href="' . esc_url($link['url']) . '">' . esc_html($link['anchor']) . '</a></li>';
        }
        return rtrim($html) . '<h2>Related on ' . esc_html(self::site_brand_name()) . '</h2><ul>' . $items . '</ul>';
    }

    private function normalize_takeaways($items, $lede, $keyphrase) {
        $clean = array();
        if (is_array($items)) {
            foreach ($items as $item) {
                $text = sanitize_text_field(is_array($item) ? (string) ($item['text'] ?? $item['takeaway'] ?? '') : (string) $item);
                if ($text !== '') {
                    $clean[] = $text;
                }
            }
        }
        if (count($clean) < 3) {
            $fallback = array_filter(array(
                $keyphrase !== '' ? $this->truncate_seo($keyphrase . ' is the development readers asked about.', 140) : '',
                $this->truncate_seo((string) $lede, 140),
                'Watch official follow-up from the attributed source before treating this as settled.',
            ));
            foreach ($fallback as $text) {
                if ($text !== '' && !in_array($text, $clean, true)) {
                    $clean[] = $text;
                }
                if (count($clean) >= 3) {
                    break;
                }
            }
        }
        if ($keyphrase !== '' && isset($clean[0]) && stripos($clean[0], $keyphrase) === false) {
            $clean[0] = $this->truncate_seo($keyphrase . ': ' . $clean[0], 140);
        }
        return array_slice($clean, 0, 3);
    }

    private function normalize_faq($items, $title, $lede, $keyphrase) {
        $clean = array();
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $question = sanitize_text_field($item['question'] ?? '');
                $answer = sanitize_text_field($item['answer'] ?? '');
                if ($question !== '' && $answer !== '') {
                    $clean[] = array('question' => $question, 'answer' => $answer);
                }
            }
        }
        $topic = $keyphrase !== '' ? $keyphrase : ($title !== '' ? $title : 'this update');
        $fallbacks = array(
            array(
                'question' => 'What is ' . $topic . '?',
                'answer' => $this->truncate_seo($lede !== '' ? $lede : ($title . ' is the attributed development covered in this article.'), 220),
            ),
            array(
                'question' => 'Why does ' . $topic . ' matter?',
                'answer' => 'It matters because it changes what readers should watch next. This article only uses facts from the attributed source.',
            ),
            array(
                'question' => 'What should readers watch next?',
                'answer' => 'Watch official follow-up from the attributed source. This site has not independently verified claims beyond that source.',
            ),
        );
        foreach ($fallbacks as $row) {
            if (count($clean) >= 3) {
                break;
            }
            $clean[] = $row;
        }
        return array_slice($clean, 0, 3);
    }

    private function ensure_ranking_blocks($html, $takeaways, $faq) {
        $html = (string) $html;
        if ($takeaways && stripos($html, 'Key takeaways') === false) {
            $items = '';
            foreach ($takeaways as $takeaway) {
                $items .= '<li>' . esc_html($takeaway) . '</li>';
            }
            $html = '<h2>Key takeaways</h2><ul>' . $items . '</ul>' . $html;
        }
        if ($faq && !preg_match('/frequently asked questions/i', $html)) {
            $blocks = '';
            foreach ($faq as $row) {
                $blocks .= '<h3>' . esc_html($row['question']) . '</h3><p>' . esc_html($row['answer']) . '</p>';
            }
            $html .= '<h2>Frequently asked questions</h2>' . $blocks;
        }
        return $html;
    }
}
