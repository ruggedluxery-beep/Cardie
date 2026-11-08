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
$orgStmt = db()->prepare('SELECT id, name, template_path FROM organizations WHERE id = :org_id');
$orgStmt->execute(['org_id' => $orgId]);
$organization = $orgStmt->fetch();
if (!$organization) {
    http_response_code(404);
    exit('Organization not found.');
}
$stmt = db()->prepare("SELECT * FROM members WHERE org_id = :org_id AND status = 'active' ORDER BY id");
$stmt->execute(['org_id' => $orgId]);
$members = $stmt->fetchAll();
if (!$members) {
    http_response_code(404);
    exit('No active members found.');
}
try {
    $buffers = [];
    foreach ($members as $member) {
        $buffers[] = render_card_pdf($member, $organization);
    }
    // FPDI imports each in-memory card page; for very large sets, stream intermediate PDFs to disk instead.
    $bytes = merge_card_pdfs($buffers);
    ensure_upload_dirs();
    $filename = 'batch-' . date('Ymd-His') . '.pdf';
    $relativePath = 'assets/uploads/batches/' . $filename;
    $target = __DIR__ . '/../' . $relativePath;
    if (file_put_contents($target, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('Unable to persist batch PDF.');
    }
    $insert = db()->prepare("INSERT INTO batches (org_id, total_cards, status, file_path) VALUES (:org_id, :total, 'completed', :file_path)");
    $insert->execute(['org_id' => $orgId, 'total' => count($members), 'file_path' => $relativePath]);
} catch (Throwable $exception) {
    error_log('Batch generation failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Unable to generate batch. Check server configuration.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($bytes));
echo $bytes;
