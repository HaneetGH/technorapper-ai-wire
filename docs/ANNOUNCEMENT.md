# Public announcement copy

Ready-to-paste posts. They describe the plugin as a generic WordPress tool. They do not include tokens, private dashboards, internal queue IDs, or one newsroom’s playbook.

Repository: https://github.com/HaneetGH/technorapper-ai-wire  
License: GPLv2 or later

## LinkedIn

We are open-sourcing AI Wire, a WordPress plugin any publisher can run.

It takes RSS or Atom feeds you configure, writes an attributed draft, runs a separate AI review, and can publish to WordPress. After an article is live, it can optionally create one branded graphic and post it to X and/or Instagram, publish a spoken 9:16 Reel of that one article, and upload the same file to YouTube as a public Short — using **your** site name, **your** feeds, and **your** social accounts.

This is not a closed pipeline for one brand. Install it on any WordPress 6.2+ site. Point it at your OpenAI key. Connect your X developer app, your Instagram Professional account, and/or Sign in with Google for YouTube. Social distribution stays off until you turn each network on.

A few cost facts we want in the open, because they get mixed up:

- OpenAI bills writer, reviewer, social images, and Mini TTS. You set monthly budgets in the plugin. Fal bills spoken-Reel motion on a separate video budget.
- Automated X posts go through the X API. That spends **X developer credits**. If the queue says “credits depleted”, top up in the X Developer Console. That is not the image budget, and it is not Instagram or YouTube.
- Instagram Graph publishing has **no Meta per-post fee**. Meta enforces a rolling 24-hour quota. When the quota is full, the plugin waits and retries.
- YouTube Data API uploads have **no Google per-video fee**. Google meters quota. A YouTube API key cannot upload; Sign in with Google is required. Title, description, and tags go out in the upload call. The Short is public immediately, not a draft.

Credentials never belong in the git repo. Each operator stores their own keys on their own WordPress host.

Code, docs, and installable releases: https://github.com/HaneetGH/technorapper-ai-wire

If you run a newsroom, a niche blog, or a product site on WordPress and you want a reviewed RSS-to-article pipeline with optional social posting, you can use this.

## Shorter GitHub / X variant

Open-sourcing AI Wire: a GPLv2 WordPress plugin that turns your RSS feeds into reviewed articles, then can post a branded graphic to X and Instagram, a spoken Reel, and a public YouTube Short.

Bring your own OpenAI key and social apps. Automated X posts need X API credits. Instagram and YouTube API posting have no per-post fee (quota only). YouTube needs Google sign-in, not an API key.

https://github.com/HaneetGH/technorapper-ai-wire
