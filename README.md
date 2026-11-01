# botWarden — security and crawler policy management for Grav 2

**Version 1.2.4** — maintained continuation of GRVHulk 1.1 for Grav 2.

botWarden is a maintained fork of [GRVHulk by horiken1216](https://github.com/horiken1216/grav-plugin-grvhulk). Its MIT license and copyright notice remain in [LICENSE](LICENSE). GRVHulk's original architecture itself credits [Ari Cooper-Davis's grav-plugin-ip-blacklist](https://github.com/aricooperdavis/grav-plugin-ip-blacklist).

The fork continues GRVHulk's 1.1 version line as botWarden 1.2.4.

## Features

- Local SQLite IP blocking, block history, manual blocks, URI filters, CIDR whitelist and trusted-proxy handling
- AbuseIPDB bulk blacklist and deferred IP reporting
- CrowdSec CTI/LAPI checks with quotas and caching
- Known Agents crawler User-Agent blocking
- **Opt-in** `robots.txt` rules with markers `# botWarden:START` / `# botWarden:END`
- Grav 2 API and Admin2 management page (sidebar, local blocks, history, statistics, legacy import)
- GRVHulk migration: detection, preview, explicit confirmation, backups and data import

This is an application-layer plugin, **not** a network firewall. `robots.txt` directives are voluntary requests to compliant crawlers and do not enforce access control.

## Requirements

- Grav CMS 2 with Grav API and Admin2
- PHP 8.3 or 8.4
- PHP extensions: curl, sqlite3
- The Grav scheduler must be active for nightly refreshes

No runtime Guzzle installation is needed: botWarden uses PHP cURL. Run Composer only to install development test dependencies.

### MCP tools

The root `mcp.yaml` exposes seven API operations: `botwarden_status`, `botwarden_legacy_preview`, `botwarden_list_blocks`, `botwarden_history`, `botwarden_add_block`, `botwarden_remove_block`, and `botwarden_migrate_legacy`. Every tool requires `api.super`, matching the controller. Block changes and migration are annotated as writes; migration still requires explicit confirmation.

Use the existing Grav MCP Server plugin to discover these tools through `tools/list`; a separate stdio bridge is not required. The API plugin collects manifests from enabled plugins through `/mcp/tools`, filtering them to the caller's permissions. A restricted account will not see botWarden tools. See `TODO.md` for this project's existing localhost installation and remaining verification gates.

## Developing and testing

Clone into `user/plugins/botwarden`; do not replace the original plugin folder.
Clean release ZIP installation is verified locally on PHP 8.4.27 and in GitHub CI.

Local verification on 2026-10-31 passed 215 PHPUnit tests (761 assertions), including 102 unit/regression tests, 70 HTTP authorization tests and 43 synthetic service integration tests, on PHP 8.4.27 / Grav 2.2.17 / API 1.1.5 / Admin2 2.2.5. Three JavaScript migration-dialog tests and earlier MCP discovery, block operations, Grav HTTP interception and synthetic GRVHulk 1.0.0/1.1.0 migration checks also passed. The MCP checks used MCP Server 1.8.0. The owner confirmed the dashboard/settings browser checklist and separately signed off the local GRVHulk 1.1 migration; original, imported and backup rows matched, with GRVHulk disabled and botWarden enabled afterward. GitHub CI passed its PHP 8.3/8.4 unit jobs and clean ZIP/GPM/HTTP smoke test. Live provider accounts, real site data and deployed proxy/web-server/cron behavior were not exercised by these local tests.

The owner subsequently confirmed scheduled Known Agents refreshes, correct robots.txt contents with the old Known Hosts section removed, and normal public/Admin2 operation on two live sites. These are owner-reported results; no independent access to either site was performed. Version 1.2.1 updates documentation and changelog formatting for GPM; runtime behavior is unchanged from 1.2.0. Version 1.2.2 hides the GRVHulk migration notice after a successful import, including on later dashboard visits; unfinished and blocked imports retain their notices.

```bash
composer install
composer test
node tests/MigrationPageTest.js
```

The `integration` PHPUnit group additionally tests real HTTP authorization against the disposable repository-local Grav fixture. It is skipped unless `BOTWARDEN_TEST_URL=http://127.0.0.1:8777` is set. Run `BOTWARDEN_TEST_URL=http://127.0.0.1:8777 php vendor/bin/phpunit tests/ApiAuthorizationTest.php --do-not-cache-result --fail-on-warning` after preparing that fixture. Temporary accounts, API keys, native PHP sessions and security data are created and removed by the tests. On 2026-10-31, all 70 integration tests (245 assertions) passed. Session writes follow the installed API's documented hostname-based Origin/Referer policy, including acceptance of another port on the same host; JSON/custom-header fallbacks apply when those headers are absent.

`tests/ServiceIntegrationTest.php` uses the same disposable Grav host plus a synthetic provider server. From this repository, start `php -S 127.0.0.1:8778 -t vendor/.botwarden-migration/tmp tests/fixtures/ServiceFixture.php`, then run `BOTWARDEN_TEST_URL=http://127.0.0.1:8777 php vendor/bin/phpunit tests/ServiceIntegrationTest.php --do-not-cache-result --fail-on-warning`. Its CLI helper redirects fixed provider URLs to localhost before the real cURL transport runs; web requests use a configured loopback LAPI stub. No live keys or external reports are used. Tests restore the fixture's plugin configuration and remove their synthetic database and request log. Feed/report failures must appear as failures in Grav's scheduler state; existing lists, last-success times and the bounded report retry policy are retained.

### Configuration

Default configuration is in `botwarden.yaml`. Grav stores overrides in `user/config/plugins/botwarden.yaml`.
The new SQLite database is `user/data/botwarden/botwarden.sqlite`, independent of GRVHulk.

Known Agents blocking is configured through `sources.dark_visitors` (preserving the legacy key).
Publishing `robots.txt` rules is a separate opt-in setting:

```yaml
robots_txt:
  enabled: true
```

This uses the Known Agents API key in `dark_visitors.api_key`. The scheduled update writes botWarden's marked section in the Grav root `robots.txt`, appending it when absent and preserving all existing bytes outside it, including separate Known Hosts rules. Each marker must occupy its own complete line; missing, duplicate, reversed or embedded markers cause publication to fail without changing the file. Changed files receive a timestamped backup in `user/data/botwarden/backups`; unchanged runs do not rewrite the file or create another backup. Existing file permissions are retained, backups use mode `0600`, and newly created public files use `0644`. Publishing rules and enabling User-Agent enforcement remain separate settings.

### Known Agents connection and visit reporting

1. In Known Agents, open the project for this site, open **Settings**, and copy its project access token.
2. Save that token in botWarden's **Known Agents Project Access Token** field (`dark_visitors.api_key`). The existing crawler-rule API uses this field too.
3. Enable **Report Visits to Known Agents** and save. This opts the site into traffic reporting and defaults to off. Crawler blocking and `robots.txt` publishing remain independent.
4. Visit a public page, then check the project's **Realtime** timeline and connection status in Known Agents. The owner confirmed that both projects became Connected after opting into visit reporting. BotWarden does not independently read or change that indicator.

Reports use `POST https://api.knownagents.com/visits` with bearer authentication and a JSON array containing the event. Only HTTP **204 No Content** is treated as event acceptance. Acceptance of a test event alone does not verify the project's connection status.

```yaml
dark_visitors:
  api_key: YOUR_PROJECT_ACCESS_TOKEN
  report_visits: false
```

Regular reporting runs in Grav's `onShutdown` hook after response output and session closure. It posts one event for each request handled by Grav, including API requests, with a three-second transport timeout. Reporting failures are logged without replacing the page response. There is no persistent queue or automatic retry; early HTTP connection completion depends on the host's response-flushing support.

Events include the path without its query string, HTTP method, selected headers, response status, available content type and elapsed milliseconds. The header allowlist includes User-Agent, Host, Referer without credentials/query/fragment, IP forwarding headers, `mcp-protocol-version`, `api-version` and `ucp-agent`. Authorization, cookies, API keys, other arbitrary headers, request bodies and response bodies are excluded. Optional MCP payload fields and ACP/UCP response bodies are not collected.

For a manual update, replace these files relative to the live `/user/` directory:

```text
plugins/botwarden/botwarden.php
plugins/botwarden/botwarden.yaml
plugins/botwarden/blueprints.yaml
plugins/botwarden/classes/DarkVisitorsClient.php
plugins/botwarden/classes/BotwardenApiController.php
plugins/botwarden/admin-next/pages/botwarden.js
plugins/botwarden/README.md
plugins/botwarden/CHANGELOG.md
```

Preserve the site's `config/plugins/botwarden.yaml`; merge any desired `dark_visitors.report_visits` override into it rather than replacing it. From the Grav root, run `php bin/grav cache`, then verify the site's `/` page, the botWarden dashboard at `/admin/plugin/botwarden` (or the site's configured Admin2 route), and both states of the reporting toggle in Known Agents Realtime. The REST reporting implementation passed 238 PHP tests (874 assertions), including HTTP authorization and synthetic provider tests. The updated dashboard passed all six JavaScript migration tests. Local tests use synthetic localhost providers; the real projects' connection indicators and deployed hosting behavior require verification on those sites.

### Migrating GRVHulk

The plugin detects old installation artifacts:

- `user/plugins/grvhulk/`
- `user/config/plugins/grvhulk.yaml`
- `user/data/grvhulk/grvhulk.sqlite`

Go to **Admin2 → botWarden → GRVHulk migration**, review the detection result, and confirm the import. Or use the CLI (preview by default):

```bash
php user/plugins/botwarden/cli/migrate.php
php user/plugins/botwarden/cli/migrate.php --confirm
```

The migration copies compatible YAML settings **with botWarden disabled**, and uses SQLite's online backup API to snapshot the original database (safe for WAL). Backups are stored under `user/data/botwarden/backups`. It does not delete, disable or overwrite the older GRVHulk installation and will not overwrite existing botWarden settings or data.

**After verifying the imported data, disable GRVHulk before enabling botWarden.** A partially completed migration rolls back newly created destination files; backups are retained for review. Review configuration keys and scheduler settings before production activation.

### Emergency unblock

```bash
php user/plugins/botwarden/cli/unblock.php --list
php user/plugins/botwarden/cli/unblock.php 203.0.113.7 --whitelist
```

## Release packaging

The repository's `.gitattributes` excludes tests, CI files and local development notes from Git archives. From a committed checkout with the ignored `vendor/` directory available, create a package without overwriting an existing one:

```bash
test ! -e vendor/botwarden-1.2.4.zip && git archive --format=zip --prefix=grav-plugin-botwarden/ --output=vendor/botwarden-1.2.4.zip HEAD
```

The tested package contained 22 files, including its license, metadata and MCP manifest, with no plugin `vendor/` directory. Grav's GPM installer installed it into a clean disposable host. On PHP 8.4.27, scheduler registration/execution, public HTTP, API authorization, block/unblock operations, tool discovery and both CLI utilities passed. CI builds and installs this ZIP for its Grav smoke test and runs the migration-dialog JavaScript tests. A semantic-version tag matching `blueprints.yaml` publishes the ZIP as a GitHub release after the unit and clean-install jobs pass. The tagged commit must be on `main`; existing release assets with different contents are not replaced.

## Release and GPM status

botWarden 1.2.4 continues the abandoned GRVHulk 1.1 plugin. Release ZIPs are published on the repository's [GitHub releases page](https://github.com/stevenjaycohen/grav-plugin-botwarden/releases). GPM catalog registration is separate from publishing a GitHub release. Local sign-off used PHP 8.4; declared compatibility requirements were retained. Confirm provider credentials, migration backups and deployment-specific settings for the installation you maintain.

## Original authors and license

Maintained by Steven Jay Cohen. Derived from GRVHulk by horiken1216; the original copyright notice and MIT license remain in `LICENSE`. Full prior changes remain in Git history.
