<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Tests\Services\Font\VariableFontExpanderTest;

/**
 * `upload_font` — an agent adds a font file to a project; a VARIABLE font is
 * expanded into one face per named instance and the reply lists the exact
 * font strings (`"Inter (Inter ExtraBold)"`) a design document may use.
 *
 * @covers \WBoost\Web\Mcp\Tool\UploadFontTool
 */
final class UploadFontTest extends WebTestCase
{
    private const string OPEN_SANS = __DIR__ . '/../../../vendor/endroid/qr-code/assets/open_sans.ttf';

    private null|KernelBrowser $browser = null;

    protected function tearDown(): void
    {
        $filesystem = self::getContainer()->get(Filesystem::class);
        foreach ($filesystem->listContents('fonts/' . TestDataFixture::PROJECT_1_ID)->toArray() as $item) {
            $base = basename($item->path());
            if (str_starts_with($base, 'Inter ') || str_starts_with($base, 'Open Sans')) {
                $filesystem->delete($item->path());
            }
        }
        $this->browser = null;

        parent::tearDown();
    }

    public function testAVariableFontIsExpandedAndTheReplyListsTheUsableFontStrings(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, (string) file_get_contents(VariableFontExpanderTest::INTER_VARIABLE), 'Inter-VariableFont_slnt,wght.ttf');
        self::assertFalse($result['isError'], $result['text']);

        $payload = json_decode($result['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('Inter', $payload['family']);
        self::assertTrue($payload['variable']);
        self::assertSame(
            array_map(static fn (array $cut): string => 'Inter (' . $cut[0] . ')', VariableFontExpanderTest::INTER_CUTS),
            $payload['created'],
        );
        self::assertSame([], $payload['alreadyPresent']);
        self::assertIsArray($payload['fonts']);
        self::assertCount(18, $payload['fonts']);
        self::assertContains('Inter (Inter ExtraBold)', $payload['fonts']);

        $font = self::getContainer()->get(GetFonts::class)->byName(Uuid::fromString(TestDataFixture::PROJECT_1_ID), 'Inter');
        self::assertCount(18, $font->faces);

        // Uploading it again adds nothing and says so.
        $again = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, (string) file_get_contents(VariableFontExpanderTest::INTER_VARIABLE), 'Inter.ttf');
        self::assertFalse($again['isError'], $again['text']);
        $againPayload = json_decode($again['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($againPayload);
        self::assertSame([], $againPayload['created']);
        self::assertCount(18, (array) $againPayload['alreadyPresent']);
    }

    public function testAStaticFontBecomesOneFace(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, (string) file_get_contents(self::OPEN_SANS), 'OpenSans-Regular.ttf');
        self::assertFalse($result['isError'], $result['text']);

        $payload = json_decode($result['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('Open Sans', $payload['family']);
        self::assertFalse($payload['variable']);
        self::assertIsArray($payload['created']);
        self::assertCount(1, $payload['created']);
    }

    public function testBytesThatAreNotAFontAreRefusedAndNothingIsStored(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'definitely not a font', 'font.ttf');

        self::assertTrue($result['isError']);
        self::assertStringContainsString('font.ttf', $result['text']);
    }

    public function testAReadOnlyTokenCannotSeeTheTool(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);
        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: TestDataFixture::MCP_TOKEN_READ_ONLY);

        self::assertStringNotContainsString('"upload_font"', (string) $browser->getResponse()->getContent());

        $designSession = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_DESIGN_ONLY);
        TestingMcpClient::request($browser, 'tools/list', sessionId: $designSession, token: TestDataFixture::MCP_TOKEN_DESIGN_ONLY);

        self::assertStringContainsString('"upload_font"', (string) $browser->getResponse()->getContent());
    }

    /**
     * @return array{isError: bool, text: string}
     */
    private function call(string $token, string $bytes, string $filename): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'upload_font',
            'arguments' => [
                'projectId' => TestDataFixture::PROJECT_1_ID,
                'fontBase64' => base64_encode($bytes),
                'filename' => $filename,
            ],
        ], $sessionId, $token);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('error', $payload, (string) $response->getContent());
        $result = $payload['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['content']);
        self::assertIsArray($result['content'][0]);
        $text = $result['content'][0]['text'];
        self::assertIsString($text);

        return ['isError' => ($result['isError'] ?? false) === true, 'text' => $text];
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }
}
