# botWarden — Codex + Grav MCP local integration TODO

**Status:** feature audit of released 1.2.4 (`e69ca4a`); prioritized future work below; historical validation records retained

**Target branch:** `develop/botwarden-bootstrap`; releases merge to `main`
**Executor:** Codex, working in this repository and connected to the **local** Grav installation through its existing [MCP Server plugin](https://github.com/sandymac/grav-plugin-mcp-server). **Scope:** Grav 2 + API + Admin2, PHP 8.3/8.4. This is an executable development/test backlog, not a list of already verified features.

**Codex owns this checklist:** inspect, execute, reproduce failures, implement fixes, add regression tests, rerun the affected checks, and record concrete evidence. Mark a checkbox complete **only** when its acceptance criteria pass on the identified local installation; leave untested/blocked items unchecked and document why. Do **not** merge, tag a release, or submit to GPM without the owner's separate instruction.

## Feature audit and future work — 2026-11-01

**Baseline:** released botWarden **1.2.4**, commit `e69ca4a`. Reviewed the request hook, all plugin classes, settings/defaults, Admin2 component, API/MCP definitions, both CLI utilities, tests and release workflow. This audit changes only this TODO file. It does not authorize implementation or deployment of the tasks below.

**Evidence:** repository inspection plus read-only PHP probes using pure functions and SQLite `:memory:` databases. No live site, provider account, external research service, other repository or canonical preview was accessed. No persistent fixtures were created. Existing suite/CI results below are historical; the full test suite was not rerun for this documentation audit.

`VALID` means the stated gap is demonstrated by code or a probe. `NEEDS USER DECISION` identifies an existing behavior or optional extension requiring a product/policy choice. `UNVERIFIED` identifies a question needing a focused reproduction or authoritative documentation before a fix. Priorities below are proposed work order, not vulnerability severity ratings. An unchecked historical test is not, by itself, evidence of a broken feature.

### Feature inventory

“Implemented” here means an implementation path and relevant tests were found; it does not certify every deployment or provider account.

| Feature | Present implementation | Remaining work |
| --- | --- | --- |
| Client IP detection, trusted proxies and IPv4/IPv6 whitelist | Direct-peer trust check, forwarding-chain handling and packed CIDR comparison | Malformed prefix validation: BW-01 |
| Local blocking and retention | Manual blocks, automatic URI-filter entries, TTL checks, manual-entry protection and cleanup | Complete audit trail and expose active/expired state: BW-04, BW-09, BW-12 |
| URI filtering | Configured expressions, bounded input, blacklist insertion and deferred reporting | Validate expressions and review diagnostic redaction: BW-10 |
| AbuseIPDB bulk list | Scheduled fetch, parsing, staged replacement and preserved data on failed fetch | Initial readiness/status and transport bounds: BW-07, BW-11 |
| AbuseIPDB reports | Persistent queue, scheduler flush and bounded retry count per selected row | Queue capacity, deduplication and flush budget: BW-05 |
| CrowdSec CTI/LAPI | Both clients, cached decisions, CTI quota cooldown and fail-open responses | Concurrent lookup coordination and expired-row maintenance: BW-06, BW-12 |
| Known Agents crawler rules | Category normalization, scheduled fetch and User-Agent matching | Ignored TTL setting, initial readiness and empty-selection semantics: BW-03, BW-07, BW-13 |
| Known Agents visits | Opt-in REST events, header allowlist, query/body exclusion and shutdown hook | Delivery reliability, optional protocol details and retired action API: BW-14, BW-17 |
| robots.txt publication | Marked section, owner-content preservation, backups and atomic replacement | Concurrent writers, disable behavior and backup lifecycle: BW-15 |
| GRVHulk migration | Discovery, explicit confirmation, disabled import, backups, WAL snapshot and rollback | Guided recovery when existing destination files prevent import: BW-16 |
| Emergency CLI recovery | Local unblock, listing and override whitelist helper | YAML shapes, failure reporting and audit parity: BW-02, BW-04 |
| Admin2, API and MCP | Local-list pagination, statistics, latest history, protected API and seven MCP tools | Health information, history pagination and state/search visibility: BW-07–BW-09 |
| Packaging and regression | Filtered ZIP, PHP 8.3/8.4 unit jobs and clean Grav smoke test | Automated integration/browser coverage, declared minimum versions and real-provider verification: BW-18 |

### First: confirmed correctness gaps

- [ ] **BW-01 — Reject malformed CIDR prefixes** (`VALID`). [Prefix parsing](classes/IpDetector.php#L110) casts the suffix to an integer without checking its syntax. Probes showed `192.0.2.0/not-a-prefix` matching `8.8.8.8`, `2001:db8::/oops` matching an unrelated IPv6 address, and `/24junk` being accepted. This requires an invalid configured entry; it is not evidence of a remote bypass on the current sites. The helper serves both whitelists and trusted proxies. Require a numeric, in-range prefix before matching; reject invalid entries visibly in settings. Acceptance: invalid/blank/fractional/trailing-text prefixes do not match, while valid IPv4/IPv6 and explicit `/0` behavior remain covered.

- [ ] **BW-02 — Finish emergency CLI whitelist handling and outcome reporting** (`VALID`). [The whitelist branch](cli/unblock.php#L122) and [append helper](cli/unblock.php#L173) do not handle all normal override shapes. Pure-function probes returned null for `enabled: true` without a whitelist and produced invalid YAML from an inline whitelist list. The duplicate check searches the entire YAML file: an IP found only in `trusted_proxies` is treated as already whitelisted. An existing-file write is not checked before printing success, and the final exit status follows whether a local DB row was removed, even when whitelisting succeeds or fails independently. Use the existing YAML facilities to merge the whitelist key, preserve other settings, check writes and define accurate partial-success/error outcomes. Acceptance: absent key, block scalar, sequence, inline list, duplicate elsewhere, failed write and whitelist-only recovery; preserve unrelated configuration and provide the required cache-refresh guidance.

- [ ] **BW-03 — Make the Known Agents UA cache TTL setting effective** (`VALID`; policy choice needed before implementation). [The setting](blueprints.yaml#L298) promises to control refresh frequency, but the only production references to `dark_visitors.cache_ttl` are its declarations; [the job](botwarden.php#L430) is fixed at 03:00 and does not read it. Decide whether to honor the interval or remove/deprecate the ineffective control while documenting the fixed schedule. Acceptance: changing the supported setting has a demonstrable scheduling/freshness effect; migration and saved configurations retain an explicit compatibility policy.

- [ ] **BW-04 — Complete the block-list audit trail** (`VALID`). [Manual promotion](classes/BotwardenDatabase.php#L174) updates the row but records history only for a new insertion; the in-memory probe left one automatic history event after promoting the row and changing its reason. The [MCP add description](mcp.yaml#L52) promises a history event for promotion. [CLI deletion](cli/unblock.php#L106) bypasses the API's history-producing removal method altogether. Record meaningful manual promotions/edits and CLI removals without flooding history with repeated automatic hits. Acceptance: UI/API/MCP/CLI mutations are represented consistently, with an explicit decision about actor attribution and event names. Keep a list-change audit distinct from a future log of every rejected HTTP request.

### Next: operational completeness and targeted investigations

- [ ] **BW-05 — Bound the AbuseIPDB report queue and flush work** (`VALID`; limits/backoff policy needs a decision). [Enqueue](classes/BotwardenDatabase.php#L492) has no deduplication, queue-size or age limit; two identical in-memory enqueues produced two pending rows. A retry limit exists for selected rows, so retries are not wholly missing. However, [the flush](botwarden.php#L561) sends up to 200 requests sequentially, each using the transport's default 10-second timeout, on a ten-minute schedule. Add a bounded queue policy, duplicate handling, quota/backoff handling and a per-run time budget, with visible failed/dropped counts. Acceptance: repeated events, prolonged outage, a large backlog and rate limiting stay within agreed storage/runtime limits; reports are never sent from the security request hook.

- [ ] **BW-06 — Verify CrowdSec concurrent lookup coordination** (`UNVERIFIED` impact; non-atomic sequence observed). [The request path](botwarden.php#L197) performs a cache read followed by [an unconditional upsert](classes/BotwardenDatabase.php#L407), rather than an atomic claim. The comment's “single-flight” guarantee is therefore not established by the code; [existing coverage](tests/ServiceIntegrationTest.php#L408) checks sequential hits/expiry, not two workers missing together. First reproduce concurrent misses using a localhost provider counter. If duplicates occur, introduce an atomic claim/expiry mechanism using existing dependencies and verify that concurrent requests cannot overwrite an authoritative decision with a temporary clean sentinel.

- [ ] **BW-07 — Expose integration readiness and provide a first-refresh workflow** (`VALID`). [Dashboard status](classes/BotwardenApiController.php#L36) returns counts only; it does not distinguish disabled, missing token, never refreshed, stale or failed integrations. [Existing database stats](classes/BotwardenDatabase.php#L558) already provide last-refresh metadata but are unused by the controller. [Feed registration](botwarden.php#L424) schedules future refreshes and does not bootstrap a newly enabled empty list; AbuseIPDB also needs its separate auto-refresh toggle. Use Grav's existing scheduler/job status capabilities to expose prerequisites, last success, failures and an explicit supported first refresh. Acceptance: a configured-but-empty integration is not presented as verified protection, failed credentials are actionable, and an accepted event/count is never labeled proof of a connected provider project.

- [ ] **BW-08 — Finish dashboard history pagination** (`VALID`). [The API](classes/BotwardenApiController.php#L102) supports history limit/offset/total, but [the component](admin-next/pages/botwarden.js#L47) always asks for the first 25 events and offers pagination only for the local blacklist. Add history navigation using the existing API. Acceptance: inspect more than 25 events, move both ways, handle deletion/pruning and empty pages, and preserve local-list pagination state.

- [ ] **BW-09 — Show useful local-block state and decide on search** (`VALID` visibility gap; search is `NEEDS USER DECISION`). [Local listing](classes/BotwardenDatabase.php#L293) returns `is_manual`, but [the table](admin-next/pages/botwarden.js#L62) hides it and shows raw timestamps. A probe confirmed an expired automatic row no longer blocks yet remains listed/countable; the UI does not identify that distinction. Display manual/automatic and active/expired status using the effective TTL, and make timestamps readable. Decide whether to expose the existing [search helper](classes/BotwardenDatabase.php#L306) through API/UI rather than requiring an operator to page through a large list. Acceptance: no implication that every stored row currently blocks a client; clarify that removal affects the local source only.

- [ ] **BW-10 — Validate filter expressions and redact query data in diagnostics** (`VALID` observations; visitor impact of warnings is `UNVERIFIED`). [The editor](blueprints.yaml#L339) has lint disabled, and [runtime matching](botwarden.php#L226) calls `preg_match` before checking its false result. A pure probe with `[` emitted one PHP warning; response effects depend on PHP error configuration and were not tested in a site request. [Filter diagnostics](botwarden.php#L237) can log the complete URI, including its query, while queued reports already strip it. Validate expressions on save or before use, provide an actionable error, and redact sensitive query data from logs. Acceptance: malformed expressions do not emit visitor-visible warnings; preserve the current treatment of expensive patterns and explicitly test path/query/encoding semantics before changing filtering behavior.

- [ ] **BW-11 — Enforce HTTP response-size limits during transfer** (`VALID`). [The transport](classes/HttpClient.php#L29) buffers the response with `CURLOPT_RETURNTRANSFER`, then checks 32 MiB only after `curl_exec` returns. The post-download rejection does not bound memory used while downloading. Abort oversized responses as they arrive, using existing cURL support. Acceptance: oversized or chunked localhost responses terminate at the agreed limit, ordinary feeds still parse, and failed downloads preserve the last good list without exposing request credentials.

- [ ] **BW-12 — Maintain expired cache rows independently of blacklist trimming** (`VALID`). [Cache lookup](classes/BotwardenDatabase.php#L395) ignores expired rows but does not delete them; an in-memory probe returned no usable decision while the table still held the row. The sole call to [cache pruning](botwarden.php#L459) is inside auto-clean, which defaults off and is advertised as local-blacklist cleanup. Separate bounded cache maintenance from the decision to trim local blocks. Acceptance: old entries for many distinct clients are reclaimed with auto-clean off, live decisions and manual blocks remain intact, and retention controls/defaults are documented consistently.

### Product decisions and verification work

- [ ] **BW-13 — Define empty category selection and installation/retention controls** (`NEEDS USER DECISION`). [Category normalization](botwarden.php#L335) treats all false checkboxes as the four defaults; [the existing test](tests/NormalizeAgentTypesTest.php#L48) explicitly expects that behavior, so it is not an accidental regression. Choose whether zero categories should be rejected, disable that policy, or deliberately mean defaults, and explain it in the form. Also decide whether the already documented bundled `enabled: true` versus form-default-disabled distinction is intended for new installs, and whether YAML-only `history_ttl`/`history_max_rows` should be exposed in Admin2. Acceptance: settings give an accurate account of effective behavior; do not change defaults or compatibility silently.

- [ ] **BW-14 — Decide how far Known Agents visit reporting should go** (`NEEDS USER DECISION`; live hosting timing is `UNVERIFIED`). [Reporting](botwarden.php#L73) performs a three-second-bounded HTTP call in shutdown, with no durable queue, retry or delivery counters; early connection completion depends on hosting support. [The payload builder](classes/DarkVisitorsClient.php#L25) includes protocol headers but not the optional MCP method/name/client/error fields or ACP/UCP response data from the supplied contract. Decide on queued/batched delivery and which optional fields, if any, justify collection. Acceptance: measure actual FPM/browser response timing and worker occupancy, keep credentials/body data excluded unless explicitly approved, document delivery loss/retry behavior, and use the existing scheduler/queue facilities where supported.

- [ ] **BW-15 — Define the robots.txt lifecycle and test concurrent ownership** (`NEEDS USER DECISION`; lost-update behavior is `UNVERIFIED`). [The publisher](classes/RobotsTxtManager.php#L47) preserves owner content in a single update, but reads before replacement without a shared read-modify-write lock or final content comparison. Test an owner edit or another writer during publication before choosing a coordination mechanism. Turning the toggle off stops publication; there is no withdrawal action, and changed-policy backups have no retention policy. Decide whether to offer a previewed removal of only botWarden's marked block and a separate backup-retention policy. Acceptance: concurrent owner changes survive, other managed blocks remain untouched, rollback is available, and migration backups are never swept up by generic cleanup.

- [ ] **BW-16 — Add a guided migration/manual-recovery path** (`NEEDS USER DECISION`). [Import preview](classes/LegacyMigration.php#L15) correctly refuses an existing destination, while [the guard](classes/LegacyGuard.php#L7) defers request blocking until a completion marker exists when legacy artifacts remain. An installation with an existing botWarden override and leftover GRVHulk files therefore needs deliberate recovery guidance; generic refusal text does not resolve it. Design a manual-import/cleanup workflow and completion diagnostics while retaining overwrite refusal and backups. Acceptance: explain config-only, DB-only, occupied destination, partial failure and disabled-but-present legacy cases; do not automatically delete legacy files or manufacture a completion marker.

- [ ] **BW-17 — Decide the fate of the retired connection-test API** (`NEEDS USER DECISION`). The UI button is removed, but [the route](botwarden.php#L361), [controller](classes/BotwardenApiController.php#L58), auth tests and shutdown exclusion remain for compatibility. It is a protected test-event sender, not a project-connection verifier. Decide whether to document it as such or deprecate/remove it with an explicit API compatibility decision. Do not reintroduce a misleading connection button. Reconcile “seven routes” wording in historical guidance with the seven MCP tools and the additional compatibility endpoint.

- [ ] **BW-18 — Close the integration, browser and compatibility verification gaps** (`UNVERIFIED`, not claims of broken features). [CI](.github/workflows/ci.yml#L30) excludes the integration group; its clean Grav smoke job checks startup/basic HTTP but does not replace the localhost authorization/service suite or an authenticated browser test. [The JavaScript tests](tests/MigrationPageTest.js#L9) use a VM and fake elements, not a real settings/dashboard render. Plan automation for the existing localhost suites and browser workflows; verify the [declared minimum Grav/API/Admin2 versions](blueprints.yaml#L16), custom admin/API route exemptions and supported host/proxy behavior. With separate authorization, verify real provider response contracts, credentials/quotas and the served robots.txt on the intended sites. Prefer existing tools and fixtures; any new dependency or wider environment needs its own approval. Do not treat a green stub test or HTTP 204 as proof of a complete provider feature.

### Audit probe record

Read-only probes during this audit produced these results; no permanent test files were added:

| Probe | Direct result |
| --- | --- |
| Nonnumeric CIDR suffix, IPv4 and IPv6 | Unrelated addresses matched; trailing-text `/24junk` also accepted |
| CLI helper with no whitelist key | Returned null |
| CLI helper with an inline whitelist list | Returned text rejected by the existing Symfony YAML parser; block-scalar control case parsed successfully |
| CLI duplicate regex with the IP only in trusted proxies | Reported the IP present despite an empty whitelist |
| Promote an automatic block to manual and change its reason | Local row became manual, history remained one old automatic event |
| Expired automatic local entry | Lookup no longer blocked; row remained listed |
| Two identical report enqueues | Two pending rows |
| Expired cached decision | Lookup returned null; physical row remained |
| Invalid regex `[` | Matching returned false and emitted one captured PHP warning |

The older numbered phases and evidence log below are retained as historical procedures/results. They include prior repository/preview references, authorizations and unchecked gates; they do not expand this audit's repository boundary or constitute current deployment instructions. Reconcile them against this inventory when selecting future work rather than interpreting every old checkbox as a new defect.

## Codex operating contract (read before executing any checklist)

1. **Discover the target, do not guess.** Use the connected Grav MCP server's actual available tools to read system info, installed packages, enabled plugins, configuration, scheduler state, logs, and discovered plugin capabilities. Identify the local site URL, resolved Grav root, site/environment name, installed API/Admin2 versions and botWarden commit. Verify the MCP server is pointing to this **local test instance**, not a production domain, before any write.
2. **Use Grav MCP first for local site interaction.** Prefer the discovered tools corresponding to `get_system_info`, `list_packages`, `get_config`, `get_scheduler`, `get_logs`, `discover_plugins`, and `get_blueprint`. Use `update_config`, `clear_cache`, `create_backup`, and `run_scheduler` only when the current test phase and safety checks authorize those changes. Inspect the installed tool catalog rather than assuming that tools from the separate stdio bridge, such as `refresh_plugin_tools`, exist here. The installed server loads plugin manifests through the API plugin on each HTTP request; request `tools/list` again after changing a manifest.
3. **Use the local Codex shell/repository tools for code and filesystem work**, such as Git, PHP lint, Composer/PHPUnit, SQLite fixture inspection, file permissions and reviewing logs that MCP does not expose. Prefer MCP for actions against the *running site*. For anonymous HTTP/authorization tests use separate unauthenticated HTTP requests; an authenticated MCP call cannot prove an anonymous caller is denied. Browser/UI interaction requires browser automation or a human-observed result, not inference from API success.
4. **Never invent success.** Classify each item as `PASS`, `FAIL`, `BLOCKED` or `NOT RUN` in the session log; only `PASS` earns `[x]`. Record the exact command/MCP operation, observed response/status/count, relevant sanitized log, build/commit SHA, and whether a fix was committed. If blocked by a missing tool, permission or browser access, explain the blocker and test all other independently testable items.
5. **Operate only on explicit test fixtures.** It is acceptable to create and destroy clearly isolated, disposable **local** test fixtures after verifying their paths. Require the owner's approval before migrating real existing GRVHulk data, editing the site's actual `robots.txt`, submitting an external AbuseIPDB report, changing production credentials, removing/updating unrelated packages, or changing live site configuration outside the test scope. Do not assume MCP write access implies consent. Never disable or remove GRVHulk on the real installation automatically.
6. **Diagnostics from the first run.** Preserve complete local test diagnostics: useful exception class/message/stack trace, HTTP status/body shape, scheduler result, DB integrity/check counts and browser console/network failure. Redact all secrets and sensitive site data before committing or posting. Do not silence warnings or treat a successful HTTP response as proof that its returned payload is correct.
7. **Tight feedback loop.** For each failure: reproduce → identify root cause in botWarden or its Grav integration → implement the smallest coherent fix → write a regression test → run that test and neighboring checks → retry against the local Grav MCP site → document the result. Commit or update PR #1 only when separately authorized; leave the PR a draft until all gates pass.

### Existing project installation

- Authoritative botWarden source: `botwarden-repo/` under the parent project. Synchronize intended plugin files into the ignored preview; do not clone another development repository or change branches.
- Canonical Grav root: `content-repo/.preview/`. Shared site configuration is maintained in `content-repo/config/`; managed plugin files are installed in `.preview/user/plugins/`. `content-repo/dependencies.yaml` records versions and update checks.
- Existing local endpoint: `http://127.0.0.1:8766/mcp`, configured as `grav_local` in the parent project's `.codex/config.toml`. Reuse the credential helper without printing its output. No separate MCP bridge, `GRAV_API_URL`, or `GRAV_API_KEY` setup is needed.
- Existing GRVHulk is 1.0.0. Shared configuration selects local, AbuseIPDB bulk and Known Agents sources; the `localhost` environment disables GRVHulk. Use isolated synthetic fixtures representing this configuration for migration; do not import the site's real database or copy service credentials into fixtures.
- The existing `codex-local` account and key lack `api.super`, scheduler execution and package-write access. Verify current grants with `whoami`. All seven botWarden routes require `api.super`; their tools must stay hidden from this restricted account. Use a separate explicitly scoped disposable test account for positive authorization tests; do not widen the existing account or weaken controller permissions.
- First verify current core and installed plugin versions and complete required updates through Grav's supported mechanisms. Keep preview-only overrides out of shared configuration and live synchronization.

### MCP connection and exposure prerequisites

- [x] Inspect the already-configured `grav_local` Streamable HTTP connection at `http://127.0.0.1:8766/mcp` and confirm its credential helper stays within this project. Do not print, commit, or place keys in shell history or issue comments. If native MCP tools are not loaded in the current client, distinguish that from endpoint failure; an authenticated localhost JSON-RPC request can verify the installed endpoint without configuring another server.
- [x] Make a **read-only** MCP call and record the site's identity and versions; confirm the API key's actual privileges and scopes. Start with the least privilege that can perform the tests; request additional test-account privileges only when a specific operation needs them.
- [ ] Use core MCP tools to inspect configuration, logs, scheduler and package state. If MCP is unreachable, distinguish authentication failure, tool absence, wrong URL and server outage; don't silently switch to a different site.
- [x] Validate the root `mcp.yaml` with the installed Grav API manifest collector and check all seven methods, paths, inputs and `api.super` permissions against the controller. Migration and access-control tools must be non-read-only. Follow [Grav API's MCP tool manifest format](https://github.com/getgrav/grav-plugin-api#mcp-tool-manifests).
- [x] Confirm the installed Grav API serves `GET /mcp/tools` without botWarden manifest warnings. Request MCP `tools/list` again and verify the seven `botwarden_*` tools are present for an authorized test account and absent for the existing restricted account. Verify returned *data* with authorized read calls. **Do not** claim the plugin's MCP integration works merely because an API route responds.
- [ ] Re-check this tool manifest and dynamic discovery after changing botWarden API routes or permissions. An API controller and an MCP tool manifest must stay in sync.

### Execution order and reporting

Use the numbered phases below in order. Test a clean install separately from a cloned GRVHulk migration. On the actual local installation perform read-only discovery and safe test operations first; use disposable fixtures to test failures, migration and `robots.txt` writes. Codex should update this file with completed boxes plus a short session log. Any separately authorized PR update must report only verified results and outstanding blockers. A green GitHub CI run verifies its own jobs, not an authenticated Admin2 page or live external services.

## 0. Safe test boundaries

- [x] Codex identifies and confirms a local/staging Grav installation with a backup or disposable clone. Do not test the new request blocker directly on a production site.
- [x] Codex records MCP target identity, local URL, Grav/PHP/API/Admin2 versions, web server, CLI PHP version, and the botWarden commit SHA before writes.
- [x] Back up `user/config/`, `user/data/`, and the actual served `robots.txt` (if present) **outside** the directories being tested. Check backup readability before proceeding.
- [x] Preserve any existing GRVHulk installation, SQLite database (including WAL/shm files), configuration, and working cron jobs.
- [x] Do not run the GRVHulk migration test on your only copy of live data. Use a disposable site clone with realistic data.
- [x] Keep **one** request blocker active at a time. After migration, botWarden does not automatically disable GRVHulk.
- [x] Keep `robots_txt.enabled: false` and all external/reporting integrations off until their individual tests.
- [x] Do not paste API keys, session cookies, user account files, or unredacted logs into issues or the PR.
- [ ] Verify that you have shell access and a recovery path before testing block rules.

> **Important:** The bundled `botwarden.yaml` currently contains `enabled: true` even though the blueprint defaults to disabled. Check the *effective* configuration before loading the site. On a fresh test install, create a local disabled override; on a **migration test**, do not pre-create `user/config/plugins/botwarden.yaml` because the importer intentionally refuses to overwrite it.

## 1. Codex: install the development branch on a clean local test site

Use the existing project source and canonical preview. Inspect the destination first, then copy only intended runtime files from `botwarden-repo/` into `content-repo/.preview/user/plugins/botwarden/`; exclude `.git/`, tests, development dependencies and local artifacts. Verify copied files match the source. Preserve an existing installation rather than overwriting it blindly. Do not modify the existing GRVHulk installation. From the **Grav root** (the directory containing `bin/grav`), verify:

```bash
test -f bin/grav || { echo 'Not in the Grav root'; exit 1; }
php -v
php -m | grep -Ei '^(curl|sqlite3)$'
php bin/grav --version
```

- [x] Installed in exactly `user/plugins/botwarden/`, **not** `user/plugins/grvhulk/`.
- [x] Record `git -C botwarden-repo status --short` and its commit from the parent project; verify intended installed files match that source, including any recorded uncommitted changes.
- [x] CLI PHP has `curl` and `sqlite3`; the web-server PHP SAPI also has them.
- [ ] Installed Grav 2, API, and Admin2 meet the requirements in `blueprints.yaml`. If the dependency/compatibility metadata disagree, record that as a release bug.
- [x] For the **fresh canonical preview only** (not a migration fixture), set `enabled: false` in `user/env/localhost/config/plugins/botwarden.yaml`, preserving shared configuration. Ensure `robots_txt.enabled: false` and no service API keys are enabled initially.
- [ ] Run `php bin/grav clear` and verify the website and Admin2 still load with botWarden disabled.
- [x] Confirm no new botWarden scheduler job or database is unexpectedly running/created while disabled.

### Codex: required developer checks (from the authoritative source directory)

```bash
cd /home/steven/Documents/PhpStorm/spokenrealms-grav/botwarden-repo
# Install the already-declared development dependencies only when authorized.
composer install --prefer-dist --no-interaction
composer validate --no-check-publish
composer test
node --check admin-next/pages/botwarden.js
```

- [x] Composer validation passes.
- [x] PHPUnit passes without warnings/fatals that are being ignored.
- [x] JavaScript syntax check passes (if Node.js is installed).
- [ ] GitHub CI is green at the **same** commit being tested; record any local-vs-CI difference.

Composer is for development/testing here. Runtime HTTP calls should use PHP cURL without requiring a local `vendor/` directory; test that explicitly on a disposable installation as well.

## 2. Codex: first enablement, Grav API and Admin2 UI

**Fresh-install fixture only:** Codex uses a Grav MCP configuration write where supported to enable botWarden with all remote sources and URI filters still off, then uses MCP to clear cache. Do not enable the plugin on the original GRVHulk site until migration is verified and GRVHulk is explicitly disabled.

- [x] Frontend returns its normal page content (no HTTP 500, blank page, or PHP fatal).
- [x] Admin2 login still succeeds and logout still works.
- [x] botWarden appears in the Admin2 sidebar / plugin page, under the configured Admin2 base URL.
- [x] Opening the botWarden page loads the JS component, statistics, local blocks, block history, and migration detection.
- [ ] Browser developer tools: **no** 404 for `admin-next/pages/botwarden.js`, missing custom-element errors, undefined API client/dialog errors, or unhandled promise rejections.
- [ ] Inspect the Network tab for response shapes: the API returns a `{"data": ...}` envelope. The page must consume it correctly rather than treating the envelope as flat JSON.
- [x] The plugin settings page renders and saves toggles without modifying unrelated settings or revealing API keys.
- [x] Refresh and revisit Admin2: saved preferences persist; no duplicate sidebar entries appear.

**Known gap to investigate if it occurs:** the current Admin2 component uses `window.__GRAV_API` and `window.__GRAV_DIALOGS`. Confirm these are actually supplied by your installed Admin2 build. A passing PHP/JS syntax check does not prove the UI is wired correctly.

## 3. Codex: API authorization and data operations

Use **Grav MCP** for authorized calls, and separate requests **without** credentials to test authorization failures. Use the existing localhost preview below. Do not use `curl -L` when inspecting status codes.

```bash
BASE='http://127.0.0.1:8766'
curl -sS -i "$BASE/api/v1/ping"
curl -sS -i "$BASE/api/v1/botwarden/status"
curl -sS -i "$BASE/api/v1/botwarden/legacy"
curl -sS -i -X POST "$BASE/api/v1/botwarden/migrate" \
  -H 'Content-Type: application/json' -d '{"confirm":true}'
```

- [x] Public ping returns HTTP 200; anonymous botWarden endpoints return 401/403.
- [x] Anonymous POSTs **cannot** migrate, add blocks, or remove blocks.
- [x] Test with a **dedicated non-super test user** using permitted auth methods (MCP key or session). Verify its permissions and confirm it cannot use protected botWarden management endpoints.
- [x] An authorized Admin2 superuser, via discovered botWarden MCP tools once `mcp.yaml` is exposed, can read status, legacy preview, blacklist and history without 500s, malformed response envelopes or unexpected writes.
- [x] Add a controlled **TEST-NET** address (example `198.51.100.7`) through the UI; it appears once with the correct manual reason and a history entry.
- [ ] Add it again and inspect whether deduplication and history behave as intended.
- [x] Remove it through the UI; the local row disappears and an unblock history event is present.
- [x] Invalid IP input (and empty input) is rejected with a useful validation message, not a fatal error.
- [x] Pagination works beyond 50 rows using test records only; page totals and Previous/Next state are correct.
- [x] Verify the server does not accept a forged `X-Forwarded-For` header as a trustworthy client IP unless the **direct** connecting proxy is explicitly trusted.

## 4. Codex: legacy GRVHulk migration — isolated clone only

**Codex must use a separate disposable copy** containing representative synthetic data or an explicitly approved clone of real legacy GRVHulk config and database. The new importer refuses to start if `user/config/plugins/botwarden.yaml` or `user/data/botwarden/botwarden.sqlite` already exists.

- [ ] Note GRVHulk version (1.0.x or 1.1.x), enabled sources, active scheduler jobs, and old SQLite `PRAGMA user_version`.
- [ ] Verify the old site still works **before** importing; capture counts for `local`, `abuseipdb_bulk`, `history` (if available), `report_queue` and `dark_visitors_agents`.
- [ ] Capture checksums/copies of original `grvhulk.yaml` and `grvhulk.sqlite`. For a live WAL database, use a SQLite backup rather than treating an uncheckpointed single-file copy as a consistent snapshot.
- [x] With only `user/plugins/grvhulk/` present, the detector reports a legacy installation.
- [x] With the old plugin removed but its config/data still present (a different test copy), detection still works.
- [x] Preview mode makes **no filesystem changes**:

```bash
php user/plugins/botwarden/cli/migrate.php
```

- [ ] Compare preview flags with the actual legacy files and readability.
- [x] Confirm Admin2 shows an import offer **only** when the legacy data is migratable and the destination is free.
- [x] Confirm the explicit confirmation prompt clearly states that GRVHulk is not disabled or deleted.
- [x] After backup validation and confirmation that the target is disposable, perform one deliberate import, **preferably via the discovered botWarden migration MCP tool**. Use Admin2 or the CLI as a separate fallback/coverage test (the CLI command is below):

```bash
php user/plugins/botwarden/cli/migrate.php --confirm
```

- [x] A backup exists under `user/data/botwarden/backups/`; the original GRVHulk files are unchanged.
- [x] The imported `user/config/plugins/botwarden.yaml` has `enabled: false` regardless of the old setting.
- [x] SQLite destination passes `PRAGMA integrity_check` and the imported blacklist/history/report/agent counts match expectations (allowing only documented schema migrations).
- [x] `user/data/botwarden/migration-complete.json` exists and identifies this import.
- [x] Repeating the migration **refuses to overwrite** the destination and preserves both databases.
- [x] If possible, simulate a failed migration on another throwaway clone (unreadable DB, damaged SQLite, or occupied destination): no partial botWarden config or database remains and error details are observable.
- [ ] Before enabling botWarden, disable GRVHulk in the cloned site and verify its old scheduled jobs are not competing.
- [ ] Then enable botWarden and verify the local entries, settings, active sources, and custom URI filters survive. Check for paths or class names still referring to GRVHulk.
- [x] Test both a config-only import and a database-only import. A database-only import must still create a **disabled** botWarden config.
- [x] Keep an actual rollback procedure: disable botWarden, restore the test-site backup, and restart the old GRVHulk configuration. Verify that reverting does not require deleting the original database.

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
- [x] Test the emergency unblock CLI on the test database, first listing entries, then removing one:

```bash
php user/plugins/botwarden/cli/unblock.php --list
php user/plugins/botwarden/cli/unblock.php 198.51.100.7
```

- [x] Test `--whitelist` on a throwaway config and verify it writes to `user/config/plugins/botwarden.yaml` in valid YAML without changing plugin defaults. Check for duplicate or malformed whitelist entries.

## 6. Codex: external threat feeds and scheduler

Do **not** enable outbound AbuseIPDB reporting during initial tests. Use a dedicated test key/account and respect external API quotas; avoid reporting arbitrary TEST-NET addresses as abuse.

- [x] Review default job list before configuring integrations (isolated fixture through Grav's scheduler object; see number 3 evidence):

```bash
php bin/grav scheduler -j
php bin/grav scheduler -d
```

- [x] Verify `botwarden-prune-history` registers normally when the plugin is enabled, and legacy jobs are absent after disabling GRVHulk.
- [x] Enable and schedule AbuseIPDB bulk refresh on a test site. Run the job through the scheduler interface; record HTTP status, downloaded count, duration, database count and last-success time (synthetic localhost provider).
- [x] Simulate network failure/invalid authentication/malformed/empty response **without risking live keys**; verify previous saved list and its last-success timestamp are not replaced.
- [x] Test CrowdSec CTI with known non-sensitive test cases: valid API response, 404/no intelligence, network failure, rate limit and local cache/quota behavior (synthetic localhost responses; live provider not tested).
- [ ] If available, test CrowdSec LAPI with a disposable local agent and verify its decisions and cache expire correctly. A loopback response stub and real Grav cache expiry passed; no actual CrowdSec agent was available.
- [x] Verify that enabling deferred AbuseIPDB reporting only queues configured URI-filter events; disable reporting before public traffic tests.
- [x] Inspect the SQLite WAL, scheduler errors, and concurrent request latency during a bulk list replacement.
- [x] Leave unused integrations **off**, with no extra outbound requests and no noisy errors.

## 7. Codex: Known Agents and robots.txt integration

The old GRVHulk and site-specific cron workflow may already write bot rules. On the **test copy**, inspect existing `robots.txt` before switching on botWarden's publisher. Keep `robots_txt.enabled: false` at first.

- [x] Configure Known Agents API key and desired agent categories; validate that a scheduled fetch populates its User-Agent table (synthetic localhost provider/key).
- [x] Confirm a failed, unauthorized, malformed or empty Known Agents response **does not erase** the last successful agent list.
- [ ] Test on-request User-Agent enforcement through a real public client (not loopback), with the corresponding source toggle enabled.
- [x] On a disposable `robots.txt` with normal rules, enable publication and run the Known Agents update. Confirm the only new section is between `# botWarden:START` and `# botWarden:END`.
- [x] If the existing file contains your separate `# KNOWN HOSTS START` / `# KNOWN HOSTS END` managed block, verify it is preserved byte-for-byte.
- [x] Verify no duplicate botWarden block is appended on a second run; content and checksum are stable when the agent list is unchanged.
- [x] Verify a timestamped backup is made before a change and not made for an unchanged run.
- [x] Intentionally corrupt/remove one botWarden marker **on a throwaway file**; publishing should refuse to change the file and produce a visible error.
- [x] Test file permissions and the actual **served** `$BASE/robots.txt` URL: the Grav root target may differ from the web server's document root (verified on the isolated localhost PHP server, not the live web server).
- [ ] Do not leave a separate cron job and botWarden publishing duplicate or conflicting policies on the final site. Decide which owns the Known Agents section before deployment.
- [x] Verify again that `robots.txt` is advisory; enforcement depends on active request checks, not the published policy (published rules alone returned HTTP 200; enabling request enforcement returned 403).

## 8. Codex: logs, diagnostics and regression

- [ ] Check `logs/grav.log` (or your configured Grav log path), PHP-FPM/web-server logs, CLI output and browser Console/Network.
- [ ] Confirm external failures produce actionable errors without logging API keys, cookies or private configuration.
- [ ] Confirm no new PHP deprecations/warnings, uncaught exceptions, HTTP 500s or silent scheduler failures on PHP 8.3 and the web-server PHP version.
- [ ] Re-run the core website, Admin2, backup routines and existing scheduler jobs after each integration is enabled.
- [ ] Check resource use: normal request latency, peak memory, SQLite lock duration, bulk import time, and log/database growth.
- [ ] Validate permissions: PHP web-server user can use the test database and (only when enabled) write `robots.txt`; secrets and backups are not publicly accessible.

## 9. Codex: release gates (all must pass before GPM)

- [ ] Authenticated Admin2 dashboard actually renders and works, including settings, migration prompts and all write actions. Codex verifies using browser automation if available; otherwise mark this gate BLOCKED pending direct UI observation.
- [x] API authorization, API-key scope limits, and CSRF/session protections verified across authenticated and anonymous requests; use a dedicated test account/key and report exact results.
- [ ] Real Grav 2 request interception, scheduler jobs, feed failures and `robots.txt` publication tested against a running local site, with MCP observations where applicable.
- [ ] Migration preserves real GRVHulk 1.0/1.1 data, imports with botWarden disabled, and can be rolled back.
- [x] Packaging tested as a clean **release ZIP / GPM-style installation**, not just a Git checkout with development dependencies; Codex performs test installs only on disposable fixtures and does not publish the package.
- [x] Composer/blueprint metadata, permission model, documentation, licenses, and dependency declarations reviewed against the installed current Grav GPM checks. Composer schema validation previously passed for the unchanged manifest; that CLI check was not rerun in number 5.
- [ ] PHPUnit, JS check, PHP lint, Grav boot, HTTP and live-local MCP tests pass on the final commit; record exact versions and commands.
- [x] Update `CHANGELOG.md` and `README.md` with confirmed behavior and limitations.
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

- Date: 2026-10-31
- MCP target / local site / clone label: canonical `content-repo/.preview/`, `localhost` environment, `http://127.0.0.1:8766/mcp`; synthetic migration fixtures only.
- Grav version: 2.2.17, updated from 2.2.14 using `GRAV_ENVIRONMENT=localhost php bin/gpm self-upgrade --force --all-yes --no-interaction --timeout=120`.
- API plugin version: 1.1.5; Admin2: 2.2.5; MCP Server: 1.8.0. GPM also updated Form 9.1.37, Login 3.11.1 and Shortcode Core 6.2.7. The owner explicitly authorized preview updates. Prior core and updated-plugin files matched their published release archives; shared configuration bytes were preserved.
- CLI PHP / web PHP: 8.4.27, PHP development server with Grav's `system/router.php`. The owner installed the authorized Debian `php8.4-sqlite3` package; CLI and running server now have SQLite support. PHP 8.3 was not available for this session.
- botWarden commit: `9b7d114a6fa09d747e3ce9838466c12f5ba46083` plus the uncommitted manifest, migration exception handling, regression assertion and documentation changes. No commit, push, PR update, tag or release performed.
- MCP server/tool discovery status: authenticated Streamable HTTP JSON-RPC passed through the existing credential helper; native `grav_local` tools were not exposed in this client session. No separate MCP bridge was installed. Restricted `site_info`, `whoami`, `get_system_info` and `clear_cache` calls passed. Existing account/key grants were preserved; a separate temporary account with `api.access` and `api.super` key scopes was used for positive tests and removed afterward.
- PR / issue references: existing draft PR #1 referenced only; remote state and CI not queried.
- Last completed section: connection/manifest prerequisites and developer checks; selected local integration checks in phases 0–7. No claim that every numbered phase or release gate is complete.
- Open blockers / approvals required: authenticated browser interaction, session/CSRF and limited-key-on-super-account coverage, actual public/CDN/proxy topology, SQLite lock/failure behavior, external feed transport/quota/reporting scenarios, scheduler execution, GRVHulk 1.1/real-data migration and rollback, release ZIP packaging and final-commit CI remain unverified. Do not deploy or release on this evidence alone. Quark2 1.1.19 was retained because four additional installed files require review before updating to 1.1.20.

#### Recorded results

- **PASS — manifest validation:** parsed `mcp.yaml` with the installed Symfony YAML parser and fed its entries to Grav API's `McpToolCollector`; seven tools, zero warnings. Checked routes, permissions, JSON input and MCP annotations against the controller and actual HTTP discovery.
- **PASS — development checks:** Composer 2.11.1 `validate --no-check-publish`; PHPUnit 11.5.57 on PHP 8.4.27, `--do-not-cache-result --fail-on-warning --display-warnings`, **81 tests / 161 assertions**, no warnings. Test temporary directories were confined to the project. JavaScript `node --check admin-next/pages/botwarden.js`, PHP lint on all 23 source/test PHP files, and Git diff checks passed. All 22 intended installed runtime files matched source. Temporary test artifacts, credentials, account and the generated Composer lockfile were removed; the authorized ignored development dependencies remain installed.
- **FAIL → PASS — corrupt migration input:** the initial suite completed with 81 tests / 160 assertions and one `SQLite3::querySingle(): ... file is not a database` warning at `LegacyMigration.php`. Enabling exceptions on the source connection and asserting the database error in the existing rollback test removed the warning; the full suite then passed with `--fail-on-warning`. This fix is uncommitted.
- **PASS — localhost Grav HTTP:** `/`, `/contact` and the temporarily enabled Admin2 `/admin` shell returned 200 HTML; `/api/v1/ping` returned 200 JSON. Admin2's pre-existing disabled localhost override was restored after testing. Serving a shell and page script does not verify browser login or dashboard interactions.
- **PASS — disabled installation:** no botWarden tools advertised and no botWarden database/directory created while disabled. Enabling the plugin and clearing cache through MCP exposed its tools to the authorized fixture; read-only calls still created no destination database. The final localhost override leaves botWarden disabled, with external sources and publication off.
- **PASS — MCP discovery and permissions:** `/api/v1/mcp/tools` and JSON-RPC `tools/list` returned all seven `botwarden_*` tools for the scoped authorized fixture and none for `codex-local`; no botWarden manifest warnings. All four read tools returned expected data. API read/write routes returned 401 anonymously and 403 to the restricted account; authorized reads returned 200, and migration without true confirmation returned 422. Actual site data was never imported.
- **PASS — management operations:** MCP add/duplicate/unblock calls against a new disposable database produced one manual row, one block history event, then an unblock event and zero rows. Invalid input was rejected. With 55 synthetic rows, pagination returned 50 then 5 disjoint entries with total 55. A clearly labeled temporary marker simulated completed-migration state solely for these API/interception fixtures; it was not an import and was removed with the database.
- **PASS — component contract inspection:** authorized API page metadata and the component script returned 200; script bytes matched source. The installed Admin2 bundle exposes the expected API/dialog/tag/base globals and its API client unwraps `data`. Interactive rendering remains NOT RUN.
- **PASS — request interception:** through the Grav router, a configured localhost trusted test peer supplied synthetic client addresses: untrusted forwarding headers were ignored, loopback bypassed checks, manual blocking returned 403, configured status 418 worked, whitelisting bypassed a block, a harmless fixture URI created a block, and unblocking restored HTTP 200. No requests were sent to those public IPs. This does not verify an actual deployed reverse proxy/CDN. `GRAV_ENVIRONMENT=localhost php bin/grav scheduler -j` registered `botwarden-prune-history` in the synthetic post-migration state; jobs were not executed.
- **PASS — GRVHulk 1.0.0 fixtures:** created a synthetic database using the installed `HulkDatabase` schema 3, keeping WAL open during import. Imported counts matched: local 2, bulk 1, agents 1, reports 1, cache 1, metadata 1. Destination integrity was `ok`; original config/database/WAL hashes were unchanged, backups and marker existed, settings stayed disabled, repeat import refused, and config-only/database-only imports passed. No real GRVHulk data or service credentials were copied into fixtures.
- **PASS — CLI fixtures:** `cli/migrate.php` preview made no destination; `--confirm` imported synthetic data; repetition refused. `cli/unblock.php --list`, removal and `--whitelist` updated only the disposable user override and did not duplicate the address.
- **NOT RUN — remaining release gates:** browser/UI actions, live services/reporting, real-data/GRVHulk 1.1 migration, full operational failure/resource checks, release archive and remote CI. Unchecked items above remain outstanding.

### Owner browser review and version correction — 2026-10-31

- **PASS — human-observed browser checklist:** the owner reported all seven requested steps completed successfully: dashboard statistics/history, 50/5 pagination, adding `198.51.100.200`, cancel/confirm unblock dialogs, blank/invalid input, settings persistence in the localhost environment, and logout/login with a single botWarden navigation entry. The review ended with 55 synthetic local rows and 57 history events, matching one block/unblock cycle.
- The initial review login was reported as invalid; the owner requested a password reset. The local review account was updated and password login plus protected API access returned HTTP 200 before the successful browser report. No credentials are recorded here.
- Browser results are the owner's observations, not browser automation. The seeded destination deliberately made migration unavailable; migration prompts/import, browser Console/Network inspection and session/CSRF protections are not established by this checklist.
- **Version decision:** the owner specified v1.2 as the continuation of the forked 1.1 application. The manifest, unreleased changelog, README, preview installation and local dependency inventory use semantic version `1.2.0`. This does not create a tag, release or GPM submission.
- **PASS — review cleanup:** removed the dedicated review account, credential file, synthetic database/marker and review metadata; restored the saved pre-review localhost overrides with Admin2 and botWarden disabled. The existing MCP account and original GRVHulk installation/data were not changed.
- **PASS — version verification:** source and synchronized installed YAML plus the local inventory report `1.2.0`; Grav returned HTTP 200 and version `1.2.0` from `/api/v1/gpm/plugins/botwarden`. Home and contact returned HTTP 200 after cleanup; YAML and diff checks passed. No PHP behavior changed during this version-only update, and the unit suite was not rerun for metadata/documentation edits.
- Remaining gates: migration dialogs and GRVHulk 1.1/approved real-data rollback, session/CSRF and restricted keys on superuser accounts, external feeds/reporting and scheduler execution, operational failure/resource checks, PHP 8.3, release packaging and final-commit CI.

### Number 1 — migration verification — 2026-10-31

**Result: PASS for the requested synthetic-fixture scope. Stop here; number 2 has not started.** No production PHP/JavaScript changes were required. Regression coverage and this evidence are uncommitted.

- **Boundary:** all new source/test changes, fixtures, profiles and artifacts were confined to `botwarden-repo/`. A disposable Grav host under ignored `vendor/.botwarden-migration/grav/` reused installed core/plugin code read-only, with fresh synthetic configuration and a dedicated local account. No site configuration, accounts, data or dependency inventory were copied into it or changed outside this repository. The localhost fixture runs at `http://127.0.0.1:8777/`; its account details stay in the private, non-served `vendor/.botwarden-migration/credentials.json`. The host remains available for review and the next numbered test phase.
- **Prerequisites:** a fresh official catalog check confirmed Grav 2.2.17 and the six current host plugins (API 1.1.5, Admin2 2.2.5, Login 3.11.1, Form 9.1.37, Email 5.3.3, Shortcode Core 6.2.7). Email was disabled. No external threat/reporting services or scheduler jobs were executed.
- **Fixture provenance:** `tests/fixtures/grvhulk-1.1.sql` was extracted by running the actual GRVHulk 1.1.0 `HulkDatabase` implementation from this repository's upstream commit `000ddfa837b1beb9b2d87c0f6d323c2ae5d4a97c`. Its schema version is 4. Synthetic rows cover local blocks, bulk list, history, report queue, Known Agents, cached decisions, metadata and SQLite sequence state.
- **PASS — PHP regression:** `TMPDIR="$PWD/vendor/.botwarden-migration/tmp" php -d sys_temp_dir="$PWD/vendor/.botwarden-migration/tmp" vendor/bin/phpunit --do-not-cache-result --fail-on-warning --display-warnings`, run from this repository on PHP 8.4.27 / PHPUnit 11.5.57: **85 tests / 202 assertions**, no warnings. Four new cases verify committed WAL data and every table/row, occupied database preservation, failure after the destination database has been created with backups retained, and rollback using an untouched original database. PHP lint and Git diff checks passed.
- **PASS — JavaScript regression:** `node tests/MigrationPageTest.js` on Node 24.21.0: **3 tests passed**. Cancel sends no import request; Confirm sends `{confirm:true}` once, displays its backup and restores the button; failure displays an error and restores the button. These unit tests complement the real-browser checks below.
- **PASS — actual Admin2 browser:** headless Chrome 156.0.8078.79, isolated profile, native Chrome DevTools Protocol and the real Grav router/API/Admin2. A readable/free destination displayed the import offer. The actual confirmation stated that botWarden stays disabled and GRVHulk stays unchanged. Cancel sent zero migration POSTs and created no destination files. Confirm sent exactly one POST and displayed the successful backup message. With an occupied destination the action was hidden and the reason displayed. A deliberately damaged synthetic SQLite source produced a visible migration error, restored the button, preserved the source and left no destination config/database/marker.
- **PASS — imported data and activation:** actual import integrity was `ok`, schema 4; counts were local 2, history 2, bulk 1, reports 1, agents 1, cache 1, metadata 1. Original config/database bytes matched their independent pre-import copies. After explicitly enabling only botWarden, its real HTTP API returned the imported counts and manual/automatic flags. The importer itself had written a disabled configuration. CLI confirmation was also exercised on the isolated fixture.
- **PASS — operational rollback:** disabled botWarden and re-enabled the original GRVHulk fixture. Through real Grav initialization, GRVHulk 1.1 read its original manual block and retained every original table/row; integrity remained `ok`, schema 4. The frontend returned HTTP 200. Scheduler listing registered `hulk-prune-history` with no competing `botwarden-prune-history`; jobs were not run. The fixture was then reset to valid original synthetic data with a free migration destination for review.
- **Test-harness corrections:** initial new assertions were adjusted for SQLite's auxiliary WAL/SHM files and a valid YAML final-newline difference. The JavaScript pending-state test now waits for the request to start instead of assuming a cross-context microtask count. An occupied-state setup assertion was corrected to parse the full imported YAML; the HTTP helper was corrected to accept the revoke endpoint's empty 204 response. Final runs above passed; these were harness corrections, not production migration fixes.
- **Remaining scope:** this verifies synthetic GRVHulk 1.1 data and current localhost components, not an approved real-data migration or production deployment. Session/CSRF, scoped-key matrices, external integrations, release packaging and CI remain for later numbered work. Console-wide browser diagnostics and PHP 8.3 were not established by this phase.

### Number 2 — authorization, sessions and CSRF — 2026-10-31

**Result: verification complete for the installed API policy. Stop here; number 3 has not started.** No production plugin or upstream API code changed. Number 1's uncommitted work was preserved.

- All edits and disposable fixture activity remained in `botwarden-repo/`, using the existing isolated Grav host at `http://127.0.0.1:8777/`. A fresh catalog check confirmed the same current core/host-plugin versions recorded for number 1. No site repository changes or dependency installations were made; outbound requests were limited to the official release catalog checks.
- Added `tests/ApiAuthorizationTest.php` (opt-in `integration` group) and `tests/fixtures/ApiSecurityFixture.php`. The helper uses real Grav accounts, the Login plugin, API key manager and native PHP session serialization; requests then traverse the real HTTP middleware/controllers. Only a uniquely identified disposable botWarden database and temporary accounts/keys are manipulated.
- **PASS — integration:** `BOTWARDEN_TEST_URL=http://127.0.0.1:8777 TMPDIR="$PWD/vendor/.botwarden-migration/tmp" php -d sys_temp_dir="$PWD/vendor/.botwarden-migration/tmp" vendor/bin/phpunit tests/ApiAuthorizationTest.php --do-not-cache-result --fail-on-warning --display-warnings --display-errors`: **70 tests / 245 assertions**, no warnings, PHP 8.4.27 / PHPUnit 11.5.57 / API 1.1.5 / Login 3.11.1.
- **PASS — scope/account matrix:** all seven management routes returned 401 anonymously and with an incomplete two-factor session; 403 for restricted keys on superuser accounts, restricted keys plus full superuser cookies, and over-scoped keys owned by non-superusers. Explicit `api.super` keys, unscoped superuser keys, valid JWTs and valid same-host sessions reached authorized reads/writes. Migration still required true confirmation (authorized false confirmation returned 422).
- **PASS — discovery:** the manifest API exposed zero botWarden tools to restricted/mixed/non-super credentials and all seven to explicitly scoped or unscoped superuser keys. A superuser cookie did not widen the restricted key.
- **PASS — cookie CSRF:** foreign/null Origin, foreign Referer, conflicting bad Origin/good Referer, headerless form posts and invalid key/JWT headers accompanying foreign-origin cookies returned 403 on all three write routes. Denied requests preserved every security table, the legacy config/database hashes, and destination/config/backup state.
- **Policy detail:** the installed API intentionally compares hostnames, so another port on `127.0.0.1` is accepted. With no Origin/Referer, JSON or a custom header is accepted, while an ordinary form is rejected. Same-host Referer fallback works. These observed compatibility rules match the installed `api/README.md` "Session Passthrough" section and `classes/Api/Auth/SameOriginGuard.php`; this phase did not tighten them or establish a stricter full-origin policy.
- **PASS — lifecycle:** session permission revocation took effect on the next request; disabled accounts lost cookie/key/JWT access; an account token cutoff rejected an older session; revoked keys and logged-out native sessions returned 401; revoked refresh tokens could not issue new tokens. The password/OTP user-interface flows themselves were not re-tested here.
- **PASS — regressions:** `TMPDIR="$PWD/vendor/.botwarden-migration/tmp" php -d sys_temp_dir="$PWD/vendor/.botwarden-migration/tmp" vendor/bin/phpunit --exclude-group integration --do-not-cache-result --fail-on-warning --display-warnings`: **85 tests / 202 assertions**, no warnings. Both new PHP files passed lint; diff checks passed. The JavaScript migration tests were unchanged and were not rerun during number 2.
- **Cleanup:** test accounts, API-key records, raw credential file, native fixture session files, cookies issued to the HTTP test client and the disposable security database were removed. The existing migration-review account, its private login details and the migration-ready fixture were preserved. Security fixture PHP sessions use the repository-local session directory. Normal API audit/cache storage remains part of that disposable runtime.
- Changes are uncommitted. Remaining numbered work starts at 3 (integrations); no release, tag, push or deployment was performed.

### Number 3 — integrations, quotas, caches and scheduler — 2026-10-31

**Result: PASS for synthetic provider responses and the actual localhost Grav scheduler/request path. Stop here; number 4 has not started.** Live provider accounts, real CrowdSec agents and production traffic were not used or verified.

- **Boundary/prerequisites:** all edits and fixtures remained in `botwarden-repo/`. Reused the isolated Grav host at `http://127.0.0.1:8777/` and added a loopback-only synthetic provider server on port 8778. A fresh official catalog query with GPM's `v=2.2.17&php=8.4.27&stable=1` parameters confirmed Grav 2.2.17 and the same six current plugins recorded above. The initial catalog requests omitted those parameters and were not valid update evidence; they were corrected before production-code edits. No packages or dependencies were changed and no site repository was modified.
- **Harness:** added [ServiceIntegrationTest.php](tests/ServiceIntegrationTest.php) and its [ServiceFixture.php](tests/fixtures/ServiceFixture.php). A test-only namespaced `curl_init` redirects the clients' fixed provider URLs to the loopback stub; production transport, parsing, database code and the installed Grav scheduler execute normally. Separate HTTP requests traverse Grav's existing PHP server/router; the configured LAPI URL points directly to the stub. Fake forwarding headers represent public client addresses behind the explicitly trusted loopback test proxy; requests never contact those addresses. This does not verify a deployed proxy or a real public client.
- **FAIL → PASS — scheduler result:** all twelve feed-failure cases initially preserved saved lists/timestamps but incorrectly produced a successful job state. Failed report deliveries likewise left a successful state. Installed Grav `Job::exec()` treats a normally returning callable as success; botWarden caught the feed exceptions and returned normally. The five-line production correction rethrows feed errors after their existing messages and throws an aggregate report error after the existing bounded retry processing. Regression assertions now verify failed feed jobs have both `isSuccessful() === false` and persisted `state: failure`; successful jobs retain `state: success`. Only `botwarden.php` changed in production code, with the same file synchronized to the ignored in-repository Grav fixture.
- **PASS — feeds:** scheduled AbuseIPDB refresh accepted HTTP 200, deduplicated four lines to three stored addresses, and recorded count/last-success metadata. Recorded duration was **2.64 ms**, last-success Unix timestamp **1793472643**. Known Agents HTTP 200 stored two agents in **1.94 ms** with the same success timestamp. For both feeds, connection refusal, HTTP 401/503/429, empty bodies and malformed bodies preserved both saved lists and all success metadata. Known Agents enforcement returned 403 for a matching User-Agent, 200 for an ordinary browser, and 200 after disabling that source. `robots_txt.enabled` stayed false.
- **PASS — CrowdSec:** synthetic CTI/LAPI block/clean, network/401/503/malformed cases returned the expected decision/cacheability. CTI 404 was authoritative clean; HTTP 429 set the quota lock to the next UTC midnight, suppressed another call, and allowed a call after an explicitly expired fixture lock. Real Grav HTTP requests verified LAPI cached blocks, cache expiration to clean, fail-open errors with the ten-second sentinel, suppression of immediate retries and retry after expiry. An empty LAPI response retained its existing authoritative-clean policy. No external service's current response schema or quota was independently verified.
- **PASS — deferred reports:** only configured URI matches queued a report; ordinary requests, disabled reporting and a missing key did not. Only the path was queued, excluding a synthetic query value; the frontend made no report POST. Scheduler flushes POSTed only to the local stub, removed successful reports, incremented failures and dropped them after the existing five-attempt limit. One flush processed at most 200 of 201 queued rows. Failed delivery runs now report failure without changing that retry/drop policy.
- **PASS — scheduler/WAL:** the default botWarden job list contained only independent history pruning and no `hulk-*` jobs. Actual `Scheduler::runJob()` executed feed, report, history and cleanup jobs. Cleanup preserved an expired manual block while removing expired automatic/history/cache data. During a 2,000-row staged bulk replacement, a simultaneous Grav request read the complete previous list and returned 403 in **3.21 ms**; after replacement it returned 200 with 2,000 rows, WAL journal mode and integrity `ok`.
- **PASS — final regression:** `BOTWARDEN_TEST_URL=http://127.0.0.1:8777 TMPDIR="$PWD/vendor/.botwarden-migration/tmp" php -d sys_temp_dir="$PWD/vendor/.botwarden-migration/tmp" vendor/bin/phpunit --do-not-cache-result --fail-on-warning --display-warnings --display-errors`: **192 tests / 619 assertions**, no warnings, PHP 8.4.27 / PHPUnit 11.5.57. This includes all 85 previous unit/regression tests, 70 HTTP authorization tests and 37 service integration tests. Source/helper/test PHP lint and Git diff checks passed. JavaScript was unchanged and not rerun in this phase.
- **Harness corrections/cleanup:** early runs corrected the fixture metadata/history column names, CLI plugin-event initialization, LAPI stub routing and the query-aware test filter. Grav's supported cache-only clear is used between config changes. Initial nonexistent-route 500 responses were fixture setup failures, not passing interception evidence. Final request tests passed. The tests restored the original plugin configuration and removed their synthetic security database, request log, bulk synchronization marker, temporary authorization accounts/keys and session artifacts. Normal cache/log/scheduler records remain within the disposable runtime; its review account and original synthetic migration data are preserved. The two localhost test servers remain running for the session.
- Source, tests and evidence remain uncommitted. No release, tag, push or deployment was performed. Number 4 (`robots.txt`) has not started.

### Number 4 — robots.txt preservation and publication — 2026-10-31

**Result: PASS on disposable local files and the isolated Grav server. Stop here; number 5 has not started.** Completed numbers 1–3 were committed first as `3c29052` (`Verify migration, authorization and service integrations`), without a push. Number 4's changes are uncommitted.

- **Boundary/prerequisites:** every edit, temporary file and installed test copy remained in `botwarden-repo/`. Reused ports 8777/8778; no live site, cron configuration or `content-repo/` was changed. Fresh official GPM catalog requests confirmed Grav 2.2.17 and API 1.1.5, Admin2 2.2.5, Login 3.11.1, Form 9.1.37, Email 5.3.3 and Shortcode Core 6.2.7; no updates/dependencies were needed.
- **FAIL → PASS:** new [RobotsTxtManagerTest.php](tests/RobotsTxtManagerTest.php) cases showed that initial publication stripped existing trailing LF/CRLF bytes, and embedded/damaged markers allowed owner text to be replaced. The targeted fix in [RobotsTxtManager.php](classes/RobotsTxtManager.php) retains the original bytes when appending and requires markers on complete lines before replacement. It was synchronized only to the ignored in-repository Grav fixture. An initial empty-string assertion was a test-harness error and was corrected separately.
- **PASS — preservation/backups:** empty files, missing final newline, repeated LF, CRLF, Unicode comments, separate Known Hosts rules, and existing managed blocks retained their expected owner bytes. Existing file mode `0640` was retained; a new file used `0644`; backup directory/files used `0700`/`0600`. Backups contained the exact prior bytes and timestamped distinct names, including two changes within one second. Unchanged runs preserved checksum/mtime and made no additional backup. Symlinks were refused. Missing, duplicate, reversed, embedded and suffixed markers refused publication without file, backup or temporary-file changes.
- **PASS — real Grav/HTTP:** six additional cases in [ServiceIntegrationTest.php](tests/ServiceIntegrationTest.php) ran the Known Agents job through the actual scheduler, using the synthetic localhost provider. `GET /robots.txt` returned HTTP 200 `text/plain` with exactly the published bytes. Repeated scheduled publication retained content, checksum, mtime and the single backup. Four damaged-marker policies produced persisted scheduler failures with visible marker errors and preserved the served original. Publication stayed opt-in. A published `Disallow` rule alone allowed HTTP 200; separately enabling User-Agent enforcement returned 403.
- **Verification:** focused publisher/neighbor tests passed **25 tests / 99 assertions**; focused scheduled/HTTP cases passed **6 / 54**. Final command: `BOTWARDEN_TEST_URL=http://127.0.0.1:8777 TMPDIR="$PWD/vendor/.botwarden-migration/tmp" php -d sys_temp_dir="$PWD/vendor/.botwarden-migration/tmp" vendor/bin/phpunit --do-not-cache-result --fail-on-warning --display-warnings --display-errors`: **215 tests / 761 assertions**, no warnings, PHP 8.4.27 / PHPUnit 11.5.57. Changed PHP files passed lint and diff checks; source/installed publisher hashes matched. JavaScript was unchanged and not rerun.
- **Cleanup/limits:** restored the original fixture configuration and absent `robots.txt`; removed generated robots files/backups, synthetic database, request logs and authorization artifacts. Preserved the review account, original migration fixtures and normal runtime logs/cache/scheduler records. Local PHP file ownership/document-root behavior was tested; live web-server permissions, real public clients, provider accounts and final cron ownership remain unverified. Both local servers remain running. No new commit, release, tag, push or deployment was made after starting number 4.

### Number 5 — PHP 8.4, release ZIP, metadata and CI — 2026-10-31

**Result: local release validation passed on PHP 8.4.27, as explicitly directed by the owner. Remote CI is NOT RUN. Stop here; number 6 (publishing) has not started.** Number 4 was committed first as `48acfa4` (`Preserve robots.txt owner content and verify publication`), without a push. Number 5's export rules, workflow and documentation are uncommitted. Version remains `1.2.0`; compatibility requirements were not raised.

- **Boundary/environment:** all artifacts, code and fixtures remained in `botwarden-repo/`. Host OS reports Debian `forky/sid`. A fresh official catalog check confirmed Grav 2.2.17 and the same current six plugins recorded above. Created ignored `vendor/.botwarden-release/grav/` from existing core/plugin code only, with new synthetic configuration and no copied accounts, keys, legacy plugin, site data or development dependencies in botWarden. The PHP/router server at `http://127.0.0.1:8779/` remains running. No package/dependency installation or site-repository change was made.
- **Packaging:** an initial Git archive contained 42 files, including 19 development-only entries. Added [.gitattributes](.gitattributes) export exclusions. `git archive --worktree-attributes --format=zip --prefix=grav-plugin-botwarden/ --output=vendor/.botwarden-release/botwarden-1.2.0.zip HEAD` produced **22 files / 46,283 bytes**, SHA-256 `52f6de20cdd3317e6b317b42600e9d6e63498807d9f68a4a72d6fc8012c16688`. This candidate used commit `48acfa4` and working-tree export rules; it does not represent a future final commit or release tag. Every included byte matched source at build time. Grav's actual `GPM\Installer::install()` installed it into an empty plugin target, and all 22 installed files matched the archive. No plugin `vendor/`, tests, CI files, local notes or legacy plugin were present. Regenerate the archive after committing final changes before any publication.
- **Metadata/license:** parsed blueprint/Composer metadata, checked slug/type/version/license/name, required PHP extensions, and current PHP/Grav/API/Admin2 dependency constraints through the installed Composer Semver library. The installed GPM generation-compatibility check accepted the blueprint; original MIT copyright was retained. The earlier Composer 2.11.1 schema-validation evidence applies to unchanged `composer.json` (`git diff 200beed HEAD -- composer.json` was empty). A Composer CLI was unavailable here, so `composer validate` was not rerun. Minimum dependency versions and PHP 8.3 were not independently exercised in this phase.
- **PASS — installed package:** Grav cache clearing and scheduler listing succeeded from the new Grav root, registering `botwarden-prune-history`; running that job succeeded and pruned zero rows. Initial CLI invocations from the plugin root emitted `FATAL: Must be run from ROOT directory of Grav!` despite exit 0; these were rejected as failures and rerun from the correct fixture root. HTTP checks verified root/Admin2 shell 200 HTML, ping 200 JSON, packaged JavaScript 200 with matching bytes, anonymous management 401, authorized reads/writes 200, seven botWarden tools, one synthetic block/unblock cycle, two history events and SQLite integrity `ok`. Read-only status created no database. Both packaged CLI utilities ran successfully. The fresh Grav log contained no warning/error/critical diagnostics. Serving the Admin2 shell is not a new interactive browser review or an MCP transport test.
- **CI/local checks:** [.github/workflows/ci.yml](.github/workflows/ci.yml) now runs migration-dialog tests, explicitly runs the unit group with warnings treated as failures, and installs a release ZIP through GPM for the Grav smoke job. Retained its declared PHP 8.3/8.4 matrix; the owner selected 8.4 for this local phase. Symfony YAML parsing, `bash -n` for all **10 run blocks**, and PHP lint of the inline GPM installer passed. PHP 8.4 lint passed for **28 tracked PHP files**. `node --check admin-next/pages/botwarden.js` and `node tests/MigrationPageTest.js` passed (**3 tests**). `TMPDIR="$PWD/vendor/.botwarden-migration/tmp" php -d sys_temp_dir="$PWD/vendor/.botwarden-migration/tmp" vendor/bin/phpunit --exclude-group integration --do-not-cache-result --fail-on-warning --display-warnings --display-errors` passed **102 tests / 290 assertions**, no warnings. The earlier full 215-test integration result is unchanged evidence from number 4; it was not rerun here. Remote workflow execution, including its download step, has not been verified for these changes.
- **Cleanup/remaining gates:** removed the disposable release account/key, raw credential file, synthetic security database, test ZIPs, scratch helpers and rollback copies. Removing the sole test account produced Admin2's normal first-account redirect (HTTP 302); Admin2 was then disabled only in this fresh fixture, cache cleared, and its public homepage returned HTTP 200. A mistaken relative cleanup path was corrected before that configuration edit. The clean installed runtime and its ordinary configuration/cache/log/session/scheduler storage remain available for review; prior migration/review fixtures were preserved. Final-commit CI, live providers, approved real-data migration, live web-server/proxy behavior and publication remain outstanding. No new tag, release, push, PR operation or live deployment occurred.

### Number 6 — publication — 2026-10-31

**Status: owner authorized commit, merge and publication of v1.2 after passing local migration sign-off.** Number 5 was committed as `fa05ae703738d72ab1d46ea9929e255392695c42` (`Validate release ZIP installation and strengthen CI`) and pushed to `develop/botwarden-bootstrap`. HTTPS lacked terminal credentials; the same Git push succeeded using existing SSH authentication with no persistent Git/SSH configuration changes.

- **PASS — remote CI:** [Actions run 41233956730](https://github.com/stevenjaycohen/grav-plugin-botwarden/actions/runs/41233956730) completed successfully for all three jobs: PHP 8.3, PHP 8.4 and clean Grav ZIP/GPM/HTTP smoke. Composer validation, PHP lint and JavaScript tests passed within those jobs.
- **PASS — final commit:** verified the integration runtime's PHP/YAML/JavaScript bytes match committed source, then reran the complete localhost suite at `fa05ae7`: **215 tests / 761 assertions**, no warnings, PHP 8.4.27 / PHPUnit 11.5.57. Synthetic security fixtures were cleaned afterward.
- Updated [draft PR #1](https://github.com/stevenjaycohen/grav-plugin-botwarden/pull/1) with version, current implementation, exact CI/local evidence and outstanding gates. No reviews or inline review threads were present. The PR remains open, draft and unmerged.
- Prepared intentional review deliverables under ignored `vendor/.botwarden-publish/`: `botwarden-1.2.0.zip` and `release-notes.md`. The committed-tree archive contains **22 files / 46,847 bytes**; SHA-256 `b2ee68d23616c46584236ba3070e65ff4074ff2a1f57fe9a3994ee22778760c3`. Every included file matches `fa05ae7`. No tests, local data or plugin development dependencies are included. These two artifacts are retained for the pending publication decision.
- **Owner sign-off and release direction:** the owner reported the local migration test passed, then explicitly requested "commit, merge, and publish v1.2". Independent local checks confirmed matching original/imported/backup rows, preserved original database bytes, valid backups, GRVHulk disabled, botWarden enabled and frontend HTTP 200. The ignored sign-off runtime, credentials and detailed local report remain outside version control. This sign-off does not claim live-provider or real-site-data testing.
- **Release preparation:** retained manifest version `1.2.0`, dated its changelog, updated release documentation and added tag-triggered publishing after the existing PHP 8.3/8.4 and clean-install jobs. The publishing job uses GitHub's built-in repository token, verifies the tag/version and `main` ancestry, uploads a filtered ZIP to a draft release, then publishes it. An existing different asset is refused. Final merge/tag/release URLs will be reported after publication; no live-site deployment or GPM catalog submission is part of these GitHub operations.
- **Local release checks:** workflow YAML, all 11 Bash blocks and inline publisher JavaScript passed syntax checks. Six isolated publisher scenarios passed: new publication, identical-asset retry, refusal to replace a different asset, failed upload, digest mismatch and wrong tag. PHP 8.4 unit checks passed **102 tests / 290 assertions**, no warnings; Admin2 JavaScript syntax and all **3** migration-dialog tests passed. No application PHP or compatibility requirement changed in this release-preparation step.

### Two-site sign-off and GPM preparation — 2026-10-31

- The owner confirmed that both live sites completed scheduled Known Agents refreshes, retained one botWarden robots.txt block without the old Known Hosts block, and continued normal public/Admin2 operation. This is owner-reported evidence; no authenticated live-site access or independent live database audit was performed.
- The owner authorized correcting the changelog, publishing documentation-only `1.2.1`, and submitting the GPM registration request. Only changelog, manifest version and documentation are changed; application PHP/JavaScript/configuration defaults and compatibility requirements remain unchanged. The released `1.2.0` tag and assets remain intact.
- Applied the required version/date/category structure from https://learn.getgrav.org/2/plugins/gpm-submission#changelog-format . Every original 1.2.0 release-note bullet is preserved under its original version. Local test instances, credentials and detailed sign-off reports remain ignored.
- An initial search of `getgrav/grav` issues found no existing botWarden submission. Registration will request the distinct `botwarden` slug, with upstream attribution retained. Publication and submission results will be reported after CI and archive verification.
- **Local validation:** both changelog versions match Grav's version/date/category syntax, all 19 original release-note bullets and the MIT attribution are preserved, and the manifest parses with `version` as its sole changed metadata field. The working diff contains only `CHANGELOG.md`, `README.md`, `TODO.md` and `blueprints.yaml`; no application code, dependency, default configuration or workflow changed. Diff checks passed.

### Migration notice patch release — 2026-11-01

- **Owner authorization:** commit the migration-notice change, make a new release, then merge and push. Scope remains this repository; no live-site deployment or content-repository preview changes are authorized.
- **Implementation:** `e23ad70` exposes the existing completion marker in migration previews and hides the Admin2 migration section after completion, even when original GRVHulk files remain. Unfinished and blocked imports retain their notices. Regression tests cover completion, failures and later refreshes.
- **PASS — local unit checks:** PHP 8.4.27 / PHPUnit 11.5.57, **102 tests / 295 assertions**, no warnings; all **6 JavaScript migration tests** passed. Temporary PHPUnit fixtures stayed inside this repository and were removed by test teardown.
- **Release preparation:** manifest and current-version documentation use `1.2.2`; dated Grav-format changelog retains prior versions unchanged. Compatibility requirements, dependencies and configuration defaults are unchanged. Fresh localhost verification passed all **215 PHP tests / 766 assertions**, including HTTP authorization and synthetic service integration, with no warnings. PHP lint passed all 28 tracked PHP files; JavaScript syntax, manifest YAML and diff checks passed. Package and remote CI/publication results must be verified separately; prior-release evidence above is historical.

### Known Agents REST connection and reporting release — 2026-11-01

- **Owner authorization:** implement a connection action plus a separate regular-reporting toggle, then commit and deploy; deployment was clarified as publishing the next GitHub release. No live-site installation or real Known Agents visit was performed.
- **Implementation:** `1790b59` adds the superuser-only connection action, project-token guidance, one-event JSON batches sent to `/visits` with bearer authentication, strict HTTP 204 acceptance, and disabled-by-default `dark_visitors.report_visits`. Regular reports run through Grav's shutdown event; crawler blocking and robots.txt publishing remain independent. Credentials, cookies, queries and bodies are excluded from reports. There is no persistent queue or automatic retry, and early connection completion depends on hosting support.
- **PASS — local checks:** PHP 8.4.27 / PHPUnit 11.5.57, **238 tests / 874 assertions**, no warnings; **9 JavaScript tests**; PHP lint for all 29 source/test PHP files, JavaScript syntax, YAML and diff checks. The localhost Grav fixture served the exact updated component bytes with HTTP 200 and JavaScript content type; anonymous connection attempts returned HTTP 401.
- **PASS — isolation and cleanup:** the repository-local Grav fixture at `http://127.0.0.1:8777` used `tests/fixtures/KnownAgentsTransport.php` to route visits exclusively to the synthetic provider at port 8778. All six intended runtime files matched source. Temporary accounts, keys, sessions, security data, configuration and backup files were cleaned. Test servers remain available for this session. No content-repository preview, external provider account, authenticated live site or remote MCP server was accessed.
- **Release preparation:** manifest and current-version documentation use `1.2.3`, with a dated Grav-format changelog preserving prior releases. Dependencies and compatibility requirements are unchanged. The real projects' connection indicators, deployed hosting behavior and interactive Admin2 rendering remain unverified; fresh remote release CI and published-archive checks are reported separately.

### Remove misleading Known Agents connection button — 2026-11-01

- **Owner observation:** the projects showed Connected only after opting into regular visit reporting. The dashboard button accepted a test event but did not establish that status. The owner requested committing and publishing its removal.
- **Change:** remove the dashboard connection button and its JavaScript action, update settings help and setup instructions to use Report Visits, and remove the three tests for the removed UI action. Preserve the existing REST reporting behavior, disabled-by-default toggle and API compatibility. Prior release notes remain unchanged.
- **PASS — local checks:** all six remaining JavaScript tests, component syntax, blueprint YAML and Git diff checks passed. The repository-local Grav server returned HTTP 200 and JavaScript content type for the exact updated component, with the button absent; its public homepage returned HTTP 200. The two affected fixture files matched source. The earlier 238-test PHP result belongs to 1.2.3; PHP implementation is unchanged and was not rerun locally for this UI removal.
- **Release preparation:** manifest, dated changelog and current-version documentation use 1.2.4. Publication uses the existing tag-triggered GitHub workflow; fresh CI and published ZIP verification are reported separately. No live site or Known Agents project was accessed or changed.
