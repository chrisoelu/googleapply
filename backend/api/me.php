<?php

require __DIR__ . '/../includes/bootstrap.php';
apply_cors();
handle_preflight();

$user = $_SESSION['user'] ?? null;

json_response([
    'authenticated' => is_array($user),
    'user' => $user,
]);