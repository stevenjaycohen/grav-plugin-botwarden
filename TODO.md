# botWarden — local live-install test TODO

**Status:** pre-release / manual verification required  
**Target branch:** `develop/botwarden-bootstrap` (draft PR [#1](https://github.com/stevenjaycohen/grav-plugin-botwarden/pull/1))  
**Scope:** Grav 2 + Grav API + Admin2, PHP 8.3/8.4. This is a **test plan**, not a claim that the items below already pass.

Check items only after observing the expected result. Record unexpected output in the Failure Log below. Do **not** merge, tag a release, or submit to GPM until the release gates at the end are satisfied.

## 0. Safe test boundaries

- [ ] Use a local/staging Grav installation with a backup or disposable clone. Do not test the new request blocker directly on a production site.
- [ ] Record local URL, Grav/PHP/API/Admin2 versions, web server, CLI PHP version, and the botWarden commit SHA.
- [ ] Back up `user/config/`, `user/data/`, and the actual served `robots.txt` (if present) **outside** the directories being tested. Check backup readability before proceeding.
- [ ] Preserve any existing GRVHulk installation, SQLite database (including WAL/shm files), configuration, and working cron jobs.
- [ ] Do not run the GRVHulk migration test on your only copy of live data. Use a disposable site clone with realistic data.
- [ ] Keep **one** request blocker active at a time. After migration, botWarden does not automatically disable GRVHulk.
- [ ] Keep `robots_txt.enabled: false` and all external/reporting integrations off until their individual tests.
- [ ] Do not paste API keys, session cookies, user account files, or unredacted logs into issues or the PR.
- [ ] Verify that you have shell access and a recovery path before testing block rules.

> **Important:** The bundled `botwarden.yaml` currently contains `enabled: true` even though the blueprint defaults to disabled. Check the *effective* configuration before loading the site. On a fresh test install, create a local disabled override; on a **migration test**, do not pre-create `user/config/plugins/botwarden.yaml` because the importer intentionally refuses to overwrite it.

## 1. Install the development branch on a clean local test site

Run the following from the **Grav root** (the directory containing `bin/grav`):

`~bash
test -f bin/grav || { echo 'Not in the Grav root'; exit 1; }
test ! -e user/plugins/botwarden || { echo 'botwarden already exists; inspect it first'; exit 1; }
git clone --single-branch --branch develop/botwarden-bootstrap \
  https://github.com/stevenjaycohen/grav-plugin-botwarden.git \
  user/plugins/botwarden

git -C user/plugins/botwarden branch --show-current
git -C user/plugins/botwarden rev-parse --short HEAD
php -v
php -m | grep -Ei '^(curl|sqlite3)$'
php bin/grav --version
`~

- [ ] Installed in exactly `user/plugins/botwarden/`, **not** `user/plugins/grvhulk/`.
- [ ] `git -C user/plugins/botwarden status --short` is empty after checkout.
- [ ] CLI PHP has `curl` and `sqlite3`; the web-server PHP SAPI also has them.
- [ ] Installed Grav 2, API, and Admin2 meet the requirements in `blueprints.yaml`. If the dependency/compatibility metadata disagree, record that as a release bug.
- [ ] For a **fresh test site only** (not a migration scenario), set `enabled: false` in `user/config/plugins/botwarden.yaml`. Ensure `robots_txt.enabled: false` and no API keys are enabled initially.
- [ ] Run `php bin/grav clear` and verify the website and Admin2 still load with botWarden disabled.
- [ ] Confirm no new botWarden scheduler job or database is unexpectedly running/created while disabled.

### Developer checks (optional, from the plugin directory)

`~bash
cd user/plugins/botwarden
composer install --prefer-dist
composer validate --no-check-publish
composer test
node --check admin-next/pages/botwarden.js
cd ../../..
`~

- [ ] Composer validation passes.
- [ ] PHPUnit passes without warnings/fatals that are being ignored.
- [ ] JavaScript syntax check passes (if Node.js is installed).
- [ ] GitHub CI is green at the **same** commit being tested; record any local-vs-CI difference.

Composer is for development/testing here. Runtime HTTP calls should use PHP cURL without requiring a local `vendor/` directory; test that explicitly on a disposable installation as well.

## 2. First enablement, Grav and Admin2

**Fresh-install test only:** enable botWarden locally with a config override (leave all remote sources and URI filters off). Clear Grav's cache afterward.

- [ ] Frontend returns its normal page content (no HTTP 500, blank page, or PHP fatal).
- [ ] Admin2 login still succeeds and logout still works.
- [ ] botWarden appears in the Admin2 sidebar / plugin page, under the configured Admin2 base URL.
- [ ] Opening the botWarden page loads the JS component, statistics, local blocks, block history, and migration detection.
- [ ] Browser developer tools: **no** 404 for `admin-next/pages/botwarden.js`, missing custom-element errors, undefined API client/dialog errors, or unhandled promise rejections.
- [ ] Inspect the Network tab for response shapes: the API returns a `{"data": ...}` envelope. The page must consume it correctly rather than treating the envelope as flat JSON.
- [ ] The plugin settings page renders and saves toggles without modifying unrelated settings or revealing API keys.
- [ ] Refresh and revisit Admin2: saved preferences persist; no duplicate sidebar entries appear.

**Known gap to investigate if it occurs:** the current Admin2 component uses `window.__GRAV_API` and `window.__GRAV_DIALOGS`. Confirm these are actually supplied by your installed Admin2 build. A passing PHP/JS syntax check does not prove the UI is wired correctly.

## 3. API authorization and data operations

Start with requests **without** credentials. Replace `http://localhost:8000` below with your actual local test URL. Do not use `curl -L` when inspecting status codes.

`~bash
BASE='http://localhost:8000'
curl -sS -i "$BASE/api/v1/ping"
curl -sS -i "$BASE/api/v1/botwarden/status"
curl -sS -i "$BASE/api/v1/botwarden/legacy"
curl -sS -i -X POST "$BASE/api/v1/botwarden/migrate" \
  -H 'Content-Type: application/json' -d '{"confirm":true}'
`~

- [ ] Public ping returns HTTP 200; anonymous botWarden endpoints return 401/403.
- [ ] Anonymous POSTs **cannot** migrate, add blocks, or remove blocks.
- [ ] In Admin2, an authenticated user without super permissions cannot use the botWarden management endpoints.
- [ ] An authenticated Admin2 superuser can read status, legacy preview, blacklist and history without 500s.
- [ ] Add a controlled **TEST-NET** address (example `198.51.100.7`) through the UI; it appears once with the correct manual reason and a history entry.
- [ ] Add it again and inspect whether deduplication and history behave as intended.
- [ ] Remove it through the UI; the local row disappears and an unblock history event is present.
- [ ] Invalid IP input (and empty input) is rejected with a useful validation message, not a fatal error.
- [ ] Pagination works beyond 50 rows using test records only; page totals and Previous/Next state are correct.
- [ ] Verify the server does not accept a forged `X-Forwarded-For` header as a trustworthy client IP unless the **direct** connecting proxy is explicitly trusted.

## 4. Legacy GRVHulk migration — isolated clone only

**Use a separate disposable copy** containing a real legacy GRVHulk config and database. The new importer refuses to start if `user/config/plugins/botwarden.yaml` or `user/data/botwarden/botwarden.sqlite` already exists.

- [ ] Note GRVHulk version (1.0.x or 1.1.x), enabled sources, active scheduler jobs, and old SQLite `PRAGMA user_version`.
- [ ] Verify the old site still works **before** importing; capture counts for `local`, `abuseipdb_bulk`, `history` (if available), `report_queue` and `dark_visitors_agents`.
- [ ] Capture checksums/copies of original `grvhulk.yaml` and `grvhulk.sqlite`. For a live WAL database, use a SQLite backup rather than treating an uncheckpointed single-file copy as a consistent snapshot.
- [ ] With only `user/plugins/grvhulk/` present, the detector reports a legacy installation.
- [ ] With the old plugin removed but its config/data still present (a different test copy), detection still works.
- [ ] Preview mode makes **no filesystem changes**:

`~bash
php user/plugins/botwarden/cli/migrate.php
`~

- [ ] Compare preview flags with the actual legacy files and readability.
- [ ] Confirm Admin2 shows an import offer **only** when the legacy data is migratable and the destination is free.
- [ ] Confirm the explicit confirmation prompt clearly states that GRVHulk is not disabled or deleted.
- [ ] Perform one deliberate import on the disposable clone, **either** through Admin2 or CLI:

`~bash
php user/plugins/botwarden/cli/migrate.php --confirm
`~

- [ ] A backup exists under `user/data/botwarden/backups/`; the original GRVHulk files are unchanged.
- [ ] The imported `user/config/plugins/botwarden.yaml` has `enabled: false` regardless of the old setting.
- [ ] SQLite destination passes `PRAGMA integrity_check` and the imported blacklist/history/report/agent counts match expectations (allowing only documented schema migrations).
- [ ] `user/data/botwarden/migration-complete.json` exists and identifies this import.
- [ ] Repeating the migration **refuses to overwrite** the destination and preserves both databases.
- [ ] If possible, simulate a failed migration on another throwaway clone (unreadable DB, damaged SQLite, or occupied destination): no partial botWarden config or database remains and error details are observable.
- [ ] Before enabling botWarden, disable GRVHulk in the cloned site and verify its old scheduled jobs are not competing.
- [ ] Then enable botWarden and verify the local entries, settings, active sources, and custom URI filters survive. Check for paths or class names still referring to GRVHulk.
- [ ] Test both a config-only import and a database-only import. A database-only import must still create a **disabled** botWarden config.
- [ ] Keep an actual rollback procedure: disable botWarden, restore the test-site backup, and restart the old GRVHulk configuration. Verify that reverting does not require deleting the original database.

## 5. Security and request interception

Do these **only** on the disposable test site, with a known recovery path.

- [ ] Confirm loopback/private/reserved addresses are **not evaluated by the blacklist**: `curl` from `127.0.0.1` will not prove IP/UA blocking works.
- [ ] Use a controlled public-source test connection or an accurately configured trusted reverse proxy when validating actual blocking (never treat a freely spoofed header as proof).
- [ ] Verify a whitelisted public test IP/CIDR bypasses local blacklist and external sources.
- [ ] Verify manual local blocks return the configured status (default 403) for a public test client; the same client is allowed again after unblocking.
- [ ] Verify trusted-proxy handling for direct, trusted-proxy, untrusted-proxy and IPv6 cases.
- [ ] Enable URI filtering on a **test host** and probe benign test paths matching configured patterns (for example `/.env`). Confirm an entry is added, history updated and the correct status returned.
- [ ] Confirm ordinary page paths continue to work; malformed/expensive custom regex patterns do not hang workers or blank the site.
- [ ] Test failure behavior if SQLite becomes unavailable or briefly locked. Capture whether requests fail open as intended and whether the error is actually logged.
- [ ] Test the emergency unblock CLI on the test database, first listing entries, then removing one:

`~bash
php user/plugins/botwarden/cli/unblock.php --list
php user/plugins/botwarden/cli/unblock.php 198.51.100.7
`~

- [ ] Test `--whitelist` on a throwaway config and verify it writes to `user/config/plugins/botwarden.yaml` in valid YAML without changing plugin defaults. Check for duplicate or malformed whitelist entries.

## 6. External threat feeds and scheduler

Do **not** enable outbound AbuseIPDB reporting during initial tests. Use a dedicated test key/account and respect external API quotas; avoid reporting arbitrary TEST-NET addresses as abuse.

- [ ] Review default job list before configuring integrations:

`~bash
php bin/grav scheduler -j
php bin/grav scheduler -d
`~

- [ ] Verify `botwarden-prune-history` registers normally when the plugin is enabled, and legacy jobs are absent after disabling GRVHulk.
- [ ] Enable and schedule AbuseIPDB bulk refresh on a test site. Run the job through the scheduler interface; record HTTP status, downloaded count, duration, database count and last-success time.
- [ ] Simulate network failure/invalid authentication/malformed/empty response **without risking live keys**; verify previous saved list and its last-success timestamp are not replaced.
- [ ] Test CrowdSec CTI with known non-sensitive test cases: valid API response, 404/no intelligence, network failure, rate limit and local cache/quota behavior.
- [ ] If available, test CrowdSec LAPI with a disposable local agent and verify its decisions and cache expire correctly.
- [ ] Verify that enabling deferred AbuseIPDB reporting only queues configured URI-filter events; disable reporting before public traffic tests.
- [ ] Inspect the SQLite WAL, scheduler errors, and concurrent request latency during a bulk list replacement.
- [ ] Leave unused integrations **off**, with no extra outbound requests and no noisy errors.

## 7. Known Agents and robots.txt integration

The old GRVHulk and site-specific cron workflow may already write bot rules. On the **test copy**, inspect existing `robots.txt` before switching on botWarden's publisher. Keep `robots_txt.enabled: false` at first.

- [ ] Configure Known Agents API key and desired agent categories; validate that a scheduled fetch populates its User-Agent table.
- [ ] Confirm a failed, unauthorized, malformed or empty Known Agents response **does not erase** the last successful agent list.
- [ ] Test on-request User-Agent enforcement through a real public client (not loopback), with the corresponding source toggle enabled.
- [ ] On a disposable `robots.txt` with normal rules, enable publication and run the Known Agents update. Confirm the only new section is between `# botWarden:START` and `# botWarden:END`.
- [ ] If the existing file contains your separate `# KNOWN HOSTS START` / `# KNOWN HOSTS END` managed block, verify it is preserved byte-for-byte.
- [ ] Verify no duplicate botWarden block is appended on a second run; content and checksum are stable when the agent list is unchanged.
- [ ] Verify a timestamped backup is made before a change and not made for an unchanged run.
- [ ] Intentionally corrupt/remove one botWarden marker **on a throwaway file**; publishing should refuse to change the file and produce a visible error.
- [ ] Test file permissions and the actual **served** `$BASE/robots.txt` URL: the Grav root target may differ from the web server's document root.
- [ ] Do not leave a separate cron job and botWarden publishing duplicate or conflicting policies on the final site. Decide which owns the Known Agents section before deployment.
- [ ] Verify again that `robots.txt` is advisory; enforcement depends on active request checks, not the published policy.

## 8. Logs, diagnostics and regression

- [ ] Check `logs/grav.log` (or your configured Grav log path), PHP-FPM/web-server logs, CLI output and browser Console/Network.
- [ ] Confirm external failures produce actionable errors without logging API keys, cookies or private configuration.
- [ ] Confirm no new PHP deprecations/warnings, uncaught exceptions, HTTP 500s or silent scheduler failures on PHP 8.3 and the web-server PHP version.
- [ ] Re-run the core website, Admin2, backup routines and existing scheduler jobs after each integration is enabled.
- [ ] Check resource use: normal request latency, peak memory, SQLite lock duration, bulk import time, and log/database growth.
- [ ] Validate permissions: PHP web-server user can use the test database and (only when enabled) write `robots.txt`; secrets and backups are not publicly accessible.

## 9. Release gates (all must pass before GPM)

- [ ] Authenticated Admin2 dashboard actually renders and works, including settings, migration prompts and all write actions.
- [ ] API authorization and CSRF/session protections verified for authenticated and anonymous requests.
- [ ] Real Grav 2 request interception, scheduler jobs, feed failures and `robots.txt` publication tested.
- [ ] Migration preserves real GRVHulk 1.0/1.1 data, imports with botWarden disabled, and can be rolled back.
- [ ] Packaging tested as a clean **release ZIP / GPM-style installation**, not just a Git checkout with development dependencies.
- [ ] Composer/blueprint metadata, permission model, documentation, licenses, and dependency declarations reviewed against current Grav GPM rules.
- [ ] PHPUnit, JS check, PHP lint, Grav boot and HTTP tests pass on the final commit.
- [ ] Update `CHANGELOG.md` and `README.md` with confirmed behavior and limitations.
- [ ] Review PR #1, then merge only after the release criteria above are met. Tag/publish and submit to GPM as a separate action.

## Failure log / test notes

Use one entry per unexpected behavior. Include enough information to reproduce it, **but redact API keys, cookies, tokens and private IPs or URLs where appropriate**.

`~
Date/time:
Test environment (Grav / API / Admin2 / PHP / OS):
botWarden commit:
Checklist item:
Steps to reproduce:
Expected:
Actual (HTTP status, CLI output, screen behavior):
Relevant sanitized log lines:
Reproducible?:
Fixed in commit / follow-up issue:
`~

### Test session record

- Date: 
- Local site / clone label:
- Grav version:
- API plugin version:
- Admin2 plugin version:
- CLI PHP / web PHP:
- botWarden commit:
- PR / issue references:
- Last completed section:
- Open blockers:
