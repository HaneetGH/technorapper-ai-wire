=== TechnoRapper AI Wire ===
Contributors: haneetgh
Tags: rss, editorial, openai, social, instagram, youtube
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 0.3.12
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

= 0.3.12 =
* Comics desk mix no longer parks normal drafts in waiting_budget when the Comics desk daily limit is 0.

= 0.3.11 =
* When Fal blocks ffmpeg-api, Social queue packs a laptop kit and shows Download laptop kit (.zip) / Prepare laptop kit so Reels can finish on a computer without a VPS.
* Fal ffmpeg-api "Access denied" is called out as an account entitlement block (a new API key will not fix it) with a local-ffmpeg workaround.
* Each Reel build and background job keeps a detail debug log (Fal endpoint, request id, HTTP/errors). Download as .txt from Social queue or Now in queue.

= 0.3.10 =
* Desk mix: Comics desk posts per day sits inside Maximum posts per day (example: 5 max + 1 Comics → about 4 tech + 1 Comics). Feed tenants can be marked Tech or Comics. Unused Comics slots soft-fill with tech. Separate from Comic voice.

= 0.3.9 =
* Website posts still go live when the reviewer marks a story as top pop-culture news, or as something people are searching for on the internet, even if the review score or the SEO score is under the minimum. Internet search means Google and other search engines, not search on this website. A searched topic is posted here, including a list or a thin item. Reject, policy, and the social score floor stay in place.

= 0.3.8 =
* Stopped Reels on Social queue (needs_review) get a Delete from queue button. The WordPress article stays; that Reel attempt is removed.

= 0.3.7 =
* Fal assemble no longer treats a failed COMPLETED job or a 403/404 result as an endless hiccup. The dead request is cleared so Resume only recharges the last step.
* Held Reel rows stop auto-requeuing after 45 minutes without a finished MP4. Stale finished background-job cards age out of Now in queue.

= 0.3.6 =
* Stop and delete from queue removes only the Reel currently processing, not every Reel waiting in the social queue.

= 0.3.5 =
* Stop job removes the stuck Reel/social rows from the queue (WordPress posts stay). A new Now in queue page shows the live background job plus unfinished website and social rows.

= 0.3.4 =
* Stuck Reel / background jobs can be stopped from the progress bar or Social queue, then resumed or regenerated. A job that keeps updating while waiting on Fal no longer blocks Stop and Rerun for half an hour.

= 0.3.3 =
* Website posts publish again when the reviewer marks a soft HOLD without a hard blocker. Fetch now also re-checks feeds, validates longer than ~6 stories per tick, and keeps filling Maximum posts per day.

= 0.3.2 =
* Website queue runs skip held stories and keep going until Maximum posts per day is filled. Failed rows no longer use up Maximum items per run or stop the Fetch now job.

= 0.3.1 =
* Queue rows that were held for score, story-value, or a pre-publish HOLD get a Force publish button. Retry still re-runs the reviewer; Force publish goes live on that one story. Profanity and OpenAI Moderation still apply.

= 0.3.0 =
* Best roundup Reels print the spoken voiceover slice as burned-in subtitles on each illustrated scene (Instagram-safe zone). Update, then Start over — old cards without subtitles are not reused.

= 0.2.99 =
* Best Reels no longer require a person or mascot in every scene — no-person story art is fine. Fal stills that come back longer than the voice (63.9s vs 55.8s) drop trailing cards on the first miss so leftover freeze is not posted.

= 0.2.98 =
* Website posts keep filling Maximum posts per day after editorial holds. Failed/quarantined RSS items do not use a daily slot, and pending/generated rows are processed before those holds so a run does not stop on red errors.

= 0.2.97 =
* Fal compose ignores a shorter duration on a long held-scenes clip, so 0.2.96 still failed at 64.8s vs 57s. Best Reels now rebuild the picture track at a scaled frame count (and fewer cards if needed) until it matches the voiceover.

