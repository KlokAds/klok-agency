# KlokOne — Android app

The site (billing.klokads.com) inside a Flutter app, plus what a phone does better:

- Notifications through Firebase, each with a sound: `finance_urgent` (money: pops up, loud) and
  `finance_normal`. Firebase keeps a message for 7 days for a phone that is off.
- App lock: fingerprint (phone PIN behind it), the app's own PIN, or off; locks again after a
  chosen time away. The app stays signed in ("remember this device"), so the web's idle
  sign-out is off inside it. Signing in anywhere signs that person out everywhere else.
- Payment SMS forwarding on the Owner's phone only (`android-src/SmsForwarder.kt`): only the
  senders the server names (bKash, NAGAD, 16216, bank) leave the phone; each SMS is queued on
  the phone and sent by WorkManager until the server has it.
- Offline: the site's service worker keeps the pages opened; quick entries written offline wait
  on the phone and are booked once when the internet is back.

## Versions

Pages come from the site, so a site change needs no new APK. Only phone-side changes do: raise
`version:` in `pubspec.yaml` (the `+number` must go up) once several have piled up, and push.
`.github/workflows/android.yml` then builds, publishes a release and hands the APK to the server
(`APP_RELEASE_URL`); the app asks once a day and offers the update.

Secrets: `FINANCE_GOOGLE_SERVICES_JSON` (Firebase app com.klokads.finance, project matir-khamar),
`KEYSTORE_BASE64` / `KEYSTORE_PASSWORD` (the same key as the farm app), `APP_RELEASE_URL`
(`https://billing.klokads.com/hooks/app-release/<token>`; the token's hash is the
`app_release_token` setting). The server's Firebase key goes in Settings › Phone app.
