<?php declare(strict_types=1);

return [
    'db_driver'      => getenv('DB_DRIVER') ?: 'sqlite',
    'db_path'        => getenv('DB_PATH') ?: __DIR__ . '/../storage/database.sqlite',
    'db_host'        => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'        => (int)(getenv('DB_PORT') ?: 3306),
    'db_name'        => getenv('DB_NAME') ?: 'litecms',
    'db_user'        => getenv('DB_USER') ?: 'root',
    'db_pass'        => getenv('DB_PASS') ?: '',
    'site_name'      => getenv('SITE_NAME') ?: 'LiteCMS',
    'site_url'       => getenv('SITE_URL') ?: 'http://localhost',
    'timezone'       => getenv('TIMEZONE') ?: 'UTC',
    'items_per_page' => (int)(getenv('ITEMS_PER_PAGE') ?: 10),
    'claude_api_key' => getenv('CLAUDE_API_KEY') ?: '',
    'claude_model'   => getenv('CLAUDE_MODEL') ?: 'claude-sonnet-4-20250514',
    'max_upload_size' => (int)(getenv('MAX_UPLOAD_SIZE') ?: 5242880), // 5MB in bytes
    'app_secret'     => getenv('APP_SECRET') ?: 'change-this-to-a-random-string',
];
