<?php
declare(strict_types=1);

/**
 * Sumber video selain YouTube. Semua memakai API publik tanpa kunci.
 *
 *  dailymotion → video dari satu akun (pakai HANYA akun resmi pemegang hak)
 *  archive     → Internet Archive, otomatis dibatasi ke item berlisensi public domain / CC0
 *  nasa        → NASA Image & Video Library (umumnya bebas hak cipta di AS)
 *  tiktok      → daftar link video TikTok (embed resmi), diisi manual
 *
 * ID video diberi awalan (dm:, ia:, nasa:, tt:) agar tidak bentrok dengan ID YouTube.
 */
class Sources
{
    public const TYPES = [
        'dailymotion' => 'Dailymotion (akun resmi)',
        'archive' => 'Internet Archive (public domain)',
        'nasa' => 'NASA Image & Video Library',
        'tiktok' => 'TikTok (link video, embed resmi)',
    ];
    public const NAMES = ['youtube' => 'YouTube', 'dailymotion' => 'Dailymotion', 'archive' => 'Internet Archive', 'nasa' => 'NASA', 'tiktok' => 'TikTok'];

    /** @var array<string, true> channel_id yang masih dipakai */
    public array $allowed = [];
    public int $failed = 0;
    public int $added = 0;

    public function run(array $sources): void
    {
        foreach ($sources as $src) {
            $type = (string)($src['type'] ?? '');
            $value = trim((string)($src['value'] ?? ''));
            if ($value === '' || !isset(self::TYPES[$type])) {
                continue;
            }
            $doneKey = 'src_done:' . md5($type . '|' . $value);
            $first = meta_get($doneKey) === null;
            try {
                $n = match ($type) {
                    'dailymotion' => $this->dailymotion($value, $first),
                    'archive' => $this->archive($value, $first),
                    'nasa' => $this->nasa($value, $first),
                    'tiktok' => $this->tiktok($value),
                };
                $this->added += $n;
                meta_set($doneKey, now());
                logmsg(self::NAMES[$type] . ' [' . mb_substr($value, 0, 60) . "]: +$n video");
            } catch (Throwable $e) {
                $this->failed++;
                logmsg(self::NAMES[$type] . " [$value] gagal: " . $e->getMessage());
            }
        }
    }

    /* ---------------- Dailymotion ---------------- */

    private function dailymotion(string $user, bool $first): int
    {
        $user = (string)preg_replace('~^https?://(www\.)?dailymotion\.com/~i', '', $user);
        $user = trim(explode('/', $user)[0]);
        if (!preg_match('/^[\w.-]{2,60}$/', $user)) {
            throw new RuntimeException('nama akun Dailymotion tidak valid');
        }
        $info = $this->dmGet('https://api.dailymotion.com/user/' . rawurlencode($user),
            ['id,screenname,username,avatar_120_url,description', 'id,screenname']);
        $cid = 'dm:' . $info['id'];
        $this->allowed[$cid] = true;
        $this->upsertChannel($cid, 'dailymotion', (string)($info['screenname'] ?? $user), 'https://www.dailymotion.com/' . ($info['username'] ?? $user),
            (string)($info['avatar_120_url'] ?? ''), (string)($info['description'] ?? ''));

        $added = 0;
        $pages = $first ? max(1, min(10, (int)cfg('youtube.initial_pages', 5))) : max(1, (int)cfg('youtube.pages_per_run', 2));
        for ($page = 1; $page <= $pages; $page++) {
            $r = $this->dmGet('https://api.dailymotion.com/user/' . rawurlencode((string)$info['id']) . '/videos?sort=recent&limit=100&page=' . $page,
                ['id,title,description,thumbnail_720_url,duration,views_total,created_time,tags,allow_embed,private',
                 'id,title,description,thumbnail_720_url,duration,created_time']);
            foreach ($r['list'] ?? [] as $v) {
                $id = (string)($v['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $added += $this->upsertVideo([
                    'id' => 'dm:' . $id, 'source' => 'dailymotion', 'channel_id' => $cid, 'channel_title' => (string)($info['screenname'] ?? $user),
                    'title' => (string)($v['title'] ?? $id), 'description' => (string)($v['description'] ?? ''),
                    'thumb' => (string)($v['thumbnail_720_url'] ?? ''), 'duration' => (int)($v['duration'] ?? 0),
                    'views' => (int)($v['views_total'] ?? 0), 'tags' => implode(', ', array_slice((array)($v['tags'] ?? []), 0, 15)),
                    'published_at' => isset($v['created_time']) ? gmdate('Y-m-d H:i:s', (int)$v['created_time']) : now(),
                    'embed_url' => 'https://www.dailymotion.com/embed/video/' . rawurlencode($id),
                    'source_url' => 'https://www.dailymotion.com/video/' . rawurlencode($id),
                    'active' => ($v['allow_embed'] ?? true) !== false && empty($v['private']),
                ]);
            }
            if (empty($r['has_more'])) {
                break;
            }
        }
        return $added;
    }

