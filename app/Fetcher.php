<?php
declare(strict_types=1);

/**
 * Sinkronisasi channel resmi → database.
 * Alur per cron: info channel → video baru → backfill video lama → playlist (series) → refresh data lama.
 */
final class Fetcher
{
    /** @var array<string, true> */
    private array $allowed = [];

    public function __construct(private YouTube $yt) {}

    /** @return array{allowed: string[], failed: int} */
    public function run(): array
    {
        $configured = array_values(array_filter(array_map('trim', (array)cfg('youtube.channels', []))));
        $failed = 0;
        foreach ($configured as $handle) {
            try {
                $ch = $this->syncChannel((string)$handle);
                if ($ch) {
                    $this->allowed[$ch['id']] = true;
                } else {
                    $failed++;
                    logmsg("Channel tidak ditemukan: $handle");
                }
            } catch (QuotaExceeded $e) {
                throw $e;
            } catch (Throwable $e) {
                $failed++;
                logmsg("Gagal ambil channel $handle: " . $e->getMessage());
            }
        }

        foreach (array_keys($this->allowed) as $cid) {
            foreach (['syncUploads', 'syncSeries'] as $step) {
                try {
                    $this->$step($cid);
                } catch (QuotaExceeded $e) {
                    throw $e;
                } catch (Throwable $e) {
                    logmsg("[$cid] $step gagal: " . $e->getMessage());
                }
            }
        }

        $this->refreshOld();
        return ['allowed' => array_keys($this->allowed), 'failed' => $failed];
    }

