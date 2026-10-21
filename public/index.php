<?php
declare(strict_types=1);

// Server bawaan PHP (php -S) untuk uji lokal: biarkan file statis dilayani langsung
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require ROOT . '/app/controllers.php';

$path = '/' . trim(rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')), '/');

try {
    /* ---------- Instalasi & panel admin ---------- */
    if ($path === '/install' || str_starts_with($path, '/install/') || $path === '/admin' || str_starts_with($path, '/admin/')) {
        require ROOT . '/app/presets.php';
        require ROOT . '/app/admin.php';
        admin_dispatch($path);
        exit;
    }
    if (!is_installed()) {
        header('Location: /install', true, 302);
        exit;
    }

    /* ---------- Cron lewat URL (untuk hosting tanpa Cron Jobs) ---------- */
    if (preg_match('#^/_cron/([a-f0-9]{24})$#', $path, $m)) {
        require ROOT . '/app/cron.php';
        if (!hash_equals(cron_key(), $m[1])) {
            http_response_code(403);
            exit('forbidden');
        }
        header('Content-Type: text/plain; charset=utf-8');
        ignore_user_abort(true);
        @set_time_limit(0);
        run_fetch_all(isset($_GET['site']) ? (string)$_GET['site'] : null, isset($_GET['enrich']));
        exit;
    }

    /* ---------- Pilih situs dari domain ---------- */
    $siteId = site_id_for_host(request_host());
    if ($siteId === null) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Not configured</title>'
            . '<body style="font-family:system-ui;padding:40px;color:#333"><h1>' . e(request_host()) . '</h1>'
            . '<p>This website is not set up yet.</p></body>';
        exit;
    }
    Site::activate($siteId);
} catch (Throwable $e) {
    error_log('[agc] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Server error. Cek izin tulis folder storage/ dan log PHP.');
}

$isGet = in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true);
$cacheable = $isGet && $path !== '/search';
$key = md5($path . '?' . ($_SERVER['QUERY_STRING'] ?? ''));

if ($cacheable && ($hit = cache_get($key)) !== null) {
    header('Content-Type: ' . $hit['type']);
    header('X-Cache: HIT');
    echo $hit['body'];
    exit;
}

$routes = [
    '#^/$#'                                   => 'page_home',
    '#^/video/([a-z0-9-]{1,120})$#'           => 'page_video',
    '#^/series/([a-z0-9-]{1,120})$#'          => 'page_series',
    '#^/series$#'                             => 'page_series_list',
    '#^/channel/([a-z0-9-]{1,120})$#'         => 'page_channel',
    '#^/topic/([a-z0-9-]{1,80})$#'            => 'page_topic',
    '#^/(latest|popular)$#'                   => 'page_listing',
    '#^/search$#'                             => 'page_search',
    '#^/(about|privacy|disclaimer|contact|impressum)$#' => 'page_static',
    '#^/sitemap\.xml$#'                       => 'sitemap_index',
    '#^/sitemap-(pages|series|videos)-(\d+)\.xml$#' => 'sitemap_part',
    '#^/robots\.txt$#'                        => 'robots_txt',
    '#^/ads\.txt$#'                           => 'ads_txt_page',
    '#^/articles$#'                           => 'page_articles',
    '#^/article/([a-z0-9-]{1,120})$#'         => 'page_article',
    '#^/([A-Za-z0-9_.-]{1,60}\.js)$#'         => 'push_sw',
    '#^/([a-f0-9]{32})\.txt$#'                => 'indexnow_key_page',
];

$res = null;
try {
    foreach ($routes as $re => $handler) {
        if (preg_match($re, $path, $m)) {
            $res = $handler(...array_slice($m, 1));
            break;
        }
    }
    $res ??= not_found();
} catch (Throwable $e) {
    error_log('[agc] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $res = ['status' => 500, 'type' => 'text/html; charset=utf-8', 'body' => view('layout', [
        'title' => t('error_title'), 'content' => '<div class="empty"><h1>' . e(t('error_title')) . '</h1></div>', 'noindex' => true,
    ])];
}

http_response_code($res['status'] ?? 200);
header('Content-Type: ' . $res['type']);
if (($res['status'] ?? 200) === 200 && $cacheable) {
    cache_put($key, $res['type'], $res['body']);
}
echo $res['body'];