    /** GET Dailymotion; bila sebuah field ditolak API, coba lagi dengan daftar field cadangan. */
    private function dmGet(string $url, array $fieldSets): array
    {
        $last = null;
        foreach ($fieldSets as $fields) {
            try {
                return $this->json($url . (str_contains($url, '?') ? '&' : '?') . 'fields=' . $fields);
            } catch (RuntimeException $e) {
                $last = $e;
                if (!str_contains($e->getMessage(), 'HTTP 400')) {
                    throw $e;
                }
            }
        }
        throw $last ?? new RuntimeException('Dailymotion error');
    }

    /* ---------------- Internet Archive ---------------- */

    private function archive(string $query, bool $first): int
    {
        // Hanya item film dengan lisensi public domain / CC0 (licenseurl memuat "publicdomain")
        $q = '(' . $query . ') AND mediatype:(movies) AND licenseurl:(*publicdomain*)';
        $cid = 'ia:' . substr(md5($query), 0, 10);
        $this->allowed[$cid] = true;
        $this->upsertChannel($cid, 'archive', 'Internet Archive – ' . mb_substr($query, 0, 60),
            'https://archive.org/search?query=' . rawurlencode($query), 'https://archive.org/images/glogo.jpg', $query);

        $added = 0;
        $pages = $first ? 3 : 1;
        for ($page = 1; $page <= $pages; $page++) {
            $qs = http_build_query([
                'q' => $q, 'fl' => ['identifier', 'title', 'description', 'publicdate', 'downloads', 'subject', 'licenseurl'],
                'sort' => ['downloads desc'], 'rows' => 100, 'page' => $page, 'output' => 'json',
            ]);
            $url = 'https://archive.org/advancedsearch.php?' . preg_replace('/%5B\d+%5D=/', '%5B%5D=', $qs);
            $docs = $this->json($url)['response']['docs'] ?? [];
            foreach ($docs as $d) {
                $id = (string)($d['identifier'] ?? '');
                if ($id === '' || !str_contains((string)($d['licenseurl'] ?? ''), 'publicdomain')) {
                    continue;
                }
                $added += $this->upsertVideo([
                    'id' => 'ia:' . $id, 'source' => 'archive', 'channel_id' => $cid, 'channel_title' => 'Internet Archive',
                    'title' => $this->flat($d['title'] ?? $id), 'description' => strip_tags($this->flat($d['description'] ?? '')),
                    'thumb' => 'https://archive.org/services/img/' . rawurlencode($id), 'duration' => 0,
                    'views' => (int)($d['downloads'] ?? 0), 'tags' => implode(', ', array_slice((array)($d['subject'] ?? []), 0, 15)),
                    'published_at' => isset($d['publicdate']) ? gmdate('Y-m-d H:i:s', (int)strtotime((string)$d['publicdate'])) : now(),
                    'embed_url' => 'https://archive.org/embed/' . rawurlencode($id),
                    'source_url' => 'https://archive.org/details/' . rawurlencode($id),
                    'active' => true,
                ]);
            }
            if (count($docs) < 100) {
                break;
            }
        }
        return $added;
    }

    /* ---------------- NASA ---------------- */

