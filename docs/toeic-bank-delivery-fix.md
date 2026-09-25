# TOEIC bank-delivery fix: scope and verification

## Scope

Local Listening/Reading delivery correction. Production database changes and deployment are separate from source publication. The release candidate is assembled on clean Git HEAD, excluding unrelated local changes, credentials, and verification artifacts.

## Hosting release requirements

1. Back up the current code and database; stop new test starts during the release. Keep existing sessions/history intact.
2. Fetch the updated `main` branch. Apply `migrations/003_toeic_bank_delivery.sql` to the actual TOEIC database **before activating the new PHP code**. Fetch/read that SQL from `origin/main` while keeping the old checkout active, or download it for import in the hosting database panel. This additive, re-runnable migration adds snapshots, fallback accounting, required pool events, and lookup indexes; it does not delete questions or rewrite historical answers. It assumes the application's existing base tables already exist.
3. In the hosting application directory, use `git pull --ff-only origin main`. If it refuses because of local changes/divergence, stop rather than reset/force. Preserve hosting configuration and uploads. Reload the PHP worker/OPcache using the host's normal controls if required.
4. Verify a newly started test: exact question counts, credit consumed only after a complete build, decoded Part 1 photo, save/navigation, and every document in multi-passage Reading. On a failed photo, verify an explicit blocker instead of silent submission. An exhausted unique bank can still cause cross-session reuse.
5. Reopen test starts only after the hosting checks pass. If code rollback is needed, restore the previous code release; do not drop the additive columns or rewrite test history as part of rollback.

Required runtime: the existing PHP/mysqli application dependencies, InnoDB transactional tables, and working HTTPS/cURL/CA certificates for external photos. Remote-photo eligibility also requires the documented public DNS-over-HTTPS path; local photo fallbacks do not. Native MySQL8 and the current hosting/CDN state were not exercised by the local MariaDB tests.

The release also includes the shared snapshot-reader/scorer dependencies. The reader selects only each section's real stimulus column (`id_audio` for Listening, `id_teks` for Reading); `scripts/test_toeic_release_schema.php` exercises both section layouts, legacy fallback, snapshot scoring, repeat migration, and unchanged bank/history rows on the guarded isolated server.

The verification evidence below describes the **original local-fix run**, not a deployment or an assertion that every raw suite passes in the clean release candidate. `.workflow/` is private local evidence, intentionally not shipped. The publication handoff reports the separately executed release-candidate checks.

Implemented:
- Context-aware question identity across cloned row/media/article IDs and rotated options; conflicting answer texts and malformed keys are excluded.
- Complete Part 3/4/6/7 groups and exact full-test quotas (6/25/39/30/30/16/54). An insufficient eligible pool fails explicitly before assignment replacement.
- Content-aware history and truthful reuse accounting. A finite bank cannot provide unlimited unseen questions; fallback remains possible across sessions.
- Atomic credit/session creation and assignment replacement. Schema preflight cannot run inside a caller transaction. Transaction detection uses portable SAVEPOINT semantics rather than MariaDB-only `@@in_transaction`.
- Part1 photo eligibility checks and visible browser decode guards. Server-known missing photos cannot receive answers or silently score unanswered questions; browser-only image failures block submission in that tab until the photo loads again.
- Rendering and escaping of every nonempty reading document, with desktop/mobile verification.
- Corrected importer fingerprint variables and an additional intra-package logical-duplicate quality gate.
- Restored CSRF token in the normal start-instructions form.

## Verified evidence

Artifact root: `.workflow/toeic-bank-fix-20260923-1518/`.

