<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Mail;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Mail\HtmlToPlainText;

final class HtmlToPlainTextTest extends TestCase
{
    public function testItKeepsTheReadableContentOfALayoutMail(): void
    {
        $html = <<<'HTML'
            <html><head><title>T</title><style>.e-p{color:red}</style></head><body>
            <div data-skip-text aria-hidden="true">Preheader line</div>
            <!--[if mso]><table><tr><td><![endif]-->
            <h1 class="e-h1">Nastavte si nové heslo</h1>
            <p class="e-p">Dobrý den,<br>účet <strong>jana@example.cz</strong> &amp; další.</p>
            <a href="https://wboost.cz/set-password/abc?x=1&amp;y=2" class="e-btn">Nastavit nové heslo</a>
            <table><tr><td class="e-fl" width="38%">Platnost</td><td class="e-fv">16. 11. 2026</td></tr></table>
            <p><a href="https://wboost.cz/x">https://wboost.cz/x</a> · <a href="https://wboost.cz/">WBoost</a></p>
            </body></html>
            HTML;

        $text = HtmlToPlainText::convert($html);

        self::assertStringNotContainsString('Preheader line', $text);
        self::assertStringNotContainsString('color:red', $text);
        self::assertStringNotContainsString('<', $text);
        self::assertStringContainsString("Nastavte si nové heslo\n", $text);
        self::assertStringContainsString("Dobrý den,\núčet jana@example.cz & další.", $text);
        self::assertStringContainsString('Nastavit nové heslo: https://wboost.cz/set-password/abc?x=1&y=2', $text);
        self::assertStringContainsString('Platnost: 16. 11. 2026', $text);
        self::assertStringContainsString('https://wboost.cz/x · WBoost (https://wboost.cz/)', $text);
    }
}
