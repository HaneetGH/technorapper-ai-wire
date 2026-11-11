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
        add_submenu_page('tr-ai-wire', 'Social settings', 'Social settings', 'manage_options', 'tr-ai-wire-social-settings', array($this, 'social_settings_page'));
        add_submenu_page('tr-ai-wire', 'Social queue', 'Social queue', 'manage_options', 'tr-ai-wire-social', array($this, 'social_distribution_page'));
        add_submenu_page('tr-ai-wire', 'Price estimate', 'Price estimate', 'manage_options', 'tr-ai-wire-costs', array($this, 'costs_page'));
    }

    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $this->settings();
        $dashboard_key = get_option(self::API_KEY_OPTION, '');
        $key_ready = (bool) $this->api_key();
        $key_managed_in_dashboard = is_string($dashboard_key) && $dashboard_key !== '';
        $gemini_dashboard_key = get_option(self::GEMINI_API_KEY_OPTION, '');
        $gemini_ready = (bool) $this->gemini_api_key();
        $gemini_managed_in_dashboard = is_string($gemini_dashboard_key) && $gemini_dashboard_key !== '';
        ?>
        <div class="wrap">
            <h1>TechnoRapper AI Wire</h1>
            <p>Feed items are rewritten, independently reviewed, and <strong>published automatically</strong> when they pass the review and SEO gates. Uncheck Auto-publish if you want drafts instead. Social publishing is on <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-social-settings')); ?>">Social settings</a> and runs only after WordPress publishes a permanent URL.</p>
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
                    <p><label for="tr-ai-wire-gemini-key"><strong>Gemini API key</strong> (optional fallback)</label></p>
                    <p><input id="tr-ai-wire-gemini-key" name="gemini_api_key" type="password" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $gemini_managed_in_dashboard ? esc_attr('Saved — enter a new key to replace it') : esc_attr('Paste a Google AI Studio key'); ?>"></p>
                    <p class="description"><?php echo $gemini_ready ? 'Gemini key configured. ' : ''; ?>When OpenAI flags a prompt, AI Wire first retries a shorter excerpt on the same model. If that is still blocked, Gemini 2.5 Flash writes the same JSON. Leave this blank to keep OpenAI only. A blank field does not remove a saved key.</p>
                    <?php submit_button($key_managed_in_dashboard ? 'Save / replace API key' : 'Save API key', 'primary', 'submit', false); ?>
                    <?php if ($key_managed_in_dashboard) : ?>
                        <label style="margin-left:12px;"><input type="checkbox" name="remove_api_key" value="1"> Remove saved OpenAI key</label>
                    <?php endif; ?>
                    <?php if ($gemini_managed_in_dashboard) : ?>
                        <label style="margin-left:12px;"><input type="checkbox" name="remove_gemini_api_key" value="1"> Remove saved Gemini key</label>
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
                    <tr><th colspan="2"><h2 style="margin:12px 0 0;">Map content</h2><p class="description">Same mapping as feed-to-post plugins: choose the WordPress type, taxonomy, status, title, date, and body for each item.</p></th></tr>
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
                    <tr><th scope="row"><label for="tr-monthly-budget">Monthly API budget (USD)</label></th><td><input id="tr-monthly-budget" type="number" min="0" step="0.01" name="<?php echo esc_attr(self::OPTION); ?>[monthly_budget_usd]" value="<?php echo esc_attr($s['monthly_budget_usd']); ?>"> <p class="description">This plugin's own USD ceiling. It is not the OpenAI credit balance or the project budget on the OpenAI dashboard. Processing stops when tracked usage reaches this cap. Set to 0 to stop AI calls.</p></td></tr>
                    <tr><th scope="row"><label for="tr-monthly-tokens">Monthly token limit</label></th><td><input id="tr-monthly-tokens" type="number" min="0" step="1000" name="<?php echo esc_attr(self::OPTION); ?>[monthly_token_limit]" value="<?php echo esc_attr($s['monthly_token_limit']); ?>"> <p class="description">This plugin's own input + output token ceiling, not the OpenAI balance. GPT-6 Luna is about $0.10–$0.50 per million tokens, so a 1,000,000 cap holds the queue while credit remains. Default is 200,000,000. Set to 0 to stop AI calls.</p></td></tr>
                    <tr><th scope="row"><label for="tr-output-tokens">Maximum output tokens per AI pass</label></th><td><input id="tr-output-tokens" type="number" min="200" max="8000" step="100" name="<?php echo esc_attr(self::OPTION); ?>[max_output_tokens]" value="<?php echo esc_attr($s['max_output_tokens']); ?>"> <p class="description">Use 3500–4000 so the writer can return a detailed article, not a short brief.</p></td></tr>
                    <tr><th scope="row"><label for="tr-source-chars">Maximum source characters per item</label></th><td><input id="tr-source-chars" type="number" min="1000" max="50000" step="1000" name="<?php echo esc_attr(self::OPTION); ?>[max_source_chars]" value="<?php echo esc_attr($s['max_source_chars']); ?>"></td></tr>
                    <tr><th scope="row">AI models</th><td>Writer: <?php $this->model_select('writer_model', $s['writer_model']); ?><br>Reviewer: <?php $this->model_select('reviewer_model', $s['reviewer_model']); ?><p class="description">Usage estimates use published standard rates for these models. Recheck provider pricing before changing models.</p></td></tr>
                    <tr><th scope="row">Independent web search</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enable_web_search]" value="1" <?php checked($s['enable_web_search'], 1); ?>> Enable up to one web-search call during each review</label><p class="description">Adds search-tool charges (currently $0.01 per call, plus tokens). Search is corroboration, not a guarantee. Leave off to check claims against the supplied feed only.</p></td></tr>
                    <tr><th scope="row">Editorial A/B experiment</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enable_experiment]" value="1" <?php checked($s['enable_experiment'], 1); ?>> Enable 50/50 writer experiment</label><p class="description">A = current writer. B = original editorial writer. Assignment is stable per source item. Disable to use A for new items.</p><label for="tr-experiment-id">Experiment ID</label> <input id="tr-experiment-id" type="text" maxlength="64" name="<?php echo esc_attr(self::OPTION); ?>[experiment_id]" value="<?php echo esc_attr($s['experiment_id']); ?>"></td></tr>
                    <tr><th scope="row"><label for="tr-comic-daily">Comic posts per day</label></th><td><input id="tr-comic-daily" type="number" min="0" max="100" name="<?php echo esc_attr(self::OPTION); ?>[comic_daily_limit]" value="<?php echo esc_attr((string) (int) ($s['comic_daily_limit'] ?? 0)); ?>"> <p class="description">Today’s first N articles (UTC) get a restrained dry wink in the article and the social graphic. The rest stay straight so you can compare them on <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-experiments')); ?>">A/B experiments</a>. Assignment is kept on retry. 0 turns comic off.</p></td></tr>
                    <tr><th scope="row">When to create a draft</th><td>
                        <p><label for="tr-draft-min-score"><strong>Minimum review score</strong></label> <input id="tr-draft-min-score" type="number" min="0" max="100" name="<?php echo esc_attr(self::OPTION); ?>[draft_min_score]" value="<?php echo esc_attr($s['draft_min_score']); ?>"> <span class="description">0–100. The independent reviewer scores a website-publishable article at 85–89 and an Instagram-grade original article at 90 or higher. Complete articles are not parked at 85. 70–84 means a concrete edit is still required. Below this stays quarantined. Retry asks the reviewer again; it does not publish a score under this minimum.</span></p>
                        <p><label>Accept reviewer verdict </label><select name="<?php echo esc_attr(self::OPTION); ?>[draft_allow_revise]"><option value="1" <?php selected($s['draft_allow_revise'], 1); ?>>pass or revise</option><option value="0" <?php selected($s['draft_allow_revise'], 0); ?>>pass only</option></select></p>
                        <p><label>Maximum similarity risk </label><select name="<?php echo esc_attr(self::OPTION); ?>[draft_max_similarity]"><?php foreach (array('high' => 'Allow high (most drafts)', 'medium' => 'Medium or lower', 'low' => 'Low only (strictest)') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($s['draft_max_similarity'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_require_grounded]" value="1" <?php checked($s['draft_require_grounded'], 1); ?>> Require grounded in the source</label></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_require_useful]" value="1" <?php checked($s['draft_require_useful'], 1); ?>> Require useful</label></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_require_new]" value="1" <?php checked($s['draft_require_new'], 1); ?>> Require original writing vs the source</label></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_block_policy_flags]" value="1" <?php checked($s['draft_block_policy_flags'], 1); ?>> Block if policy flags are present</label></p>
                        <p><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[draft_block_fact_flags]" value="1" <?php checked($s['draft_block_fact_flags'], 1); ?>> Block if fact flags are present</label></p>
                        <p class="description">Reject and a score below the minimum never publish — including Retry. Fact flags mean a concrete claim is missing from or contradicts the source. Style notes and labeled analysis are not fact flags. A local profanity/slur list and OpenAI Moderation also run on every article and social caption before it goes out; those holds cannot be turned off. A story-value check also cannot be turned off: thin blurbs, deal lists, coupons, best-of lists, and recaps stay in the queue, and the reviewer must say the story is big enough that readers would look for it. Auto-publish is at the top of this form.</p>
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
                <p class="description">This stays on this page. A background worker fetches new feed items, rewrites, independently reviews, and publishes (or saves drafts if Auto-publish is off). A progress bar at the top tracks it. Save settings first if you just changed feeds.</p>
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
                <p class="description">Re-assign desks and featured images on already published AI Wire posts so they can appear on the home board, not only at their own URL. This stays on the page and shows a progress bar.</p>
                <?php submit_button('Repair existing posts for homepage', 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public function social_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $this->settings();
        $social_credentials = $this->social_credentials();
        $x_ready = TechnoRapper_AI_Wire::x_oauth1_ready($social_credentials);
        $ig_ready = TechnoRapper_AI_Wire::instagram_credentials_ready($social_credentials);
        $x_on = !empty($s['social_x_enabled']) || !$x_ready;
        $ig_on = !empty($s['social_instagram_enabled']) || !$ig_ready;
        $option = self::OPTION;
        ?>
        <div class="wrap">
            <h1>Social settings</h1>
            <?php if (!empty($_GET['social_credentials_saved'])) : ?><div class="notice notice-success is-dismissible"><p>Social settings saved.<?php
            $backfill = (int) ($_GET['social_backfill'] ?? 0);
            $backfill_platform = sanitize_key((string) ($_GET['social_backfill_platform'] ?? ''));
            if ($backfill > 0 && $backfill_platform !== '') {
                $label = TechnoRapper_AI_Wire::social_platform_label($backfill_platform);
                echo ' Queued ' . esc_html((string) $backfill) . ' existing article' . ($backfill === 1 ? '' : 's') . ' for ' . esc_html($label) . ' using saved graphics — no new images will be generated.';
            }
            ?></p></div><?php endif; ?>
            <?php if (!empty($_GET['site_context'])) : ?><div class="notice notice-info is-dismissible"><p>We are understanding your site so everything we do for social stays in your context.</p></div><?php endif; ?>
            <p>Connect X, Instagram, and YouTube here, then turn each network on or off without removing its tokens. Delivery status is on the <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-social')); ?>">Social queue</a>.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="tr_ai_wire_save_social_credentials">
                <input type="hidden" name="<?php echo esc_attr($option); ?>[social_platform_toggles]" value="1">
                <?php wp_nonce_field('tr_ai_wire_save_social_credentials'); ?>
                <div class="card" style="max-width:900px;margin:16px 0;padding:16px 20px;">
                    <h2>X</h2>
                    <p>Status: <strong><?php echo esc_html(TechnoRapper_AI_Wire::x_status_text($social_credentials)); ?></strong> · posting is <strong><?php echo !empty($s['social_x_enabled']) && TechnoRapper_AI_Wire::x_credentials_ready($social_credentials) ? 'on' : 'off'; ?></strong></p>
                    <p class="description">From the developer portal → Keys and tokens, save all four values. App access must be Read and Write. The Access Token + Secret popup is OAuth 1.0a. Bearer is not needed.</p>
                    <p><label for="tr-x-api-key">API Key</label><br><input id="tr-x-api-key" name="social[x_api_key]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($social_credentials['x_api_key']) ? esc_attr('Saved — enter a new key to replace it') : esc_attr('API Key / Consumer Key'); ?>"></p>
                    <p><label for="tr-x-api-secret">API Key Secret</label><br><input id="tr-x-api-secret" name="social[x_api_secret]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($social_credentials['x_api_secret']) ? esc_attr('Saved — enter a new secret to replace it') : esc_attr('API Key Secret / Consumer Secret'); ?>"></p>
                    <p><label for="tr-x-access-token">Access Token</label><br><input id="tr-x-access-token" name="social[x_access_token]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($social_credentials['x_access_token']) ? esc_attr('Saved — enter a new token to replace it') : esc_attr('Access Token'); ?>"></p>
                    <p><label for="tr-x-access-secret">Access Token Secret</label><br><input id="tr-x-access-secret" name="social[x_access_token_secret]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($social_credentials['x_access_token_secret']) ? esc_attr('Saved — enter a new secret to replace it') : esc_attr('Access Token Secret'); ?>"></p>
                    <?php if ($x_ready) : ?><p><label><input type="checkbox" name="remove_x_oauth" value="1"> Remove saved X Keys and tokens</label></p><?php endif; ?>
                    <p><label><input id="tr-social-x-enabled" type="checkbox" name="<?php echo esc_attr($option); ?>[social_x_enabled]" value="1" <?php checked($x_on); ?>> <strong>Post to X</strong></label></p>
                    <p class="description">Leave this off to keep the four Keys and tokens saved without posting to X. Instagram can stay on.</p>
                    <details style="margin:12px 0;">
                        <summary>Advanced: OAuth 2.0 Bearer (optional, not needed)</summary>
                        <p><label for="tr-x-token">OAuth 2.0 user token</label><br><input id="tr-x-token" name="social[x_user_access_token]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($social_credentials['x_user_access_token']) ? esc_attr('Saved — enter a new token to replace it') : esc_attr('Only if you already have an OAuth 2.0 user access token'); ?>"></p>
                        <?php if (!empty($social_credentials['x_user_access_token'])) : ?><p><label><input type="checkbox" name="remove_x_user_access_token" value="1"> Remove saved X Bearer token</label></p><?php endif; ?>
                    </details>
                </div>
                <div class="card" style="max-width:900px;margin:16px 0;padding:16px 20px;">
                    <h2>Instagram</h2>
                    <p>Status: <?php echo $ig_ready ? '<strong>Page token and Professional account ID saved</strong>' : '<strong>not connected</strong>'; ?> · posting is <strong><?php echo !empty($s['social_instagram_enabled']) && $ig_ready ? 'on' : 'off'; ?></strong></p>
                    <p><label for="tr-ig-token"><strong>Instagram Page access token</strong></label><br><input id="tr-ig-token" name="social[instagram_user_access_token]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($social_credentials['instagram_user_access_token']) ? esc_attr('Saved — enter a new token to replace it') : esc_attr('Paste a Page token for the linked Professional account'); ?>"></p>
                    <?php if (!empty($social_credentials['instagram_user_access_token'])) : ?><p><label><input type="checkbox" name="remove_instagram_user_access_token" value="1"> Remove saved Instagram token</label></p><?php endif; ?>
                    <p><label for="tr-ig-user-id"><strong>Instagram Professional account ID</strong></label><br><input id="tr-ig-user-id" name="social[instagram_user_id]" type="text" class="regular-text" inputmode="numeric" value="<?php echo esc_attr($social_credentials['instagram_user_id']); ?>"></p>
                    <p><label><input id="tr-social-ig-enabled" type="checkbox" name="<?php echo esc_attr($option); ?>[social_instagram_enabled]" value="1" <?php checked($ig_on); ?>> <strong>Post to Instagram</strong></label></p>
                    <p class="description">Leave this off to keep the Instagram token saved without posting. X can stay on. Use the Facebook Page access token from Graph API Explorer GET me/accounts, not the User Token at the top of Explorer.</p>
                </div>
                <div class="card" style="max-width:900px;margin:16px 0;padding:16px 20px;">
                    <h2>YouTube</h2>
                    <?php
                    $yt_ready = TechnoRapper_AI_Wire::youtube_credentials_ready($social_credentials);
                    $yt_oauth_ready = TechnoRapper_AI_Wire_YouTube_OAuth::configured($social_credentials);
                    $yt_on = !empty($s['social_youtube_enabled']) || !$yt_ready;
                    $yt_channel = (string) ($social_credentials['youtube_channel_title'] ?? '');
                    ?>
                    <p>Status: <?php echo $yt_ready ? '<strong>channel connected' . ($yt_channel !== '' ? ' — ' . esc_html($yt_channel) : '') . '</strong>' : '<strong>not connected</strong>'; ?> · posting is <strong><?php echo !empty($s['social_youtube_enabled']) && $yt_ready ? 'on' : 'off'; ?></strong></p>
                    <p class="description">YouTube gets the same spoken 9:16 Short as the Instagram Reel — not a still image. The file uploads <strong>public immediately</strong>: title, description, tags, category, language, license, audience (not made for kids), and AI-content disclosure are all set in that API call. There is no YouTube draft or scheduled-publish step. Google does not charge per upload; default quota is 100 uploads/day.</p>
                    <p><strong>Connect with Google sign-in.</strong> A YouTube or Google API key cannot upload. Google requires OAuth (Sign in with Google) so this site can post as your channel. The client ID/secret below identify this plugin; they are not the channel login.</p>
                    <p><label for="tr-yt-client-id"><strong>Google OAuth client ID</strong></label><br><input id="tr-yt-client-id" name="social[youtube_client_id]" type="text" class="regular-text" autocomplete="off" placeholder="<?php echo TechnoRapper_AI_Wire_YouTube_OAuth::client_id($social_credentials) !== '' ? esc_attr('Saved — enter a new ID to replace it') : esc_attr('Google Cloud → APIs & Services → Credentials → OAuth client'); ?>" value=""></p>
                    <p><label for="tr-yt-client-secret"><strong>Google OAuth client secret</strong></label><br><input id="tr-yt-client-secret" name="social[youtube_client_secret]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo TechnoRapper_AI_Wire_YouTube_OAuth::client_secret($social_credentials) !== '' ? esc_attr('Saved — enter a new secret to replace it') : esc_attr('OAuth client secret, not an API key'); ?>"></p>
                    <p class="description">Create a Web application OAuth client, enable YouTube Data API v3, and add this redirect URI: <code><?php echo esc_html(TechnoRapper_AI_Wire_YouTube_OAuth::redirect_uri()); ?></code>. Or set <code>TR_AI_WIRE_GOOGLE_CLIENT_ID</code> and <code>TR_AI_WIRE_GOOGLE_CLIENT_SECRET</code> in wp-config.php.</p>
                    <?php if ($yt_oauth_ready) : ?>
                    <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tr_ai_wire_youtube_oauth_start'), 'tr_ai_wire_youtube_oauth_start')); ?>"><?php echo $yt_ready ? 'Reconnect with Google' : 'Sign in with Google'; ?></a> <span class="description">Opens Google single sign-on, then returns a channel refresh token. That is the supported upload login.</span></p>
                    <?php else : ?>
                    <p class="description">Save the Google OAuth client ID and secret first, then a <strong>Sign in with Google</strong> button appears here.</p>
                    <?php endif; ?>
                    <details style="margin:12px 0;">
                        <summary>Advanced: paste a Google refresh token instead</summary>
                        <p class="description">Use this only if you already completed Google OAuth elsewhere. A YouTube Data API key will not work here. A refresh token stays valid; an access token expires in about an hour.</p>
                        <p><label for="tr-yt-refresh"><strong>Google refresh token</strong></label><br><input id="tr-yt-refresh" name="social[youtube_refresh_token]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($social_credentials['youtube_refresh_token']) ? esc_attr('Saved — enter a new token to replace it') : esc_attr('OAuth refresh token for the channel'); ?>"></p>
                        <p><label for="tr-yt-access"><strong>Google access token</strong> (optional)</label><br><input id="tr-yt-access" name="social[youtube_access_token]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($social_credentials['youtube_access_token']) ? esc_attr('Saved — enter a new token to replace it') : esc_attr('Short-lived; leave blank to refresh automatically'); ?>"></p>
                    </details>
                    <?php if ($yt_ready) : ?><p><label><input type="checkbox" name="remove_youtube_oauth" value="1"> Remove saved YouTube channel</label></p><?php endif; ?>
                    <p><label><input id="tr-social-yt-enabled" type="checkbox" name="<?php echo esc_attr($option); ?>[social_youtube_enabled]" value="1" <?php checked($yt_on); ?>> <strong>Post to YouTube Shorts</strong></label></p>
                    <p><label for="tr-yt-category">YouTube category</label>
                    <select id="tr-yt-category" name="<?php echo esc_attr($option); ?>[social_youtube_category_id]">
                        <?php foreach (TechnoRapper_AI_Wire::youtube_video_categories() as $cat_id => $cat_label) : ?>
                        <option value="<?php echo esc_attr((string) $cat_id); ?>" <?php selected((string) ($s['social_youtube_category_id'] ?? '28'), (string) $cat_id); ?>><?php echo esc_html($cat_label); ?></option>
                        <?php endforeach; ?>
                    </select></p>
                    <p><label><input type="checkbox" name="<?php echo esc_attr($option); ?>[social_youtube_notify_subscribers]" value="1" <?php checked(!empty($s['social_youtube_notify_subscribers'])); ?>> Notify YouTube subscribers when a Short goes live</label></p>
                    <p class="description">Leave posting off to keep the channel connected without uploading. Reels per day still gates the spoken file. X and Instagram stills are unchanged. Subscriber notifications are optional; the Short is public either way.</p>
                </div>
                <div class="card" style="max-width:900px;margin:16px 0;padding:16px 20px;">
                    <h2>Distribution</h2>
                    <p><label for="tr-social-daily-limit">Maximum articles distributed per day</label> <input id="tr-social-daily-limit" type="number" min="1" max="20" name="<?php echo esc_attr($option); ?>[social_daily_limit]" value="<?php echo esc_attr($s['social_daily_limit']); ?>"> <span class="description">Each article can create one post per network that is turned on.</span></p>
                    <p><label for="tr-social-min-score">Minimum review score to post stills</label> <input id="tr-social-min-score" type="number" min="0" max="100" name="<?php echo esc_attr($option); ?>[social_min_review_score]" value="<?php echo esc_attr((int) ($s['social_min_review_score'] ?? 90)); ?>"> <span class="description">Only stories at or above this score get an Instagram or X graphic. 90 now means a complete original article (named change in the title, unique angle, takeaways, FAQ). A website-publishable 85–89 stays on the site. The writer aims at 90; a clustered 85–89 with no remaining defect is raised to 90, and one polish pass runs when the reviewer named fixes. 0 posts every published article. Default 90. Instagram captions never include a URL (link in bio instead), and stills are spaced at least 3 hours apart. Reels stay off until you raise Reels per day.</span></p>
                    <p class="description">Outbound captions, Reel scripts, YouTube titles/descriptions/tags, and on-image lines are scanned for profanity/slurs and sent through OpenAI Moderation before X, Instagram, or YouTube. A fail holds the job for review — it does not post.</p>
                    <p><label for="tr-social-image-budget">Monthly social image budget (USD)</label> <input id="tr-social-image-budget" type="number" min="0" step="0.01" name="<?php echo esc_attr($option); ?>[social_image_budget_usd]" value="<?php echo esc_attr($s['social_image_budget_usd']); ?>"> <span class="description">Set to 0 to pause new social images. Saved graphics are still reused.</span></p>
                    <input type="hidden" name="<?php echo esc_attr($option); ?>[social_mix_saved]" value="1">
                    <h3 style="margin:16px 0 8px;">How many of each post</h3>
                    <p class="description">Set a type to 0 to turn it off. The job fills Reel quota first (one spoken story), then carousel, then collages, then single stories.</p>
                    <p><label for="tr-social-reel-daily">Reel posts per day</label> <input id="tr-social-reel-daily" type="number" min="0" max="10" name="<?php echo esc_attr($option); ?>[social_reel_daily_limit]" value="<?php echo esc_attr($s['social_reel_daily_limit'] ?? 0); ?>"> <span class="description">Spoken 9:16 video of one article. 0 keeps Reels and YouTube Shorts off. Instagram uses that file as a Reel; YouTube uploads the same file as a Short. X still gets the still poster.</span></p>
                    <p><label for="tr-social-carousel-daily">Carousel posts per day</label> <input id="tr-social-carousel-daily" type="number" min="0" max="10" name="<?php echo esc_attr($option); ?>[social_carousel_daily_limit]" value="<?php echo esc_attr($s['social_carousel_daily_limit'] ?? 3); ?>"> <span class="description">Swipeable Instagram posts. On smaller accounts these usually beat single stills. Each one uses several website articles and a curiosity slide so people keep swiping. Default 3.</span></p>
                    <p><label for="tr-social-slide-count">Website articles in each carousel or collage</label> <select id="tr-social-slide-count" name="<?php echo esc_attr($option); ?>[social_slide_count]"><?php foreach (array(4, 5, 6) as $pack_n) : ?><option value="<?php echo esc_attr((string) $pack_n); ?>" <?php selected((int) ($s['social_slide_count'] ?? 5), $pack_n); ?>><?php echo esc_html((string) $pack_n); ?></option><?php endforeach; ?></select></p>
                    <p><label for="tr-social-collage-daily">Collage posts per day</label> <input id="tr-social-collage-daily" type="number" min="0" max="10" name="<?php echo esc_attr($option); ?>[social_collage_daily_limit]" value="<?php echo esc_attr($s['social_collage_daily_limit'] ?? 0); ?>"> <span class="description">Several website articles in one grid image.</span></p>
                    <p><label for="tr-social-single-daily">Single-story posts per day</label> <input id="tr-social-single-daily" type="number" min="0" max="10" name="<?php echo esc_attr($option); ?>[social_single_daily_limit]" value="<?php echo esc_attr($s['social_single_daily_limit'] ?? 2); ?>"> <span class="description">One website article, one social post. Default 2. Prefer carousels when a story can fill several slides.</span></p>
                    <p><label for="tr-social-video-budget">Monthly Reel video budget (USD)</label> <input id="tr-social-video-budget" type="number" min="0" step="0.01" name="<?php echo esc_attr($option); ?>[social_video_budget_usd]" value="<?php echo esc_attr($s['social_video_budget_usd'] ?? 10); ?>"> <span class="description">Default $10 covers about one 8s Veo Lite clip per day. Raise this before switching to Fast, full Veo, or Kling. <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-costs')); ?>">Price estimate</a></span></p>
                    <fieldset id="tr-social-reel-model">
                        <legend>Reel motion model</legend>
                        <p class="description">Suggested: <strong>Veo 3.1 Lite</strong> on a $10 budget. Click the info icon on a model for its price, or open the <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-costs')); ?>">Price estimate</a> page.</p>
                        <?php
                        $selected_model = (string) ($s['social_reel_video_model'] ?? 'veo-lite');
                        foreach ($this->social_reel_video_models() as $value => $spec) :
                            $info_id = 'tr-model-info-' . $value;
                            $clip_example = $this->social_reel_clip_seconds(array_merge($s, array('social_reel_video_model' => $value, 'social_reel_clip_seconds' => 8)));
                            $clip_cost = round($clip_example * (float) $spec['usd_per_second'], 4);
                            $month_cost = $clip_cost * 30;
                            ?>
                        <div style="margin:8px 0 12px;">
                            <label for="tr-reel-model-<?php echo esc_attr($value); ?>">
                                <input id="tr-reel-model-<?php echo esc_attr($value); ?>" type="radio" name="<?php echo esc_attr($option); ?>[social_reel_video_model]" value="<?php echo esc_attr($value); ?>" <?php checked($selected_model, $value); ?>>
                                <strong><?php echo esc_html((string) $spec['label']); ?></strong>
                                <?php if (!empty($spec['recommended'])) : ?><span class="description">(suggested)</span><?php endif; ?>
                                — <?php echo esc_html((string) $spec['short']); ?>
                            </label>
                            <details style="display:inline-block;margin-left:6px;vertical-align:middle;">
                                <summary style="cursor:pointer;list-style:none;display:inline-flex;align-items:center;" title="<?php echo esc_attr('Pricing for ' . $spec['label']); ?>">
                                    <span class="dashicons dashicons-info" aria-hidden="true"></span>
                                    <span class="screen-reader-text"><?php echo esc_html('Pricing for ' . $spec['label']); ?></span>
                                </summary>
                                <div class="notice notice-info inline" id="<?php echo esc_attr($info_id); ?>" style="margin:8px 0 0;padding:8px 12px;">
                                    <p><strong><?php echo esc_html((string) $spec['label']); ?></strong> — about $<?php echo esc_html(number_format_i18n((float) $spec['usd_per_second'], 3)); ?>/s silent.
                                    <?php echo esc_html((string) $clip_example); ?>s ≈ $<?php echo esc_html(number_format_i18n($clip_cost, 2)); ?>.
                                    One Reel/day ≈ $<?php echo esc_html(number_format_i18n($month_cost, 2)); ?>/month.
                                    <?php echo $month_cost <= 10 ? 'Fits the $10 default.' : 'Raise the video budget before using this daily.'; ?></p>
                                    <p><?php echo esc_html((string) $spec['blurb']); ?> Allowed lengths: <?php echo esc_html(implode('/', array_map('strval', (array) $spec['durations']))); ?>s.
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-costs#tr-cost-' . $value)); ?>">Full estimate</a>
                                    · <a href="<?php echo esc_url((string) $spec['docs']); ?>" target="_blank" rel="noopener noreferrer">Fal page</a></p>
                                </div>
                            </details>
                        </div>
                        <?php endforeach; ?>
                    </fieldset>
                    <p><label for="tr-social-reel-clip">Generated motion length</label> <select id="tr-social-reel-clip" name="<?php echo esc_attr($option); ?>[social_reel_clip_seconds]"><?php foreach (array(4, 5, 6, 8, 10, 15) as $clip_n) : ?><option value="<?php echo esc_attr((string) $clip_n); ?>" <?php selected((int) ($s['social_reel_clip_seconds'] ?? 8), $clip_n); ?>><?php echo esc_html((string) $clip_n); ?> seconds</option><?php endforeach; ?></select> <span class="description">Veo allows 4/6/8s. Kling allows 5–15s; the worker sends the nearest length that model accepts. Writer voiceover can run 15–18s; extra time holds the last frame, then the brand sting.</span></p>
                    <p><label for="tr-fal-key">Fal.ai API key</label><br><input id="tr-fal-key" name="social[fal_api_key]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $this->fal_api_key() !== '' ? esc_attr('Saved — enter a new key to replace it') : esc_attr('Required for Reel motion (Veo via Fal)'); ?>"></p>
                    <?php if ($this->fal_api_key() !== '') : ?><p><label><input type="checkbox" name="remove_fal_api_key" value="1"> Remove saved Fal.ai key</label></p><?php endif; ?>
                    <?php
                    $clip_cost = $this->social_reel_video_estimate_usd($s);
                    ?>
                    <p class="description">This clip estimate: $<?php echo esc_html(number_format_i18n($clip_cost, 2)); ?>. At one Reel/day that is about $<?php echo esc_html(number_format_i18n($clip_cost * 30, 2)); ?>/month on the selected model. See <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-costs')); ?>">Price estimate</a> for every model.</p>
                    <?php if ((int) ($s['social_reel_daily_limit'] ?? 0) > 0 && $this->social_ffmpeg_path() === '') : ?>
                    <div class="notice notice-warning inline"><p>Reels need <code>ffmpeg</code> on this host. Until it is installed, Instagram Reel jobs will be held for review. The plugin will not fall back to a slideshow of posters.</p></div>
                    <?php endif; ?>
                    <?php if ((int) ($s['social_reel_daily_limit'] ?? 0) > 0 && $this->fal_api_key() === '') : ?>
                    <div class="notice notice-warning inline"><p>Reels need a Fal.ai API key to generate motion. Until it is saved, Instagram Reel jobs will be held. The plugin will not Ken Burns the posters instead.</p></div>
                    <?php endif; ?>
                    <p class="description">Artwork is GPT Image 2 low; type is overlaid locally for stills. A Reel uses the current Writer model for the spoken script, GPT-4o Mini TTS for the voice, the Fal model you selected to animate the social photograph, and ffmpeg to mux captions. One article only — not a roundup of the day’s posts. X still gets the still poster. That file is a social asset only — it never becomes the article featured image. Image estimate $0.006 each; Mini TTS about $0.004. X API charges are separate.</p>
                </div>
                <?php $this->render_social_news_formats_card($s, $option); ?>
                <?php submit_button('Save social settings'); ?>
            </form>
            <?php $this->render_site_context_card('tr-ai-wire-social-settings'); ?>
        </div>
        <?php
    }

    public function costs_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = $this->settings();
        $clip = $this->social_reel_clip_seconds($s);
        ?>
        <div class="wrap">
            <h1>Price estimate</h1>
            <p>Local estimates the plugin uses to pause spend. They are not Fal or OpenAI invoices. Recheck provider pricing before raising a budget. Social stills stay on the <strong>$<?php echo esc_html(number_format_i18n((float) ($s['social_image_budget_usd'] ?? 5), 2)); ?></strong> image budget. Reel motion stays on the <strong>$<?php echo esc_html(number_format_i18n((float) ($s['social_video_budget_usd'] ?? 10), 2)); ?></strong> video budget.</p>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-social-settings')); ?>">Back to Social settings</a></p>
            <h2>Suggested Reel model</h2>
            <p><strong>Veo 3.1 Lite</strong>, 8 seconds, silent 720p. About <strong>$0.24 per clip</strong> and <strong>$7/month</strong> at one Reel/day — that is what the $10 video budget is sized for. Switch models below only after traffic can pay for them.</p>
            <h2>Reel motion models (Fal.ai, silent)</h2>
            <table class="widefat striped" style="max-width:960px;">
                <thead>
                    <tr>
                        <th>Model</th>
                        <th>Per second</th>
                        <th>This length (<?php echo esc_html((string) $clip); ?>s)</th>
                        <th>1 Reel/day</th>
                        <th>Fits $10?</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($this->social_reel_video_models() as $value => $spec) :
                    $seconds = $this->social_reel_clip_seconds(array_merge($s, array('social_reel_video_model' => $value)));
                    $each = round($seconds * (float) $spec['usd_per_second'], 4);
                    $month = $each * 30;
                    ?>
                    <tr id="tr-cost-<?php echo esc_attr($value); ?>">
                        <td>
                            <strong><?php echo esc_html((string) $spec['label']); ?></strong>
                            <?php if (!empty($spec['recommended'])) : ?>— suggested<?php endif; ?>
                            <br><span class="description"><?php echo esc_html((string) $spec['blurb']); ?> Lengths: <?php echo esc_html(implode('/', array_map('strval', (array) $spec['durations']))); ?>s.</span>
                        </td>
                        <td>$<?php echo esc_html(number_format_i18n((float) $spec['usd_per_second'], 3)); ?></td>
                        <td>$<?php echo esc_html(number_format_i18n($each, 2)); ?> <span class="description">(<?php echo esc_html((string) $seconds); ?>s)</span></td>
                        <td>$<?php echo esc_html(number_format_i18n($month, 2)); ?></td>
                        <td><?php echo $month <= 10 ? 'Yes at 1/day' : 'Raise budget'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Writer GPT script tokens stay on the article budget. Mini TTS is about $0.004 per voiceover on the image budget. X API credits, Instagram quota, and YouTube Data API quota are separate. Fal bills the motion clip; the plugin only reserves the local estimate above. YouTube uploads have no Google per-video fee.</p>
            <h2>Social stills</h2>
            <p>GPT Image 2 low is about <strong>$0.006</strong> per branded poster. Default image budget is $5/month. Details: plugin file <code>docs/IMAGE-GENERATION-COST.md</code>.</p>
        </div>
        <?php
    }

    private function render_social_news_formats_card($settings, $option) {
        $catalog = TechnoRapper_AI_Wire::social_news_formats();
        $selected = TechnoRapper_AI_Wire::sanitize_social_news_format_ids($settings['social_news_formats'] ?? array_keys($catalog));
        $ideas = $this->social_custom_ideas();
        $groups = array();
        foreach ($catalog as $id => $format) {
            $group = (string) ($format['group'] ?? 'Single story');
            if (!isset($groups[$group])) {
                $groups[$group] = array();
            }
            $groups[$group][$id] = $format;
        }
        ?>
        <div class="card" style="max-width:900px;margin:16px 0;padding:16px 20px;">
            <h2>News graphics we can produce</h2>
            <p>Select every format you want in rotation. Unchecked formats are never generated. Sample posts you may have seen elsewhere are <strong>examples of look-and-feel only</strong> — they are not the options below, and we will not reuse those headlines, brands, or layouts as presets.</p>
            <?php foreach ($groups as $group => $formats) : ?>
                <h3 style="margin:16px 0 8px;"><?php echo esc_html($group); ?></h3>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
                    <?php foreach ($formats as $id => $format) : ?>
                        <label for="tr-news-format-<?php echo esc_attr($id); ?>" style="display:block;margin:0;padding:12px 14px;border:1px solid #dcdcde;border-radius:4px;background:#fff;">
                            <input id="tr-news-format-<?php echo esc_attr($id); ?>" type="checkbox" name="<?php echo esc_attr($option); ?>[social_news_formats][]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id, $selected, true)); ?>>
                            <strong><?php echo esc_html((string) $format['label']); ?></strong>
                            <span class="description" style="display:block;margin:6px 0 0;"><?php echo esc_html((string) $format['description']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <h3 style="margin:24px 0 8px;">Your ideas</h3>
            <p class="description">Upload a written brief and an optional reference image. We treat it as another selectable format. Do not upload someone else’s finished post as a template to copy — describe the layout you want in your own words. Ideas that ask for nudity, sexualized bodies, children, gore, or other policy violations are rejected before they can be saved. Captions with profanity or sexual bait are held for review instead of posting.</p>
            <?php if ($ideas) : ?>
                <table class="widefat striped" style="margin:12px 0;">
                    <thead><tr><th>Use</th><th>Idea</th><th>Reference</th><th>Remove</th></tr></thead>
                    <tbody>
                    <?php foreach ($ideas as $idea) : ?>
                        <tr>
                            <td><label><input type="checkbox" name="social_idea_enabled[]" value="<?php echo esc_attr($idea['id']); ?>" <?php checked(!empty($idea['enabled'])); ?>> On</label></td>
                            <td>
                                <strong><?php echo esc_html($idea['title']); ?></strong>
                                <p class="description" style="margin:4px 0 0;"><?php echo esc_html($idea['description']); ?></p>
                                <input type="hidden" name="social_idea_keep[]" value="<?php echo esc_attr($idea['id']); ?>">
                            </td>
                            <td><?php
                                if (!empty($idea['image_id'])) {
                                    echo wp_get_attachment_image((int) $idea['image_id'], 'thumbnail');
                                } else {
                                    echo '—';
                                }
                            ?></td>
                            <td><label><input type="checkbox" name="remove_social_idea[]" value="<?php echo esc_attr($idea['id']); ?>"> Remove</label></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <p><label for="tr-social-idea-title">New idea title</label><br>
                <input id="tr-social-idea-title" name="social_idea_title" type="text" class="regular-text" maxlength="80" placeholder="Night recap cover, product cluster, …"></p>
            <p><label for="tr-social-idea-description">Describe the graphic</label><br>
                <textarea id="tr-social-idea-description" name="social_idea_description" class="large-text" rows="4" maxlength="800" placeholder="Layout, lighting, type placement, and what should sit in the photo. Do not paste another publisher’s headline."></textarea></p>
            <p><label for="tr-social-idea-image">Optional reference image</label><br>
                <input id="tr-social-idea-image" name="social_idea_image" type="file" accept="image/jpeg,image/png,image/webp,image/gif"></p>
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
        $this->enqueue_background_job('fetch');
        wp_safe_redirect(admin_url('admin.php?page=tr-ai-wire-queue'));
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
            <p>Average reviewer score for items in the selected range. The yellow line is your minimum review score. Grey bars are how many items landed in that day, week, or month. 90 is a complete original article. Instagram and X stills only go out at or above the social stills minimum (default 90); 85–89 stay on the website.</p>
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
                <div class="card" style="margin:0;padding:14px 16px;"><p class="description" style="margin:0;">Social stills minimum</p><p style="font-size:28px;margin:4px 0 0;"><strong><?php echo esc_html((string) (int) ($report['social_min_score'] ?? 90)); ?></strong></p></div>
            </div>
            <p><span style="display:inline-block;width:12px;height:12px;background:#2271b1;margin-right:6px;"></span> Review score
                <span style="display:inline-block;width:12px;height:12px;background:#00a32a;margin:0 6px 0 16px;"></span> SEO score
                <span style="display:inline-block;width:12px;height:12px;background:#c3c4c7;margin:0 6px 0 16px;"></span> Item count
                <span style="display:inline-block;width:16px;border-top:2px dashed #dba617;margin:0 6px 0 16px;"></span> Minimum review score</p>
            <div style="max-width:960px;"><?php
                $chart = $this->render_score_chart($report);
                echo wp_kses($chart, $this->score_chart_allowed_html());
            ?></div>
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
                <thead><tr><th>When (UTC)</th><th>Source</th><th>Status</th><th>Review</th><th>SEO</th><th>Social</th></tr></thead>
                <tbody>
                <?php if (empty($report['recent'])) : ?>
                    <tr><td colspan="6">No scored items yet. Run the queue after the reviewer has scored an item.</td></tr>
                <?php else : foreach ($report['recent'] as $item) : ?>
                    <tr>
                        <td><?php echo esc_html($item['created_at']); ?></td>
                        <td><?php if ($item['wp_post_id']) : ?><a href="<?php echo esc_url(get_edit_post_link($item['wp_post_id'])); ?>"><?php echo esc_html($item['title']); ?></a><?php else : echo esc_html($item['title']); endif; ?></td>
                        <td><?php echo esc_html($item['status']); ?></td>
                        <td><?php echo esc_html((string) $item['score']); ?></td>
                        <td><?php echo esc_html($item['seo_score'] !== null ? (string) $item['seo_score'] : '—'); ?></td>
                        <td><?php echo esc_html($item['social'] !== '' ? $item['social'] : '—'); ?></td>
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
        <p>Active experiment: <strong><?php echo esc_html($settings['experiment_id']); ?></strong> · <?php echo !empty($settings['enable_experiment']) ? '50/50 assignment enabled' : 'Experiment disabled; new items use A'; ?> · Comic posts per day: <strong><?php echo esc_html((string) (int) ($settings['comic_daily_limit'] ?? 0)); ?></strong> (<?php echo esc_html((string) $this->comic_assigned_today()); ?> assigned today)</p>
        <table class="widefat striped"><thead><tr><th>Variant</th><th>Writer</th><th>Posts created</th><th>Quarantined</th><th>Other queue items</th></tr></thead><tbody>
        <tr><td><strong>A</strong></td><td>Current writer</td><td><?php echo esc_html((string) $counts['A']['posts']); ?></td><td><?php echo esc_html((string) $counts['A']['quarantined']); ?></td><td><?php echo esc_html((string) $counts['A']['other']); ?></td></tr>
        <tr><td><strong>B</strong></td><td>Original editorial writer</td><td><?php echo esc_html((string) $counts['B']['posts']); ?></td><td><?php echo esc_html((string) $counts['B']['quarantined']); ?></td><td><?php echo esc_html((string) $counts['B']['other']); ?></td></tr>
        </tbody></table>
        <?php
        $voice_rows = $wpdb->get_results($wpdb->prepare("SELECT voice, status, COUNT(*) AS total FROM {$this->table} WHERE experiment_id = %s GROUP BY voice, status", $settings['experiment_id']), ARRAY_A);
        $voice_counts = array('comic' => array('posts' => 0, 'quarantined' => 0, 'other' => 0), 'straight' => array('posts' => 0, 'quarantined' => 0, 'other' => 0));
        foreach ((array) $voice_rows as $row) {
            $voice = (($row['voice'] ?? '') === 'comic') ? 'comic' : 'straight';
            if (in_array($row['status'], array('draft', 'published'), true)) {
                $status = 'posts';
            } elseif ($row['status'] === 'quarantined') {
                $status = 'quarantined';
            } else {
                $status = 'other';
            }
            $voice_counts[$voice][$status] += (int) $row['total'];
        }
        $voice_view_rows = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF(pm.meta_value, ''), 'straight') AS voice, SUM(v.views) AS views, COUNT(DISTINCT v.post_id) AS articles FROM {$views_table} v INNER JOIN {$wpdb->postmeta} exp ON exp.post_id = v.post_id AND exp.meta_key = '_tr_ai_wire_experiment_id' AND exp.meta_value = %s INNER JOIN {$wpdb->posts} p ON p.ID = v.post_id AND p.post_status = 'publish' LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = v.post_id AND pm.meta_key = '_tr_ai_wire_voice' WHERE v.view_date >= DATE_SUB(UTC_DATE(), INTERVAL 30 DAY) GROUP BY voice", $settings['experiment_id']), ARRAY_A);
        $voice_views = array('comic' => array('views' => 0, 'articles' => 0), 'straight' => array('views' => 0, 'articles' => 0));
        foreach ((array) $voice_view_rows as $view_row) {
            $voice = (($view_row['voice'] ?? '') === 'comic') ? 'comic' : 'straight';
            $voice_views[$voice]['views'] = (int) $view_row['views'];
            $voice_views[$voice]['articles'] = (int) $view_row['articles'];
        }
        ?>
        <h2>Comic vs straight</h2>
        <p>Set <strong>Comic posts per day</strong> on the main AI Wire page. Compare engaged reads here before deciding whether the dry wink stays.</p>
        <table class="widefat striped"><thead><tr><th>Voice</th><th>Posts created</th><th>Quarantined</th><th>Other queue items</th></tr></thead><tbody>
        <tr><td><strong>Comic</strong> — dry wink</td><td><?php echo esc_html((string) $voice_counts['comic']['posts']); ?></td><td><?php echo esc_html((string) $voice_counts['comic']['quarantined']); ?></td><td><?php echo esc_html((string) $voice_counts['comic']['other']); ?></td></tr>
        <tr><td><strong>Straight</strong> — no comic prompt</td><td><?php echo esc_html((string) $voice_counts['straight']['posts']); ?></td><td><?php echo esc_html((string) $voice_counts['straight']['quarantined']); ?></td><td><?php echo esc_html((string) $voice_counts['straight']['other']); ?></td></tr>
        </tbody></table>
        <h2>Reader performance — last 30 days</h2>
        <p>Engaged reads are counted after a visitor remains on a published experiment article for at least 3 seconds. Repeat views of the same article in one browser session are counted once. This is a lightweight first-party engagement proxy, not a replacement for GA4.</p>
        <table class="widefat striped"><thead><tr><th>Variant</th><th>Engaged reads</th><th>Articles with reads</th><th>Average reads per article</th></tr></thead><tbody>
        <?php foreach (array('A' => 'Current writer', 'B' => 'Original editorial writer') as $variant => $label) : $avg = $view_counts[$variant]['articles'] ? round($view_counts[$variant]['views'] / $view_counts[$variant]['articles'], 1) : 0; ?>
        <tr><td><strong><?php echo esc_html($variant); ?></strong> — <?php echo esc_html($label); ?></td><td><?php echo esc_html(number_format_i18n($view_counts[$variant]['views'])); ?></td><td><?php echo esc_html(number_format_i18n($view_counts[$variant]['articles'])); ?></td><td><?php echo esc_html(number_format_i18n($avg, 1)); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <table class="widefat striped" style="margin-top:12px;"><thead><tr><th>Voice</th><th>Engaged reads</th><th>Articles with reads</th><th>Average reads per article</th></tr></thead><tbody>
        <?php foreach (array('comic' => 'Comic', 'straight' => 'Straight') as $voice => $label) : $avg = $voice_views[$voice]['articles'] ? round($voice_views[$voice]['views'] / $voice_views[$voice]['articles'], 1) : 0; ?>
        <tr><td><strong><?php echo esc_html($label); ?></strong></td><td><?php echo esc_html(number_format_i18n($voice_views[$voice]['views'])); ?></td><td><?php echo esc_html(number_format_i18n($voice_views[$voice]['articles'])); ?></td><td><?php echo esc_html(number_format_i18n($avg, 1)); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <h2>Reader and revenue measurement</h2>
        <p>Published articles automatically emit the GA4 event <code>tr_ai_wire_article_view</code> with <code>experiment_id</code>, <code>variant</code>, <code>voice</code> (<code>comic</code> or <code>straight</code>), <code>article_id</code>, and <code>article_title</code>. Drafts do not emit events.</p>
        <ol><li>Confirm your existing GA4 tag or Google Tag Manager is installed on the site.</li><li>In GA4 Admin → Custom definitions, create event-scoped dimensions for <code>experiment_id</code>, <code>variant</code>, <code>voice</code>, and <code>article_id</code>.</li><li>Wait for data to populate, then use Explore to compare event counts, engaged sessions, engagement time and key events by variant and by voice. Use article_id/page path to inspect individual URLs.</li><li>For revenue, link AdSense to GA4 where supported, or export ad revenue by page URL and join it to article_id. Compare revenue per 1,000 sessions, not raw revenue alone.</li></ol>
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
        $status_aliases = array('new' => 'pending', 'rewritten' => 'generated');
        if (isset($status_aliases[$status_filter])) {
            $status_filter = $status_aliases[$status_filter];
        }
        $allowed_statuses = array('pending', 'waiting_budget', 'retry_wait', 'generated', 'reviewed', 'draft', 'published', 'quarantined', 'error');
        if (!in_array($status_filter, $allowed_statuses, true)) { $status_filter = ''; }
        $status_match = array('pending' => array('pending', 'new'), 'generated' => array('generated', 'rewritten'));
        if ($status_filter && isset($status_match[$status_filter])) {
            $placeholders = implode(', ', array_fill(0, count($status_match[$status_filter]), '%s'));
            $where = $wpdb->prepare(' WHERE status IN (' . $placeholders . ')', $status_match[$status_filter]);
        } else {
            $where = $status_filter ? $wpdb->prepare(' WHERE status = %s', $status_filter) : '';
        }
        $total_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}{$where}");
        $offset = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, feed_name, source_title, source_url, status, voice, wp_post_id, created_at, error_text, review_json, rewrite_json FROM {$this->table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset), ARRAY_A);
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
        <div class="notice <?php echo empty($last_run['skipped']) ? 'notice-info' : 'notice-warning'; ?>"><p id="tr-ai-wire-last-run">
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
        <p><strong>This month (AI Wire only):</strong> $<?php echo esc_html(number_format_i18n((float) $usage['cost_usd'], 4)); ?> / $<?php echo esc_html(number_format_i18n((float) $s['monthly_budget_usd'], 2)); ?> · <?php echo esc_html(number_format_i18n((int) $usage['input_tokens'] + (int) $usage['output_tokens'])); ?> / <?php echo esc_html(number_format_i18n((int) $s['monthly_token_limit'])); ?> tokens · <?php echo esc_html((int) $usage['search_calls']); ?> web searches</p>
        <p class="description">These figures are this plugin's counter. A remaining OpenAI credit balance does not by itself release held items. Items stay held until the next call fits under both numbers above.</p>
        <p><strong>Daily post cap:</strong> <?php echo esc_html((int) $s['max_posts_per_day']); ?> · Posts still available today: <?php echo esc_html($this->remaining_posts_today($s)); ?> · Comic today: <?php echo esc_html((string) $this->comic_assigned_today()); ?> / <?php echo esc_html((string) (int) ($s['comic_daily_limit'] ?? 0)); ?><?php if (!empty($s['auto_publish'])) : ?> · Auto-publish is on<?php endif; ?> · <a href="<?php echo esc_url(admin_url('admin.php?page=tr-ai-wire-scores')); ?>">View score graph</a></p>
        <form method="get" style="margin:12px 0;"><input type="hidden" name="page" value="tr-ai-wire-queue"><label for="tr-ai-wire-status">Filter status </label><select id="tr-ai-wire-status" name="status"><option value="">All statuses</option><?php foreach ($allowed_statuses as $option_status) : ?><option value="<?php echo esc_attr($option_status); ?>" <?php selected($status_filter, $option_status); ?>><?php echo esc_html($option_status); ?></option><?php endforeach; ?></select> <button class="button">Apply</button></form>
        <table class="widefat striped"><thead><tr><th>Added (UTC)</th><th>Feed</th><th>Source item</th><th>Status</th><th>Voice</th><th>Post / details</th></tr></thead><tbody>
        <?php if (empty($rows)) : ?><tr><td colspan="6">No items processed yet.</td></tr><?php else : foreach ($rows as $row) : ?>
            <tr data-item-id="<?php echo esc_attr((string) (int) $row['id']); ?>"><td><?php echo esc_html($row['created_at']); ?></td><td><?php echo esc_html($row['feed_name']); ?></td><td><a href="<?php echo esc_url($row['source_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($row['source_title']); ?></a></td><td><?php echo esc_html($row['status']); ?></td><td><?php echo esc_html(($row['voice'] ?? '') === 'comic' ? 'comic' : (($row['voice'] ?? '') === 'straight' ? 'straight' : '—')); ?></td><td><?php if ($row['wp_post_id']) : ?><a href="<?php echo esc_url(get_edit_post_link((int) $row['wp_post_id'])); ?>"><?php echo $row['status'] === 'published' ? 'Edit post' : 'Edit draft'; ?></a><?php if ($row['status'] === 'published') : ?> · <a href="<?php echo esc_url(get_permalink((int) $row['wp_post_id'])); ?>" target="_blank" rel="noopener">View</a><?php endif; ?><?php endif; ?><?php if (in_array($row['status'], array('error', 'retry_wait', 'quarantined'), true)) : ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tr_ai_wire_retry_item&item_id=' . (int) $row['id']), 'tr_ai_wire_retry_item_' . (int) $row['id'])); ?>">Retry</a> <?php endif; ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=tr_ai_wire_delete_item&item_id=' . (int) $row['id']), 'tr_ai_wire_delete_item_' . (int) $row['id'])); ?>" onclick="return confirm('Delete this queue item? The WordPress post, if any, stays.');">Delete</a> <?php if ($row['error_text']) : ?><br><?php echo esc_html($row['error_text']); ?><?php endif; ?><?php if ($row['review_json']) : $review = json_decode($row['review_json'], true); if (is_array($review)) : ?><br>Review score: <?php echo esc_html((string) ($review['score'] ?? 'n/a')); ?> · <?php echo esc_html((string) ($review['verdict'] ?? '')); ?><?php endif; endif; ?><?php if ($row['rewrite_json']) : $rewrite = json_decode($row['rewrite_json'], true); $seo = is_array($rewrite) && is_array($rewrite['seo'] ?? null) ? $rewrite['seo'] : null; if (is_array($seo)) : ?><br><?php echo esc_html((string) ($seo['visibility_label'] ?? '')); ?><br><?php echo esc_html((string) ($seo['seo_analysis_label'] ?? '')); ?><br><?php echo esc_html((string) ($seo['readability_label'] ?? '')); ?><br><?php echo esc_html((string) ($seo['keyphrase_label'] ?? '')); ?><br><?php echo esc_html((string) ($seo['social_label'] ?? '')); ?><?php endif; endif; ?></td></tr>
        <?php endforeach; endif; ?>
        </tbody></table>
        <?php $pages = (int) ceil($total_rows / $per_page); if ($pages > 1) : ?>
        <div class="tablenav"><div class="tablenav-pages"><span class="displaying-num"><?php echo esc_html(number_format_i18n($total_rows)); ?> items</span> <?php for ($p = 1; $p <= $pages; $p++) : ?><a class="button <?php echo $p === $page ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'tr-ai-wire-queue', 'status' => $status_filter, 'paged' => $p), admin_url('admin.php'))); ?>"><?php echo esc_html((string) $p); ?></a> <?php endfor; ?></div></div>
        <?php endif; ?>
        </div>
        <?php
    }
}
