# Instagram Content Publishing Setup

This document records the **working setup and troubleshooting path** used to enable TechnoRapper to publish content to the Instagram Professional account `@techno.rapper` via the Meta Graph API.

The goal is intentionally narrow:

> **Publish images/captions to the TechnoRapper Instagram account through an API.**

This document does not cover Facebook Page publishing, ads, comments, DMs, or other Meta integrations.

---

## 1. Current setup

| Item | Value |
|---|---|
| Meta App | `TechnoRapper_Automation` |
| Instagram account | `@techno.rapper` |
| Instagram Professional Account ID | `17841473664186186` |
| Required publish permission | `instagram_content_publish` |
| Basic account permission | `instagram_basic` |
| Working API host | `graph.facebook.com` |
| API version tested | `v27.0` |

**Never commit an access token to the repository.**

---

## 2. Meta App configuration

Open:

https://developers.facebook.com/apps/

Select:

**TechnoRapper_Automation**

The Instagram account `@techno.rapper` must be configured as an Instagram tester/test account where required, and the tester invitation must be accepted.

The working token was confirmed to have these scopes:

- `instagram_basic`
- `instagram_content_publish`
- `public_profile`

For this content-publishing use case, `instagram_content_publish` is the important permission.

---

## 3. Important: token/API host discovery

During setup we initially tried:

```text
https://graph.instagram.com/me
```

with the generated token.

That returned:

```json
{
  "error": {
    "message": "Invalid OAuth access token - Cannot parse access token",
    "code": 190
  }
}
```

This was **not** a missing-permission error.

The Meta Access Token Debugger showed:

```text
App ID: TechnoRapper_Automation
Type: System User
Valid: True
Scopes: instagram_basic, instagram_content_publish, public_profile
```

The important discovery was that this token works with:

```text
https://graph.facebook.com
```

for the Instagram Professional Account ID.

Therefore, for the current working setup, use **`graph.facebook.com`** rather than assuming the token must be sent to `graph.instagram.com`.

Do not change the API host just because another Instagram documentation example uses `graph.instagram.com`; the token type and login configuration determine the correct API flow.

---

## 4. Verify the Instagram Professional Account ID

The Instagram Professional Account ID is:

```text
17841473664186186
```

Verify it with the access token:

```bash
curl -G "https://graph.facebook.com/v27.0/17841473664186186" \
  --data-urlencode "fields=id,username" \
  --data-urlencode "access_token=YOUR_ACCESS_TOKEN"
```

Expected response:

```json
{
  "id": "17841473664186186",
  "username": "techno.rapper"
}
```

If this succeeds, the token can access the target Instagram Professional account.

---

## 5. Publish an image

Instagram image publishing uses a **two-step process**:

1. Create an Instagram media container.
2. Publish that media container.

The image must be available from a **publicly reachable HTTPS URL**.

### Step 1: Create media container

```bash
curl -X POST "https://graph.facebook.com/v27.0/17841473664186186/media" \
  -d "image_url=https://YOUR_PUBLIC_IMAGE_URL/image.jpg" \
  -d "caption=Hello from TechnoRapper 🚀" \
  -d "access_token=YOUR_ACCESS_TOKEN"
```

Expected response:

```json
{
  "id": "CREATION_ID"
}
```

Save the returned `id`.

This is the **media container / creation ID**.

---

### Step 2: Publish the media container

```bash
curl -X POST "https://graph.facebook.com/v27.0/17841473664186186/media_publish" \
  -d "creation_id=CREATION_ID" \
  -d "access_token=YOUR_ACCESS_TOKEN"
```

Expected response:

```json
{
  "id": "PUBLISHED_MEDIA_ID"
}
```

At this point the post has been published to:

```text
@techno.rapper
```

---

## 6. End-to-end API flow

The production flow is:

```text
TechnoRapper AI Wire
        |
        | Select article/content
        v
Generate social image
        |
        | Public HTTPS image URL
        v
Instagram /media
        |
        | creation_id
        v
Instagram /media_publish
        |
        v
@techno.rapper
```

