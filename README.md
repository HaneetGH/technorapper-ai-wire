# TechnoRapper AI Wire (MVP)

WordPress plugin that reads configured RSS/Atom feeds, generates a short original Technorapper brief, runs an editorial/fact-consistency review, and creates a WordPress Draft only when the review passes.

## Install

1. Zip the `technorapper-ai-wire` directory and upload it in **Plugins → Add New → Upload Plugin**, or copy the directory to `wp-content/plugins/`.
2. Add an OpenAI API key to `wp-config.php` before the “That's all, stop editing” line:

   ```php
   define('OPENAI_API_KEY', 'your-api-key');
   ```

   `TR_AI_WIRE_OPENAI_API_KEY` is also accepted. The key is deliberately not stored in the plugin settings. API usage is billed separately from ChatGPT.
3. Activate **TechnoRapper AI Wire**.
4. Open **AI Wire** in wp-admin, add one RSS/Atom URL per line, then set:
   - Maximum drafts per day
   - Monthly API budget in USD
   - Monthly combined input/output token limit
   - Maximum output tokens per AI pass
   - Maximum source characters per feed item
   - Writer and reviewer model
   - Optional one-call web-search corroboration
5. Use **Fetch feeds and process queue now** for an initial small supervised run. Scheduled polling runs hourly through WP-Cron.
6. Review generated drafts in the normal WordPress Posts screen. The plugin never auto-publishes.

Manual and scheduled feed runs are serialized with a database-backed lock to prevent overlapping executions. A stale lock is recoverable after 45 minutes. Deactivation clears the scheduled hook.

## Spend and volume controls

- Daily cap counts drafts created by this plugin on the current UTC day. Set to `0` to pause creation.
- Monthly USD and token limits are tracked from API usage reports. Before each request, the plugin estimates input size and reserves the configured output-token allowance; it stops if that estimate would exceed either limit.
- The optional web-search review is limited to one search tool call per story and adds the provider's search-call and token charges.
- Usage counters are calendar-month based in UTC and are estimates using the configured model's published standard token rates. Verify current provider prices when changing model pricing.
- Limits are enforced by this plugin's tracked use; they do not cap other applications using the same API account/key. Configure a provider-side project budget as an additional safeguard.

## Review behavior and current limits

- The reviewer compares claims with the supplied feed content. Without web search, that is source consistency checking, not independent verification.
- With web search enabled, the reviewer may cite online corroboration, but AI checks are not a guarantee that every fact is correct.
- Items with a failed API call or failed review are held in the AI Wire queue; passing items become drafts. A human must publish.
- Feed URLs are validated as HTTP(S); feed text is treated as untrusted input and is never allowed to control plugin behavior.
- This MVP does not migrate or modify existing Feedzy posts/jobs and does not import source images.

## Development checks

GitHub Actions runs PHP syntax checks on PHP 7.4, 8.1, and 8.3 for pushes and pull requests. This is a syntax/compatibility smoke check, not a substitute for WordPress integration tests.

## Operational notes

- WP-Cron is traffic-triggered and can run late on low-traffic sites. Configure the host's system cron to invoke WordPress cron if dependable hourly polling is needed.
- Each scheduled/manual run scans up to 10 latest items per feed and processes until the daily cap is reached. Feed caching may delay detection of new entries.
- Remove the plugin's API key from `wp-config.php` and deactivate the plugin to stop processing. Deactivation clears scheduled polling; it does not delete queue records or generated drafts.
