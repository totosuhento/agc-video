<?php /** @var array $latest @var array $popular @var array $series @var array $channels @var array $topics */
$intro = trim((string)cfg('site.intro', '')); ?>
<section class="hero">
  <h1><?= e(cfg('site.name')) ?></h1>
  <p><?= e(tagline()) ?></p>
  <?php if ($intro !== ''): ?><div class="intro"><?= paragraphs($intro) ?></div><?php endif; ?>
  <?php if ($topics): ?>
  <nav class="chips topic-chips" aria-label="<?= e(t('topics')) ?>">
    <?php foreach ($topics as $slug => $kw): ?><a class="chip" href="/topic/<?= e($slug) ?>"><?= e($kw) ?></a><?php endforeach; ?>
  </nav>
  <?php endif; ?>
</section>

<?php if (!$latest): ?>
  <div class="empty"><p><?= e(t('no_results')) ?></p></div>
<?php endif; ?>

<?php if ($latest): ?>
<section class="block">
  <div class="block-head"><h2><?= e(t('latest_videos')) ?></h2><a href="/latest"><?= e(t('view_all')) ?> →</a></div>
  <?= view('partials/grid', ['items' => $latest, 'kind' => 'video']) ?>
</section>
<?php endif; ?>

<?php if (!empty($articles)): ?>
<section class="block">
  <div class="block-head"><h2><?= e(t('latest_articles')) ?></h2><a href="/articles"><?= e(t('view_all')) ?> →</a></div>
  <div class="article-list"><?php foreach ($articles as $a): ?><?= view('partials/article_card', ['a' => $a]) ?><?php endforeach; ?></div>
</section>
<?php endif; ?>

<?php if ($series): ?>
<section class="block">
  <div class="block-head"><h2><?= e(t('all_series')) ?></h2><a href="/series"><?= e(t('view_all')) ?> →</a></div>
  <?= view('partials/grid', ['items' => $series, 'kind' => 'series']) ?>
</section>
<?php endif; ?>

<?php if ($popular): ?>
<section class="block">
  <div class="block-head"><h2><?= e(t('most_watched')) ?></h2><a href="/popular"><?= e(t('view_all')) ?> →</a></div>
  <?= view('partials/grid', ['items' => $popular, 'kind' => 'video']) ?>
</section>
<?php endif; ?>

<?php if ($channels): ?>
<section class="block">
  <div class="block-head"><h2><?= e(t('channels')) ?></h2></div>
  <div class="chips">
    <?php foreach ($channels as $c): ?>
      <a class="chip" href="/channel/<?= e($c['slug']) ?>">
        <?php if ($c['thumb']): ?><img src="<?= e($c['thumb']) ?>" alt="" width="28" height="28" loading="lazy"><?php endif; ?>
        <?= e($c['title']) ?> <span class="muted"><?= e(t('videos_count', (int)$c['video_count'])) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
