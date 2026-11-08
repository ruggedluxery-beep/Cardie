<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use setasign\Fpdi\Fpdi;

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fit_text(string $text, float $maxWidthPt, float $fontSize): string
{
    $text = trim($text);
    if ($text === '') {
        return '-';
    }
    // Approximate core-font glyph widths; keep a small safety margin for FPDF rendering.
    $limit = max(1, (int) floor($maxWidthPt / max(1.0, $fontSize * 0.56)));
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return rtrim(mb_substr($text, 0, max(1, $limit - 1), 'UTF-8')) . '…';
    }
    if (!function_exists('mb_strlen') && strlen($text) > $limit) {
        return rtrim(substr($text, 0, max(1, $limit - 1))) . '…';
    }
    return $text;
}

function ensure_upload_dirs(): void
{
    foreach (['photos', 'qr', 'batches'] as $dir) {
        $path = __DIR__ . '/../assets/uploads/' . $dir;
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create upload directory.');
        }
    }
}

function safe_host(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/\A[a-zA-Z0-9.-]+(?::[0-9]{1,5})?\z/', $host)) {
        return 'localhost';
    }
    return $host;
}

function qr_file_for_token(string $token): string
{
    if (!preg_match('/\A[a-f0-9]{40}\z/', $token)) {
        throw new InvalidArgumentException('Invalid card token.');
    }
    ensure_upload_dirs();
    $file = __DIR__ . '/../assets/uploads/qr/' . $token . '.png';
    if (!is_file($file)) {
        $url = 'https://' . safe_host() . '/api/verify.php?token=' . rawurlencode($token);
        // Cache locally so repeated card renders do not regenerate the same QR image.
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($url)
            ->size(240)
            ->margin(8)
            ->build();
        $result->saveToFile($file);
    }
    return $file;
}

function render_card_pdf(array $member, array $organization): string
{
    $autoload = __DIR__ . '/../lib/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Composer dependencies are missing. Run composer install.');
    }
    require_once $autoload;
    ensure_upload_dirs();

    $pdf = new Fpdi('L', 'pt', [336, 213]);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $template = (string) ($organization['template_path'] ?: (__DIR__ . '/../assets/templates/template.png'));
    if (is_file($template)) {
        $pdf->Image($template, 0, 0, 336, 213);
    }

    $photo = (string) ($member['photo_path'] ?? '');
    if ($photo !== '' && is_file($photo)) {
        $pdf->Image($photo, 20, 45, 90, 90);
    }
    $qr = qr_file_for_token((string) $member['card_token']);
    $pdf->Image($qr, 264, 132, 52, 52);

    $pdf->SetTextColor(25, 35, 50);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetXY(126, 48);
    $pdf->Cell(126, 22, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', fit_text((string) $member['full_name'], 126, 16)) ?: '-', 0, 1);

    $fields = [
        ['ID', (string) $member['member_code']],
        ['Position', (string) ($member['position'] ?? '')],
        ['Department', (string) ($member['department'] ?? '')],
        ['Issued', (string) ($member['issue_date'] ?? '')],
        ['Expires', (string) ($member['expiry_date'] ?? '')],
    ];
    $y = 81;
    foreach ($fields as [$label, $value]) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY(126, $y);
        $pdf->Cell(52, 13, $label . ':', 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $fit = fit_text($value, 127, 9);
        $encoded = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $fit);
        $pdf->Cell(127, 13, $encoded === false ? '-' : $encoded, 0, 1);
        $y += 19;
    }
    return $pdf->Output('S');
}

function safe_photo_path(string $candidate): ?string
{
    $base = realpath(__DIR__ . '/../assets/uploads/photos');
    $real = realpath($candidate);
    if ($base === false || $real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($real);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    if (!isset($extensions[$mime]) || $extensions[$mime] !== $ext) {
        return null;
    }
    return $real;
}

function merge_card_pdfs(array $pdfBuffers): string
{
    $merged = new Fpdi('L', 'pt', [336, 213]);
    $merged->SetAutoPageBreak(false);
    foreach ($pdfBuffers as $buffer) {
        $pages = $merged->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($buffer));
        for ($page = 1; $page <= $pages; $page++) {
            $templateId = $merged->importPage($page);
            $size = $merged->getTemplateSize($templateId);
            $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $merged->useTemplate($templateId);
        }
    }
    return $merged->Output('S');
}
