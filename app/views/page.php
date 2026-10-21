<?php /** @var string $name */ ?>
<article class="static">
  <h1><?= e(t('page_' . $name)) ?></h1>
  <?= t('page_' . $name . '_body', ...legal_args()) ?>
</article>
