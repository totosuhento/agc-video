<?php
/** @var string $content */
$title ??= (string)cfg('site.name');
$description ??= t('home_meta', cfg('site.name'));
$canonical ??= null;
$image ??= null;
$og_type ??= 'website';
$noindex ??= false;
$schema ??= null;
$theme = cfg('site.theme') === 'light' ? 'light' : 'dark';
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)cfg('site.accent', '')) ? (string)cfg('site.accent') : '';
$topics = function_exists('topics_with_videos') ? topics_with_videos() : [];
$hreflang_video ??= null;
$reqPath = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$alternates = $noindex || !empty($_GET) ? [] : ($hreflang_video !== null
    ? hreflang_links($reqPath, (string)$hreflang_video)
    : (in_array($reqPath, ['/', '/latest', '/popular', '/series', '/articles'], true) ? hreflang_links($reqPath === '/' ? '/' : $reqPath) : []));
$sisters = sister_sites();
$hasArticles = function_exists('articles_count') && articles_count() > 0;
?><!doctype html>
<html lang="<?= e(html_lang()) ?>" data-theme="<?= $theme ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<?php if ($noindex): ?><meta name="robots" content="noindex, follow">
<?php else: ?><meta name="robots" content="index, follow, max-image-preview:large">
<?php endif; ?>
<?php foreach ($alternates as [$hl, $hu]): ?><link rel="alternate" hreflang="<?= e($hl) ?>" href="<?= e($hu) ?>">
<?php endforeach; ?>
<?php if ($canonical): ?><link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<?php endif; ?>
<meta property="og:site_name" content="<?= e(cfg('site.name')) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:type" content="<?= e($og_type) ?>">
<meta property="og:locale" content="<?= e(locale_code()) ?>">
<?php if ($image): ?><meta property="og:image" content="<?= e($image) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
<meta name="theme-color" content="<?= $theme === 'light' ? '#ffffff' : '#120d17' ?>">
<link rel="preconnect" href="https://i.ytimg.com">
<link rel="stylesheet" href="/assets/style.css?v=<?= AGC_VERSION ?>">
<?php if ($accent): ?><style>:root{--accent:<?= $accent ?>}</style>
<?php endif; ?>
<?php if ($schema) echo json_ld($schema), "\n"; ?>
<?= cfg('analytics', '') ?>
<?= adsense_head() ?>
<?= cfg('push.head', '') ?>
<?= cfg('ads.head', '') ?>
</head>
<body>
<header class="top">
  <div class="wrap top-in">
    <a class="logo" href="/"><span class="logo-mark" aria-hidden="true">▶</span><?= e(cfg('site.name')) ?></a>
    <form class="search" action="/search" method="get" role="search">
      <input type="search" name="q" placeholder="<?= e(t('search_placeholder')) ?>" value="<?= e($_GET['q'] ?? '') ?>" aria-label="<?= e(t('search')) ?>">
    </form>
    <nav class="nav">
      <a href="/latest"><?= e(t('latest')) ?></a>
      <a href="/popular"><?= e(t('popular')) ?></a>
      <a href="/series"><?= e(t('series')) ?></a>
      <?php if ($hasArticles): ?><a href="/articles"><?= e(t('articles')) ?></a><?php endif; ?>
    </nav>
  </div>
</header>
<main class="wrap">
<?= ad('top') ?>
<?= $content ?>
</main>
<footer class="foot">
  <div class="wrap">
    <?= ad('footer') ?>
    <?php if ($topics): ?>
    <nav class="foot-topics" aria-label="<?= e(t('topics')) ?>">
      <?php foreach ($topics as $slug => $kw): ?><a href="/topic/<?= e($slug) ?>"><?= e($kw) ?></a><?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <nav class="foot-nav">
      <a href="/about"><?= e(t('page_about')) ?></a>
      <a href="/privacy"><?= e(t('page_privacy')) ?></a>
      <a href="/disclaimer"><?= e(t('page_disclaimer')) ?></a>
      <a href="/contact"><?= e(t('page_contact')) ?></a>
      <?php if (show_impressum()): ?><a href="/impressum"><?= e(t('page_impressum')) ?></a><?php endif; ?>
    </nav>
    <?php if ($sisters): ?>
    <nav class="langs" aria-label="<?= e(t('languages')) ?>">🌐
      <?php foreach ($sisters as $sis): ?>
        <?php if ($sis['id'] === Site::$current?->id): ?><b><?= e(LANGS[$sis['lang']] ?? $sis['lang']) ?></b>
        <?php else: ?><a href="<?= e($sis['url']) ?>/" hreflang="<?= e($sis['tag']) ?>" lang="<?= e($sis['tag']) ?>"><?= e(LANGS[$sis['lang']] ?? $sis['lang']) ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <p class="muted small"><?= e(t('footer_note')) ?></p>
    <p class="muted small">© <?= date('Y') ?> <?= e(cfg('site.name')) ?></p>
  </div>
</footer>
<script>
document.querySelectorAll('.player[data-embed]').forEach(function (p) {
  p.addEventListener('click', function () {
    var src = p.dataset.embed, el;
    if (!src) return;
    if (p.dataset.kind === 'mp4') {
      el = document.createElement('video');
      el.src = src; el.controls = true; el.autoplay = true; el.playsInline = true;
      if (p.dataset.poster) el.poster = p.dataset.poster;
    } else {
      el = document.createElement('iframe');
      el.src = src + (src.indexOf('?') < 0 ? '?' : '&') + (src.indexOf('youtube') > -1 ? 'autoplay=1&rel=0&playsinline=1' : 'autoplay=1');
      el.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen';
      el.allowFullscreen = true;
      el.referrerPolicy = 'strict-origin-when-cross-origin';
    }
    el.title = p.dataset.title || '';
    p.innerHTML = '';
    p.appendChild(el);
    p.classList.add('is-playing');
  }, { once: true });
});
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var done = function () { b.textContent = b.dataset.done || 'OK'; };
    if (navigator.clipboard) navigator.clipboard.writeText(b.dataset.copy).then(done); else done();
  });
});
document.querySelectorAll('[data-native-share]').forEach(function (b) {
  if (!navigator.share) { b.remove(); return; }
  b.addEventListener('click', function () { navigator.share({ title: b.dataset.title, url: b.dataset.url }).catch(function () {}); });
});
</script>
<?= ad_allowed('body_end') ? (string)cfg('ads.body_end', '') : '' ?>
</body>
</html>
