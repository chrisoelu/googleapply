<?php

return [
    'google_client_id' => 'YOUR_GOOGLE_CLIENT_ID',
    'google_client_secret' => 'YOUR_GOOGLE_CLIENT_SECRET',
    'google_redirect_uri' => 'http://localhost:8000/api/callback.php',
    'frontend_url' => 'http://localhost:5173',
    'session_name' => 'google_login_test_session',
    'db' => [
        'host' => 'YOUR_DB_HOST',
        'port' => 3306,
        'database' => 'YOUR_DB_NAME',
        'username' => 'YOUR_DB_USER',
        'password' => 'YOUR_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],
];
