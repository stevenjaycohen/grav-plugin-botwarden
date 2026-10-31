-- GRVHulk 1.1.0 schema 4, extracted from HulkDatabase::getInstance().
-- Source: upstream commit 000ddfa837b1beb9b2d87c0f6d323c2ae5d4a97c.
-- Synthetic test databases only; no site data or credentials.

CREATE TABLE abuseipdb_bulk (
                ip TEXT PRIMARY KEY,
                confidence INTEGER DEFAULT 0,
                updated_at INTEGER DEFAULT (strftime('%s','now'))
            );

CREATE TABLE dark_visitors_agents (
                user_agent TEXT PRIMARY KEY,
                updated_at INTEGER DEFAULT (strftime('%s','now'))
            );

CREATE TABLE history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                action TEXT NOT NULL,
                reason TEXT DEFAULT '',
                is_manual INTEGER NOT NULL DEFAULT 0,
                acted_at INTEGER DEFAULT (strftime('%s','now'))
            );

CREATE TABLE ip_cache (
                ip TEXT NOT NULL,
                source TEXT NOT NULL,
                decision TEXT NOT NULL,
                score INTEGER DEFAULT 0,
                expires_at INTEGER NOT NULL,
                PRIMARY KEY (ip, source)
            );

CREATE TABLE local (
                ip TEXT PRIMARY KEY,
                reason TEXT DEFAULT '',
                is_manual INTEGER NOT NULL DEFAULT 0,
                added_at INTEGER DEFAULT (strftime('%s','now'))
            );

CREATE TABLE metadata (
                key TEXT PRIMARY KEY,
                value TEXT
            );

CREATE TABLE report_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                path TEXT NOT NULL DEFAULT '/',
                attempts INTEGER NOT NULL DEFAULT 0,
                queued_at INTEGER DEFAULT (strftime('%s','now'))
            );

CREATE INDEX idx_history_acted_at ON history(acted_at DESC);

CREATE INDEX idx_ip_cache_expires ON ip_cache(expires_at);

PRAGMA user_version = 4;
