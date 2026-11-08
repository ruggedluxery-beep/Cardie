<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();
$orgId = current_org();
$inserted = 0;
$errors = [];
$processed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $processed = true;
    $upload = $_FILES['csv'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
        $errors[] = 'Choose a valid CSV file.';
    } elseif ((int) $upload['size'] > 5 * 1024 * 1024) {
        $errors[] = 'CSV file must be 5 MB or smaller.';
    } elseif (($handle = fopen((string) $upload['tmp_name'], 'rb')) === false) {
        $errors[] = 'Unable to read the uploaded CSV.';
    } else {
        $header = fgetcsv($handle);
        $expected = ['member_code', 'full_name', 'position', 'department', 'email', 'phone', 'issue_date', 'expiry_date', 'photo_file'];
        if (!$header) {
            $errors[] = 'CSV is empty.';
        } else {
            $header = array_map(static fn($v): string => strtolower(trim((string) $v)), $header);
            if (array_slice($header, 0, count($expected)) !== $expected) {
                $errors[] = 'CSV headers must be: ' . implode(', ', $expected);
            } else {
                $rowNumber = 1;
                $stmt = db()->prepare('INSERT INTO members (org_id, member_code, full_name, position, department, email, phone, photo_path, issue_date, expiry_date, card_token) VALUES (:org_id, :member_code, :full_name, :position, :department, :email, :phone, :photo_path, :issue_date, :expiry_date, :card_token)');
                while (($row = fgetcsv($handle)) !== false) {
                    $rowNumber++;
                    if (count($row) !== count($header)) {
                        $errors[] = "Row {$rowNumber}: column count does not match header.";
                        continue;
                    }
                    $data = array_combine($header, $row);
                    $code = trim((string) ($data['member_code'] ?? ''));
                    $name = trim((string) ($data['full_name'] ?? ''));
                    if ($code === '' || $name === '') {
                        $errors[] = "Row {$rowNumber}: member_code and full_name are required.";
                        continue;
                    }
                    $photoPath = null;
                    $photoCandidate = trim((string) ($data['photo_file'] ?? ''));
                    if ($photoCandidate !== '') {
                        $photoPath = safe_photo_path($photoCandidate);
                        if ($photoPath === null) {
                            $errors[] = "Row {$rowNumber}: photo_file must point to a valid JPG, PNG, or WebP already inside assets/uploads/photos; photo skipped.";
                        }
                    }
                    $issue = trim((string) ($data['issue_date'] ?? '')) ?: date('Y-m-d');
                    $expiry = trim((string) ($data['expiry_date'] ?? '')) ?: null;
                    $validDate = static fn(?string $date): bool => $date === null || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false;
                    if (!$validDate($issue) || !$validDate($expiry)) {
                        $errors[] = "Row {$rowNumber}: dates must use YYYY-MM-DD.";
                        continue;
                    }
                    try {
                        $stmt->execute([
                            'org_id' => $orgId,
                            'member_code' => $code,
                            'full_name' => $name,
                            'position' => trim((string) ($data['position'] ?? '')) ?: null,
                            'department' => trim((string) ($data['department'] ?? '')) ?: null,
                            'email' => trim((string) ($data['email'] ?? '')) ?: null,
                            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                            'photo_path' => $photoPath,
                            'issue_date' => $issue,
                            'expiry_date' => $expiry,
                            'card_token' => bin2hex(random_bytes(20)),
                        ]);
                        $inserted++;
                    } catch (Throwable $exception) {
                        $errors[] = "Row {$rowNumber}: could not be imported (duplicate code or invalid data).";
                        error_log('CSV import row failed: ' . $exception->getMessage());
                    }
                }
            }
        }
        fclose($handle);
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bulk Import · ID Generator</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body><main class="container py-4" style="max-width:850px"><a href="/dashboard.php">← Dashboard</a><h1 class="h3 mt-3">Bulk import members</h1><p>CSV columns: <code>member_code, full_name, position, department, email, phone, issue_date, expiry_date, photo_file</code></p><?php if ($processed): ?><div class="alert <?= $errors ? 'alert-warning' : 'alert-success' ?>"><strong><?= $inserted ?> row(s) imported.</strong><?php if ($errors): ?><hr><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?><div class="alert alert-info">Optional photo paths must refer to an image already stored under <code>assets/uploads/photos/</code>; arbitrary server paths are rejected.</div><form method="post" enctype="multipart/form-data" class="card card-body"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label class="form-label" for="csv">CSV file (max 5 MB)</label><input class="form-control mb-3" type="file" id="csv" name="csv" accept=".csv,text/csv" required><button class="btn btn-primary align-self-start" type="submit">Import CSV</button></form></main></body></html>
