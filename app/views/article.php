<?php /** @var array $a @var array $related @var array $more @var string $image */ ?>
<nav class="crumbs"><a href="/"><?= e(t('home')) ?></a> › <a href="/articles"><?= e(t('articles')) ?></a> › <span><?= e(excerpt($a['title'], 60)) ?></span></nav>
<article class="post">
  <h1><?= e($a['title']) ?></h1>
  <p class="meta"><?= e(t('published', fmt_date($a['published_at']))) ?></p>
  <?php if (!empty($a['cover'])): ?><img class="post-cover" src="<?= e($a['cover']) ?>" alt="<?= e($a['title']) ?>" width="1200" height="675"><?php endif; ?>
  <?php if (!empty($a['excerpt'])): ?><p class="post-lead"><?= e($a['excerpt']) ?></p><?php endif; ?>
  <div class="post-body"><?= md_render((string)$a['body']) ?></div>
  <?= share_buttons(url('article/' . $a['slug']), $a['title'], $image) ?>
</article>

<?php if ($related): ?>
<section class="block">
  <div class="block-head"><h2><?= e(t('related_videos')) ?></h2></div>
  <?= view('partials/grid', ['items' => $related, 'kind' => 'video']) ?>
</section>
<?php endif; ?>

<?php if ($more): ?>
<section class="block">
  <div class="block-head"><h2><?= e(t('latest_articles')) ?></h2><a href="/articles"><?= e(t('view_all')) ?> →</a></div>
  <div class="article-list"><?php foreach ($more as $m): ?><?= view('partials/article_card', ['a' => $m]) ?><?php endforeach; ?></div>
</section>
<?php endif; ?>