= 0.2.96 =
* Best roundup Reels match the voiceover length. Fal hold frames are trimmed to the spoken duration, compose asks for that length, and an overlong mux is cut instead of failing with leftover blank or frozen frames.

= 0.2.95 =
* Failed stills, Reels, and queue items print a red error that says whether the graphic was not generated or not posted, and why. Retry/Resume clears that message immediately; a second failure shows the new reason.

= 0.2.94 =
* Best roundup Reels hold each illustrated scene for seconds, not one frame. Fal stitches the unique cards with images-to-video (frames/fps), then muxes the voice. A leftover 5s B-roll clip is never placed in that cut, so you do not get a short video, then millisecond slides, then a blank screen.

= 0.2.93 =
* Best roundup Reels now draw a unique 2D newsroom illustration for every story beat (Fal FLUX schnell, ~$0.003 each), then overlay the matching caption. The pictures match the packed desk stories. The look is a locked viral 2D newsroom, not a rotating stick-figure pose.

= 0.2.92 =
* Best roundup Reels leave no blank leftover. Unique stick-figure cards are timed to cover 100% of the voiceover; Fal will not pad the rest with a black screen. A mux shorter than the voice, or longer than the last card, is refused.

= 0.2.91 =
* Best roundup Reels never repeat a frame. The 3-second B-roll of a person watching is not looped, and Fal no longer leaves a still or blank screen after it. Every scene is a unique stick-figure card that covers the voiceover.
* Fal assembles those cards on an image track so a short video clip cannot be reused to fill the rest of the Reel.

= 0.2.90 =
* Best roundup Reels keep at least 135 spoken words. 150 is not a cap — the voiceover can run 180–240 words when 4–5 stories need the room. Scenes follow the spoken words; the brand end card is not leftover filler.
* Cached 15–18s teach scripts and old 24s roundups are rewritten before mux.

= 0.2.89 =
* Best production path is now a 60-second stick-figure newsroom roundup: 135–150 words, 3–5 desk stories, scene every 3–4 seconds, then auto-post to Instagram and YouTube.
* Budget stays the 18-second Veo Lite teach cut. Custom is unchanged.

= 0.2.88 =
* Best production path for Reels: voice first, lesson cards carry the cut, MiniMax H3 Max animates one approved still into short silent accents, then the worker auto-posts to Instagram and YouTube (at least one Reel per day).
* Budget path still uses Veo Lite and also auto-posts. Custom keeps hold-for-review when Reel posts per day is 0, plus the talking-host upsell and every Fal motion model.
* Hosts without ffmpeg stay on WordPress: Fal muxes the spoken MP4. A laptop kit is only the fallback if Fal assembly fails.

= 0.2.87 =
* Automatic processing can run at exact clock times (for example 09:00, 13:00, 18:00 in the site timezone), not only every N hours.
* Instagram stills can use the same clock slots instead of a 3-hour gap. Leave the times blank to keep the hour gap (default 3, or 0 for no wait).

= 0.2.86 =
* Instagram stills keep posting while a Reel is building or held. A waiting Reel no longer stalls the social worker.
* The 3-hour Instagram still gap ignores Reels, so a recent Reel does not delay a normal image post.
* Enable Reels / YouTube Shorts no longer forces 1 auto Reel per day. Leave Reel posts per day at 0 for stills plus Generate Reel now.

= 0.2.85 =
* Independent web search no longer sends OpenAI JSON mode (API 400: Web Search cannot be used with JSON mode). Search replies are still parsed as JSON.

= 0.2.84 =
* Local ffmpeg helper no longer treats a successful encode as failure when PHP already reaped the process (fixes a PHP 8.2 CI flake that surfaced as an ffmpeg version-banner error).

