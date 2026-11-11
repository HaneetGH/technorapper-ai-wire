<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Jobs {
    private $background_job_id = '';

    public function ajax_start_job() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are not allowed to run this task.'), 403);
        }
        $job_action = sanitize_key(wp_unslash($_POST['job_action'] ?? ''));
        $item_id = absint($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
        if ($job_action === 'tr_ai_wire_run_now') {
            check_ajax_referer('tr_ai_wire_run_now');
            wp_send_json_success($this->public_job($this->enqueue_background_job('fetch')));
        }
        if ($job_action === 'tr_ai_wire_retry_item') {
            check_ajax_referer('tr_ai_wire_retry_item_' . $item_id);
            wp_send_json_success($this->public_job($this->enqueue_background_job('retry', array('item_id' => $item_id))));
        }
        if ($job_action === 'tr_ai_wire_run_social_queue') {
            check_ajax_referer('tr_ai_wire_run_social_queue');
            wp_send_json_success($this->public_job($this->enqueue_background_job('social')));
        }
        if ($job_action === 'tr_ai_wire_generate_social_pack') {
            check_ajax_referer('tr_ai_wire_generate_social_pack');
            $pack_kind = sanitize_key(wp_unslash($_POST['pack_kind'] ?? ''));
            if ($pack_kind !== 'collage') {
                $pack_kind = 'carousel';
            }
            wp_send_json_success($this->public_job($this->enqueue_background_job('social_pack', array(
                'pack_kind' => $pack_kind,
            ))));
        }
        if ($job_action === 'tr_ai_wire_retry_social_item') {
            check_ajax_referer('tr_ai_wire_retry_social_item_' . $item_id);
            if (empty($_POST['confirmed_not_published'])) {
                wp_send_json_error(array('message' => 'Confirm that this item is not already live before retrying.'), 400);
            }
            wp_send_json_success($this->public_job($this->enqueue_background_job('social_retry', array(
                'item_id' => $item_id,
                'regenerate' => !empty($_POST['regenerate_image']),
            ))));
        }
        if ($job_action === 'tr_ai_wire_repair_home') {
            check_ajax_referer('tr_ai_wire_repair_home');
            wp_send_json_success($this->public_job($this->enqueue_background_job('repair')));
        }
        if ($job_action === 'tr_ai_wire_migrate_images') {
            check_ajax_referer('tr_ai_wire_migrate_images');
            wp_send_json_success($this->public_job($this->enqueue_background_job('migrate_images')));
        }
        if ($job_action === 'tr_ai_wire_stop_run') {
            check_ajax_referer('tr_ai_wire_stop_run');
            $this->stop_running_jobs();
            $this->finish_background_job('Stopped. The item already in flight may still finish.', 'done');
            wp_send_json_success($this->public_job(get_option(self::JOB_OPTION, array())));
        }
        if ($job_action === 'tr_ai_wire_delete_item') {
            check_ajax_referer('tr_ai_wire_delete_item_' . $item_id);
            $this->delete_queue_item($item_id);
            wp_send_json_success(array(
                'status' => 'done',
                'percent' => 100,
                'message' => 'Queue item deleted. The WordPress post, if any, stays.',
                'item_id' => $item_id,
                'effect' => 'remove-row',
            ));
        }
        if ($job_action === 'tr_ai_wire_clean_queue') {
            check_ajax_referer('tr_ai_wire_clean_queue');
            $mode = sanitize_key(wp_unslash($_POST['mode'] ?? 'all'));
            if ($mode !== 'unfinished') {
                $mode = 'all';
            }
            $deleted = $this->clean_queue($mode);
            wp_send_json_success(array(
                'status' => 'done',
                'percent' => 100,
                'message' => $mode === 'unfinished' ? 'Unfinished items removed.' : 'Queue board cleaned.',
                'effect' => $mode === 'unfinished' ? 'clear-unfinished' : 'clear-all',
                'deleted' => (int) $deleted,
            ));
        }
        wp_send_json_error(array('message' => 'Unknown task.'), 400);
    }

    public function ajax_run_job() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are not allowed to run this task.'), 403);
        }
        check_ajax_referer('tr_ai_wire_job');
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        $this->run_background_job(sanitize_text_field(wp_unslash($_POST['job_id'] ?? '')));
        wp_send_json_success($this->public_job(get_option(self::JOB_OPTION, array())));
    }

    public function ajax_job_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You are not allowed to view this task.'), 403);
        }
        check_ajax_referer('tr_ai_wire_job');
        $job = get_option(self::JOB_OPTION, array());
        if (is_array($job) && ($job['status'] ?? '') === 'queued' && (time() - (int) ($job['updated_at'] ?? 0)) > 2) {
            $this->spawn_background_job();
        }
        wp_send_json_success($this->public_job(is_array($job) ? $job : array()));
    }

    public function render_job_ui() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'tr-ai-wire') !== 0) {
            return;
        }
        $job = $this->public_job(get_option(self::JOB_OPTION, array()));
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tr_ai_wire_job'),
            'job' => $job,
            'actions' => array(
                'tr_ai_wire_run_now',
                'tr_ai_wire_retry_item',
                'tr_ai_wire_run_social_queue',
                'tr_ai_wire_generate_social_pack',
                'tr_ai_wire_retry_social_item',
                'tr_ai_wire_repair_home',
                'tr_ai_wire_migrate_images',
                'tr_ai_wire_stop_run',
                'tr_ai_wire_delete_item',
                'tr_ai_wire_clean_queue',
            ),
        );
        ?>
        <div id="tr-ai-wire-progress" hidden>
            <div class="tr-ai-wire-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="tr-ai-wire-progress-bar"></div></div>
            <p class="tr-ai-wire-progress-label"></p>
        </div>
        <style>
            #tr-ai-wire-progress{position:fixed;top:32px;left:160px;right:0;z-index:99999;background:#fff;border-bottom:1px solid #c3c4c7;padding:8px 16px 10px;box-shadow:0 1px 2px rgba(0,0,0,.06)}
            .tr-ai-wire-progress-track{height:8px;background:#dcdcde;border-radius:4px;overflow:hidden}
            .tr-ai-wire-progress-bar{height:100%;width:0;background:#2271b1;transition:width .3s ease}
            #tr-ai-wire-progress.is-running .tr-ai-wire-progress-bar{background-image:linear-gradient(90deg,#2271b1 0%,#72aee6 45%,#2271b1 100%);background-size:45% 100%;animation:tr-ai-wire-slide 1.1s linear infinite}
            .tr-ai-wire-progress-label{margin:6px 0 0;font-size:13px}
            @keyframes tr-ai-wire-slide{from{background-position:100% 0}to{background-position:-45% 0}}
            @media (max-width:782px){#tr-ai-wire-progress{left:0;top:46px}}
            body.folded #tr-ai-wire-progress{left:36px}
        </style>
        <script>
        (function () {
            var cfg = <?php echo wp_json_encode($config); ?>;
            var box = document.getElementById('tr-ai-wire-progress');
            var bar = box.querySelector('.tr-ai-wire-progress-bar');
            var track = box.querySelector('.tr-ai-wire-progress-track');
            var label = box.querySelector('.tr-ai-wire-progress-label');
            var pollTimer = null;
            var hideTimer = null;
            var workerSent = '';

            function show(job) {
                window.clearTimeout(hideTimer);
                box.hidden = false;
                var pct = Math.max(0, Math.min(100, parseInt(job.percent || 0, 10)));
                var running = job.status === 'queued' || job.status === 'running';
                box.classList.toggle('is-running', running);
                bar.style.width = (running ? Math.max(pct, 8) : pct) + '%';
                track.setAttribute('aria-valuenow', String(pct));
                label.textContent = job.message || (running ? 'Working…' : 'Done');
            }

            function hideSoon() {
                window.clearTimeout(hideTimer);
                hideTimer = window.setTimeout(function () { box.hidden = true; }, 4000);
            }

            function applyEffect(job) {
                if (job.effect === 'remove-row' && job.item_id) {
                    var gone = document.querySelector('tr[data-item-id="' + job.item_id + '"]');
                    if (gone) gone.remove();
                }
                if (job.effect === 'clear-all') {
                    var body = document.querySelector('.wrap table.widefat tbody');
                    if (body) body.innerHTML = '<tr><td colspan="6">No items processed yet.</td></tr>';
                }
                if (job.effect === 'clear-unfinished') {
                    document.querySelectorAll('tr[data-item-id]').forEach(function (row) {
                        var status = (row.children[3] && row.children[3].textContent || '').trim();
                        if (status !== 'draft' && status !== 'published') row.remove();
                    });
                }
                if (job.kind === 'fetch') {
                    var last = document.getElementById('tr-ai-wire-last-run');
                    if (last) last.textContent = 'Last run: ' + (job.message || '');
                }
                if (job.item_id && job.item_status) {
                    var row = document.querySelector('tr[data-item-id="' + job.item_id + '"]');
                    if (row && row.children[3]) {
                        row.children[3].textContent = job.item_status;
                        var detail = row.children[5];
                        if (detail && job.item_error) {
                            var note = detail.querySelector('.tr-ai-wire-job-note');
                            if (!note) {
                                note = document.createElement('div');
                                note.className = 'tr-ai-wire-job-note';
                                detail.appendChild(note);
                            }
                            note.textContent = job.item_error;
                        }
                    }
                }
            }

            function kick(job) {
                if (!job || !job.id || job.status !== 'queued' || workerSent === job.id) return;
                workerSent = job.id;
                var body = new FormData();
                body.set('action', 'tr_ai_wire_run_job');
                body.set('_ajax_nonce', cfg.nonce);
                body.set('job_id', job.id);
                fetch(cfg.ajaxUrl, {method: 'POST', body: body, credentials: 'same-origin'}).catch(function () { workerSent = ''; });
            }

            function poll() {
                var body = new FormData();
                body.set('action', 'tr_ai_wire_job_status');
                body.set('_ajax_nonce', cfg.nonce);
                fetch(cfg.ajaxUrl, {method: 'POST', body: body, credentials: 'same-origin'})
                    .then(function (res) { return res.json(); })
                    .then(function (payload) {
                        var job = payload && payload.data ? payload.data : null;
                        if (!job || !job.status) return;
                        show(job);
                        if (job.status === 'queued') kick(job);
                        if (job.status === 'done' || job.status === 'error') {
                            window.clearInterval(pollTimer);
                            pollTimer = null;
                            applyEffect(job);
                            hideSoon();
                        }
                    })
                    .catch(function () {});
            }

            function watch(job) {
                show(job);
                kick(job);
                if (pollTimer) window.clearInterval(pollTimer);
                pollTimer = window.setInterval(poll, 1500);
            }

            function startFrom(body) {
                fetch(cfg.ajaxUrl, {method: 'POST', body: body, credentials: 'same-origin'})
                    .then(function (res) { return res.json(); })
                    .then(function (payload) {
                        if (!payload || !payload.success) {
                            show({status: 'error', percent: 100, message: (payload && payload.data && payload.data.message) || 'Could not start.'});
                            hideSoon();
                            return;
                        }
                        var job = payload.data || {};
                        if (job.status === 'done' || job.status === 'error') {
                            show(job);
                            applyEffect(job);
                            hideSoon();
                            return;
                        }
                        watch(job);
                    })
                    .catch(function () {
                        show({status: 'error', percent: 100, message: 'Could not start the background task.'});
                        hideSoon();
                    });
            }

            document.addEventListener('submit', function (event) {
                if (event.defaultPrevented) return;
                var form = event.target;
                if (!form || !form.querySelector) return;
                var action = form.querySelector('[name="action"]');
                if (!action || cfg.actions.indexOf(action.value) === -1) return;
                event.preventDefault();
                var body = new FormData(form);
                body.set('job_action', action.value);
                body.set('action', 'tr_ai_wire_start_job');
                show({status: 'running', percent: 4, message: 'Starting…'});
                startFrom(body);
            });

            document.addEventListener('click', function (event) {
                if (event.defaultPrevented) return;
                var link = event.target.closest ? event.target.closest('a[href*="admin-post.php"]') : null;
                if (!link) return;
                var url;
                try { url = new URL(link.href); } catch (e) { return; }
                var action = url.searchParams.get('action') || '';
                if (cfg.actions.indexOf(action) === -1) return;
                event.preventDefault();
                var body = new FormData();
                body.set('action', 'tr_ai_wire_start_job');
                body.set('job_action', action);
                body.set('item_id', url.searchParams.get('item_id') || '');
                body.set('_ajax_nonce', url.searchParams.get('_wpnonce') || '');
                show({status: 'running', percent: 4, message: 'Starting…'});
                startFrom(body);
            });

            if (cfg.job && (cfg.job.status === 'queued' || cfg.job.status === 'running')) {
                watch(cfg.job);
            }
        }());
        </script>
        <?php
    }

    public function run_background_job($job_id = '') {
        $job_id = is_string($job_id) ? $job_id : '';
        $job = $this->claim_background_job($job_id);
        if (!$job) {
            return;
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        try {
            if ($job['kind'] === 'fetch') {
                $this->note_background_progress(8, 'Fetching feeds');
                $summary = $this->process_feeds(array('foreground' => true, 'reason' => 'manual'));
                $message = $this->background_fetch_message(is_array($summary) ? $summary : array());
                $failed = is_array($summary) && !empty($summary['skipped']);
                $this->finish_background_job($message . ' Refresh the queue to see new rows.', $failed ? 'error' : 'done');
            } elseif ($job['kind'] === 'retry') {
                $this->note_background_progress(15, 'Retrying item');
                $this->retry_queue_item((int) $job['item_id']);
                $row = $this->get_item((int) $job['item_id']);
                $status = is_array($row) ? (string) ($row['status'] ?? '') : '';
                $this->finish_background_job($status !== '' ? 'Retry finished: ' . $status : 'Retry finished.', 'done', array(
                    'item_status' => $status,
                    'item_error' => is_array($row) ? (string) ($row['error_text'] ?? '') : '',
                ));
            } elseif ($job['kind'] === 'social') {
                $processed = $this->drain_social_queue(15);
                $this->finish_background_job('Social queue processed ' . (int) $processed . ' item' . ((int) $processed === 1 ? '' : 's') . '.', 'done');
            } elseif ($job['kind'] === 'social_pack') {
                $kind = (($job['pack_kind'] ?? '') === 'collage') ? 'collage' : 'carousel';
                $this->note_background_progress(12, 'Choosing articles for a ' . $kind);
                $queued = $this->force_generate_social_pack($kind);
                if (is_wp_error($queued)) {
                    $this->finish_background_job($queued->get_error_message(), 'error');
                } else {
                    $processed = 0;
                    $max = 8;
                    for ($i = 0; $i < $max; $i++) {
                        $this->note_background_progress(30 + (int) (60 * (($i + 1) / $max)), 'Publishing the ' . $kind);
                        $before = $this->social_queued_count();
                        if ($before < 1) {
                            break;
                        }
                        $this->process_social_queue();
                        $after = $this->social_queued_count();
                        if ($after < $before) {
                            $processed++;
                            continue;
                        }
                        break;
                    }
                    $this->finish_background_job('Queued a ' . (int) $queued . '-article ' . $kind . '. Processed ' . (int) $processed . ' social post' . ((int) $processed === 1 ? '' : 's') . '.', 'done');
                }
            } elseif ($job['kind'] === 'social_retry') {
                $this->note_background_progress(20, 'Requeueing social post');
                $ok = $this->requeue_social_item((int) $job['item_id'], !empty($job['regenerate']));
                if (!$ok) {
                    $this->finish_background_job('Could not requeue that social post. The live post was left up if the delete failed.', 'error');
                } else {
                    $processed = $this->drain_social_queue(8);
                    $this->finish_background_job('Social retry sent ' . (int) $processed . ' item' . ((int) $processed === 1 ? '' : 's') . '.', 'done', array(
                        'item_id' => (int) $job['item_id'],
                    ));
                }
            } elseif ($job['kind'] === 'repair') {
                $this->note_background_progress(25, 'Repairing homepage posts');
                $this->repair_home_posts();
                $this->finish_background_job('Homepage posts repaired.', 'done');
            } elseif ($job['kind'] === 'migrate_images') {
                $this->note_background_progress(15, 'Migrating older images onto this site');
                $result = $this->migrate_hosted_images();
                $scanned = (int) ($result['scanned'] ?? 0);
                $hosted = (int) ($result['hosted'] ?? 0);
                $this->finish_background_job('Migrated images on ' . $hosted . ' of ' . $scanned . ' post' . ($scanned === 1 ? '' : 's') . '.', 'done');
            } else {
                $this->finish_background_job('Unknown background task.', 'error');
            }
        } catch (Throwable $e) {
            $this->finish_background_job('Background task failed: ' . $e->getMessage(), 'error');
        }
        delete_option(self::JOB_LOCK_OPTION);
    }

    private function enqueue_background_job($kind, $args = array()) {
        $current = get_option(self::JOB_OPTION, array());
        if (is_array($current) && in_array($current['status'] ?? '', array('queued', 'running'), true)) {
            $age = time() - (int) ($current['updated_at'] ?? 0);
            if ($age < 180) {
                return $current;
            }
        }
        delete_option(self::JOB_LOCK_OPTION);
        $job = array(
            'id' => wp_generate_uuid4(),
            'kind' => sanitize_key($kind),
            'item_id' => absint($args['item_id'] ?? 0),
            'regenerate' => !empty($args['regenerate']),
            'pack_kind' => sanitize_key((string) ($args['pack_kind'] ?? '')),
            'status' => 'queued',
            'percent' => 0,
            'message' => 'Queued. The page stays put while a background worker runs this.',
            'created_at' => time(),
            'updated_at' => time(),
        );
        update_option(self::JOB_OPTION, $job, false);
        wp_schedule_single_event(time(), self::JOB_HOOK, array($job['id']));
        $this->spawn_background_job();
        return $job;
    }

    private function claim_background_job($job_id) {
        $job = get_option(self::JOB_OPTION, array());
        $stored = is_array($job) ? (string) ($job['id'] ?? '') : '';
        if (!is_array($job) || $job_id === '' || strlen($stored) !== strlen($job_id) || !hash_equals($stored, $job_id)) {
            return null;
        }
        if (($job['status'] ?? '') !== 'queued') {
            return null;
        }
        if (!add_option(self::JOB_LOCK_OPTION, $job_id, '', false)) {
            return null;
        }
        $this->background_job_id = $job_id;
        $job['status'] = 'running';
        $job['percent'] = 5;
        $job['message'] = 'Working';
        $job['updated_at'] = time();
        update_option(self::JOB_OPTION, $job, false);
        return $job;
    }

    private function note_background_progress($percent, $message) {
        $job = get_option(self::JOB_OPTION, array());
        if (!$this->background_job_matches($job) || ($job['status'] ?? '') !== 'running') {
            return;
        }
        $job['percent'] = max(1, min(99, (int) $percent));
        $job['message'] = sanitize_text_field($message);
        $job['updated_at'] = time();
        update_option(self::JOB_OPTION, $job, false);
    }

    private function finish_background_job($message, $status, $extra = array()) {
        $job = get_option(self::JOB_OPTION, array());
        if ($this->background_job_id !== '' && !$this->background_job_matches($job)) {
            return;
        }
        if (!is_array($job)) {
            $job = array();
        }
        $job['status'] = $status === 'error' ? 'error' : 'done';
        $job['percent'] = 100;
        $job['message'] = sanitize_text_field($message);
        $job['updated_at'] = time();
        foreach ($extra as $key => $value) {
            $job[$key] = is_string($value) ? sanitize_text_field($value) : $value;
        }
        update_option(self::JOB_OPTION, $job, false);
        delete_option(self::JOB_LOCK_OPTION);
    }

    private function background_job_matches($job) {
        if ($this->background_job_id === '' || !is_array($job)) {
            return false;
        }
        $stored = (string) ($job['id'] ?? '');
        return strlen($stored) === strlen($this->background_job_id) && hash_equals($stored, $this->background_job_id);
    }

    private function spawn_background_job() {
        if (defined('WP_TESTS_DOMAIN')) {
            return;
        }
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    private function background_fetch_message($summary) {
        if (!empty($summary['skipped'])) {
            return (string) $summary['skipped'];
        }
        return 'Checked ' . (int) ($summary['feeds_checked'] ?? 0) . ' feeds, queued ' . (int) ($summary['new_items'] ?? 0) . ', processed ' . (int) ($summary['processed'] ?? 0) . '.';
    }

    private function public_job($job) {
        $job = is_array($job) ? $job : array();
        if (empty($job['status'])) {
            return array('status' => '');
        }
        return array(
            'id' => (string) ($job['id'] ?? ''),
            'kind' => (string) ($job['kind'] ?? ''),
            'status' => (string) ($job['status'] ?? ''),
            'percent' => (int) ($job['percent'] ?? 0),
            'message' => (string) ($job['message'] ?? ''),
            'item_id' => (int) ($job['item_id'] ?? 0),
            'item_status' => (string) ($job['item_status'] ?? ''),
            'item_error' => (string) ($job['item_error'] ?? ''),
            'effect' => (string) ($job['effect'] ?? ''),
        );
    }
}
