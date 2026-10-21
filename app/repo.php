<?php
declare(strict_types=1);

const VIDEO_COLS = 'v.id, v.title, v.slug, v.thumb, v.duration, v.views, v.published_at, v.channel_id, v.channel_title, v.source';

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** @return array{0: array, 1: int} */
function videos_list(string $order, int $page, int $per, ?string $channelId = null): array
{
    $orderSql = $order === 'popular' ? 'v.views DESC' : 'v.published_at DESC';
    $where = 'v.is_active = 1';
    $params = [];
    if ($channelId !== null) {
        $where .= ' AND v.channel_id = ?';
        $params[] = $channelId;
    }
    $total = (int)q("SELECT COUNT(*) FROM videos v WHERE $where", $params)->fetchColumn();
    $items = q("SELECT " . VIDEO_COLS . " FROM videos v WHERE $where ORDER BY $orderSql LIMIT ? OFFSET ?",
        [...$params, $per, ($page - 1) * $per])->fetchAll();
    return [$items, $total];
}

/** Cari video: semua kata harus ada di judul/tag. @return array{0: array, 1: int} */
function videos_search(string $term, int $page, int $per): array
{
    $words = array_slice(array_filter(preg_split('/\s+/u', mb_strtolower(trim($term))) ?: [], fn($w) => $w !== ''), 0, 6);
    if (!$words) {
        return [[], 0];
    }
    $conds = [];
    $params = [];
    foreach ($words as $w) {
        $conds[] = "v.search LIKE ? ESCAPE '\\'";
        $params[] = '%' . addcslashes($w, '%_\\') . '%';
    }
    $where = 'v.is_active = 1 AND ' . implode(' AND ', $conds);
    $total = (int)q("SELECT COUNT(*) FROM videos v WHERE $where", $params)->fetchColumn();
    $items = q("SELECT " . VIDEO_COLS . " FROM videos v WHERE $where ORDER BY v.views DESC LIMIT ? OFFSET ?",
        [...$params, $per, ($page - 1) * $per])->fetchAll();
    return [$items, $total];
}

function stats(): array
{
    return [
        'videos' => (int)q('SELECT COUNT(*) FROM videos WHERE is_active = 1')->fetchColumn(),
        'hidden' => (int)q('SELECT COUNT(*) FROM videos WHERE is_active = 0')->fetchColumn(),
        'series' => (int)q('SELECT COUNT(*) FROM series WHERE is_active = 1 AND video_count > 1')->fetchColumn(),
        'channels' => (int)q('SELECT COUNT(*) FROM channels')->fetchColumn(),
        'ai' => (int)q("SELECT COUNT(*) FROM videos WHERE is_active = 1 AND ai_summary <> ''")->fetchColumn(),
    ];
}

function video_by_slug(string $slug): ?array
{
    $row = q('SELECT v.*, c.slug AS channel_slug FROM videos v LEFT JOIN channels c ON c.id = v.channel_id
              WHERE v.slug = ? AND v.is_active = 1', [$slug])->fetch();
    return $row ?: null;
}

function series_for_video(string $videoId): ?array
{
    $row = q('SELECT s.*, sv.position FROM series_videos sv JOIN series s ON s.id = sv.series_id
              WHERE sv.video_id = ? AND s.is_active = 1 AND s.video_count > 1
              ORDER BY s.video_count DESC LIMIT 1', [$videoId])->fetch();
    return $row ?: null;
}

function series_videos(string $seriesId): array
{
    return q("SELECT " . VIDEO_COLS . ", sv.position FROM series_videos sv JOIN videos v ON v.id = sv.video_id
              WHERE sv.series_id = ? AND v.is_active = 1 ORDER BY sv.position ASC", [$seriesId])->fetchAll();
}

function related_videos(array $video, int $limit = 12): array
{
    return q("SELECT " . VIDEO_COLS . " FROM videos v
              WHERE v.is_active = 1 AND v.channel_id = ? AND v.id != ?
              ORDER BY v.views DESC LIMIT ?", [$video['channel_id'], $video['id'], $limit])->fetchAll();
}

function series_by_slug(string $slug): ?array
{
    $row = q('SELECT s.*, c.title AS channel_title, c.slug AS channel_slug FROM series s
              LEFT JOIN channels c ON c.id = s.channel_id
              WHERE s.slug = ? AND s.is_active = 1 AND s.video_count > 0', [$slug])->fetch();
    return $row ?: null;
}

/** @return array{0: array, 1: int} */
function series_list(int $page, int $per): array
{
    $where = 's.is_active = 1 AND s.video_count > 1';
    $total = (int)q("SELECT COUNT(*) FROM series s WHERE $where")->fetchColumn();
    $items = q("SELECT s.id, s.title, s.slug, s.thumb, s.video_count, s.published_at FROM series s
                WHERE $where ORDER BY s.published_at DESC LIMIT ? OFFSET ?", [$per, ($page - 1) * $per])->fetchAll();
    return [$items, $total];
}

function channel_by_slug(string $slug): ?array
{
    $row = q('SELECT * FROM channels WHERE slug = ?', [$slug])->fetch();
    return $row ?: null;
}

function channels_all(): array
{
    return q('SELECT c.*, (SELECT COUNT(*) FROM videos v WHERE v.channel_id = c.id AND v.is_active = 1) AS video_count
              FROM channels c ORDER BY c.title')->fetchAll();
}

/* ---------- Artikel ---------- */

/** @return array{0: array, 1: int} */
function articles_list(int $page, int $per): array
{
    $total = (int)q("SELECT COUNT(*) FROM articles WHERE status = 'published' AND published_at <= ?", [now()])->fetchColumn();
    $items = q("SELECT id, slug, title, excerpt, cover, published_at FROM articles WHERE status = 'published' AND published_at <= ?
                ORDER BY published_at DESC LIMIT ? OFFSET ?", [now(), $per, ($page - 1) * $per])->fetchAll();
    return [$items, $total];
}

function article_by_slug(string $slug): ?array
{
    $row = q("SELECT * FROM articles WHERE slug = ? AND status = 'published' AND published_at <= ?", [$slug, now()])->fetch();
    return $row ?: null;
}

function articles_count(): int
{
    return (int)q("SELECT COUNT(*) FROM articles WHERE status = 'published' AND published_at <= ?", [now()])->fetchColumn();
}

function video_by_slug_light(string $slug): ?array
{
    $row = q('SELECT ' . VIDEO_COLS . ' FROM videos v WHERE v.slug = ? AND v.is_active = 1', [$slug])->fetch();
    return $row ?: null;
}
