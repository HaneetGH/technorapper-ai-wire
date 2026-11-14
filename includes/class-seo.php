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

    private function rewrite_seo_blockers($rewrite, $s, $review = null) {
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        if ($this->people_are_searching($review)) {
            return array();
        }
        $seo = is_array($rewrite['seo'] ?? null) ? $rewrite['seo'] : $this->analyze_rewrite_seo($rewrite);
        $reasons = array();
        $score = (int) ($seo['seo_score'] ?? 0);
        $min_score = (int) $s['seo_min_score'];
        if ($score < $min_score && !$this->audience_demand_overrides_score($review)) {
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

    private function seo_blockers_are_score_only($reasons) {
        $reasons = array_values(array_filter((array) $reasons, function ($reason) {
            return trim((string) $reason) !== '';
        }));
        if (!$reasons) {
            return false;
        }
        foreach ($reasons as $reason) {
            if (strpos((string) $reason, 'SEO score ') !== 0) {
                return false;
            }
        }
        return true;
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
                if (count($clean) >= 5) {
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

    private function evaluate_pre_publish_gate($rewrite, $review, $item = array(), $s = null, $exclude_post_id = 0) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $rewrite = is_array($rewrite) ? $this->prepare_rewrite_seo($rewrite) : array();
        $review = is_array($review) ? $review : array();
        $item = is_array($item) ? $item : array();
        $title = trim(wp_strip_all_tags((string) ($rewrite['title'] ?? '')));
        $lede = trim(wp_strip_all_tags((string) ($rewrite['lede'] ?? '')));
        $body = (string) ($rewrite['body_html'] ?? '');
        $plain = $this->plain_text($lede . ' ' . $body);
        $meta = trim(sanitize_text_field($rewrite['meta_description'] ?? ''));
        $slug = sanitize_title((string) ($rewrite['slug'] ?? ''));
        $keyphrase = trim(sanitize_text_field($rewrite['focus_keyphrase'] ?? ''));
        $blocking = array();
        $warnings = array();
        $passed = array();
        $recommended = array();

        if ($title === '' || $this->word_count($title) < 3) {
            $blocking[] = 'Title is missing or too vague.';
        } elseif (preg_match('/you won\'t believe|wait for it|this one trick|doctors hate|gone wrong|what happened next|unbelievable|mind-blowing/i', $title)) {
            $blocking[] = 'Title is clickbait.';
        } else {
            $passed[] = 'Title is specific and natural.';
        }
        if ($meta === '' || strlen($meta) < 70) {
            $blocking[] = 'Article is missing a meaningful excerpt/meta description.';
        } else {
            $passed[] = 'Excerpt/meta description is present.';
        }
        if ($keyphrase === '' && !empty($s['seo_require_keyphrase'])) {
            $blocking[] = 'No single primary topic/keyphrase.';
        } elseif ($keyphrase !== '') {
            $passed[] = 'Primary topic is set.';
        }
        if ($slug === '') {
            $blocking[] = 'Slug is missing.';
        } else {
            $passed[] = 'Slug is readable.';
        }
        if (preg_match('/\b(lorem ipsum|todo:|fixme|placeholder text|insert headline here|insert text here)\b|\{\{[^}]+\}\}|\[\s*(?:insert|todo|placeholder)[^\]]*\]/i', $title . ' ' . $plain)) {
            $blocking[] = 'Placeholder text or generation artifacts remain.';
        }
        if (preg_match('/<h[1-3][^>]*>\s*(?:&nbsp;)?\s*<\/h[1-3]>/i', $body)) {
            $blocking[] = 'Empty heading in the article.';
        }
        $source_title = trim(wp_strip_all_tags((string) ($item['source_title'] ?? '')));
        if ($source_title !== '' && $this->titles_are_near_duplicates($title, $source_title)) {
            $blocking[] = 'Title is a near-duplicate of the source headline.';
            $recommended[] = 'Write a different search title that names the product and the change.';
        }
        $duplicate = $this->cannibalization_match($title, (string) ($rewrite['search_intent'] ?? ''), $exclude_post_id);
        $duplicate_note = 'None';
        if ($duplicate) {
            $blocking[] = 'A near-duplicate or same-intent article already exists on this site.';
            $duplicate_note = 'Existing article #' . (int) $duplicate->ID . ' — ' . get_the_title($duplicate) . '. Recommend UPDATE EXISTING ARTICLE.';
            $recommended[] = 'Update the existing article instead of publishing a new URL.';
        } else {
            $passed[] = 'No duplicate or cannibalizing article found.';
        }
        $brand_bleed = preg_match('/\b(it services company|consulting company|ai-generated content farm|content farm)\b/i', $plain);
        if ($brand_bleed) {
            $blocking[] = 'Copy misstates the publication identity.';
        }
        $keyphrase_hits = 0;
        if ($keyphrase !== '') {
            $keyphrase_hits = substr_count(strtolower($title . ' ' . $plain), strtolower($keyphrase));
            if ($keyphrase_hits > 12) {
                $warnings[] = 'Focus keyphrase appears often enough to look stuffed.';
            }
        }
        foreach ($this->string_list($review['blocking_issues'] ?? array()) as $issue) {
            if ($this->pre_publish_issue_is_hard($issue)) {
                $blocking[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }
        foreach ($this->string_list($review['warnings'] ?? array()) as $warning) {
            $warnings[] = $warning;
        }
        foreach ($this->string_list($review['checks_passed'] ?? array()) as $check) {
            $passed[] = $check;
        }
        foreach ($this->string_list($review['recommended_changes'] ?? array()) as $change) {
            $recommended[] = $change;
        }
        $readability = is_array($rewrite['seo']['readability_errors'] ?? null) ? $rewrite['seo']['readability_errors'] : array();
        foreach ($readability as $error) {
            $warnings[] = (string) $error;
        }
        $internal = is_array($rewrite['internal_links'] ?? null) ? $rewrite['internal_links'] : array();
        $internal_added = array();
        foreach ($internal as $link) {
            if (!empty($link['url'])) {
                $internal_added[] = (string) ($link['anchor'] ?? $link['title'] ?? $link['url']) . ' — ' . $link['url'];
            }
        }
        $sources = array();
        foreach ((array) ($rewrite['official_links'] ?? array()) as $link) {
            if (is_array($link) && !empty($link['url'])) {
                $sources[] = (string) $link['url'];
            }
        }
        if ($sources) {
            $passed[] = 'Official links stay on attributed URLs.';
        } else {
            $sources[] = 'Inspired by source only; no extra citations invented.';
        }

        $blocking = array_values(array_unique(array_filter(array_map('strval', $blocking))));
        $warnings = array_values(array_unique(array_filter(array_map('strval', $warnings))));
        $passed = array_values(array_unique(array_filter(array_map('strval', $passed))));
        $recommended = array_values(array_unique(array_filter(array_map('strval', $recommended))));

        $model_status = strtoupper(str_replace(array(' ', '-'), '_', (string) ($review['gate_status'] ?? '')));
        // A bare reviewer gate_status=HOLD must not zero the day when local
        // checks and hard blockers are clear. Soft model nits become warnings.
        $status = 'PUBLISH';
        if ($blocking) {
            $status = 'HOLD';
        } elseif ($warnings || $model_status === 'PUBLISH_WITH_WARNINGS' || $model_status === 'HOLD') {
            $status = 'PUBLISH WITH WARNINGS';
            if ($model_status === 'HOLD' && !$blocking) {
                $warnings[] = 'Reviewer marked HOLD without a hard blocker; publishing with warnings.';
                $warnings = array_values(array_unique(array_filter(array_map('strval', $warnings))));
            }
        }
        $seo_score = isset($review['seo_score']) ? (int) $review['seo_score'] : (int) ($rewrite['seo']['seo_score'] ?? 0);
        $editorial_score = isset($review['editorial_score']) ? (int) $review['editorial_score'] : (int) ($review['score'] ?? 0);
        $reason = trim((string) ($review['final_reason'] ?? ''));
        if ($reason === '') {
            if ($status === 'HOLD') {
                $reason = 'Blocking pre-publish checks failed.';
            } elseif ($status === 'PUBLISH WITH WARNINGS') {
                $reason = 'No blocker; minor issues remain.';
            } else {
                $reason = 'Blocking checks passed.';
            }
        }
        if ($duplicate_note !== 'None' && empty($review['duplicate_cannibalization'])) {
            // keep local note
        } elseif (!empty($review['duplicate_cannibalization'])) {
            $duplicate_note = sanitize_text_field((string) $review['duplicate_cannibalization']);
        }
        if (!empty($s['_admin_force_publish'])) {
            foreach ($blocking as $issue) {
                $warnings[] = $issue;
            }
            $blocking = array();
            $warnings[] = 'Administrator overrode score, story-value, and pre-publish HOLD.';
            $warnings = array_values(array_unique(array_filter(array_map('strval', $warnings))));
            $status = $warnings ? 'PUBLISH WITH WARNINGS' : 'PUBLISH';
            $reason = 'Published by an administrator from the queue.';
        }

        return array(
            'status' => $status,
            'seo_score' => max(0, min(100, $seo_score)),
            'editorial_score' => max(0, min(100, $editorial_score)),
            'checks_passed' => $passed,
            'warnings' => $warnings,
            'blocking_issues' => $blocking,
            'recommended_changes' => $recommended,
            'duplicate_cannibalization' => $duplicate_note,
            'internal_links_added' => $internal_added,
            'sources_verified' => $sources,
            'final_reason' => $reason,
        );
    }

    private function store_pre_publish_gate($post_id, $rewrite, $review, $item, $s) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return array();
        }
        $gate = $this->evaluate_pre_publish_gate($rewrite, is_array($review) ? $review : array(), is_array($item) ? $item : array(), $s, $post_id);
        update_post_meta($post_id, '_tr_ai_wire_prepublish_gate', $gate);
        return $gate;
    }

    private function pre_publish_gate_holds($gate) {
        return is_array($gate) && (($gate['status'] ?? '') === 'HOLD' || !empty($gate['blocking_issues']));
    }

    /**
     * Only hard P0/P1 failures may HOLD auto-publish. Soft style/SEO nits from
     * the reviewer become warnings so a conservative model cannot stop the day.
     */
    private function pre_publish_issue_is_hard($issue) {
        $text = strtolower(trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $issue))));
        if ($text === '') {
            return false;
        }
        // Match a real failure, not a mention. "no policy issues", "unsupported
        // as a style note", and "differs from the source headline" were matching
        // and holding every auto-publish.
        return (bool) preg_match(
            '/\b(?:clickbait|placeholder text|empty heading|near-?duplicate|same-intent article|cannibali[sz]|synonym-?spin of the source|fabricat\w*|invent(?:s|ed)? (?:a |an )?(?:quote|stat|fact|number|link)|contradicts the source|title is missing|title is too vague|unclear title|no primary topic|hate speech|self-harm|misstates the publication)\b/',
            $text
        );
    }

    private function format_pre_publish_gate($gate) {
        $gate = is_array($gate) ? $gate : array();
        $lines = array(
            'Status: ' . (string) ($gate['status'] ?? 'HOLD'),
            'SEO Score: ' . (int) ($gate['seo_score'] ?? 0) . '/100',
            'Editorial Score: ' . (int) ($gate['editorial_score'] ?? 0) . '/100',
            'Checks Passed:',
        );
        foreach ($this->string_list($gate['checks_passed'] ?? array()) as $item) {
            $lines[] = '* ' . $item;
        }
        $lines[] = 'Warnings:';
        $warnings = $this->string_list($gate['warnings'] ?? array());
        $lines[] = $warnings ? '* ' . implode("\n* ", $warnings) : '* None';
        $lines[] = 'Blocking Issues:';
        $blocking = $this->string_list($gate['blocking_issues'] ?? array());
        $lines[] = $blocking ? '* ' . implode("\n* ", $blocking) : '* None';
        $lines[] = 'Recommended Changes:';
        $recommended = $this->string_list($gate['recommended_changes'] ?? array());
        $lines[] = $recommended ? '* ' . implode("\n* ", $recommended) : '* None';
        $lines[] = 'Duplicate/Cannibalization:';
        $lines[] = '* ' . (string) ($gate['duplicate_cannibalization'] ?? 'None');
        $lines[] = 'Internal Links Added:';
        $internal = $this->string_list($gate['internal_links_added'] ?? array());
        $lines[] = $internal ? '* ' . implode("\n* ", $internal) : '* None';
        $lines[] = 'Sources Verified:';
        $sources = $this->string_list($gate['sources_verified'] ?? array());
        $lines[] = $sources ? '* ' . implode("\n* ", $sources) : '* None';
        $lines[] = 'Final Reason:';
        $lines[] = '* ' . (string) ($gate['final_reason'] ?? '');
        return implode("\n", $lines);
    }

    private function cannibalization_match($title, $search_intent, $exclude_post_id = 0) {
        $title = trim(wp_strip_all_tags((string) $title));
        if ($title === '') {
            return null;
        }
        $found = get_posts(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'future'),
            'posts_per_page' => 20,
            'meta_key' => '_tr_ai_wire_queue_id',
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'suppress_filters' => true,
        ));
        foreach ($found as $post) {
            if ($exclude_post_id && (int) $post->ID === (int) $exclude_post_id) {
                continue;
            }
            if (!get_post_meta($post->ID, '_tr_ai_wire_queue_id', true)) {
                continue;
            }
            if ($this->titles_are_near_duplicates($title, $post->post_title)) {
                return $post;
            }
            $existing_intent = (string) get_post_meta($post->ID, '_tr_ai_wire_search_intent', true);
            // Search intents share boilerplate ("what changed and why it matters").
            // Only an almost identical intent is the same story. Titles stay at 82.
            if ($search_intent !== '' && $existing_intent !== '' && $this->titles_are_near_duplicates($search_intent, $existing_intent, 94)) {
                return $post;
            }
        }
        return null;
    }

    private function string_list($value) {
        $out = array();
        foreach ((array) $value as $item) {
            if (is_array($item)) {
                $item = implode(' ', $item);
            }
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }
}
