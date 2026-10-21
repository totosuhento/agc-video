<?php
/** @var array $v @var string $text @var ?array $series @var array $episodes @var ?array $prev @var ?array $next @var array $related */
$poster = $v['thumb'] ?: yt_thumb($v['id']);
?>
<nav class="crumbs">
  <a href="/"><?= e(t('home')) ?></a> ›
  <?php if ($series): ?><a href="/series/<?= e($series['slug']) ?>"><?= e($series['title']) ?></a> ›<?php endif; ?>
  <span><?= e(excerpt($v['title'], 60)) ?></span>
</nav>

<div class="watch">
  <article class="watch-main">
    <div class="player" data-id="<?= e($v['id']) ?>" data-title="<?= e($v['title']) ?>" role="button" tabindex="0" aria-label="<?= e(t('play')) ?>">
      <img src="<?= e($poster) ?>" alt="<?= e($v['title']) ?>" width="1280" height="720" fetchpriority="high">
      <span class="play-btn" aria-hidden="true"></span>
    </div>

    <?php if ($prev || $next): ?>
    <div class="ep-nav">
      <?php if ($prev): ?><a href="/video/<?= e($prev['slug']) ?>"><?= e(t('prev_ep')) ?></a><?php else: ?><span></span><?php endif; ?>
      <?php if ($next): ?><a class="primary" href="/video/<?= e($next['slug']) ?>"><?= e(t('next_ep')) ?></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?= cta_box() ?>
    <?= ad('below_player') ?>

    <h1><?= e($v['title']) ?></h1>
    <p class="meta">
      <?php if ($v['channel_slug']): ?><a href="/channel/<?= e($v['channel_slug']) ?>"><?= e($v['channel_title']) ?></a><?php else: ?><?= e($v['channel_title']) ?><?php endif; ?>
      · <?= e(t('views', fmt_number((int)$v['views']))) ?>
      · <?= e(t('published', fmt_date($v['published_at']))) ?>
      <?php if ((int)$v['duration']): ?>· <?= e(fmt_duration((int)$v['duration'])) ?><?php endif; ?>
    </p>

    <?php if ($text !== ''): ?>
    <section class="about">
      <h2><?= e(t('about_video')) ?></h2>
      <?= paragraphs($text) ?>
    </section>
    <?php endif; ?>

    <?php if ($v['tags']): ?>
      <p class="tags"><strong><?= e(t('tags')) ?>:</strong> <?= e($v['tags']) ?></p>
    <?php endif; ?>

    <p class="source">
      <span class="badge"><?= e(t('official_channel')) ?></span>
      <a href="https://www.youtube.com/watch?v=<?= e(rawurlencode($v['id'])) ?>" target="_blank" rel="noopener nofollow"><?= e(t('watch_on_youtube')) ?> ↗</a>
    </p>

    <?php if ($series && count($episodes) > 1): ?>
    <section class="block">
      <div class="block-head"><h2><?= e(t('all_episodes')) ?> · <?= e($series['title']) ?></h2></div>
      <ol class="ep-list">
        <?php foreach ($episodes as $i => $ep): ?>
          <li class="<?= $ep['id'] === $v['id'] ? 'cur' : '' ?>">
            <a href="/video/<?= e($ep['slug']) ?>"><span class="n"><?= $i + 1 ?></span> <?= e($ep['title']) ?></a>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>
  </article>

  <aside class="watch-side">
    <?= ad('sidebar') ?>
    <?php if ($related): ?>
      <h2 class="side-h"><?= e(t('related')) ?></h2>
      <div class="side-list">
        <?php foreach ($related as $r): ?><?= view('partials/video_card', ['v' => $r]) ?><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </aside>
</div>
<script>
document.querySelectorAll('.player[role=button]').forEach(function (p) {
  p.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); p.click(); } });
});
</script>