= 0.2.83 =
* Every Reel (no-face, B-roll recipe, talking host, local ffmpeg, Fal, laptop kit) now uses one cards-first timeline. Lesson cards carry the voice; the B-roll clip appears at most twice for up to 2.5s each and is never looped or stretched.
* A guard checks the timeline before any mux and stops the build with a clear error if cards are missing, motion would run long, or the cut would not cover the voice. There is no motion-only fallback any more.
* Lesson cards are baked at their exact on-screen length and must all be ready before the final assemble. A broken card clip stops the build instead of being muxed.
* Lesson cards are readable: big centred text in the Instagram safe zone over a blurred, darkened backdrop, with step labels and progress dots.
* Finished Reels from older cuts are no longer reused, previewed, or posted. Resume deletes them and rebuilds the new cut, held for review.
* The laptop kit renders the same cut (card images, short accents, end card) and packs every file its script needs.
* Settings, progress steps, and preview copy no longer describe a looping motion cut.

= 0.2.82 =
* No-face teach cut is cards-first: lesson cards carry the Reel; B-roll motion is at most one or two short accents (no looping laptop bed).

= 0.2.81 =
* Finished-Reel duration check no longer ignores multi-hour muxes (the short-clip helper capped at 90s and could treat a 2h freeze as OK). Overlong voice MP3s are discarded instead of reused.

= 0.2.80 =
* Starting a new Reel (or Generate script again) deletes older Reel media for that article so a bad MP4 cannot linger.
* Social queue: Generate script again rewrites the teach voiceover and rebuilds video from a fresh script.

= 0.2.79 =
* No-face teach Reels: research pass writes a learnable script, Veo motion cuts with animated lesson cards (JPEG stills are converted to short MP4s before Fal mux).
* Fixes multi-hour frozen Reels caused by putting still images on Fal's video track. Overlong mux output is rejected.
* Optional Independent web search also feeds the Reel research/teach pass.

= 0.2.78 =
* Default Reel is a no-face hook story: pattern-interrupt on-screen hook, captioned news-detail beats and B-roll, then sting. Talking host stays an optional paid upsell.
* Dev cheap test forces no face + Veo Lite so tests do not burn Avatar credits. Hard caps stop multi-minute freezes when a 4s motion clip was stretched past its length.
* Spoken scripts are trimmed to the target length before TTS so Fal assemble cannot run out to ~2 minutes on a long voice file.

= 0.2.77 =
* Dev cheap test mode: one button/checkbox forces Avatar Standard + Veo Lite 4s + short voice while your production model picks stay saved. Turn it off to spend the configured Pro/Fast recipe again.

= 0.2.76 =
* Reels stop sounding like a robot readout: punchier spoken hooks, creator-style TTS delivery, and a cold-open story cut (news video/photos first, short host hook, then detail beats). Partial previews are labeled so the full talking-host take is not mistaken for the finished Reel.

= 0.2.75 =
* Story edit is movie-style storytelling on hosts without ffmpeg: the presenter speaks the opening, then the cut moves to news detail B-roll and captioned photos while the voice continues, then the brand sting.
* Talking host keeps the same face across Reels, but can change outfit, place, and backdrop per video (Social setting: New outfit / place per Reel).

= 0.2.74 =
* Reel builds no longer get stuck on "processing". Every Fal job is saved by request id, so Resume never pays twice. Each step (voiceover, talking host, B-roll, assembly) is checked in short rounds and the row shows live progress with Check now and Resume building this Reel.
* A worker that dies mid-build is detected after four minutes and resumed automatically. Flaky Fal status checks are retried and only reported after repeated failures.
* The voiceover is recorded once per script and reused. Durations are read from the MP3/MP4 files, so this works without ffprobe.
* Without ffmpeg on the host, the presenter plays unbroken and B-roll cutaways are skipped to keep lips in sync. If Fal refuses the end card, the Reel is rebuilt without it.
* Download video and Post only appear for a real finished Reel (never a silent clip). Saved pieces stay downloadable, and you can still upload a finished MP4.

= 0.2.73 =
* Fal already billed Kling Avatar, then the queue stuck on “still generating” because status was polled on the full model path (HTTP 405). Poll now uses fal-ai/kling-video/requests/{id}. Retry building this Reel downloads that completed talking-host clip.