    private function nasa(string $query, bool $first): int
    {
        $cid = 'nasa:' . substr(md5($query), 0, 10);
        $this->allowed[$cid] = true;
        $this->upsertChannel($cid, 'nasa', 'NASA – ' . mb_substr($query, 0, 60),
            'https://images.nasa.gov/search?q=' . rawurlencode($query), '', $query);

        $added = 0;
        $pages = $first ? 3 : 1;
        for ($page = 1; $page <= $pages; $page++) {
            $r = $this->json('https://images-api.nasa.gov/search?' . http_build_query(['q' => $query, 'media_type' => 'video', 'page' => $page, 'page_size' => 100]));
            $items = $r['collection']['items'] ?? [];
            foreach ($items as $it) {
                $d = $it['data'][0] ?? [];
                $nid = (string)($d['nasa_id'] ?? '');
                $desc = (string)($d['description'] ?? '');
                // Lewati materi pihak ketiga yang berhak cipta
                if ($nid === '' || preg_match('/©|\bcopyright/i', $desc . ' ' . ($d['secondary_creator'] ?? ''))) {
                    continue;
                }
                $id = 'nasa:' . $nid;
                if ($this->keepExisting($id)) {
                    continue;   // sudah ada; aset NASA tidak berubah
                }
                $mp4 = $this->nasaMp4((string)($it['href'] ?? ''));
                if ($mp4 === '') {
                    continue;
                }
                $thumb = '';
                foreach ($it['links'] ?? [] as $l) {
                    if (($l['render'] ?? '') === 'image' || str_ends_with(strtolower((string)($l['href'] ?? '')), '.jpg')) {
                        $thumb = $this->https((string)$l['href']);
                        break;
                    }
                }
                $added += $this->upsertVideo([
                    'id' => $id, 'source' => 'nasa', 'channel_id' => $cid, 'channel_title' => 'NASA',
                    'title' => (string)($d['title'] ?? $nid), 'description' => strip_tags($desc),
                    'thumb' => $thumb, 'duration' => 0, 'views' => 0,
                    'tags' => implode(', ', array_slice((array)($d['keywords'] ?? []), 0, 15)),
                    'published_at' => isset($d['date_created']) ? gmdate('Y-m-d H:i:s', (int)strtotime((string)$d['date_created'])) : now(),
                    'embed_url' => $mp4, 'source_url' => 'https://images.nasa.gov/details/' . rawurlencode($nid),
                    'active' => true,
                ]);
            }
            if (count($items) < 100) {
                break;
            }
        }
        return $added;
    }

    private function nasaMp4(string $manifestUrl): string
    {
        if ($manifestUrl === '') {
            return '';
        }
        $files = $this->json($this->https($manifestUrl));
        $mp4 = array_values(array_filter(array_map(fn($f) => $this->https((string)$f), $files), fn($f) => str_ends_with(strtolower($f), '.mp4')));
        foreach (['~mobile.mp4', '~medium.mp4', '~small.mp4', '~orig.mp4'] as $pref) {
            foreach ($mp4 as $f) {
                if (str_ends_with($f, $pref)) {
                    return $f;
                }
            }
        }
        return $mp4[0] ?? '';
    }

    /* ---------------- TikTok ---------------- */

    private function tiktok(string $urls): int
    {
        $added = 0;
        foreach (preg_split('/\s+/', $urls) ?: [] as $u) {
            if (!preg_match('~^https?://(www\.)?tiktok\.com/@([\w.]+)/video/(\d+)~i', $u, $m)) {
                continue;
            }
            [$author, $vid] = [$m[2], $m[3]];
            $cid = 'tt:' . strtolower($author);
            $this->allowed[$cid] = true;
            $id = 'tt:' . $vid;
            if ($this->keepExisting($id)) {
                if (!q('SELECT 1 FROM channels WHERE id = ?', [$cid])->fetchColumn()) {
                    $this->upsertChannel($cid, 'tiktok', '@' . $author, 'https://www.tiktok.com/@' . $author, '', '');
                }
                continue;
            }
            $o = $this->json('https://www.tiktok.com/oembed?url=' . rawurlencode('https://www.tiktok.com/@' . $author . '/video/' . $vid));
            $this->upsertChannel($cid, 'tiktok', (string)($o['author_name'] ?? '@' . $author), 'https://www.tiktok.com/@' . $author, '', '');
            $added += $this->upsertVideo([
                'id' => $id, 'source' => 'tiktok', 'channel_id' => $cid, 'channel_title' => (string)($o['author_name'] ?? '@' . $author),
                'title' => (string)($o['title'] ?? 'TikTok ' . $vid) ?: 'TikTok ' . $vid, 'description' => (string)($o['title'] ?? ''),
                'thumb' => (string)($o['thumbnail_url'] ?? ''), 'duration' => 0, 'views' => 0, 'tags' => '',
                'published_at' => now(), 'embed_url' => 'https://www.tiktok.com/embed/v2/' . $vid,
                'source_url' => 'https://www.tiktok.com/@' . $author . '/video/' . $vid, 'active' => true,
            ]);
        }
        return $added;
    }

