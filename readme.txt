=== TechnoRapper AI Wire ===
Contributors: haneetgh
Tags: rss, editorial, openai, social, instagram, youtube
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 0.2.60
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
* Optional Google Cloud OAuth client, then Sign in with Google (or paste a refresh token) for YouTube Data API v3 uploads. A YouTube API key cannot upload.

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

No. YouTube Data API v3 has no Google per-video fee. Connect with Sign in with Google; a YouTube API key cannot upload. Title, description, tags, category, privacy, and made-for-kids are set in the upload call. The Short goes live as public immediately (not a draft). Google meters quota (default 100 uploads/day). When quota is full, the item stays queued.

= Are my API keys in this repository? =

No. Store keys on your WordPress host. Never commit tokens to git or paste them into public issues.

== Changelog ==

= 0.2.60 =
* Approved articles can POST to another CMS (or the standalone no-CMS reader) over a signed webhook, and can write static HTML/JSON for GitHub Pages. WordPress remains the plugin host. This does not add social networks.

= 0.2.59 =
* Social copy follows Instagram distribution rules: named hooks, payoff in the caption, save/send asks, and no generic bait (“wait for it”, “you won’t believe”). Poison phrases are stripped before publish. Reels send is_ai_generated. Default mix prefers carousels (3) over single stills (2).

= 0.2.58 =
* Reviewer 90 now means a complete original article, not a parked 85. The writer aims at a named-change headline; clustered 85–89 scores with no remaining defect are raised to 90; one polish pass applies reviewer fixes before social stills.

= 0.2.57 =
* Instagram stills skip stories below a review-score floor (default 90) so the feed is not a graveyard of 15-view posts. Captions no longer include URLs (link in bio instead), and stills wait at least 3 hours apart. Reels stay off.

= 0.2.56 =
* Manual fetch, queue retry, social process, social retry, and homepage repair run on a background worker. The admin page stays put and a progress bar tracks the job. Delete, clean, and stop update the page in place.
* A feed item is not published just because the rewrite succeeded. Thin blurbs, deal lists, coupons, best-of lists, and recaps stay in the queue. The reviewer must also mark the story solid enough that readers would look for it.

= 0.2.55 =
* When OpenAI flags a prompt, retry once with a shorter excerpt, then call Gemini 2.5 Flash if a Gemini API key is saved. Writer instructions no longer name disallowed categories, which was causing HTTP 400 invalid_prompt on ordinary tech copy.

= 0.2.54 =
* Optional YouTube Shorts: the same spoken Reel MP4 uploads through YouTube Data API v3 and goes live as public immediately (not a draft). Title, description, tags, category, language, license, audience, and AI-content disclosure are set in that API call. Still images are not posted to YouTube. Off until you connect a channel and turn posting on.

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

= 0.2.60 =
Optional webhook and static export after WordPress publish, for other CMS hosts and the standalone reader. Social networks are unchanged.

= 0.2.59 =
Captions now use named hooks and save/send asks, strip generic Instagram bait, and Reels disclose AI. New installs prefer 3 carousels and 2 singles.

= 0.2.58 =
Complete original articles can now reach the Instagram stills score of 90. The reviewer no longer parks them at 85, and one polish pass applies named fixes.

= 0.2.57 =
Instagram stills now skip weaker stories, drop caption URLs, and space posts so the account is not flooded.

= 0.2.56 =
Fetch, retry, and the other long admin actions keep the page open and show a progress bar while a background worker runs them.

= 0.2.55 =
If OpenAI blocks a prompt, AI Wire retries a shorter excerpt and can fall back to Gemini when you save a Gemini API key.

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
