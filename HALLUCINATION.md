# HALLUCINATION.md

## 2026-05-03 — Remove Kampung Inggris settings assets

### Unknowns
- Whether a local database setting or admin-upload configuration still points to `uploads/settings/logo.jpeg` or `uploads/settings/favicon.jpeg` at runtime.

### Reason for proceeding
- The user explicitly requested removing all Kampung Inggris elements, including the logo file, because the TOEIC product is unrelated to Kampung Inggris.

### Assumptions used
- The tracked settings logo and favicon are not required for the new TOEIC-only preview direction.
- If production settings still point to those files, a TOEIC-specific replacement can be added during the implementation phase.

### Project impact
- Deleted `uploads/settings/logo.jpeg`.
- Deleted `uploads/settings/favicon.jpeg`.
- Removed preview references to `../../uploads/settings/logo.jpeg` and replaced them with a CSS/text TOEIC brand mark.

### Verification attempted
- Searched preview files for `logo.jpeg`, `favicon.jpeg`, `Kampung`, `Inggris`, and `Rumah`.
- Searched non-doc, non-output source files for the same terms and did not find source references.
- Rendered representative static preview screenshots after removing logo references.

### Risks and rollback
- Risk: a database-configured site setting may still expect the deleted image paths.
- Rollback: restore the deleted assets from git, or add TOEIC-specific replacement assets at the same paths during production implementation.

## 2026-05-03 — TOEIC listening audio batch output path

### Unknowns
- The user did not specify the final local destination for newly generated audio before the Cloudflare R2 upload/import phase.
- The import mapping for the future 10 generated packages is not present yet in this workspace.

### Reason for proceeding
- The user explicitly requested autonomous generation with MiniMax first and OpenAI fallback if MiniMax reached its limit.
- A separate generated-media directory avoids overwriting the known-good examples under `uploads/toeic_audio`.

### Assumptions used
- New generated audio should be staged under `content/generated_media/toeic/minimax_audio`.
- `uploads/toeic_audio/transcripts.json` is the canonical spoken-script source for the current 54 listening audio targets.
- MiniMax English system voices satisfy the required US/British-style English accent constraint for the MiniMax-generated Part 1 and Part 2 files.

### Project impact
- Added `scripts/generate_toeic_audio_batch.ps1`.
- Generated 54 MP3 files under `content/generated_media/toeic/minimax_audio`.
- Wrote generation evidence to `content/generated_media/toeic/minimax_audio_manifest.json` and `content/generated_media/toeic/minimax_audio_generation.log`.

### Verification attempted
- Confirmed MiniMax authentication was active before generation.
- Verified expected-vs-actual audio filenames: 54 expected, 54 actual, 0 missing, 0 extra.
- Verified every generated MP3 had a valid MP3 header and non-trivial byte size.
- Confirmed MiniMax hit `usage limit exceeded`, then continued remaining audio through OpenAI fallback without storing the OpenAI key in repository files.

### Risks and rollback
- Risk: OpenAI fallback files may have a different timbre/loudness profile from MiniMax files.
- Risk: final import scripts may require a different output directory or public object-key convention.
- Rollback: delete `content/generated_media/toeic/minimax_audio*` and rerun the generator with a different output directory/provider configuration.

## 2026-05-04 - TOEIC C2 package 02-10 staging and media generation

### Unknowns
- The final Cloudflare R2 object-key convention and production import mapping have not been pulled from production yet.
- The exact human editorial threshold for "CEFR C2 TOEIC-style" may require later review beyond deterministic structural validation.
- Provider quotas and runtime memory availability may change while generation is running.

### Reason for proceeding
- The user explicitly instructed autonomous generation through package 10 and said they would take over again at the production `git pull` phase.
- The local codebase already defines a 200-question TOEIC structure, so packages 02-10 were generated against that structure without waiting for production import details.