For the current implementation, the critical API sequence is:

```text
POST /17841473664186186/media
              |
              v
        creation_id
              |
              v
POST /17841473664186186/media_publish
              |
              v
        Instagram post
```

---

## 7. What NOT to do

### Do not use the old Facebook Page permissions

Do not add or depend on:

- `manage_pages`
- `pages_show_list`

Those belong to older/different Facebook Page flows and are not required for this content-publishing setup.

### Do not use the Facebook Page ID as the Instagram account ID

The publishing endpoint above uses:

```text
17841473664186186
```

which is the **Instagram Professional Account ID** for `@techno.rapper`.

### Do not commit tokens

Never put an access token in:

- GitHub
- source code
- `.env` committed to Git
- documentation
- screenshots
- issues/PR comments
- logs

Use environment variables/secrets in the actual application.

Example:

```env
META_ACCESS_TOKEN=...
INSTAGRAM_ACCOUNT_ID=17841473664186186
```

---

## 8. Troubleshooting

### Error: Invalid OAuth access token / Cannot parse access token

Example:

```text
Invalid OAuth access token - Cannot parse access token
code: 190
```

First verify:

1. The token was generated for `TechnoRapper_Automation`.
2. The token is still valid.
3. `instagram_content_publish` is present.
4. The Instagram tester/account authorization is complete.
5. You are using the API host appropriate for the token type.

For the token used in this setup, the working host was:

```text
https://graph.facebook.com
```

not:

```text
https://graph.instagram.com
```

---

### Token debugger

Use Meta's Access Token Debugger:

https://developers.facebook.com/tools/debug/accesstoken/

Check:

- App
- Token type
- Valid
- Scopes
- Expiration
- User/account association

Never share the actual token when asking someone to debug the integration.

---

## 9. Token security incident during setup

A development token was accidentally exposed in a terminal screenshot during troubleshooting.

**If this happens again:**

1. Treat the token as compromised.
2. Revoke/regenerate it.
3. Update the application secret/environment variable.
4. Never reuse the exposed token.

The token value itself must never be recorded in this document.

---

## 10. Production implementation checklist

Before enabling automated posting:

- [ ] `TechnoRapper_Automation` is the active Meta app.
- [ ] `@techno.rapper` is the intended Instagram Professional account.
- [ ] Instagram Professional Account ID is stored as configuration.
- [ ] `instagram_content_publish` is available.
- [ ] Access token is stored securely.
- [ ] Access token is not in Git.
- [ ] Image is uploaded/hosted at a public HTTPS URL.
- [ ] Call `POST /{instagram-account-id}/media`.
- [ ] Save the returned `creation_id`.
- [ ] Call `POST /{instagram-account-id}/media_publish`.
- [ ] Record the returned published media ID.
- [ ] Handle API failures/retries without publishing duplicates.
- [ ] Add logging that never prints the access token.

---

## 11. Useful Meta documentation

Instagram Content Publishing:

https://developers.facebook.com/docs/instagram-platform/content-publishing/

Instagram API with Instagram Login:

https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login

Meta Developers:

https://developers.facebook.com/apps/

Access Token Debugger:

https://developers.facebook.com/tools/debug/accesstoken/

---

## 12. Quick reference

If someone needs to reproduce the working setup, the shortest path is:

```text
1. Open TechnoRapper_Automation in Meta Developers.
2. Configure/authorize @techno.rapper as the Instagram tester/account.
3. Ensure instagram_content_publish is available.
4. Generate a valid token.
5. Verify the token with Meta's Access Token Debugger.
6. Verify the account:

   GET https://graph.facebook.com/v27.0/17841473664186186
       ?fields=id,username
       &access_token=TOKEN

7. Create media:

   POST https://graph.facebook.com/v27.0/17841473664186186/media

8. Take the returned creation_id.
9. Publish:

   POST https://graph.facebook.com/v27.0/17841473664186186/media_publish

10. Verify the post on @techno.rapper.
```

This is the **known working content-publishing path established during the TechnoRapper integration setup**.
