<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Last-7-day Instagram review: fetch platform views, decide what failed,
 * and feed mix + copy tweaks into the next posts. Never invent metrics.
 */
trait TechnoRapper_AI_Wire_Social_Review {
    public function process_social_review() {
        if (!$this->social_distribution_ready()) {
            return array();
        }
        $this->social_review_refresh_insights();
        return $this->social_review_rebuild();
    }

    public function maybe_schedule_social_review() {
        if (!$this->social_distribution_ready() || !TechnoRapper_AI_Wire::instagram_credentials_ready()) {
            return;
        }
        if (!wp_next_scheduled(self::SOCIAL_REVIEW_CRON_HOOK)) {
            wp_schedule_event(time() + 600, 'daily', self::SOCIAL_REVIEW_CRON_HOOK);
        }
    }

    public function run_social_review_now() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run this task.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_run_social_review');
        $this->enqueue_background_job('social_review');
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-social&social_review=1'));
        exit;
    }

    private function social_review_window_start() {
        return gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));
    }

    private function social_review_load() {
        $stored = get_option(self::SOCIAL_REVIEW_OPTION, array());
        return is_array($stored) ? $stored : array();
    }

    private function social_review_is_fresh($review = null) {
        $review = is_array($review) ? $review : $this->social_review_load();
        $at = (int) ($review['generated_at'] ?? 0);
        return $at > 0 && (time() - $at) < (8 * DAY_IN_SECONDS);
    }

    private function social_review_published_rows() {
        global $wpdb;
        $start = $this->social_review_window_start();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tr_ai_wire_social WHERE status = 'published' AND platform = 'instagram' AND updated_at >= %s ORDER BY updated_at DESC LIMIT 40",
            $start
        ), ARRAY_A);
    }

    private function social_review_refresh_insights() {
        $credentials = $this->instagram_resolve_publish_credentials($this->social_credentials());
        if (is_wp_error($credentials)) {
            return 0;
        }
        $token = (string) ($credentials['instagram_user_access_token'] ?? '');
        if ($token === '') {
            return 0;
        }
        $updated = 0;
        foreach ($this->social_review_published_rows() as $row) {
            $remote_id = preg_replace('/[^0-9]/', '', (string) ($row['remote_id'] ?? ''));
            if ($remote_id === '') {
                continue;
            }
            $mode = $this->social_review_row_mode($row);
            $insights = $this->instagram_fetch_media_insights($remote_id, $token, $mode);
            if (is_wp_error($insights)) {
                $payload = array(
                    'error' => $insights->get_error_message(),
                    'site_reads' => $this->social_review_site_reads((int) ($row['post_id'] ?? 0)),
                );
            } else {
                $payload = $insights;
                $payload['site_reads'] = $this->social_review_site_reads((int) ($row['post_id'] ?? 0));
            }
            $this->social_review_store_insights((int) ($row['id'] ?? 0), $payload);
            $updated++;
        }
        return $updated;
    }

    private function instagram_fetch_media_insights($media_id, $token, $mode = 'single') {
        $sets = $this->instagram_insights_metric_sets($mode);
        $last_error = null;
        foreach ($sets as $metrics) {
            $data = $this->instagram_graph_get($media_id . '/insights', $token, array('metric' => $metrics));
            if (!is_wp_error($data)) {
                $parsed = $this->social_review_parse_insights($data, $mode);
                $parsed['metrics'] = $metrics;
                return $parsed;
            }
            $last_error = $data;
            $message = strtolower($data->get_error_message());
            if (strpos($message, 'metric') === false && strpos($message, 'does not exist') === false) {
                break;
            }
        }
        return $last_error instanceof WP_Error
            ? $last_error
            : new WP_Error('tr_ai_wire_ig_insights', 'Instagram did not return insights for this post.');
    }

    private function instagram_insights_metric_sets($mode) {
        if ($mode === 'reel') {
            return array(
                'plays,reach,saved,likes,comments,shares',
                'views,reach,saved,likes,comments,shares',
                'impressions,reach,saved',
            );
        }
        return array(
            'impressions,reach,saved,likes,comments,shares',
            'views,reach,saved,likes,comments,shares',
            'reach,saved',
        );
    }

    private function social_review_parse_insights($data, $mode = 'single') {
        $metrics = array();
        foreach ((array) ($data['data'] ?? array()) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = sanitize_key((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $value = 0;
            foreach ((array) ($row['values'] ?? array()) as $point) {
                if (is_array($point) && isset($point['value']) && is_numeric($point['value'])) {
                    $value = (int) $point['value'];
                }
            }
            $metrics[$name] = $value;
        }
        $views = $this->social_review_views_from_metrics($metrics, $mode);
        return array(
            'views' => $views['views'],
            'view_source' => $views['source'],
            'reach' => (int) ($metrics['reach'] ?? 0),
            'saved' => (int) ($metrics['saved'] ?? 0),
            'likes' => (int) ($metrics['likes'] ?? 0),
            'comments' => (int) ($metrics['comments'] ?? 0),
            'shares' => (int) ($metrics['shares'] ?? 0),
            'raw' => $metrics,
        );
    }

    private function social_review_views_from_metrics($metrics, $mode = 'single') {
        $metrics = is_array($metrics) ? $metrics : array();
        $order = ($mode === 'reel')
            ? array('plays', 'views', 'impressions', 'reach')
            : array('impressions', 'views', 'plays', 'reach');
        foreach ($order as $key) {
            if (isset($metrics[$key]) && is_numeric($metrics[$key])) {
                return array('views' => (int) $metrics[$key], 'source' => $key);
            }
        }
        return array('views' => 0, 'source' => 'none');
    }

    private function social_review_site_reads($post_id) {
        global $wpdb;
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return 0;
        }
        $start = gmdate('Y-m-d', time() - (7 * DAY_IN_SECONDS));
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(views) FROM {$wpdb->prefix}tr_ai_wire_views WHERE post_id = %d AND view_date >= %s",
            $post_id,
            $start
        ));
    }

    private function social_review_store_insights($id, $payload) {
        global $wpdb;
        $id = (int) $id;
        if ($id < 1) {
            return;
        }
        $wpdb->update(
            $this->social_table(),
            array(
                'insights_json' => wp_json_encode(is_array($payload) ? $payload : array()),
                'insights_at' => current_time('mysql', true),
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
    }

    private function social_review_row_mode($row) {
        $post_id = (int) ($row['post_id'] ?? 0);
        $mode = $post_id > 0 ? sanitize_key((string) get_post_meta($post_id, '_tr_ai_wire_social_mode', true)) : '';
        if ($mode === 'pack') {
            return 'carousel';
        }
        if (in_array($mode, array('single', 'carousel', 'collage', 'reel'), true)) {
            return $mode;
        }
        return 'single';
    }

    private function social_review_row_variant($row) {
        $image_id = (int) ($row['image_attachment_id'] ?? 0);
        if ($image_id > 0) {
            $variant = sanitize_key((string) get_post_meta($image_id, '_tr_ai_wire_social_variant', true));
            if ($variant !== '') {
                return $variant;
            }
        }
        return 'photo-first';
    }

    private function social_review_samples_from_rows($rows) {
        $samples = array();
        foreach ((array) $rows as $row) {
            $insights = json_decode((string) ($row['insights_json'] ?? ''), true);
            if (!is_array($insights)) {
                $insights = array();
            }
            $mode = $this->social_review_row_mode($row);
            $metrics = is_array($insights['raw'] ?? null) ? $insights['raw'] : $insights;
            $parsed = $this->social_review_views_from_metrics($metrics, $mode);
            $views = isset($insights['views']) ? (int) $insights['views'] : (int) $parsed['views'];
            $source = (string) ($insights['view_source'] ?? $parsed['source']);
            $error = sanitize_text_field((string) ($insights['error'] ?? ''));
            if ($error !== '' && $views < 1) {
                $source = 'unavailable';
            }
            $samples[] = array(
                'id' => (int) ($row['id'] ?? 0),
                'post_id' => (int) ($row['post_id'] ?? 0),
                'mode' => $mode,
                'variant' => $this->social_review_row_variant($row),
                'views' => $views,
                'view_source' => $source,
                'saved' => (int) ($insights['saved'] ?? 0),
                'site_reads' => (int) ($insights['site_reads'] ?? 0),
                'error' => $error,
            );
        }
        return $samples;
    }

    private function social_review_rebuild() {
        $rows = $this->social_review_published_rows();
        $samples = $this->social_review_samples_from_rows($rows);
        $settings = $this->settings();
        $review = $this->social_review_decide($samples, $settings);
        $review['generated_at'] = time();
        $review['window_start'] = $this->social_review_window_start();
        $review['window_end'] = gmdate('Y-m-d H:i:s');
        update_option(self::SOCIAL_REVIEW_OPTION, $review, false);
        return $review;
    }

    private function social_review_decide($samples, $settings = array()) {
        $settings = is_array($settings) ? $settings : array();
        $samples = is_array($samples) ? $samples : array();
        $current = array(
            'reel' => (int) ($settings['social_reel_daily_limit'] ?? 0),
            'carousel' => (int) ($settings['social_carousel_daily_limit'] ?? 3),
            'collage' => (int) ($settings['social_collage_daily_limit'] ?? 0),
            'single' => (int) ($settings['social_single_daily_limit'] ?? 2),
        );
        $limits = $this->clamp_social_mix_counts($current, (int) ($settings['social_daily_limit'] ?? 10));
        $by_mode = array();
        $measured = 0;
        $unavailable = 0;
        foreach ($samples as $sample) {
            $mode = (string) ($sample['mode'] ?? 'single');
            if ((string) ($sample['view_source'] ?? '') === 'unavailable') {
                $unavailable++;
                continue;
            }
            $measured++;
            if (!isset($by_mode[$mode])) {
                $by_mode[$mode] = array();
            }
            $by_mode[$mode][] = (int) ($sample['views'] ?? 0);
        }
        $medians = array();
        foreach ($by_mode as $mode => $values) {
            $medians[$mode] = $this->social_review_median($values);
        }
        $verdicts = array();
        $tweaks = array(
            'reel' => $limits['reel'],
            'carousel' => $limits['carousel'],
            'collage' => $limits['collage'],
            'single' => $limits['single'],
            'overlay' => 'photo-first',
            'caption' => '',
            'mix_changed' => 0,
        );
        $n = count($samples);
        if ($n < 1) {
            $verdicts[] = 'No Instagram posts in the last 7 days. Keep the current mix until something publishes.';
            return array(
                'posts' => 0,
                'measured' => 0,
                'unavailable' => 0,
                'medians' => $medians,
                'verdicts' => $verdicts,
                'tweaks' => $tweaks,
                'source_note' => 'No Instagram insights yet.',
            );
        }
        if ($measured < 1) {
            $verdicts[] = 'Instagram did not return views for last week’s posts. The Page token may be missing insights access. Site reads are shown separately and are not Instagram views.';
        } elseif ($n < 3) {
            $verdicts[] = 'Only ' . (string) $n . ' Instagram post' . ($n === 1 ? '' : 's') . ' in 7 days — too few to change the mix. Keep the current settings.';
        }

        $still = isset($medians['single']) ? (float) $medians['single'] : null;
        $carousel = isset($medians['carousel']) ? (float) $medians['carousel'] : null;
        $reel = isset($medians['reel']) ? (float) $medians['reel'] : null;
        $can_change_mix = $n >= 3 && $measured >= 1;

        if ($still !== null && $can_change_mix) {
            if ($still < 30) {
                $verdicts[] = 'Single stills median ' . (string) (int) $still . ' views. Instagram is treating them as a news-card dump.';
                if ($tweaks['single'] > 0) {
                    $shift = $tweaks['single'];
                    $tweaks['single'] = 0;
                    $tweaks['carousel'] += $shift;
                    $tweaks['mix_changed'] = 1;
                    $tweaks['caption'] = 'Last week’s stills got almost no distribution. Keep photo-first overlays, a 3–6 word named hook, and a caption whose first line is the feed preview.';
                    $verdicts[] = 'Next mix moves those single slots into carousels. Photo-first overlay stays.';
                }
            } elseif ($carousel !== null && $carousel >= max(50, $still * 2) && $tweaks['single'] > 0) {
                $verdicts[] = 'Carousels median ' . (string) (int) $carousel . ' views vs stills ' . (string) (int) $still . '. Prefer swipe packs.';
                $shift = (int) ceil($tweaks['single'] / 2);
                $tweaks['single'] -= $shift;
                $tweaks['carousel'] += $shift;
                $tweaks['mix_changed'] = 1;
            } elseif ($still >= 50) {
                $verdicts[] = 'Single stills median ' . (string) (int) $still . ' views — keep the current still mix.';
            }
        }

        if ($reel !== null && $reel >= 3 * max((float) ($still ?? 0), (float) ($carousel ?? 0), 1)) {
            $verdicts[] = 'Reels median ' . (string) (int) $reel . ' views, well above stills. Raise Reels per day on Social settings if the video budget allows. Not applied automatically because Reels spend Fal.';
        }

        if ($tweaks['caption'] === '' && $still !== null && $still < 80) {
            $tweaks['caption'] = 'Lead with a named change on the first caption line. Keep the photograph, not a news screenshot.';
        }

        $clamped = $this->clamp_social_mix_counts(array(
            'reel' => $tweaks['reel'],
            'carousel' => $tweaks['carousel'],
            'collage' => $tweaks['collage'],
            'single' => $tweaks['single'],
        ), (int) ($settings['social_daily_limit'] ?? 10));
        $tweaks['reel'] = $clamped['reel'];
        $tweaks['carousel'] = $clamped['carousel'];
        $tweaks['collage'] = $clamped['collage'];
        $tweaks['single'] = $clamped['single'];
        if ($tweaks['reel'] !== $limits['reel'] || $tweaks['carousel'] !== $limits['carousel'] || $tweaks['collage'] !== $limits['collage'] || $tweaks['single'] !== $limits['single']) {
            $tweaks['mix_changed'] = 1;
        }

        $source_note = $measured > 0
            ? 'Instagram Graph insights (impressions, plays, or views). Site reads are first-party article counts, not Instagram views.'
            : 'Instagram insights were unavailable. Do not treat site reads as Instagram views.';

        return array(
            'posts' => $n,
            'measured' => $measured,
            'unavailable' => $unavailable,
            'medians' => $medians,
            'verdicts' => $verdicts,
            'tweaks' => $tweaks,
            'samples' => $samples,
            'source_note' => $source_note,
        );
    }

    private function social_review_median($values) {
        $values = array_values(array_map('intval', (array) $values));
        $n = count($values);
        if ($n < 1) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $mid = (int) floor($n / 2);
        if ($n % 2 === 1) {
            return (float) $values[$mid];
        }
        return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    private function social_review_apply_mix_limits($mix, $settings = array()) {
        $mix = is_array($mix) ? $mix : array();
        $settings = is_array($settings) ? $settings : array();
        if (empty($settings['social_review_apply_mix'])) {
            return $mix;
        }
        $review = $this->social_review_load();
        if (!$this->social_review_is_fresh($review) || empty($review['tweaks']['mix_changed'])) {
            return $mix;
        }
        $tweaks = (array) $review['tweaks'];
        $next = array(
            'reel' => (int) ($mix['reel'] ?? 0),
            'carousel' => (int) ($tweaks['carousel'] ?? ($mix['carousel'] ?? 0)),
            'collage' => (int) ($mix['collage'] ?? 0),
            'single' => (int) ($tweaks['single'] ?? ($mix['single'] ?? 0)),
        );
        return $this->clamp_social_mix_counts($next, (int) ($settings['social_daily_limit'] ?? 10));
    }

    private function social_review_prompt_brief() {
        $review = $this->social_review_load();
        if (!$this->social_review_is_fresh($review)) {
            return '';
        }
        $caption = trim((string) ($review['tweaks']['caption'] ?? ''));
        $verdicts = array();
        foreach ((array) ($review['verdicts'] ?? array()) as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $verdicts[] = $line;
            }
            if (count($verdicts) >= 2) {
                break;
            }
        }
        $parts = array_filter(array($caption, implode(' ', $verdicts)));
        if (!$parts) {
            return '';
        }
        return $this->clip_social_text(implode(' ', $parts), 280);
    }

    private function social_review_row_views($row) {
        $insights = json_decode((string) ($row['insights_json'] ?? ''), true);
        if (!is_array($insights)) {
            return array();
        }
        return $insights;
    }

    private function render_social_review_card() {
        $review = $this->social_review_load();
        $settings = $this->settings();
        $fresh = $this->social_review_is_fresh($review);
        $apply = !empty($settings['social_review_apply_mix']);
        echo '<div class="card" style="max-width:920px;margin:16px 0;padding:16px 20px;">';
        echo '<h2 style="margin-top:0;">Last 7 days</h2>';
        if (!$fresh) {
            echo '<p>No review yet. Refresh after Instagram posts have been live long enough for Graph insights (often a few hours).</p>';
        } else {
            $posts = (int) ($review['posts'] ?? 0);
            $measured = (int) ($review['measured'] ?? 0);
            echo '<p>' . esc_html((string) $posts) . ' Instagram post' . ($posts === 1 ? '' : 's') . ' · ' . esc_html((string) $measured) . ' with platform views.';
            $medians = (array) ($review['medians'] ?? array());
            $bits = array();
            foreach (array('single' => 'stills', 'carousel' => 'carousels', 'reel' => 'Reels', 'collage' => 'collages') as $mode => $label) {
                if (isset($medians[$mode]) && $medians[$mode] !== null) {
                    $bits[] = $label . ' median ' . (string) (int) $medians[$mode];
                }
            }
            if ($bits) {
                echo ' ' . esc_html(implode(', ', $bits)) . '.';
            }
            echo '</p>';
            echo '<ul style="margin:8px 0 8px 18px;">';
            foreach ((array) ($review['verdicts'] ?? array()) as $line) {
                echo '<li>' . esc_html((string) $line) . '</li>';
            }
            echo '</ul>';
            $tweaks = (array) ($review['tweaks'] ?? array());
            if (!empty($tweaks['mix_changed'])) {
                echo '<p><strong>Next mix:</strong> ' . esc_html((string) ((int) ($tweaks['reel'] ?? 0))) . ' Reels, '
                    . esc_html((string) ((int) ($tweaks['carousel'] ?? 0))) . ' carousels, '
                    . esc_html((string) ((int) ($tweaks['collage'] ?? 0))) . ' collages, '
                    . esc_html((string) ((int) ($tweaks['single'] ?? 0))) . ' singles.';
                echo $apply
                    ? ' Applied to today’s queue (Reels were not raised).'
                    : ' Not applied — turn on “Apply last-7-day mix tweaks” in Social settings.';
                echo '</p>';
            }
            if (!empty($review['source_note'])) {
                echo '<p class="description">' . esc_html((string) $review['source_note']) . '</p>';
            }
            $at = (int) ($review['generated_at'] ?? 0);
            if ($at > 0) {
                echo '<p class="description">Reviewed ' . esc_html(gmdate('Y-m-d H:i', $at)) . ' UTC.</p>';
            }
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0 0;">';
        echo '<input type="hidden" name="action" value="tr_ai_wire_run_social_review">';
        wp_nonce_field('tr_ai_wire_run_social_review');
        submit_button('Refresh last 7 days now', 'secondary', 'submit', false);
        echo ' <span class="description">Pulls Instagram insights for published posts, then decides mix and caption tweaks for the next stills. Does not spend image budget.</span>';
        echo '</form></div>';
    }
}
