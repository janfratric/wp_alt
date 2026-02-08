<?php $this->layout('public/layout'); ?>

<div class="blog-index">
    <h1>Blog</h1>

<?php if (!empty($posts)): ?>
<?php foreach ($posts as $post): ?>
    <article class="post-card">
<?php if (!empty($post['featured_image'])): ?>
        <div class="post-card-image">
            <a href="/blog/<?= $this->e($post['slug']) ?>">
                <img src="<?= $this->e($post['featured_image']) ?>" alt="<?= $this->e($post['title']) ?>">
            </a>
        </div>
<?php endif; ?>
        <div class="post-card-body">
            <h2><a href="/blog/<?= $this->e($post['slug']) ?>"><?= $this->e($post['title']) ?></a></h2>
            <div class="post-meta">
                <time datetime="<?= $this->e($post['published_at'] ?? $post['created_at']) ?>">
                    <?= date('M j, Y', strtotime($post['published_at'] ?? $post['created_at'])) ?>
                </time>
                <span class="post-author">by <?= $this->e($post['author_name'] ?? 'Unknown') ?></span>
            </div>
<?php if (!empty($post['excerpt'])): ?>
            <p class="post-excerpt"><?= $this->e($post['excerpt']) ?></p>
<?php else: ?>
            <p class="post-excerpt"><?= $this->e(mb_substr(strip_tags($post['body']), 0, 160, 'UTF-8')) ?>...</p>
<?php endif; ?>
            <a href="/blog/<?= $this->e($post['slug']) ?>" class="read-more">Read more</a>
        </div>
    </article>
<?php endforeach; ?>

<?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Blog pagination">
<?php if ($currentPage > 1): ?>
        <a href="/blog?page=<?= $currentPage - 1 ?>" class="pagination-prev">Previous</a>
<?php endif; ?>
        <span class="pagination-info">Page <?= $currentPage ?> of <?= $totalPages ?></span>
<?php if ($currentPage < $totalPages): ?>
        <a href="/blog?page=<?= $currentPage + 1 ?>" class="pagination-next">Next</a>
<?php endif; ?>
    </nav>
<?php endif; ?>

<?php else: ?>
    <p>No blog posts published yet. Check back soon!</p>
<?php endif; ?>
</div>
