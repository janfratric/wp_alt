<?php $this->layout('public/layout'); ?>

<article class="page-content">
    <h1><?= $this->e($content['title']) ?></h1>

<?php if (!empty($content['featured_image'])): ?>
    <div class="page-featured-image">
        <img src="<?= $this->e($content['featured_image']) ?>" alt="<?= $this->e($content['title']) ?>">
    </div>
<?php endif; ?>

    <div class="page-body">
        <?= $content['body'] ?>
    </div>
</article>
