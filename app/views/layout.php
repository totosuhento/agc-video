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
?><!doctype html>
<html lang="<?= e(html_lang()) ?>" data-theme="<?= $theme ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<?php if ($noindex): ?><meta name="robots" content="noindex, follow">
<?php endif; ?>
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
    <p class="muted small"><?= e(t('footer_note')) ?></p>
    <p class="muted small">© <?= date('Y') ?> <?= e(cfg('site.name')) ?></p>
  </div>
</footer>
<script>
document.querySelectorAll('.player[data-id]').forEach(function (p) {
  p.addEventListener('click', function () {
    var f = document.createElement('iframe');
    f.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(p.dataset.id) + '?autoplay=1&rel=0&playsinline=1';
    f.title = p.dataset.title || '';
    f.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
    f.allowFullscreen = true;
    f.referrerPolicy = 'strict-origin-when-cross-origin';
    p.innerHTML = '';
    p.appendChild(f);
    p.classList.add('is-playing');
  }, { once: true });
});
</script>
<?= ad_allowed('body_end') ? (string)cfg('ads.body_end', '') : '' ?>
</body>
</html>
