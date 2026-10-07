# Gmail + Facebook auth for AutoFlows

Self-hosted OAuth — no Composer, no framework. Two jobs share one flow:

1. **Social login** — Continue with Google / Facebook (`/index.php?r=login`)
2. **Channel connect** — Gmail API send + Facebook Page posts (`Settings → Channels`)

Without keys or tokens, everything still works in **simulated** mode so
autoflows complete end-to-end (result stored in `content.meta.publish_result`).

## 1. Redirect URIs

Start the app, open Settings → Channels, and copy the two URIs
(they follow your host automatically):

```
http://127.0.0.1:8020/index.php?r=auth/google/callback
http://127.0.0.1:8020/index.php?r=auth/facebook/callback
```

For production use `https://your-domain/...` for both.

## 2. Google (Gmail send)

1. https://console.cloud.google.com → New project → **APIs & Services → Credentials**
2. **Create Credentials → OAuth client ID → Web application**
   - Authorized redirect URI: paste the Google URI above
3. **OAuth consent screen** → External → add your Gmail as test user
4. Enable **Gmail API** (APIs & Services → Library → Gmail API → Enable)
5. Scopes requested by AutoFlows: `openid email profile` + `https://www.googleapis.com/auth/gmail.send`
6. Paste **Client ID + Client secret** into Settings → Channels → Save
7. Click **Connect Gmail** (or Continue with Google on the login page)

## 3. Facebook (Page posts)

1. https://developers.facebook.com → **Create App** (Business type)
2. Add product **Facebook Login** → Settings → Valid OAuth Redirect URIs: paste the Facebook URI
3. Scopes: `public_profile email pages_manage_posts pages_read_engagement`
4. **App Review → Permissions**: `pages_manage_posts` needs review for public users;
   while in Development Mode it works for admins/testers of the app.
5. To post **as a Page**: make the login account an admin of the Page.
   AutoFlows picks the first manageable Page (`/me/accounts`) automatically.
6. Paste **App ID + App secret** into Settings → Channels → Save → **Connect Facebook**

## 4. How publishing works

| Content | Destination |
|---|---|
| `channel=email` | Gmail API `users.messages.send` (needs recipient `to`) |
| `channel=social`, `platform=facebook` (or blank) | Graph API `{page-id\|me}/feed` |
| `channel=social`, other platform | recorded as simulated cross-post copy |
| `channel=blog` | marked published locally (no external endpoint) |

Every result lands in `content.meta.publish_result`:
`{ok, simulated, post_id|message_id|url, note|error, at}` —
visible on the editor card and in `task_logs` (`publish.*`).

The daily worker delivers due items too:

```bash
php tools/run_daily.php --publish
```

## 5. Offline / dev mode

- Login page → **Gmail dev / FB dev**: one-click accounts with a simulated
  channel, no network needed.
- Disable anytime: set `auth.allow_dev_login` to `false` in `app/config.php`.

## 6. Notes

- Tokens are obfuscated before storage (`SocialAuth::seal` — AES-256-GCM when
  `AF_SEAL_KEY` / `oauth.seal_key` is set, else base64) and never echoed back.
- Secrets are entered as password fields; leaving them blank on Save keeps the stored value.
- Google access tokens auto-refresh via the stored refresh token.
- Facebook tokens are exchanged for 60-day long-lived tokens.
- Tables: `users`, `social_accounts` (auto-created + migrated on boot).
- Routes: `login logout auth/google auth/google/callback auth/facebook
  auth/facebook/callback auth/dev auth/disconnect api/auth/status api/content/publish`.
