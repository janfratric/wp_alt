<?php $this->layout('public/layout'); ?>

<div class="homepage">
    <h1>Welcome to <?= $this->e($siteName ?? $title) ?></h1>

<?php if (!empty($posts)): ?>
    <section class="recent-posts">
        <h2>Recent Posts</h2>
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
                <h3><a href="/blog/<?= $this->e($post['slug']) ?>"><?= $this->e($post['title']) ?></a></h3>
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
    </section>
<?php else: ?>
    <p>No posts published yet. Check back soon!</p>
<?php endif; ?>
</div>
