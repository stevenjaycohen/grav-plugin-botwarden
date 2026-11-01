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
