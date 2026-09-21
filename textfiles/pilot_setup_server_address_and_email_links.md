# Pilot setup: server address, email links and Google login

**For:** whoever sets up the pilot server PC. It is written to be followed **without internet access to Claude**.
**Written:** 2026-09-22, from the code on `develop` after phase-42.
**Short version:** no PHP code has to change. Everything below is settings in `.env`, one entry in Google Cloud Console, and how the server is started.

---

## 1. Why this is needed

Out of the box, the app assumes it is only opened on the machine it runs on (`127.0.0.1`). On the pilot, other PCs open it over the network, and that breaks four things.

| What breaks | Why | Fixed by |
|---|---|---|
| **Google login on every PC except the server** | After signing in, Google sends the browser back to `GOOGLE_REDIRECT_URI`, which is `http://127.0.0.1:8000/...`, meaning the user's *own* PC. | Step 3 + Step 4 |
| **The "Log in" button in the new-contractor-account email and the login-link email** | Built with `url('/login')`, which copies the address the *admin* is browsing on. An admin using `127.0.0.1` on the server PC sends contractors a button pointing at the contractor's own PC. | Step 3 + **the rule below** |
| **The AI report's open-tracking image** (always shows 0 opened) | `route('tracking.open')` → `APP_URL` when the scheduler sends it. | Step 3 |
| **Links inside Google Calendar events** | `GoogleCalendarService.php` lines 371 and 433 use `config('app.url')`. | Step 3 |
| Other PCs cannot open the site at all | `php artisan serve` listens on the server PC only, unless told otherwise. | Step 5 |
| Page styling can break on other PCs | `npm run dev` writes `public/hot`, pointing pages at `http://[::1]:5173`, which is the viewer's own PC. | Step 5 |

**Not affected:** the 8 notification emails (deadline, inspections, extensions, issues, risks) contain **no links**, only text. (The 2026-09-21 restudy said they had "View project" buttons; that was wrong.)

**Rule for the pilot:** everyone, **including the admin sitting at the server PC**, opens the system at `SERVER`, never at `127.0.0.1` or `localhost`. Otherwise emails sent from their actions carry the wrong address.

---

## 2. Decide the server address (do this first)

Pick **one**. Every later step uses it. Below it is called **`SERVER`**.

**A. The system is deployed to a hosted server with a domain name** (the plan as of 2026-09-22).
`SERVER` = `https://your-domain` (for example `https://pmu.cspc.edu.ph`). This is the easy case; skip the Google warning in Step 4.

**B. The pilot runs on the admin's desktop over the office network (LAN).**
1. On the server PC, open Command Prompt and run `ipconfig`.
2. Under the adapter that is connected (Wi-Fi or Ethernet), read **IPv4 Address**, for example `192.168.1.25`.
3. `SERVER` = `http://192.168.1.25:8000`
4. ⚠ **Ask IT to keep this IP fixed** (a static IP or a DHCP reservation). If the IP changes, every email link, calendar link and login breaks again, and you must repeat Steps 3–4.
5. ⚠ **Read the Google warning in Step 4 before choosing B.**

---

## 3. Edit `.env` on the server PC

`.env` is in the project folder (the same folder as `artisan`). Open it in Notepad. Change **exactly these lines**; leave everything else.

Before:

```env
APP_URL=http://127.0.0.1:8000
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
```

After (replace `SERVER` with your address from Step 2, **no slash at the end**):

```env
APP_URL=SERVER
GOOGLE_REDIRECT_URI=SERVER/auth/google/callback
```

Example for a LAN server:

```env
APP_URL=http://192.168.1.25:8000
GOOGLE_REDIRECT_URI=http://192.168.1.25:8000/auth/google/callback
```

**Recommended at the same time** (pilot finding F1). These hide the developer Date Simulator and stop error pages from showing code to users:

```env
APP_ENV=production
APP_DEBUG=false
```

> Note: with `APP_ENV=production`, the `/dev/*` routes (Date Simulator, "Run Notification Scan" widget) disappear. `php artisan migrate` will also ask "Are you sure?"; answer `yes`, or add `--force`.

**Then clear the cached settings.** This is required; the app keeps reading the old values otherwise:

```
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

If the queue worker or scheduler is already running, **stop and restart them** (Step 5). They only read `.env` when they start.

---

## 4. Register the new login address in Google Cloud Console

Google refuses to send users back to an address it does not know. This needs internet, but not Claude.

1. Go to <https://console.cloud.google.com/> and sign in with the Google account that owns the project's OAuth client.
2. Select the project whose **Client ID** matches `GOOGLE_CLIENT_ID` in `.env` (starts with `1073829316144-`).
3. Menu → **APIs & Services** → **Credentials** → under *OAuth 2.0 Client IDs*, click the web client.
4. Under **Authorized redirect URIs** → **Add URI** → paste exactly the value of `GOOGLE_REDIRECT_URI` from Step 3.
5. Keep the old `http://127.0.0.1:8000/auth/google/callback` entry if developers still run it locally.
6. **Save.** Changes can take a few minutes to apply.

