# Isolated TOEIC save-recovery browser contracts

Runs actual `user/test_toeic.php`, answer-save, section-submit, result and their
literal PHP helper dependencies against a new loopback-only MariaDB engine.
The source copy is an explicit module/static allowlist. The real
`includes/config.php`, `.env`, uploads, admin, vendor caches and developer
artifacts are never copied. Every copied source is byte-hashed and rechecked
against the candidate after the run.

## Boundary

The database/schema, authenticated PHP session row and configuration are
synthetic. Listening uses valid Part 2 A/B/C answers without audio, and Reading
uses Part 5. This verifies persistence/navigation/expiry/scoring integration,
not login, credit checkout, proctoring, production media, HTTPS cookies, WAF or
hosting availability. The conversion table is synthetic: raw scores 0–4 map
to `5 + 5 * raw`, solely to make precise grader assertions deterministic.

The production page needs its actual public Tailwind CDN script. Download the
exact script URL in `user/test_toeic.php` to a local cache before execution;
the harness serves those cached bytes through route interception, not a mock
`tailwind` global. Public fonts/other known optional CDN assets are blocked.
Unexpected external requests fail. Browser requests may access only the
selected HTTP loopback origin; no production site is contacted.

## Execute

Prerequisites: Node, Playwright + Chromium, PHP with mysqli/mysqlnd, MariaDB
server and initializer. Install development tools separately if needed.

```bash
PHP_BIN='C:/xampp/php/php.exe' \
MYSQLD_BIN='C:/xampp/mysql/bin/mysqld.exe' \
MYSQL_INSTALL_BIN='C:/xampp/mysql/bin/mysql_install_db.exe' \
TOEIC_E2E_TAILWIND_CACHE='C:/path/to/cached-tailwind.js' \
node scripts/test_toeic_save_recovery_e2e.cjs
```

Use normal `require('playwright')` resolution, or explicitly set
`PLAYWRIGHT_PKG` to an installed package directory for this execution only.
`PLAYWRIGHT_EXECUTABLE` may point to an installed Chromium executable.

Optional `TOEIC_E2E_DB_PORT` and `TOEIC_E2E_HTTP_PORT` default to 23471/23472.
Ports must be distinct integers, unused, and not 3306, 13361 or 18931.
`TOEIC_E2E_ARTIFACTS` must name a **new** directory. Default is a unique OS temp
directory. Datadir is always its fresh `mariadb-data` child; active datadirs
are never reused. A collision fails rather than stopping a foreign process.
MariaDB starts with `--no-defaults` first. The Windows initializer does not
support that switch; it uses an explicit new private datadir and never creates
a service. Every database connection checks exact port/datadir before writes.
Only a random `toeic_saverec_<8hex>` schema is accepted.

Native children and Chromium receive an explicit OS-only environment plus
synthetic fixture fields, not inherited account/provider/storage/MySQL keys.
The PHP helper rejects non-CLI execution with HTTP404 before database or file
operations. No test-facing route can initialize or mutate a database.

## Contracts

1. Actual poisoned-header CSRF rejection blocks radio + Next navigation.
2. Normal radio selection receives real ACK, persists and survives reload.
3. Last-question save rejection prevents grade and keeps URL/editing.
4. Short native deadline locks the DOM choice, waits for gated real ACKs,
   then grades only after final acknowledgements.
5. Delayed same-question responses never overlap; newest UI choice persists.
6. Failed manual finalization restores editing; healthy UI retry transitions.
7. Offline expiry keeps the frozen UI choice; reconnect drains it before grade.
8. Native Listening → Reading → result preserves selected answers and exact
   raw/scaled scores and `is_correct` fields, not merely a nonzero total.
9. HTML hosting-challenge stimulus surfaces in the actual Next UI path.
10. A genuinely hung request hits the application's unchanged 12-second
    deadline; expired controls stay locked and no grade occurs.
11. A genuinely applied submit with its response dropped is never replayed
    by manual function calls; original uncertain UI remains locked.
12. Previous uses actual save acknowledgement before leaving the question.
13. Next freezes controls until its final acknowledgement arrives.
14. An unconfirmed, not-applied submit stays locked across a same-tab reload
    with an expired timer; no automatic or manual replay occurs. A direct call
    to the actual late-photo callback also must not reopen the locked controls.
15. Expired Previous retries the frozen section and waits for actual grade ACK,
    never opening another Listening question.
16. Expired question-map navigation follows the same frozen section gate.

All assertions and unexpected-network checks precede setting a case PASS.
Any catch sets FAIL. An incomplete suite, fatal error, source drift or cleanup
failure makes the process nonzero. JSON, screenshots and server logs remain in
the artifact directory. `provenAgainstFinalCandidate` remains false until a
parent independently reviews and reruns the exact final/staged candidate.

Cleanup drops only the guarded owned schema, reads back its absence, and stops only process handles
spawned by this run. Both ports are rechecked afterward. The datadir, source
copy and evidence remain for diagnosis; no foreign service is killed.
