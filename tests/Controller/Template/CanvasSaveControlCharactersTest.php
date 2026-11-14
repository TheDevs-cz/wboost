<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Services\Editor\CanvasStorageGuard;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Tests\TestingLogin;

/**
 * The same hostile input as the AI-scan red-team case `text-nul-rtl-zw`
 * (a NUL cannot be stored in a JSONB column — Postgres 22P05), on the three
 * ordinary ways a design is saved: the editor, the group editor and MCP
 * `set_design`. Each refuses with a message; none is a 500, none writes.
 */
final class CanvasSaveControlCharactersTest extends WebTestCase
{
    private const string FONT = 'Rubik (Rubik Bold)';

    public function testTheGuardTellsWhatAJsonbColumnTakes(): void
    {
        self::assertTrue(CanvasStorageGuard::storable('{"objects":[{"text":"Řádek\n\tdruhý \u000b 👩‍💻"}]}', '[]', ''));
        // An ESCAPED backslash followed by the letters u0000 is six ordinary characters.
        self::assertTrue(CanvasStorageGuard::storable('{"text":"C:\\\\u0000 is a path"}'));

        self::assertFalse(CanvasStorageGuard::storable('{"text":"a\u0000b"}'), 'the escape');
        self::assertFalse(CanvasStorageGuard::storable('{"text":"a\\\\\u0000b"}'), 'an escaped backslash, then the escape');
        self::assertFalse(CanvasStorageGuard::storable("{\"text\":\"a\0b\"}"), 'the raw character');
        self::assertFalse(CanvasStorageGuard::storable('{"text":"\ud800"}'), 'an unpaired surrogate');
        self::assertFalse(CanvasStorageGuard::storable('{"text":'), 'not JSON');
        self::assertFalse(CanvasStorageGuard::storable('{}', '[{"name":"x\u0000"}]'), 'any of the documents');
    }

    public function testTheEditorRefusesACanvasThatCannotBeStored(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $url = '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/editor';

        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $imageInputsName = $crawler->filter('[data-canvas-editor-target="imageInputs"]')->attr('name');
        self::assertIsString($imageInputsName);
        $prefix = substr($imageInputsName, 0, (int) strpos($imageInputsName, '['));
        $token = $crawler->filter('input[name="' . $prefix . '[_token]"]')->attr('value');
        self::assertIsString($token);

        $before = $this->canvas(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $documents = [
            ['{"version":"5.2.4","objects":[{"type":"Textbox","text":"a\u0000b"}]}', '[]'],
            ['{"version":"5.2.4","objects":[{"type":"Textbox","text":"\ud800"}]}', '[]'],
            ['{"version":"5.2.4","objects":[]}', '[{"inputId":"00000000-0000-4000-8000-0000000000bb","name":"Nadpis\u0000","maxLength":40,"locked":false,"uppercase":false,"description":null,"hidable":false}]'],
        ];

        foreach ($documents as [$canvas, $textInputs]) {
            $client->request('POST', $url, [
                $prefix => ['canvas' => $canvas, 'textInputs' => $textInputs, 'imageInputs' => '[]', 'imagePreview' => '', '_token' => $token],
            ], [], ['HTTP_ACCEPT' => 'application/json']);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['status' => 'error', 'message' => CanvasStorageGuard::MESSAGE], json_decode((string) $client->getResponse()->getContent(), true));
        }

        self::assertSame($before, $this->canvas(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));

        // A vertical tab (an office document's soft line break) is not a reason to fail a save.
        $client->request('POST', $url, [
            $prefix => ['canvas' => '{"version":"5.2.4","objects":[{"type":"Textbox","text":"a\u000bb"}]}', 'textInputs' => '[]', 'imageInputs' => '[]', 'imagePreview' => '', '_token' => $token],
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
    }

    public function testTheGroupEditorRefusesACanvasThatCannotBeStored(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $url = '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/editor';

        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-group-editor-csrf-value]')->attr('data-group-editor-csrf-value');
        self::assertIsString($token);

        $before = $this->canvas(TestDataFixture::GROUPED_PRESET_VARIANT_ID);

        $client->request('POST', $url, [
            '_token' => $token,
            'variants' => [
                TestDataFixture::GROUPED_PRESET_VARIANT_ID => ['canvas' => '{"version":"5.2.4","objects":[{"type":"Textbox","text":"a\u0000b"}]}', 'textInputs' => '[]', 'imageInputs' => '[]', 'imagePreview' => ''],
            ],
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->canvas(TestDataFixture::GROUPED_PRESET_VARIANT_ID));
    }

    public function testSetDesignRefusesAControlCharacterAsADocumentError(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $before = $this->canvas(TestDataFixture::BLANK_VARIANT_ID);

        foreach (["a\u{0}b\u{202E}cod.exe\u{200B}", "bell\u{7}"] as $text) {
            $sessionId = TestingMcpClient::connect($client, TestDataFixture::MCP_TOKEN_DESIGN_ONLY);
            TestingMcpClient::request($client, 'tools/call', [
                'name' => 'set_design',
                'arguments' => ['variantId' => TestDataFixture::BLANK_VARIANT_ID, 'design' => [
                    'canvas' => ['width' => 1080, 'height' => 1080, 'background' => ['fill' => '#ffffff']],
                    'elements' => [['kind' => 'text', 'id' => 'headline', 'text' => $text, 'font' => self::FONT, 'size' => 72, 'x' => 80, 'y' => 120, 'width' => 920]],
                ]],
            ], $sessionId, TestDataFixture::MCP_TOKEN_DESIGN_ONLY);

            self::assertResponseIsSuccessful();
            $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertArrayNotHasKey('error', $payload);
            self::assertIsArray($payload['result']);
            self::assertTrue($payload['result']['isError']);
            self::assertIsArray($payload['result']['content']);
            self::assertIsArray($payload['result']['content'][0]);
            $summary = $payload['result']['content'][0]['text'];
            self::assertIsString($summary);
            self::assertStringContainsString('control character', $summary);
            self::assertStringContainsString('elements[0].text', $summary);
        }

        self::assertSame($before, $this->canvas(TestDataFixture::BLANK_VARIANT_ID));
    }

    private function canvas(string $variantId): string
    {
        $canvas = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT canvas FROM template_variant WHERE id = ?', [$variantId]);
        self::assertIsString($canvas);

        return $canvas;
    }
}
