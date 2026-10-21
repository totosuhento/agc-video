<?php /** @var string $name */ ?>
<article class="static">
  <h1><?= e(t('page_' . $name)) ?></h1>
  <?= t('page_' . $name . '_body', ...legal_args()) ?>
  <?php if ($name === 'privacy' && site_uses_other_sources()): ?><?= t('privacy_other') ?><?php endif; ?>
</article>
