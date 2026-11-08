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
    // Approximate core-font glyph widths; leave room for FPDF's actual font metrics.
    $limit = max(1, (int) floor($maxWidthPt / max(1.0, $fontSize * 0.56)));
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return rtrim(mb_substr($text, 0, max(1, $limit - 3), 'UTF-8')) . '...';
    }
    if (!function_exists('mb_strlen') && strlen($text) > $limit) {
        return rtrim(substr($text, 0, max(1, $limit - 3))) . '...';
    }
    return $text;
}

/** Convert user text to the encoding expected by FPDF's built-in Arial fonts. */
function pdf_text(string $text): string
{
    $converted = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    return $converted === false ? '' : $converted;
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
        // Cache locally so repeatedly rendering a member's card doesn't remake its QR image.
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

/**
 * Render a polished, generic member-access card. It is intentionally marked
 * SAMPLE / NOT VALID FOR IDENTIFICATION so it cannot be mistaken for an official credential.
 */
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

    // Draw the base ourselves so the design remains polished even without an uploaded PNG.
    $pdf->SetFillColor(248, 250, 252);
    $pdf->Rect(0, 0, 336, 213, 'F');
    $template = (string) ($organization['template_path'] ?? '');
    if ($template !== '' && is_file($template)) {
        $pdf->Image($template, 0, 0, 336, 213);
        // Keep a light veil over custom artwork for text contrast; foreground labels remain legible.
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(0, 0, 336, 213, 'F');
    }

    // Navy masthead, teal accent, and restrained rule create a clean contemporary badge layout.
    $pdf->SetFillColor(18, 38, 63);
    $pdf->Rect(0, 0, 336, 57, 'F');
    $pdf->SetFillColor(18, 166, 160);
    $pdf->Rect(0, 57, 336, 4, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY(20, 12);
    $pdf->Cell(210, 10, 'MEMBER ACCESS', 0, 0);
    $pdf->SetTextColor(255, 191, 171);
    $pdf->SetXY(252, 12);
    $pdf->Cell(64, 10, 'SAMPLE CARD', 0, 0, 'R');
    $pdf->SetTextColor(255, 255, 255);
    $orgName = fit_text((string) ($organization['name'] ?? 'Organization'), 286, 17);
    $pdf->SetFont('Arial', 'B', 17);
    $pdf->SetXY(20, 27);
    $pdf->Cell(296, 21, pdf_text($orgName), 0, 0);

    // Photo frame leaves a clear neutral placeholder when no portrait was imported.
    $photoX = 20.0;
    $photoY = 75.0;
    $photoW = 78.0;
    $photoH = 91.0;
    $pdf->SetFillColor(229, 236, 243);
    $pdf->Rect($photoX, $photoY, $photoW, $photoH, 'F');
    $photo = (string) ($member['photo_path'] ?? '');
    if ($photo !== '' && is_file($photo)) {
        $info = @getimagesize($photo);
        if (is_array($info) && in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            // Contain the portrait within the frame rather than distorting its aspect ratio.
            $ratio = min($photoW / max(1, (int) $info[0]), $photoH / max(1, (int) $info[1]));
            $drawW = (int) $info[0] * $ratio;
            $drawH = (int) $info[1] * $ratio;
            $pdf->Image($photo, $photoX + ($photoW - $drawW) / 2, $photoY + ($photoH - $drawH) / 2, $drawW, $drawH);
        }
    } else {
        $pdf->SetTextColor(98, 116, 135);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY($photoX, $photoY + 40);
        $pdf->Cell($photoW, 12, 'PHOTO', 0, 0, 'C');
    }
    $pdf->SetDrawColor(206, 217, 227);
    $pdf->Rect($photoX, $photoY, $photoW, $photoH);

    $left = 116.0;
    $right = 244.0;
    $pdf->SetTextColor(25, 42, 60);
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetXY($left, 73);
    $pdf->Cell($right - $left, 21, pdf_text(fit_text((string) ($member['full_name'] ?? ''), $right - $left, 15)), 0, 0);

    $pdf->SetTextColor(18, 127, 132);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY($left, 98);
    $pdf->Cell(43, 12, 'MEMBER CODE', 0, 0);
    $pdf->SetTextColor(45, 59, 73);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($left + 47, 98);
    $pdf->Cell($right - $left - 47, 12, pdf_text(fit_text((string) ($member['member_code'] ?? ''), $right - $left - 47, 9)), 0, 0);

    $fields = [
        ['POSITION', (string) ($member['position'] ?? '')],
        ['DEPARTMENT', (string) ($member['department'] ?? '')],
        ['ISSUED', (string) ($member['issue_date'] ?? '')],
        ['EXPIRES', (string) ($member['expiry_date'] ?? '')],
    ];
    $y = 117.0;
    foreach ($fields as [$label, $value]) {
        $pdf->SetTextColor(103, 119, 135);
        $pdf->SetFont('Arial', 'B', 6);
        $pdf->SetXY($left, $y);
        $pdf->Cell(46, 11, $label, 0, 0);
        $pdf->SetTextColor(38, 54, 70);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY($left + 48, $y - 1);
        $pdf->Cell($right - $left - 48, 12, pdf_text(fit_text($value, $right - $left - 48, 8)), 0, 0);
        $y += 15;
    }

    $qr = qr_file_for_token((string) $member['card_token']);
    $pdf->Image($qr, 264, 112, 50, 50);
    $pdf->SetTextColor(75, 93, 112);
    $pdf->SetFont('Arial', '', 6);
    $pdf->SetXY(253, 164);
    $pdf->Cell(72, 9, 'SCAN TO VERIFY SAMPLE', 0, 0, 'C');

    // Prominent notice is part of every card design, not just its verification page.
    $pdf->SetFillColor(188, 58, 46);
    $pdf->Rect(0, 184, 336, 29, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(0, 193);
    $pdf->Cell(336, 12, 'SAMPLE - NOT VALID FOR IDENTIFICATION', 0, 0, 'C');

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
