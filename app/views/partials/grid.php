<?php
/** @var array $items  @var string $kind ('video'|'series') */
$adEvery = 8;
?>
<div class="grid">
<?php foreach ($items as $i => $item): ?>
  <?= view('partials/' . ($kind === 'series' ? 'series_card' : 'video_card'), $kind === 'series' ? ['s' => $item] : ['v' => $item]) ?>
  <?php if ($i + 1 === $adEvery && count($items) > $adEvery && cfg('ads.in_list')): ?>
    <div class="grid-ad"><?= ad('in_list') ?></div>
  <?php endif; ?>
<?php endforeach; ?>
</div>
