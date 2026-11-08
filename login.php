<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $password = (string) ($_POST['password'] ?? '');
    if (!$email || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        $stmt = db()->prepare('SELECT id, org_id, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, (string) $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['org_id'] = (int) $user['org_id'];
            $_SESSION['role'] = (string) $user['role'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: /dashboard.php');
            exit;
        }
        $error = 'Invalid email or password.';
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in · ID Generator</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-5"><div class="card mx-auto shadow-sm" style="max-width:420px"><div class="card-body p-4"><h1 class="h3 mb-4">ID Generator sign in</h1><?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><div class="mb-3"><label class="form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" autocomplete="username" required></div><div class="mb-3"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required></div><button class="btn btn-primary w-100" type="submit">Sign in</button></form></div></div></main></body></html>