> ⚠ **Google warning for option B (LAN IP).** Google's rules for redirect URIs, as known when this was written:
> - they must use `https://`, **except** for `localhost` / `127.0.0.1`;
> - they may not be a raw IP address like `192.168.1.25`, except localhost.
>
> If Google rejects the URI on Save, that is why. **Google login will then only work on the server PC itself.** Your options:
> 1. **Deploy with a domain and HTTPS** (option A). This is the clean fix.
> 2. Give the LAN server a real domain name with an HTTPS certificate (needs IT).
> 3. Use a tunnelling service that gives a public `https://` address pointing at the server PC (for example Cloudflare Tunnel or ngrok). Login already needs internet, so this adds no new dependency. Use that `https://...` address as `SERVER` in Steps 3–4.
>
> Check this **before the pilot day**: add the URI in the Console and see whether it saves.

**If the Google Calendar sync is used:** the OAuth consent screen and calendar scope are unaffected; only the redirect URI changes.

---

## 5. Start the server so other PCs can reach it

Do **not** use `composer dev` for the pilot. It starts `npm run dev` (breaks styling on other PCs) and `php artisan serve` (only reachable from the server PC).

**Once, after copying the project** (needs Node.js installed):

```
npm run build
```

Then **delete the file `public\hot`** if it exists. While that file exists, pages look for Vite on the viewer's own PC.

**Every time the server PC starts: three windows**, each left open:

```
php artisan serve --host=0.0.0.0 --port=8000
php artisan queue:work
php artisan schedule:work
```

- `serve --host=0.0.0.0` = accept connections from other PCs.
- `queue:work` = **sends the notification emails**. Since phase-42 they are queued: if this window is closed, emails wait, and in-app notices still appear. It also runs the Google Calendar sync.
- `schedule:work` = runs the daily notification scan (07:00) and the AI monthly reports (25th 08:00, sending 08:05). **The PC must be on at those times**; missed times are not caught up (restudy §7, A2).

**Windows Firewall** (once, in PowerShell opened *as Administrator*), so other PCs may connect to port 8000:

```
netsh advfirewall firewall add rule name="PMU System 8000" dir=in action=allow protocol=TCP localport=8000
```

(Option A, hosted server: the hosting uses its own web server and HTTPS instead of `artisan serve`. Keep `queue:work` and `schedule:work` running there as services.)

---

## 6. Check it worked

Do these from a **different PC** than the server:

1. Open `SERVER` in the browser → the login page appears **with normal styling**.
2. Log in with Google → you land on the dashboard, **not** on an error or on `127.0.0.1`.
3. Report a test issue on a project → the notified person's notification email arrives (proves the queue worker runs; this email has no link).
4. Create a test contractor account (or send the login-page link) → in that email, click **Log in** → it opens `SERVER`.
5. (If calendar sync is on) open the project's event in Google Calendar → its link opens `SERVER`.
6. On the server PC, `php artisan tinker --execute="echo config('app.url');"` prints `SERVER`.

---

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Other PCs: "This site can't be reached" | `serve` without `--host=0.0.0.0`, firewall, wrong IP | Step 5; re-check `ipconfig` |
| Login ends on `127.0.0.1` or "connection refused" | `GOOGLE_REDIRECT_URI` still old, or config cached | Step 3, then `php artisan config:clear` |
| Google error **`redirect_uri_mismatch`** | URI not added in the Console, or it differs (http/https, port, trailing slash) | Step 4: must match `.env` character for character |
| Google Console refuses to save the URI | Raw IP / `http` rule (Step 4 warning) | Option A or a tunnel |
| Email "Log in" button opens `127.0.0.1` | The admin who triggered it was browsing at `127.0.0.1` | Everyone uses `SERVER` (rule in §1); re-send the email |
| AI report tracking / calendar links show `127.0.0.1` | `APP_URL` old; **or the worker / scheduler was started before the change** | Step 3, then restart `queue:work` and `schedule:work` |
| Emails never arrive, in-app notices do | `queue:work` not running | Start it; waiting emails then go out |
| Pages unstyled on other PCs only | `public\hot` exists | Delete it; `npm run build` |
| Everything worked, then broke on another day | Server PC's IP changed | Fixed IP (Step 2), then redo Steps 3–4 |

---

## 8. Reference: where the address is used in the code

No edits are needed; this is for understanding and for debugging.

| Setting | Read by | Used for |
|---|---|---|
| `APP_URL` | `config/app.php` → `url` | `route()` / `url()` when there is **no web request**: the scheduler / queue worker, e.g. the AI report tracking image (`app/Mail/AiAnalysisMail.php:53` → `resources/views/emails/ai-analysis.blade.php:224`) |
| (request host) | `resources/views/emails/contractor_account.blade.php:52`, `loginpagelink.blade.php:53` | `url('/login')` button, built while the admin's page request runs, so it copies the admin's address |
| `APP_URL` | `app/Services/GoogleCalendarService.php:371, :433` | project link inside calendar events |
| `GOOGLE_REDIRECT_URI` | `config/services.php` → `google.redirect` → Socialite | where Google returns after login (`/auth/google/callback`, `routes/web.php:61`) |
| (none; request host) | `url()` / `route()` / `asset()` during a normal page view | on-screen links use whatever address the user typed, so they work once the site is reachable |

Only if you ever want the **code** to guard against a wrong address: a check at boot that logs a warning when `APP_ENV=production` and `APP_URL` contains `127.0.0.1` would go in `app/Providers/AppServiceProvider.php` → `boot()`. It is not needed for the pilot.
