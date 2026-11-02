# botWarden — security and crawler policy management for Grav 2

**Version 1.2.5** — maintained continuation of GRVHulk 1.1 for Grav 2.

botWarden helps you block unwanted IP addresses, filter abusive requests and manage crawler access on your Grav site. Manage local blocks from Admin2 and optionally connect AbuseIPDB, CrowdSec or Known Agents.

## Features

- Local SQLite IP blocking, block history, manual blocks, URI filters, CIDR whitelist and trusted-proxy handling
- AbuseIPDB bulk blacklist and deferred IP reporting
- CrowdSec CTI/LAPI checks with quotas and caching
- Known Agents crawler User-Agent blocking
- **Opt-in** `robots.txt` rules with markers `# botWarden:START` / `# botWarden:END`
- **Opt-in** visit reporting to a Known Agents project
- Grav 2 API and Admin2 management page (sidebar, local blocks, history, statistics, legacy import)
- GRVHulk migration: detection, preview, explicit confirmation, backups and data import

This is an application-layer plugin, **not** a network firewall. `robots.txt` directives are voluntary requests to compliant crawlers and do not enforce access control.

## Requirements

- Grav CMS 2 (2.1.5 or later), with API 1.0.0 or later and Admin2 2.0.0 or later
- PHP 8.3 or later within the PHP 8 series
- PHP extensions: curl, sqlite3
- Write access for PHP to `user/data/botwarden/`
- An active Grav scheduler for list refreshes, maintenance and queued AbuseIPDB reports

No Composer installation is needed to use the plugin. Third-party services are optional; local IP blocking does not require an API key.

## Installation