    private function syncChannel(string $handle): ?array
    {
        $isId = (bool)preg_match('/^UC[\w-]{22}$/', $handle);
        $row = $isId
            ? q('SELECT * FROM channels WHERE id = ?', [$handle])->fetch()
            : q('SELECT * FROM channels WHERE lower(handle) = lower(?)', ['@' . ltrim($handle, '@')])->fetch();
        if ($row && strtotime($row['updated_at'] . ' UTC') > time() - 86400) {
            return $row;
        }

        $item = $this->yt->channel($handle);
        if (!$item) {
            return null;
        }
        $sn = $item['snippet'];
        $th = $sn['thumbnails'] ?? [];
        q('INSERT INTO channels (id, handle, title, slug, description, thumb, uploads_playlist, subscribers, updated_at, source, url)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'youtube\', \'https://www.youtube.com/channel/\' || ?)
           ON CONFLICT(id) DO UPDATE SET handle = excluded.handle, title = excluded.title, description = excluded.description,
             thumb = excluded.thumb, uploads_playlist = excluded.uploads_playlist, subscribers = excluded.subscribers,
             updated_at = excluded.updated_at', [
            $item['id'],
            $isId ? ($sn['customUrl'] ?? null) : '@' . ltrim($handle, '@'),
            $sn['title'],
            make_slug($sn['title'], $item['id']),
            $sn['description'] ?? '',
            $th['high']['url'] ?? $th['medium']['url'] ?? $th['default']['url'] ?? '',
            $item['contentDetails']['relatedPlaylists']['uploads'] ?? '',
            (int)($item['statistics']['subscriberCount'] ?? 0),
            now(),
            $item['id'],
        ]);
        logmsg("Channel diperbarui: {$sn['title']}");
        return q('SELECT * FROM channels WHERE id = ?', [$item['id']])->fetch() ?: null;
    }

    private function syncUploads(string $cid): void
    {
        $ch = q('SELECT * FROM channels WHERE id = ?', [$cid])->fetch();
        if (!$ch || !$ch['uploads_playlist']) {
            return;
        }
        $pl = $ch['uploads_playlist'];
        $first = (int)$ch['backfill_state'] === 0;
        $maxPages = $first ? (int)cfg('youtube.initial_pages', 20) : (int)cfg('youtube.pages_per_run', 2);

        // 1) Video terbaru (uploads selalu urut terbaru → lama)
        $token = null;
        $pages = 0;
        $added = 0;
        do {
            $r = $this->yt->playlistItems($pl, $token);
            $ids = $this->idsFromItems($r['items'] ?? []);
            $new = $this->unknownIds($ids);
            $added += $this->importVideos($new);
            $token = $r['nextPageToken'] ?? null;
            $pages++;
            if (!$first && !$new) {
                break; // semua sudah ada → tidak ada video baru lagi
            }
        } while ($token && $pages < $maxPages);

        if ($first) {
            q('UPDATE channels SET backfill_state = ?, backfill_token = ? WHERE id = ?', [$token ? 1 : 2, $token, $cid]);
            logmsg("[{$ch['title']}] Ambil awal: +$added video");
            return;
        }

        // 2) Backfill video lama sedikit demi sedikit
        if ((int)$ch['backfill_state'] === 1 && $ch['backfill_token']) {
            $token = $ch['backfill_token'];
            $pages = 0;
            try {
                do {
                    $r = $this->yt->playlistItems($pl, $token);
                    $added += $this->importVideos($this->unknownIds($this->idsFromItems($r['items'] ?? [])));
                    $token = $r['nextPageToken'] ?? null;
                } while ($token && ++$pages < (int)cfg('youtube.pages_per_run', 2));
            } catch (QuotaExceeded $e) {
                throw $e;
            } catch (Throwable $e) {
                logmsg("[{$ch['title']}] Backfill dihentikan: " . $e->getMessage());
                $token = null;
            }
            q('UPDATE channels SET backfill_state = ?, backfill_token = ? WHERE id = ?', [$token ? 1 : 2, $token, $cid]);
        }
        logmsg("[{$ch['title']}] Video baru/backfill: +$added");
    }

    private function syncSeries(string $cid): void
    {
        $seen = [];
        $token = null;
        do {
            $r = $this->yt->playlists($cid, $token);
            foreach ($r['items'] ?? [] as $pl) {
                $count = (int)($pl['contentDetails']['itemCount'] ?? 0);
                if ($count < 2) {
                    continue;
                }
                $sn = $pl['snippet'];
                $th = $sn['thumbnails'] ?? [];
                q('INSERT INTO series (id, channel_id, title, slug, description, thumb, item_count, published_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON CONFLICT(id) DO UPDATE SET title = excluded.title, description = excluded.description, thumb = excluded.thumb,
                     item_count = excluded.item_count, is_active = 1, updated_at = excluded.updated_at', [
                    $pl['id'], $cid, $sn['title'], make_slug($sn['title'], $pl['id']), $sn['description'] ?? '',
                    $th['high']['url'] ?? $th['medium']['url'] ?? $th['default']['url'] ?? '',
                    $count, $sn['publishedAt'] ?? null, now(),
                ]);
                $seen[] = $pl['id'];
            }
            $token = $r['nextPageToken'] ?? null;
        } while ($token);

        // Playlist yang sudah dihapus channel → nonaktif
        if ($seen) {
            $in = implode(',', array_fill(0, count($seen), '?'));
            q("UPDATE series SET is_active = 0 WHERE channel_id = ? AND id NOT IN ($in)", [$cid, ...$seen]);
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - 3600 * (int)cfg('youtube.playlist_refresh_hours', 24));
        $todo = q('SELECT id, title FROM series WHERE channel_id = ? AND is_active = 1
                   AND (items_synced_at IS NULL OR items_synced_at < ? OR synced_item_count != item_count)
                   ORDER BY items_synced_at IS NOT NULL, published_at DESC LIMIT ?',
            [$cid, $cutoff, (int)cfg('youtube.series_per_run', 20)])->fetchAll();

        foreach ($todo as $s) {
            $positions = [];
            $token = null;
            $guard = 0;
            do {
                $r = $this->yt->playlistItems($s['id'], $token);
                foreach ($r['items'] ?? [] as $it) {
                    $vid = $it['contentDetails']['videoId'] ?? null;
                    if ($vid && !isset($positions[$vid])) {
                        $positions[$vid] = (int)($it['snippet']['position'] ?? count($positions));
                    }
                }
                $token = $r['nextPageToken'] ?? null;
            } while ($token && ++$guard < 20);

            $this->importVideos($this->unknownIds(array_keys($positions)));

            $pdo = db();
            $pdo->beginTransaction();
            q('DELETE FROM series_videos WHERE series_id = ?', [$s['id']]);
            $ins = $pdo->prepare('INSERT OR IGNORE INTO series_videos (series_id, video_id, position) VALUES (?, ?, ?)');
            foreach ($positions as $vid => $pos) {
                $ins->execute([$s['id'], $vid, $pos]);
            }
            q('UPDATE series SET items_synced_at = ?, synced_item_count = item_count,
                 video_count = (SELECT COUNT(*) FROM series_videos sv JOIN videos v ON v.id = sv.video_id
                                WHERE sv.series_id = series.id AND v.is_active = 1)
               WHERE id = ?', [now(), $s['id']]);
            $pdo->commit();
        }
        if ($todo) {
            logmsg("[$cid] Series disinkronkan: " . count($todo));
        }
    }

    /** Refresh data video tertua (statistik, status). Video yang dihapus/privat → nonaktif. */
    private function refreshOld(): void
    {
        $ids = q("SELECT id FROM videos WHERE is_active = 1 AND source = 'youtube' ORDER BY updated_at ASC LIMIT ?",
            [(int)cfg('youtube.refresh_per_run', 1000)])->fetchAll(PDO::FETCH_COLUMN);
        $off = 0;
        foreach (array_chunk($ids, 50) as $chunk) {
            $found = [];
            foreach ($this->yt->videos($chunk) as $item) {
                $this->saveVideo($item);
                $found[$item['id']] = true;
            }
            foreach ($chunk as $id) {
                if (!isset($found[$id])) {
                    q('UPDATE videos SET is_active = 0, updated_at = ? WHERE id = ?', [now(), $id]);
                    $off++;
                }
            }
        }
        if ($ids) {
            logmsg('Refresh: ' . count($ids) . " video, $off dinonaktifkan");
        }
        if ($off) {
            q('UPDATE series SET video_count = (SELECT COUNT(*) FROM series_videos sv JOIN videos v ON v.id = sv.video_id
                                                WHERE sv.series_id = series.id AND v.is_active = 1)');
        }
    }

    /** Kebijakan YouTube API: data yang tidak dipakai/tidak di-refresh tidak disimpan > 30 hari. */
    public static function purgeInactive(): void
    {
        $cut = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        q('DELETE FROM series_videos WHERE video_id IN (SELECT id FROM videos WHERE is_active = 0 AND updated_at < ?)', [$cut]);
        q('DELETE FROM videos WHERE is_active = 0 AND updated_at < ?', [$cut]);
        q('DELETE FROM series WHERE is_active = 0 AND updated_at < ?', [$cut]);
    }

    private function idsFromItems(array $items): array
    {
        return array_values(array_filter(array_map(fn($i) => $i['contentDetails']['videoId'] ?? null, $items)));
    }

    private function unknownIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $known = q("SELECT id FROM videos WHERE id IN ($in)", $ids)->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff($ids, $known));
    }

    /** @return int jumlah video aktif yang ditambahkan */
    private function importVideos(array $ids): int
    {
        $n = 0;
        foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
            foreach ($this->yt->videos($chunk) as $item) {
                if (!isset($this->allowed[$item['snippet']['channelId'] ?? ''])) {
                    continue; // hanya video milik channel resmi yang didaftarkan
                }
                $n += $this->saveVideo($item) ? 1 : 0;
            }
        }
        return $n;
    }

    /** Simpan/perbarui video. Return true bila video layak tampil. */
    private function saveVideo(array $item): bool
    {
        $sn = $item['snippet'] ?? [];
        $cd = $item['contentDetails'] ?? [];
        $st = $item['status'] ?? [];
        $duration = iso_duration_to_seconds((string)($cd['duration'] ?? ''));

        $active = ($st['privacyStatus'] ?? '') === 'public'
            && ($st['embeddable'] ?? false) === true
            && ($st['uploadStatus'] ?? 'processed') === 'processed'
            && ($sn['liveBroadcastContent'] ?? 'none') === 'none'
            && !isset($cd['contentRating']['ytRating'])          // video 18+ tidak bisa di-embed
            && $duration >= (int)cfg('youtube.min_duration', 0)
            && $duration > 0
            && title_allowed((string)($sn['title'] ?? ''));

        $th = $sn['thumbnails'] ?? [];
        $tags = implode(', ', array_slice((array)($sn['tags'] ?? []), 0, 15));
        q('INSERT INTO videos (id, channel_id, channel_title, title, slug, description, thumb, duration, views, likes, tags,
                               search, published_at, is_active, created_at, updated_at, source, embed_url, source_url)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'youtube\', \'https://www.youtube-nocookie.com/embed/\' || ?, \'https://www.youtube.com/watch?v=\' || ?)
           ON CONFLICT(id) DO UPDATE SET channel_title = excluded.channel_title, title = excluded.title,
             description = excluded.description, thumb = excluded.thumb, duration = excluded.duration,
             views = excluded.views, likes = excluded.likes, tags = excluded.tags, search = excluded.search,
             is_active = excluded.is_active, updated_at = excluded.updated_at', [
            $item['id'], $sn['channelId'] ?? '', $sn['channelTitle'] ?? '', $sn['title'] ?? $item['id'],
            make_slug($sn['title'] ?? $item['id'], $item['id']), $sn['description'] ?? '',
            $th['maxres']['url'] ?? $th['high']['url'] ?? yt_thumb($item['id']),
            $duration, (int)($item['statistics']['viewCount'] ?? 0), (int)($item['statistics']['likeCount'] ?? 0),
            $tags, mb_strtolower(($sn['title'] ?? '') . ' ' . $tags), isset($sn['publishedAt']) ? gmdate('Y-m-d H:i:s', strtotime($sn['publishedAt'])) : now(),
            $active ? 1 : 0, now(), now(), $item['id'], $item['id'],
        ]);
        return $active;
    }
}

/** Filter judul: youtube.include_keywords (minimal satu harus ada) & youtube.exclude_keywords (tidak boleh ada). */
function title_allowed(string $title): bool
{
    $t = mb_strtolower($title);
    $has = static function (array $words) use ($t): bool {
        foreach ($words as $w) {
            $w = mb_strtolower(trim((string)$w));
            if ($w !== '' && str_contains($t, $w)) {
                return true;
            }
        }
        return false;
    };
    $include = array_filter((array)cfg('youtube.include_keywords', []), fn($w) => trim((string)$w) !== '');
    if ($include && !$has($include)) {
        return false;
    }
    return !$has((array)cfg('youtube.exclude_keywords', []));
}