= 0.2.72 =
* Download video and Post to Instagram were missing when the host had no ffmpeg, because the job stopped before storing an MP4. Retry building this Reel now generates the video (Fal mux, or the motion clip) without a checkbox. Those buttons appear on the row when the file is ready.

= 0.2.71 =
* Social queue has a Download video button on the Reel row. It saves the MP4 to your computer.
* After you review a Reel, Post to Instagram (or YouTube) sits next to that download. Tick "I reviewed this video", then click Post.

= 0.2.70 =
* Hosts without ffmpeg can still make Reels: Fal assembles the spoken MP4 when possible, otherwise Social queue offers Download motion/voice/kit so you can run ffmpeg on a laptop, upload reel.mp4, and Publish to Instagram.

= 0.2.69 =
* Spoken Reels are configurable from Social settings: recipe (B-roll vs story edit), spoken length, male/female/neutral Mini TTS voice, weekly cap, cameras, and sting. Story edit is a talking host plus moving B-roll cutaways with captions — no still news cards. Social queue has Generate Reel now: it builds one video, holds it on the dashboard for review, and only posts when you click Publish. Auto Reels still go out when Reel posts per day is above 0.
* Optional avatar-face upsell: upload a host portrait, generate a fictional presenter, enhance the face with CodeFormer, and lip-sync with Kling Avatar Standard or Pro. That one saved face is reused on every Reel. Defaults stay B-roll, Reels off, $10 video budget.
* Social settings, Social queue, and Price estimate use grouped cards, recipe choice tiles, an avatar-face panel, mix meters, and a current-recipe cost summary.

= 0.2.68 =
* A queued carousel or collage no longer sits on “Graphic not generated yet” for the 3-hour gap between single Instagram posts. Opening the Social queue starts the worker, which builds the graphics and posts that carousel or collage. Single stills still wait 3 hours.

= 0.2.67 =
* Social queue has Generate carousel now and Generate collage now. Each builds one post from at least two articles that have not already gone out, without waiting for the daily mix or for 4–6 stories to pile up. A one-article pack stays queued until a second article is available.

= 0.2.66 =
* Settings only show detail fields for features that are on. Reels off hides daily video count, budget, model, clip length, and Fal key. YouTube off hides category and subscriber notify. Comic and A/B experiment IDs stay hidden until those features are enabled.
* Carousel (and collage) daily counts actually run. The mix no longer throws those quotas away when the legacy post-format value is still "single", which is why only single stills were going out. A one-article leftover stays held instead of posting as a fake single.
* Social posts per day is the total. Reels, carousels, collages, and singles must add up to that number (5 can be 2 carousels + 1 collage + 2 singles). Extra type counts are trimmed on save.

= 0.2.65 =
* Hosted-image backfill no longer runs inside a page request (wp-admin or frontend WP-Cron). That timeout was causing WordPress’s “There has been a critical error on this website” screen. Repair homepage still does the work in the background.

= 0.2.64 =
* Auto-publish runs a pre-publish SEO and editorial gate (clickbait, placeholders, near-duplicates, brand identity). Existing score, similarity, and SEO settings still decide the rest. The plugin no longer prints a second NewsArticle JSON-LD graph.

= 0.2.63 =
* Queue and settings include Migrate older images, which copies hotlinked featured photos and in-article images from other servers into this site’s media library.

= 0.2.62 =
* Published articles keep the original feed URL only in the Inspired by credit at the end. Body links and images that pointed at the source site or its CDN are removed.

= 0.2.61 =
* RSS featured images are downloaded into the WordPress media library on publish, so article, schema, and social-preview URLs stay on this site instead of hotlinking the feed CDN.

= 0.2.60 =
* Comic posts per day (default 0) assigns a restrained dry wink to the first N articles each UTC day so you can A/B comic vs straight on the experiments page and in GA4 `voice`. The rest stay straight. Missing jokes do not block a 90 social score.

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
* Optional spoken Instagram Reels, off until you set Reels per day above 0. Writer GPT writes the 15–18s script; Fal motion (suggested Veo 3.1 Lite) animates the photo; Mini TTS speaks it. Local ffmpeg muxes captions when present; otherwise Fal assembles the MP4. A local profanity/slur list plus OpenAI Moderation hold unsafe copy. X still gets the still graphic.

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

