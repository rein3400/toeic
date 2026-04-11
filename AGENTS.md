# Agent Instructions

## Product Scope
- Treat this repository as a **TOEIC-only** product.
- Prefer `toeic_*` tables, routes, copy, and UI. Do not introduce new non-TOEIC surface area.
- User-facing scope is `index.php`, `login.php`, `register.php`, `checkout-va.php`, `user/`, `api/`, `includes/`, and `assets/`. Do not change `admin/` UI unless explicitly requested.

## Package Manager
- Use Composer: `composer install`

## Local Commands
- Dev server: `php -S 127.0.0.1:8000 -c php.ini`
- Syntax check one file: `C:\xampp\php\php.exe -l path\to\file.php`
- Data sanity check: `C:\xampp\php\php.exe scripts\verify_tables.php`
- Content import: `C:\xampp\php\php.exe scripts\run_content_import.php`

## File-Scoped Conventions
- Keep shared business logic in `includes/`; keep pages/endpoints thin.
- Procedural endpoints/pages stay lowercase snake_case.
- Reusable helpers/classes stay PascalCase.
- Use 4-space indentation and same-line opening braces in PHP.

## Frontend Rules
- Preserve a single TOEIC visual system across public and student pages.
- Prefer shared CSS in `assets/css/` over page-local duplication when changing global look-and-feel.
- Maintain desktop and mobile behavior together; every UI change should account for `user/css/mobile-responsive.css`.

## Verification
- Run `php -l` on every changed PHP file before finishing.
- For UI work, smoke test the touched flow and note if browser/manual verification was not run.
- Re-check payment, voucher, secure audio, and proctoring flows when touching related files.
- Do local validation first; do not treat work as production-ready until local DB, local flows, and local smoke tests pass.

## Safety
- Keep secrets in `.env` / environment variables only.
- Be careful with `includes/config.php`, payment callbacks, proctoring endpoints, and anything under `uploads/`.

## Commit Attribution
- AI commits must include a `Co-Authored-By` trailer.
