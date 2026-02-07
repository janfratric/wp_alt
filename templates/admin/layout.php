<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($title ?? 'Admin') ?> — LiteCMS Admin</title>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="sidebar">
            <h2>LiteCMS</h2>
            <nav>
                <a href="/admin/dashboard">Dashboard</a>
            </nav>
        </aside>
        <main class="admin-content">
            <?= $this->content() ?>
        </main>
    </div>
</body>
</html>
