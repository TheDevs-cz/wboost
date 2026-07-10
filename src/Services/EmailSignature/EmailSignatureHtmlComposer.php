<?php

declare(strict_types=1);

namespace WBoost\Web\Services\EmailSignature;

use WBoost\Web\Entity\EmailSignatureTemplate;
use WBoost\Web\Entity\EmailSignatureVariant;

/**
 * Server-side mirror of the substitution done client-side by
 * email_signature_export_controller.js: fills `#<id>[data-text-placeholder]`
 * elements, the `___<id>___` fulltext tokens and the `___vcard_qr___` token.
 * Variant values win over the template's default texts, so passing no variant
 * yields the template preview with its designed placeholder texts.
 */
readonly final class EmailSignatureHtmlComposer
{
    public function compose(
        EmailSignatureTemplate $template,
        null|EmailSignatureVariant $variant = null,
        null|string $vcardQrCodeUrl = null,
    ): string {
        $values = [];

        foreach ($template->textInputs as $input) {
            $values[$input->id] = $variant?->inputValue($input->id) ?? $input->content;
        }

        $html = $template->code;

        if (str_contains($html, 'data-text-placeholder')) {
            $html = $this->fillPlaceholderElements($html, $values);
        }

        foreach ($values as $id => $value) {
            $html = str_replace('___' . $id . '___', $value, $html);
        }

        return str_replace('___vcard_qr___', $vcardQrCodeUrl ?? '', $html);
    }

    /**
     * @param array<string, string> $values
     */
    private function fillPlaceholderElements(string $html, array $values): string
    {
        $dom = new \DOMDocument();

        $useInternalErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        if ($loaded === false) {
            return $html;
        }

        $xpath = new \DOMXPath($dom);

        foreach ($values as $id => $value) {
            if (str_contains($id, '"')) {
                continue;
            }

            $elements = $xpath->query(sprintf('//*[@id="%s"][@data-text-placeholder]', $id));

            if ($elements === false) {
                continue;
            }

            foreach ($elements as $element) {
                if (!$element instanceof \DOMElement) {
                    continue;
                }

                while ($element->firstChild !== null) {
                    $element->removeChild($element->firstChild);
                }

                $element->appendChild($dom->createTextNode($value));
            }
        }

        // The signature gets embedded into a wrapper email body, so return the
        // fragment libxml parsed into <body>, not a full document.
        $body = $dom->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return $html;
        }

        $result = '';

        foreach ($body->childNodes as $child) {
            $childHtml = $dom->saveHTML($child);

            if ($childHtml !== false) {
                $result .= $childHtml;
            }
        }

        return $result;
    }
}
