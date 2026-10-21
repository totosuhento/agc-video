<?php
declare(strict_types=1);

const HTML = 'text/html; charset=utf-8';
const XML = 'application/xml; charset=utf-8';
const SITEMAP_SIZE = 5000;

function html(string $content, array $layout, int $status = 200): array
{
    return ['status' => $status, 'type' => HTML, 'body' => view('layout', ['content' => $content] + $layout)];
}

function current_page(): int
{
    return max(1, min(10000, (int)($_GET['page'] ?? 1)));
}

function per_page(): int
{
    return max(6, (int)cfg('site.per_page', 24));
}

function not_found(): array
{
    return html(view('404'), ['title' => t('not_found'), 'noindex' => true], 404);
}

function page_home(): array
{
    [$latest] = videos_list('latest', 1, 12);
    [$popular] = videos_list('popular', 1, 12);
    [$series] = series_list(1, 8);
    $content = view('home', ['latest' => $latest, 'popular' => $popular, 'series' => $series, 'channels' => channels_all(),
        'topics' => topics_with_videos()]);
    $intro = trim((string)cfg('site.intro', ''));
    return html($content, [
        'title' => cfg('site.name') . ' – ' . tagline(),
        'description' => $intro !== '' ? excerpt($intro, 158) : t('home_meta', cfg('site.name')),
        'canonical' => url(),
        'schema' => [
            '@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => cfg('site.name'), 'url' => url(),
            'potentialAction' => ['@type' => 'SearchAction', 'target' => url('search') . '?q={search_term_string}',
                'query-input' => 'required name=search_term_string'],
        ],
    ]);
}

function page_video(string $slug): array
{
    $v = video_by_slug($slug);
    if (!$v) {
        return not_found();
    }
    $series = series_for_video($v['id']);
    $episodes = $series ? series_videos($series['id']) : [];
    $prev = $next = null;
    foreach ($episodes as $i => $ep) {
        if ($ep['id'] === $v['id']) {
            $prev = $episodes[$i - 1] ?? null;
            $next = $episodes[$i + 1] ?? null;
            break;
        }
    }
    $text = $v['ai_summary'] ?: clean_description($v['description']);
    $metaDesc = excerpt($text !== '' ? $text : t('video_meta', $v['title'], $v['channel_title']), 158);

    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'VideoObject',
                'name' => $v['title'],
                'description' => $metaDesc,
                'thumbnailUrl' => [$v['thumb'] ?: yt_thumb($v['id'])],
                'uploadDate' => date('c', strtotime($v['published_at'] . ' UTC')),
                'duration' => seconds_to_iso((int)$v['duration']),
                'embedUrl' => 'https://www.youtube.com/embed/' . $v['id'],
                'interactionStatistic' => ['@type' => 'InteractionCounter',
                    'interactionType' => ['@type' => 'WatchAction'], 'userInteractionCount' => (int)$v['views']],
                'author' => ['@type' => 'Organization', 'name' => $v['channel_title']],
            ],
            breadcrumb_schema(array_filter([
                [t('home'), url()],
                $series ? [$series['title'], url('series/' . $series['slug'])] : null,
                [$v['title'], url('video/' . $v['slug'])],
            ])),
        ],
    ];

    $content = view('video', [
        'v' => $v, 'text' => $text, 'series' => $series, 'episodes' => $episodes,
        'prev' => $prev, 'next' => $next, 'related' => related_videos($v, 12),
    ]);
    return html($content, [
        'title' => $v['title'] . ' | ' . cfg('site.name'),
        'description' => $metaDesc,
        'canonical' => url('video/' . $v['slug']),
        'image' => $v['thumb'] ?: yt_thumb($v['id']),
        'og_type' => 'video.other',
        'schema' => $schema,
    ]);
}

function page_series(string $slug): array
{
    $s = series_by_slug($slug);
    if (!$s) {
        return not_found();
    }
    $episodes = series_videos($s['id']);
    $text = $s['ai_summary'] ?: clean_description($s['description']);
    $metaDesc = excerpt($text !== '' ? $text : t('series_meta', $s['title'], count($episodes)), 158);
    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'ItemList', 'name' => $s['title'],
                'itemListElement' => array_map(fn($ep, $i) => [
                    '@type' => 'ListItem', 'position' => $i + 1, 'url' => url('video/' . $ep['slug']),
                ], $episodes, array_keys($episodes)),
            ],
            breadcrumb_schema([[t('home'), url()], [t('series'), url('series')], [$s['title'], url('series/' . $s['slug'])]]),
        ],
    ];
    return html(view('series', ['s' => $s, 'text' => $text, 'episodes' => $episodes]), [
        'title' => t('series_title', $s['title']) . ' | ' . cfg('site.name'),
        'description' => $metaDesc,
        'canonical' => url('series/' . $s['slug']),
        'image' => $s['thumb'],
        'schema' => $schema,
    ]);
}