    /* ---------------- Simpan ---------------- */

    /** Video sudah ada: aktifkan lagi (bila sempat disembunyikan) sesuai filter terbaru. */
    private function keepExisting(string $id): bool
    {
        $row = q('SELECT title, duration FROM videos WHERE id = ?', [$id])->fetch();
        if (!$row) {
            return false;
        }
        $min = (int)cfg('youtube.min_duration', 0);
        $ok = title_allowed((string)$row['title']) && ((int)$row['duration'] === 0 || (int)$row['duration'] >= $min);
        q('UPDATE videos SET is_active = ?, updated_at = ? WHERE id = ?', [$ok ? 1 : 0, now(), $id]);
        return true;
    }

    private function upsertChannel(string $id, string $source, string $title, string $url, string $thumb, string $desc): void
    {
        q('INSERT INTO channels (id, handle, title, slug, description, thumb, uploads_playlist, subscribers, updated_at, source, url, backfill_state)
           VALUES (?, NULL, ?, ?, ?, ?, NULL, 0, ?, ?, ?, 2)
           ON CONFLICT(id) DO UPDATE SET title = excluded.title, description = excluded.description,
             thumb = CASE WHEN excluded.thumb <> \'\' THEN excluded.thumb ELSE channels.thumb END, updated_at = excluded.updated_at, url = excluded.url',
            [$id, $title, make_slug($title, $id), $desc, $thumb, now(), $source, $url]);
    }

    /** @return int 1 bila video baru & tampil */
    private function upsertVideo(array $v): int
    {
        $existed = (bool)q('SELECT 1 FROM videos WHERE id = ?', [$v['id']])->fetchColumn();
        $title = trim(preg_replace('/\s+/u', ' ', (string)$v['title']) ?? '') ?: $v['id'];
        $min = (int)cfg('youtube.min_duration', 0);
        $active = $v['active'] && title_allowed($title) && ($v['duration'] === 0 || $v['duration'] >= $min);
        q('INSERT INTO videos (id, channel_id, channel_title, title, slug, description, thumb, duration, views, likes, tags, search,
                               published_at, is_active, created_at, updated_at, source, embed_url, source_url)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?)
           ON CONFLICT(id) DO UPDATE SET title = excluded.title, description = excluded.description, thumb = excluded.thumb,
             duration = excluded.duration, views = excluded.views, tags = excluded.tags, search = excluded.search,
             is_active = excluded.is_active, updated_at = excluded.updated_at, embed_url = excluded.embed_url', [
            $v['id'], $v['channel_id'], $v['channel_title'], $title, make_slug($title, $v['id']), $v['description'], $v['thumb'],
            $v['duration'], $v['views'], $v['tags'], mb_strtolower($title . ' ' . $v['tags']), $v['published_at'],
            $active ? 1 : 0, now(), now(), $v['source'], $v['embed_url'], $v['source_url'],
        ]);
        return !$existed && $active ? 1 : 0;
    }

    /* ---------------- HTTP ---------------- */

    protected function json(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'AGC-Video/' . AGC_VERSION . ' (+' . cfg('site.url') . ')',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('cURL: ' . $err);
        }
        if ($code >= 400) {
            throw new RuntimeException("HTTP $code: " . substr((string)$body, 0, 200));
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new RuntimeException('respons bukan JSON');
        }
        return $data;
    }

    private function https(string $url): string
    {
        return (string)preg_replace('~^http://~i', 'https://', $url);
    }

    private function flat(mixed $v): string
    {
        return is_array($v) ? implode("\n", array_map('strval', $v)) : (string)$v;
    }
}
