<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\AiScan\AiScanUpload;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * A PDF is an accepted upload (owner decision O4: its first page, as a
 * picture): by its signature and byte size only — the web process reads no
 * page, the scanner's intake rasterises it.
 */
final class AiScanUploadPdfTest extends TestCase
{
    public function testAPdfIsAcceptedOnItsSignatureAndBytesAlone(): void
    {
        $path = self::file("%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");

        try {
            $facts = AiScanUpload::inspect($path);
        } finally {
            @unlink($path);
        }

        self::assertSame('application/pdf', $facts['contentType']);
        self::assertSame('pdf', $facts['ext']);
        self::assertNull($facts['width'], 'no page is read in the web process');
        self::assertNull($facts['height']);
        self::assertSame(64, strlen($facts['sha256']));
    }

    public function testAPdfOverTheByteLimitIsRefusedLikeAnyOtherUpload(): void
    {
        $path = self::file('%PDF-1.7' . str_repeat(' ', AiScanLimits::UPLOAD_MAX_BYTES));

        try {
            AiScanUpload::inspect($path);
            self::fail('A PDF above the byte limit must be refused.');
        } catch (AiScanRefused $refused) {
            self::assertSame('bytes', $refused->params['reason'] ?? null);
        } finally {
            @unlink($path);
        }
    }

    public function testOtherDocumentsStayRefused(): void
    {
        self::assertNull(AiScanUpload::sniff('8BPS' . str_repeat("\0", 32)), 'a Photoshop file');
        self::assertNull(AiScanUpload::sniff('%!PS-Adobe-3.0 EPSF-3.0'), 'PostScript / EPS');
        self::assertNull(AiScanUpload::sniff('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'SVG');
    }

    private static function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'aiscan-pdf-');
        file_put_contents($path, $bytes);

        return $path;
    }
}
