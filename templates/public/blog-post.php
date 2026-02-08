<?php $this->layout('public/layout'); ?>

<article class="blog-post">
    <header class="post-header">
        <h1><?= $this->e($content['title']) ?></h1>
        <div class="post-meta">
            <time datetime="<?= $this->e($content['published_at'] ?? $content['created_at']) ?>">
                <?= date('F j, Y', strtotime($content['published_at'] ?? $content['created_at'])) ?>
            </time>
            <span class="post-author">by <?= $this->e($content['author_name'] ?? 'Unknown') ?></span>
        </div>
    </header>

<?php if (!empty($content['featured_image'])): ?>
    <div class="post-featured-image">
        <img src="<?= $this->e($content['featured_image']) ?>" alt="<?= $this->e($content['title']) ?>">
    </div>
<?php endif; ?>

    <div class="post-content">
        <?= $content['body'] ?>
    </div>
</article>
