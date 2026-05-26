# Email Verification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add email verification for new, legacy, and email-updated TOEIC accounts.

**Architecture:** Add a focused helper for email verification schema, token lifecycle, email sending, resend throttling, and student guards. Reuse the existing SMTP transport from the password reset helper, then wire register, login, profile, admin users, and critical student pages through the helper.

**Tech Stack:** PHP 8.2, mysqli, PHPMailer through existing Composer autoload, existing TOEIC `site_settings`.

---

### Task 1: Contract Test

**Files:**
- Create: `scripts/test_email_verification_contract.php`

- [ ] Write a static contract test that asserts helper functions, new pages, register/login/profile/admin wiring, migration schema, and important page guards exist.
- [ ] Run `C:\xampp\php\php.exe scripts\test_email_verification_contract.php` and confirm it fails before implementation.

### Task 2: Helper and Schema

**Files:**
- Create: `includes/email_verification_helper.php`
- Modify: `scripts/migrate_toeic_standalone.php`

- [ ] Add `toeicEnsureEmailVerificationSchema()` with `users.email_verified_at` and `email_verification_tokens`.
- [ ] Add `toeicCreateEmailVerification()`, `toeicConsumeEmailVerification()`, `toeicEmailVerificationRateLimitStatus()`, `toeicSendEmailVerification()`, and `toeicRequireVerifiedEmail()`.
- [ ] Add migration defaults and schema for `email_verification_tokens`.
- [ ] Run PHP syntax checks for the helper and migration.

### Task 3: Public and Student Flow

**Files:**
- Modify: `register.php`
- Modify: `login.php`
- Create: `verify_email.php`
- Create: `user/verify_email.php`
- Create: `user/resend_verification.php`
- Modify: `user/profile.php`

- [ ] Register new users as unverified and send verification email.
- [ ] Login redirects unverified students to `user/verify_email.php`.
- [ ] Public `verify_email.php` consumes links and redirects to login.
- [ ] Student notice page shows status and resend action.
- [ ] Profile can update email; email changes clear verification and send a new link.
- [ ] Run PHP syntax checks for all changed/created files.

### Task 4: Guards and Admin

**Files:**
- Modify: `user/index.php`
- Modify: `buy_exam.php`
- Modify: `payment.php`
- Modify: `checkout-va.php`
- Modify: `user/test_instructions.php`
- Modify: `user/test_toeic.php`
- Modify: `user/test_toeic_sw.php`
- Modify: `admin/users.php`

- [ ] Add `toeicRequireVerifiedEmail($conn)` to critical student/payment/test pages.
- [ ] Admin users table shows verified/unverified/missing email status.
- [ ] Admin can resend verification for a user with an email.
- [ ] Run PHP syntax checks for all touched files.

### Task 5: Verification and Push

**Files:**
- Test: `scripts/test_email_verification_contract.php`
- Test: `scripts/test_password_reset_smtp_rate_limit_contract.php`
- Test: `scripts/test_admin_users_email_reset_contract.php`

- [ ] Run all contract tests.
- [ ] Run `git diff --check`.
- [ ] Commit with required Lore and co-author trailers.
- [ ] Merge to `main` and push `origin main`.
