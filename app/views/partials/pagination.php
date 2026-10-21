<?php
/** @var int $page @var int $pages @var string $base */
if ($pages <= 1) return;
$sep = str_contains($base, '?') ? '&' : '?';
$link = fn(int $p) => $p === 1 ? $base : $base . $sep . 'page=' . $p;
$from = max(1, $page - 2);
$to = min($pages, $page + 2);
?>
<nav class="pager" aria-label="pagination">
  <?php if ($page > 1): ?><a rel="prev" href="<?= e($link($page - 1)) ?>">‹ <?= e(t('prev')) ?></a><?php endif; ?>
  <?php for ($p = $from; $p <= $to; $p++): ?>
    <?php if ($p === $page): ?><span class="cur"><?= $p ?></span><?php else: ?><a href="<?= e($link($p)) ?>"><?= $p ?></a><?php endif; ?>
  <?php endfor; ?>
  <?php if ($page < $pages): ?><a rel="next" href="<?= e($link($page + 1)) ?>"><?= e(t('next')) ?> ›</a><?php endif; ?>
</nav>