function page_series_list(): array
{
    $page = current_page();
    [$items, $total] = series_list($page, per_page());
    if (!$items && $page > 1) {
        return not_found();
    }
    return html(view('list', [
        'heading' => t('all_series'), 'items' => $items, 'kind' => 'series',
        'page' => $page, 'pages' => (int)ceil($total / per_page()), 'base' => url('series'),
    ]), [
        'title' => t('all_series') . ($page > 1 ? ' – ' . t('page_n', $page) : '') . ' | ' . cfg('site.name'),
        'description' => t('series_list_meta', cfg('site.name')),
        'canonical' => url('series') . ($page > 1 ? '?page=' . $page : ''),
    ]);
}

function page_listing(string $which): array
{
    $page = current_page();
    [$items, $total] = videos_list($which, $page, per_page());
    if (!$items && $page > 1) {
        return not_found();
    }
    $heading = t($which === 'popular' ? 'most_watched' : 'latest_videos');
    return html(view('list', [
        'heading' => $heading, 'items' => $items, 'kind' => 'video',
        'page' => $page, 'pages' => (int)ceil($total / per_page()), 'base' => url($which),
    ]), [
        'title' => $heading . ($page > 1 ? ' – ' . t('page_n', $page) : '') . ' | ' . cfg('site.name'),
        'description' => t($which . '_meta', cfg('site.name')),
        'canonical' => url($which) . ($page > 1 ? '?page=' . $page : ''),
    ]);
}

function page_channel(string $slug): array
{
    $c = channel_by_slug($slug);
    if (!$c) {
        return not_found();
    }
    $page = current_page();
    [$items, $total] = videos_list('latest', $page, per_page(), $c['id']);
    return html(view('list', [
        'heading' => $c['title'], 'items' => $items, 'kind' => 'video', 'channel' => $c,
        'page' => $page, 'pages' => (int)ceil($total / per_page()), 'base' => url('channel/' . $c['slug']),
    ]), [
        'title' => t('channel_title', $c['title']) . ($page > 1 ? ' – ' . t('page_n', $page) : '') . ' | ' . cfg('site.name'),
        'description' => t('channel_meta', $c['title']),
        'canonical' => url('channel/' . $c['slug']) . ($page > 1 ? '?page=' . $page : ''),
        'image' => $c['thumb'],
    ]);
}

function page_search(): array
{
    $term = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
    $page = current_page();
    [$items, $total] = $term !== '' ? videos_search($term, $page, per_page()) : [[], 0];
    return html(view('list', [
        'heading' => $term !== '' ? t('search_results', $term) : t('search'), 'items' => $items, 'kind' => 'video',
        'page' => $page, 'pages' => (int)ceil($total / per_page()), 'base' => url('search') . '?q=' . rawurlencode($term),
        'term' => $term,
    ]), ['title' => t('search') . ' | ' . cfg('site.name'), 'noindex' => true]);
}

/** Halaman topik dari keyword situs: /topic/<slug> */
function page_topic(string $slug): array
{
    $topics = site_topics();
    if (!isset($topics[$slug])) {
        return not_found();
    }
    $kw = $topics[$slug];
    $page = current_page();
    [$items, $total] = videos_search($kw, $page, per_page());
    if (!$items) {
        return not_found();
    }
    return html(view('list', [
        'heading' => t('topic_title', $kw), 'items' => $items, 'kind' => 'video',
        'page' => $page, 'pages' => (int)ceil($total / per_page()), 'base' => url('topic/' . $slug),
    ]), [
        'title' => t('topic_title', $kw) . ($page > 1 ? ' – ' . t('page_n', $page) : '') . ' | ' . cfg('site.name'),
        'description' => t('topic_meta', $kw),
        'canonical' => url('topic/' . $slug) . ($page > 1 ? '?page=' . $page : ''),
    ]);
}

