# HALLUCINATION.md

## 2026-05-05 - Global agent instruction filename fallback

### Unknowns
- The user asked to read `C:\Users\stefa\.codex\agent.md`, but that exact lowercase file does not exist in the current filesystem.
- It is unknown whether the missing file was meant to be created elsewhere or whether the existing `C:\Users\stefa\.codex\AGENTS.md` is the intended global instruction file.

### Reason for proceeding
- The user explicitly requested a rebuild of the static visual preview, and the available global `AGENTS.md` contains the relevant frontend, Superpowers, verification, and output contract instructions.

### Assumptions used
- `C:\Users\stefa\.codex\AGENTS.md` is the intended global agent instruction file for this task.
- The rebuild should prioritize the new global frontend rules, especially avoiding card-heavy poster layouts and making operational TOEIC pages dense and scannable.

### Project impact
- Rebuilt `docs/previews/public-visual-pages.html` according to the available global `AGENTS.md` plus the requested high-end visual design skill.

### Verification attempted
- Checked `C:\Users\stefa\.codex\agent.md` directly and confirmed it was missing.
- Listed `C:\Users\stefa\.codex` and found `C:\Users\stefa\.codex\AGENTS.md`.
- Read `C:\Users\stefa\.codex\AGENTS.md` before editing the preview file.

### Risks and rollback
- Risk: if a different lowercase `agent.md` was intended, the rebuild may miss instructions that were not available in this workspace.
- Rollback: provide the intended `agent.md`, then revise or revert `docs/previews/public-visual-pages.html` against those instructions.

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

## 2026-05-04 - TOEIC frontend visual upgrade without local DB session

### Unknowns
- The exact authenticated dashboard/payment/proctoring visual result could not be rendered locally because the local database connection was refused.
- The user requested a better frontend without a single approved mockup for the full student app surface.

### Reason for proceeding
- The requested edits are local, reversible frontend changes and the user explicitly asked for autonomous completion without more questions.
- Public pages could be verified in Browser, and auth-gated PHP files could still be syntax-checked and statically verified.

### Assumptions used
- A shared `assets/css/toeic-redesign.css` layer is acceptable for public and student pages as long as it is not loaded in `admin/`.
- `checkout-va.php` and `logout.php` should remain action/redirect surfaces rather than new visual pages.

### Project impact
- Added the shared visual layer to public/student TOEIC pages.
- Reordered login/register columns so the form appears before the promotional panel on narrow screens.

### Verification attempted
- Ran PHP lint on every PHP page that received the redesign stylesheet link.
- Verified each requested visual page links `toeic-redesign.css` exactly once and no admin PHP page links it.
- Browser-smoked homepage, login, and register locally at `http://127.0.0.1:8000/`.
- Attempted `scripts/verify_tables.php`, but local DB connection was unavailable.

### Risks and rollback
- Risk: authenticated pages may need additional visual tuning once a real local session and DB are available.
- Rollback: remove the `toeic-redesign.css` link from the target pages and delete or revise `assets/css/toeic-redesign.css`.

## 2026-05-04 - TOEIC full static visual review board

### Unknowns
- The authenticated student, payment, proctoring, and test-runner pages still cannot be rendered from live local data because the local database/session context is unavailable.

### Reason for proceeding
- The user asked for a DB-free HTML review surface after being unable to access the PHP pages locally.
- A static visual board lets the user review every requested page family without calling production-like endpoints.

### Assumptions used
- Static mock TOEIC data is acceptable for visual review as long as the HTML does not execute PHP, session, database, Tripay, camera, audio, or proctoring behavior.
- `docs/previews/public-visual-pages.html` can remain the review URL even though it now includes public, student, payment, test, learning, and action-state previews.

### Project impact
- Expanded `docs/previews/public-visual-pages.html` into a full no-DB visual review board for all requested frontend pages and action states.
- Linked `assets/css/toeic-redesign.css` into `docs/previews/toeic-estudyme-home-static.html` so the embedded homepage preview uses the same visual layer.

### Verification attempted
- Served `docs/previews/public-visual-pages.html` locally with HTTP 200.
- Verified all 18 expected preview anchors exist exactly once in Browser.
- Verified Browser console errors were 0 for the static review page.
- Checked the static board for PHP/session/database/AJAX/camera/proctoring code patterns.

### Risks and rollback
- Risk: static mock content may differ from live PHP data density once authenticated pages are accessible.
- Rollback: remove the full-board sections from `docs/previews/public-visual-pages.html` or replace individual sections with screenshots/live-rendered snippets after DB access is restored.

## 2026-05-04 - TOEIC non-landing imagegen-style static revision

### Unknowns
- The user explicitly required `$imagegen-frontend-web`; no separate raster reference files were persisted in the repository for each non-landing page.
- The authenticated production PHP pages still cannot be rendered locally from live DB data in this session.

### Reason for proceeding
- The user requested immediate revision without more questions.
- The available deliverable that the user can review despite DB issues is the static no-DB preview board.

### Assumptions used
- Applying the `$imagegen-frontend-web` design rules directly as an art-direction system for the static HTML review board is acceptable for this revision.
- Legacy static mock sections can remain in the file as hidden `legacy-*` anchors while the visible canonical anchors point to the revised imagegen-style sections.

### Project impact
- Revised `docs/previews/public-visual-pages.html` with a new `.imagegen-comp` visual system for all non-landing pages.
- Added a hash-scroll stabilizer so direct anchors such as `#dashboard`, `#payment`, and `#actions` land on visible revised sections after fonts/layout settle.

### Verification attempted
- Served `docs/previews/public-visual-pages.html` locally with HTTP 200.
- Verified all 18 canonical preview anchors exist exactly once.
- Verified Browser console errors were 0 after opening revised anchors.
- Browser-smoked `#dashboard`, `#payment`, `#test-toeic`, `#pathway`, and `#actions` and confirmed the revised sections render.
- Checked the static board for PHP/session/database/AJAX/camera code patterns.

### Risks and rollback
- Risk: if the required interpretation was separate persisted raster image references per page, this revision still needs an additional reference-image artifact step.
- Rollback: remove the inserted `.imagegen-comp` sections and restore the visible anchors to the previous static mock sections.
