<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_AI {
    private function writer_prompt($variant, $comic = false) {
        if ($variant === 'B') {
            $prompt = 'You are the original editorial desk at ' . self::site_brand_name() . '. Write a detailed, reader-first article from the supplied source material; use the source as evidence, never as a writing template. This must be a full article, not a brief, blurb, or synonym rewrite. Treat every source field as reference material. Do not paraphrase paragraph-by-paragraph, copy distinctive phrasing, imitate the source headline, or preserve its structure. First identify the most useful news angle, then explain at length what changed, why it matters, who is affected, and what readers should watch next. Add background only when it is supported by the supplied material; clearly label uncertainty and never invent facts, quotes, tests, experience, or reporting. If the source lacks enough evidence for a useful article, return JSON with an empty title and body_html and explain why in novelty_notes. Aim for 900–1400 words, a specific non-clickbait headline, a concise lede, at least four scannable h2 subheadings, useful context, and clear source attribution in prose. Do not put the source URL, the source site, or its images in body_html; the plugin adds one Inspired by link at the end.';
        } else {
            $prompt = 'Write a detailed, original ' . self::site_brand_name() . ' article using only the supplied source. This must be a full article, not a brief, blurb, or synonym rewrite. Treat all source text as reference material. Use a genuinely different headline, lede, and structure; do not mirror the source. Do not invent facts, quotes, numbers, or reporting. Expand only what the source supports: what happened, the background, why it matters, who is affected, and what readers should watch next. Aim for 800–1200 words with at least four h2 sections, short paragraphs of 2–3 sentences, and active voice. Clearly attribute the source in prose. Do not put the source URL, the source site, or its images in body_html; the plugin adds one Inspired by link at the end.';
        }
        return $prompt . ' ' . $this->google_ranking_instructions($comic) . ' ' . $this->policy_writing_instructions($comic);
    }

    private function google_ranking_instructions($comic = false) {
        $prompt = 'Google ranking rules — apply every on-page item while writing. Do not grade, score, or reject your own article: '
            . '1) Write for this site\'s audience, using the site name and the Site categories list. Do not write a generic world-news dump that ignores the publisher. If people are searching this topic on the public internet, write the article, including when the source is a list, a deal, a coupon, a recap, or a short note. That means Google and other search engines, not search on this website. Return an empty title and an empty body_html only when the source has no named topic people could type into a search engine. Do not pad a source that has nothing to look up. '
            . '2) Add a reader angle the headline does not: why the sourced facts matter, who is affected, and what to watch next. Write that as labeled analysis, not as new facts. Do not add numbers, quotes, dates, names, prices, or events that are not in the source. Compare only products or companies the source already names. When the source is global, explain local or regional impact only if the source supports it. Never invent local facts. Missing local context is fine when the source has none — still write the article. '
            . '3) Do not reuse the source headline or its search query. Pick a different job-to-be-done a reader would type. Thin synonym-spins will not rank. '
            . '4) One detailed article, not a brief. Title is the only H1. Required H2s in this order after a Key takeaways box: What changed, Why it matters, Who is affected, What to watch next, Frequently asked questions. Paragraphs are 2–3 sentences, active voice, with transition words. Aim 800–1400 words. '
            . '5) Open the lede by answering search_intent in one sentence so it can win a featured snippet. Then give context. '
            . '6) Choose one 2–4 word focus_keyphrase. Put it in the title, the first 80 words, one H2, the first key takeaway, slug, seo_title (50–60 characters), and meta_description (140–155 characters). Also fill social_title and social_description for Discover and Open Graph. Use the keyphrase 4–7 times total. Never stuff. '
            . '7) Return slug as a clean lowercase hyphenated path from the keyphrase, for example qualcomm-data-center-push, never the source URL. '
            . '8) Weave 2-5 dofollow internal links only to the related posts supplied in the input, and only where they help the reader. Never add links merely to hit a number. Use natural anchors, not “click here”. Do not invent URLs. Never link to the source article, the source site, or its CDN. If none are supplied, omit internal_links — that is not a failure. '
            . '9) Return key_takeaways as exactly 3 short bullets grounded in the source. The first bullet must contain the focus keyphrase and answer the search intent. '
            . '10) Return faq as exactly 3 question/answer pairs a reader would search (People Also Ask). Answers are 2–3 sentences, grounded, no invention. '
            . '11) Write as the ' . self::site_brand_name() . ' editorial desk. Include one sentence on how the source was used. No first-person “I tested”, no source-brand voice, no invented quotes, tests, or experience. Do not paste the source URL in the article; Inspired by credit is added after the body. '
            . '12) Return official_links as 0–2 http(s) URLs that already appear in the source text (company, regulator, or docs). Never invent URLs. Never return the source article or any URL on the source host. '
            . '13) Return featured_image_alt as a factual caption for a suitable editorial still. Do not claim you created or saw a photo. '
            . '14) Read the source document and classify it yourself. Set category to exactly one name from the supplied Site categories list. Set tags to 3–6 specific names that appear in or clearly describe the source (products, companies, chips, OS). Never use News or Technology when a desk fits. Do not wait for a human to map the feed. Attribute the source once in prose. Label uncertainty. '
            . '15) Return search_intent as the reader job-to-be-done in one sentence. '
            . '16) Title names the product, company, or policy AND what changed. Never clone “X announces Y” from the source headline. The first takeaway must answer search_intent so a stranger understands the news. Why it matters names a reader stake the source supports (device, job, money, privacy, or access). If the source has no such stake, say that as labeled analysis — do not invent one. social_title is a tap-worthy restatement of that same named change, not a synonym of the source headline. '
            . $this->brand_identity_instructions()
            . 'Originality: after reading the source, write from a blank outline. Do not keep the source paragraph order, lede sequence, or example order. search_intent must be a query this site\'s reader would type that is not a synonym of the source headline. Do not reuse 4 or more consecutive source words except proper nouns, product names, official titles, and numbers. At most one short attributed quote. Never invent quotes, statistics, citations, or links. Title is specific and natural, not clickbait, and suitable for Google results without extra branding. Do not keyword-stuff. Prefer updating an existing related post when the input says the same story is already covered. ';
        if (!empty($comic)) {
            $prompt .= 'Comic voice: a light wry aside in the lede or Why it matters is welcome; comedy is not the point. A wry title is fine only if it still names the product and the change — never a punchline-only headline. A dry wink in social_title is fine; the change still has to be obvious. ';
        }
        return $prompt
            . 'Return only JSON with keys title, lede, body_html, angle, category, tags (array), source_attribution, claims (array of concrete facts that appear in the source; do not put analysis here), novelty_notes, focus_keyphrase, seo_title, meta_description, social_title, social_description, slug, search_intent, featured_image_alt, key_takeaways (array of 3 strings), faq (array of {question, answer}), official_links (array of {url, anchor}), internal_links (array of {url, anchor}).';
    }

    private function reviewer_prompt($s) {
        $prompt = 'You are an independent editorial reviewer for ' . self::site_brand_name() . '. You did not write this article and you must not improve or rewrite it as if you were the author. Check each concrete factual claim against the supplied source. Fail closed on facts: if you are uncertain whether a concrete claim is supported, do not pass. Assess factual grounding, hallucinations, policy compliance, grammar, attribution, unsupported claims, source-branding bleed, usefulness, originality of writing, groundedness, depth (it should be a detailed article, not a short brief), and Google-ranking quality: unique search angle vs the source headline, focus keyphrase in title/lede/one H2/slug/first takeaway, Key takeaways (3), FAQ (3 People Also Ask), internal links only when same-site URLs were supplied, reader context the source supports, and an editorial-desk voice (not the source brand). A single dry, family-friendly aside is house style, not a defect — do not lower the score for it, and do not require jokes. Treat crude humor, meme slang, punchline-only headlines, or invented gags as policy flags. Never invent fixes. Invented facts, quotes, tests, experience, URLs, or attributions are reject-level. Also reject copy a family-friendly newsroom would not publish, and list those problems in policy_flags. The article must not present source reporting as original investigation. Body links must not point at the source article, the source site, or its images; one Inspired by credit is added after the article. '
            . 'The site publishes at 85. Instagram and X stills require 90. Use this scale and do not park a publishable article at 78-84: '
            . '90-100 complete original detailed article: unique angle vs the source headline, title names the product/company/policy AND the change, keyphrase, takeaways, FAQ, empty fact_flags, no policy flags, story_value substance solid or major and reader_demand medium or high. Score 90-96 when this checklist is met. 97-100 is rare (major story plus exceptional originality). '
            . '85-89 a human editor would publish to the website, but a named remaining defect keeps it off social: generic or source-mirrored headline, missing ranking item, thin Why it matters, or a restated press blurb. You MUST name that defect in issues and suggested_fixes. '
            . '70-84 usable only after a human rewrites a concrete problem (thin section, unclear attribution, or a real unsupported concrete claim); '
            . '50-69 thin or close paraphrase; '
            . '0-49 reject-level (fabrication, synonym-spin, empty, policy). '
            . 'Do not park a 90-checklist article at 85-89. If issues and suggested_fixes are empty, or only readability/style nits, and the 90 checklist is met, the score MUST be 90 or higher. Do not deduct below 85 for missing internal links when none were supplied, for missing local context when the source has none, for missing jokes, or for labeled analysis (why it matters, who is affected, what to watch next) that adds no new number, quote, date, name, price, or event. Never invent a low score to appear strict. If a human editor would publish it, score 85 or higher with verdict pass. Do not list missing humor as an issue or suggested_fix. '
            . 'fact_flags must be an empty array when every concrete claim appears in the source. Do not flag single-source reporting, lack of independent verification, hedging, readability, or labeled analysis. A non-empty fact_flags array blocks publication, so list only a claim that contradicts the source or is not in the source. Put style and structure notes in issues. '
            . 'Return JSON with score (0-100), verdict (pass/revise/reject), issues (array), fact_flags (array), policy_flags (array), pragmatism {is_new, is_useful, is_grounded, similarity_risk (low/medium/high), notes}, story_value {publish (boolean), substance (thin/solid/major), reader_demand (low/medium/high), adds_value (boolean), reason}, suggested_fixes (array), safe_to_publish (boolean). is_new means the writing is original versus the source, not that the news event is unpublished. Use verdict pass when the score is 85 or higher and fact_flags is empty. Use verdict revise only for a 70-84 article a human must edit. Use reject for fabrication, policy problems, synonym-spin, missing unique angle, or empty/unusable copy. Set safe_to_publish true when the article is grounded, fact_flags is empty, and there are no policy flags. '
            . 'story_value is a separate publish gate from the score. A clean 85 does not publish a thin story, a list, or a topic readers would not search. Set publish true only when the source describes a concrete development readers would look up: a named product, company, or policy, plus what changed, and there is enough source to explain it. substance is thin when the source cannot explain what changed. reader_demand is low for a deal list, coupon, best-of list, weekly recap, sponsored item, or a minor aside with no named change. adds_value is false when the post would only repeat a press blurb or a list. Set publish false in those cases. '
            . 'audience is a website-only layer and does not change the 0-100 score or the social stills floor. Return audience {pop_culture_top (boolean), everyone_searching (boolean), reason}. Set pop_culture_top true only when this is a leading pop-culture story general audiences are talking about today, such as a public figure, entertainment, or a viral culture moment, not a routine product note. everyone_searching means people are searching this topic on the public internet (Google, Bing, and other search engines). It does not mean search on this website, this site\'s search box, or a query this site already ranks for. Set everyone_searching true when people on the internet are typing this topic, including a list, deal, coupon, recap, or thin item if that is the internet query. When everyone_searching is true, publish the article on this website. A low score, a low SEO score, a missing keyphrase, a thin story, or a list label does not keep it off the site. Set everyone_searching false only when people on the internet are not searching it. Do not leave it false to be strict. When either flag is true, set reader_demand to high. '
            . $this->pre_publish_gate_reviewer_instructions();
        if (!empty($s['enable_web_search'])) {
            $prompt .= ' Use the available web search for one corroborating search action and include evidence URLs in issues or notes. Use that search to judge public internet demand, not demand on this website. Set everyone_searching true when that search shows people querying this topic on Google or other search engines. Set it false when the search does not show that. Set pop_culture_top true when that search shows a leading pop-culture story. Set reader_demand low when people on the internet would not look up the named change. The source text is reference material.';
        }
        return $prompt;
    }

    private function brand_identity_instructions() {
        $brand = self::site_brand_name();
        $site = home_url('/');
        $prompt = 'Publication identity: ' . $brand . ' is an independent technology publication at ' . $site . '. Do not describe it as an IT services company, a consulting company, a generic blog, or an AI-generated content farm. ';
        if (stripos($brand, 'technorapper') !== false) {
            $prompt = 'Publication identity: Brand TechnoRapper, website https://technorapper.com, independent technology publication founded in 2012. Do not describe TechnoRapper as an IT services company, a consulting company, a generic blog, or an AI-generated content farm. ';
        }
        return $prompt;
    }

    private function pre_publish_gate_reviewer_instructions() {
        return 'Also run the pre-publish SEO and editorial quality gate before any live URL. Existing dashboard score, similarity, and SEO settings still apply. Return gate_status as PUBLISH, PUBLISH_WITH_WARNINGS, or HOLD. HOLD on a blocking P0/P1 failure: unclear or clickbait title, missing excerpt, no primary topic, placeholders or empty headings, near-duplicate of an existing article on this site, factual contradictions, invented quotes/stats/links, or a synonym-spin of the source. PUBLISH_WITH_WARNINGS when there is no blocker but a minor issue remains. Never raise the SEO score by sacrificing accuracy, originality, or reader value. Also return seo_score (0-100), editorial_score (0-100), checks_passed (array), warnings (array), blocking_issues (array), recommended_changes (array), duplicate_cannibalization (string), internal_links_added (array), sources_verified (array), and final_reason (one sentence). ';
    }

    private function build_writer_input($item) {
        $input = "Feed: {$item['feed_name']}\nSource title: {$item['source_title']}\nSource URL: {$item['source_url']}\nSource text:\n{$item['source_body']}";
        $categories = $this->site_category_names();
        if (is_array($item) && $this->item_desk_lane($item) === 'comics') {
            $input .= "\n\nThis source is a Comics desk feed. Set category to Comics.";
        }
        if ($categories) {
            $input .= "\n\nSite categories — pick exactly one that best fits this source document. Do not ask a human. Never pick News, Tech, Technology, or Uncategorized:\n- " . implode("\n- ", $categories);
        } else {
            $input .= "\n\nSite categories — pick exactly one homepage desk: Android, Apple, AI, Auto, PC Hardware, Reviews, Comics, Guides, or Tech. Never pick News or Uncategorized.";
        }
        $tags = $this->site_tag_names();
        if ($tags) {
            $input .= "\n\nExisting tags you may reuse when they fit. Also add 3–6 specific names from the source:\n- " . implode("\n- ", $tags);
        } else {
            $input .= "\n\nTags — read the source and return 3–6 specific names (products, companies, chips, OS).";
        }
        $related = $this->related_site_posts($item['source_title'] ?? '');
        if ($related) {
            $lines = array();
            foreach ($related as $link) {
                $lines[] = '- ' . $link['title'] . ' | ' . $link['url'];
            }
            $input .= "\n\nRelated posts on this site to link (use only these URLs):\n" . implode("\n", $lines);
        } else {
            $input .= "\n\nRelated posts on this site to link: none yet. Omit internal_links.";
        }
        return $input;
    }

    private function build_review_input($item, $rewrite) {
        $input = "SOURCE TITLE: {$item['source_title']}\nSOURCE URL: {$item['source_url']}\nSOURCE TEXT:\n{$item['source_body']}\n\nPROPOSED ARTICLE JSON:\n" . wp_json_encode($this->public_rewrite_payload($rewrite));
        $related = $this->related_site_posts($rewrite['title'] ?? ($item['source_title'] ?? ''));
        if ($related) {
            $lines = array();
            foreach ($related as $link) {
                $lines[] = '- ' . $link['title'] . ' | ' . $link['url'];
            }
            $input .= "\n\nAllowed internal URLs on this site (" . count($related) . " supplied). Missing other internals is not a scoring defect:\n" . implode("\n", $lines);
        } else {
            $input .= "\n\nAllowed internal URLs on this site: none supplied. Do not lower the score for missing internal links.";
        }
        $input .= "\n\nYou are not the author. Do not rewrite the article. Publish gate: 85. Social stills gate: 90. If every concrete claim is in the source, return fact_flags as []. Labeled analysis is not a fact flag. If a human editor would publish this, score 85 or higher with verdict pass. If the 90 checklist is met, score 90 or higher — do not park it at 85-89. Also return story_value. A high score does not publish a thin story, a deal or best-of list, or a topic readers would not search. Also return audience. everyone_searching means people searching on the public internet, not search on this website. If people are searching for this on the internet, set everyone_searching true so this website posts it even when the score is low, the SEO score is low, the keyphrase is missing, or the item is a list or thin. Social still needs 90. Set everyone_searching false only when people on the internet are not searching it.";
        return $input;
    }

    private function polish_writer_prompt($variant, $comic = false) {
        $desk = ($variant === 'B')
            ? 'Stay the original editorial desk. '
            : '';
        return $desk . 'You are revising a ' . self::site_brand_name() . ' article using only the reviewer suggested_fixes. Keep every concrete fact grounded in the source. Do not invent numbers, quotes, dates, names, prices, or events. Apply the named fixes: a title that names the product, company, or policy AND the change; a unique search angle vs the source headline; complete keyphrase, takeaways, and FAQ; and a Why it matters that uses only sourced reader stakes. If a fix would require inventing a fact, leave that part unchanged. Return a full article JSON. Do not grade or score the article. '
            . $this->google_ranking_instructions($comic) . ' ' . $this->policy_writing_instructions($comic);
    }

    private function build_polish_input($item, $rewrite, $review) {
        $fixes = $review['suggested_fixes'] ?? array();
        $issues = $review['issues'] ?? array();
        $input = $this->build_writer_input($item);
        $input .= "\n\nCurrent article JSON:\n" . wp_json_encode($this->public_rewrite_payload($rewrite));
        $input .= "\n\nReviewer issues:\n" . wp_json_encode($issues);
        $input .= "\n\nSuggested fixes to apply (do not invent facts to satisfy them):\n" . wp_json_encode($fixes);
        return $input;
    }

    private function public_rewrite_payload($rewrite) {
        $out = is_array($rewrite) ? $rewrite : array();
        unset($out['_tr_score_polish'], $out['seo']);
        return $out;
    }

    /**
     * Reviewers cluster complete articles at 85 because the publish gate is 85.
     * Lift 85-89 to 90 only when the social checklist is already met.
     */
    private function normalize_review_score($review, $rewrite = array(), $item = array()) {
        if (!is_array($review)) {
            return $review;
        }
        $score = isset($review['score']) ? (int) $review['score'] : 0;
        if ($score < 85 || $score >= 90) {
            return $review;
        }
        if (strtolower(trim((string) ($review['verdict'] ?? ''))) !== 'pass') {
            return $review;
        }
        if ($this->review_has_social_blocking_defect($review, $rewrite, $item)) {
            return $review;
        }
        $review['score'] = 90;
        if (!isset($review['pragmatism']) || !is_array($review['pragmatism'])) {
            $review['pragmatism'] = array();
        }
        $notes = trim((string) ($review['pragmatism']['notes'] ?? ''));
        $lift = 'Score raised from clustering below 90; the 90 checklist was already met.';
        $review['pragmatism']['notes'] = $notes === '' ? $lift : $notes . ' ' . $lift;
        return $review;
    }

    private function review_has_social_blocking_defect($review, $rewrite = array(), $item = array()) {
        if (!is_array($review)) {
            return true;
        }
        if (!$this->flag_list_is_empty($review['fact_flags'] ?? null) || !$this->flag_list_is_empty($review['policy_flags'] ?? null)) {
            return true;
        }
        if ($this->story_value_model_reason($review['story_value'] ?? null) !== '') {
            return true;
        }
        if ($this->rewrite_missing_ranking_items($rewrite)) {
            return true;
        }
        $title = trim(wp_strip_all_tags((string) ($rewrite['title'] ?? '')));
        $source = trim(wp_strip_all_tags((string) ($item['source_title'] ?? '')));
        if ($this->titles_are_near_duplicates($title, $source)) {
            return true;
        }
        $notes = $this->review_notes_text($review);
        if ($notes === '') {
            return false;
        }
        if (preg_match('/missing (?:a )?(?:keyphrase|takeaway|faq|h2)|source headline|mirrored|synonym-?spin|generic headline|thin section|paraphrase|unsupported claim|press blurb|restated|clone/', $notes)) {
            return true;
        }
        // A leftover note that is not one of those defects is not a reason to
        // park a complete article under 90. Reviewers add harmless asides.
        return false;
    }

    private function rewrite_missing_ranking_items($rewrite) {
        if (!is_array($rewrite)) {
            return true;
        }
        if (trim((string) ($rewrite['focus_keyphrase'] ?? '')) === '') {
            return true;
        }
        $takeaways = $rewrite['key_takeaways'] ?? array();
        $faq = $rewrite['faq'] ?? array();
        if (!is_array($takeaways) || count($takeaways) < 3) {
            return true;
        }
        if (!is_array($faq) || count($faq) < 3) {
            return true;
        }
        return false;
    }

    private function titles_are_near_duplicates($left, $right, $minimum = 82) {
        $a = strtolower(preg_replace('/[^a-z0-9]+/', '', (string) $left));
        $b = strtolower(preg_replace('/[^a-z0-9]+/', '', (string) $right));
        if (strlen($a) < 8 || strlen($b) < 8) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        similar_text($a, $b, $percent);
        $minimum = (float) $minimum;
        if ($minimum < 50) {
            $minimum = 82;
        }
        return $percent >= $minimum;
    }

    private function review_notes_text($review) {
        $parts = array();
        foreach (array('issues', 'suggested_fixes') as $key) {
            foreach ((array) ($review[$key] ?? array()) as $item) {
                if (is_array($item)) {
                    $parts[] = implode(' ', $item);
                } else {
                    $parts[] = (string) $item;
                }
            }
        }
        return strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', $parts))));
    }

    private function rewrite_was_polished($rewrite) {
        return is_array($rewrite) && !empty($rewrite['_tr_score_polish']);
    }

    private function review_should_polish($review, $rewrite) {
        if (!is_array($review) || $this->rewrite_was_polished($rewrite)) {
            return false;
        }
        $score = isset($review['score']) ? (int) $review['score'] : 0;
        if ($score < 85 || $score >= 90) {
            return false;
        }
        if (strtolower(trim((string) ($review['verdict'] ?? ''))) !== 'pass') {
            return false;
        }
        if (!$this->flag_list_is_empty($review['fact_flags'] ?? null) || !$this->flag_list_is_empty($review['policy_flags'] ?? null)) {
            return false;
        }
        if ($this->story_value_model_reason($review['story_value'] ?? null) !== '') {
            return false;
        }
        foreach ((array) ($review['suggested_fixes'] ?? array()) as $fix) {
            if (is_array($fix)) {
                $fix = implode(' ', $fix);
            }
            if (trim((string) $fix) !== '') {
                return true;
            }
        }
        return false;
    }

    private function related_site_posts($hint, $limit = 5) {
        $query = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'suppress_filters' => true,
        );
        $hint = trim(wp_strip_all_tags((string) $hint));
        $posts = array();
        if ($hint !== '') {
            $query['s'] = $hint;
            $posts = get_posts($query);
        }
        if (count($posts) < $limit) {
            unset($query['s']);
            $seen = array();
            foreach ($posts as $post) {
                $seen[(int) $post->ID] = $post;
            }
            foreach (get_posts($query) as $post) {
                $seen[(int) $post->ID] = $post;
                if (count($seen) >= $limit) {
                    break;
                }
            }
            $posts = array_values($seen);
        }
        $out = array();
        foreach ($posts as $post) {
            $url = get_permalink($post);
            if (!$url) {
                continue;
            }
            $out[] = array(
                'url' => $url,
                'title' => get_the_title($post),
                'anchor' => get_the_title($post),
            );
        }
        return $out;
    }

    /**
     * Dashboard settings decide score, verdict, similarity, flag gates and
     * whether the result is a draft or a published post. Reject is always a hard stop.
     */
    private function review_draft_blockers($review, $s) {
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        $reasons = array();
        $verdict = strtolower(trim((string) ($review['verdict'] ?? '')));
        if ($verdict === 'reject') {
            $reasons[] = 'verdict=reject';
        } elseif ($verdict !== 'pass' && (empty($s['draft_allow_revise']) || $verdict !== 'revise')) {
            $reasons[] = 'verdict=' . ($verdict !== '' ? $verdict : 'missing');
        }
        $score = isset($review['score']) ? (int) $review['score'] : 0;
        $min_score = (int) $s['draft_min_score'];
        $demand_post = $this->audience_demand_overrides_score($review);
        $website_pass = $this->review_is_website_pass($review, $s);
        if ($score < $min_score && !$demand_post && !$website_pass) {
            $reasons[] = 'score ' . $score . ' < ' . $min_score;
        }
        $risk = strtolower((string) ($review['pragmatism']['similarity_risk'] ?? 'high'));
        $risk_rank = array('low' => 1, 'medium' => 2, 'high' => 3);
        $item_rank = $risk_rank[$risk] ?? 3;
        $allowed_rank = $risk_rank[$s['draft_max_similarity']] ?? 3;
        if ($item_rank > $allowed_rank) {
            $reasons[] = 'similarity_risk=' . $risk;
        }
        if (!empty($s['draft_require_grounded']) && $this->is_explicitly_false($review['pragmatism']['is_grounded'] ?? null)) {
            $facts_clear = $this->flag_list_is_empty($review['fact_flags'] ?? null);
            $score_clears = $score >= $min_score && ($verdict === 'pass' || (!empty($s['draft_allow_revise']) && $verdict === 'revise'));
            // A pass at the dashboard minimum with an empty fact list is grounded.
            // Models set is_grounded false on labeled analysis and then every post waits for Force publish.
            if (!$facts_clear || !$score_clears) {
                $reasons[] = 'not grounded in the source';
            }
        }
        if (!empty($s['draft_require_useful']) && empty($review['pragmatism']['is_useful'])) {
            $reasons[] = 'not useful';
        }
        if (!empty($s['draft_require_new']) && empty($review['pragmatism']['is_new'])) {
            $reasons[] = 'not original vs source';
        }
        if (!empty($s['draft_block_policy_flags']) && !$this->flag_list_is_empty($review['policy_flags'] ?? null)) {
            $reasons[] = 'policy flags';
        }
        if (!empty($s['draft_block_fact_flags']) && !$this->flag_list_is_empty($review['fact_flags'] ?? null)) {
            $reasons[] = 'fact flags';
        }
        $value = $this->story_value_model_reason($review['story_value'] ?? null);
        $score_clears = $score >= $min_score && ($verdict === 'pass' || (!empty($s['draft_allow_revise']) && $verdict === 'revise'));
        // Story-value is a filter for items under the dashboard minimum.
        // A pass that already cleared that score was being quarantined whenever
        // the reviewer marked ordinary tech news as thin or low-demand, so
        // nothing auto-published and every row needed Force publish.
        // A grounded pass at 70+ is the same case when the model parks it
        // just under an 85 dashboard minimum. An explicit thin or low-demand
        // label still holds that pass. A missing story-value field does not.
        $explicit_thin = $this->story_value_is_explicitly_unworthy($review['story_value'] ?? null);
        if ($website_pass && !$score_clears) {
            if ($explicit_thin && !$this->people_are_searching($review) && !$demand_post) {
                $reasons[] = $value !== '' ? $value : 'Held: not worth publishing.';
            }
        } elseif ($value !== '' && !$score_clears && !$this->people_are_searching($review) && !($demand_post && $this->story_value_is_only_low_demand($review['story_value'] ?? null))) {
            $reasons[] = $value;
        }
        return $reasons;
    }

    /**
     * A grounded pass the reviewer scored at 70 or above. The dashboard
     * minimum (often 85) was turning these into review holds, so the day
     * auto-published nothing. Reject, revise, fact flags, and policy flags
     * stay held. The SEO score floor and the social 90 floor still apply.
     */
    private function review_is_website_pass($review, $s) {
        if (!is_array($review)) {
            return false;
        }
        $verdict = strtolower(trim((string) ($review['verdict'] ?? '')));
        if ($verdict !== 'pass') {
            return false;
        }
        $score = isset($review['score']) ? (int) $review['score'] : 0;
        if ($score < 70) {
            return false;
        }
        if (!$this->flag_list_is_empty($review['policy_flags'] ?? null) || !$this->flag_list_is_empty($review['fact_flags'] ?? null)) {
            return false;
        }
        $pragmatism = is_array($review['pragmatism'] ?? null) ? $review['pragmatism'] : array();
        if ($this->is_explicitly_false($pragmatism['is_grounded'] ?? null)) {
            return false;
        }
        unset($s);
        return true;
    }

    /**
     * The reviewer said this story should not go out. A blank or partial
     * story_value object is not that sentence.
     */
    private function story_value_is_explicitly_unworthy($value) {
        if (!is_array($value) || !$value) {
            return false;
        }
        $substance = strtolower(trim((string) ($value['substance'] ?? '')));
        $demand = strtolower(trim((string) ($value['reader_demand'] ?? '')));
        if ($substance === 'thin' || $demand === 'low' || $this->is_explicitly_false($value['publish'] ?? null) || $this->is_explicitly_false($value['adds_value'] ?? null)) {
            return true;
        }
        return false;
    }

    /**
     * People are searching this topic, so the website should carry it.
     */
    private function people_are_searching($review) {
        if (!is_array($review)) {
            return false;
        }
        $audience = $review['audience'] ?? null;
        if (!is_array($audience)) {
            return false;
        }
        return $this->is_explicitly_true($audience['everyone_searching'] ?? null);
    }

    /**
     * Website posts only. Top pop-culture news still clears the review-score
     * and SEO-score floors. A story people are searching for is posted on
     * the website. Social keeps its own floor. Reject, policy, facts, and
     * an ungrounded article stay held.
     */
    private function audience_demand_overrides_score($review) {
        if (!is_array($review)) {
            return false;
        }
        $audience = $review['audience'] ?? null;
        if (!is_array($audience)) {
            return false;
        }
        return $this->is_explicitly_true($audience['pop_culture_top'] ?? null)
            || $this->is_explicitly_true($audience['everyone_searching'] ?? null);
    }

    private function is_explicitly_true($value) {
        if ($value === true || $value === 1) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }
        return in_array(strtolower(trim($value)), array('1', 'true', 'yes'), true);
    }

    /**
     * The only story-value miss is that readers would not look it up.
     * Thin copy and a post that adds nothing stay held.
     */
    private function story_value_is_only_low_demand($value) {
        if (!is_array($value)) {
            return false;
        }
        $substance = strtolower(trim((string) ($value['substance'] ?? '')));
        if (!in_array($substance, array('solid', 'major'), true)) {
            return false;
        }
        if (!$this->is_explicitly_true($value['adds_value'] ?? null)) {
            return false;
        }
        return strtolower(trim((string) ($value['reader_demand'] ?? ''))) === 'low';
    }

    private function audience_override_note($review, $rewrite, $s) {
        if (!$this->audience_demand_overrides_score($review)) {
            return '';
        }
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        $score = isset($review['score']) ? (int) $review['score'] : 0;
        $min_score = (int) $s['draft_min_score'];
        $seo = is_array($rewrite['seo'] ?? null) ? $rewrite['seo'] : array();
        $seo_score = (int) ($seo['seo_score'] ?? 0);
        $seo_min = (int) $s['seo_min_score'];
        $low_score = $min_score > 0 && $score < $min_score;
        $low_seo = $seo_min > 0 && $seo_score < $seo_min;
        $low_demand = $this->story_value_is_only_low_demand($review['story_value'] ?? null);
        if (!$low_score && !$low_seo && !$low_demand) {
            return '';
        }
        $audience = is_array($review['audience'] ?? null) ? $review['audience'] : array();
        $why = array();
        if ($this->is_explicitly_true($audience['pop_culture_top'] ?? null)) {
            $why[] = 'top pop-culture news';
        }
        if ($this->is_explicitly_true($audience['everyone_searching'] ?? null)) {
            $why[] = 'people are searching for it on the internet';
        }
        if (!$why) {
            return '';
        }
        return 'Website post went live as ' . implode(' and ', $why) . '. Internet search demand is public web search, not search on this website. The review-score and SEO-score minimums were not applied. Social still uses its own score floor.';
    }

    /**
     * Drop feed items that are too thin, or that are lists and deals rather
     * than a development a reader would search. This does not call a model.
     */
    private function story_value_local_filler_patterns() {
        return array(
            '/\b(?:best|top)\s+\d+\b/' => 'it is a best-of list',
            '/\b\d+\s+(?:best|ways|things|tips|gadgets|apps|deals|laptops|phones|headphones)\b/' => 'it is a list, not a story',
            '/\b(?:coupon|promo code|discount code|%\s*off|percent off)\b/' => 'it is a coupon or discount',
            '/\b(?:sponsored(?:\s+post|\s+content)?|advertorial|paid partnership)\b/' => 'it is sponsored',
            '/\b(?:daily deals|deal of the day|this week\'?s deals)\b/' => 'it is a deals roundup',
            '/\b(?:weekly roundup|week in review|what to watch this week)\b/' => 'it is a recap, not a story',
        );
    }

    private function story_value_local_is_filler($item) {
        if (!is_array($item)) {
            return false;
        }
        $title = strtolower(trim(wp_strip_all_tags((string) ($item['source_title'] ?? ''))));
        if ($title === '') {
            return false;
        }
        foreach ($this->story_value_local_filler_patterns() as $pattern => $why) {
            unset($why);
            if (preg_match($pattern, $title)) {
                return true;
            }
        }
        return false;
    }

    private function story_value_local_reason($item) {
        if (!is_array($item)) {
            return '';
        }
        $title = strtolower(trim(wp_strip_all_tags((string) ($item['source_title'] ?? ''))));
        $body = strtolower(trim(wp_strip_all_tags((string) ($item['source_body'] ?? ''))));
        foreach ($this->story_value_local_filler_patterns() as $pattern => $why) {
            if ($title !== '' && preg_match($pattern, $title)) {
                return 'Held: not worth publishing. ' . ucfirst($why) . ', and readers are unlikely to look for it.';
            }
        }
        $words = $body === '' ? array() : preg_split('/\s+/', $body, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            $words = array();
        }
        $concrete = preg_match('/\d|[$£€]|announc|launch|recall|acqui|releas|unveil|earnings|lawsuit|breach|price|\bships?\b|\bban(?:s|ned)?\b|\bversion\b|cve-|\bsues?\b|\bsuing\b|\bbuys?\b|\bbought\b|\bcuts?\b|\bdelays?\b|\bconfirms?\b|\bleaks?\b/i', $title . ' ' . $body);
        $significant = 0;
        $stop = array('a', 'an', 'the', 'and', 'or', 'of', 'for', 'to', 'in', 'on', 'at', 'by', 'with', 'from', 'is', 'are', 'as', 'how', 'why', 'what', 'when', 'this', 'that', 'its', 'new');
        foreach (preg_split('/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $clean = strtolower(preg_replace('/[^a-z0-9]/', '', (string) $word));
            if ($clean !== '' && strlen($clean) > 2 && !in_array($clean, $stop, true)) {
                $significant++;
            }
        }
        // RSS excerpts are often under 35 words. A named headline still goes to
        // the writer. Only a vague short title with a short body is skipped.
        if (count($words) < 35 && !$concrete && $significant < 3) {
            return 'Held: not worth publishing. The source is too thin to show a development readers would search for.';
        }
        return '';
    }

    /**
     * The reviewer reports this separately from the 0-100 score. A missing
     * object does not block older reviews; a present object must clear.
     */
    private function story_value_model_reason($value) {
        if (!is_array($value) || !$value) {
            return '';
        }
        $substance = strtolower(trim((string) ($value['substance'] ?? '')));
        $demand = strtolower(trim((string) ($value['reader_demand'] ?? '')));
        $reason = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) ($value['reason'] ?? ''))));
        $detail = $reason !== '' ? ' ' . $reason : '';
        $publish = $value['publish'] ?? null;
        $adds = array_key_exists('adds_value', $value) ? $value['adds_value'] : null;
        if ($this->is_explicitly_false($publish) || $substance === 'thin' || $demand === 'low' || $this->is_explicitly_false($adds)) {
            if ($substance === 'thin') {
                return 'Held: not worth publishing. The story is too thin.' . $detail;
            }
            if ($demand === 'low') {
                return 'Held: not worth publishing. Readers are unlikely to look for this.' . $detail;
            }
            if ($this->is_explicitly_false($adds)) {
                return 'Held: not worth publishing. It does not add enough value.' . $detail;
            }
            return 'Held: not worth publishing.' . $detail;
        }
        $publish_ok = $publish === true || $publish === 1 || $publish === '1' || $publish === 'true';
        if (!in_array($substance, array('solid', 'major'), true) || !in_array($demand, array('medium', 'high'), true) || !$publish_ok) {
            return 'Held: not worth publishing. Story value did not clear.' . $detail;
        }
        if ($adds === null || $this->is_explicitly_false($adds)) {
            return 'Held: not worth publishing. It does not add enough value.' . $detail;
        }
        return '';
    }

    private function is_explicitly_false($value) {
        return $value === false || $value === 0 || $value === '0' || $value === 'false';
    }

    private function flag_list_is_empty($flags) {
        if ($flags === null || $flags === '' || $flags === false) {
            return true;
        }
        if (!is_array($flags)) {
            return trim((string) $flags) === '';
        }
        foreach ($flags as $flag) {
            if (is_array($flag)) {
                if (array_filter($flag, function ($value) {
                    return trim((string) $value) !== '';
                })) {
                    return false;
                }
                continue;
            }
            if (trim((string) $flag) !== '') {
                return false;
            }
        }
        return true;
    }

    private function ai_json($model, $system, $user, $s, $web_search, $allow_flag_retry = true) {
        $key = $this->api_key();
        $estimated_input = (int) ceil((strlen($system) + strlen($user)) / 3.5) + ($web_search ? 8000 : 0);
        $block = $this->spend_block_reason($model, $estimated_input, $s, $web_search);
        if ($block !== '') {
            return new WP_Error('tr_ai_wire_limit', $block);
        }
        // OpenAI rejects web_search together with JSON mode (400: "Web Search cannot
        // be used with JSON mode."). Keep json_object only for plain calls; with
        // search, ask for JSON in the prompt and parse the text reply.
        $system_text = (string) $system;
        if ($web_search) {
            $system_text .= ' After any search, reply with one JSON object only — no markdown fences, no prose before or after the JSON.';
        }
        $payload = array(
            'model' => $model,
            'input' => array(
                array('role' => 'system', 'content' => array(array('type' => 'input_text', 'text' => $system_text))),
                array('role' => 'user', 'content' => array(array('type' => 'input_text', 'text' => $user))),
            ),
            'max_output_tokens' => (int) $s['max_output_tokens'],
            // Luna/Terra default to medium reasoning, which consumes max_output_tokens
            // and routinely exceeds a short HTTP timeout for JSON drafting.
            'reasoning' => array('effort' => 'none'),
        );
        if ($web_search) {
            $payload['tools'] = array(array('type' => 'web_search', 'search_context_size' => 'low'));
            $payload['tool_choice'] = 'required';
            $payload['max_tool_calls'] = 1;
        } else {
            $payload['text'] = array('format' => array('type' => 'json_object'));
        }
        $response = wp_remote_post(self::API_URL, array(
            'timeout' => self::API_TIMEOUT,
            'headers' => array('Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_http', 'AI request failed: ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            // Reasoning models return this 400 for long prompts and for prompts that
            // name disallowed categories, even as "do not" rules. One shorter excerpt
            // often passes. The same full text will not, so do not schedule another retry.
            if ($allow_flag_retry && $this->prompt_was_flagged($code, $data)) {
                $next_user = $user;
                $shorter = $this->shorten_flagged_input($user);
                if ($shorter !== $user) {
                    $retry = $this->ai_json($model, $system, $shorter, $s, $web_search, false);
                    if (!$this->is_prompt_flag_error($retry)) {
                        return $retry;
                    }
                    $next_user = $shorter;
                }
                $gemini = $this->gemini_json($system, $next_user, $s);
                if ($gemini !== null) {
                    return $gemini;
                }
            }
            $message = isset($data['error']['message']) ? sanitize_text_field($data['error']['message']) : 'Unexpected API response.';
            if ($this->prompt_was_flagged($code, $data)) {
                $message = 'OpenAI blocked this prompt on a usage-policy check. A shorter excerpt is tried first. Add a Gemini API key on the AI Wire screen to try Gemini when OpenAI still blocks it. This is not a billing or outage error.';
            }
            $retryable = ($code === 408 || $code === 409 || $code === 429 || $code >= 500 || $code === 0);
            return new WP_Error($retryable ? 'tr_ai_wire_api' : 'tr_ai_wire_api_permanent', 'AI API error (' . (int) $code . '): ' . $message);
        }
        $search_calls = 0;
        $text = '';
        foreach (($data['output'] ?? array()) as $output) {
            if (($output['type'] ?? '') === 'web_search_call') {
                $search_calls++;
            }
            foreach (($output['content'] ?? array()) as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    $text .= $content['text'];
                }
            }
        }
        $this->record_usage($model, $data, $search_calls);
        $status = (string) ($data['status'] ?? 'completed');
        if (in_array($status, array('failed', 'incomplete', 'cancelled'), true)) {
            return new WP_Error('tr_ai_wire_api', 'AI response was ' . $status . '.');
        }
        $parsed = $this->ai_decode_json_object($text);
        if (!is_array($parsed)) {
            return new WP_Error('tr_ai_wire_json', 'AI returned invalid JSON.');
        }
        return $parsed;
    }

    /** Parse a JSON object from model text, including optional ```json fences. */
    private function ai_decode_json_object($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            return $parsed;
        }
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/is', $text, $m)) {
            $parsed = json_decode($m[1], true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $parsed = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }
        return null;
    }

    private function prompt_was_flagged($code, $data) {
        if ((int) $code !== 400 || !is_array($data)) {
            return false;
        }
        $error = isset($data['error']) && is_array($data['error']) ? $data['error'] : array();
        $name = strtolower((string) ($error['code'] ?? ''));
        $message = strtolower((string) ($error['message'] ?? ''));
        return $name === 'invalid_prompt' || strpos($message, 'flagged as potentially violating') !== false;
    }

    private function is_prompt_flag_error($result) {
        return is_wp_error($result)
            && $result->get_error_code() === 'tr_ai_wire_api_permanent'
            && strpos($result->get_error_message(), 'usage-policy check') !== false;
    }

    /**
     * Different provider, used only after OpenAI flags the prompt.
     * Returns null when no Gemini key is saved so the caller keeps the OpenAI error.
     */
    private function gemini_json($system, $user, $s) {
        $key = $this->gemini_api_key();
        if ($key === '') {
            return null;
        }
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        $estimated_input = (int) ceil((strlen($system) + strlen($user)) / 3.5);
        $block = $this->spend_block_reason(self::GEMINI_MODEL, $estimated_input, $s, false);
        if ($block !== '') {
            return new WP_Error('tr_ai_wire_limit', $block);
        }
        $response = wp_remote_post(self::GEMINI_API_URL, array(
            'timeout' => self::API_TIMEOUT,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $key,
            ),
            'body' => wp_json_encode(array(
                'systemInstruction' => array('parts' => array(array('text' => $system))),
                'contents' => array(array(
                    'role' => 'user',
                    'parts' => array(array('text' => $user)),
                )),
                'generationConfig' => array(
                    'responseMimeType' => 'application/json',
                    'maxOutputTokens' => (int) $s['max_output_tokens'],
                ),
            )),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_http', 'Gemini request failed: ' . $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            $message = isset($data['error']['message']) ? sanitize_text_field($data['error']['message']) : 'Unexpected Gemini response.';
            $retryable = ($code === 408 || $code === 409 || $code === 429 || $code >= 500 || $code === 0);
            return new WP_Error($retryable ? 'tr_ai_wire_api' : 'tr_ai_wire_api_permanent', 'Gemini API error (' . $code . '): ' . $message);
        }
        $blocked = sanitize_text_field((string) ($data['promptFeedback']['blockReason'] ?? ''));
        if ($blocked !== '') {
            return new WP_Error('tr_ai_wire_api_permanent', 'Gemini blocked this prompt (' . $blocked . '). This is not a billing or outage error.');
        }
        $text = '';
        $parts = $data['candidates'][0]['content']['parts'] ?? array();
        foreach ((array) $parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
        $text = trim($text);
        if (strpos($text, '```') === 0) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        }
        $usage = isset($data['usageMetadata']) && is_array($data['usageMetadata']) ? $data['usageMetadata'] : array();
        $this->record_usage(self::GEMINI_MODEL, array(
            'usage' => array(
                'input_tokens' => absint($usage['promptTokenCount'] ?? 0),
                'output_tokens' => absint($usage['candidatesTokenCount'] ?? 0),
            ),
        ), 0);
        $parsed = json_decode((string) $text, true);
        if (!is_array($parsed)) {
            return new WP_Error('tr_ai_wire_json', 'Gemini returned invalid JSON.');
        }
        return $parsed;
    }

    /**
     * Keep the title and the article JSON, and cut the long source body.
     * Length is what usually trips the provider check on otherwise ordinary tech copy.
     */
    private function shorten_flagged_input($user) {
        $user = (string) $user;
        if (strlen($user) <= 4500) {
            return $user;
        }
        foreach (array("Source text:\n", "SOURCE TEXT:\n") as $marker) {
            $start = strpos($user, $marker);
            if ($start === false) {
                continue;
            }
            $body_start = $start + strlen($marker);
            $end = strlen($user);
            foreach (array("\n\nSite categories", "\n\nPROPOSED ARTICLE JSON:", "\n\nRelated posts", "\n\nExisting tags", "\n\nTags —") as $end_marker) {
                $pos = strpos($user, $end_marker, $body_start);
                if ($pos !== false && $pos < $end) {
                    $end = $pos;
                }
            }
            if (($end - $body_start) <= 1800) {
                break;
            }
            $excerpt = $this->prompt_excerpt(substr($user, $body_start, $end - $body_start), 1800);
            $excerpt .= "\n[Source excerpt shortened after the provider blocked the full text. Use only this excerpt.]\n";
            return substr($user, 0, $body_start) . $excerpt . substr($user, $end);
        }
        $head = $this->prompt_excerpt($user, 1600);
        $tail = function_exists('mb_substr') ? mb_substr($user, -1800) : substr($user, -1800);
        return $head . "\n\n[Middle omitted after the provider blocked the full text.]\n\n" . $tail;
    }

    private function prompt_excerpt($text, $length) {
        if (function_exists('mb_substr')) {
            return mb_substr((string) $text, 0, $length);
        }
        return substr((string) $text, 0, $length);
    }

    private function usage_key() {
        return 'tr_ai_wire_usage_' . gmdate('Ym');
    }

    private function usage() {
        return wp_parse_args(get_option($this->usage_key(), array()), array('input_tokens' => 0, 'output_tokens' => 0, 'search_calls' => 0, 'cost_usd' => 0));
    }

    private function model_rates($model) {
        $rates = array(
            'gpt-6-luna' => array('input' => 0.10, 'output' => 0.50),
            'gpt-6.1-terra' => array('input' => 1.00, 'output' => 5.00),
            // Gemini 2.5 Flash paid tier, USD per 1M tokens. Recheck Google's price card if this cap starts holding items early.
            'gemini-2.5-flash' => array('input' => 0.30, 'output' => 2.50),
        );
        return $rates[$model] ?? $rates['gpt-6-luna'];
    }

    private function estimate_call_cost($model, $input_tokens, $output_tokens, $search = false) {
        $r = $this->model_rates($model);
        return (($input_tokens * $r['input']) + ($output_tokens * $r['output'])) / 1000000 + ($search ? self::SEARCH_CALL_USD : 0);
    }

    private function can_spend($model, $estimated_input_tokens, $s, $search = false) {
        return $this->spend_block_reason($model, $estimated_input_tokens, $s, $search) === '';
    }

    /**
     * Local AI Wire caps only. OpenAI credit balance and the provider project
     * budget are separate and are not read here.
     */
    private function spend_block_reason($model, $estimated_input_tokens, $s, $search = false) {
        $u = $this->usage();
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        $output_tokens = max(0, (int) $s['max_output_tokens']);
        $estimated_total_tokens = max(0, (int) $estimated_input_tokens) + $output_tokens;
        $used_tokens = (int) $u['input_tokens'] + (int) $u['output_tokens'];
        $token_limit = (int) $s['monthly_token_limit'];
        if ($token_limit <= 0) {
            return 'AI Wire monthly token limit is 0, so this item is held. That cap is in AI Wire settings, not the OpenAI credit balance. Set Monthly token limit above 0.';
        }
        if (($used_tokens + $estimated_total_tokens) > $token_limit) {
            return 'AI Wire monthly token limit reached (' . $used_tokens . ' used + ' . $estimated_total_tokens . ' reserved > ' . $token_limit . '). OpenAI can still show remaining credit. Raise Monthly token limit in AI Wire settings.';
        }
        $budget = (float) $s['monthly_budget_usd'];
        $reserve = $this->estimate_call_cost($model, max(0, (int) $estimated_input_tokens), $output_tokens, $search);
        $spent = (float) $u['cost_usd'];
        if ($budget <= 0) {
            return 'AI Wire monthly API budget is $0, so this item is held. That cap is in AI Wire settings, not the OpenAI credit balance. Set Monthly API budget above 0.';
        }
        if (($spent + $reserve) > ($budget + 0.000001)) {
            return 'AI Wire monthly API budget reached ($' . number_format($spent, 4, '.', '') . ' used + $' . number_format($reserve, 4, '.', '') . ' reserved > $' . number_format($budget, 2, '.', '') . '). OpenAI can still show remaining credit. Raise Monthly API budget in AI Wire settings.';
        }
        return '';
    }

    private function record_usage($model, $response, $search_calls = 0) {
        $usage = isset($response['usage']) && is_array($response['usage']) ? $response['usage'] : array();
        $in = absint($usage['input_tokens'] ?? 0);
        $out = absint($usage['output_tokens'] ?? 0);
        $search_calls = absint($search_calls);
        $rates = $this->model_rates($model);
        $cost = (($in * $rates['input']) + ($out * $rates['output'])) / 1000000 + ($search_calls * self::SEARCH_CALL_USD);
        $current = $this->usage();
        $current['input_tokens'] += $in;
        $current['output_tokens'] += $out;
        $current['search_calls'] += $search_calls;
        $current['cost_usd'] = round(((float) $current['cost_usd']) + $cost, 6);
        update_option($this->usage_key(), $current, false);
    }

    private function remaining_posts_today($s) {
        $limit = (int) $s['max_posts_per_day'];
        if ($limit <= 0) {
            return 0;
        }
        return max(0, $limit - $this->posts_created_today_count($s));
    }

    /**
     * Operator phrases. A story that mentions one is written before the rest.
     * Matching does not skip review, and a hold still does not use a daily slot.
     */
    private function hot_topic_phrases($s = null) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $parts = preg_split('/[\r\n,]+/', (string) ($s['hot_topics'] ?? ''));
        $out = array();
        foreach ((array) $parts as $part) {
            $part = trim(wp_strip_all_tags((string) $part));
            if (strlen($part) < 2 || strlen($part) > 80) {
                continue;
            }
            $key = strtolower($part);
            $out[$key] = $part;
            if (count($out) >= 40) {
                break;
            }
        }
        return array_values($out);
    }

    private function sanitize_hot_topics($value) {
        $lines = array();
        foreach ($this->hot_topic_phrases(array('hot_topics' => (string) $value)) as $phrase) {
            $lines[] = sanitize_text_field($phrase);
        }
        return implode("\n", $lines);
    }

    private function item_matches_hot_topic($item, $s = null) {
        $phrases = $this->hot_topic_phrases($s);
        if (!$phrases || !is_array($item)) {
            return false;
        }
        $hay = strtolower(wp_strip_all_tags((string) ($item['source_title'] ?? '') . ' ' . (string) ($item['source_body'] ?? '')));
        if ($hay === '' || $hay === ' ') {
            return false;
        }
        foreach ($phrases as $phrase) {
            $pattern = '/(?<![a-z0-9])' . preg_quote(strtolower($phrase), '/') . '(?![a-z0-9])/';
            if (preg_match($pattern, $hay)) {
                return true;
            }
        }
        return false;
    }

    private function comics_desk_daily_limit($s) {
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        $total = max(0, (int) ($s['max_posts_per_day'] ?? 0));
        return min($total, max(0, (int) ($s['comics_desk_daily_limit'] ?? 0)));
    }

    private function comics_desk_feed_urls($s) {
        $urls = array();
        foreach ($this->enabled_tenants($s) as $tenant) {
            if ($this->tenant_desk_lane($tenant) !== 'comics') {
                continue;
            }
            $url = esc_url_raw($tenant['url'] ?? '', array('http', 'https'));
            if ($url) {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }

    private function comics_desk_assigned_today($s = null) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        return $this->count_plugin_posts_created_today($this->comics_desk_feed_urls($s));
    }

    private function posts_created_today_count($s = null) {
        unset($s);
        return $this->count_plugin_posts_created_today();
    }

    /**
     * Posts this plugin inserted today. Held rows and later edits to an older
     * post do not consume Maximum posts per day.
     */
    private function count_plugin_posts_created_today($feed_urls = null) {
        global $wpdb;
        if (is_array($feed_urls) && !$feed_urls) {
            return 0;
        }
        $start = gmdate('Y-m-d 00:00:00');
        $end = gmdate('Y-m-d 23:59:59');
        $params = array($start, $end, $start, $end);
        $feed_sql = '';
        if (is_array($feed_urls)) {
            $feed_sql = ' AND q.feed_url IN (' . implode(',', array_fill(0, count($feed_urls), '%s')) . ')';
            $params = array_merge($params, $feed_urls);
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$this->table} q ON q.wp_post_id = p.ID
             WHERE q.status IN ('draft', 'published')
               AND p.post_status IN ('publish', 'draft', 'pending', 'future', 'private')
               AND p.post_type = 'post'
               AND (
                    p.post_date_gmt BETWEEN %s AND %s
                    OR EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} c
                        WHERE c.post_id = p.ID
                          AND c.meta_key = '_tr_ai_wire_created_gmt'
                          AND c.meta_value BETWEEN %s AND %s
                    )
               )
               {$feed_sql}",
            $params
        ));
    }

    private function comics_desk_waiting($exclude = array(), $s = null) {
        global $wpdb;
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $urls = $this->comics_desk_feed_urls($s);
        if (!$urls) {
            return false;
        }
        $exclude_ids = array();
        foreach ((array) $exclude as $key => $value) {
            $id = is_int($key) && $key > 0 ? $key : (int) $value;
            if ($id > 0) {
                $exclude_ids[] = $id;
            }
        }
        $exclude_ids = array_values(array_unique($exclude_ids));
        $not_in = $exclude_ids ? ' AND id NOT IN (' . implode(',', array_map('intval', $exclude_ids)) . ')' : '';
        $placeholders = implode(',', array_fill(0, count($urls), '%s'));
        $params = array_merge($urls, array(current_time('mysql', true)));
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE feed_url IN ({$placeholders})
               AND (
                (status IN ('pending', 'new', 'waiting_budget', 'generated', 'rewritten') AND (wp_post_id IS NULL OR wp_post_id = 0))
                OR status = 'reviewed'
                OR (status = 'retry_wait' AND (next_retry_at IS NULL OR next_retry_at <= %s))
               ){$not_in}
             LIMIT 1",
            $params
        ));
        return $id > 0;
    }

    /**
     * Desk mix inside Maximum posts per day.
     * Example: max 5 + comics desk 1 → up to 1 Comics and about 4 tech.
     * Unused Comics slots soft-fill with tech. Comics never exceeds its desk cap.
     */
    private function lane_has_room_for_item($item, $s, $exclude = array()) {
        $s = wp_parse_args(is_array($s) ? $s : $this->settings(), self::defaults());
        $comics_cap = $this->comics_desk_daily_limit($s);
        // Desk mix off: overall daily cap stays in the feed/job loop, not create_draft.
        if ($comics_cap <= 0) {
            return true;
        }
        if ($this->remaining_posts_today($s) <= 0) {
            return false;
        }
        $lane = $this->item_desk_lane($item, $s);
        $comics_used = $this->comics_desk_assigned_today($s);
        $total_used = $this->posts_created_today_count($s);
        $tech_used = max(0, $total_used - $comics_used);
        $tech_cap = max(0, (int) $s['max_posts_per_day'] - $comics_cap);
        if ($lane === 'comics') {
            return $comics_used < $comics_cap;
        }
        if ($tech_used < $tech_cap) {
            return true;
        }
        // Soft-fill: tech may use leftover Comics slots when no Comics story is waiting.
        return ($comics_used < $comics_cap) && !$this->comics_desk_waiting($exclude, $s);
    }

    private function experiment_assignment($source_key, $settings) {
        $enabled = !empty($settings['enable_experiment']);
        $variant = $enabled && (hexdec(substr($source_key, 0, 2)) % 2 === 1) ? 'B' : 'A';
        return array('experiment_id' => sanitize_key($settings['experiment_id'] ?? 'editorial-v1'), 'variant' => $variant);
    }

    private function has_usable_rewrite($rewrite) {
        return is_array($rewrite)
            && trim((string) ($rewrite['title'] ?? '')) !== ''
            && trim((string) ($rewrite['body_html'] ?? '')) !== '';
    }

    private function review_meets_score($review, $s) {
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        $min = (int) $s['draft_min_score'];
        if ($min <= 0 || !is_array($review)) {
            return true;
        }
        return (int) ($review['score'] ?? 0) >= $min;
    }

    private function should_force_publish_retry($item, $rewrite, $review, $s) {
        return in_array(($item['status'] ?? ''), array('quarantined', 'error', 'retry_wait'), true)
            && $this->has_usable_rewrite($rewrite)
            && is_array($review)
            && !$this->review_draft_blockers($review, $s);
    }

    private function force_publish_item($id, $item, $rewrite, $review, $s) {
        if (!is_array($review) || $this->review_draft_blockers($review, $s)) {
            $this->quarantine_item($id, 'Retry requires an independent review that currently passes editorial gates.', $item);
            return;
        }
        $gate = $this->evaluate_pre_publish_gate($rewrite, $review, $item, $s);
        $review['prepublish_gate'] = $gate;
        if ($this->pre_publish_gate_holds($gate) && $this->mapped_post_status($s) === 'publish') {
            $this->quarantine_item($id, 'Held by pre-publish gate. ' . $this->format_pre_publish_gate($gate), $item, array(
                'rewrite_json' => wp_json_encode($rewrite),
                'review_json' => wp_json_encode($review),
            ));
            return;
        }
        $this->create_draft($id, $item, $rewrite, $review, $s);
    }

    /**
     * Keep this positive. Naming disallowed categories here, even as a "do not"
     * list, makes gpt-6 reasoning models return HTTP 400 invalid_prompt.
     * The local word filter and the Moderation API still run before publish.
     */
    private function policy_writing_instructions($comic = false) {
        if (!empty($comic)) {
            return 'House style: factual, family-friendly tech news with a pinch of dry comic sense so people want to keep reading. Keep that humor restrained: one raised-eyebrow aside in the lede or Why it matters is enough. Never a standup set, never a punchline-only headline, never meme slang. Facts stay facts. Skip crude language. If the source is not appropriate for a general audience, return an empty title and an empty body_html.';
        }
        return 'House style: plain, factual, family-friendly tech news. Skip crude language. If the source is not appropriate for a general audience, return an empty title and an empty body_html.';
    }

    private function normalize_voice($voice) {
        $voice = sanitize_key((string) $voice);
        return in_array($voice, array('comic', 'straight'), true) ? $voice : '';
    }

    private function item_is_comic($item) {
        return $this->normalize_voice(is_array($item) ? ($item['voice'] ?? '') : '') === 'comic';
    }

    private function post_is_comic($post) {
        $id = ($post instanceof WP_Post) ? (int) $post->ID : (int) $post;
        if ($id < 1) {
            return false;
        }
        return $this->normalize_voice(get_post_meta($id, '_tr_ai_wire_voice', true)) === 'comic';
    }

    private function comic_daily_limit($s) {
        return min(100, max(0, (int) (is_array($s) ? ($s['comic_daily_limit'] ?? 0) : 0)));
    }

    private function comic_assigned_today() {
        global $wpdb;
        $start = gmdate('Y-m-d 00:00:00');
        $end = gmdate('Y-m-d 23:59:59');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE voice = %s AND updated_at BETWEEN %s AND %s", 'comic', $start, $end));
    }

    private function assign_item_voice($id, $item, $s) {
        $item = is_array($item) ? $item : array();
        $voice = $this->normalize_voice($item['voice'] ?? '');
        if ($voice !== '') {
            return $item;
        }
        $rewrite = !empty($item['rewrite_json']) ? json_decode($item['rewrite_json'], true) : null;
        if (is_array($rewrite) && $this->has_usable_rewrite($rewrite)) {
            $voice = 'straight';
        } else {
            $limit = $this->comic_daily_limit($s);
            $voice = ($limit > 0 && $this->comic_assigned_today() < $limit) ? 'comic' : 'straight';
        }
        $this->update_item((int) $id, array('voice' => $voice));
        $item['voice'] = $voice;
        return $item;
    }

    private function policy_guard_rewrite($rewrite) {
        return $this->policy_guard_text($this->policy_flatten_rewrite($rewrite), 'article');
    }

    private function policy_guard_social_copy($copy) {
        return $this->policy_guard_text($this->policy_flatten_social_copy($copy), 'social');
    }

    private function policy_guard_text($text, $context = 'content') {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') {
            return true;
        }
        $local = $this->policy_local_hits($text);
        if ($local) {
            return new WP_Error('tr_ai_wire_policy', $this->policy_hold_message($context, true, array()));
        }
        $moderation = $this->openai_moderation($text);
        if (is_wp_error($moderation)) {
            return $moderation;
        }
        if (!empty($moderation['flagged'])) {
            return new WP_Error('tr_ai_wire_policy', $this->policy_hold_message($context, false, $moderation['categories']));
        }
        return true;
    }

    private function policy_flatten_rewrite($rewrite) {
        $rewrite = is_array($rewrite) ? $rewrite : array();
        $parts = array(
            (string) ($rewrite['title'] ?? ''),
            (string) ($rewrite['lede'] ?? ''),
            wp_strip_all_tags((string) ($rewrite['body_html'] ?? '')),
            (string) ($rewrite['social_title'] ?? ''),
            (string) ($rewrite['social_description'] ?? ''),
            (string) ($rewrite['meta_description'] ?? ''),
        );
        foreach ((array) ($rewrite['key_takeaways'] ?? array()) as $takeaway) {
            $parts[] = is_array($takeaway) ? implode(' ', $takeaway) : (string) $takeaway;
        }
        foreach ((array) ($rewrite['faq'] ?? array()) as $faq) {
            if (is_array($faq)) {
                $parts[] = (string) ($faq['question'] ?? '') . ' ' . (string) ($faq['answer'] ?? '');
            } else {
                $parts[] = (string) $faq;
            }
        }
        return trim(implode("\n", $parts));
    }

    private function policy_flatten_social_copy($copy) {
        $copy = is_array($copy) ? $copy : array();
        $parts = array(
            (string) ($copy['hook'] ?? ''),
            (string) ($copy['on_image_headline'] ?? ''),
            (string) ($copy['on_image_subhead'] ?? ''),
            (string) ($copy['on_image_kicker'] ?? ''),
            (string) ($copy['visual_brief'] ?? ''),
            (string) ($copy['cta'] ?? ''),
            (string) ($copy['x'] ?? ''),
            (string) ($copy['instagram'] ?? ''),
            (string) ($copy['instagram_alt'] ?? ''),
            (string) ($copy['reel_script'] ?? ''),
            (string) ($copy['youtube_title'] ?? ''),
            (string) ($copy['youtube_description'] ?? ''),
        );
        foreach ((array) ($copy['on_image_bullets'] ?? array()) as $bullet) {
            $parts[] = is_array($bullet) ? (string) ($bullet['text'] ?? implode(' ', $bullet)) : (string) $bullet;
        }
        foreach ((array) ($copy['reel_captions'] ?? array()) as $caption) {
            if (is_array($caption)) {
                $parts[] = (string) ($caption['caption'] ?? implode(' ', $caption));
            } else {
                $parts[] = (string) $caption;
            }
        }
        foreach ((array) ($copy['youtube_tags'] ?? array()) as $tag) {
            $parts[] = (string) $tag;
        }
        foreach ((array) ($copy['hashtags'] ?? array()) as $tag) {
            $parts[] = (string) $tag;
        }
        return trim(implode("\n", $parts));
    }

    private function policy_hold_message($context, $local_hit, $categories) {
        $label = $context === 'social' ? 'social copy' : 'article';
        $parts = array();
        if ($local_hit) {
            $parts[] = 'the profanity/slur filter';
        }
        $named = array();
        foreach ((array) $categories as $name => $on) {
            if (!$on) {
                continue;
            }
            $named[] = str_replace(array('-', '_'), ' ', sanitize_text_field((string) $name));
        }
        if ($named) {
            $parts[] = 'OpenAI Moderation (' . implode(', ', array_slice($named, 0, 6)) . ')';
        }
        $why = $parts ? implode('; ', $parts) : 'policy checks';
        return 'Held: ' . $label . ' failed ' . $why . '. Nothing was posted. Edit or regenerate, then retry.';
    }

    private function policy_local_hits($text) {
        $hits = array();
        $blobs = $this->policy_normalized_blobs($text);
        if (!$blobs) {
            return $hits;
        }
        foreach ($this->policy_banned_terms() as $term) {
            $pattern = '/\b' . preg_quote($term, '/') . '\b/i';
            foreach ($blobs as $blob) {
                if (preg_match($pattern, $blob)) {
                    $hits[] = $term;
                    break;
                }
            }
        }
        return $hits;
    }

    private function policy_normalized_blobs($text) {
        $plain = strtolower(wp_strip_all_tags((string) $text));
        if ($plain === '') {
            return array();
        }
        $spaced = trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $plain));
        $no_fillers = str_replace(array('*', '#', '@', '$', '!'), '', $plain);
        $no_fillers = trim((string) preg_replace('/[^a-z0-9]+/i', ' ', $no_fillers));
        $between = preg_replace('/(?<=[a-z])[^a-z0-9\s]+(?=[a-z])/i', '', $plain);
        $between = trim((string) preg_replace('/[^a-z0-9]+/i', ' ', (string) $between));
        $out = array();
        foreach (array($spaced, $no_fillers, $between) as $blob) {
            if ($blob !== '' && !in_array($blob, $out, true)) {
                $out[] = $blob;
            }
        }
        return $out;
    }

    private function policy_banned_terms() {
        return array(
            'asshole',
            'asswipe',
            'bastard',
            'bitch',
            'bitches',
            'bullshit',
            'chink',
            'cock',
            'cocksucker',
            'cunt',
            'dick',
            'dickhead',
            'dyke',
            'fag',
            'faggot',
            'fck',
            'fcked',
            'fcking',
            'fuck',
            'fucked',
            'fucker',
            'fucking',
            'gook',
            'kike',
            'motherfucker',
            'nigga',
            'nigger',
            'pussy',
            'retard',
            'retarded',
            'shit',
            'slut',
            'spastic',
            'spic',
            'tranny',
            'twat',
            'wanker',
            'wetback',
            'whore',
        );
    }

    private function openai_moderation($text) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ($text === '') {
            return array('flagged' => false, 'categories' => array());
        }
        $filtered = apply_filters('tr_ai_wire_moderation_result', null, $text);
        if (is_array($filtered)) {
            $categories = array();
            foreach ((array) ($filtered['categories'] ?? array()) as $name => $on) {
                if ($on) {
                    $categories[sanitize_key((string) $name)] = true;
                }
            }
            return array(
                'flagged' => !empty($filtered['flagged']) || !empty($categories),
                'categories' => $categories,
            );
        }
        $key = $this->api_key();
        if ($key === '') {
            return new WP_Error('tr_ai_wire_policy_unavailable', 'Held: OpenAI Moderation could not run because the API key is missing. Nothing was posted.');
        }
        if (function_exists('mb_substr') && function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 20000) {
            $text = mb_substr($text, 0, 20000, 'UTF-8');
        } elseif (strlen($text) > 20000) {
            $text = substr($text, 0, 20000);
        }
        $response = wp_remote_post('https://api.openai.com/v1/moderations', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => 'omni-moderation-latest',
                'input' => $text,
            )),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('tr_ai_wire_policy_unavailable', 'Held: OpenAI Moderation failed (' . $response->get_error_message() . '). Nothing was posted.');
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $result = is_array($data['results'][0] ?? null) ? $data['results'][0] : null;
        if ($code < 200 || $code >= 300 || !is_array($result)) {
            $message = isset($data['error']['message']) ? sanitize_text_field($data['error']['message']) : ('HTTP ' . (int) $code);
            return new WP_Error('tr_ai_wire_policy_unavailable', 'Held: OpenAI Moderation returned ' . $message . '. Nothing was posted.');
        }
        $categories = array();
        foreach ((array) ($result['categories'] ?? array()) as $name => $on) {
            if ($on) {
                $categories[sanitize_key((string) $name)] = true;
            }
        }
        return array(
            'flagged' => !empty($result['flagged']) || !empty($categories),
            'categories' => $categories,
        );
    }
}
