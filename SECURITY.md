# Security

## Secrets stay on your WordPress host

This repository must never contain live credentials. Each operator stores their own:

- OpenAI API key (AI Wire dashboard, or `OPENAI_API_KEY` / `TR_AI_WIRE_OPENAI_API_KEY` in `wp-config.php`)
- X Keys and tokens (API Key, API Key Secret, Access Token, Access Token Secret)
- Instagram Page token and Professional account ID

The plugin saves social tokens in a non-autoloaded WordPress option and does not redisplay them in the form.

## Reporting a vulnerability

Open a private report to the repository maintainers. Do not file a public issue that includes tokens, private URLs with query secrets, or production data.

If you accidentally committed a secret, rotate it at the provider first, then tell the maintainers so the git history can be checked.
