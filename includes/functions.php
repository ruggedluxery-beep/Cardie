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
 * Render a polished, generic organizational member badge. This is not a
 * government or official credential template; the sample notice is printed
 * prominently on every card and a diagonal SAMPLE mark crosses its face.
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

    // A user-provided artwork background can decorate the outer canvas. Text fields are placed on a solid panel for contrast.
    $pdf->SetFillColor(237, 243, 247);
    $pdf->Rect(0, 0, 336, 213, 'F');
    $template = (string) ($organization['template_path'] ?? '');
    if ($template !== '' && is_file($template)) {
        $pdf->Image($template, 0, 0, 336, 213);
    }

    // Corporate palette and geometric accent are intentionally distinct from government credential designs.
    $pdf->SetFillColor(18, 38, 63);
    $pdf->Rect(0, 0, 336, 58, 'F');
    $pdf->SetFillColor(18, 166, 160);
    $pdf->Rect(0, 58, 336, 5, 'F');
    $pdf->SetFillColor(18, 166, 160);
    $pdf->Rect(0, 63, 7, 119, 'F');
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect(12, 67, 312, 111, 'F');

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY(20, 10);
    $pdf->Cell(210, 10, 'ORGANIZATION MEMBER BADGE', 0, 0);
    $pdf->SetTextColor(255, 205, 194);
    $pdf->SetXY(240, 10);
    $pdf->Cell(76, 10, 'SAMPLE - DEMONSTRATION', 0, 0, 'R');
    $pdf->SetTextColor(255, 255, 255);
    $orgName = fit_text((string) ($organization['name'] ?? 'Organization'), 286, 17);
    $pdf->SetFont('Arial', 'B', 17);
    $pdf->SetXY(20, 27);
    $pdf->Cell(296, 21, pdf_text($orgName), 0, 0);

    // Portrait uses a fixed frame and aspect-fit, avoiding stretched photos.
    $photoX = 21.0;
    $photoY = 76.0;
    $photoW = 76.0;
    $photoH = 88.0;
    $pdf->SetFillColor(231, 237, 243);
    $pdf->Rect($photoX, $photoY, $photoW, $photoH, 'F');
    $photo = (string) ($member['photo_path'] ?? '');
    if ($photo !== '' && is_file($photo)) {
        $info = @getimagesize($photo);
        if (is_array($info) && in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $ratio = min($photoW / max(1, (int) $info[0]), $photoH / max(1, (int) $info[1]));
            $drawW = (int) $info[0] * $ratio;
            $drawH = (int) $info[1] * $ratio;
            $pdf->Image($photo, $photoX + ($photoW - $drawW) / 2, $photoY + ($photoH - $drawH) / 2, $drawW, $drawH);
        }
    } else {
        $pdf->SetTextColor(98, 116, 135);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetXY($photoX, $photoY + 38);
        $pdf->Cell($photoW, 12, 'MEMBER PHOTO', 0, 0, 'C');
    }
    $pdf->SetDrawColor(206, 217, 227);
    $pdf->Rect($photoX, $photoY, $photoW, $photoH);

    $left = 111.0;
    $detailsRight = 240.0;
    $pdf->SetTextColor(25, 42, 60);
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetXY($left, 75);
    $pdf->Cell($detailsRight - $left, 21, pdf_text(fit_text((string) ($member['full_name'] ?? ''), $detailsRight - $left, 15)), 0, 0);

    $pdf->SetTextColor(18, 127, 132);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetXY($left, 99);
    $pdf->Cell(54, 11, 'MEMBER CODE', 0, 0);
    $pdf->SetTextColor(45, 59, 73);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($left + 56, 98);
    $pdf->Cell($detailsRight - $left - 56, 13, pdf_text(fit_text((string) ($member['member_code'] ?? ''), $detailsRight - $left - 56, 9)), 0, 0);

    $fields = [
        ['POSITION', (string) ($member['position'] ?? '')],
        ['DEPARTMENT', (string) ($member['department'] ?? '')],
        ['ISSUED', (string) ($member['issue_date'] ?? '')],
        ['EXPIRES', (string) ($member['expiry_date'] ?? '')],
    ];
    $y = 116.0;
    foreach ($fields as [$label, $value]) {
        $pdf->SetTextColor(103, 119, 135);
        $pdf->SetFont('Arial', 'B', 6);
        $pdf->SetXY($left, $y);
        $pdf->Cell(48, 11, $label, 0, 0);
        $pdf->SetTextColor(38, 54, 70);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY($left + 50, $y - 1);
        $pdf->Cell($detailsRight - $left - 50, 12, pdf_text(fit_text($value, $detailsRight - $left - 50, 8)), 0, 0);
        $y += 14;
    }

    $qr = qr_file_for_token((string) $member['card_token']);
    $pdf->Image($qr, 264, 104, 49, 49);
    $pdf->SetTextColor(75, 93, 112);
    $pdf->SetFont('Arial', 'B', 5);
    $pdf->SetXY(250, 154);
    $pdf->Cell(76, 9, 'SCAN TO VERIFY SAMPLE RECORD', 0, 0, 'C');

    // High-contrast sample banner is legible even when the card is printed at CR80 size.
    $pdf->SetFillColor(188, 58, 46);
    $pdf->Rect(0, 183, 336, 30, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(0, 192);
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
