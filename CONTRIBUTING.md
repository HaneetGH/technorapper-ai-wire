# Contributing

AI Wire is an open-source WordPress plugin. Anyone can install it, fork it, and send changes. It is not limited to one newsroom or brand.

## Who it is for

Any WordPress site that wants to:

1. Turn configured RSS/Atom feeds into attributed, AI-reviewed articles
2. Optionally post a branded graphic to **your** X and Instagram accounts, a spoken Reel, and a YouTube Short

Site name, desks, and voice come from **your WordPress site**. Bring your own feeds, OpenAI key, X developer app, Instagram Professional account, and Google Cloud OAuth client for YouTube.

## What not to send us

Do not open issues, pull requests, screenshots, or logs that contain:

- OpenAI API keys
- X API Key, API Key Secret, Access Token, or Access Token Secret
- Instagram / Meta access tokens or account IDs
- Google OAuth client secrets, YouTube refresh tokens, or YouTube API keys
- `wp-config.php` secrets
- Production database dumps

If a secret was pasted by mistake, rotate it in the provider console and do not commit a “fix” that still contains the value.

## How to work on the code

1. Fork the repository or branch from `main`.
2. Keep credentials in your own WordPress install, never in the repo.
3. Run PHP lint and the WordPress integration tests described in the README.
4. Open a pull request against `main`.

Useful docs:

- [README](README.md) — install and configure
- [What it costs to run](docs/PLATFORM-COSTS.md) — OpenAI vs X credits vs Instagram quota vs YouTube quota vs Fal
- [Instagram setup](docs/INSTAGRAM-CONTENT-PUBLISHING-SETUP.md) — your Meta app, your account
- [Social architecture](docs/SOCIAL-DISTRIBUTION-ARCHITECTURE.md) — X, Instagram, Reels, YouTube Shorts as shipped in 0.2.54
- [Social implementation notes](docs/SOCIAL-DISTRIBUTION-HANDOFF.md) — queue, mix, Google sign-in, staging canary

## License

Contributions are accepted under **GPLv2 or later**, the same license as the plugin.
