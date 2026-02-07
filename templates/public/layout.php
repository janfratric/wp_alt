<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($title ?? 'LiteCMS') ?></title>
</head>
<body>
    <header>
        <nav>
            <a href="/"><?= $this->e($title ?? 'LiteCMS') ?></a>
        </nav>
    </header>
    <main>
        <?= $this->content() ?>
    </main>
    <footer>
        <p>&copy; <?= date('Y') ?> <?= $this->e($title ?? 'LiteCMS') ?></p>
    </footer>
</body>
</html>
