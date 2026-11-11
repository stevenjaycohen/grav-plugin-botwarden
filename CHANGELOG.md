# 1.2.8
## 2026-11-11

1. [](#bugfix)
    * Coordinate concurrent Known Agents visit-report failures so only the first failure starts and logs each five-minute pause. Continue counting every completed delivery attempt without extending the active pause.
    * Preserve an active reporting pause when an earlier in-flight request succeeds; a late success no longer restarts reporting during an outage.

# 1.2.7
## 2026-11-10

1. [](#bugfix)
    * Pause new Known Agents visit reports for five minutes after a recorded delivery failure instead of attempting delivery on every page during an outage. Retain the three-second timeout, unchanged page responses and no queued retries.
    * Save visit counters, timestamps and cooldown state atomically. Allow brief SQLite contention to clear, skip diagnostics at debug level for longer locks and identify unexpected persistence errors by exception type and code.
    * Extract only Admin2's configuration object in route integration tests when the same script also assigns a CSP nonce.

2. [](#improved)
    * Run the Known Agents refresh check every 15 minutes and flush queued AbuseIPDB reports every 30 minutes.

# 1.2.6
## 2026-11-03

1. [](#new)
    * Add AI Data Provider (third-party AI data suppliers) to Known Agents category choices and fresh-install defaults. Selected rules use the existing User-Agent blocking and robots.txt integration; explicit saved selections and the legacy four-category empty-selection fallback are preserved.
    * Add feed readiness, first-refresh controls and delivery diagnostics to the dashboard, with independent history pagination, exact-IP search and manual/automatic and active/expired block labels.
    * Add a previewed, explicitly confirmed withdrawal of botWarden's robots.txt block while retaining owner content and backups.

2. [](#bugfix)
    * Reject malformed CIDR prefixes and surface saved configuration/filter errors; omit query strings from URI diagnostics and prevent malformed filters from displaying PHP warnings to visitors.
    * Honor the Known Agents cache TTL through the existing refresh job, including publishing cached rules when robots.txt publication is newly enabled.
    * Bound and deduplicate the AbuseIPDB report queue, add expiry and delivery counters, and limit flush time, request timeouts and retry/backoff work.
    * Coordinate concurrent CrowdSec lookups with expiring ownership claims and prevent late results from overwriting newer decisions.
    * Enforce the existing 32 MiB HTTP response limit during transfer and preserve cached lists on failed downloads.
    * Preserve unrelated YAML settings during emergency CLI whitelisting, check writes and report recovery outcomes accurately; record manual promotions, reason edits and CLI removals in block history.
    * Coordinate robots.txt publication, reread owner content before replacement and refuse stale withdrawal previews.

3. [](#improved)
    * Explain blocked migration recovery while retaining overwrite refusal, backups, legacy guards and completion-marker handling.
    * Extend local Grav, synthetic-provider and real-browser regression coverage and automate it in the release workflow.
    * Document the repository-local development boundary and remove external-site verification requirements.

# 1.2.5
## 2026-11-01

1. [](#bugfix)
    * Reject URI-filter probes without automatically banning or reporting shared proxies when visitor attribution is untrusted or unresolved, including equivalent IPv6 addresses.
    * Suppress automatic bans and AbuseIPDB reports for cross-site, same-site and unrecognized Fetch Metadata requests while retaining request rejection and existing blocks.
    * Honor configured Admin2 and versioned API routes for blocking and visit-reporting exemptions, including subdirectory installations, while retaining endpoint authentication.
    * Prune expired local entries and cached decisions through the registered history-maintenance job even when optional automatic cleanup is disabled.
    * Remove the remaining Known Agents connection endpoint, mask API-key inputs, format dashboard timestamps in local time and navigate to settings through Admin2.
    * Guard CLI tools against web execution, remove redundant autoloading and align the outbound User-Agent with the plugin version.

2. [](#new)
    * Add an opt-in Cloudflare IPv4/IPv6 trusted-proxy preset and conditional dashboard setup warnings.
    * Add real-browser Fetch Metadata regressions, mounted-route authorization checks and a 512-visitor CTI quota/cache-expiration regression.

# 1.2.4
## 2026-11-01

1. [](#bugfix)
    * Remove the misleading Known Agents connection button and direct setup through the opt-in Report Visits setting.

# 1.2.3
## 2026-11-01

1. [](#new)
    * Connect a Known Agents project with a one-time REST API test visit from the botWarden dashboard.
    * Add a separate, disabled-by-default toggle for ongoing visit reporting after Grav emits the response.
    * Filter visit metadata to exclude credentials, cookies, query strings and request/response bodies; preserve MCP and commerce protocol headers.

# 1.2.2
## 2026-11-01

1. [](#bugfix)
    * Hide the GRVHulk migration notice after a successful import, including on later dashboard visits while legacy files remain.
    * Keep migration notices visible for unfinished or blocked imports.

# 1.2.1
## 2026-10-31

1. [](#improved)
    * Format the changelog for Grav GPM while preserving the 1.2.0 release notes.
    * Update release documentation with owner-reported validation on two live sites.

# 1.2.0
## 2026-10-31

1. [](#new)
    * Tagged release publishing through GitHub Actions after unit and clean-install checks pass.
    * Release archive export exclusions and CI checks that install the ZIP through Grav's GPM installer, run migration-dialog tests and fail unit tests on warnings.
    * Robots.txt unit and scheduled HTTP regression tests for owner-rule preservation, file permissions, backups, idempotence and damaged markers.
    * Localhost service integration tests for feed failures, CrowdSec quotas/cache expiry, deferred report limits/retries, scheduler execution and concurrent SQLite WAL reads.
    * Opt-in HTTP authorization regression tests for API-key scope caps, mixed credentials, cookie CSRF checks, session/account revocation and logout.
    * GRVHulk 1.1 schema fixtures and migration regression tests for WAL snapshots, existing destinations, late-failure cleanup and rollback, plus confirmation/cancel/error dialog tests.
    * MCP tool manifest for the seven management API operations, retaining `api.super` permissions and explicit migration confirmation.
    * Secure Admin2 API for statistics, local blacklist management and block history.
    * Legacy GRVHulk installation discovery and user-authorized migration with backups.
    * Optional Known Agents robots.txt management with marked sections and atomic publication.
    * Regression tests for feed parsing and crawler policies, and GitHub Actions PHP checks.

2. [](#improved)
    * Continued GRVHulk's 1.1 version line as botWarden 1.2.0.
    * Renamed repository, plugin slug, namespaces, class paths and database location.
    * Replaced runtime Guzzle dependency with a PHP cURL transport.
    * Moved the dashboard to Grav 2's API and Admin2 extension interfaces.

3. [](#bugfix)
    * Preserve trailing bytes when appending robots.txt rules and reject markers embedded in other text before publication can replace owner content.
    * Failed feed refreshes and report deliveries now register as failed Grav scheduler runs instead of reporting success; saved lists and report retry handling are preserved.
    * Migration reports corrupt SQLite input through an exception and rolls back without emitting a PHP warning.
    * Invalid/empty AbuseIPDB and Known Agents responses no longer erase existing security lists.

**Upstream GRVHulk**

See the repository's Git history for the original GRVHulk 1.0.0 and 1.1.0 development changelog.
