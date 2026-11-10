=== TechnoRapper AI Wire ===
Contributors: haneetgh
Tags: rss, editorial, openai, social, instagram, youtube
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 0.2.54
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns your RSS/Atom feeds into AI-reviewed WordPress posts, with optional X and Instagram graphics, spoken Reels, and YouTube Shorts.

== Description ==

AI Wire is an open-source WordPress plugin any publisher can run. It is licensed under **GPLv2 or later**.

It takes RSS or Atom feeds you configure, writes an attributed draft, runs a separate AI review, and can publish to WordPress. After an article is live, it can optionally create one branded graphic and post it to **your** X and/or Instagram accounts. Instagram can instead publish a spoken 9:16 Reel for that one article — not a slideshow of posters. The same spoken file can upload to **your** YouTube channel as a Short, with title, description, and tags set through the YouTube Data API. Site name, desks, and the poster brand mark come from this WordPress site.

Social distribution stays off until you turn each network on. This plugin does not ship API keys. You provide:

* An OpenAI API key
* Optional X developer Keys and tokens (API Key, API Key Secret, Access Token, Access Token Secret)
* Optional Instagram Professional account via your own Meta app
* Optional Google Cloud OAuth client for YouTube Data API v3 uploads

**Third-party services.** Article text may be sent to OpenAI for writing, review, and social images. If you enable a network, media and captions are sent to the X API, Instagram Graph API, and/or YouTube Data API. Those services have their own terms and billing. Automated X posts spend X developer credits. Instagram Graph publishing has no Meta per-post fee; Meta enforces a rolling quota. YouTube uploads have no Google per-video fee; Google enforces a daily API quota.

== Installation ==

1. Upload the plugin ZIP in Plugins → Add New → Upload Plugin, or copy the `technorapper-ai-wire` folder to `wp-content/plugins/`.
2. Activate the plugin.
3. Open **AI Wire**. Save your OpenAI API key, add feeds, and set daily/monthly limits.
4. Optional: open **Social settings** and connect your X, Instagram, and/or YouTube accounts. Leave posting off until you are ready.

== Frequently Asked Questions ==

= What license is this plugin? =

GPLv2 or later. See `LICENSE` in the plugin directory and https://www.gnu.org/licenses/gpl-2.0.html

= Does automated X posting need paid credits? =

Yes. The plugin posts through the X API, which spends X developer credits. A Social queue error of `credits depleted` is an X console balance/spending-limit problem, not the OpenAI image budget.

= Is Instagram API posting billed per post? =

No. Instagram Graph publishing has no Meta per-post fee. Meta enforces a rolling 24-hour publishing quota. When the quota is full, the plugin leaves the item queued and tries later.

= Is YouTube API posting billed per upload? =

No. YouTube Data API v3 has no Google per-video fee. Title, description, tags, category, privacy, and made-for-kids are set in the upload call. Google meters quota (default 100 uploads/day). When quota is full, the item stays queued.

= Are my API keys in this repository? =

No. Store keys on your WordPress host. Never commit tokens to git or paste them into public issues.

== Changelog ==

= 0.2.54 =
* Optional YouTube Shorts: the same spoken Reel MP4 uploads through YouTube Data API v3 with title, description, tags, category, privacy, and made-for-kids set in the API. Still images are not posted to YouTube. Off until you connect a channel and turn posting on.

= 0.2.53 =
* Optional spoken Instagram Reels, off until you set Reels per day above 0. Writer GPT writes the 15–18s script; Fal motion (suggested Veo 3.1 Lite) animates the photo; Mini TTS and ffmpeg mux captions. A local profanity/slur list plus OpenAI Moderation hold unsafe copy. X still gets the still graphic.

= 0.2.52 =
* Social copy is written like a newsroom growth desk: clickbait that is true, a curiosity gap, and a photo built to stop the thumb. Operators set how many carousel, collage, and single-story posts go out each day. Carousels add a swipe cue on the cover and one curiosity slide before the last story. Image prompts refuse nudity, sexualized bodies, children, gore, and other policy violations before the image API is called. Unsafe captions are held for review.

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

= 0.2.54 =
Adds optional YouTube Shorts. The spoken Reel file is uploaded with title, description, and tags set through the YouTube API. Still posters stay on X and Instagram.

= 0.2.53 =
Adds optional spoken Instagram Reels you can turn on or off. Writer GPT writes the script; Fal motion and ffmpeg mux the rest. Policy holds block unsafe copy.

= 0.2.52 =
Social posts now use scroll-stopping copy and photos. Set how many carousel, collage, and single-story posts go out each day. Violating image briefs are dropped at prompt level; unsafe captions stay in the social queue for review.

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
