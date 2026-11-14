<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Errors {
    private function error_kind_labels() {
        return array(
            'publish_review' => array('Post publish', 'Review hold'),
            'publish_gate' => array('Post publish', 'Pre-publish hold'),
            'publish_seo' => array('Post publish', 'SEO hold'),
            'publish_image' => array('Post publish', 'Missing image'),
            'publish_rewrite' => array('Post publish', 'Empty rewrite'),
            'publish_budget' => array('Post publish', 'Budget or daily cap'),
            'publish_desk' => array('Post publish', 'Desk mix wait'),
            'publish_duplicate' => array('Post publish', 'Near duplicate'),
            'publish_api' => array('Post publish', 'Writer or API'),
            'publish_other' => array('Post publish', 'Other publish error'),
            'social_reel' => array('Social', 'Reel build'),
            'social_quota' => array('Social', 'Platform quota'),
            'social_token' => array('Social', 'Account or token'),
            'social_policy' => array('Social', 'Policy hold'),
            'social_post' => array('Social', 'Platform publish'),
            'social_x_credits' => array('Social', 'X API credits'),
            'social_credits' => array('Social', 'Image credits'),
            'social_review' => array('Social', 'Needs review'),
            'social_other' => array('Social', 'Other social error'),
        );
    }

    private function error_kind($area, $status, $message) {
        $text = strtolower(trim(preg_replace('/\s+/', ' ', (string) $message)));
        $status = sanitize_key((string) $status);
        if ($area === 'social') {
            if ($text === '' && $status === 'needs_review') {
                return 'social_review';
            }
            if (strpos($text, 'held: watch this reel') === 0 || strpos($text, 'policy') !== false || strpos($text, 'moderation') !== false) {
                return 'social_policy';
            }
            if (preg_match('/ffmpeg|reel build|could not assemble|talking host|lesson card|b-roll|fal could|fal answered/', $text)) {
                return 'social_reel';
            }
            if (strpos($text, 'quota') !== false || strpos($text, 'content_publishing_limit') !== false || strpos($text, 'publishing limit') !== false) {
                return 'social_quota';
            }
            if (preg_match('/credits to fulfill|creditsdepleted|does not have any credits|x api credit/', $text)) {
                return 'social_x_credits';
            }
            if (strpos($text, 'token') !== false || strpos($text, 'oauth') !== false || strpos($text, 'unauthorized') !== false || strpos($text, 'permission') !== false) {
                return 'social_token';
            }
            if (preg_match('/image budget|credits depleted|insufficient credits|out of credits/', $text)) {
                return 'social_credits';
            }
            if (preg_match('/media id|post id|container|permalink|rejected the/', $text)) {
                return 'social_post';
            }
            return 'social_other';
        }
        if (strpos($text, 'comics desk') !== false || strpos($text, 'tech desk') !== false) {
            return 'publish_desk';
        }
        if ($status === 'waiting_budget' || strpos($text, 'daily cap') !== false || strpos($text, 'budget') !== false || strpos($text, 'token limit') !== false) {
            return 'publish_budget';
        }
        if (strpos($text, 'image') !== false || strpos($text, 'thumbnail') !== false) {
            return 'publish_image';
        }
        if (strpos($text, 'empty title') !== false || strpos($text, 'did not include a title') !== false || strpos($text, 'generation output') !== false) {
            return 'publish_rewrite';
        }
        if (strpos($text, 'pre-publish') !== false) {
            return 'publish_gate';
        }
        if (strpos($text, 'seo') !== false) {
            return 'publish_seo';
        }
        if (strpos($text, 'near-duplicate') !== false || strpos($text, 'near duplicate') !== false) {
            return 'publish_duplicate';
        }
        if (preg_match('/openai|timed out|rate limit|\bapi\b/', $text)) {
            return 'publish_api';
        }
        if (strpos($text, 'editorial review') !== false || strpos($text, 'review score') !== false || strpos($text, 'story-value') !== false || strpos($text, 'story value') !== false || $status === 'quarantined') {
            return 'publish_review';
        }
        return 'publish_other';
    }

    private function error_report($range = '30d') {
        global $wpdb;
        $ranges = array('7d', '30d', '90d', 'month', 'year', 'all');
        $range = in_array($range, $ranges, true) ? $range : '30d';
        $window = $this->score_window(array('range' => $range, 'now' => ''));
        $start = $window[0];
        $end = $window[1];
        $now = $window[2];
        $group = in_array($range, array('90d', 'year', 'all'), true) ? ($range === '7d' ? 'day' : ($range === '90d' ? 'week' : 'month')) : 'day';
        $labels = $this->error_kind_labels();
        $counts = array();
        foreach ($labels as $key => $label) {
            $counts[$key] = 0;
        }
        $platforms = array();
        $series_counts = array();
        $recent = array();
        $samples = array();
        $publish_where = "status IN ('error','quarantined','retry_wait','waiting_budget')";
        $params = array();
        if ($start !== '') {
            $publish_where .= ' AND updated_at >= %s AND updated_at <= %s';
            $params[] = $start;
            $params[] = $end;
        }
        $publish_sql = "SELECT id, status, error_text, source_title, wp_post_id, updated_at FROM {$this->table} WHERE {$publish_where} ORDER BY updated_at DESC LIMIT 1500";
        $publish_rows = $params ? $wpdb->get_results($wpdb->prepare($publish_sql, $params), ARRAY_A) : $wpdb->get_results($publish_sql, ARRAY_A);
        foreach ((array) $publish_rows as $row) {
            $this->error_tally_row('publish', $row, $counts, $platforms, $series_counts, $recent, $samples, $group);
        }
        $social_where = "(status = 'needs_review' OR (status IN ('error','queued','processing') AND error_text IS NOT NULL AND error_text <> ''))";
        $social_params = array();
        if ($start !== '') {
            $social_where .= ' AND updated_at >= %s AND updated_at <= %s';
            $social_params[] = $start;
            $social_params[] = $end;
        }
        $social_sql = "SELECT id, post_id, platform, status, error_text, updated_at FROM {$wpdb->prefix}tr_ai_wire_social WHERE {$social_where} ORDER BY updated_at DESC LIMIT 1500";
        $social_rows = $social_params ? $wpdb->get_results($wpdb->prepare($social_sql, $social_params), ARRAY_A) : $wpdb->get_results($social_sql, ARRAY_A);
        foreach ((array) $social_rows as $row) {
            $this->error_tally_row('social', $row, $counts, $platforms, $series_counts, $recent, $samples, $group);
        }
        $kinds = array();
        $publish_total = 0;
        $social_total = 0;
        foreach ($labels as $key => $label) {
            $count = (int) $counts[$key];
            if (strpos($key, 'publish_') === 0) {
                $publish_total += $count;
            } else {
                $social_total += $count;
            }
            $sample = '';
            if (!empty($samples[$key]) && is_array($samples[$key])) {
                arsort($samples[$key]);
                $sample = (string) array_key_first($samples[$key]);
            }
            $kinds[] = array(
                'key' => $key,
                'area' => $label[0],
                'label' => $label[1],
                'count' => $count,
                'sample' => $sample,
            );
        }
        usort($kinds, function ($left, $right) {
            if ($left['count'] === $right['count']) {
                return strcmp($left['label'], $right['label']);
            }
            return $right['count'] - $left['count'];
        });
        usort($recent, function ($left, $right) {
            return strcmp($right['updated_at'], $left['updated_at']);
        });
        $keys = $this->score_bucket_keys($start, $end, $group, $now);
        $series = array();
        foreach ($keys as $key) {
            $bucket = isset($series_counts[$key]) ? $series_counts[$key] : array('publish' => 0, 'social' => 0);
            $series[] = array(
                'label' => $group === 'day' ? substr($key, 5) : $key,
                'publish' => (int) $bucket['publish'],
                'social' => (int) $bucket['social'],
            );
        }
        $top = null;
        foreach ($kinds as $kind) {
            if ($kind['count'] > 0) {
                $top = $kind;
                break;
            }
        }
        return array(
            'range' => $range,
            'group' => $group,
            'publish_total' => $publish_total,
            'social_total' => $social_total,
            'total' => $publish_total + $social_total,
            'kinds' => $kinds,
            'platforms' => $platforms,
            'series' => $series,
            'recent' => array_slice($recent, 0, 12),
            'top' => $top,
        );
    }

    private function error_tally_row($area, $row, &$counts, &$platforms, &$series_counts, &$recent, &$samples, $group) {
        $message = trim((string) ($row['error_text'] ?? ''));
        $kind = $this->error_kind($area, $row['status'] ?? '', $message);
        if (!isset($counts[$kind])) {
            $kind = $area === 'social' ? 'social_other' : 'publish_other';
        }
        $counts[$kind]++;
        $snippet = $message;
        if (function_exists('mb_substr')) {
            $snippet = mb_substr($snippet, 0, 180);
        } else {
            $snippet = substr($snippet, 0, 180);
        }
        if ($snippet !== '') {
            if (!isset($samples[$kind]) || !is_array($samples[$kind])) {
                $samples[$kind] = array();
            }
            if (!isset($samples[$kind][$snippet])) {
                $samples[$kind][$snippet] = 0;
            }
            $samples[$kind][$snippet]++;
        }
        $bucket = $this->score_bucket_key($row['updated_at'] ?? '', $group);
        if ($bucket !== '') {
            if (!isset($series_counts[$bucket])) {
                $series_counts[$bucket] = array('publish' => 0, 'social' => 0);
            }
            $series_counts[$bucket][$area]++;
        }
        $platform = '';
        if ($area === 'social') {
            $platform = self::social_platform_label($row['platform'] ?? '');
            if ($platform === '') {
                $platform = 'Social';
            }
            if (!isset($platforms[$platform])) {
                $platforms[$platform] = 0;
            }
            $platforms[$platform]++;
        }
        $title = $area === 'social' ? '' : trim((string) ($row['source_title'] ?? ''));
        $recent[] = array(
            'area' => $area === 'social' ? 'Social' : 'Post publish',
            'platform' => $platform,
            'kind' => $kind,
            'status' => (string) ($row['status'] ?? ''),
            'title' => $title,
            'post_id' => (int) ($row['post_id'] ?? $row['wp_post_id'] ?? 0),
            'message' => $message !== '' ? $message : 'No detail recorded.',
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        );
    }

    private function render_error_bars($report) {
        $max = 1;
        foreach ($report['kinds'] as $kind) {
            $max = max($max, (int) $kind['count']);
        }
        $html = '';
        $shown = 0;
        foreach ($report['kinds'] as $kind) {
            if ((int) $kind['count'] < 1) {
                continue;
            }
            $shown++;
            $width = max(2, (int) round(((int) $kind['count'] / $max) * 100));
            $color = $kind['area'] === 'Social' ? '#b26200' : '#d63638';
            $html .= '<div style="display:grid;grid-template-columns:220px 1fr 48px;gap:10px;align-items:center;margin:0 0 8px;">';
            $html .= '<div><strong>' . esc_html($kind['label']) . '</strong><br><span class="description">' . esc_html($kind['area']) . '</span></div>';
            $html .= '<div style="background:#f0f0f1;border-radius:3px;height:18px;" title="' . esc_attr($kind['label'] . ': ' . (int) $kind['count']) . '"><div style="width:' . $width . '%;height:18px;background:' . esc_attr($color) . ';border-radius:3px;"></div></div>';
            $html .= '<div>' . esc_html(number_format_i18n((int) $kind['count'])) . '</div>';
            $html .= '</div>';
            if (!empty($kind['sample'])) {
                $html .= '<p class="description" style="margin:-4px 0 12px;">' . esc_html($kind['sample']) . '</p>';
            }
        }
        if (!$shown) {
            $html = '<p>No publish or social errors in this range.</p>';
        }
        return $html;
    }

    private function render_error_trend($report) {
        $series = $report['series'];
        $width = 920;
        $height = 220;
        $left = 36;
        $right = 16;
        $top = 16;
        $bottom = 36;
        $inner_w = $width - $left - $right;
        $inner_h = $height - $top - $bottom;
        $n = count($series);
        $max = 1;
        foreach ($series as $point) {
            $max = max($max, (int) $point['publish'] + (int) $point['social']);
        }
        $svg = '<svg viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '" role="img" aria-label="Errors over time" style="width:100%;height:auto;background:#fff;border:1px solid #dcdcde;border-radius:4px;">';
        if ($n === 0) {
            $svg .= '<text x="' . ($width / 2) . '" y="' . ($height / 2) . '" text-anchor="middle" fill="#646970">No errors in this range.</text></svg>';
            return $svg;
        }
        $slot = $inner_w / max(1, $n);
        foreach ($series as $i => $point) {
            $publish = (int) $point['publish'];
            $social = (int) $point['social'];
            $x = $left + ($i * $slot) + ($slot * 0.2);
            $bar_w = max(4, $slot * 0.6);
            $publish_h = ($publish / $max) * $inner_h;
            $social_h = ($social / $max) * $inner_h;
            $base = $top + $inner_h;
            $svg .= '<rect x="' . $x . '" y="' . ($base - $publish_h) . '" width="' . $bar_w . '" height="' . $publish_h . '" fill="#d63638"><title>' . esc_attr($point['label'] . ' post publish ' . $publish) . '</title></rect>';
            $svg .= '<rect x="' . $x . '" y="' . ($base - $publish_h - $social_h) . '" width="' . $bar_w . '" height="' . $social_h . '" fill="#b26200"><title>' . esc_attr($point['label'] . ' social ' . $social) . '</title></rect>';
            if ($i === 0 || $i === $n - 1 || $n <= 14 || $i % max(1, (int) ceil($n / 8)) === 0) {
                $svg .= '<text x="' . ($x + ($bar_w / 2)) . '" y="' . ($height - 10) . '" font-size="10" fill="#646970" text-anchor="middle">' . esc_html($point['label']) . '</text>';
            }
        }
        $svg .= '</svg>';
        return $svg;
    }

    private function error_chart_allowed_html() {
        return array(
            'svg' => array(
                'viewbox' => true,
                'viewBox' => true,
                'role' => true,
                'aria-label' => true,
                'style' => true,
            ),
            'rect' => array(
                'x' => true,
                'y' => true,
                'width' => true,
                'height' => true,
                'fill' => true,
            ),
            'text' => array(
                'x' => true,
                'y' => true,
                'font-size' => true,
                'fill' => true,
                'text-anchor' => true,
            ),
            'title' => array(),
            'div' => array(
                'style' => true,
                'title' => true,
            ),
            'strong' => array(),
            'br' => array(),
            'span' => array('class' => true),
            'p' => array(),
        );
    }

    public function errors_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $range = sanitize_key(wp_unslash($_GET['range'] ?? '30d'));
        $report = $this->error_report($range);
        $range_labels = array(
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
            'month' => 'This month',
            'year' => 'This year',
            'all' => 'All time',
        );
        $labels = $this->error_kind_labels();
        $top = is_array($report['top']) ? $report['top'] : null;
        ?>
        <div class="wrap">
            <h1>AI Wire — Errors</h1>
            <p>Open failures on the website queue and the social queue. The longest bar is the error you are hitting most often. Red is post publish. Orange is social (Instagram, X, and YouTube, including Reels).</p>
            <form method="get" style="margin:16px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
                <input type="hidden" name="page" value="tr-ai-wire-errors">
                <p><label for="tr-error-range"><strong>Range</strong></label><br>
                    <select id="tr-error-range" name="range"><?php foreach ($range_labels as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($report['range'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?></select></p>
                <p><?php submit_button('Update graph', 'secondary', 'submit', false); ?></p>
            </form>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:960px;margin:12px 0 20px;">
                <div class="card" style="margin:0;padding:14px 16px;"><p class="description" style="margin:0;">Open errors</p><p style="font-size:28px;margin:4px 0 0;"><strong><?php echo esc_html(number_format_i18n($report['total'])); ?></strong></p></div>
                <div class="card" style="margin:0;padding:14px 16px;"><p class="description" style="margin:0;">Post publish</p><p style="font-size:28px;margin:4px 0 0;color:#d63638;"><strong><?php echo esc_html(number_format_i18n($report['publish_total'])); ?></strong></p></div>
                <div class="card" style="margin:0;padding:14px 16px;"><p class="description" style="margin:0;">Social</p><p style="font-size:28px;margin:4px 0 0;color:#b26200;"><strong><?php echo esc_html(number_format_i18n($report['social_total'])); ?></strong></p></div>
                <div class="card" style="margin:0;padding:14px 16px;"><p class="description" style="margin:0;">Most common</p><p style="font-size:20px;margin:8px 0 0;"><strong><?php echo esc_html($top ? $top['label'] : 'None'); ?></strong></p><?php if ($top) : ?><p class="description" style="margin:4px 0 0;"><?php echo esc_html($top['area'] . ' · ' . number_format_i18n($top['count'])); ?></p><?php endif; ?></div>
            </div>
            <?php if ($report['platforms']) : ?>
                <p><?php
                    $bits = array();
                    arsort($report['platforms']);
                    foreach ($report['platforms'] as $name => $count) {
                        $bits[] = $name . ' ' . number_format_i18n((int) $count);
                    }
                    echo esc_html('Social by platform: ' . implode(' · ', $bits));
                ?></p>
            <?php endif; ?>
            <h2>Which error happens most</h2>
            <div style="max-width:960px;"><?php echo wp_kses($this->render_error_bars($report), $this->error_chart_allowed_html()); ?></div>
            <h2>Over time</h2>
            <p><span style="display:inline-block;width:12px;height:12px;background:#d63638;margin-right:6px;"></span> Post publish
                <span style="display:inline-block;width:12px;height:12px;background:#b26200;margin:0 6px 0 16px;"></span> Social</p>
            <div style="max-width:960px;"><?php echo wp_kses($this->render_error_trend($report), $this->error_chart_allowed_html()); ?></div>
            <h2>Latest open errors</h2>
            <table class="widefat striped" style="max-width:960px;">
                <thead><tr><th>When (UTC)</th><th>Area</th><th>Type</th><th>Item</th><th>Detail</th></tr></thead>
                <tbody>
                <?php if (empty($report['recent'])) : ?>
                    <tr><td colspan="5">No open errors in this range.</td></tr>
                <?php else : foreach ($report['recent'] as $item) :
                    $kind_label = isset($labels[$item['kind']]) ? $labels[$item['kind']][1] : $item['kind'];
                    $area = $item['area'];
                    if ($item['platform'] !== '') {
                        $area .= ' · ' . $item['platform'];
                    }
                    $title = $item['title'];
                    $edit_url = '';
                    if ($item['area'] === 'Social') {
                        $edit_url = admin_url('admin.php?page=tr-ai-wire-social');
                        $post = $item['post_id'] ? get_post($item['post_id']) : null;
                        if ($post instanceof WP_Post && $post->post_title !== '') {
                            $title = $post->post_title;
                        }
                    } elseif ($item['post_id']) {
                        $edit_url = get_edit_post_link($item['post_id']);
                    }
                    if ($title === '') {
                        $title = 'Untitled';
                    }
                    if (!$edit_url) {
                        $edit_url = admin_url('admin.php?page=' . ($item['area'] === 'Social' ? 'tr-ai-wire-social' : 'tr-ai-wire-queue'));
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($item['updated_at']); ?></td>
                        <td><?php echo esc_html($area); ?></td>
                        <td><?php echo esc_html($kind_label); ?></td>
                        <td><a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($title); ?></a></td>
                        <td><?php echo esc_html(wp_html_excerpt($item['message'], 180, '…')); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <p class="description">Counts stay on the current queue row. A story that later publishes drops out. <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-queue')); ?>">Website queue</a> · <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-social')); ?>">Social queue</a></p>
        </div>
        <?php
    }
}
