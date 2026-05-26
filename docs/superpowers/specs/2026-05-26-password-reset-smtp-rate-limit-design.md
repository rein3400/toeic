# Password Reset SMTP and Rate Limit Design

## Goal

Harden the existing TOEIC password reset flow so reset email delivery uses configurable SMTP and reset requests are rate-limited without leaking whether an email address exists.

## Current State

The app already has a registered-email reset flow:

- `forgot_password.php` accepts an email and returns a neutral notice.
- `reset_password.php` consumes a token and updates the password.
- `includes/password_reset_helper.php` creates `password_reset_tokens`, stores token hashes, applies expiry, and sends email with PHP `mail()`.
- `admin/settings.php` exposes the reset enable toggle, expiry, from email, and from name.

## Proposed Architecture

Add SMTP as a transport inside `includes/password_reset_helper.php` while preserving the current helper API used by public pages. SMTP settings continue to live in `site_settings`, matching the existing admin settings pattern. If SMTP is disabled or incomplete, the helper falls back to PHP `mail()` so local development and older installs do not break.

Rate limiting is enforced before token creation. The existing `password_reset_tokens` table already stores email, IP, and creation time, so no new table is required. New limits are configurable through `site_settings` and admin UI:

- `password_reset_email_limit`: default `3`
- `password_reset_ip_limit`: default `10`
- `password_reset_rate_window_minutes`: default `60`

The user-facing response remains neutral for unknown emails and rate-limited requests.

## SMTP Settings

Add settings:

- `password_reset_smtp_enabled`: `1` or `0`
- `password_reset_smtp_host`
- `password_reset_smtp_port`: default `587`
- `password_reset_smtp_username`
- `password_reset_smtp_password`
- `password_reset_smtp_encryption`: `tls`, `ssl`, or empty

SMTP will use PHPMailer through Composer. Secrets remain in `site_settings` for now because this admin surface already stores payment credentials there. A later hardening pass can move the password to `.env` without changing the reset pages.

## Error Handling

The public forgot-password page should not show delivery failures or rate-limit status. The helper logs transport failures with `error_log()`. If SMTP fails, it may attempt PHP `mail()` fallback so admins have a migration path.

## Verification

Add a static contract test for:

- PHPMailer dependency exists in `composer.json`.
- SMTP setting keys and rate-limit setting keys are present.
- Helper contains SMTP send path and rate-limit guard.
- Public forgot-password page keeps the neutral notice.
- Admin settings exposes SMTP and rate-limit controls.
- Migration seeds the new defaults.

Run PHP syntax checks on changed PHP files and run the contract script.
