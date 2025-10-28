<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use JeroenDesloovere\VCard\VCard;
use WBoost\Web\Entity\EmailSignatureVariant;
use WBoost\Web\Exceptions\NoVcardData;
use WBoost\Web\Value\VcardFieldType;

readonly final class VcardQrCodeGenerator
{
    /**
     * @throws NoVcardData
     */
    public function generateQrCode(EmailSignatureVariant $variant): QrCode
    {
        $vcardData = $this->collectVcardData($variant);

        if ($vcardData === []) {
            throw new NoVcardData();
        }

        $vcard = $this->buildVcard($vcardData);
        $vcardContent = $vcard->getOutput();

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

    /**
     * Collect vCard data from both dynamic inputs and static template configuration.
     *
     * @return array<string, string>
     */
    private function collectVcardData(EmailSignatureVariant $variant): array
    {
        $vcardData = [];

        // Step 1: Collect static values from template vcardInfo
        foreach ($variant->template->vcardInfo as $fieldKey => $value) {
            if ($value !== '') {
                $vcardData[$fieldKey] = $value;
            }
        }

        // Step 2: Collect dynamic values from template textInputs + variant textInputs
        // Dynamic values override static values if both exist
        foreach ($variant->template->textInputs as $textInput) {
            if ($textInput->vcardType === null) {
                continue;
            }

            $inputValue = $variant->inputValue($textInput->id);
            if ($inputValue !== null && $inputValue !== '') {
                $vcardData[$textInput->vcardType->value] = $inputValue;
            }
        }

        return $vcardData;
    }

    /**
     * @param array<string, string> $data
     */
    private function buildVcard(array $data): VCard
    {
        $vcard = new VCard();

        // Add name (required for valid vCard)
        if (isset($data[VcardFieldType::Name->value])) {
            // Split name into parts (assuming "FirstName LastName" format)
            $nameParts = explode(' ', $data[VcardFieldType::Name->value], 2);
            $lastName = $nameParts[0];
            $firstName = $nameParts[1] ?? '';
            $vcard->addName($lastName, $firstName, '', '', '');
        }

        if (isset($data[VcardFieldType::Email->value])) {
            $vcard->addEmail($data[VcardFieldType::Email->value]);
        }

        if (isset($data[VcardFieldType::Phone->value])) {
            $vcard->addPhoneNumber($data[VcardFieldType::Phone->value], 'WORK');
        }

        if (isset($data[VcardFieldType::Company->value])) {
            $vcard->addCompany($data[VcardFieldType::Company->value]);
        }

        if (isset($data[VcardFieldType::JobTitle->value])) {
            $vcard->addJobtitle($data[VcardFieldType::JobTitle->value]);
        }

        if (isset($data[VcardFieldType::Website->value])) {
            $vcard->addURL($data[VcardFieldType::Website->value]);
        }

        return $vcard;
    }
}