= 0.3.12 =
Release tag for the Comics desk create_draft gate fix after 0.3.11.

= 0.3.11 =
Stuck Reels get a clear Download laptop kit (.zip) button when Fal ffmpeg-api is blocked. Reel and background jobs also keep a downloadable detail log.

= 0.3.10 =
Set Comics desk posts per day inside your daily max (for example 1 Comics out of 5). Mark Marvel/DC feed tenants as the Comics desk lane.

= 0.3.9 =
Top pop-culture stories publish on the website under the review-score and SEO-score minimums. A story people are searching for on the internet is posted on this website. Social stills keep their score floor.

= 0.3.8 =
Stopped Reels on Social queue can be deleted from the queue without touching the WordPress article.

= 0.3.7 =
Fal result 403/404 and long stuck Reel builds now stop cleanly so Resume can finish the last step without grinding for hours.

= 0.3.6 =
Stop and delete from queue only drops the stuck processing Reel; other queued Reels stay.

= 0.3.5 =
Stop job now deletes the stuck queue rows. Open Now in queue to see what is waiting right now.

= 0.3.4 =
Use Stop job / Stop and rerun Reel on Social queue when a Reel build hangs.

= 0.3.3 =
Soft reviewer HOLDs no longer block website publish, and Fetch now keeps validating past the first handful of stories.

= 0.3.2 =
Fetch now keeps trying the next website story after holds, instead of stopping the job when the first few fail.

= 0.3.1 =
Held queue rows now have Force publish when you still want that story live. Retry still asks the reviewer again.

= 0.3.0 =
Best Reels print spoken subtitles on each newsroom scene. Update, then Start over — do not Resume an old MP4 that has no subtitles.

= 0.2.99 =
Best Reels illustrate the story without a person in every scene, and drop extra cards when Fal returns a clip longer than the voiceover. Update, then Start over — do not Resume the leftover file.

= 0.2.98 =
Website queue runs keep going after red editorial holds until today's post cap is filled. Failed items do not count as published.

= 0.2.97 =
Best Reels rebuild a too-long Fal picture track to the spoken length. Update, then Start over — do not Resume the 64s leftover file.

= 0.2.96 =
Best Reels that come back longer than the voiceover are trimmed to the spoken length instead of failing with leftover blank frames.

= 0.2.95 =
Failed stills and Reels show a red error that says not generated vs not posted. Retry clears it; a second failure prints the new reason.

= 0.2.94 =
Best Reels hold each illustrated scene for the time it is spoken. The leftover 5s B-roll clip is not muxed in, so cards do not flash in milliseconds.

= 0.2.93 =
Best Reels now illustrate each desk story as a unique 2D newsroom scene. The pictures match the voiceover stories.

= 0.2.92 =
Best Reels hold unique stick-figure cards through the whole voiceover. Fal will not leave a blank screen after the last scene.

= 0.2.91 =
Best Reels no longer loop a 3-second B-roll clip. Each stick-figure scene is unique and covers the spoken voiceover.

= 0.2.90 =
Best Reels keep a full roundup on screen. The script can go past 150 words when the stories need it. The TechnoRapper end card is no longer half the video.

= 0.2.89 =
Best production path is a 60-second stick-figure news roundup (3–5 stories) that auto-posts. Budget is still the short Veo Lite teach cut.

= 0.2.88 =
On Social settings, pick Best production path. Saving it auto-posts at least one Instagram Reel per day (H3 Max, Fal mux if the host has no ffmpeg). Custom with Reel posts per day at 0 still holds for review.

= 0.2.87 =
Optional: set exact run times on Feed tenants and Social settings. Interval schedules (every 3 hours, and so on) stay the default.

= 0.2.86 =
Update if Instagram image posts stalled after Reel work. Set Reel posts per day to 0 unless you want auto Reels; stills then post independently.

