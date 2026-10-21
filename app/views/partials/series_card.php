<?php /** @var array $s */ ?>
<a class="card card-series" href="/series/<?= e($s['slug']) ?>">
  <span class="thumb">
    <?php if (!empty($s['thumb'])): ?><img src="<?= e($s['thumb']) ?>" alt="<?= e($s['title']) ?>" loading="lazy" width="320" height="180"><?php endif; ?>
    <span class="stack" aria-hidden="true"></span>
    <span class="dur"><?= e(t('episodes', (int)$s['video_count'])) ?></span>
  </span>
  <span class="card-title"><?= e($s['title']) ?></span>
</a>
