<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Design Files</h1>
    <div>
        <a href="/admin/design/editor" class="btn btn-primary">+ New Design</a>
    </div>
</div>

<?php if (empty($designFiles)): ?>
    <div class="design-browser-grid">
        <div class="design-browser-empty">
            <p>No design files found.</p>
            <p><a href="/admin/design/editor">Create your first design</a></p>
        </div>
    </div>
<?php else: ?>
    <div class="design-browser-grid" id="design-browser">
        <?php foreach ($designFiles as $df): ?>
        <div class="design-browser-card" data-path="<?= $this->e($df['path']) ?>">
            <div class="design-browser-thumb">
                <iframe src="/admin/design/preview?path=<?= $this->e($df['path']) ?>"
                        sandbox="allow-scripts allow-same-origin"
                        loading="lazy"></iframe>
            </div>
            <div class="design-browser-info">
                <span class="design-browser-name"><?= $this->e($df['name']) ?></span>
                <span class="design-browser-meta"><?= $this->e($df['modified'] ?? '') ?> &middot; <?= number_format(($df['size'] ?? 0) / 1024, 1) ?> KB</span>
            </div>
            <div class="design-browser-actions">
                <a href="/admin/design/editor?file=<?= $this->e($df['path']) ?>" class="btn btn-sm">Edit</a>
                <button type="button" class="btn btn-sm" data-action="duplicate" data-path="<?= $this->e($df['path']) ?>">Duplicate</button>
                <button type="button" class="btn btn-sm btn-danger" data-action="delete" data-path="<?= $this->e($df['path']) ?>">Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function() {
    var csrfToken = <?= json_encode($csrfToken ?? '') ?>;

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;

        var action = btn.getAttribute('data-action');
        var path = btn.getAttribute('data-path');

        if (action === 'duplicate') {
            var newName = prompt('Enter name for the duplicate:', path.replace('.pen', '-copy.pen'));
            if (!newName) return;
            if (!newName.endsWith('.pen')) newName += '.pen';

            fetch('/admin/design/duplicate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    source: path,
                    target: newName,
                    csrf_token: csrfToken
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown'));
                }
            })
            .catch(function() { alert('Request failed'); });
        }

        if (action === 'delete') {
            if (!confirm('Delete "' + path + '"? This cannot be undone.')) return;

            fetch('/admin/design/delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    path: path,
                    csrf_token: csrfToken
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Unknown'));
                }
            })
            .catch(function() { alert('Request failed'); });
        }
    });
})();
</script>
