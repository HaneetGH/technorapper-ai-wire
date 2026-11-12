# Instagram Content Publishing Setup

This guide is intentionally **generic for an open-source project**. Any WordPress operator can use it.

Each operator must connect the plugin to **their own Instagram Professional account**, using **their own Meta app, account ID, and access token**.

Instagram Graph publishing has **no Meta per-post fee**. The limit is a rolling 24-hour quota. That is not the same as **X API credits**. See [What it costs to run](PLATFORM-COSTS.md).

> **Security rule:** This repository must never contain, document, or reuse credentials belonging to any developer or organization.

## 1. What you need

| Item | What you provide |
|---|---|
| Meta Developer App | Create your own app |
| Instagram account | Your own Instagram Professional account |
| Instagram Professional Account ID | Your own account ID |
| Access token | Your own valid Meta token |
| Permission | `instagram_content_publish` |
| API host | `graph.facebook.com` for the tested flow |

Example:

```env
INSTAGRAM_ACCOUNT_ID=YOUR_INSTAGRAM_PROFESSIONAL_ACCOUNT_ID
INSTAGRAM_ACCESS_TOKEN=YOUR_ACCESS_TOKEN
```

Never commit these values to Git.

## 2. Create your own Meta app

Open:

https://developers.facebook.com/apps/

Create or select **your own Meta app** and configure the Instagram API/content-publishing access required by your app.

For this project, the important permission is:

- `instagram_content_publish`

You may also need:

- `instagram_basic`
- `instagram_manage_insights` (required for the last-7-day Social queue review; without it the plugin shows “views not available” and will not invent numbers)

depending on the authentication/configuration used by your app.

Meta's UI and authentication flows can change, so follow the current setup shown for your own app.

## 3. Connect your own Instagram Professional account

The Instagram account you want to publish to must be an eligible Instagram Professional account and must be connected/authorized according to your Meta app configuration.

If Meta requires tester access for your development configuration:

1. Add your Instagram account as a tester/test account.
2. Accept the invitation from the Instagram account.
3. Complete the authorization flow.
4. Confirm the account is available to your app.

You are configuring **your account with your app**.

## 4. Generate your own access token

Generate a token using the authentication method configured for **your Meta app**.

The token must have the publishing permission required by your setup, including:

```
instagram_content_publish
```

Never copy a token from another installation, developer, screenshot, issue, PR, or documentation.

Validate your own token with Meta's Access Token Debugger:

https://developers.facebook.com/tools/debug/accesstoken/

Never paste the actual token into GitHub, logs, documentation, or screenshots.

## 5. Find your Instagram Professional Account ID

You need the numeric Instagram Professional Account ID belonging to **your account**.

Do not confuse it with your username or a Facebook Page ID.

For the tested API flow:

```bash
curl -G "https://graph.facebook.com/v27.0/$INSTAGRAM_ACCOUNT_ID" \
  --data-urlencode "fields=id,username" \
  --data-urlencode "access_token=$INSTAGRAM_ACCESS_TOKEN"
```

Expected response shape:

```json
{
  "id": "YOUR_INSTAGRAM_PROFESSIONAL_ACCOUNT_ID",
  "username": "your_username"
}
```

The returned ID should be the account you intend to publish to.

## 6. Publish an image

Instagram image publishing uses a two-step process:

1. Create an Instagram media container.
2. Publish the media container.

The image must be available through a publicly reachable HTTPS URL.

### Step 1: Create the media container

```bash
curl -X POST "https://graph.facebook.com/v27.0/$INSTAGRAM_ACCOUNT_ID/media" \
  -d "image_url=https://YOUR_PUBLIC_IMAGE_URL/image.jpg" \
  -d "caption=Hello from my application" \
  -d "access_token=$INSTAGRAM_ACCESS_TOKEN"
```

Expected response:

```json
{
  "id": "CREATION_ID"
}
```

### Step 2: Publish the media container

```bash
curl -X POST "https://graph.facebook.com/v27.0/$INSTAGRAM_ACCOUNT_ID/media_publish" \
  -d "creation_id=CREATION_ID" \
  -d "access_token=$INSTAGRAM_ACCESS_TOKEN"
```

Expected response:

```json
{
  "id": "PUBLISHED_MEDIA_ID"
}
```

The post is then published to the Instagram account associated with the credentials.

Spoken Reels use the same Graph publishing flow with `media_type=REELS` and a publicly reachable HTTPS **video_url** (the spoken MP4 in WordPress Media). Meta fetches that file the same way it fetches a JPEG. Missing `ffmpeg`, a Fal.ai key, or video budget holds the Reel in the Social queue; the plugin does not post a still as fake video. YouTube Shorts reuse that MP4 through Google sign-in, not this Meta flow. See [Social Distribution Architecture](SOCIAL-DISTRIBUTION-ARCHITECTURE.md).

## 7. End-to-end flow

```text
Developer's Instagram Professional account
                 |
                 v
        Developer's Meta app
                 |
                 v
       Developer's access token
                 |
                 v
       Instagram account ID
                 |
                 v
        Open-source project
                 |
                 | POST /{account-id}/media
                 v
           creation_id
                 |
                 | POST /{account-id}/media_publish
                 v
          Instagram post
```

The project must never assume a specific Instagram account. All account-specific values come from configuration.

