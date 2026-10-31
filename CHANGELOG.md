# Changelog

## Unreleased — botWarden 0.1.0 (development)

### Changed
- Renamed repository, plugin slug, namespaces, class paths and database location.
- Replaced runtime Guzzle dependency with a PHP cURL transport.
- Moved the dashboard to Grav 2's API and Admin2 extension interfaces.

### Added
- Secure Admin2 API for statistics, local blacklist management and block history.
- Legacy GRVHulk installation discovery and user-authorized migration with backups.
- Optional Known Agents robots.txt management with marked sections and atomic publication.
- Regression tests for feed parsing and crawler policies, and GitHub Actions PHP checks.

### Fixed
- Invalid/empty AbuseIPDB and Known Agents responses no longer erase existing security lists.

## Upstream GRVHulk

See the repository's Git history for the original GRVHulk 1.0.0 and 1.1.0 development changelog.
