# TOEIC Part 1 Photo Replacement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Regenerate rejected TOEIC Part 1 images as versioned replacement files and provide a dry-run-first script for updating already-imported production photo URLs.

**Architecture:** Keep regenerated image targets in a JSON map, generate new `_v2.png` files beside the existing media, and update only `toeic_photos.file_path` so existing audio and question links remain intact. The production DB script is CLI-only and dry-run by default.

**Tech Stack:** PowerShell, MiniMax `mmx`, PHP 8, mysqli, existing TOEIC C2 importer URL layout.

---

### Task 1: Replacement Map

**Files:**
- Create: `content/generated/toeic_photo_replacements.json`

- [x] **Step 1: List the rejected and borderline images**

Include package number, item id, original image filename, replacement filename, and review reason for each image that should be regenerated.

### Task 2: Regeneration Helper

**Files:**
- Create: `scripts/regenerate_toeic_part1_photo_replacements.ps1`

- [x] **Step 1: Read the replacement map**

The script reads `content/generated/toeic_photo_replacements.json`, loads each package `part1.json`, and finds the source item by `item_id`.

- [x] **Step 2: Generate versioned replacement PNGs**

The script uses `mmx image generate` with a strict no-text/no-logo/no-watermark prompt suffix and writes files such as `toeic_pkg07_p1_06_v2.png` into the existing `D:\toeic_generated_media\package_07\photos` directory.

- [x] **Step 3: Write a replacement manifest**

The script writes `D:\toeic_generated_media\toeic_photo_replacement_manifest.json` with hashes, byte sizes, generation status, and source prompts.

### Task 3: Production DB Replacement Script

**Files:**
- Create: `scripts/replace_toeic_part1_photo_urls.php`

- [x] **Step 1: Make dry-run the default**

The script previews exact `toeic_photos` rows and linked Part 1 question counts without writing unless `--apply --confirm=replace-toeic-part1-photos` is passed.

- [x] **Step 2: Update only photo URLs**

When applied, the script updates `toeic_photos.file_path` from the old R2 URL to the new `_v2.png` R2 URL, preserving `id_photo`, `toeic_audio.id_photo`, and all `toeic_soal_listening` links.

### Task 4: Verification

**Files:**
- `scripts/regenerate_toeic_part1_photo_replacements.ps1`
- `scripts/replace_toeic_part1_photo_urls.php`

- [ ] **Step 1: Run the regeneration script**

Run the PowerShell script with `-Overwrite` only for the replacement targets.

- [ ] **Step 2: Inspect regenerated images**

Create/view a contact sheet or open original files to reject any remaining overlays, watermarks, or readable text.

- [ ] **Step 3: Lint PHP**

Run `C:\xampp\php\php.exe -l scripts\replace_toeic_part1_photo_urls.php`.

- [ ] **Step 4: Preview DB changes**

Run the DB script without `--apply` against the configured target DB and read the output before applying.
