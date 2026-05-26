# Password Reset SMTP and Rate Limit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add SMTP delivery and configurable rate limiting to the existing TOEIC password reset flow.

**Architecture:** Keep the public reset pages thin and extend `includes/password_reset_helper.php` with SMTP transport and request throttling. Store operational controls in `site_settings` through the existing `admin/settings.php` pattern, and seed defaults in the standalone migration.

**Tech Stack:** PHP 8.2, mysqli, Composer, PHPMailer, existing TOEIC `site_settings`.

---

### Task 1: Contract Test

**Files:**
- Create: `scripts/test_password_reset_smtp_rate_limit_contract.php`

- [ ] **Step 1: Write the failing contract test**

Create a script that reads `composer.json`, `includes/password_reset_helper.php`, `forgot_password.php`, `admin/settings.php`, and `scripts/migrate_toeic_standalone.php`. It must assert that PHPMailer, SMTP settings, rate-limit settings, neutral public copy, and migration defaults exist.

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\xampp\php\php.exe scripts\test_password_reset_smtp_rate_limit_contract.php`

Expected before implementation: at least one `FAIL` line for missing PHPMailer or SMTP/rate-limit code.

### Task 2: SMTP Dependency and Helper

**Files:**
- Modify: `composer.json`
- Modify: `includes/password_reset_helper.php`

- [ ] **Step 1: Add PHPMailer dependency**

Add `"phpmailer/phpmailer": "^6.9"` to `composer.json`.

- [ ] **Step 2: Extend helper settings and transport**

In `includes/password_reset_helper.php`, load Composer autoload when present, add SMTP setting readers, add a `toeicPasswordResetSendSmtpEmail()` helper using `PHPMailer\PHPMailer\PHPMailer`, and update `toeicPasswordResetSendEmail()` to try SMTP when enabled before falling back to `mail()`.

- [ ] **Step 3: Add rate-limit guard**

Add `toeicPasswordResetRateLimitStatus(mysqli $conn, string $email): array` that counts recent rows by email and IP using the configured window and limits. Call it inside `toeicCreatePasswordReset()` after schema ensure and before token creation.

- [ ] **Step 4: Run helper syntax check**

Run: `C:\xampp\php\php.exe -l includes\password_reset_helper.php`

Expected: `No syntax errors detected`.

### Task 3: Admin Settings and Migration Defaults

**Files:**
- Modify: `admin/settings.php`
- Modify: `scripts/migrate_toeic_standalone.php`

- [ ] **Step 1: Save SMTP and rate-limit settings**

Extend the `update_auth_settings` branch in `admin/settings.php` to save SMTP enable, host, port, username, password, encryption, email limit, IP limit, and window minutes.

- [ ] **Step 2: Render SMTP and rate-limit controls**

Extend the existing `Lupa Password Pengguna` admin card with compact SMTP and rate-limit fields.

- [ ] **Step 3: Seed migration defaults**

Add `site_settings` inserts for the new SMTP and rate-limit keys in `scripts/migrate_toeic_standalone.php`.

- [ ] **Step 4: Run syntax checks**

Run:

```powershell
C:\xampp\php\php.exe -l admin\settings.php
C:\xampp\php\php.exe -l scripts\migrate_toeic_standalone.php
```

Expected: `No syntax errors detected` for both files.

### Task 4: Verification

**Files:**
- Test: `scripts/test_password_reset_smtp_rate_limit_contract.php`

- [ ] **Step 1: Run Composer update/install**

Run: `composer update phpmailer/phpmailer --no-interaction`

Expected: Composer installs or updates PHPMailer and refreshes `composer.lock` / `vendor` as needed.

- [ ] **Step 2: Run contract test**

Run: `C:\xampp\php\php.exe scripts\test_password_reset_smtp_rate_limit_contract.php`

Expected: all contract checks pass.

- [ ] **Step 3: Run changed PHP syntax checks**

Run syntax checks for `forgot_password.php`, `reset_password.php`, `includes/password_reset_helper.php`, `admin/settings.php`, `scripts/migrate_toeic_standalone.php`, and the new contract script.

Expected: all report `No syntax errors detected`.

- [ ] **Step 4: Review diff**

Run: `git diff --check`

Expected: no whitespace errors.
