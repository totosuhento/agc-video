<?php
/** @var string $heading @var array $items @var string $kind @var int $page @var int $pages @var string $base */
$channel ??= null;
?>
<?php if ($channel): ?>
<header class="channel-head">
  <?php if ($channel['thumb']): ?><img src="<?= e($channel['thumb']) ?>" alt="" width="72" height="72"><?php endif; ?>
  <div>
    <h1><?= e($heading) ?></h1>
    <p class="muted"><span class="badge"><?= e(t('official_channel')) ?></span>
      <a href="https://www.youtube.com/channel/<?= e(rawurlencode($channel['id'])) ?>" target="_blank" rel="noopener nofollow">YouTube ↗</a></p>
  </div>
</header>
<?php else: ?>
<h1 class="page-h"><?= e($heading) ?><?php if ($page > 1): ?> <small class="muted">· <?= e(t('page_n', $page)) ?></small><?php endif; ?></h1>
<?php endif; ?>

<?php if ($items): ?>
  <?= view('partials/grid', ['items' => $items, 'kind' => $kind]) ?>
  <?= view('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => $base]) ?>
<?php else: ?>
  <div class="empty"><p><?= e(t('no_results')) ?></p></div>
<?php endif; ?>
