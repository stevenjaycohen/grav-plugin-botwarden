# botWarden — security and crawler policy management for Grav 2

**Pre-release development branch (1.2.0). Do not use on a production site yet.**

botWarden is a maintained fork of [GRVHulk by horiken1216](https://github.com/horiken1216/grav-plugin-grvhulk). Its MIT license and copyright notice remain in [LICENSE](LICENSE). GRVHulk's original architecture itself credits [Ari Cooper-Davis's grav-plugin-ip-blacklist](https://github.com/aricooperdavis/grav-plugin-ip-blacklist).

The fork continues GRVHulk's 1.1 version line as botWarden 1.2.0.

## Features in development

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
This branch has not yet completed clean-install integration testing.

Local verification on 2026-10-31 passed 81 PHPUnit tests (161 assertions), MCP discovery and authorization, block operations, Grav HTTP interception, and synthetic GRVHulk 1.0.0 migration checks on PHP 8.4.27 / Grav 2.2.17 / API 1.1.5 / Admin2 2.2.5 / MCP Server 1.8.0. The owner subsequently confirmed the browser checklist passed: dashboard, pagination, block/unblock dialogs, validation, settings persistence and login/logout. Migration dialogs, external integrations, PHP 8.3, real-data migration and release packaging remain unverified. See the session evidence in `TODO.md`; this remains a pre-release.

```bash
composer install
composer test
```

### Configuration

Default configuration is in `botwarden.yaml`. Grav stores overrides in `user/config/plugins/botwarden.yaml`.
The new SQLite database is `user/data/botwarden/botwarden.sqlite`, independent of GRVHulk.

Known Agents blocking is configured through `sources.dark_visitors` (preserving the legacy key).
Publishing `robots.txt` rules is a separate opt-in setting:

```yaml
robots_txt:
  enabled: true
```

This uses the Known Agents API key in `dark_visitors.api_key`. The scheduled update writes only inside botWarden's marked section in the Grav root `robots.txt`, backing up existing files in `user/data/botwarden/backups`. If the markers are corrupted, it refuses to write.

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

## Release and GPM status

This branch is under development and has **not** been released or submitted to GPM. Before a release, test on a clean Grav 2/Admin2 install, verify scheduler and security behavior with real APIs and recovery procedures, and review the GitHub Actions results.

## Original authors and license

Maintained by Steven Jay Cohen. Derived from GRVHulk by horiken1216; the original copyright notice and MIT license remain in `LICENSE`. Full prior changes remain in Git history.
