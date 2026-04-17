<?php
require_once 'config.php';
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function fail(string $msg): never {
    header('Location: /login.php?error=' . urlencode($msg));
    exit;
}

// Verify CSRF state
if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
    fail('Ongeldige sessiestatus. Probeer opnieuw.');
}
unset($_SESSION['oauth_state']);

if (isset($_GET['error'])) {
    fail('Aanmelding geannuleerd.');
}

$code = $_GET['code'] ?? '';
if (!$code) fail('Geen autorisatiecode ontvangen.');

// Exchange code for tokens
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$tokenResponse = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($tokenResponse['access_token'])) {
    fail('Tokenuitwisseling mislukt.');
}

// Fetch user info
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenResponse['access_token']],
]);
$userInfo = json_decode(curl_exec($ch), true);
curl_close($ch);

// Enforce workspace domain
$hd = $userInfo['hd'] ?? '';
if ($hd !== GOOGLE_WORKSPACE_DOMAIN) {
    fail('Toegang beperkt tot @' . GOOGLE_WORKSPACE_DOMAIN . ' accounts.');
}

$email = strtolower($userInfo['email'] ?? '');
$name  = $userInfo['name']  ?? $email;

if (!$email) fail('Kon e-mailadres niet ophalen.');

// Look up role
$stmt = $pdo->prepare("SELECT role FROM user_roles WHERE email = ?");
$stmt->execute([$email]);
$row = $stmt->fetch();

$_SESSION['user'] = [
    'email' => $email,
    'name'  => $name,
    'role'  => $row ? $row['role'] : null,
];

$redirect = $_SESSION['redirect_after_login'] ?? '/index.php';
unset($_SESSION['redirect_after_login']);

// Only allow redirects within this site
if (!str_starts_with($redirect, '/')) $redirect = '/index.php';
header('Location: ' . $redirect);
exit;
