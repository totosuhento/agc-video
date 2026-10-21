<?php
declare(strict_types=1);

require_once ROOT . '/app/YouTube.php';
require_once ROOT . '/app/Fetcher.php';
require_once ROOT . '/app/AI.php';

/** Ambil video untuk satu situs. Return ringkasan untuk ditampilkan. */
function run_fetch_site(string $siteId, array $overrides = []): string
{
    $site = Site::activate($siteId);
    if ($overrides) {
        $site->cfg = array_replace_recursive($site->cfg, $overrides);   // hanya untuk run ini, tidak disimpan
    }
    if (trim((string)cfg('youtube.api_key', '')) === '') {
        return "[$siteId] dilewati: YouTube API key belum diisi";
    }
    if (!array_filter((array)cfg('youtube.channels', []))) {
        return "[$siteId] dilewati: belum ada channel";
    }
    $lock = fopen($site->dir() . '/fetch.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return "[$siteId] dilewati: proses fetch lain masih berjalan";
    }
    $yt = new YouTube((string)cfg('youtube.api_key'));
    try {
        (new Fetcher($yt))->run();
    } catch (QuotaExceeded $e) {
        logmsg('Berhenti, kuota habis: ' . $e->getMessage());
    } catch (Throwable $e) {
        logmsg('ERROR: ' . $e->getMessage());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    cache_clear();
    $msg = 'Selesai. Unit API run ini: ' . $yt->units() . ' | total hari ini (key ini): ' . quota_used();
    logmsg($msg);
    return "[$siteId] $msg";
}

/** Tulis deskripsi AI untuk satu situs. Return jumlah deskripsi. */
function run_enrich_site(string $siteId): int
{
    $site = Site::activate($siteId);
    if (!cfg('ai.enabled') || trim((string)cfg('ai.api_key', '')) === '') {
        return 0;
    }
    $lock = fopen($site->dir() . '/enrich.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
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
    return $done;
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