= 0.2.85 =
Update if Independent web search is on — review/Reel research was hitting OpenAI 400 with JSON mode.

= 0.2.84 =
Same as 0.2.83 for operators — this patch only hardens local ffmpeg status handling.

= 0.2.83 =
Update, keep Dev cheap on, then click Resume (or Start over) on any Reel row. Old looping Reels are deleted automatically and rebuilt cards-first, held for review.

= 0.2.82 =
Update, keep Dev cheap on, then Start over or Generate script again so the new cards-first cut replaces the looping B-roll Reel.

= 0.2.81 =
Update, then Start over or Generate script again on any old multi-hour Reel. Resume alone keeps the broken file.

= 0.2.80 =
On Social queue use Generate script again or Start over — both delete older Reel files for that row before rebuilding.

= 0.2.79 =
Update, then Start over on any Reel that shows a multi-hour still+voice file. Resume alone keeps the broken MP4. Enable Independent web search if you want the teach script researched.

= 0.2.78 =
Turn off Talking host for the cheap no-face hook story. Use Dev cheap test, then Start over on an old Reel so assemble uses the new cut (Resume alone keeps the old MP4).

= 0.2.77 =
On Social queue use Dev — cheap test while credits are low. Switch to production config when the pipeline looks right.

= 0.2.76 =
Update, then Start over on a stuck/boring Reel so the voiceover and assemble use the new hook style. Resume alone keeps the old script.

= 0.2.75 =
Set Reel recipe to Story edit, turn on talking host and B-roll, then Resume. The finished Reel cuts from speech into news details. Same host face can appear in a new outfit and place on each video.

= 0.2.74 =
Open Social queue and click Resume building this Reel on a stuck row. Finished Fal steps are kept and not billed again. Leave the page open while it builds.

= 0.2.73 =
Retry building this Reel picks up a completed Fal Kling Avatar job that the queue could not download.

= 0.2.72 =
If Download and Post are missing on a held Reel, click Retry building this Reel. The host does not need ffmpeg.

= 0.2.71 =
Social queue Reels now have Download video and a Post button after review.

= 0.2.70 =
If this WordPress host has no ffmpeg, Generate Reel still builds motion and voice. Download the kit, mux on your laptop, upload the MP4, then Publish to Instagram.

= 0.2.69 =
Reel recipe, spoken length, voice, weekly cap, and an optional avatar-face upsell are now on Social settings, with a cleaner dashboard for the mix, queue, and costs. Generate Reel now builds one video for review, then Publish sends it live. Existing installs keep the B-roll Reel and $10 video budget until you change them.

= 0.2.68 =
Open the Social queue if a carousel is sitting there with no graphics. The worker generates them and posts the carousel. Single Instagram posts still wait 3 hours.

= 0.2.67 =
Use Generate carousel now or Generate collage now on the Social queue when those formats are not posting on their own.

= 0.2.66 =
Inactive features no longer show their extra settings. Carousel and collage daily counts now run. Social posts per day is the total; split it across Reels, carousels, collages, and singles.

= 0.2.65 =
Fixes the WordPress critical-error / dashboard hang after update. Image backfill now runs in the background.

= 0.2.64 =
New posts must pass a pre-publish SEO and editorial gate. Daily cap, review score, and similarity settings are unchanged.

= 0.2.63 =
Use Migrate older images on Queue & usage to copy already-published photos off the original CDN onto this WordPress site.

= 0.2.62 =
Article bodies no longer link out to the RSS source. The original URL stays only in the Inspired by line at the end.

= 0.2.61 =
Article featured images are copied into your media library on publish so image URLs stay on your site instead of the RSS feed CDN.

= 0.2.60 =
Set Comic posts per day to mix a dry wink with straight copy, then compare them on A/B experiments. Family-friendly news first; jokes never block a complete article from social stills.

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
Adds optional spoken Instagram Reels you can turn on or off. Writer GPT writes the script; Fal motion and Mini TTS do the rest. Local ffmpeg is optional. Policy holds block unsafe copy.

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
