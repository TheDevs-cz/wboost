<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use JeroenDesloovere\VCard\VCard;
use WBoost\Web\Entity\EmailSignatureVariant;

readonly final class VcardQrCodeGenerator
{
    public function generateQrCode(EmailSignatureVariant $variant): QrCode
    {
        // Generate vCard with static test data
        $vcard = new VCard();
        $vcard->addName('Doe', 'John', '', '', '');
        $vcard->addEmail('john.doe@example.com');
        $vcard->addPhoneNumber('+420 123 456 789', 'WORK');
        $vcard->addCompany('Example s.r.o.');
        $vcard->addJobtitle('Marketing Manager');

        $vcardContent = $vcard->getOutput();

        // Generate QR code with custom styling (dark blue color)
        return new QrCode(
            data: $vcardContent,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 300,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(26, 54, 93), // Dark blue #1a365d
            backgroundColor: new Color(255, 255, 255), // White
        );
    }
}
