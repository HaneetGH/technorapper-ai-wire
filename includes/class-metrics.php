<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Metrics {
    private function append_run_log($summary) {
        $summary = is_array($summary) ? $summary : array();
        $log = get_option(self::RUN_LOG_OPTION, array());
        if (!is_array($log)) {
            $log = array();
        }
        $log[] = array(
            'at' => (int) ($summary['at'] ?? time()),
            'reason' => sanitize_key((string) ($summary['reason'] ?? '')),
            'processed' => (int) ($summary['processed'] ?? 0),
            'published' => (int) ($summary['published'] ?? 0),
            'drafts' => (int) ($summary['drafts'] ?? 0),
            'quarantined' => (int) ($summary['quarantined'] ?? 0),
            'skipped' => sanitize_text_field((string) ($summary['skipped'] ?? '')),
        );
        $cutoff = time() - (45 * DAY_IN_SECONDS);
        $kept = array();
        foreach ($log as $row) {
            if (!is_array($row) || (int) ($row['at'] ?? 0) < $cutoff) {
                continue;
            }
            $kept[] = $row;
        }
        if (count($kept) > 800) {
            $kept = array_slice($kept, -800);
        }
        update_option(self::RUN_LOG_OPTION, $kept, false);
    }

    private function remember_publish_path($post_id, $s) {
        $post_id = (int) $post_id;
        if ($post_id < 1) {
            return;
        }
        $manual = !empty($s['_admin_force_publish']) || get_post_status($post_id) !== 'publish';
        update_post_meta($post_id, '_tr_ai_wire_publish_path', $manual ? 'manual' : 'auto');
    }

    private function metrics_timezone() {
        if (function_exists('wp_timezone')) {
            $zone = wp_timezone();
            if ($zone instanceof DateTimeZone) {
                return $zone;
            }
        }
        return new DateTimeZone('UTC');
    }

    private function metrics_days($count, $now = null) {
        $zone = $this->metrics_timezone();
        $count = max(1, min(31, (int) $count));
        if (!$now instanceof DateTimeImmutable) {
            $now = new DateTimeImmutable('now', $zone);
        }
        $cursor = $now->setTimezone($zone)->setTime(0, 0, 0);
        $utc = new DateTimeZone('UTC');
        $days = array();
        for ($i = 0; $i < $count; $i++) {
            $next = $cursor->modify('+1 day');
            $label = $cursor->format('D j M');
            if ($i === 0) {
                $label = 'Today';
            } elseif ($i === 1) {
                $label = 'Yesterday';
            }
            $days[] = array(
                'key' => $cursor->format('Y-m-d'),
                'label' => $label,
                'start' => $cursor->setTimezone($utc)->format('Y-m-d H:i:s'),
                'end' => $next->setTimezone($utc)->format('Y-m-d H:i:s'),
                'published' => 0,
                'auto' => 0,
                'manual' => 0,
                'failed' => 0,
                'failures' => array(),
                'runs' => null,
            );
            $cursor = $cursor->modify('-1 day');
        }
        return $days;
    }

    private function metrics_day_index($days, $gmt) {
        $gmt = (string) $gmt;
        foreach ($days as $index => $day) {
            if ($gmt >= $day['start'] && $gmt < $day['end']) {
                return $index;
            }
        }
        return -1;
    }

    private function metrics_publish_path($post_id, $review_json, $queue_status, $post_status) {
        $path = sanitize_key((string) get_post_meta((int) $post_id, '_tr_ai_wire_publish_path', true));
        if ($path === 'auto' || $path === 'manual') {
            return $path;
        }
        $review = json_decode((string) $review_json, true);
        if (is_array($review) && !empty($review['admin_force_publish'])) {
            return 'manual';
        }
        if ($post_status === 'publish' && $queue_status === 'draft') {
            return 'manual';
        }
        return 'auto';
    }

    private function metrics_report($days_count = 14) {
        $days = $this->metrics_days($days_count);
        $first = $days[count($days) - 1];
        $last = $days[0];
        global $wpdb;
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_status, c.meta_value AS created_gmt, q.status AS queue_status, q.review_json, path.meta_value AS publish_path
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_tr_ai_wire_created_gmt'
             INNER JOIN {$this->table} q ON q.wp_post_id = p.ID
             LEFT JOIN {$wpdb->postmeta} path ON path.post_id = p.ID AND path.meta_key = '_tr_ai_wire_publish_path'
             WHERE p.post_status = 'publish' AND p.post_type = 'post'
               AND c.meta_value >= %s AND c.meta_value < %s",
            $first['start'],
            $last['end']
        ), ARRAY_A);
        foreach ((array) $posts as $row) {
            $index = $this->metrics_day_index($days, $row['created_gmt'] ?? '');
            if ($index < 0) {
                continue;
            }
            $days[$index]['published']++;
            $path = $this->metrics_publish_path($row['ID'], $row['review_json'] ?? '', $row['queue_status'] ?? '', $row['post_status'] ?? '');
            if (($row['publish_path'] ?? '') === 'auto' || ($row['publish_path'] ?? '') === 'manual') {
                $path = $row['publish_path'];
            }
            $days[$index][$path === 'manual' ? 'manual' : 'auto']++;
        }
        $holds = $wpdb->get_results($wpdb->prepare(
            "SELECT queue_item_id, reason, created_at FROM {$wpdb->prefix}" . self::QUARANTINE_TABLE_SUFFIX . " WHERE created_at >= %s AND created_at < %s",
            $first['start'],
            $last['end']
        ), ARRAY_A);
        $held_ids = array();
        foreach ((array) $holds as $row) {
            $index = $this->metrics_day_index($days, $row['created_at'] ?? '');
            if ($index < 0) {
                continue;
            }
            $days[$index]['failed']++;
            $kind = $this->error_kind('publish', 'quarantined', (string) ($row['reason'] ?? ''));
            if (!isset($days[$index]['failures'][$kind])) {
                $days[$index]['failures'][$kind] = 0;
            }
            $days[$index]['failures'][$kind]++;
            $held_ids[$index . ':' . (int) ($row['queue_item_id'] ?? 0)] = true;
        }
        $errors = $wpdb->get_results($wpdb->prepare(
            "SELECT id, error_text, updated_at FROM {$this->table} WHERE status = 'error' AND updated_at >= %s AND updated_at < %s",
            $first['start'],
            $last['end']
        ), ARRAY_A);
        foreach ((array) $errors as $row) {
            $index = $this->metrics_day_index($days, $row['updated_at'] ?? '');
            if ($index < 0 || isset($held_ids[$index . ':' . (int) ($row['id'] ?? 0)])) {
                continue;
            }
            $days[$index]['failed']++;
            $kind = $this->error_kind('publish', 'error', (string) ($row['error_text'] ?? ''));
            if (!isset($days[$index]['failures'][$kind])) {
                $days[$index]['failures'][$kind] = 0;
            }
            $days[$index]['failures'][$kind]++;
        }
        $labels = $this->error_kind_labels();
        $log = get_option(self::RUN_LOG_OPTION, array());
        $log_start = 0;
        if (is_array($log)) {
            foreach ($log as $row) {
                $at = (int) ($row['at'] ?? 0);
                if ($at > 0 && ($log_start === 0 || $at < $log_start)) {
                    $log_start = $at;
                }
            }
        }
        $logged_from = $log_start > 0 ? gmdate('Y-m-d H:i:s', $log_start) : '';
        foreach ($days as $index => $day) {
            if ($logged_from !== '' && $day['end'] > $logged_from) {
                $days[$index]['runs'] = 0;
            }
            $top_key = '';
            $top_count = 0;
            foreach ($day['failures'] as $key => $count) {
                if ($count > $top_count) {
                    $top_key = $key;
                    $top_count = $count;
                }
            }
            $days[$index]['top'] = $top_count > 0 && isset($labels[$top_key]) ? $labels[$top_key][1] : '';
            $days[$index]['top_count'] = $top_count;
            unset($days[$index]['failures']);
        }
        if (is_array($log)) {
            foreach ($log as $row) {
                $at = (int) ($row['at'] ?? 0);
                if ($at < 1) {
                    continue;
                }
                $index = $this->metrics_day_index($days, gmdate('Y-m-d H:i:s', $at));
                if ($index < 0) {
                    continue;
                }
                $days[$index]['runs'] = (int) $days[$index]['runs'] + 1;
            }
        }
        return array(
            'timezone' => $this->metrics_timezone()->getName(),
            'days' => $days,
            'logging' => $log_start > 0,
        );
    }

    public function metrics_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $count = absint(wp_unslash($_GET['days'] ?? 14));
        if (!in_array($count, array(7, 14, 30), true)) {
            $count = 14;
        }
        $report = $this->metrics_report($count);
        $today = $report['days'][0];
        ?>
        <div class="wrap">
            <h1>AI Wire — Metrics</h1>
            <p>Each row is one day in the site timezone (<code><?php echo esc_html($report['timezone']); ?></code>). Published is a live post this plugin created that day. Auto means the pipeline published it. Manual means Force publish, or a draft that was published by hand. Failed is a quarantine or a hard error recorded that day. Job runs are counted from this version forward; earlier days stay blank.</p>
            <form method="get" style="margin:16px 0;">
                <input type="hidden" name="page" value="tr-ai-wire-metrics">
                <label for="tr-metrics-days"><strong>Days</strong></label>
                <select id="tr-metrics-days" name="days">
                    <?php foreach (array(7 => '7 days', 14 => '14 days', 30 => '30 days') as $value => $label) : ?>
                        <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($count, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button('Update', 'secondary', 'submit', false); ?>
            </form>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;max-width:980px;margin:0 0 20px;">
                <?php
                $cards = array(
                    array('Published today', $today['published']),
                    array('Auto', $today['auto']),
                    array('Manual', $today['manual']),
                    array('Failed', $today['failed']),
                    array('Job runs', $today['runs'] === null ? '—' : $today['runs']),
                );
                foreach ($cards as $card) :
                    ?>
                    <div class="card" style="margin:0;padding:14px 16px;">
                        <p class="description" style="margin:0;"><?php echo esc_html($card[0]); ?></p>
                        <p style="font-size:28px;margin:4px 0 0;"><strong><?php echo esc_html(is_int($card[1]) ? number_format_i18n($card[1]) : (string) $card[1]); ?></strong></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($today['top'] !== '') : ?>
                <p><strong>Top failure today:</strong> <?php echo esc_html($today['top'] . ' · ' . number_format_i18n((int) $today['top_count'])); ?></p>
            <?php endif; ?>
            <table class="widefat striped" style="max-width:980px;">
                <thead><tr><th>Day</th><th>Published</th><th>Auto</th><th>Manual</th><th>Failed</th><th>Top failure</th><th>Job runs</th></tr></thead>
                <tbody>
                <?php foreach ($report['days'] as $day) : ?>
                    <tr>
                        <td><?php echo esc_html($day['label']); ?><br><span class="description"><?php echo esc_html($day['key']); ?></span></td>
                        <td><?php echo esc_html(number_format_i18n((int) $day['published'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $day['auto'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $day['manual'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $day['failed'])); ?></td>
                        <td><?php echo esc_html($day['top'] !== '' ? $day['top'] . ' (' . number_format_i18n((int) $day['top_count']) . ')' : '—'); ?></td>
                        <td><?php echo esc_html($day['runs'] === null ? '—' : number_format_i18n((int) $day['runs'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