**Upgrading from GRVHulk?** Install botWarden alongside it, keeping the `grvhulk` folder intact. Follow [Migrating from GRVHulk](#migrating-from-grvhulk) before saving botWarden settings or adding blocks; the importer needs an unused destination.

1. Check the requirements above and back up your site before installing.
2. Download the plugin ZIP from [GitHub Releases](https://github.com/stevenjaycohen/grav-plugin-botwarden/releases).
3. Extract it into your Grav installation as `user/plugins/botwarden/`. The file `botwarden.php` must be directly inside that folder, rather than inside another nested directory.
4. From your Grav root, clear the cache:

   ```bash
   php bin/grav cache
   ```

5. Sign in to Admin2 with an account that has `api.super` permission. Open **botWarden** in the sidebar, then follow **Plugin settings** to configure it.

On a fresh installation, botWarden is enabled with local IP blocking and a default block response of **403 Forbidden**. External sources, URI filtering, reporting and `robots.txt` publication are off until you enable them.

## Configuration

Default configuration is in `botwarden.yaml`. Grav stores overrides in `user/config/plugins/botwarden.yaml`.
The new SQLite database is `user/data/botwarden/botwarden.sqlite`, independent of GRVHulk.

Use **Plugin settings** in the dashboard, or merge overrides into `user/config/plugins/botwarden.yaml`. Keep custom settings in that override file so plugin updates do not replace them.

### Local blocking and request filtering

- Use the dashboard's **Local IP blacklist** to block an address with a reason or remove a local block. The dashboard also shows security statistics and block history.
- Add IP addresses or CIDR ranges to **Whitelisted IPs / CIDRs** to exempt them from blocking. Enter one per line.
- If your site uses a reverse proxy or CDN, configure **Trusted Proxies** with its IP addresses or CIDR ranges. Forwarded client-IP headers are used only when the direct connection is from a trusted proxy; otherwise botWarden uses the direct connection's address.
- For a Cloudflare-fronted site, explicitly select **Trusted Proxy Preset → Cloudflare (IPv4 and IPv6)**. The preset adds Cloudflare's published ranges to any custom **Trusted Proxies** entries; **None** is the default. It uses bundled ranges verified on 2026-11-01 against Cloudflare's [IPv4 list](https://www.cloudflare.com/ips-v4/) and [IPv6 list](https://www.cloudflare.com/ips-v6/), without request-time downloads. Review the published lists when updating the plugin.
- The dashboard shows a conditional setup warning when no effective trusted proxies are configured, or a warning when its current request carries forwarding headers from an untrusted peer. The warning does not automatically trust headers or change settings.
- Enable **URI Filtering** to match requests against the configured regular expressions. Review the patterns for your site first. Matching requests add the client IP to the local blacklist; **Enable Blacklisting** controls whether they are rejected.

When a request carries `CF-Connecting-IP`, `X-Forwarded-For` or `X-Real-IP` from a peer outside **Trusted Proxies**, a URI-filter match does not create a local block or queue an AbuseIPDB report. **Enable Blacklisting** still controls rejection of that request. Configure trusted proxies to identify visitors correctly behind a CDN or reverse proxy.

Automatic insertion and reporting are also skipped when the detected address is still the connecting trusted proxy. This covers missing or unusable visitor headers and forwarded chains containing only trusted proxies, so the shared peer is not banned or reported as a visitor. Equivalent IPv6 spellings are treated as the same address. Ensure your proxy supplies a usable visitor address; the matching request is still rejected when **Enable Blacklisting** is enabled, and existing manual blocks remain enforced.

URI-filter matches also create no local block or AbuseIPDB report when `Sec-Fetch-Site` is `cross-site`, `same-site` or any unrecognized value. Automatic insertion and reporting require the header to be absent, `none` or `same-origin`, as well as the proxy check above. This protects readers of cross-site embeds or links when their browsers send that metadata; the matching request is still rejected when **Enable Blacklisting** is enabled. [Fetch Metadata](https://www.w3.org/TR/fetch-metadata/#sec-fetch-site-header) defines these origin relationships; this header is a safeguard, not proof that a client is trustworthy.

Manual dashboard blocks are permanent until removed. Automatically added local entries expire after 24 hours by default (`local_ttl: 86400`); set `local_ttl: 0` for no automatic expiry. Removing a local block does not override another enabled source that still blocks the address.

botWarden exempts Admin2 and the versioned API from request blocking, using `plugins.admin2.route`, `plugins.api.route` and `plugins.api.version_prefix`. These are complete path-prefix matches: `/admin` does not exempt `/administrator`, and `/api/v1` does not exempt `/api/v10`. Changing the configured routes moves the exemptions; the old defaults are not additional exemptions. Blocked IPs can still reach the configured Admin2 login and API authentication endpoints. Those endpoints retain their own authentication and permission checks; the exemption does not grant access to protected operations.

### Optional threat sources

| Source | Setup |
| --- | --- |
| AbuseIPDB | Save an AbuseIPDB API key, select the bulk blacklist in **Active Sources**, and enable **Auto-refresh AbuseIPDB Bulk List**. Optional **Auto-report Filtered IPs to AbuseIPDB** queues URI-filter matches for reporting. |
| CrowdSec CTI | Select CrowdSec in **Active Sources**, choose CTI mode, and enter your CTI API key. Configure the score threshold and cache duration for your account. |
| CrowdSec LAPI | Select CrowdSec in **Active Sources**, choose LAPI mode, and enter your self-hosted LAPI URL and API key. |
| Known Agents | Save your project's access token, select Known Agents in **Active Sources**, and choose the agent types to block. See the separate options for crawler rules and visit reporting below. |

### Known Agents crawler rules

Known Agents blocking is configured through `sources.dark_visitors` (preserving the legacy key).
Publishing `robots.txt` rules is a separate opt-in setting:

```yaml
robots_txt:
  enabled: true
```

This uses the Known Agents project access token in `dark_visitors.api_key` and requires PHP write access to the Grav root `robots.txt` and its directory. The scheduled update writes between `# botWarden:START` and `# botWarden:END`, appending the section when absent and preserving content outside it, including separate Known Hosts rules. Existing files are backed up before changes in `user/data/botwarden/backups/`. Invalid or duplicate markers stop publication without changing the file. Publishing rules and enabling User-Agent blocking are separate settings.

### Known Agents visit reporting

The former one-time connection action has been removed. Project setup uses the opt-in **Report Visits** setting and actual public-site traffic:

1. In Known Agents, open the project for this site, open **Settings**, and copy its project access token.
2. Save that token in botWarden's **Known Agents Project Access Token** field (`dark_visitors.api_key`). The existing crawler-rule API uses this field too.
3. Enable **Report Visits to Known Agents** and save. This opts the site into traffic reporting and defaults to off. Crawler blocking and `robots.txt` publishing remain independent.
4. Visit a public page, then check the project's **Realtime** timeline and connection status in Known Agents.

```yaml
dark_visitors:
  api_key: YOUR_PROJECT_ACCESS_TOKEN
  report_visits: true
```

Automatic visit reporting sends public-site request metadata to Known Agents after Grav emits the response. It excludes the configured Admin2 and versioned API routes, including login and API authentication requests, using the same path-prefix check as the blocker. Reports have a three-second timeout; failures are logged without replacing the page response and are not queued for retry.

Events include the path without its query string, HTTP method, selected headers (including User-Agent and client-IP information), response status, available content type and elapsed time. Authorization headers, cookies, API keys, request bodies and response bodies are excluded. Referer URLs have credentials, query strings and fragments removed.

## Scheduled updates

Configure Grav's scheduler to run regularly through your hosting environment. Enabling a plugin setting alone does not run scheduled work. The plugin registers these jobs when their settings are active:

| Job | Schedule | Required setting |
| --- | --- | --- |
| Local blacklist trimming and database vacuum | Daily at 02:35 | `auto_clean` |
| AbuseIPDB bulk refresh | Daily at 02:45 | `auto_cache`, AbuseIPDB bulk source and API key |
| Known Agents crawler refresh / robots.txt publication | Daily at 03:00 | Known Agents source or robots.txt publication, plus project token |
| Expired cache/local entries and block history cleanup | Daily at 03:30 | Always registered while botWarden is enabled, after any required migration |
| Queued AbuseIPDB reports | Every 10 minutes | `enable_reporting` and AbuseIPDB API key |

After enabling a list source, run its job from Grav's scheduler or wait for its scheduled refresh, then check the dashboard counts and scheduler results.

The `botwarden-prune-history` job removes expired IP cache rows (including expired CrowdSec single-flight sentinels), expired automatic local blocks, and old or excess history rows even with `auto_clean: false`. It preserves unexpired cache/local entries and manual blocks; `local_ttl: 0` keeps automatic local blocks permanent. Size-based blacklist trimming and `VACUUM` remain in the optional `auto_clean` job, which also prunes before reclaiming database space. These tasks require a running Grav scheduler; registration alone does not execute them.

## Migrating from GRVHulk

The plugin detects old installation artifacts:

- `user/plugins/grvhulk/`
- `user/config/plugins/grvhulk.yaml`
- `user/data/grvhulk/grvhulk.sqlite`

Go to **Admin2 → botWarden → GRVHulk migration**, review the detection result, and confirm the import. Or run the CLI from your Grav root (preview by default):

```bash
php user/plugins/botwarden/cli/migrate.php
php user/plugins/botwarden/cli/migrate.php --confirm
```

The migration copies compatible YAML settings **with botWarden disabled**, and uses SQLite's online backup API to snapshot the original database (safe for WAL). Backups are stored under `user/data/botwarden/backups`. It does not delete, disable or overwrite the older GRVHulk installation and will not overwrite existing botWarden settings or data.

**After verifying the imported data, disable GRVHulk before enabling botWarden.** A partially completed migration rolls back newly created destination files; backups are retained for review. Review configuration keys and scheduler settings before production activation.

## Emergency unblock

Run these commands from your Grav root. Replace the example address with the IP you want to unblock:

```bash
php user/plugins/botwarden/cli/unblock.php --list
php user/plugins/botwarden/cli/unblock.php 203.0.113.7 --whitelist
```

`--whitelist` also adds the address to `user/config/plugins/botwarden.yaml` so it takes priority over other blocking sources.

## Updating botWarden

Back up `user/config/plugins/botwarden.yaml` and `user/data/botwarden/`, then replace the files in `user/plugins/botwarden/` with the new release. Preserve your configuration overrides and data. Run `php bin/grav cache` from the Grav root, then check a public page and the botWarden dashboard. Read [CHANGELOG.md](CHANGELOG.md) for release changes.

## Optional MCP access

If you use Grav's MCP Server plugin, botWarden provides tools to inspect status, local blocks and history, add or remove local blocks, and preview or confirm a GRVHulk import. These tools require `api.super` permission. MCP Server is not required for installation or dashboard use.

## Support

Report problems through [GitHub Issues](https://github.com/stevenjaycohen/grav-plugin-botwarden/issues). Include your Grav, PHP and plugin versions, the relevant settings with credentials removed, and steps to reproduce the problem.

## Original authors and license

Maintained by Steven Jay Cohen. Derived from [GRVHulk by horiken1216](https://github.com/horiken1216/grav-plugin-grvhulk), whose architecture credits [Ari Cooper-Davis's grav-plugin-ip-blacklist](https://github.com/aricooperdavis/grav-plugin-ip-blacklist). The original copyright notice and MIT license remain in [LICENSE](LICENSE).