/** Topik yang punya minimal satu video (untuk menu & sitemap). @return array<string, string> */
function topics_with_videos(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $memo = [];
    foreach (site_topics() as $slug => $kw) {
        if (videos_search($kw, 1, 1)[1] > 0) {
            $memo[$slug] = $kw;
        }
    }
    return $memo;
}

function ads_txt_page(): array
{
    return ['status' => 200, 'type' => 'text/plain; charset=utf-8', 'body' => ads_txt()];
}

function page_static(string $name): array
{
    if ($name === 'impressum' && !show_impressum()) {
        return not_found();
    }
    return html(view('page', ['name' => $name]), [
        'title' => t('page_' . $name) . ' | ' . cfg('site.name'),
        'canonical' => url($name),
        'description' => excerpt(html_entity_decode((string)preg_replace('/<[^>]+>/', ' ', t('page_' . $name . '_body', ...legal_args()))), 158),
    ]);
}

function breadcrumb_schema(array $crumbs): array
{
    return [
        '@type' => 'BreadcrumbList',
        'itemListElement' => array_map(fn($c, $i) => [
            '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1],
        ], array_values($crumbs), array_keys(array_values($crumbs))),
    ];
}

/* ---------- Sitemap & robots ---------- */

function sitemap_index(): array
{
    $nv = (int)q('SELECT COUNT(*) FROM videos WHERE is_active = 1')->fetchColumn();
    $ns = (int)q('SELECT COUNT(*) FROM series WHERE is_active = 1 AND video_count > 1')->fetchColumn();
    $parts = ['sitemap-pages-1.xml'];
    for ($i = 1; $i <= max(1, (int)ceil($ns / SITEMAP_SIZE)); $i++) {
        $parts[] = "sitemap-series-$i.xml";
    }
    for ($i = 1; $i <= max(1, (int)ceil($nv / SITEMAP_SIZE)); $i++) {
        $parts[] = "sitemap-videos-$i.xml";
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($parts as $p) {
        $xml .= '<sitemap><loc>' . e(url($p)) . '</loc><lastmod>' . gmdate('Y-m-d') . '</lastmod></sitemap>';
    }
    return ['status' => 200, 'type' => XML, 'body' => $xml . '</sitemapindex>'];
}

function sitemap_part(string $kind, string $n): array
{
    $page = max(1, (int)$n);
    $offset = ($page - 1) * SITEMAP_SIZE;
    $urls = [];
    if ($kind === 'pages') {
        foreach (['', 'latest', 'popular', 'series', 'about', 'privacy', 'disclaimer', 'contact', ...(show_impressum() ? ['impressum'] : [])] as $p) {
            $urls[] = [url($p), null];
        }
        foreach (channels_all() as $c) {
            $urls[] = [url('channel/' . $c['slug']), $c['updated_at']];
        }
        foreach (array_keys(topics_with_videos()) as $slug) {
            $urls[] = [url('topic/' . $slug), null];
        }
    } elseif ($kind === 'series') {
        foreach (q('SELECT slug, updated_at FROM series WHERE is_active = 1 AND video_count > 1 ORDER BY published_at DESC LIMIT ? OFFSET ?',
            [SITEMAP_SIZE, $offset])->fetchAll() as $r) {
            $urls[] = [url('series/' . $r['slug']), $r['updated_at']];
        }
    } else {
        foreach (q('SELECT slug, updated_at FROM videos WHERE is_active = 1 ORDER BY published_at DESC LIMIT ? OFFSET ?',
            [SITEMAP_SIZE, $offset])->fetchAll() as $r) {
            $urls[] = [url('video/' . $r['slug']), $r['updated_at']];
        }
    }
    if (!$urls && $page > 1) {
        return not_found();
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as [$loc, $mod]) {
        $xml .= '<url><loc>' . e($loc) . '</loc>' . ($mod ? '<lastmod>' . substr($mod, 0, 10) . '</lastmod>' : '') . '</url>';
    }
    return ['status' => 200, 'type' => XML, 'body' => $xml . '</urlset>'];
}

function robots_txt(): array
{
    return ['status' => 200, 'type' => 'text/plain; charset=utf-8',
        'body' => "User-agent: *\nDisallow: /search\nDisallow: /admin\nDisallow: /install\nDisallow: /_cron\nAllow: /\n\nSitemap: " . url('sitemap.xml') . "\n"];
}
