<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id FROM organizations WHERE name = :name LIMIT 1');
    $stmt->execute(['name' => 'Acme Corp']);
    $orgId = $stmt->fetchColumn();
    if (!$orgId) {
        $insertOrg = $pdo->prepare('INSERT INTO organizations (name, template_path) VALUES (:name, :template)');
        $insertOrg->execute(['name' => 'Acme Corp', 'template' => 'assets/templates/template.png']);
        $orgId = (int) $pdo->lastInsertId();
    }
    $email = 'admin@acme.test';
    $find = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $find->execute(['email' => $email]);
    if (!$find->fetchColumn()) {
        $create = $pdo->prepare("INSERT INTO users (org_id, email, password_hash, role) VALUES (:org_id, :email, :hash, 'admin')");
        $create->execute([
            'org_id' => (int) $orgId,
            'email' => $email,
            'hash' => password_hash('admin123', PASSWORD_DEFAULT),
        ]);
    }
    $pdo->commit();
    echo "Seeded Acme Corp administrator: admin@acme.test / admin123\nChange the password before exposing this application publicly.\n";
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
