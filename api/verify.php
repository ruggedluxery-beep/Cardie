<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function verify_page(string $title, string $body): never
{
    http_response_code($title === 'Verified' ? 200 : 404);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-5" style="max-width:640px">' . $body . '</main></body></html>';
    exit;
}

// TODO: Replace this small local counter with a shared Redis or reverse-proxy rate limiter in production.
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateFile = sys_get_temp_dir() . '/id-verify-' . hash('sha256', $ip) . '.json';
$now = time();
$rateData = ['start' => $now, 'count' => 0];
$handle = @fopen($rateFile, 'c+');
if ($handle !== false) {
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $decoded = json_decode($raw ?: '', true);
    if (is_array($decoded) && isset($decoded['start'], $decoded['count']) && $now - (int) $decoded['start'] < 300) {
        $rateData = ['start' => (int) $decoded['start'], 'count' => (int) $decoded['count']];
    }
    $rateData['count']++;
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($rateData));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}
if ($rateData['count'] > 120) {
    http_response_code(429);
    verify_page('Too many requests', '<div class="alert alert-warning">Please try again later.</div>');
}

$token = $_GET['token'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{40}$/', $token)) {
    verify_page('Invalid ID', '<div class="alert alert-danger">Invalid or revoked ID.</div>');
}
$stmt = db()->prepare('SELECT m.member_code, m.full_name, m.position, m.department, m.photo_path, m.issue_date, m.expiry_date, m.status, o.name AS organization_name FROM members m JOIN organizations o ON o.id = m.org_id WHERE m.card_token = :token LIMIT 1');
$stmt->execute(['token' => $token]);
$member = $stmt->fetch();
if (!$member || $member['status'] !== 'active') {
    verify_page('Invalid ID', '<div class="alert alert-danger">Invalid or revoked ID.</div>');
}
$photoUrl = '';
if (!empty($member['photo_path']) && is_file((string) $member['photo_path'])) {
    $real = realpath((string) $member['photo_path']);
    $photos = realpath(__DIR__ . '/../assets/uploads/photos');
    if ($real && $photos && str_starts_with($real, $photos . DIRECTORY_SEPARATOR)) {
        $photoUrl = '/assets/uploads/photos/' . rawurlencode(basename($real));
    }
}
$photo = $photoUrl !== '' ? '<img class="rounded-circle mb-3" width="110" height="110" style="object-fit:cover" src="' . htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Member photo">' : '';
$body = '<div class="card shadow-sm"><div class="card-body text-center p-4"><div class="alert alert-success fw-bold" role="status">✓ Verified</div>' . $photo . '<h1 class="h3">' . htmlspecialchars((string) $member['full_name'], ENT_QUOTES, 'UTF-8') . '</h1><p class="text-muted">' . htmlspecialchars((string) $member['organization_name'], ENT_QUOTES, 'UTF-8') . '</p><p><span class="badge text-bg-success">Active</span></p><dl class="row text-start mb-0">';
$details = ['Code' => $member['member_code'], 'Position' => $member['position'], 'Department' => $member['department'], 'Issue date' => $member['issue_date'], 'Expiry date' => $member['expiry_date']];
foreach ($details as $label => $value) {
    $body .= '<dt class="col-5">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt><dd class="col-7">' . htmlspecialchars((string) ($value ?: '-'), ENT_QUOTES, 'UTF-8') . '</dd>';
}
$body .= '</dl></div></div>';
verify_page('Verified', $body);
