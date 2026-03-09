<?php

require __DIR__ . '/../includes/bootstrap.php';

$state = $_GET['state'] ?? '';
$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';

if ($error !== '') {
    frontend_redirect('/?error=' . urlencode($error));
}

if (!$state || !$code || !hash_equals($_SESSION['oauth_state'] ?? '', $state)) {
    frontend_redirect('/?error=' . urlencode('invalid_state'));
}

unset($_SESSION['oauth_state']);

try {
    $tokenResponse = http_post_form('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => app_config('google_client_id'),
        'client_secret' => app_config('google_client_secret'),
        'redirect_uri' => app_config('google_redirect_uri'),
        'grant_type' => 'authorization_code',
    ]);

    if ($tokenResponse['status'] !== 200 || empty($tokenResponse['json']['access_token'])) {
        frontend_redirect('/?error=' . urlencode('token_exchange_failed'));
    }

    $accessToken = $tokenResponse['json']['access_token'];

    $profileResponse = http_get_json('https://openidconnect.googleapis.com/v1/userinfo', [
        'Authorization' => 'Bearer ' . $accessToken,
    ]);

    if ($profileResponse['status'] !== 200) {
        frontend_redirect('/?error=' . urlencode('userinfo_failed'));
    }

    $profile = $profileResponse['json'];

    $_SESSION['user'] = [
        'sub' => $profile['sub'] ?? null,
        'email' => $profile['email'] ?? null,
        'email_verified' => $profile['email_verified'] ?? null,
        'name' => $profile['name'] ?? null,
        'given_name' => $profile['given_name'] ?? null,
        'family_name' => $profile['family_name'] ?? null,
        'picture' => $profile['picture'] ?? null,
        'locale' => $profile['locale'] ?? null,
    ];

    try {
        $pdo = db_connection();
        sync_google_user_to_form($pdo, $_SESSION['user']);
    } catch (Throwable $ignored) {
        // Login darf nicht scheitern, nur weil DB-Sync nicht klappt.
    }

    frontend_redirect('/?login=success');
} catch (Throwable $e) {
    frontend_redirect('/?error=' . urlencode('oauth_exception'));
}
