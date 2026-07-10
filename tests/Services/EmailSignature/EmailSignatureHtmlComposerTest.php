<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\EmailSignature;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\EmailSignatureTemplate;
use WBoost\Web\Entity\EmailSignatureVariant;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\EmailSignature\EmailSignatureHtmlComposer;
use WBoost\Web\Value\EmailTextInput;

/**
 * @covers \WBoost\Web\Services\EmailSignature\EmailSignatureHtmlComposer
 */
final class EmailSignatureHtmlComposerTest extends TestCase
{
    public function testTemplateWithoutVariantUsesDefaultTexts(): void
    {
        $template = $this->template(
            code: '<table><tr><td><span id="iabc" data-text-placeholder="">Jméno</span></td></tr></table>',
            inputs: [new EmailTextInput('iabc', 'Jméno')],
        );

        $html = (new EmailSignatureHtmlComposer())->compose($template);

        self::assertStringContainsString('>Jméno</span>', $html);
    }

    public function testVariantValueWinsInPlaceholderElement(): void
    {
        $template = $this->template(
            code: '<table><tr><td><span id="iabc" data-text-placeholder="">Jméno</span></td></tr></table>',
            inputs: [new EmailTextInput('iabc', 'Jméno')],
        );

        $variant = $this->variant($template, ['iabc' => 'Jan Novák']);

        $html = (new EmailSignatureHtmlComposer())->compose($template, $variant);

        self::assertStringContainsString('Jan Novák', $html);
        self::assertStringNotContainsString('>Jméno<', $html);
        // The span itself (with its attributes) must survive the substitution.
        self::assertStringContainsString('data-text-placeholder', $html);
    }

    public function testFulltextTokensAreReplacedEverywhere(): void
    {
        $template = $this->template(
            code: '<a href="mailto:___imail___">___imail___</a>',
            inputs: [new EmailTextInput('imail', 'info@firma.cz')],
        );

        $variant = $this->variant($template, ['imail' => 'jan@firma.cz']);

        $html = (new EmailSignatureHtmlComposer())->compose($template, $variant);

        self::assertSame('<a href="mailto:jan@firma.cz">jan@firma.cz</a>', $html);
    }

    public function testVcardQrTokenIsReplacedWithUrl(): void
    {
        $template = $this->template(
            code: '<img src="___vcard_qr___" alt="QR">',
            inputs: [],
        );

        $html = (new EmailSignatureHtmlComposer())->compose($template, null, 'https://example.com/qr.png');

        self::assertSame('<img src="https://example.com/qr.png" alt="QR">', $html);
    }

    public function testVcardQrTokenIsEmptiedWithoutUrl(): void
    {
        $template = $this->template(
            code: '<img src="___vcard_qr___" alt="QR">',
            inputs: [],
        );

        $html = (new EmailSignatureHtmlComposer())->compose($template);

        self::assertSame('<img src="" alt="QR">', $html);
    }

    public function testMultibyteContentSurvivesDomRoundTrip(): void
    {
        $template = $this->template(
            code: '<p>Příliš žluťoučký <span id="ix" data-text-placeholder="">kůň</span></p>',
            inputs: [new EmailTextInput('ix', 'kůň')],
        );

        $variant = $this->variant($template, ['ix' => 'úpěl ďábelské ódy']);

        $html = (new EmailSignatureHtmlComposer())->compose($template, $variant);

        self::assertStringContainsString('Příliš žluťoučký', $html);
        self::assertStringContainsString('úpěl ďábelské ódy', $html);
    }

    /**
     * @param array<EmailTextInput> $inputs
     */
    private function template(string $code, array $inputs): EmailSignatureTemplate
    {
        $now = new \DateTimeImmutable();

        $project = new Project(
            Uuid::uuid4(),
            new User(Uuid::uuid4(), 'owner@example.com', $now),
            $now,
            'Projekt',
        );

        $template = new EmailSignatureTemplate(
            Uuid::uuid4(),
            $project,
            $now,
            'Šablona',
            $code,
            null,
        );

        $template->changeCode($code, $inputs);

        return $template;
    }

    /**
     * @param array<string, string> $textInputs
     */
    private function variant(EmailSignatureTemplate $template, array $textInputs): EmailSignatureVariant
    {
        return new EmailSignatureVariant(
            Uuid::uuid4(),
            $template,
            new \DateTimeImmutable(),
            'Varianta',
            '',
            $textInputs,
        );
    }
}
