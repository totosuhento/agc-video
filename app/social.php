<?php
declare(strict_types=1);

/* ================= Tombol share (tanpa script pihak ketiga, tidak memperlambat halaman) ================= */

const SHARE_NETWORKS = [
    'whatsapp' => ['WhatsApp', 'https://wa.me/?text={text}%20{url}'],
    'telegram' => ['Telegram', 'https://t.me/share/url?url={url}&text={text}'],
    'facebook' => ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u={url}'],
    'x' => ['X', 'https://x.com/intent/post?url={url}&text={text}'],
    'pinterest' => ['Pinterest', 'https://pinterest.com/pin/create/button/?url={url}&media={image}&description={text}'],
    'reddit' => ['Reddit', 'https://www.reddit.com/submit?url={url}&title={text}'],
    'linkedin' => ['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url={url}'],
    'line' => ['LINE', 'https://social-plugins.line.me/lineit/share?url={url}'],
    'vk' => ['VK', 'https://vk.com/share.php?url={url}&title={text}'],
    'email' => ['Email', 'mailto:?subject={text}&body={url}'],
];

function share_buttons(string $pageUrl, string $title, string $image = ''): string
{
    if (!cfg('share.enabled', true)) {
        return '';
    }
    $rep = ['{url}' => rawurlencode($pageUrl), '{text}' => rawurlencode($title), '{image}' => rawurlencode($image)];
    $out = '<div class="share" aria-label="' . e(t('share')) . '"><span class="share-label">' . e(t('share')) . ':</span>';
    $out .= '<button type="button" class="share-btn" data-native-share data-url="' . e($pageUrl) . '" data-title="' . e($title) . '">↗ ' . e(t('share')) . '</button>';
    foreach ((array)cfg('share.networks', []) as $n) {
        if (!isset(SHARE_NETWORKS[$n]) || ($n === 'pinterest' && $image === '')) {
            continue;
        }
        [$label, $tpl] = SHARE_NETWORKS[$n];
        $out .= '<a class="share-btn share-' . e($n) . '" href="' . e(strtr($tpl, $rep)) . '" target="_blank" rel="noopener nofollow">' . e($label) . '</a>';
    }
    $out .= '<button type="button" class="share-btn" data-copy="' . e($pageUrl) . '" data-done="' . e(t('link_copied')) . '">' . e(t('copy_link')) . '</button>';
    return $out . '</div>';
}

/* ================= Auto-post Telegram ================= */

function telegram_api(string $method, array $params): array
{
    $token = trim((string)cfg('social.telegram_token', ''));
    if (!preg_match('/^\d+:[\w-]{20,}$/', $token)) {
        throw new RuntimeException('Token bot Telegram tidak valid');
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query($params),
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('cURL: ' . $err);
    }
    $res = json_decode((string)$raw, true);
    if (!is_array($res) || empty($res['ok'])) {
        throw new RuntimeException('Telegram: ' . ($res['description'] ?? substr((string)$raw, 0, 200)));
    }
    return $res;
}

/** Kirim video & artikel baru ke channel Telegram (hanya yang muncul setelah fitur diaktifkan). */
function social_autopost(): void
{
    if (!cfg('social.telegram_enabled') || trim((string)cfg('social.telegram_chat', '')) === '') {
        return;
    }
    $since = meta_get('tg_since');
    if ($since === null) {
        meta_set('tg_since', now());
        return;
    }
    $limit = max(1, min(30, (int)cfg('social.telegram_per_run', 5)));
    $chat = trim((string)cfg('social.telegram_chat'));
    $sent = 0;
    try {
        $arts = q("SELECT id, slug, title, excerpt, cover FROM articles WHERE status = 'published' AND posted_at IS NULL
                   AND published_at >= ? ORDER BY published_at ASC LIMIT ?", [$since, $limit])->fetchAll();
        foreach ($arts as $a) {
            $link = url('article/' . $a['slug']);
            telegram_api('sendMessage', ['chat_id' => $chat, 'parse_mode' => 'HTML',
                'text' => '<b>' . e($a['title']) . "</b>\n\n" . e(excerpt((string)$a['excerpt'], 300)) . "\n\n" . $link]);
            q('UPDATE articles SET posted_at = ? WHERE id = ?', [now(), $a['id']]);
            $sent++;
        }
        $vids = q('SELECT ' . VIDEO_COLS . " FROM videos v WHERE v.is_active = 1 AND v.posted_at IS NULL AND v.created_at >= ?
                   ORDER BY v.published_at ASC LIMIT ?", [$since, max(0, $limit - $sent)])->fetchAll();
        foreach ($vids as $v) {
            $link = url('video/' . $v['slug']);
            $caption = '<b>' . e($v['title']) . '</b>' . "\n" . e((string)$v['channel_title']) . "\n\n" . $link;
            $thumb = video_thumb($v, 'hqdefault');
            if ($thumb !== '') {
                telegram_api('sendPhoto', ['chat_id' => $chat, 'photo' => $thumb, 'caption' => $caption, 'parse_mode' => 'HTML']);
            } else {
                telegram_api('sendMessage', ['chat_id' => $chat, 'text' => $caption, 'parse_mode' => 'HTML']);
            }
            q('UPDATE videos SET posted_at = ? WHERE id = ?', [now(), $v['id']]);
            $sent++;
        }
    } catch (Throwable $e) {
        logmsg('Telegram gagal: ' . $e->getMessage());
    }
    if ($sent) {
        logmsg("Telegram: $sent posting terkirim");
    }
}

/* ================= Push notification (service worker) ================= */

/** Nama file service worker yang diizinkan, mis. sw.js atau OneSignalSDKWorker.js */
function push_sw_name(): string
{
    $n = trim((string)cfg('push.sw_name', ''));
    return preg_match('/^[A-Za-z0-9_.-]{1,60}\.js$/', $n) ? $n : '';
}