## 8. Environment configuration

Recommended:

```env
INSTAGRAM_ACCOUNT_ID=...
INSTAGRAM_ACCESS_TOKEN=...
```

For local development:

- Keep credentials in a local `.env`.
- Add `.env` to `.gitignore`.
- Never commit it.
- Never print the token in logs.

For production, use encrypted environment variables/secrets.

## 9. Important: API host and token type

A common source of confusion is using the wrong Meta API host for a token/authentication flow.

During development of this project, the working content-publishing token was successfully used with:

```
https://graph.facebook.com
```

and the Instagram Professional Account ID.

Do not blindly replace this with:

```
https://graph.instagram.com
```

The correct host depends on the authentication/API flow used by your Meta app.

## 10. Common mistakes

### Using someone else's account ID

Wrong:

```env
INSTAGRAM_ACCOUNT_ID=someone_elses_account
```

Correct:

```env
INSTAGRAM_ACCOUNT_ID=YOUR_OWN_ACCOUNT_ID
```

### Using someone else's token

Wrong:

```env
INSTAGRAM_ACCESS_TOKEN=someone_elses_token
```

Correct:

```env
INSTAGRAM_ACCESS_TOKEN=YOUR_OWN_TOKEN
```

### Using a Facebook Page ID

The publishing endpoint expects the Instagram Professional Account ID used by your configured Instagram API flow. Do not substitute an unrelated Facebook Page ID.

### Committing credentials

Never commit:

```
.env
access tokens
client secrets
private credentials
debug logs containing tokens
screenshots containing tokens
```

## 11. Troubleshooting

### Invalid OAuth access token

Check:

1. The token belongs to your Meta app.
2. The token is still valid.
3. `instagram_content_publish` is available. `instagram_manage_insights` is required if you want last-7-day views on the Social queue.
4. Your Instagram account is correctly authorized.
5. You are using the API host appropriate for your authentication flow.
6. The account ID belongs to the same Instagram account you authorized.

### Account lookup fails

Run the account lookup again:

```bash
curl -G "https://graph.facebook.com/v27.0/$INSTAGRAM_ACCOUNT_ID" \
  --data-urlencode "fields=id,username" \
  --data-urlencode "access_token=$INSTAGRAM_ACCESS_TOKEN"
```

Do not debug by sharing the token.

### Media creation succeeds but publishing fails

Check:

- The `creation_id` belongs to the same account.
- The image URL is publicly reachable over HTTPS.
- The image meets Instagram's current publishing requirements.
- The access token is still valid.
- The account has the required publishing permission.

## 12. Security requirements for an open-source project

The repository should contain:

- API integration code
- configuration examples
- setup instructions
- placeholder values
- troubleshooting guidance

The repository must **not** contain:

- real access tokens
- client secrets
- private credentials
- unnecessary production account IDs
- screenshots exposing credentials
- logs containing credentials

Use placeholders such as:

```text
YOUR_INSTAGRAM_ACCOUNT_ID
YOUR_INSTAGRAM_ACCESS_TOKEN
YOUR_PUBLIC_IMAGE_URL
```

This allows every developer to configure the project independently.

## 13. Optional: OAuth for multiple users

If this project becomes a hosted application where many users connect their own Instagram accounts, use an OAuth-based connection flow rather than asking users to paste permanent tokens into the application.

Typical flow:

1. User chooses **Connect Instagram**.
2. User authorizes the application's Meta app.
3. Meta redirects to the application's configured callback URL.
4. The application securely handles the authorization result.
5. The application stores the user's credentials securely.
6. The credentials are associated with that user's Instagram account.
7. Publishing uses that user's account ID and credentials.

The callback URL belongs to **the application operator's own Meta app**.

## 14. Quick start for a new developer

```text
1. Clone the open-source project.
2. Create your own Meta Developer app.
3. Configure Instagram content publishing.
4. Connect/authorize your own Instagram Professional account.
5. Obtain your own access token.
6. Find your own Instagram Professional Account ID.
7. Configure:

   INSTAGRAM_ACCOUNT_ID=YOUR_ACCOUNT_ID
   INSTAGRAM_ACCESS_TOKEN=YOUR_ACCESS_TOKEN

8. Keep the credentials out of Git.
9. Verify the account:

   GET https://graph.facebook.com/v27.0/{YOUR_ACCOUNT_ID}
       ?fields=id,username
       &access_token=YOUR_TOKEN

10. Create media:

   POST https://graph.facebook.com/v27.0/{YOUR_ACCOUNT_ID}/media

11. Take the returned creation_id.

12. Publish:

   POST https://graph.facebook.com/v27.0/{YOUR_ACCOUNT_ID}/media_publish

13. Verify the post on your own Instagram account.
```

## 15. Related Meta documentation

Instagram Content Publishing:

https://developers.facebook.com/docs/instagram-platform/content-publishing/

Instagram API with Instagram Login:

https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login

Meta Developers:

https://developers.facebook.com/apps/

Access Token Debugger:

https://developers.facebook.com/tools/debug/accesstoken/

---

## Important

This documentation is intentionally **account-agnostic**.

Never copy another installation's Meta app ID, Instagram account ID, access token, client secret, or other credentials into your own setup.

The project should work by replacing the documented placeholders with values belonging to the developer's own Meta/Instagram configuration.
