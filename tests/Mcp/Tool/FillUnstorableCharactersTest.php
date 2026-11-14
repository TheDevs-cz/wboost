<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\Mcp\TestingMcpClient;

/**
 * The fill tools refuse a value that cannot be stored (the character U+0000)
 * with the REST code `invalid_value`, before anything is drawn — the MCP half
 * of {@see \WBoost\Web\Tests\Api\FillUnstorableCharactersApiTest}. Before, an
 * `export_variant` answered its picture while the version was silently not
 * recorded.
 *
 * @covers \WBoost\Web\Services\Template\FillValueGuard
 * @covers \WBoost\Web\Mcp\Fill\VariantFill
 * @covers \WBoost\Web\Mcp\Tool\RenderVariantTool
 * @covers \WBoost\Web\Mcp\Tool\ExportVariantTool
 */
final class FillUnstorableCharactersTest extends WebTestCase
{
    private const string VARIANT = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;
    private const string HEADLINE = TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID;

    private null|KernelBrowser $browser = null;

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function calls(): iterable
    {
        foreach (['render_variant', 'export_variant'] as $tool) {
            yield $tool . ': a text' => [$tool, ['inputs' => [self::HEADLINE => "a\0b"]]];
            yield $tool . ': a rich run' => [$tool, ['inputs' => [self::HEADLINE => ['runs' => [['text' => "a\0b"]]]]]];
            yield $tool . ': an id nobody carries' => [$tool, ['inputs' => ["x\0y" => 'a']]];
            yield $tool . ': an image id' => [$tool, ['images' => [TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID => "\0"]]];
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('calls')]
    public function testANulIsRefusedWithTheRestCode(string $tool, array $arguments): void
    {
        $result = $this->call($tool, ['variantId' => self::VARIANT] + $arguments);

        self::assertTrue($result['isError'], $result['text']);
        self::assertStringContainsString('(invalid_value)', $result['text']);
        self::assertStringContainsString('U+0000', $result['text']);
        self::assertStringNotContainsString("\0", $result['text']);

        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);
        self::assertSame([], $renderer->calls, 'refused before anything is drawn');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(TemplateExportVersion::class)->count([]));
        self::assertSame(0, $em->getRepository(ExportEvent::class)->count([]));
    }

    public function testOtherControlCharactersAreKept(): void
    {
        $result = $this->call('export_variant', ['variantId' => self::VARIANT, 'inputs' => [self::HEADLINE => "a\u{0B}b"]]);

        self::assertFalse($result['isError'], $result['text']);

        $version = self::getContainer()->get(EntityManagerInterface::class)->getRepository(TemplateExportVersion::class)->findOneBy(['variant' => self::VARIANT]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);
        self::assertSame("a\u{0B}b", $version->fillValues->texts[self::HEADLINE] ?? null);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{isError: bool, text: string}
     */
    private function call(string $tool, array $arguments): array
    {
        $browser = $this->browser ??= self::createClient();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_ACTIVE);
        TestingMcpClient::request($browser, 'tools/call', ['name' => $tool, 'arguments' => $arguments], $sessionId, TestDataFixture::MCP_TOKEN_ACTIVE);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('error', $payload, (string) $response->getContent());
        self::assertIsArray($payload['result']);
        self::assertIsArray($payload['result']['content']);
        self::assertIsArray($payload['result']['content'][0]);
        $text = $payload['result']['content'][0]['text'] ?? '';
        self::assertIsString($text);

        return ['isError' => ($payload['result']['isError'] ?? false) === true, 'text' => $text];
    }
}
