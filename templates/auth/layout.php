<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($title ?? 'Login') ?> — LiteCMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.08);
            padding: 2.5rem;
            width: 100%;
            max-width: 400px;
        }
        .auth-container h1 {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
            text-align: center;
        }
        .auth-container .subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            margin-bottom: 0.35rem;
            font-weight: 500;
            font-size: 0.9rem;
        }
        .form-group input {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.15s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .btn-primary {
            width: 100%;
            padding: 0.65rem;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-primary:hover { background: #1d4ed8; }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
    </style>
</head>
<body>
    <main class="auth-container">
        <?= $this->content() ?>
    </main>
</body>
</html>
