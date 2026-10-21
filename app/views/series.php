<?php /** @var array $s @var string $text @var array $episodes */ ?>
<nav class="crumbs"><a href="/"><?= e(t('home')) ?></a> › <a href="/series"><?= e(t('series')) ?></a> › <span><?= e(excerpt($s['title'], 60)) ?></span></nav>

<header class="series-head">
  <?php if ($s['thumb']): ?><img src="<?= e($s['thumb']) ?>" alt="<?= e($s['title']) ?>" width="480" height="270"><?php endif; ?>
  <div>
    <h1><?= e(t('series_title', $s['title'])) ?></h1>
    <p class="meta">
      <?php if ($s['channel_slug']): ?><a href="/channel/<?= e($s['channel_slug']) ?>"><?= e($s['channel_title']) ?></a> · <?php endif; ?>
      <?= e(t('episodes', count($episodes))) ?>
    </p>
    <?php if ($episodes): ?><a class="btn" href="/video/<?= e($episodes[0]['slug']) ?>">▶ <?= e(t('play_first')) ?></a><?php endif; ?>
  </div>
</header>

<?php if ($text !== ''): ?><section class="about"><?= paragraphs($text) ?></section><?php endif; ?>

<section class="block">
  <div class="block-head"><h2><?= e(t('all_episodes')) ?></h2></div>
  <?= view('partials/grid', ['items' => array_map(fn($ep, $i) => ['position' => $i] + $ep, $episodes, array_keys($episodes)), 'kind' => 'video']) ?>
</section>