- `final-audit.json`: exact production source hashes, preservation of unrelated tracked files, **47 PHP syntax checks**, no new regression versus the captured baseline.
- `regression-results.json`: **28 commands passed; 2 failed**. Six separate legacy integration suites were explicitly excluded; their names/reasons are recorded, not counted as passes.
- `legacy-baseline-replay.json`: both failing suites reproduce identical failures from **105 byte-verified original inputs**. One expects SVG attributes in existing SW JPEG assets; the other concerns unchanged progress components/CTA pages. Neither assertion was weakened or hidden.
- `integration-candidate.json`: real isolated mysqli builder verification, including 200 distinct assignments, complete groups, second-session avoidance, explicit fallback, trial/practice modes and rollback.
- `scripts/test_toeic_session_credit_atomicity.php`: actual failed-build rollback, no ghost/partial session, one-credit success, duplicate/stale-preview safety.
- `scripts/test_toeic_transaction_portability.php`: real MariaDB-backed mysqli plus a narrow trap rejecting MariaDB-only SQL; caller transaction/autocommit controls. **This is not a native MySQL8 server run.**
- `scripts/test_toeic_caller_transaction.php`: missing-schema negative control reproduces and fixes implicit DDL commit of caller work.
- `infra/browser-final-result.json`: **10 real Edge browser/API checks**, normal login and CSRF, persisted answer, invalid-CSRF rejection, server/browser photo failures, recovery, complete escaped triple passages at desktop/mobile sizes, and zero JavaScript page errors.
- `infra/final-*.png` and `infra/browser-final-trace.zip`: screenshots and trace. Passage visibility includes the last text line and hit-testing after scrolling, not just DOM text presence.
- `review/final-core.json`: independent review of the earlier frozen core. The demonstrated caller-DDL issue was subsequently fixed and regression-tested. `review/transaction-final.json` and `review/media-api-final.json` report no demonstrated blockers on the final deltas; their source hashes and real test exits were independently reconciled in `review/final-review-acceptance.json`.

Do not stage or upload `.workflow/`: it contains local databases, browser traces, baseline copies and a virtual environment. The code/test/document changes are separate from these verification artifacts.

## Operational boundaries

- Only new sessions receive the new selection plan; existing bank rows and historical assignments were not rewritten or deduplicated in place.
- Existing generated content still has limited variety. The C2 gate checks duplicates within an incoming package; the legacy cross-bank warning is not a global semantic uniqueness constraint.
- Remote-photo probing accepts HTTPS only, rejects userinfo and non-public/special-use IP ranges, rejects empty DNS, pins the connection, preserves TLS verification, disables redirects/proxy inheritance, and caps fetches at 3 seconds/8 MiB.
- Public IPv4 DNS resolution uses a pinned Cloudflare DNS-over-HTTPS request capped at 2 seconds. It is memoized per host/request. Resolver outages and IPv6-only names fail closed. Existing local fallbacks do not depend on DNS.
- Server checks validate image format/dimensions, not a full raster decode when GD is absent. The real browser must decode the image before enabling answers. SVG is not eligible. A successful health check cannot guarantee later availability on every user's connection.
- The browser-only failure list uses sessionStorage and is tab-local; it is a usability guard, not a server-side attestation that a client viewed an image. Exam timing/scoring policy was not redesigned.
- Native MySQL8 deployment and current production media/bank were not exercised. Portable transaction behavior was checked against the official MySQL8.4 `sql/transaction.cc` implementation in addition to the isolated tests.

## Local re-verification

Use the isolated server only: loopback `127.0.0.1:23306`, datadir recorded in `infra/setup.json`. Never point fixture tests at production or installed XAMPP data. Tests refuse a mismatched port/datadir and create fresh synthetic schemas.

With that server running, from the project root:

```text
C:/xampp/php/php.exe scripts/test_toeic_builder_integration.php
C:/xampp/php/php.exe scripts/test_toeic_session_credit_atomicity.php
C:/xampp/php/php.exe scripts/test_toeic_transaction_portability.php
C:/xampp/php/php.exe scripts/test_toeic_caller_transaction.php
C:/xampp/php/php.exe scripts/test_toeic_photo_fail_closed.php
python .workflow/toeic-bank-fix-20260923-1518/run_final_regressions.py
python .workflow/toeic-bank-fix-20260923-1518/replay_legacy_failures.py
python .workflow/toeic-bank-fix-20260923-1518/audit_final.py
```

The full regression runner intentionally retains a nonzero exit for the two unchanged legacy failures. The separate audit proves their provenance and rejects any new failure or source drift; it does not relabel them as passing.

Browser fixtures are CLI-only, guard the isolated datadir, create synthetic credits, and use normal application authentication. Their PHP server uses port18080. Installed Edge and the existing authorized Python interpreter were used after bundled browser/venv executables could not launch; no OS security policy or application authentication guard was weakened.

Before production release: inspect deployed schema/snapshot migrations, test the actual server/runtime and CDN paths, back up relevant data, and obtain deployment authorization. Do not run bulk dedup/import scripts merely because this code fix passed locally.
