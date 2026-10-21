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
        'topics' => topics_with_videos(), 'articles' => articles_list(1, 3)[0]]);
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
                'thumbnailUrl' => array_values(array_unique(array_filter([(string)$v['thumb'], video_thumb($v, 'hqdefault')]))),
                'uploadDate' => date('c', strtotime($v['published_at'] . ' UTC')),
                'duration' => seconds_to_iso((int)$v['duration']),
                'embedUrl' => video_embed_url($v),
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
        'articles' => articles_related($v['title'] . ' ' . ($series['title'] ?? ''), 3),
    ]);
    return html($content, [
        'title' => $v['title'] . ' | ' . cfg('site.name'),
        'description' => $metaDesc,
        'canonical' => url('video/' . $v['slug']),
        'image' => (video_source($v) === 'youtube' ? ($v['thumb'] ?: yt_thumb($v['id'])) : (string)$v['thumb']) ?: null,
        'og_type' => 'video.other',
        'hreflang_video' => $v['id'],
        'noindex' => !video_indexable($v),
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

function page_articles(): array
{
    $page = current_page();
    [$items, $total] = articles_list($page, 12);
    if (!$items && $page > 1) {
        return not_found();
    }
    return html(view('articles', ['items' => $items, 'page' => $page, 'pages' => (int)ceil($total / 12)]), [
        'title' => t('articles') . ($page > 1 ? ' – ' . t('page_n', $page) : '') . ' | ' . cfg('site.name'),
        'description' => t('articles_meta', cfg('site.name')),
        'canonical' => url('articles') . ($page > 1 ? '?page=' . $page : ''),
    ]);
}

function page_article(string $slug): array
{
    $a = article_by_slug($slug);
    if (!$a) {
        return not_found();
    }
    $related = trim((string)$a['keyword']) !== '' ? videos_search((string)$a['keyword'], 1, 8)[0] : [];
    $more = articles_related($a['title'] . ' ' . $a['keyword'], 3, $slug);
    $desc = excerpt(trim((string)$a['excerpt']) ?: (string)preg_replace('/[#*\[\]>]/', '', (string)$a['body']), 158);
    $image = (string)($a['cover'] ?: ($related ? ($related[0]['thumb'] ?: video_thumb($related[0], 'hqdefault')) : ''));
    $schema = [
        '@context' => 'https://schema.org',
        '@graph' => [
            array_filter([
                '@type' => 'BlogPosting', 'headline' => $a['title'], 'description' => $desc,
                'image' => $image ?: null,
                'datePublished' => date('c', strtotime($a['published_at'] . ' UTC')),
                'dateModified' => date('c', strtotime($a['updated_at'] . ' UTC')),
                'author' => ['@type' => 'Organization', 'name' => cfg('site.name'), 'url' => url()],
                'publisher' => ['@type' => 'Organization', 'name' => cfg('site.name')],
                'mainEntityOfPage' => url('article/' . $a['slug']),
            ]),
            breadcrumb_schema([[t('home'), url()], [t('articles'), url('articles')], [$a['title'], url('article/' . $a['slug'])]]),
        ],
    ];
    return html(view('article', ['a' => $a, 'related' => $related, 'more' => array_slice($more, 0, 3), 'image' => $image]), [
        'title' => $a['title'] . ' | ' . cfg('site.name'),
        'description' => $desc,
        'canonical' => url('article/' . $a['slug']),
        'image' => $image ?: null,
        'og_type' => 'article',
        'schema' => $schema,
    ]);
}

/** Service worker push notification (OneSignal, Monetag, dll.) */
function push_sw(string $name): array
{
    if ($name !== push_sw_name() || trim((string)cfg('push.sw_code', '')) === '') {
        return not_found();
    }
    header('Service-Worker-Allowed: /');
    return ['status' => 200, 'type' => 'application/javascript; charset=utf-8', 'body' => (string)cfg('push.sw_code')];
}

function indexnow_key_page(string $k): array
{
    return cfg('seo.indexnow', true) && hash_equals(indexnow_key(), $k)
        ? ['status' => 200, 'type' => 'text/plain; charset=utf-8', 'body' => $k]
        : not_found();
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
    $nv = (int)q('SELECT COUNT(*) FROM videos WHERE is_active = 1 AND ' . video_index_sql())->fetchColumn();
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
        if (articles_count() > 0) {
            $urls[] = [url('articles'), null];
            foreach (q("SELECT slug, updated_at FROM articles WHERE status = 'published' AND published_at <= ? ORDER BY published_at DESC LIMIT 5000",
                [now()])->fetchAll() as $r) {
                $urls[] = [url('article/' . $r['slug']), $r['updated_at']];
            }
        }
    } elseif ($kind === 'series') {
        foreach (q('SELECT slug, updated_at FROM series WHERE is_active = 1 AND video_count > 1 ORDER BY published_at DESC LIMIT ? OFFSET ?',
            [SITEMAP_SIZE, $offset])->fetchAll() as $r) {
            $urls[] = [url('series/' . $r['slug']), $r['updated_at']];
        }
    } else {
        // Sitemap video: membantu Google menampilkan halaman ini di tab Video & hasil video
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">';
        $n = 0;
        foreach (q('SELECT id, slug, title, thumb, duration, published_at, updated_at, ai_summary, description, source, embed_url, lang
                    FROM videos WHERE is_active = 1 AND ' . video_index_sql() . ' ORDER BY published_at DESC LIMIT ? OFFSET ?',
            [SITEMAP_SIZE, $offset])->fetchAll() as $r) {
            $n++;
            $thumb = (string)$r['thumb'] ?: video_thumb($r, 'hqdefault');
            $desc = excerpt((string)($r['ai_summary'] ?: clean_description((string)$r['description'])) ?: (string)$r['title'], 2000);
            $player = video_embed_url($r);
            $xml .= '<url><loc>' . e(url('video/' . $r['slug'])) . '</loc><lastmod>' . substr((string)$r['updated_at'], 0, 10) . '</lastmod>';
            if ($thumb !== '' && $player !== '') {
                $xml .= '<video:video><video:thumbnail_loc>' . e($thumb) . '</video:thumbnail_loc>'
                    . '<video:title>' . e(excerpt((string)$r['title'], 100)) . '</video:title>'
                    . '<video:description>' . e($desc) . '</video:description>'
                    . (video_source($r) === 'nasa' ? '<video:content_loc>' . e($player) . '</video:content_loc>' : '<video:player_loc>' . e($player) . '</video:player_loc>')
                    . ((int)$r['duration'] > 0 ? '<video:duration>' . min(28800, (int)$r['duration']) . '</video:duration>' : '')
                    . '<video:publication_date>' . date('c', strtotime($r['published_at'] . ' UTC')) . '</video:publication_date>'
                    . '<video:family_friendly>yes</video:family_friendly></video:video>';
            }
            $xml .= '</url>';
        }
        if (!$n && $page > 1) {
            return not_found();
        }
        return ['status' => 200, 'type' => XML, 'body' => $xml . '</urlset>'];
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

/** RSS: artikel + video baru, dengan gambar besar. Dipakai Pinterest (auto-pin dari RSS), Flipboard, Feedly, Bing. */
function feed_xml(): array
{
    $items = [];
    foreach (q("SELECT slug, title, excerpt, cover, keyword, published_at FROM articles WHERE status = 'published' AND published_at <= ?
                ORDER BY published_at DESC LIMIT 30", [now()])->fetchAll() as $a) {
        $img = (string)$a['cover'];
        if ($img === '' && trim((string)$a['keyword']) !== '' && ($rv = videos_search((string)$a['keyword'], 1, 1)[0])) {
            $img = (string)($rv[0]['thumb'] ?: video_thumb($rv[0], 'hqdefault'));
        }
        $items[] = ['t' => $a['title'], 'u' => url('article/' . $a['slug']), 'd' => (string)$a['excerpt'], 'img' => $img, 'at' => $a['published_at']];
    }
    foreach (q('SELECT id, slug, title, thumb, ai_summary, description, source, created_at FROM videos WHERE is_active = 1 AND ' . video_index_sql()
               . ' ORDER BY created_at DESC LIMIT 30')->fetchAll() as $v) {
        $items[] = ['t' => $v['title'], 'u' => url('video/' . $v['slug']), 'd' => (string)($v['ai_summary'] ?: clean_description((string)$v['description'])),
            'img' => (string)($v['thumb'] ?: video_thumb($v, 'hqdefault')), 'at' => $v['created_at']];
    }
    usort($items, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/"><channel>'
        . '<title>' . e(cfg('site.name')) . '</title><link>' . e(url()) . '</link>'
        . '<description>' . e(t('home_meta', cfg('site.name'))) . '</description><language>' . e(html_lang()) . '</language>'
        . '<atom:link href="' . e(url('feed.xml')) . '" rel="self" type="application/rss+xml"/>';
    foreach (array_slice($items, 0, 40) as $it) {
        $xml .= '<item><title>' . e($it['t']) . '</title><link>' . e($it['u']) . '</link><guid isPermaLink="true">' . e($it['u']) . '</guid>'
            . '<pubDate>' . gmdate(DATE_RSS, (int)strtotime($it['at'] . ' UTC')) . '</pubDate>'
            . '<description>' . e(excerpt($it['d'], 300)) . '</description>'
            . ($it['img'] !== '' ? '<enclosure url="' . e($it['img']) . '" type="image/jpeg" length="0"/><media:content url="' . e($it['img']) . '" medium="image"/>' : '')
            . '</item>';
    }
    return ['status' => 200, 'type' => 'application/rss+xml; charset=utf-8', 'body' => $xml . '</channel></rss>'];
}

function robots_txt(): array
{
    return ['status' => 200, 'type' => 'text/plain; charset=utf-8',
        'body' => "User-agent: *\nDisallow: /search\nDisallow: /admin\nDisallow: /install\nDisallow: /_cron\nAllow: /\n\nSitemap: " . url('sitemap.xml') . "\n"];
}
