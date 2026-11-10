=== TechnoRapper AI Wire ===
Contributors: haneetgh
Tags: rss, editorial, openai, social, instagram
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 0.2.52
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns your RSS/Atom feeds into AI-reviewed WordPress posts, with optional X and Instagram graphics.

== Description ==

AI Wire is an open-source WordPress plugin any publisher can run. It is licensed under **GPLv2 or later**.

It takes RSS or Atom feeds you configure, writes an attributed draft, runs a separate AI review, and can publish to WordPress. After an article is live, it can optionally create one branded graphic and post it to **your** X and/or Instagram accounts. Site name, desks, and the poster brand mark come from this WordPress site.

Social distribution stays off until you turn each network on. This plugin does not ship API keys. You provide:

* An OpenAI API key
* Optional X developer Keys and tokens (API Key, API Key Secret, Access Token, Access Token Secret)
* Optional Instagram Professional account via your own Meta app

**Third-party services.** Article text may be sent to OpenAI for writing, review, and social images. If you enable a network, media and captions are sent to the X API and/or Instagram Graph API. Those services have their own terms and billing. Automated X posts spend X developer credits. Instagram Graph publishing has no Meta per-post fee; Meta enforces a rolling quota.

== Installation ==

1. Upload the plugin ZIP in Plugins → Add New → Upload Plugin, or copy the `technorapper-ai-wire` folder to `wp-content/plugins/`.
2. Activate the plugin.
3. Open **AI Wire**. Save your OpenAI API key, add feeds, and set daily/monthly limits.
4. Optional: open **Social settings** and connect your X and/or Instagram accounts. Leave posting off until you are ready.

== Frequently Asked Questions ==

= What license is this plugin? =

GPLv2 or later. See `LICENSE` in the plugin directory and https://www.gnu.org/licenses/gpl-2.0.html

= Does automated X posting need paid credits? =

Yes. The plugin posts through the X API, which spends X developer credits. A Social queue error of `credits depleted` is an X console balance/spending-limit problem, not the OpenAI image budget.

= Is Instagram API posting billed per post? =

No. Instagram Graph publishing has no Meta per-post fee. Meta enforces a rolling 24-hour publishing quota. When the quota is full, the plugin leaves the item queued and tries later.

= Are my API keys in this repository? =

No. Store keys on your WordPress host. Never commit tokens to git or paste them into public issues.

== Changelog ==

= 0.2.52 =
* Social copy is written like a newsroom growth desk: specific stakes, saveable bullets, no fake urgency. Image prompts refuse nudity, sexualized bodies, children, gore, and other policy violations before the image API is called. Unsafe captions are held for review.

= 0.2.51 =
* Social settings lists every news graphic format the plugin can produce, lets you select several at once, and accepts a written idea plus optional reference image. Those are format types, not example headlines from other accounts.

= 0.2.50 =
* Keep the previous Run now, review-score, homepage pin, and social outcomes on the decoupled pipeline: writer quality and the 85 review scale stay the same, scheduled ticks still do one stage, and auto-publish still pins and syndicates after a verified publish.

= 0.2.49 =
* Split the editorial queue into committed stages (pending, generated, reviewed, quarantined, published), send failed reviews to a quarantine table, and syndicate socially only after a verified draft-to-publish transition.

= 0.2.48 =
* WordPress.org Plugin Check: Tested up to 7.1 in readme only, escape the scores chart, and prepare social/view SQL against prefixed table names.

= 0.2.47 =
* Ship readme.txt and LICENSE inside the GitHub Release ZIP so WordPress.org Plugin Check can see the GPLv2 license.

= 0.2.46 =
* Declare GPLv2 or later in readme.txt and the plugin header License URI for WordPress.org Plugin Check.

= 0.2.45 =
* Open-source operator docs. Site name drives bylines and social posters. Document OpenAI vs X credits vs Instagram quota.

== Upgrade Notice ==

= 0.2.52 =
Social posts now pass a growth-desk and safety check. Violating image briefs are dropped at prompt level; unsafe captions stay in the social queue for review.

= 0.2.51 =
Choose which news graphic formats to rotate, and upload your own idea. Example posts you have seen elsewhere are not the selectable options.

= 0.2.50 =
Same publish, homepage, and social results as before, with committed pipeline stages so an API or adapter failure cannot roll back a finished step.

= 0.2.49 =
Decouples ingestion, generation, independent review, publishing, and social distribution so an API or adapter failure cannot roll back a finished stage.

= 0.2.48 =
Required for wordpress.org upload: current Tested up to, readme-only header, and Plugin Check SQL/escaping fixes.

= 0.2.47 =
Use this GitHub Release ZIP for wordpress.org upload. Older zips omitted readme.txt, which Plugin Check reports as no license.