### Assumptions used
- Packages 02-10 should be staged under `content/generated/toeic_packages/package_XX`.
- Heavy generated media can be staged on the larger `D:` drive at `D:\toeic_generated_media` before the R2 upload/import phase.
- MiniMax-generated Part 1 images are acceptable as realistic TOEIC workplace photos when normalized to the package's expected `.png` filenames.
- OpenAI loud TTS fallback is acceptable for audio after MiniMax speech quota is reached, provided it uses clear US/British business English instructions.

### Project impact
- Added `docs/superpowers/plans/2026-05-04-toeic-c2-package-generation.md`.
- Added `scripts/generate_toeic_c2_packages.php`.
- Added `scripts/validate_toeic_c2_packages.php`.
- Updated `scripts/generate_toeic_audio_batch.ps1` with OpenAI timeout/retry settings.
- Added `scripts/generate_toeic_part1_images.ps1`.
- Generated packages 02-10 under `content/generated/toeic_packages`.
- Generated package media under `D:\toeic_generated_media`.

### Verification attempted
- Ran PHP syntax checks on the generated package scripts.
- Ran the TOEIC C2 package validator against all 9 generated packages.
- Parsed both PowerShell media generation scripts.
- Verified package 02-10 media counts: 486 expected audio files, 486 actual MP3 files; 54 expected image files, 54 actual PNG files.
- Verified MP3/PNG headers and non-trivial file sizes for all generated package media.
- Scanned checked project files for pasted OpenAI project-key tokens and found none.

### Risks and rollback
- Risk: production import code may require a different local media root or R2 key naming convention.
- Risk: deterministic generation may still need a human editorial pass for exact C2 nuance, distractor quality, and audio-photo alignment.
- Rollback: delete `content/generated/toeic_packages/package_02` through `package_10`, remove `D:\toeic_generated_media\package_02` through `package_10`, and rerun generators after production import conventions are known.

## 2026-05-04 - Cloudflare R2 upload key layout preparation

### Unknowns
- The Cloudflare R2 bucket name is not present in the repository, local `.env`, Wrangler config, or current shell environment.
- Wrangler is installed but not authenticated in this Windows session.
- The final production import script may choose a different public URL/key layout after production is pulled.

### Reason for proceeding
- The user requested upload to Cloudflare R2 immediately after package generation.
- Upload preparation is local, reversible, and lets the actual upload start as soon as Cloudflare auth and bucket name are available.

### Assumptions used
- Package 02-10 media should use package-scoped R2 keys to avoid filename collisions across packages.
- Audio keys should use `toeic/audio/package_XX/<filename>`.
- Photo keys should use `toeic/photos/package_XX/<filename>`.
- The public base URL remains `https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev` unless production config says otherwise.

### Project impact
- Added `scripts/upload_toeic_r2_media.ps1`.
- Created a dry-run upload manifest at `D:\toeic_generated_media\r2_upload_manifest.dryrun.json`.
- Created a dry-run log at `D:\toeic_generated_media\r2_upload_dryrun.log`.

### Verification attempted
- Checked `wrangler --version`: Wrangler 4.87.0 is available.
- Checked `wrangler whoami`: current session is not authenticated.
- Ran `scripts/upload_toeic_r2_media.ps1` in dry-run mode: 540 media objects were mapped with 0 failures.
- Parsed `scripts/upload_toeic_r2_media.ps1` successfully.

### Risks and rollback
- Risk: package-scoped keys may need to be adjusted to match a production import convention.
- Rollback: rerun the uploader with different `-AudioPrefix`/`-PhotoPrefix`, or regenerate the manifest after production import paths are known.

## 2026-05-04 - TOEIC C2 R2 upload and browser import script

### Unknowns
- Production has not been pulled yet, so the production database state and exact admin deployment flow are not available locally.
- The generated MP3 files already contain their spoken scripts; the browser importer can repair text/transcript values stored in the database, but it cannot change audio that has already been rendered.
- The local database service was not running during verification, so a full DB dry-run import could not be executed locally.

### Reason for proceeding
- The user explicitly requested upload to the `toeic-assets` R2 bucket and a browser-open import script after upload.
- Cloudflare API verified the bucket and managed public domain before upload, and public HEAD checks verified uploaded sample media.
- The import script can be verified for syntax and package quality gates without requiring a live local database.

