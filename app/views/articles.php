<?php /** @var array $items @var int $page @var int $pages */ ?>
<h1 class="page-h"><?= e(t('articles')) ?><?php if ($page > 1): ?> <small class="muted">· <?= e(t('page_n', $page)) ?></small><?php endif; ?></h1>
<?php if ($items): ?>
  <div class="article-list">
    <?php foreach ($items as $a): ?><?= view('partials/article_card', ['a' => $a]) ?><?php endforeach; ?>
  </div>
  <?= view('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => url('articles')]) ?>
<?php else: ?>
  <div class="empty"><p><?= e(t('no_results')) ?></p></div>
<?php endif; ?>
