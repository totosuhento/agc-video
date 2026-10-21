<?php /** @var array $a */ ?>
<a class="acard" href="/article/<?= e($a['slug']) ?>">
  <?php if (!empty($a['cover'])): ?><img src="<?= e($a['cover']) ?>" alt="<?= e($a['title']) ?>" loading="lazy" width="400" height="225"><?php endif; ?>
  <span class="acard-body">
    <span class="acard-title"><?= e($a['title']) ?></span>
    <?php if (!empty($a['excerpt'])): ?><span class="acard-ex"><?= e(excerpt((string)$a['excerpt'], 150)) ?></span><?php endif; ?>
    <span class="card-meta"><?= e(fmt_date($a['published_at'])) ?> · <?= e(t('read_more')) ?> →</span>
  </span>
</a>
