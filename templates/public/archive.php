<?php $this->layout('public/layout'); ?>

<div class="archive-listing">
    <h1><?= $this->e($archiveTitle ?? $title) ?></h1>

<?php if (!empty($items)): ?>
<?php foreach ($items as $item): ?>
    <article class="post-card">
<?php if (!empty($item['featured_image'])): ?>
        <div class="post-card-image">
            <a href="/<?= $this->e($archiveSlug ?? '') ?>/<?= $this->e($item['slug']) ?>">
                <img src="<?= $this->e($item['featured_image']) ?>" alt="<?= $this->e($item['title']) ?>">
            </a>
        </div>
<?php endif; ?>
        <div class="post-card-body">
            <h2><a href="/<?= $this->e($archiveSlug ?? '') ?>/<?= $this->e($item['slug']) ?>"><?= $this->e($item['title']) ?></a></h2>
            <div class="post-meta">
                <time datetime="<?= $this->e($item['published_at'] ?? $item['created_at']) ?>">
                    <?= date('M j, Y', strtotime($item['published_at'] ?? $item['created_at'])) ?>
                </time>
<?php if (!empty($item['author_name'])): ?>
                <span class="post-author">by <?= $this->e($item['author_name']) ?></span>
<?php endif; ?>
            </div>
<?php if (!empty($item['excerpt'])): ?>
            <p class="post-excerpt"><?= $this->e($item['excerpt']) ?></p>
<?php else: ?>
            <p class="post-excerpt"><?= $this->e(mb_substr(strip_tags($item['body'] ?? ''), 0, 160, 'UTF-8')) ?>...</p>
<?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>

<?php if (($totalPages ?? 1) > 1): ?>
    <nav class="pagination" aria-label="Archive pagination">
<?php if ($currentPage > 1): ?>
        <a href="/<?= $this->e($archiveSlug ?? '') ?>?page=<?= $currentPage - 1 ?>" class="pagination-prev">Previous</a>
<?php endif; ?>
        <span class="pagination-info">Page <?= $currentPage ?> of <?= $totalPages ?></span>
<?php if ($currentPage < $totalPages): ?>
        <a href="/<?= $this->e($archiveSlug ?? '') ?>?page=<?= $currentPage + 1 ?>" class="pagination-next">Next</a>
<?php endif; ?>
    </nav>
<?php endif; ?>

<?php else: ?>
    <p class="text-muted">No items found.</p>
<?php endif; ?>
</div>
