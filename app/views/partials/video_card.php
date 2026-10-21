<?php /** @var array $v */ ?>
<a class="card" href="/video/<?= e($v['slug']) ?>">
  <span class="thumb">
    <img src="<?= e(yt_thumb($v['id'], 'mqdefault')) ?>" alt="<?= e($v['title']) ?>" loading="lazy" width="320" height="180">
    <?php if ((int)$v['duration'] > 0): ?><span class="dur"><?= e(fmt_duration((int)$v['duration'])) ?></span><?php endif; ?>
    <?php if (isset($v['position'])): ?><span class="ep"><?= e(t('episode_n', (int)$v['position'] + 1)) ?></span><?php endif; ?>
  </span>
  <span class="card-title"><?= e($v['title']) ?></span>
  <span class="card-meta"><?= e($v['channel_title']) ?> · <?= e(t('views', fmt_number((int)$v['views']))) ?></span>
</a>
