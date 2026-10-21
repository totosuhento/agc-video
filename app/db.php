<?php
declare(strict_types=1);

/** Database milik situs aktif (storage/sites/<id>/database.sqlite). */
function db(): PDO
{
    $site = current_site();
    if ($site->pdo instanceof PDO) {
        return $site->pdo;
    }
    $pdo = new PDO('sqlite:' . $site->dir() . '/database.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=5000;');
    db_migrate($pdo);
    $site->pdo = $pdo;
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 1) {
        db_migrate_v1($pdo);
    }
    if ($version < 2) {
        // v2: banyak sumber video, artikel, auto-post
        $pdo->exec(<<<SQL
        ALTER TABLE videos ADD COLUMN source TEXT DEFAULT 'youtube';
        ALTER TABLE videos ADD COLUMN embed_url TEXT;
        ALTER TABLE videos ADD COLUMN source_url TEXT;
        ALTER TABLE videos ADD COLUMN posted_at TEXT;
        ALTER TABLE channels ADD COLUMN source TEXT DEFAULT 'youtube';
        ALTER TABLE channels ADD COLUMN url TEXT;
        CREATE INDEX IF NOT EXISTS idx_videos_source ON videos(source, is_active, updated_at);
        CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            title TEXT NOT NULL,
            excerpt TEXT,
            body TEXT,
            cover TEXT,
            keyword TEXT,
            status TEXT DEFAULT 'draft',
            published_at TEXT,
            created_at TEXT,
            updated_at TEXT,
            posted_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_articles_pub ON articles(status, published_at DESC);
        PRAGMA user_version = 2;
        SQL);
    }
    if ($version < 3) {
        // v3: antrian tulis cepat AI
        $pdo->exec(<<<SQL
        ALTER TABLE articles ADD COLUMN brief TEXT;
        ALTER TABLE articles ADD COLUMN notes TEXT;
        ALTER TABLE articles ADD COLUMN review_id TEXT;
        ALTER TABLE articles ADD COLUMN ai_error TEXT;
        PRAGMA user_version = 3;
        SQL);
    }
}

function db_migrate_v1(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS channels (
        id TEXT PRIMARY KEY,
        handle TEXT,
        title TEXT NOT NULL,
        slug TEXT UNIQUE NOT NULL,
        description TEXT,
        thumb TEXT,
        uploads_playlist TEXT,
        subscribers INTEGER DEFAULT 0,
        backfill_state INTEGER DEFAULT 0,   -- 0 belum, 1 berjalan, 2 selesai
        backfill_token TEXT,
        updated_at TEXT
    );
    CREATE TABLE IF NOT EXISTS videos (
        id TEXT PRIMARY KEY,
        channel_id TEXT NOT NULL,
        channel_title TEXT,
        title TEXT NOT NULL,
        slug TEXT UNIQUE NOT NULL,
        description TEXT,
        ai_summary TEXT,
        thumb TEXT,
        duration INTEGER DEFAULT 0,
        views INTEGER DEFAULT 0,
        likes INTEGER DEFAULT 0,
        tags TEXT,
        search TEXT,                        -- judul + tag huruf kecil (untuk pencarian & halaman topik)
        published_at TEXT,
        is_active INTEGER DEFAULT 1,
        created_at TEXT,
        updated_at TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_videos_pub ON videos(is_active, published_at DESC);
    CREATE INDEX IF NOT EXISTS idx_videos_views ON videos(is_active, views DESC);
    CREATE INDEX IF NOT EXISTS idx_videos_channel ON videos(channel_id, is_active, published_at DESC);
    CREATE INDEX IF NOT EXISTS idx_videos_updated ON videos(is_active, updated_at);

    CREATE TABLE IF NOT EXISTS series (
        id TEXT PRIMARY KEY,
        channel_id TEXT NOT NULL,
        title TEXT NOT NULL,
        slug TEXT UNIQUE NOT NULL,
        description TEXT,
        ai_summary TEXT,
        thumb TEXT,
        item_count INTEGER DEFAULT 0,
        video_count INTEGER DEFAULT 0,
        published_at TEXT,
        items_synced_at TEXT,
        synced_item_count INTEGER DEFAULT -1,
        is_active INTEGER DEFAULT 1,
        updated_at TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_series_list ON series(is_active, video_count, published_at DESC);

    CREATE TABLE IF NOT EXISTS series_videos (
        series_id TEXT NOT NULL,
        video_id TEXT NOT NULL,
        position INTEGER DEFAULT 0,
        PRIMARY KEY (series_id, video_id)
    );
    CREATE INDEX IF NOT EXISTS idx_sv_video ON series_videos(video_id);
    PRAGMA user_version = 1;
    SQL);
}

function meta_get(string $k, ?string $default = null): ?string
{
    $v = q('SELECT v FROM meta WHERE k = ?', [$k])->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function meta_set(string $k, string $v): void
{
    q('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
}
