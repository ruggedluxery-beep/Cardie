<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method not allowed');
}
$orgId = current_org();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid member.');
}
$stmt = db()->prepare('SELECT m.* FROM members m WHERE m.id = :id AND m.org_id = :org_id LIMIT 1');
$stmt->execute(['id' => $id, 'org_id' => $orgId]);
$member = $stmt->fetch();
if (!$member) {
    http_response_code(404);
    exit('Member not found.');
}
$orgStmt = db()->prepare('SELECT id, name, template_path FROM organizations WHERE id = :org_id');
$orgStmt->execute(['org_id' => $orgId]);
$organization = $orgStmt->fetch();
if (!$organization) {
    http_response_code(404);
    exit('Organization not found.');
}
try {
    $bytes = render_card_pdf($member, $organization);
} catch (Throwable $exception) {
    error_log('Card generation failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to generate card. Check server configuration.');
}
$code = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $member['member_code']) ?: 'member';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="card-' . $code . '.pdf"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
