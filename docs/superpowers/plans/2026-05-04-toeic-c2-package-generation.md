# TOEIC C2 Package Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate TOEIC packages 02-10 as C2-level, import-ready JSON packages with transcript manifests for later audio/image upload.

**Architecture:** Keep the existing single-package `content/generated/toeic` untouched and stage multi-package output under `content/generated/toeic_packages/package_XX`. A deterministic PHP generator creates TOEIC-only JSON files and package-level transcript manifests; a PHP validator verifies count, answer, transcript, and media-reference consistency.

**Tech Stack:** PHP 8.2 CLI, JSON files, existing TOEIC schema conventions, MiniMax/OpenAI media scripts in later steps.

---

### Task 1: Generate Multi-Package TOEIC Content

**Files:**
- Create: `scripts/generate_toeic_c2_packages.php`
- Create output: `content/generated/toeic_packages/package_02` through `content/generated/toeic_packages/package_10`

- [ ] **Step 1: Create deterministic package generator**

Create `scripts/generate_toeic_c2_packages.php` with CLI options `--from`, `--to`, and `--overwrite`. It must write `part1.json` through `part7.json`, `media/transcripts.json`, and `manifest.json` per package.

- [ ] **Step 2: Run generator**

Run:

```powershell
C:\xampp\php\php.exe scripts\generate_toeic_c2_packages.php --from=2 --to=10 --overwrite
```

Expected: packages 02-10 are written with no PHP errors.

### Task 2: Validate Package Quality Gates

**Files:**
- Create: `scripts/validate_toeic_c2_packages.php`

- [ ] **Step 1: Create validator**

Create `scripts/validate_toeic_c2_packages.php` that verifies each package has 100 listening questions, 100 reading questions, 54 listening transcript files, 6 Part 1 images, valid A-D/A-C answers, unique non-empty options, and no placeholder-only answers.

- [ ] **Step 2: Run validator**

Run:

```powershell
C:\xampp\php\php.exe scripts\validate_toeic_c2_packages.php content\generated\toeic_packages
```

Expected: validator reports all generated packages valid.

### Task 3: Media Generation Follow-Up

**Files:**
- Reuse: `scripts/generate_toeic_audio_batch.ps1`
- Potentially create: package media folders under `content/generated_media/toeic/package_XX`

- [ ] **Step 1: Generate audio package-by-package**

Run the existing audio script against each package transcript manifest, MiniMax first and OpenAI fallback only if configured.

- [ ] **Step 2: Generate Part 1 images package-by-package**

Use MiniMax image generation for each package Part 1 `image_prompt`, saving files with the package-specific names referenced in JSON.

- [ ] **Step 3: Verify media**

Check every referenced MP3 and image exists, has a non-trivial byte size, and can be mapped back to the package manifest.
