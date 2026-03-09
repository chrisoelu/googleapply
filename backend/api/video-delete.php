<?php

require __DIR__ . '/../includes/bootstrap.php';
apply_cors();
handle_preflight();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$user = require_authenticated_user();
$googleSub = (string) ($user['sub'] ?? '');

if ($googleSub === '') {
    json_response(['error' => 'Ungueltiger Benutzer.'], 400);
}

try {
    $pdo = db_connection();
    sync_google_user_to_form($pdo, $user);

    $stmt = $pdo->prepare(
        'UPDATE user_forms
         SET video_blob = NULL,
             video_mime = NULL,
             video_filename = NULL,
             video_size = NULL,
             video_uploaded_at = NULL,
             updated_at = NOW()
         WHERE google_sub = :google_sub'
    );
    $stmt->execute([':google_sub' => $googleSub]);

    json_response(['ok' => true]);
} catch (Throwable $e) {
    json_response(['error' => 'Video konnte nicht geloescht werden.'], 500);
}
