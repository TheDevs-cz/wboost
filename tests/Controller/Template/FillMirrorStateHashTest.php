<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * The single fill page's settle contract, end to end: the hash the BROWSER
 * computes over the mirrors (assets/controllers/fill_state_hash.js reads each
 * mirror's `.value` / `.checked`) must equal the `data-state-hash` the server
 * stamps on the settle source — for multi-line texts too.
 *
 * Regression (prod, 2026-11-05, "jumping text on hiding an element"): the text
 * mirror was an `<input type="text">`, whose value sanitization strips every
 * "\n" the moment the browser parses `value="…"`. The server kept the
 * newlines, so the two hashes never matched. The first edit that put the page
 * into echo mode — the eye toggle, which only flips a checkbox — repainted
 * EVERY echo-capable text from the stripped mirrors (multi-line texts snapped
 * onto one line: the jump), and since no settle could ever match, the page
 * stayed on that wrong echo for good.
 *
 * The test reads every mirror the way a browser exposes it to JS (HTML parser
 * + value sanitization), so it fails for any mirror markup that loses bytes.
 *
 * @covers \WBoost\Web\Twig\Components\AbstractVariantFiller
 */
final class FillMirrorStateHashTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testBrowserVisibleMirrorsHashToTheServerStampForMultiLineTexts(): void
    {
        $rendered = $this->renderFiller([
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => "O vaše zdraví\npečujeme po celý rok!",
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID => "\nstarts with a newline",
        ]);

        self::assertSame($this->serverHash($rendered), $this->browserHash($rendered));
    }

    /**
     * A loaded export version (or draft) can seed CRLF line breaks — a
     * `<textarea>` form POST encodes newlines as CRLF. The browser normalizes
     * them to "\n" (HTML parser input-stream preprocessing + the textarea API
     * value), so the server hash must canonicalize the same way, or every
     * settle of a CRLF-seeded fill is "stale" and the echo never rests.
     */
    public function testCrlfSeededTextsHashLikeTheBrowserSeesThem(): void
    {
        $rendered = $this->renderFiller([
            TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => "first\r\nsecond\rthird",
        ]);

        self::assertSame($this->serverHash($rendered), $this->browserHash($rendered));
    }

    /**
     * @param array<string, string> $textValues
     */
    private function renderFiller(array $textValues): string
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        return (string) $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: [
                'variant' => $this->loadVariant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
                'textValues' => $textValues,
            ],
            client: $client,
        )->render();
    }

    private function serverHash(string $rendered): string
    {
        $source = (new Crawler($rendered))->filter('#variant-backdrop-source, [data-variant-fill-overlay-target="previewSource"]');
        self::assertCount(1, $source);

        return (string) $source->attr('data-state-hash');
    }

    /**
     * PHP port of fill_state_hash.js over the browser-visible mirror values.
     */
    private function browserHash(string $rendered): string
    {
        $crawler = new Crawler($rendered);
        $parts = [];

        $groups = [
            ['[data-text-mirror]', 'data-text-mirror', 'T'],
            ['[data-hide-mirror]', 'data-hide-mirror', 'H'],
            ['[data-font-mirror]', 'data-font-mirror', 'F'],
            ['[data-shape-color-mirror]', 'data-shape-color-mirror', 'S'],
        ];
        foreach ($groups as [$selector, $attribute, $prefix]) {
            $rows = [];
            foreach ($crawler->filter($selector) as $node) {
                $mirror = new Crawler($node);
                $value = $prefix === 'H'
                    ? ($mirror->attr('checked') !== null ? '1' : '0')
                    : self::browserValue($mirror);
                $rows[(string) $mirror->attr($attribute)] = $value;
            }
            ksort($rows, SORT_STRING);
            foreach ($rows as $id => $value) {
                $parts[] = $prefix . ':' . $id . '=' . $value;
            }
        }

        $hash = 5381;
        $bytes = implode("\n", $parts);
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $hash = (($hash * 33) & 0xFFFFFFFF) ^ ord($bytes[$i]);
        }

        return (string) $hash;
    }

    /**
     * What `element.value` returns in a browser for the parsed markup.
     */
    private static function browserValue(Crawler $mirror): string
    {
        $tag = strtolower($mirror->nodeName());

        if ($tag === 'textarea') {
            // DomCrawler's HTML5 parser already does what a browser's does:
            // CRLF/CR → LF and ONE newline right after the start tag dropped.
            return $mirror->text('', false);
        }

        $value = (string) $mirror->attr('value');
        $type = strtolower((string) ($mirror->attr('type') ?? 'text'));

        // Value sanitization of text-like inputs strips line breaks
        // (type=hidden has none and keeps them).
        return $type === 'hidden' ? $value : str_replace(["\r", "\n"], '', $value);
    }

    private function loadVariant(string $id): TemplateVariant
    {
        $repository = self::getContainer()->get(TemplateVariantRepository::class);

        return $repository->get(Uuid::fromString($id));
    }
}
