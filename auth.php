<?php
session_set_cookie_params(['lifetime' => 7200]);
ini_set('session.gc_maxlifetime', 7200);
if (session_status() === PHP_SESSION_NONE) session_start();

function getUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireAuth(): void {
    if (!getUser()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: /login.php');
        exit;
    }
}

function hasRole(string $role): bool {
    $user = getUser();
    if (!$user) return false;
    $r = $user['role'] ?? null;
    if (!$r) return false;
    if ($role === 'read')  return in_array($r, ['read', 'write']);
    if ($role === 'write') return $r === 'write';
    return false;
}

function requireRole(string $role): void {
    requireAuth();
    if (!hasRole($role)) {
        http_response_code(403);
        $back = hasRole('read') ? '/rapport.php' : '/index.php';
        die('<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><title>Geen toegang</title>'
          . '<link rel="stylesheet" href="/css/pos.css">'
          . '<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=Crimson+Pro&display=swap" rel="stylesheet">'
          . '</head><body><div class="center-card" style="margin-top:15vh">'
          . '<h1>🚫</h1><h2 style="color:var(--red)">Geen toegang</h2>'
          . '<p style="margin:16px 0;color:var(--text-dim)">Je hebt geen rechten voor deze pagina.</p>'
          . '<a href="' . $back . '" style="color:var(--accent)">← Terug</a>'
          . '</div></body></html>');
    }
}
