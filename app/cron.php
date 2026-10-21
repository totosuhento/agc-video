<?php
declare(strict_types=1);

require_once ROOT . '/app/YouTube.php';
require_once ROOT . '/app/Fetcher.php';
require_once ROOT . '/app/AI.php';
require_once ROOT . '/app/Sources.php';

/** Ambil video untuk satu situs (YouTube + sumber lain). Return ringkasan untuk ditampilkan. */
function run_fetch_site(string $siteId, array $overrides = []): string
{
    $site = Site::activate($siteId);
    if ($overrides) {
        $site->cfg = array_replace_recursive($site->cfg, $overrides);   // hanya untuk run ini, tidak disimpan
    }
    $ytChannels = array_values(array_filter(array_map('trim', (array)cfg('youtube.channels', []))));
    $others = array_values(array_filter((array)cfg('sources', []), fn($s) => trim((string)($s['value'] ?? '')) !== ''));
    if (!$ytChannels && !$others) {
        return "[$siteId] dilewati: belum ada channel / sumber video";
    }
    $lock = fopen($site->dir() . '/fetch.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return "[$siteId] dilewati: proses fetch lain masih berjalan";
    }
    $allowed = [];
    $failed = 0;
    $units = 0;
    try {
        if ($ytChannels) {
            if (trim((string)cfg('youtube.api_key', '')) === '') {
                $failed++;
                logmsg('YouTube dilewati: API key belum diisi');
            } else {
                $yt = new YouTube((string)cfg('youtube.api_key'));
                try {
                    $r = (new Fetcher($yt))->run();
                    $allowed = array_merge($allowed, $r['allowed']);
                    $failed += $r['failed'];
                } catch (QuotaExceeded $e) {
                    $failed++;
                    logmsg('YouTube berhenti, kuota habis: ' . $e->getMessage());
                } catch (Throwable $e) {
                    $failed++;
                    logmsg('YouTube ERROR: ' . $e->getMessage());
                }
                $units = $yt->units();
            }
        }
        if ($others) {
            $src = new Sources();
            $src->run($others);
            $allowed = array_merge($allowed, array_keys($src->allowed));
            $failed += $src->failed;
        }
        if ($failed === 0) {
            cleanup_removed_channels($allowed);
        }
        Fetcher::purgeInactive();
        meta_set('last_fetch', now());
        if (function_exists('social_autopost')) {
            social_autopost();
        }
    } catch (Throwable $e) {
        logmsg('ERROR: ' . $e->getMessage());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    cache_clear();
    $msg = 'Selesai. Unit YouTube API run ini: ' . $units . ' | hari ini (key ini): ' . quota_used();
    logmsg($msg);
    return "[$siteId] $msg";
}

/** Channel/sumber yang dihapus dari pengaturan → videonya disembunyikan. */
function cleanup_removed_channels(array $keep): void
{
    $keep = array_values(array_unique($keep));
    $in = $keep ? implode(',', array_fill(0, count($keep), '?')) : "''";
    $off = q("UPDATE videos SET is_active = 0, updated_at = ? WHERE is_active = 1 AND channel_id NOT IN ($in)", [now(), ...$keep])->rowCount();
    q("UPDATE series SET is_active = 0, updated_at = ? WHERE is_active = 1 AND channel_id NOT IN ($in)", [now(), ...$keep]);
    q("DELETE FROM channels WHERE id NOT IN ($in)", $keep);
    if ($off) {
        logmsg("$off video disembunyikan (channel/sumber sudah dihapus dari pengaturan)");
    }
}

/** Tulis deskripsi AI untuk satu situs. Return jumlah deskripsi. */
function run_enrich_site(string $siteId): int
{
    $site = Site::activate($siteId);
    if (trim((string)cfg('ai.api_key', '')) === '') {
        return 0;
    }
    $lock = fopen($site->dir() . '/enrich.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    $articles = process_article_queue(max(0, (int)cfg('ai.articles_per_run', 2)));
    if (!cfg('ai.enabled')) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return $articles;
    }
    $limit = (int)cfg('ai.per_run', 30);
    $done = 0;
    $series = q("SELECT s.id, s.title, s.description, c.title AS channel FROM series s LEFT JOIN channels c ON c.id = s.channel_id
                 WHERE s.is_active = 1 AND s.video_count > 1 AND (s.ai_summary IS NULL OR s.ai_summary = '')
                 ORDER BY s.video_count DESC LIMIT ?", [max(1, intdiv($limit, 3))])->fetchAll();
    foreach ($series as $s) {
        try {
            $txt = AI::describe('series', $s['title'], (string)$s['description'], (string)$s['channel']);
            if ($txt !== '') {
                q('UPDATE series SET ai_summary = ? WHERE id = ?', [$txt, $s['id']]);
                $done++;
            }
        } catch (Throwable $e) {
            logmsg('AI series gagal: ' . $e->getMessage());
            break;
        }
    }
    $videos = q("SELECT id, title, description, channel_title FROM videos
                 WHERE is_active = 1 AND (ai_summary IS NULL OR ai_summary = '')
                 ORDER BY views DESC LIMIT ?", [max(0, $limit - $done)])->fetchAll();
    foreach ($videos as $v) {
        try {
            $txt = AI::describe('video', $v['title'], (string)$v['description'], (string)$v['channel_title']);
            if ($txt !== '') {
                q('UPDATE videos SET ai_summary = ? WHERE id = ?', [$txt, $v['id']]);
                $done++;
            }
        } catch (Throwable $e) {
            logmsg('AI video gagal: ' . $e->getMessage());
            break;
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    if ($done) {
        cache_clear();
    }
    logmsg("AI: $done deskripsi ditulis.");
    return $done + $articles;
}

/** Jalankan untuk semua situs (atau satu situs: id atau domain). */
function run_fetch_all(?string $only = null, bool $enrich = false): void
{
    $targets = [];
    foreach (sites_all() as $s) {
        if ($only === null || $only === '' || $s['id'] === $only || site_id_for_host($only) === $s['id']) {
            $targets[] = $s['id'];
        }
    }
    if (!$targets) {
        echo "Tidak ada situs yang cocok.\n";
        return;
    }
    foreach ($targets as $id) {
        $line = $enrich ? "[$id] AI: " . run_enrich_site($id) . ' deskripsi' : run_fetch_site($id);
        echo $line, "\n";
        @ob_flush();
        flush();
    }
}

/**
 * Antrian tulis cepat: ubah item "queued" menjadi draf artikel lengkap dalam bahasa situs.
 * @return int jumlah artikel yang berhasil dibuat
 */
function process_article_queue(int $max): int
{
    $done = 0;
    $items = q("SELECT id, brief, notes, keyword FROM articles WHERE status = 'queued' ORDER BY id LIMIT ?", [$max])->fetchAll();
    foreach ($items as $it) {
        try {
            $kw = trim((string)$it['keyword']);
            $titles = $kw !== '' ? array_column(videos_search($kw, 1, 15)[0], 'title') : [];
            $a = AI::articleFull((string)$it['brief'], $kw, $titles, (string)$it['notes']);
            $slug = slugify($a['title'], 90);
            if ($slug === 'video') {
                $slug = 'article-' . $it['id'];
            }
            if (q('SELECT 1 FROM articles WHERE slug = ? AND id != ?', [$slug, $it['id']])->fetchColumn()) {
                $slug .= '-' . $it['id'];
            }
            q("UPDATE articles SET title = ?, slug = ?, excerpt = ?, body = ?, review_id = ?, status = 'draft', ai_error = NULL, updated_at = ? WHERE id = ?",
                [$a['title'], $slug, mb_substr($a['excerpt'], 0, 300), $a['body'], $a['summary_id'], now(), $it['id']]);
            logmsg('Tulis cepat: draf "' . $a['title'] . '" siap dicek');
            $done++;
        } catch (Throwable $e) {
            q("UPDATE articles SET status = 'failed', ai_error = ?, updated_at = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), now(), $it['id']]);
            logmsg('Tulis cepat gagal: ' . $e->getMessage());
        }
    }
    return $done;
}
