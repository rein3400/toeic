# toeic

## What this codebase does

Procedural PHP 8.2 TOEIC training and simulation app
backed by MySQL/mysqli.
It serves public landing/auth pages, student dashboards,
TOEIC Listening/Reading and TOEIC Speaking/Writing flows,
Tripay payment/voucher credit purchase, secure audio playback,
proctoring, scoring/result reports, and admin content/import tooling.
Shared business logic lives mostly in `includes/`;
thin browser/API entry points live in root pages, `user/`, `api/`,
and `admin/`.

## Auth shape

- `includes/session_handler.php` starts sessions, using `DbSessionHandler`
  when MySQL is available; identity is `$_SESSION['user_id']`,
  privilege is `$_SESSION['role']`.
- Student pages usually require `isset($_SESSION['user_id'])`
  plus `$_SESSION['role'] === 'student'`; admin pages require `admin`.
- `includes/csrf_helper.php` provides `generateCsrfToken`,
  `validateCsrfToken`, `csrfField`, and `csrfMeta`; state-changing
  user/admin AJAX should use it unless the endpoint is a signed callback.
- Credit primitives are `hasStrictTestCredit`, `consumeTestCredit`,
  `grantTestCredit`, `hasToeicAccess`, and `getUsersIdColumn`.
- TOEIC session ownership is normally enforced by querying `test_session`
  together with `user_id`, or by `toeicGetSessionSummary`
  and `toeicIsPracticeSession`.

## Threat model

Highest-impact bugs grant TOEIC credits without real payment/voucher
authority, expose admin-only setup/import tooling, leak secure TOEIC
audio, or let one student read/modify another student's test session,
result, recording, or proctoring data.
Anonymous attackers can hit public root pages and signed callbacks.
Logged-in students can supply `test_session`, `question_id`, audio ids,
voucher codes, and proctoring events.
Admin compromise matters because admin pages manage users, vouchers,
Tripay/API settings, TOEIC content, imports, proctoring reviews,
and scoring data.

## Project-specific patterns to flag

- Entry points that trust `test_session`, result ids, audio ids,
  voucher ids, or uploaded media without tying them back to
  `$_SESSION['user_id']` and the expected role.
- Code that grants or consumes credits outside `grantTestCredit` /
  `consumeTestCredit`, or accepts `exam_type` beyond `toeic` and
  `toeic_sw` in this TOEIC-only product.
- Tripay callback handling must remain public but signed: look for
  `TripayHandler::verifyCallbackSignature`, terminal-status idempotence,
  and `TOEIC-` / `TOEICSW-` merchant refs before `grantTestCredit`.
- Secure TOEIC audio should flow through `api/get_audio_token.php`,
  `AudioStreamer::generateToken`, `api/stream_audio.php`, and
  `AudioStreamer::validateAndMarkStarted`; direct `uploads/toeic_audio`
  or public remote audio URLs are high signal.
- Bootstrap/dev bypass paths (`TOEIC_SETUP_TOKEN`, `SETUP_BOOTSTRAP_TOKEN`,
  `DEV_BYPASS_TOKEN`) require server-side env tokens and `hash_equals`;
  client input alone must not enable import, credit grant, or proctor bypass.

## Known false-positives

- `api/tripay_callback.php` intentionally has no session auth;
  `X-Callback-Signature` verified by `TripayHandler` is the auth boundary.
- `index.php`, `login.php`, `register.php`, and `checkout-va.php`
  are intentionally public; `includes/config.php` can render these public
  GET pages gracefully when DB is unavailable.
- Public asset directories are expected for `uploads/settings/`,
  `uploads/images/`, and TOEIC photo assets; TOEIC audio is different
  and should stay behind secure streaming when `FEATURE_SECURE_AUDIO` is on.
- `admin/import_toeic_c2_packages.php` and `admin/setup_toeic_production.php`
  intentionally support first-boot bootstrap mode when the `users` table
  is missing, but only with a configured setup token.
- `content/generated/`, `docs/previews/`, `.deepsec/`, and many
  `scripts/test_*` / `scripts/generate_*` files are local tooling or
  scanner context, not normal runtime routes.