### Assumptions used
- The package-scoped R2 key layout is the final import layout: `toeic/audio/package_XX/<filename>` and `toeic/photos/package_XX/<filename>`.
- The verified public base URL for `toeic-assets` is `https://pub-63f89fd288834fdf9eca1c875f0dfca9.r2.dev`.
- Package-aware question numbering can use 100-question blocks per section package to avoid collisions with existing package-1 numbering.

### Project impact
- Updated `scripts/upload_toeic_r2_media.ps1` with Wrangler config support and per-object retries.
- Uploaded package 02-10 media to Cloudflare R2 under the package-scoped key layout.
- Added `includes/toeic_c2_package_importer.php`.
- Added `admin/import_toeic_c2_packages.php`.
- Added `docs/superpowers/plans/2026-05-04-toeic-c2-r2-import.md`.

### Verification attempted
- Cloudflare API bucket check returned `toeic-assets` and confirmed the managed `r2.dev` domain is enabled.
- Public HEAD checks returned 200 for representative package 02 audio, package 03 audio, package 03 photo, and package 10 photo.
- Ran PHP syntax checks on the new importer and admin page.
- Ran `scripts/validate_toeic_c2_packages.php` successfully for all 9 generated packages.
- Ran the importer quality loader without DB: all 9 packages passed, with deterministic text repairs applied and zero quality errors.

### Risks and rollback
- Risk: audio files may still contain pre-import generated phrasing/date defects because text repair occurs at DB import time only.
- Risk: production may already contain rows whose `part`/`nomor_soal` values collide with the package-aware numbering blocks.
- Rollback: delete rows imported by the browser script using the package-specific media URL prefixes and question number ranges, then rerun the importer after regenerating or manually editing the package content/audio.

## 2026-05-08 - TOEIC frontend quality review fixes

### Unknowns
- The live production database state and the exact student/admin sessions from the May 6 review were not available locally.
- The specific corrupt Part 1 photo row from the user's screenshot could not be queried because local table verification could not connect to MySQL.
- The payment gateway credentials are environment-dependent, so the full payment provider callback flow was not executable locally.

### Reason for proceeding
- The user explicitly requested autonomous completion without questions.
- The reported issues had clear local code paths and the fixes are reversible source changes.

### Assumptions used
- Practice sessions are identified by `toeic_test_sessions.practice_mode = 1` and should never create, update, or obey proctoring termination.
- Voucher codes generated by this product use the `OSGLI-` prefix and should accept typed variants with different case, whitespace, missing hyphen, or Unicode dash characters.
- Part 1 broken photos may be stale extension paths, so JPG/JPEG/PNG/WEBP sibling candidates are safe fallbacks before showing a user-facing placeholder.

### Project impact
- Added shared TOEIC quality helpers for voucher normalization, null-safe score display, flash redirects, session summary lookup, and robust logout.
- Updated practice/proctoring guards across `user/test_toeic.php`, `user/camera_setup.php`, `user/disqualified.php`, and `api/ajax_proctor.php`.
- Updated profile stats, voucher redemption, logout, registration feedback, prerequisite redirects, payment offline messaging, test navigation layout, and Part 1 photo fallback UI.
- Added focused helper regression coverage in `scripts/test_toeic_quality_helpers.php`.

### Verification attempted
- Ran the helper regression script successfully.
- Ran PHP syntax checks on every changed PHP file.
- Ran practice credit and proctor snapshot regression scripts successfully.
- Ran local browser smoke checks for `/`, `/login.php`, and `/register.php` with bundled Playwright and confirmed no PHP diagnostics were exposed.
- Attempted `scripts/verify_tables.php`; local MySQL refused the connection.

### Risks and rollback
- Risk: production may still contain specific bad photo records that should be repaired in the database even though the UI now tries sibling extensions and shows a clean placeholder.
- Risk: user-authenticated flows could not be fully browser-tested locally because the database service was unavailable.
- Rollback: revert the source changes in the listed files, remove `includes/toeic_quality_helpers.php` and `scripts/test_toeic_quality_helpers.php`, then rerun syntax checks.
