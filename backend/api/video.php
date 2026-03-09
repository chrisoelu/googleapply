<?php

require __DIR__ . '/../includes/bootstrap.php';
apply_cors();
handle_preflight();

$user = current_user();
if ($user === null) {
    json_response(['error' => 'Not authenticated'], 401);
}

$googleSub = (string) ($user['sub'] ?? '');
if ($googleSub === '') {
    json_response(['error' => 'Ungueltiger Benutzer.'], 400);
}

try {
    $pdo = db_connection();
    ensure_user_forms_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT video_blob, video_mime, video_filename, video_size
         FROM user_forms
         WHERE google_sub = :google_sub
           AND video_blob IS NOT NULL
         LIMIT 1'
    );
    $stmt->execute([':google_sub' => $googleSub]);

    $blob = null;
    $mime = null;
    $fileName = null;
    $fileSize = null;

    $stmt->bindColumn('video_blob', $blob, PDO::PARAM_LOB);
    $stmt->bindColumn('video_mime', $mime, PDO::PARAM_STR);
    $stmt->bindColumn('video_filename', $fileName, PDO::PARAM_STR);
    $stmt->bindColumn('video_size', $fileSize, PDO::PARAM_INT);

    if (!$stmt->fetch(PDO::FETCH_BOUND)) {
        json_response(['error' => 'Kein Video gefunden.'], 404);
    }

    $contentType = (is_string($mime) && $mime !== '') ? $mime : 'application/octet-stream';
    $downloadName = (is_string($fileName) && $fileName !== '') ? $fileName : 'video';

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: inline; filename="' . rawurlencode($downloadName) . '"');
    header('Cache-Control: no-store');

    if (is_int($fileSize) && $fileSize > 0) {
        header('Content-Length: ' . $fileSize);
    }

    if (is_resource($blob)) {
        fpassthru($blob);
        fclose($blob);
    } elseif (is_string($blob)) {
        echo $blob;
    }
    exit;
} catch (Throwable $e) {
    json_response(['error' => 'Video konnte nicht geladen werden.'], 500);
}
