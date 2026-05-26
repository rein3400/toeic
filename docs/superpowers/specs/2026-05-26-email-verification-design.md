# Email Verification Design

## Goal

Require production email verification for TOEIC accounts that register or attach an email address, while preserving a recovery path for legacy users.

## Current State

- Public registration already requires `users.email`.
- Password reset works only for accounts with a stored email.
- Login does not check whether an email address is verified.
- Student profile does not expose email updates.
- Admin user management can now add/edit reset email for legacy and admin-created accounts.

## Proposed Architecture

Add a dedicated email verification flow beside password reset:

- `users.email_verified_at DATETIME NULL`
- `email_verification_tokens` stores hashed one-time tokens.
- `includes/email_verification_helper.php` owns schema, token creation, email delivery, token consumption, and page guards.
- `verify_email.php` consumes emailed verification links.
- `user/verify_email.php` shows verification status and resend controls.
- `user/resend_verification.php` sends a new verification link for the logged-in student.

The flow uses the same SMTP transport as password reset through the existing PHPMailer-backed reset helper. If SMTP is configured in production, verification email delivery works the same way as reset email delivery.

## User Rules

- New registered users are created with `email_verified_at = NULL`, receive a verification email, and are redirected to login with a verification notice.
- Students can log in before verification so they can resend the email or correct their email from profile.
- Important student actions are blocked until verification: dashboard, buy/checkout/payment, test instructions, LR test, and SW test.
- Student profile remains accessible and can update the email; changing email resets `email_verified_at` and sends a new verification email.
- Admin-created or legacy users with an email but no `email_verified_at` are treated as unverified until they verify.
- Admin user management displays email verification status and can resend verification links for accounts with email.

## Security

- Store token hashes only.
- Tokens are one-time use and expire.
- Resend is rate-limited per email and per IP.
- Public verification errors stay generic.
- Admin can mark email verified only by forcing a resend or editing the account; automatic trust is avoided.

## Verification

Use a static contract test to confirm schema, helper functions, route wiring, guards, and admin/profile/register/login integration. Run PHP syntax checks on changed PHP files and rerun password-reset contract tests to confirm shared SMTP behavior still works.
