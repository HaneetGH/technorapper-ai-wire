<?php
if (!defined('ABSPATH')) {
    exit;
}

trait TechnoRapper_AI_Wire_Admin {
    public function admin_menu() {
        add_menu_page('TechnoRapper AI Wire', 'AI Wire', 'manage_options', 'tr-ai-wire', array($this, 'settings_page'), 'dashicons-rss', 58);
        add_submenu_page('tr-ai-wire', 'Feed tenants', 'Feed tenants', 'manage_options', 'tr-ai-wire-tenants', array($this, 'settings_page'));
        add_submenu_page('tr-ai-wire', 'Queue & usage', 'Queue & usage', 'manage_options', 'tr-ai-wire-queue', array($this, 'queue_page'));
        add_submenu_page('tr-ai-wire', 'Scores', 'Scores', 'manage_options', 'tr-ai-wire-scores', array($this, 'scores_page'));
        add_submenu_page('tr-ai-wire', 'A/B experiments', 'A/B experiments', 'manage_options', 'tr-ai-wire-experiments', array($this, 'experiments_page'));
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $this->settings();
        $dashboard_key = get_option(self::API_KEY_OPTION, '');
        $key_ready = (bool) $this->api_key();
        $key_managed_in_dashboard = is_string($dashboard_key) && $dashboard_key !== '';
        ?>
        <div class="wrap">
            <h1>TechnoRapper AI Wire</h1>
            <p>Feed items are rewritten, reviewed, and <strong>published automatically</strong> when they pass the review and SEO gates. Uncheck Auto-publish if you want drafts instead.</p>
            <div class="card" style="max-width:900px;margin:20px 0;padding:16px 20px;">
                <h2>1. OpenAI API connection</h2>
                <?php if ($key_ready) : ?>
                    <p><span class="dashicons dashicons-yes-alt" style="color:#16803c;"></span> <strong>API key configured.</strong> <?php echo $key_managed_in_dashboard ? 'Managed securely from this dashboard.' : 'Using a server-level key from wp-config.php.'; ?> The secret is never displayed.</p>
                <?php else : ?>
                    <p><span class="dashicons dashicons-warning" style="color:#b32d2e;"></span> <strong>API key required.</strong> Add your OpenAI API key below to enable processing.</p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tr_ai_wire_save_api_key">
                    <?php wp_nonce_field('tr_ai_wire_save_api_key'); ?>
                    <p><label for="tr-ai-wire-api-key"><strong>OpenAI API key</strong></label></p>
                    <p><input id="tr-ai-wire-api-key" name="api_key" type="password" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $key_managed_in_dashboard ? esc_attr('Saved — enter a new key to replace it') : esc_attr('Paste your API key'); ?>"></p>
                    <p class="description">Saved in a dedicated non-autoloaded WordPress option. Existing key is retained when this field is blank. API usage is billed separately from ChatGPT.</p>
                    <?php submit_button($key_managed_in_dashboard ? 'Save / replace API key' : 'Save API key', 'primary', 'submit', false); ?>
                    <?php if ($key_managed_in_dashboard) : ?>
                        <label style="margin-left:12px;"><input type="checkbox" name="remove_api_key" value="1"> Remove saved dashboard key</label>
                    <?php endif; ?>
                </form>
                <p class="description">A server-level constant can still be used as a fallback if no dashboard key is saved.</p>
            </div>
            <h2>2. Feed, editorial and processing settings</h2>
            <form method="post" action="options.php">
                <?php settings_fields('tr_ai_wire'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="tr-cron-frequency">Automatic processing schedule</label></th><td><select id="tr-cron-frequency" name="<?php echo esc_attr(self::OPTION); ?>[cron_frequency]"><?php foreach (array('hourly' => 'Every hour', 'every_3_hours' => 'Every 3 hours', 'every_6_hours' => 'Every 6 hours', 'every_12_hours' => 'Every 12 hours', 'daily' => 'Once per day', 'manual' => 'Manual only (disable scheduled runs)') as $frequency => $label) : ?><option value="<?php echo esc_attr($frequency); ?>" <?php selected($s['cron_frequency'], $frequency); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><p class="description">Changes take effect after saving. WP-Cron is traffic-triggered; use a real server cron for reliable timing.</p></td></tr>
                    <tr><th colspan="2" id="tr-tenants"><h2 style="margin:12px 0 0;">Feed tenants</h2><p class="description">Each RSS/Atom feed is its own tenant. Onboard one source at a time with its own homepage and content mapping. Disabled tenants are not fetched.</p></th></tr>
                    <tr><th scope="row">Onboard a tenant</th><td><?php $this->render_tenant_fields('new_tenant', array_merge($this->tenant_defaults(), array('pin_to_homepage' => $s['pin_to_homepage'], 'map_post_type' => $s['map_post_type'], 'map_taxonomy' => $s['map_taxonomy'], 'map_terms' => $s['map_terms'], 'map_auto_desk' => $s['map_auto_desk'], 'map_post_status' => $s['map_post_status'], 'map_title' => $s['map_title'], 'map_date' => $s['map_date'], 'map_content' => $s['map_content'])), true); ?><p class="description">Save settings to add this feed as a tenant. Leave the URL blank if you are only editing tenants below.</p></td></tr>
                    <?php foreach (array_values((array) ($s['tenants'] ?? array())) as $index => $tenant) : ?>
                    <tr><th scope="row"><?php echo esc_html($tenant['name'] !== '' ? $tenant['name'] : $tenant['url']); ?></th><td><?php $this->render_tenant_fields('tenants][' . (int) $index, $tenant, false); ?></td></tr>
                    <?php endforeach; ?>
                    <tr><th scope="row"><label for="tr-daily">Maximum posts per day</label></th><td><input id="tr-daily" type="number" min="0" max="2000" name="<?php echo esc_attr(self::OPTION); ?>[max_posts_per_day]" value="<?php echo esc_attr($s['max_posts_per_day']); ?>"> <p class="description">Counts drafts and auto-published posts created by this plugin today. 350 is the daily target. Set to 0 to pause creation.</p></td></tr>
                    <tr><th scope="row">Auto-publish</th><td><label><input id="tr-auto-publish" type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[auto_publish]" value="1" <?php checked($s['auto_publish'], 1); ?>> <strong>Publish posts automatically</strong> — no draft and no Publish button. Passing items go live. Rejected items stay quarantined.</label></td></tr>
                    <tr><th scope="row">Show on homepage</th><td>
                        <fieldset>
                            <label style="display:block;margin:0 0 8px;"><input id="tr-pin-home-on" type="radio" name="<?php echo esc_attr(self::OPTION); ?>[pin_to_homepage]" value="1" <?php checked(!empty($s['pin_to_homepage']), true); ?>> <strong>On</strong> — assign a desk, featured image, and sticky flag so the post can appear on the home board.</label>
                            <label style="display:block;"><input id="tr-pin-home-off" type="radio" name="<?php echo esc_attr(self::OPTION); ?>[pin_to_homepage]" value="0" <?php checked(empty($s['pin_to_homepage']), true); ?>> <strong>Off</strong> — keep the post on its own link only. It is hidden from the home feed.</label>
                        </fieldset>
                        <p class="description">Turn Off if you only want the permalink. Repair existing posts after you change this.</p>
                    </td></tr>
                    <tr><th colspan="2"><h2 style="margin:12px 0 0;">3. Map content</h2><p class="description">Same mapping as feed-to-post plugins: choose the WordPress type, taxonomy, status, title, date, and body for each item.</p></th></tr>
                    <tr><th scope="row"><label for="tr-map-post-type">Post type</label></th><td><select id="tr-map-post-type" name="<?php echo esc_attr(self::OPTION); ?>[map_post_type]"><?php foreach ($this->mappable_post_types() as $type => $label) : ?><option value="<?php echo esc_attr($type); ?>" <?php selected($s['map_post_type'], $type); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><p class="description">Home page boards only show the <code>post</code> type. Pages and other types stay off the home feed.</p></td></tr>
                    <tr><th scope="row"><label for="tr-map-taxonomy">Post taxonomy</label></th><td><select id="tr-map-taxonomy" name="<?php echo esc_attr(self::OPTION); ?>[map_taxonomy]"><?php foreach ($this->mappable_taxonomies() as $tax => $label) : ?><option value="<?php echo esc_attr($tax); ?>" <?php selected($s['map_taxonomy'], $tax); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><p class="description">Pick Categories so desks like Android / Apple can appear on the home board.</p></td></tr>
                    <tr><th scope="row">Terms</th><td>
                        <div style="max-height:180px;overflow:auto;border:1px solid #c3c4c7;padding:8px 12px;background:#fff;max-width:420px;">
                            <?php
                            $map_terms = array_map('intval', (array) ($s['map_terms'] ?? array()));
                            $term_list = get_terms(array('taxonomy' => $s['map_taxonomy'] ?: 'category', 'hide_empty' => false, 'number' => 200));
                            if (is_wp_error($term_list) || empty($term_list)) :
                                ?><p class="description">No terms found for this taxonomy yet.</p><?php
                            else :
                                foreach ($term_list as $term) :
                                    ?>
                                    <label style="display:block;margin:4px 0;"><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[map_terms][]" value="<?php echo esc_attr((string) $term->term_id); ?>" <?php checked(in_array((int) $term->term_id, $map_terms, true)); ?>> <?php echo esc_html($term->name); ?></label>
                                    <?php
                                endforeach;
                            endif;
                            ?>
                        </div>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[map_auto_desk]" value="1" <?php checked(!empty($s['map_auto_desk']), true); ?>> Read the source and auto-tag a matching site category (Android, Apple, AI…)</label></p>
                        <p class="description">Leave all terms unchecked. The writer reads the document and picks the category and tags. Checked terms are forced on every post from this tenant.</p>
                    </td></tr>
                    <tr><th scope="row"><label for="tr-map-status">Post status</label></th><td><select id="tr-map-status" name="<?php echo esc_attr(self::OPTION); ?>[map_post_status]"><?php foreach (array('auto' => 'Auto (follow Auto-publish)', 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending review') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($s['map_post_status'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th scope="row"><label for="tr-map-title">Post title</label></th><td><select id="tr-map-title" name="<?php echo esc_attr(self::OPTION); ?>[map_title]"><option value="rewrite" <?php selected($s['map_title'], 'rewrite'); ?>>Rewrite title (itemtitle after AI)</option><option value="source" <?php selected($s['map_title'], 'source'); ?>>Source title (itemtitle from the feed)</option></select><p class="description">This field is required. A post is not created without a title.</p></td></tr>
                    <tr><th scope="row"><label for="tr-map-date">Post date</label></th><td><select id="tr-map-date" name="<?php echo esc_attr(self::OPTION); ?>[map_date]"><option value="now" <?php selected($s['map_date'], 'now'); ?>>Publish now</option><option value="source" <?php selected($s['map_date'], 'source'); ?>>Source date ({item_date})</option></select><p class="description">Leave as Publish now if the feed date is missing or you want the post at the top of the home board.</p></td></tr>
                    <tr><th scope="row"><label for="tr-map-content">Content</label></th><td><select id="tr-map-content" name="<?php echo esc_attr(self::OPTION); ?>[map_content]"><option value="rewrite" <?php selected($s['map_content'], 'rewrite'); ?>>Rewrite article (itemcontent after AI)</option><option value="source" <?php selected($s['map_content'], 'source'); ?>>Source body (itemcontent from the feed)</option></select></td></tr>
                    <tr><th scope="row"><label for="tr-items-run">Maximum items per run</label></th><td><input id="tr-items-run" type="number" min="1" max="350" name="<?php echo esc_attr(self::OPTION); ?>[max_items_per_run]" value="<?php echo esc_attr($s['max_items_per_run']); ?>"> <p class="description">Caps queued items touched in one run (1–350). Long days continue on WP-Cron after the time budget.</p></td></tr>
                    <tr><th scope="row"><label for="tr-monthly-budget">Monthly API budget (USD)</label></th><td><input id="tr-monthly-budget" type="number" min="0" step="0.01" name="<?php echo esc_attr(self::OPTION); ?>[monthly_budget_usd]" value="<?php echo esc_attr($s['monthly_budget_usd']); ?>"> <p class="description">Processing stops when tracked usage reaches the cap. Set to 0 to stop AI calls.</p></td></tr>
                    <tr><th scope="row"><label for="tr-monthly-tokens">Monthly token limit</label></th><td><input id="tr-monthly-tokens" type="number" min="0" step="1000" name="<?php echo esc_attr(self::OPTION); ?>[monthly_token_limit]" value="<?php echo esc_attr($s['monthly_token_limit']); ?>"> <p class="description">Combined input + output tokens, based on API usage reports. Also stops at this cap.</p></td></tr>
                    <tr><th scope="row"><label for="tr-output-tokens">Maximum output tokens per AI pass</label></th><td><input id="tr-output-tokens" type="number" min="200" max="8000" step="100" name="<?php echo esc_attr(self::OPTION); ?>[max_output_tokens]" value="<?php echo esc_attr($s['max_output_tokens']); ?>"> <p class="description">Use 3500–4000 so the writer can return a detailed article, not a short brief.</p></td></tr>
                    <tr><th scope="row"><label for="tr-source-chars">Maximum source characters per item</label></th><td><input id="tr-source-chars" type="number" min="1000" max="50000" step="1000" name="<?php echo esc_attr(self::OPTION); ?>[max_source_chars]" value="<?php echo esc_attr($s['max_source_chars']); ?>"></td></tr>
                    <tr><th scope="row">AI models</th><td>Writer: <?php $this->model_select('writer_model', $s['writer_model']); ?><br>Reviewer: <?php $this->model_select('reviewer_model', $s['reviewer_model']); ?><p class="description">Usage estimates use published standard rates for these models. Recheck provider pricing before changing models.</p></td></tr>
                    <tr><th scope="row">Independent web search</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enable_web_search]" value="1" <?php checked($s['enable_web_search'], 1); ?>> Enable up to one web-search call during each review</label><p class="description">Adds search-tool charges (currently $0.01 per call, plus tokens). Search is corroboration, not a guarantee. Leave off to check claims against the supplied feed only.</p></td></tr>
                    <tr><th scope="row">Editorial A/B experiment</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enable_experiment]" value="1" <?php checked($s['enable_experiment'], 1); ?>> Enable 50/50 writer experiment</label><p class="description">A = current writer. B = original editorial writer. Assignment is stable per source item. Disable to use A for new items.</p><label for="tr-experiment-id">Experiment ID</label> <input id="tr-experiment-id" type="text" maxlength="64" name="<?php echo esc_attr(self::OPTION); ?>[experiment_id]" value="<?php echo esc_attr($s['experiment_id']); ?>"></td></tr>
                    <tr><th scope="row">When to create a draft</th><td>
                        <p><label for="tr-draft-min-score"><strong>Minimum review score</strong></label> <input id="tr-draft-min-score" type="number" min="0" max="100" name="<?php echo esc_attr(self::OPTION); ?>[draft_min_score]" value="<?php echo esc_attr($s['draft_min_score']); ?>"> <span class="description">0–100. The reviewer scores an article a human would publish at 85 or higher. 70–84 means a concrete edit is still required. Below this stays quarantined. Retry asks the reviewer again; it does not publish a score under this minimum.</span></p>
                        <p><label>Accept reviewer verdict </label><select name="<?php echo esc_attr(self::OPTION); ?>[draft_allow_revise]"><option value="1" <?php selected($s['draft_allow_revise'], 1); ?>>pass or revise</option><option value="0" <?php selected($s['draft_allow_revise'], 0); ?>>pass only</option></select></p>
                        <p><label>Maximum similarity risk </label><select name="<?php echo esc_attr(self::OPTION); ?>[draft_max_similarity]"><?php foreach (array('high' => 'Allow high (most drafts)', 'medium' => 'Medium or lower', 'low' => 'Low only (strictest)') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($s['draft_max_similarity'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_require_grounded]" value="1" <?php checked($s['draft_require_grounded'], 1); ?>> Require grounded in the source</label></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_require_useful]" value="1" <?php checked($s['draft_require_useful'], 1); ?>> Require useful</label></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_require_new]" value="1" <?php checked($s['draft_require_new'], 1); ?>> Require original writing vs the source</label></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_block_policy_flags]" value="1" <?php checked($s['draft_block_policy_flags'], 1); ?>> Block if policy flags are present</label></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_block_fact_flags]" value="1" <?php checked($s['draft_block_fact_flags'], 1); ?>> Block if fact flags are present</label></p>
                        <p class="description">Reject and a score below the minimum never publish — including Retry. Fact flags mean a concrete claim is missing from or contradicts the source. Style notes and labeled analysis are not fact flags. Auto-publish is at the top of this form.</p>
                    </td></tr>
                    <tr><th scope="row">After rewrite: SEO tests</th><td>
                        <p><label for="tr-seo-min-score"><strong>Minimum SEO score</strong></label> <input id="tr-seo-min-score" type="number" min="0" max="100" name="<?php echo esc_attr(self::OPTION); ?>[seo_min_score]" value="<?php echo esc_attr($s['seo_min_score']); ?>"> <span class="description">Visibility, keyphrase, readability and social markup. 0–100.</span></p>
                        <p><label for="tr-seo-readability"><strong>Maximum readability errors</strong></label> <input id="tr-seo-readability" type="number" min="0" max="20" name="<?php echo esc_attr(self::OPTION); ?>[seo_max_readability_errors]" value="<?php echo esc_attr($s['seo_max_readability_errors']); ?>"></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[seo_require_keyphrase]" value="1" <?php checked($s['seo_require_keyphrase'], 1); ?>> Require a focus keyphrase</label></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[seo_require_social]" value="1" <?php checked($s['seo_require_social'], 1); ?>> Require social title and description</label></p>
                        <p class="description">These scores are shown on the queue. Missing keyphrase, missing social markup, or a score below the minimum can hold an item. Readability errors are listed but do not block auto-publish. The writer must also add a unique search angle, key takeaways, FAQ, keyphrase slug, internal links, and an editorial-desk byline.</p>
                    </td></tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tr_ai_wire_run_now">
                <?php wp_nonce_field('tr_ai_wire_run_now'); ?>
                <p class="description">This runs immediately: fetch new feed items, rewrite, review, and publish (or save drafts if Auto-publish is off). It can take up to a minute. Save settings first if you just changed feeds.</p>
                <?php submit_button('Fetch feeds and process queue now', 'secondary'); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <input type="hidden" name="action" value="tr_ai_wire_stop_run">
                <?php wp_nonce_field('tr_ai_wire_stop_run'); ?>
                <p class="description">Stops the current run after the item already in flight. Scheduled runs still start again on the next interval unless the schedule is Manual only.</p>
                <?php submit_button('Stop current run', 'delete'); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <input type="hidden" name="action" value="tr_ai_wire_repair_home">
                <?php wp_nonce_field('tr_ai_wire_repair_home'); ?>
                <p class="description">Re-assign desks and featured images on already published AI Wire posts so they can appear on the home board, not only at their own URL.</p>
                <?php submit_button('Repair existing posts for homepage', 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    private function model_select($field, $selected) {
        $models = array('gpt-6-luna' => 'GPT-6 Luna', 'gpt-6.1-terra' => 'GPT-6.1 Terra');
        echo '<select name="' . esc_attr(self::OPTION) . '[' . esc_attr($field) . ']">';
        foreach ($models as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function run_now() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to run this task.', 'technorapper-ai-wire'));
        }
        check_admin_referer('tr_ai_wire_run_now');
        if (function_exists('set_time_limit')) {
            set_time_limit(120);
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        $this->process_feeds(array('foreground' => true, 'reason' => 'manual'));
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-queue&ran=1'));
        exit;
    }

    public function scores_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $filters = $this->score_filters(array(
            'range' => wp_unslash($_GET['range'] ?? '30d'),
            'group' => wp_unslash($_GET['group'] ?? 'day'),
            'scope' => wp_unslash($_GET['scope'] ?? 'posts'),
        ));
        $report = $this->score_report($filters);
        $range_labels = array(
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
            'month' => 'This month',
            'year' => 'This year',
            'all' => 'All time',
        );
        $group_labels = array('day' => 'Day', 'week' => 'Week', 'month' => 'Month');
        $scope_labels = array(
            'posts' => 'Drafts + published',
            'published' => 'Published only',
            'reviewed' => 'Every reviewed item',
            'quarantined' => 'Quarantined only',
        );
        ?>
        <div class="wrap">
            <h1>AI Wire — Scores</h1>
            <p>Average reviewer score for items in the selected range. The yellow line is your minimum review score. Grey bars are how many items landed in that day, week, or month.</p>
            <form method="get" style="margin:16px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
                <input type="hidden" name="page" value="tr-ai-wire-scores">
                <p><label for="tr-score-range"><strong>Range</strong></label><br>
                    <select id="tr-score-range" name="range"><?php foreach ($range_labels as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['range'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?></select></p>
                <p><label for="tr-score-group"><strong>Group by</strong></label><br>
                    <select id="tr-score-group" name="group"><?php foreach ($group_labels as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['group'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?></select></p>
                <p><label for="tr-score-scope"><strong>Items</strong></label><br>
                    <select id="tr-score-scope" name="scope"><?php foreach ($scope_labels as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($filters['scope'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?></select></p>
                <p><?php submit_button('Update graph', 'secondary', 'submit', false); ?></p>
            </form>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;max-width:960px;margin:12px 0 20px;">
                <div class="card" style="margin:0;padding:14px 16px;"><p class="description" style="margin:0;">Average review score</p><p style="font-size:28px;margin:4px 0 0;"><strong><?php echo esc_html($report['count'] ? number_format_i18n($report['average'], 1) : '—'); ?></strong></p></div>
                <div class="card" style="margin:0;padding:14px 16px;"><p class="description" style="margin:0;">Average SEO score</p><p style="font-size:28px;margin:4px 0 0;"><strong><?php echo esc_html($report['seo_average'] !== null ? number_format_i18n($report['seo_average'], 1) : '—'); ?></strong></p></div>
                <div class="card" style="margin:0;padding:14px 16px;"><p class="description" style="margin:0;">Scored items</p><p style="font-size:28px;margin:4px 0 0;"><strong><?php echo esc_html(number_format_i18n($report['count'])); ?></strong></p></div>
                <div class="card" style="margin:0;padding:14px 16px;"><p class="description" style="margin:0;">At or above minimum (<?php echo esc_html((string) $report['min_score']); ?>)</p><p style="font-size:28px;margin:4px 0 0;"><strong><?php echo esc_html($report['count'] ? number_format_i18n($report['above_min_rate'], 1) . '%' : '—'); ?></strong></p></div>
            </div>
            <p><span style="display:inline-block;width:12px;height:12px;background:#2271b1;margin-right:6px;"></span> Review score
                <span style="display:inline-block;width:12px;height:12px;background:#00a32a;margin:0 6px 0 16px;"></span> SEO score
                <span style="display:inline-block;width:12px;height:12px;background:#c3c4c7;margin:0 6px 0 16px;"></span> Item count
                <span style="display:inline-block;width:16px;border-top:2px dashed #dba617;margin:0 6px 0 16px;"></span> Minimum review score</p>
            <div style="max-width:960px;"><?php echo $this->render_score_chart($report); ?></div>
            <h2>Score bands</h2>
            <table class="widefat striped" style="max-width:480px;">
                <thead><tr><th>Band</th><th>Items</th></tr></thead>
                <tbody>
                <?php foreach ($report['distribution'] as $band => $total) : ?>
                    <tr><td><?php echo esc_html($band); ?></td><td><?php echo esc_html(number_format_i18n($total)); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <h2>Average by <?php echo esc_html(strtolower($group_labels[$filters['group']])); ?></h2>
            <table class="widefat striped" style="max-width:960px;">
                <thead><tr><th>Period</th><th>Items</th><th>Avg review</th><th>Avg SEO</th><th>At or above min</th></tr></thead>
                <tbody>
                <?php $has_row = false; foreach ($report['series'] as $point) : if (!$point['count']) { continue; } $has_row = true; ?>
                    <tr>
                        <td><?php echo esc_html($point['label']); ?></td>
                        <td><?php echo esc_html(number_format_i18n($point['count'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n($point['average'], 1)); ?></td>
                        <td><?php echo esc_html($point['seo_average'] !== null ? number_format_i18n($point['seo_average'], 1) : '—'); ?></td>
                        <td><?php echo esc_html(number_format_i18n($point['above_min'])); ?></td>
                    </tr>
                <?php endforeach; if (!$has_row) : ?>
                    <tr><td colspan="5">No scored items in this range.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <h2>Latest scored items</h2>
            <table class="widefat striped" style="max-width:960px;">
                <thead><tr><th>When (UTC)</th><th>Source</th><th>Status</th><th>Review</th><th>SEO</th></tr></thead>
                <tbody>
                <?php if (empty($report['recent'])) : ?>
                    <tr><td colspan="5">No scored items yet. Run the queue after the reviewer has scored an item.</td></tr>
                <?php else : foreach ($report['recent'] as $item) : ?>
                    <tr>
                        <td><?php echo esc_html($item['created_at']); ?></td>
                        <td><?php if ($item['wp_post_id']) : ?><a href="<?php echo esc_url(get_edit_post_link($item['wp_post_id'])); ?>"><?php echo esc_html($item['title']); ?></a><?php else : echo esc_html($item['title']); endif; ?></td>
                        <td><?php echo esc_html($item['status']); ?></td>
                        <td><?php echo esc_html((string) $item['score']); ?></td>
                        <td><?php echo esc_html($item['seo_score'] !== null ? (string) $item['seo_score'] : '—'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function experiments_page() {
        if (!current_user_can('manage_options')) { return; }
        global $wpdb;
        $settings = $this->settings();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT variant, status, COUNT(*) AS total FROM {$this->table} WHERE experiment_id = %s GROUP BY variant, status", $settings['experiment_id']), ARRAY_A);
        $views_table = $wpdb->prefix . self::VIEWS_TABLE_SUFFIX;
        $view_rows = $wpdb->get_results($wpdb->prepare("SELECT pm.meta_value AS variant, SUM(v.views) AS views, COUNT(DISTINCT v.post_id) AS articles FROM {$views_table} v INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = v.post_id AND pm.meta_key = '_tr_ai_wire_variant' INNER JOIN {$wpdb->postmeta} exp ON exp.post_id = v.post_id AND exp.meta_key = '_tr_ai_wire_experiment_id' AND exp.meta_value = %s INNER JOIN {$wpdb->posts} p ON p.ID = v.post_id AND p.post_status = 'publish' WHERE v.view_date >= DATE_SUB(UTC_DATE(), INTERVAL 30 DAY) GROUP BY pm.meta_value", $settings['experiment_id']), ARRAY_A);
        $view_counts = array('A' => array('views' => 0, 'articles' => 0), 'B' => array('views' => 0, 'articles' => 0));
        foreach ($view_rows as $view_row) {
            $v = in_array($view_row['variant'], array('A', 'B'), true) ? $view_row['variant'] : 'A';
            $view_counts[$v]['views'] = (int) $view_row['views'];
            $view_counts[$v]['articles'] = (int) $view_row['articles'];
        }
        $counts = array('A' => array('posts' => 0, 'quarantined' => 0, 'other' => 0), 'B' => array('posts' => 0, 'quarantined' => 0, 'other' => 0));
        foreach ($rows as $row) {
            $variant = in_array($row['variant'], array('A', 'B'), true) ? $row['variant'] : 'A';
            if (in_array($row['status'], array('draft', 'published'), true)) {
                $status = 'posts';
            } elseif ($row['status'] === 'quarantined') {
                $status = 'quarantined';
            } else {
                $status = 'other';
            }
            $counts[$variant][$status] += (int) $row['total'];
        }
        ?>
        <div class="wrap"><h1>AI Wire — A/B experiments</h1>
        <p>Active experiment: <strong><?php echo esc_html($settings['experiment_id']); ?></strong> · <?php echo !empty($settings['enable_experiment']) ? '50/50 assignment enabled' : 'Experiment disabled; new items use A'; ?></p>
        <table class="widefat striped"><thead><tr><th>Variant</th><th>Writer</th><th>Posts created</th><th>Quarantined</th><th>Other queue items</th></tr></thead><tbody>
        <tr><td><strong>A</strong></td><td>Current writer</td><td><?php echo esc_html((string) $counts['A']['posts']); ?></td><td><?php echo esc_html((string) $counts['A']['quarantined']); ?></td><td><?php echo esc_html((string) $counts['A']['other']); ?></td></tr>
        <tr><td><strong>B</strong></td><td>Original editorial writer</td><td><?php echo esc_html((string) $counts['B']['posts']); ?></td><td><?php echo esc_html((string) $counts['B']['quarantined']); ?></td><td><?php echo esc_html((string) $counts['B']['other']); ?></td></tr>
        </tbody></table>
        <h2>Reader performance — last 30 days</h2>
        <p>Engaged reads are counted after a visitor remains on a published experiment article for at least 3 seconds. Repeat views of the same article in one browser session are counted once. This is a lightweight first-party engagement proxy, not a replacement for GA4.</p>
        <table class="widefat striped"><thead><tr><th>Variant</th><th>Engaged reads</th><th>Articles with reads</th><th>Average reads per article</th></tr></thead><tbody>
        <?php foreach (array('A' => 'Current writer', 'B' => 'Original editorial writer') as $variant => $label) : $avg = $view_counts[$variant]['articles'] ? round($view_counts[$variant]['views'] / $view_counts[$variant]['articles'], 1) : 0; ?>
        <tr><td><strong><?php echo esc_html($variant); ?></strong> — <?php echo esc_html($label); ?></td><td><?php echo esc_html(number_format_i18n($view_counts[$variant]['views'])); ?></td><td><?php echo esc_html(number_format_i18n($view_counts[$variant]['articles'])); ?></td><td><?php echo esc_html(number_format_i18n($avg, 1)); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <h2>Reader and revenue measurement</h2>
        <p>Published articles automatically emit the GA4 event <code>tr_ai_wire_article_view</code> with <code>experiment_id</code>, <code>variant</code>, <code>article_id</code>, and <code>article_title</code>. Drafts do not emit events.</p>
        <ol><li>Confirm your existing GA4 tag or Google Tag Manager is installed on the site.</li><li>In GA4 Admin → Custom definitions, create event-scoped dimensions for <code>experiment_id</code>, <code>variant</code>, and <code>article_id</code>.</li><li>Wait for data to populate, then use Explore to compare event counts, engaged sessions, engagement time and key events by variant. Use article_id/page path to inspect individual URLs.</li><li>For revenue, link AdSense to GA4 where supported, or export ad revenue by page URL and join it to article_id. Compare revenue per 1,000 sessions, not raw revenue alone.</li></ol>
        <p><strong>Important:</strong> Engaged reads shown above are first-party counts from the plugin's 3-second tracker. GA4 engagement, Search Console clicks, and ad revenue are not fetched into WordPress by this version; check those reports separately. No revenue figures are estimated or fabricated.</p>
        <p>Do not compare a handful of posts. Run the experiment for several weeks and compare similar topics, publication age and traffic sources before selecting a winner.</p>
        </div>
        <?php
    }

    public function queue_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $usage = $this->usage();
        $s = $this->settings();
        $page = max(1, absint($_GET['paged'] ?? 1));
        $per_page = 50;
        $status_filter = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        $allowed_statuses = array('new', 'waiting_budget', 'retry_wait', 'rewritten', 'reviewed', 'draft', 'published', 'quarantined', 'error');
        if (!in_array($status_filter, $allowed_statuses, true)) { $status_filter = ''; }
        $where = $status_filter ? $wpdb->prepare(' WHERE status = %s', $status_filter) : '';
        $total_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}{$where}");
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, feed_name, source_title, source_url, status, wp_post_id, created_at, error_text, review_json, rewrite_json FROM {$this->table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset), ARRAY_A);
        ?>
        <div class="wrap"><h1>AI Wire — Queue & usage</h1>
        <?php
        $notice = sanitize_key(wp_unslash($_GET['queue_notice'] ?? ''));
        $notices = array(
            'stopped' => 'The current run was stopped. The item already being written may still finish. Set the schedule to Manual only if you do not want the next scheduled run.',
            'deleted' => 'Queue item deleted. WordPress posts created from it were left in place.',
            'cleared' => 'Unfinished queue items were removed. Drafts, published posts, and WordPress posts were kept.',
            'cleaned' => 'The queue board is clean. WordPress posts were kept. The same feeds can be ingested again.',
            'repaired' => 'Existing AI Wire posts were reassigned to homepage desks and given featured images so they can appear on the home board.',
        );
        if (isset($notices[$notice])) :
            ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notices[$notice]); ?></p></div><?php
        endif;
        if ($this->is_run_in_progress()) :
            ?><div class="notice notice-warning"><p><strong>A run is in progress.</strong> Use Stop current run to release the lock after the item already in flight.</p></div><?php
        endif;
        ?>
        <div class="tablenav top" style="margin:12px 0 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tr_ai_wire_stop_run">
                <?php wp_nonce_field('tr_ai_wire_stop_run'); ?>
                <?php submit_button('Stop current run', $this->is_run_in_progress() ? 'delete' : 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Remove unfinished queue items? Drafts, published posts, and WordPress posts stay.');">
                <input type="hidden" name="action" value="tr_ai_wire_clean_queue">
                <input type="hidden" name="mode" value="unfinished">
                <?php wp_nonce_field('tr_ai_wire_clean_queue'); ?>
                <?php submit_button('Clear unfinished', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Clean the whole board? This deletes every queue row so you can start over. WordPress posts stay. The same feeds can be ingested again.');">
                <input type="hidden" name="action" value="tr_ai_wire_clean_queue">
                <input type="hidden" name="mode" value="all">
                <?php wp_nonce_field('tr_ai_wire_clean_queue'); ?>
                <?php submit_button('Clean board', 'delete', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="tr_ai_wire_repair_home">
                <?php wp_nonce_field('tr_ai_wire_repair_home'); ?>
                <?php submit_button('Repair homepage posts', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php $last_run = get_option(self::LAST_RUN_OPTION, array()); if (is_array($last_run) && !empty($last_run['at'])) : ?>
        <div class="notice <?php echo empty($last_run['skipped']) ? 'notice-info' : 'notice-warning'; ?>"><p>
            <strong>Last run</strong> (<?php echo esc_html(gmdate('Y-m-d H:i', (int) $last_run['at'])); ?> UTC):
            <?php if (!empty($last_run['skipped'])) : ?>
                <?php echo esc_html((string) $last_run['skipped']); ?>
            <?php else : ?>
                checked <?php echo esc_html((string) (int) ($last_run['feeds_checked'] ?? 0)); ?> feeds,
                queued <?php echo esc_html((string) (int) ($last_run['new_items'] ?? 0)); ?> new items,
                processed <?php echo esc_html((string) (int) ($last_run['processed'] ?? 0)); ?>,
                <?php echo esc_html((string) (int) ($last_run['published'] ?? 0)); ?> published,
                <?php echo esc_html((string) (int) ($last_run['drafts'] ?? 0)); ?> drafts,
                <?php echo esc_html((string) (int) ($last_run['quarantined'] ?? 0)); ?> quarantined.
            <?php endif; ?>
            <?php if (!empty($last_run['feed_errors']) && is_array($last_run['feed_errors'])) : ?>
                <br><?php echo esc_html(implode(' · ', array_map('sanitize_text_field', $last_run['feed_errors']))); ?>
            <?php endif; ?>
        </p></div>
        <?php endif; ?>
        <p><strong>This month:</strong> $<?php echo esc_html(number_format_i18n((float) $usage['cost_usd'], 4)); ?> / $<?php echo esc_html(number_format_i18n((float) $s['monthly_budget_usd'], 2)); ?> · <?php echo esc_html(number_format_i18n((int) $usage['input_tokens'] + (int) $usage['output_tokens'])); ?> / <?php echo esc_html(number_format_i18n((int) $s['monthly_token_limit'])); ?> tokens · <?php echo esc_html((int) $usage['search_calls']); ?> web searches</p>
        <p><strong>Daily post cap:</strong> <?php echo esc_html((int) $s['max_posts_per_day']); ?> · Posts still available today: <?php echo esc_html($this->remaining_posts_today($s)); ?><?php if (!empty($s['auto_publish'])) : ?> · Auto-publish is on<?php endif; ?> · <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-scores')); ?>">View score graph</a></p>
        <form method="get" style="margin:12px 0;"><input type="hidden" name="page" value="tr-ai-wire-queue"><label for="tr-ai-wire-status">Filter status </label><select id="tr-ai-wire-status" name="status"><option value="">All statuses</option><?php foreach ($allowed_statuses as $option_status) : ?><option value="<?php echo esc_attr($option_status); ?>" <?php selected($status_filter, $option_status); ?>><?php echo esc_html($option_status); ?></option><?php endforeach; ?></select> <button class="button">Apply</button></form>
        <table class="widefat striped"><thead><tr><th>Added (UTC)</th><th>Feed</th><th>Source item</th><th>Status</th><th>Post / details</th></tr></thead><tbody>
        <?php if (empty($rows)) : ?><tr><td colspan="5">No items processed yet.</td></tr><?php else : foreach ($rows as $row) : ?>
            <tr><td><?php echo esc_html($row['created_at']); ?></td><td><?php echo esc_html($row['feed_name']); ?></td><td><a href="<?php echo esc_url($row['source_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($row['source_title']); ?></a></td><td><?php echo esc_html($row['status']); ?></td><td><?php if ($row['wp_post_id']) : ?><a href="<?php echo esc_url(get_edit_post_link((int) $row['wp_post_id'])); ?>"><?php echo $row['status'] === 'published' ? 'Edit post' : 'Edit draft'; ?></a><?php if ($row['status'] === 'published') : ?> · <a href="<?php echo esc_url(get_permalink((int) $row['wp_post_id'])); ?>" target="_blank" rel="noopener">View</a><?php endif; ?><?php endif; ?><?php if (in_array($row['status'], array('error', 'retry_wait', 'quarantined'), true)) : ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tr_ai_wire_retry_item&item_id=' . (int) $row['id']), 'tr_ai_wire_retry_item_' . (int) $row['id'])); ?>">Retry</a> <?php endif; ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tr_ai_wire_delete_item&item_id=' . (int) $row['id']), 'tr_ai_wire_delete_item_' . (int) $row['id'])); ?>" onclick="return confirm('Delete this queue item? The WordPress post, if any, stays.');">Delete</a> <?php if ($row['error_text']) : ?><br><?php echo esc_html($row['error_text']); ?><?php endif; ?><?php if ($row['review_json']) : $review = json_decode($row['review_json'], true); if (is_array($review)) : ?><br>Review score: <?php echo esc_html((string) ($review['score'] ?? 'n/a')); ?> · <?php echo esc_html((string) ($review['verdict'] ?? '')); ?><?php endif; endif; ?><?php if ($row['rewrite_json']) : $rewrite = json_decode($row['rewrite_json'], true); $seo = is_array($rewrite) && is_array($rewrite['seo'] ?? null) ? $rewrite['seo'] : null; if (is_array($seo)) : ?><br><?php echo esc_html((string) ($seo['visibility_label'] ?? '')); ?><br><?php echo esc_html((string) ($seo['seo_analysis_label'] ?? '')); ?><br><?php echo esc_html((string) ($seo['readability_label'] ?? '')); ?><br><?php echo esc_html((string) ($seo['keyphrase_label'] ?? '')); ?><br><?php echo esc_html((string) ($seo['social_label'] ?? '')); ?><?php endif; endif; ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table>
        <?php $pages = (int) ceil($total_rows / $per_page); if ($pages > 1) : ?>
        <div class="tablenav"><div class="tablenav-pages"><span class="displaying-num"><?php echo esc_html(number_format_i18n($total_rows)); ?> items</span> <?php for ($p = 1; $p <= $pages; $p++) : ?><a class="button <?php echo $p === $page ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'tr-ai-wire-queue', 'status' => $status_filter, 'paged' => $p), admin_url('admin.php'))); ?>"><?php echo esc_html((string) $p); ?></a> <?php endfor; ?></div></div>
        <?php endif; ?>
        </div>
        <?php
    }
}
