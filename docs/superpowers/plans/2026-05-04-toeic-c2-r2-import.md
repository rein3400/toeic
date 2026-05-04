# TOEIC C2 R2 Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a browser-accessible importer that loads generated TOEIC C2 packages into the existing TOEIC tables with Cloudflare R2 media URLs.

**Architecture:** Keep import logic in a shared include and keep the admin browser page thin. The importer reads `content/generated/toeic_packages/package_02` through `package_10`, applies deterministic text cleanup and quality gates, creates media/text rows, and imports package-aware question numbers.

**Tech Stack:** PHP 8, mysqli, existing TOEIC tables, Cloudflare R2 public URLs.

---

### Task 1: Shared Importer

**Files:**
- Create: `includes/toeic_c2_package_importer.php`

- [ ] **Step 1: Add importer functions**

Create functions for strict JSON loading, option normalization, answer normalization, R2 URL construction, transcript extraction, deterministic text cleanup, quality validation, idempotent media/text creation, and question insertion.

- [ ] **Step 2: Add package-aware numbering**

Use listening numbers `(($packageNumber - 1) * 100) + 1..100` and reading numbers `(($packageNumber - 1) * 100) + 101..200` so imported packages do not collide with the existing package-1 numbering.

- [ ] **Step 3: Add dry-run support**

When dry-run is enabled, run all validation and counting but skip database writes.

### Task 2: Browser Runner

**Files:**
- Create: `admin/import_toeic_c2_packages.php`

- [ ] **Step 1: Add admin/bootstrap guard**

Reuse the `TOEIC_SETUP_TOKEN` bootstrap pattern from `admin/setup_toeic_production.php` and allow admin-session access when users already exist.

- [ ] **Step 2: Add a form**

Show package range, content directory, R2 base URL, dry-run mode, optional media HEAD verification, current table counts, and a submit button.

- [ ] **Step 3: Execute import**

On POST, run `scripts/migrate_toeic_standalone.php`, call the shared importer, and print stats plus quality warnings/fixes.

### Task 3: Verification

**Files:**
- Modify: `scripts/upload_toeic_r2_media.ps1`
- Create: `includes/toeic_c2_package_importer.php`
- Create: `admin/import_toeic_c2_packages.php`

- [ ] **Step 1: Lint changed PHP**

Run `C:\xampp\php\php.exe -l includes\toeic_c2_package_importer.php` and `C:\xampp\php\php.exe -l admin\import_toeic_c2_packages.php`. Expected: `No syntax errors detected`.

- [ ] **Step 2: Validate generated packages**

Run `C:\xampp\php\php.exe scripts\validate_toeic_c2_packages.php`. Expected: all generated C2 packages validate successfully.

- [ ] **Step 3: Verify R2 samples**

HEAD sample audio and image URLs from packages 02, 03, and 10. Expected: HTTP 200.
