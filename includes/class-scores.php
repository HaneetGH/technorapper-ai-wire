<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Scores {
    private function score_filters($filters = array()) {
        $ranges = array('7d', '30d', '90d', 'month', 'year', 'all');
        $groups = array('day', 'week', 'month');
        $scopes = array('posts', 'published', 'reviewed', 'quarantined');
        $filters = is_array($filters) ? $filters : array();
        $range = sanitize_key($filters['range'] ?? '30d');
        $group = sanitize_key($filters['group'] ?? 'day');
        $scope = sanitize_key($filters['scope'] ?? 'posts');
        return array(
            'range' => in_array($range, $ranges, true) ? $range : '30d',
            'group' => in_array($group, $groups, true) ? $group : 'day',
            'scope' => in_array($scope, $scopes, true) ? $scope : 'posts',
            'now' => isset($filters['now']) ? (string) $filters['now'] : '',
        );
    }

    private function score_window($filters) {
        $now = $filters['now'] !== '' ? strtotime($filters['now'] . ' UTC') : time();
        if ($now === false) {
            $now = time();
        }
        $end = gmdate('Y-m-d 23:59:59', $now);
        $range = $filters['range'];
        if ($range === 'all') {
            return array('', $end, $now);
        }
        if ($range === 'month') {
            return array(gmdate('Y-m-01 00:00:00', $now), $end, $now);
        }
        if ($range === 'year') {
            return array(gmdate('Y-01-01 00:00:00', $now), $end, $now);
        }
        $days = $range === '7d' ? 6 : ($range === '90d' ? 89 : 29);
        return array(gmdate('Y-m-d 00:00:00', $now - ($days * DAY_IN_SECONDS)), $end, $now);
    }

    private function score_bucket_key($datetime, $group) {
        $ts = strtotime((string) $datetime . ' UTC');
        if ($ts === false) {
            return '';
        }
        if ($group === 'month') {
            return gmdate('Y-m', $ts);
        }
        if ($group === 'week') {
            return gmdate('o-\WW', $ts);
        }
        return gmdate('Y-m-d', $ts);
    }

    private function score_bucket_keys($start, $end, $group, $now) {
        if ($start === '') {
            $start = gmdate('Y-m-d 00:00:00', $now - (89 * DAY_IN_SECONDS));
        }
        $keys = array();
        $ts = strtotime(substr($start, 0, 10) . ' 00:00:00 UTC');
        $end_ts = strtotime(substr($end, 0, 10) . ' 00:00:00 UTC');
        if ($ts === false || $end_ts === false) {
            return $keys;
        }
        while ($ts <= $end_ts) {
            $keys[] = $this->score_bucket_key(gmdate('Y-m-d H:i:s', $ts), $group);
            if ($group === 'month') {
                $ts = strtotime('+1 month', $ts);
            } elseif ($group === 'week') {
                $ts = strtotime('+1 week', $ts);
            } else {
                $ts = strtotime('+1 day', $ts);
            }
            if (count($keys) > 366) {
                break;
            }
        }
        return array_values(array_unique($keys));
    }

    private function extract_review_score($json) {
        $review = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($review) || !isset($review['score']) || !is_numeric($review['score'])) {
            return null;
        }
        return max(0, min(100, (int) $review['score']));
    }

    private function extract_seo_score($json) {
        $rewrite = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($rewrite) || !is_array($rewrite['seo'] ?? null) || !isset($rewrite['seo']['seo_score']) || !is_numeric($rewrite['seo']['seo_score'])) {
            return null;
        }
        return max(0, min(100, (int) $rewrite['seo']['seo_score']));
    }

    private function score_report($filters = array()) {
        global $wpdb;
        $filters = $this->score_filters($filters);
        $s = $this->settings();
        list($start, $end, $now) = $this->score_window($filters);
        $where = array('review_json IS NOT NULL', "review_json <> ''");
        $args = array();
        if ($start !== '') {
            $where[] = 'created_at BETWEEN %s AND %s';
            $args[] = $start;
            $args[] = $end;
        } else {
            $where[] = 'created_at <= %s';
            $args[] = $end;
        }
        if ($filters['scope'] === 'posts') {
            $where[] = "status IN ('draft', 'published')";
        } elseif ($filters['scope'] === 'published') {
            $where[] = "status = 'published'";
        } elseif ($filters['scope'] === 'quarantined') {
            $where[] = "status = 'quarantined'";
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status, created_at, source_title, wp_post_id, review_json, rewrite_json FROM {$wpdb->prefix}tr_ai_wire_items WHERE " . implode(' AND ', $where) . ' ORDER BY created_at ASC LIMIT 5000',
                $args
            ),
            ARRAY_A
        );
        $min = (int) $s['draft_min_score'];
        $review_sum = 0;
        $seo_sum = 0;
        $seo_count = 0;
        $above = 0;
        $low = null;
        $high = null;
        $distribution = array(
            '0-49' => 0,
            '50-69' => 0,
            '70-84' => 0,
            '85-100' => 0,
        );
        $buckets = array();
        $items = array();
        foreach ((array) $rows as $row) {
            $score = $this->extract_review_score($row['review_json'] ?? '');
            if ($score === null) {
                continue;
            }
            $seo = $this->extract_seo_score($row['rewrite_json'] ?? '');
            $review_sum += $score;
            $above += $score >= $min ? 1 : 0;
            $low = $low === null ? $score : min($low, $score);
            $high = $high === null ? $score : max($high, $score);
            if ($seo !== null) {
                $seo_sum += $seo;
                $seo_count++;
            }
            if ($score < 50) {
                $distribution['0-49']++;
            } elseif ($score < 70) {
                $distribution['50-69']++;
            } elseif ($score < 85) {
                $distribution['70-84']++;
            } else {
                $distribution['85-100']++;
            }
            $key = $this->score_bucket_key($row['created_at'], $filters['group']);
            if (!isset($buckets[$key])) {
                $buckets[$key] = array('count' => 0, 'review_sum' => 0, 'seo_sum' => 0, 'seo_count' => 0, 'above' => 0);
            }
            $buckets[$key]['count']++;
            $buckets[$key]['review_sum'] += $score;
            $buckets[$key]['above'] += $score >= $min ? 1 : 0;
            if ($seo !== null) {
                $buckets[$key]['seo_sum'] += $seo;
                $buckets[$key]['seo_count']++;
            }
            $items[] = array(
                'id' => (int) $row['id'],
                'title' => (string) $row['source_title'],
                'status' => (string) $row['status'],
                'created_at' => (string) $row['created_at'],
                'score' => $score,
                'seo_score' => $seo,
                'wp_post_id' => (int) ($row['wp_post_id'] ?? 0),
            );
        }
        $count = count($items);
        $series = array();
        foreach ($this->score_bucket_keys($start, $end, $filters['group'], $now) as $key) {
            $bucket = $buckets[$key] ?? array('count' => 0, 'review_sum' => 0, 'seo_sum' => 0, 'seo_count' => 0, 'above' => 0);
            $series[] = array(
                'label' => $key,
                'count' => (int) $bucket['count'],
                'average' => $bucket['count'] ? round($bucket['review_sum'] / $bucket['count'], 1) : null,
                'seo_average' => $bucket['seo_count'] ? round($bucket['seo_sum'] / $bucket['seo_count'], 1) : null,
                'above_min' => (int) $bucket['above'],
            );
        }
        return array(
            'filters' => $filters,
            'min_score' => $min,
            'count' => $count,
            'average' => $count ? round($review_sum / $count, 1) : 0.0,
            'seo_average' => $seo_count ? round($seo_sum / $seo_count, 1) : null,
            'above_min' => $above,
            'above_min_rate' => $count ? round(($above / $count) * 100, 1) : 0.0,
            'low' => $low,
            'high' => $high,
            'distribution' => $distribution,
            'series' => $series,
            'recent' => array_slice(array_reverse($items), 0, 15),
        );
    }

    private function score_chart_allowed_html() {
        $svg = array(
            'viewbox' => true,
            'viewBox' => true,
            'role' => true,
            'aria-label' => true,
            'style' => true,
            'xmlns' => true,
        );
        return array(
            'svg' => $svg,
            'line' => array(
                'x1' => true,
                'y1' => true,
                'x2' => true,
                'y2' => true,
                'stroke' => true,
                'stroke-dasharray' => true,
            ),
            'text' => array(
                'x' => true,
                'y' => true,
                'font-size' => true,
                'fill' => true,
                'text-anchor' => true,
            ),
            'rect' => array(
                'x' => true,
                'y' => true,
                'width' => true,
                'height' => true,
                'fill' => true,
                'opacity' => true,
            ),
            'title' => array(),
            'circle' => array(
                'cx' => true,
                'cy' => true,
                'r' => true,
                'fill' => true,
            ),
            'polyline' => array(
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'points' => true,
            ),
        );
    }

    private function render_score_chart($report) {
        $series = $report['series'];
        $width = 920;
        $height = 280;
        $left = 44;
        $right = 16;
        $top = 18;
        $bottom = 42;
        $inner_w = $width - $left - $right;
        $inner_h = $height - $top - $bottom;
        $n = count($series);
        $max_count = 1;
        foreach ($series as $point) {
            $max_count = max($max_count, (int) $point['count']);
        }
        $svg = '<svg viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '" role="img" aria-label="Average review score chart" style="width:100%;height:auto;background:#fff;border:1px solid #dcdcde;border-radius:4px;">';
        foreach (array(0, 25, 50, 75, 100) as $tick) {
            $y = $top + $inner_h * (1 - ($tick / 100));
            $svg .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($width - $right) . '" y2="' . $y . '" stroke="#dcdcde" />';
            $svg .= '<text x="8" y="' . ($y + 4) . '" font-size="11" fill="#646970">' . (int) $tick . '</text>';
        }
        $threshold = max(0, min(100, (int) $report['min_score']));
        $threshold_y = $top + $inner_h * (1 - ($threshold / 100));
        $svg .= '<line x1="' . $left . '" y1="' . $threshold_y . '" x2="' . ($width - $right) . '" y2="' . $threshold_y . '" stroke="#dba617" stroke-dasharray="5 4" />';
        $svg .= '<text x="' . ($width - $right - 4) . '" y="' . ($threshold_y - 6) . '" font-size="11" fill="#996800" text-anchor="end">min ' . (int) $threshold . '</text>';
        if ($n === 0) {
            $svg .= '<text x="' . ($width / 2) . '" y="' . ($height / 2) . '" text-anchor="middle" fill="#646970">No scored items in this range.</text></svg>';
            return $svg;
        }
        $x_at = function ($i) use ($n, $left, $inner_w) {
            return $n === 1 ? $left + ($inner_w / 2) : $left + ($i * $inner_w / ($n - 1));
        };
        foreach ($series as $i => $point) {
            $bar_h = ((int) $point['count'] / $max_count) * ($inner_h * 0.28);
            $bar_w = max(4, ($inner_w / max(1, $n)) * 0.45);
            $x = $x_at($i) - ($bar_w / 2);
            $y = $top + $inner_h - $bar_h;
            $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bar_w . '" height="' . $bar_h . '" fill="#c3c4c7" opacity="0.55"><title>' . esc_attr($point['label'] . ': ' . (int) $point['count'] . ' items') . '</title></rect>';
        }
        $review_points = array();
        $seo_points = array();
        foreach ($series as $i => $point) {
            $x = $x_at($i);
            if ($point['average'] !== null) {
                $y = $top + $inner_h * (1 - ($point['average'] / 100));
                $review_points[] = $x . ',' . $y;
                $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="3.5" fill="#2271b1"><title>' . esc_attr($point['label'] . ' review avg ' . $point['average']) . '</title></circle>';
            }
            if ($point['seo_average'] !== null) {
                $y = $top + $inner_h * (1 - ($point['seo_average'] / 100));
                $seo_points[] = $x . ',' . $y;
                $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="3" fill="#00a32a"><title>' . esc_attr($point['label'] . ' SEO avg ' . $point['seo_average']) . '</title></circle>';
            }
            if ($i === 0 || $i === $n - 1 || ($n <= 14) || $i % max(1, (int) ceil($n / 8)) === 0) {
                $svg .= '<text x="' . $x . '" y="' . ($height - 12) . '" font-size="10" fill="#646970" text-anchor="middle">' . esc_html($point['label']) . '</text>';
            }
        }
        if (count($review_points) > 1) {
            $svg .= '<polyline fill="none" stroke="#2271b1" stroke-width="2.5" points="' . esc_attr(implode(' ', $review_points)) . '" />';
        }
        if (count($seo_points) > 1) {
            $svg .= '<polyline fill="none" stroke="#00a32a" stroke-width="2" points="' . esc_attr(implode(' ', $seo_points)) . '" />';
        }
        $svg .= '</svg>';
        return $svg;
    }
}
