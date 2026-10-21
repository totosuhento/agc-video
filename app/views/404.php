<div class="empty">
  <h1><?= e(t('not_found')) ?></h1>
  <p><?= e(t('not_found_text')) ?></p>
  <form action="/search" method="get" class="search search-big"><input type="search" name="q" placeholder="<?= e(t('search_placeholder')) ?>"></form>
  <p><a class="btn" href="/"><?= e(t('home')) ?></a></p>
</div>
