# botWarden — Codex + Grav MCP local integration TODO

**Status:** pre-release / Codex-executed verification required  
**Target branch:** `develop/botwarden-bootstrap` (draft PR [#1](https://github.com/stevenjaycohen/grav-plugin-botwarden/pull/1))  
**Executor:** Codex, working in this repository and connected to the **local** Grav installation through [getgrav/grav-mcp](https://github.com/getgrav/grav-mcp). **Scope:** Grav 2 + API + Admin2, PHP 8.3/8.4. This is an executable development/test backlog, not a list of already verified features.

**Codex owns this checklist:** inspect, execute, reproduce failures, implement fixes, add regression tests, rerun the affected checks, and record concrete evidence. Mark a checkbox complete **only** when its acceptance criteria pass on the identified local installation; leave untested/blocked items unchecked and document why. Do **not** merge, tag a release, or submit to GPM without the owner's separate instruction.

## Codex operating contract (read before executing any checklist)

1. **Discover the target, do not guess.** Use the connected Grav MCP server's actual available tools to read system info, installed packages, enabled plugins, configuration, scheduler state, logs, and discovered plugin capabilities. Identify the local site URL, resolved Grav root, site/environment name, installed API/Admin2 versions and botWarden commit. Verify the MCP server is pointing to this **local test instance**, not a production domain, before any write.
2. **Use Grav MCP first for live site interaction.** Prefer the discovered tools corresponding to `get_system_info`, `list_packages`, `get_config`, `get_scheduler`, `get_logs`, `discover_plugins`, `get_blueprint`, and, when needed, `refresh_plugin_tools`. Use `update_config`, `clear_cache`, `create_backup`, and `run_scheduler` only when the current test phase and safety checks authorize those changes. Tool names can differ by MCP client: inspect the installed tool catalog rather than guessing exact call names or arguments.
3. **Use the local Codex shell/repository tools for code and filesystem work**, such as Git, PHP lint, Composer/PHPUnit, SQLite fixture inspection, file permissions and reviewing logs that MCP does not expose. Prefer MCP for actions against the *running site*. For anonymous HTTP/authorization tests use separate unauthenticated HTTP requests; an authenticated MCP call cannot prove an anonymous caller is denied. Browser/UI interaction requires browser automation or a human-observed result, not inference from API success.
4. **Never invent success.** Classify each item as `PASS`, `FAIL`, `BLOCKED` or `NOT RUN` in the session log; only `PASS` earns `[x]`. Record the exact command/MCP operation, observed response/status/count, relevant sanitized log, build/commit SHA, and whether a fix was committed. If blocked by a missing tool, permission or browser access, explain the blocker and test all other independently testable items.
5. **Operate only on explicit test fixtures.** It is acceptable to create and destroy clearly isolated, disposable **local** test fixtures after verifying their paths. Require the owner's approval before migrating real existing GRVHulk data, editing the site's actual `robots.txt`, submitting an external AbuseIPDB report, changing production credentials, removing/updating unrelated packages, or changing live site configuration outside the test scope. Do not assume MCP write access implies consent. Never disable or remove GRVHulk on the real installation automatically.
6. **Diagnostics from the first run.** Preserve complete local test diagnostics: useful exception class/message/stack trace, HTTP status/body shape, scheduler result, DB integrity/check counts and browser console/network failure. Redact all secrets and sensitive site data before committing or posting. Do not silence warnings or treat a successful HTTP response as proof that its returned payload is correct.
7. **Tight feedback loop.** For each failure: reproduce → identify root cause in botWarden or its Grav integration → implement the smallest coherent fix → write a regression test → run that test and neighboring checks → retry against the local Grav MCP site → document the result and commit. Leave PR #1 a draft until all gates pass.

### MCP connection and exposure prerequisites

- [ ] Inspect Codex's already-configured `grav-mcp` connection. Grav's MCP server uses `GRAV_API_URL` pointing to the **API base** (for example `http://127.0.0.1:8000/api`, not `/api/v1`) and `GRAV_API_KEY` supplied through the MCP process environment. Do not print, commit, or place keys in shell history or issue comments. Follow [grav-mcp configuration](https://github.com/getgrav/grav-mcp#configuration), rather than inventing a Codex configuration file or launch command.
- [ ] Make a **read-only** MCP call and record the site's identity and versions; confirm the API key's actual privileges and scopes. Start with the least privilege that can perform the tests; request additional test-account privileges only when a specific operation needs them.
- [ ] Use core MCP tools to inspect configuration, logs, scheduler and package state. If MCP is unreachable, distinguish authentication failure, tool absence, wrong URL and server outage; don't silently switch to a different site.
- [ ] **Implement and validate `mcp.yaml` at the botWarden plugin root** as a development task. The current branch has custom `/botwarden/...` API routes but **no botWarden tool manifest**; the core Grav MCP tools cannot invoke these plugin-specific routes by name until they are published. Follow [Grav API's MCP tool manifest format](https://github.com/getgrav/grav-plugin-api#mcp-tool-manifests). Declare separate tools for status, legacy preview, block listing, history, add/remove block and migration; specify each route's real method, permission (`api.super` as enforced by the current controller), JSON input, and accurate `readOnly` / `destructive` annotations. Mark migration and access-control writes as non-read-only.
- [ ] Confirm the installed Grav API supports `GET /mcp/tools` (the grav-mcp documentation specifies API plugin **1.0.22+** for plugin manifests), and check any returned `warnings`. Call the discovered MCP `refresh_plugin_tools` function, then verify tools are advertised as `botwarden_*` and return the expected *data* for this user's permission level. **Do not** claim the plugin's MCP integration works merely because an API route responds.
- [ ] Re-check this tool manifest and dynamic discovery after changing botWarden API routes or permissions. An API controller and an MCP tool manifest must stay in sync.

### Execution order and reporting

Use the numbered phases below in order. Test a clean install separately from a cloned GRVHulk migration. On the actual local installation perform read-only discovery and safe test operations first; use disposable fixtures to test failures, migration and `robots.txt` writes. Codex should update this file with completed boxes plus a short session log, and report only verified results and outstanding blockers in PR #1. A green GitHub CI run verifies its own jobs, not an authenticated Admin2 page or live external services.

## 0. Safe test boundaries

- [ ] Codex identifies and confirms a local/staging Grav installation with a backup or disposable clone. Do not test the new request blocker directly on a production site.
- [ ] Codex records MCP target identity, local URL, Grav/PHP/API/Admin2 versions, web server, CLI PHP version, and the botWarden commit SHA before writes.
- [ ] Back up `user/config/`, `user/data/`, and the actual served `robots.txt` (if present) **outside** the directories being tested. Check backup readability before proceeding.
- [ ] Preserve any existing GRVHulk installation, SQLite database (including WAL/shm files), configuration, and working cron jobs.
- [ ] Do not run the GRVHulk migration test on your only copy of live data. Use a disposable site clone with realistic data.
- [ ] Keep **one** request blocker active at a time. After migration, botWarden does not automatically disable GRVHulk.
- [ ] Keep `robots_txt.enabled: false` and all external/reporting integrations off until their individual tests.
- [ ] Do not paste API keys, session cookies, user account files, or unredacted logs into issues or the PR.
- [ ] Verify that you have shell access and a recovery path before testing block rules.

> **Important:** The bundled `botwarden.yaml` currently contains `enabled: true` even though the blueprint defaults to disabled. Check the *effective* configuration before loading the site. On a fresh test install, create a local disabled override; on a **migration test**, do not pre-create `user/config/plugins/botwarden.yaml` because the importer intentionally refuses to overwrite it.

## 1. Codex: install the development branch on a clean local test site

Codex first confirms the target directory and that it is disposable; then execute the following from the **Grav root** (the directory containing `bin/grav`):

```bash
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
```

- [ ] Installed in exactly `user/plugins/botwarden/`, **not** `user/plugins/grvhulk/`.
- [ ] `git -C user/plugins/botwarden status --short` is empty after checkout.
- [ ] CLI PHP has `curl` and `sqlite3`; the web-server PHP SAPI also has them.
- [ ] Installed Grav 2, API, and Admin2 meet the requirements in `blueprints.yaml`. If the dependency/compatibility metadata disagree, record that as a release bug.
- [ ] For a **fresh test site only** (not a migration scenario), set `enabled: false` in `user/config/plugins/botwarden.yaml`. Ensure `robots_txt.enabled: false` and no API keys are enabled initially.
- [ ] Run `php bin/grav clear` and verify the website and Admin2 still load with botWarden disabled.
- [ ] Confirm no new botWarden scheduler job or database is unexpectedly running/created while disabled.

### Codex: required developer checks (from the plugin directory)

```bash
cd user/plugins/botwarden
composer install --prefer-dist
composer validate --no-check-publish
composer test
node --check admin-next/pages/botwarden.js
cd ../../..
```

- [ ] Composer validation passes.
- [ ] PHPUnit passes without warnings/fatals that are being ignored.
- [ ] JavaScript syntax check passes (if Node.js is installed).
- [ ] GitHub CI is green at the **same** commit being tested; record any local-vs-CI difference.

Composer is for development/testing here. Runtime HTTP calls should use PHP cURL without requiring a local `vendor/` directory; test that explicitly on a disposable installation as well.

## 2. Codex: first enablement, Grav API and Admin2 UI

**Fresh-install fixture only:** Codex uses a Grav MCP configuration write where supported to enable botWarden with all remote sources and URI filters still off, then uses MCP to clear cache. Do not enable the plugin on the original GRVHulk site until migration is verified and GRVHulk is explicitly disabled.

- [ ] Frontend returns its normal page content (no HTTP 500, blank page, or PHP fatal).
- [ ] Admin2 login still succeeds and logout still works.
- [ ] botWarden appears in the Admin2 sidebar / plugin page, under the configured Admin2 base URL.
- [ ] Opening the botWarden page loads the JS component, statistics, local blocks, block history, and migration detection.
- [ ] Browser developer tools: **no** 404 for `admin-next/pages/botwarden.js`, missing custom-element errors, undefined API client/dialog errors, or unhandled promise rejections.
- [ ] Inspect the Network tab for response shapes: the API returns a `{"data": ...}` envelope. The page must consume it correctly rather than treating the envelope as flat JSON.
- [ ] The plugin settings page renders and saves toggles without modifying unrelated settings or revealing API keys.
- [ ] Refresh and revisit Admin2: saved preferences persist; no duplicate sidebar entries appear.

**Known gap to investigate if it occurs:** the current Admin2 component uses `window.__GRAV_API` and `window.__GRAV_DIALOGS`. Confirm these are actually supplied by your installed Admin2 build. A passing PHP/JS syntax check does not prove the UI is wired correctly.

## 3. Codex: API authorization and data operations

Use **Grav MCP** for authorized calls, and separate requests **without** credentials to test authorization failures. Replace `http://localhost:8000` below with your actual local test URL. Do not use `curl -L` when inspecting status codes.

```bash
BASE='http://localhost:8000'
curl -sS -i "$BASE/api/v1/ping"
curl -sS -i "$BASE/api/v1/botwarden/status"
curl -sS -i "$BASE/api/v1/botwarden/legacy"
curl -sS -i -X POST "$BASE/api/v1/botwarden/migrate" \
  -H 'Content-Type: application/json' -d '{"confirm":true}'
```

- [ ] Public ping returns HTTP 200; anonymous botWarden endpoints return 401/403.
- [ ] Anonymous POSTs **cannot** migrate, add blocks, or remove blocks.
- [ ] Test with a **dedicated non-super test user** using permitted auth methods (MCP key or session). Verify its permissions and confirm it cannot use protected botWarden management endpoints.
- [ ] An authorized Admin2 superuser, via discovered botWarden MCP tools once `mcp.yaml` is exposed, can read status, legacy preview, blacklist and history without 500s, malformed response envelopes or unexpected writes.
- [ ] Add a controlled **TEST-NET** address (example `198.51.100.7`) through the UI; it appears once with the correct manual reason and a history entry.
- [ ] Add it again and inspect whether deduplication and history behave as intended.
- [ ] Remove it through the UI; the local row disappears and an unblock history event is present.
- [ ] Invalid IP input (and empty input) is rejected with a useful validation message, not a fatal error.
- [ ] Pagination works beyond 50 rows using test records only; page totals and Previous/Next state are correct.
- [ ] Verify the server does not accept a forged `X-Forwarded-For` header as a trustworthy client IP unless the **direct** connecting proxy is explicitly trusted.

## 4. Codex: legacy GRVHulk migration — isolated clone only

**Codex must use a separate disposable copy** containing representative synthetic data or an explicitly approved clone of real legacy GRVHulk config and database. The new importer refuses to start if `user/config/plugins/botwarden.yaml` or `user/data/botwarden/botwarden.sqlite` already exists.

- [ ] Note GRVHulk version (1.0.x or 1.1.x), enabled sources, active scheduler jobs, and old SQLite `PRAGMA user_version`.
- [ ] Verify the old site still works **before** importing; capture counts for `local`, `abuseipdb_bulk`, `history` (if available), `report_queue` and `dark_visitors_agents`.
- [ ] Capture checksums/copies of original `grvhulk.yaml` and `grvhulk.sqlite`. For a live WAL database, use a SQLite backup rather than treating an uncheckpointed single-file copy as a consistent snapshot.
- [ ] With only `user/plugins/grvhulk/` present, the detector reports a legacy installation.
- [ ] With the old plugin removed but its config/data still present (a different test copy), detection still works.
- [ ] Preview mode makes **no filesystem changes**:

```bash
php user/plugins/botwarden/cli/migrate.php
```

- [ ] Compare preview flags with the actual legacy files and readability.
- [ ] Confirm Admin2 shows an import offer **only** when the legacy data is migratable and the destination is free.
- [ ] Confirm the explicit confirmation prompt clearly states that GRVHulk is not disabled or deleted.
- [ ] After backup validation and confirmation that the target is disposable, perform one deliberate import, **preferably via the discovered botWarden migration MCP tool**. Use Admin2 or the CLI as a separate fallback/coverage test (the CLI command is below):

```bash
php user/plugins/botwarden/cli/migrate.php --confirm
```

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

## 5. Codex: security and request interception

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

```bash
php user/plugins/botwarden/cli/unblock.php --list
php user/plugins/botwarden/cli/unblock.php 198.51.100.7
```

- [ ] Test `--whitelist` on a throwaway config and verify it writes to `user/config/plugins/botwarden.yaml` in valid YAML without changing plugin defaults. Check for duplicate or malformed whitelist entries.

## 6. Codex: external threat feeds and scheduler

Do **not** enable outbound AbuseIPDB reporting during initial tests. Use a dedicated test key/account and respect external API quotas; avoid reporting arbitrary TEST-NET addresses as abuse.

- [ ] Review default job list before configuring integrations:

```bash
php bin/grav scheduler -j
php bin/grav scheduler -d
```

- [ ] Verify `botwarden-prune-history` registers normally when the plugin is enabled, and legacy jobs are absent after disabling GRVHulk.
- [ ] Enable and schedule AbuseIPDB bulk refresh on a test site. Run the job through the scheduler interface; record HTTP status, downloaded count, duration, database count and last-success time.
- [ ] Simulate network failure/invalid authentication/malformed/empty response **without risking live keys**; verify previous saved list and its last-success timestamp are not replaced.
- [ ] Test CrowdSec CTI with known non-sensitive test cases: valid API response, 404/no intelligence, network failure, rate limit and local cache/quota behavior.
- [ ] If available, test CrowdSec LAPI with a disposable local agent and verify its decisions and cache expire correctly.
- [ ] Verify that enabling deferred AbuseIPDB reporting only queues configured URI-filter events; disable reporting before public traffic tests.
- [ ] Inspect the SQLite WAL, scheduler errors, and concurrent request latency during a bulk list replacement.
- [ ] Leave unused integrations **off**, with no extra outbound requests and no noisy errors.

## 7. Codex: Known Agents and robots.txt integration

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

## 8. Codex: logs, diagnostics and regression

- [ ] Check `logs/grav.log` (or your configured Grav log path), PHP-FPM/web-server logs, CLI output and browser Console/Network.
- [ ] Confirm external failures produce actionable errors without logging API keys, cookies or private configuration.
- [ ] Confirm no new PHP deprecations/warnings, uncaught exceptions, HTTP 500s or silent scheduler failures on PHP 8.3 and the web-server PHP version.
- [ ] Re-run the core website, Admin2, backup routines and existing scheduler jobs after each integration is enabled.
- [ ] Check resource use: normal request latency, peak memory, SQLite lock duration, bulk import time, and log/database growth.
- [ ] Validate permissions: PHP web-server user can use the test database and (only when enabled) write `robots.txt`; secrets and backups are not publicly accessible.

## 9. Codex: release gates (all must pass before GPM)

- [ ] Authenticated Admin2 dashboard actually renders and works, including settings, migration prompts and all write actions. Codex verifies using browser automation if available; otherwise mark this gate BLOCKED pending direct UI observation.
- [ ] API authorization, API-key scope limits, and CSRF/session protections verified across authenticated and anonymous requests; use a dedicated test account/key and report exact results.
- [ ] Real Grav 2 request interception, scheduler jobs, feed failures and `robots.txt` publication tested against a running local site, with MCP observations where applicable.
- [ ] Migration preserves real GRVHulk 1.0/1.1 data, imports with botWarden disabled, and can be rolled back.
- [ ] Packaging tested as a clean **release ZIP / GPM-style installation**, not just a Git checkout with development dependencies; Codex performs test installs only on disposable fixtures and does not publish the package.
- [ ] Composer/blueprint metadata, permission model, documentation, licenses, and dependency declarations reviewed against current Grav GPM rules.
- [ ] PHPUnit, JS check, PHP lint, Grav boot, HTTP and live-local MCP tests pass on the final commit; record exact versions and commands.
- [ ] Update `CHANGELOG.md` and `README.md` with confirmed behavior and limitations.
- [ ] Review PR #1, then merge only after the release criteria above are met. Tag/publish and submit to GPM as a separate action.

## Codex evidence and failure log

On every run, Codex must record the environment, MCP server/tools used, commit, results by checklist phase and newly identified release blockers. Use one entry per unexpected behavior. Include enough information to reproduce it, **but redact API keys, cookies, tokens and private IPs or URLs where appropriate**.

```
Date/time:
Test environment (Grav / API / Admin2 / PHP / OS):
botWarden commit:
Checklist item:
Steps to reproduce:
Expected:
Actual (HTTP status, CLI output, screen behavior):
Grav MCP tool (or local command) and arguments, redacted:
Relevant sanitized log lines:
Result: PASS / FAIL / BLOCKED / NOT RUN
Reproducible?:
Fix commit and local rerun evidence / follow-up issue:
```

### Test session record

- Date: 
- MCP target / local site / clone label:
- Grav version:
- API plugin version:
- Admin2 plugin version:
- CLI PHP / web PHP:
- botWarden commit:
- MCP server/tool discovery status:
- PR / issue references:
- Last completed section:
- Open blockers / approvals required:
