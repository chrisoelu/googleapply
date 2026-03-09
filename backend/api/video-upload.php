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

$maxVideoSizeBytes = 300 * 1024 * 1024;
$maxVideoDurationSeconds = 180;

try {
    $pdo = db_connection();
    sync_google_user_to_form($pdo, $user);

    if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
        json_response(['error' => 'Kein Video empfangen.'], 400);
    }

    $video = $_FILES['video'];
    $uploadError = (int) ($video['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        json_response(['error' => map_upload_error($uploadError)], 400);
    }

    $tmpName = (string) ($video['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        json_response(['error' => 'Ungueltiger Upload.'], 400);
    }

    $size = (int) ($video['size'] ?? 0);
    if ($size <= 0) {
        json_response(['error' => 'Video ist leer.'], 400);
    }

    if ($size > $maxVideoSizeBytes) {
        json_response(['error' => 'Video ist groesser als 300MB.'], 400);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    if ($mimeType === '' || !str_starts_with($mimeType, 'video/')) {
        json_response(['error' => 'Die Datei ist kein gueltiges Video.'], 400);
    }

    $durationSeconds = detect_video_duration_seconds($tmpName);
    if ($durationSeconds !== null && $durationSeconds > $maxVideoDurationSeconds) {
        json_response(['error' => 'Video ist laenger als 3 Minuten.'], 400);
    }

    $originalName = normalize_text($video['name'] ?? null) ?? 'video';
    $stream = fopen($tmpName, 'rb');
    if (!is_resource($stream)) {
        json_response(['error' => 'Video konnte nicht gelesen werden.'], 500);
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE user_forms
             SET video_blob = :video_blob,
                 video_mime = :video_mime,
                 video_filename = :video_filename,
                 video_size = :video_size,
                 video_uploaded_at = NOW(),
                 updated_at = NOW()
             WHERE google_sub = :google_sub'
        );
        $stmt->bindParam(':video_blob', $stream, PDO::PARAM_LOB);
        $stmt->bindValue(':video_mime', $mimeType);
        $stmt->bindValue(':video_filename', $originalName);
        $stmt->bindValue(':video_size', $size, PDO::PARAM_INT);
        $stmt->bindValue(':google_sub', $googleSub);
        $stmt->execute();
    } finally {
        fclose($stream);
    }

    json_response([
        'ok' => true,
        'duration_seconds' => $durationSeconds,
    ]);
} catch (Throwable $e) {
    json_response(['error' => 'Video konnte nicht gespeichert werden.'], 500);
}
