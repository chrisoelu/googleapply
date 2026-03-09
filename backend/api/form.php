<?php

require __DIR__ . '/../includes/bootstrap.php';
apply_cors();
handle_preflight();

$user = require_authenticated_user();
$googleSub = (string) ($user['sub'] ?? '');

if ($googleSub === '') {
    json_response(['error' => 'Ungueltiger Benutzer.'], 400);
}

try {
    $pdo = db_connection();
    sync_google_user_to_form($pdo, $user);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT
                first_name,
                last_name,
                email,
                approximate_address,
                preferred_contact,
                social_media,
                other_notes,
                video_blob IS NOT NULL AS has_video,
                video_uploaded_at
             FROM user_forms
             WHERE google_sub = :google_sub
             LIMIT 1'
        );
        $stmt->execute([':google_sub' => $googleSub]);
        $row = $stmt->fetch();

        if (!$row) {
            json_response(['error' => 'Formular konnte nicht geladen werden.'], 404);
        }

        json_response([
            'form' => [
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'approximate_address' => (string) ($row['approximate_address'] ?? ''),
                'preferred_contact' => (string) ($row['preferred_contact'] ?? ''),
                'social_media' => (string) ($row['social_media'] ?? ''),
                'other_notes' => (string) ($row['other_notes'] ?? ''),
            ],
            'video' => [
                'has_video' => (bool) ($row['has_video'] ?? false),
                'uploaded_at' => $row['video_uploaded_at'] ?? null,
            ],
        ]);
    }

    if ($method === 'POST') {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody ?: '', true);

        if (!is_array($payload)) {
            json_response(['error' => 'Ungueltiges JSON im Request-Body.'], 400);
        }

        $firstName = normalize_text($payload['first_name'] ?? null);
        $lastName = normalize_text($payload['last_name'] ?? null);
        $email = normalize_text($payload['email'] ?? null);
        $approximateAddress = normalize_text($payload['approximate_address'] ?? null);
        $preferredContact = normalize_text($payload['preferred_contact'] ?? null);
        $socialMedia = normalize_text($payload['social_media'] ?? null);
        $otherNotes = normalize_text($payload['other_notes'] ?? null);

        $updateStmt = $pdo->prepare(
            'UPDATE user_forms
             SET first_name = :first_name,
                 last_name = :last_name,
                 email = :email,
                 approximate_address = :approximate_address,
                 preferred_contact = :preferred_contact,
                 social_media = :social_media,
                 other_notes = :other_notes,
                 updated_at = NOW()
             WHERE google_sub = :google_sub'
        );

        $updateStmt->execute([
            ':google_sub' => $googleSub,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':email' => $email,
            ':approximate_address' => $approximateAddress,
            ':preferred_contact' => $preferredContact,
            ':social_media' => $socialMedia,
            ':other_notes' => $otherNotes,
        ]);

        json_response(['ok' => true]);
    }

    json_response(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    json_response(['error' => 'Formular-API-Fehler.'], 500);
}
