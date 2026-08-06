<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Mcp\Tool\RenderVariantTool;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Image\DownscaleImage;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\Mcp\TestingMcpClient;

/**
 * `render_variant` (S3-T2) — driven end to end over `/_mcp`, like every other
 * tool suite: the SDK derives the input schema from reflection at COMPILE time,
 * so a tool can be perfectly correct in isolation and still fail to register.
 *
 * ## What is actually load-bearing here
 *
 * 1. **The bytes are WebP, not merely requested as WebP.**
 *    {@see FakeTemplateVariantImageRenderer} emits FORMAT-MATCHING bytes on
 *    purpose, so asserting the RIFF/WEBP magic proves the request reached the
 *    renderer as WebP — an assertion that could not pass if someone let the
 *    `RenderImageFormat` default (PNG, which every export path depends on)
 *    stand in.
 * 2. **The payload is an image CONTENT BLOCK.** A base64 blob hidden inside a
 *    text block would look identical in a `json_decode` and would render as
 *    gibberish in a real client.
 * 3. **Overflow warns, it does not refuse.** This is the one contract
 *    `render_variant` relaxes relative to `export_variant`, and the picture
 *    still has to come back.
 * 4. **A variant this account cannot see is indistinguishable from one that
 *    does not exist** — or any token becomes an id-probing oracle.
 */
final class RenderVariantTest extends WebTestCase
{
    /**
     * One browser per test method, created on first use — `createClient()` may
     * only be called once per test, and the handshake alone costs two requests.
     */
    private null|KernelBrowser $browser = null;

    /**
     * The happy path, and the two facts everything else rests on: the reply
     * carries a real image block, and its bytes are really WebP.
     */
    public function testReturnsAWebpImageBlockAlongsideItsSummary(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertFalse($result['isError'], $result['text']);

        self::assertSame('image', $result['image']['type']);
        self::assertSame('image/webp', $result['image']['mimeType']);

        $data = $result['image']['data'];
        self::assertIsString($data);

        $bytes = base64_decode($data, true);
        self::assertIsString($bytes);
        self::assertSame('RIFF', substr($bytes, 0, 4), 'The returned bytes are not a WebP.');
        self::assertSame('WEBP', substr($bytes, 8, 4), 'The returned bytes are not a WebP.');

        $summary = $result['summary'];
        self::assertSame(TestDataFixture::ORIENTATION_VARIANT_ID, $summary['variantId']);
        self::assertSame('Orientation Template', $summary['templateName']);
        self::assertSame('Project 1', $summary['projectName']);
        self::assertSame('image/webp', $summary['format']);
        self::assertSame([], $summary['warnings']);

        // The DESIGNED size is reported separately from the returned picture's
        // — an agent measuring anything off the preview needs both.
        self::assertSame(1080, $summary['canvasWidth']);
        self::assertSame(1080, $summary['canvasHeight']);

        $call = $this->lastRenderCall();
        self::assertSame('webp', $call['format'], 'The renderer must be asked for WebP explicitly.');
        self::assertSame(TestDataFixture::ORIENTATION_VARIANT_ID, $call['variantId']);
    }

    /**
     * A print variant reports the size `export_variant` would produce (A4 at
     * 300 DPI), which is the number that tells an agent the preview it is
     * looking at is a downscaled copy. The scaling itself is asserted on real
     * bytes in {@see \WBoost\Web\Tests\Services\Image\DownscaleImageTest} — the
     * fake renderer here emits a 1×1 pixel, so nothing about resizing is
     * decidable in this suite.
     */
    public function testReportsThePrintVariantsRealCanvasSize(): void
    {
        $summary = $this->render(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
        );

        self::assertSame(2480, $summary['canvasWidth'], 'A4 at 300 DPI.');
        self::assertSame(3508, $summary['canvasHeight']);
        self::assertFalse($summary['downscaled'], 'A 1×1 fake render is already inside the bound.');
    }

    /**
     * The point of the whole tool: what the caller sends is what gets drawn.
     * Asserted on the resolver's OUTPUT (what the renderer received), not on
     * the request — the ids, the maxLength and the sample fallback all sit
     * between the two.
     */
    public function testProvidedTextReachesTheRendererAndOmittedInputsFallBackToTheirSample(): void
    {
        $this->render(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::ORIENTATION_VARIANT_ID,
            ['inputs' => [TestDataFixture::ORIENTATION_INPUT_INTRO_ID => 'Rendered by an agent']],
        );

        $texts = $this->lastRenderCall()['texts'];

        self::assertSame('Rendered by an agent', $texts[TestDataFixture::ORIENTATION_INPUT_INTRO_ID] ?? null);

        // Same call, an input nobody addressed: the designer's "Vzorový text"
        // renders, exactly as it does for the REST export.
        $this->render(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertSame(
            'Welcome to the show',
            $this->lastRenderCall()['texts'][TestDataFixture::ORIENTATION_INPUT_INTRO_ID] ?? null,
        );
    }

    /**
     * The `images` map takes the SAME shapes the REST export documents, keyed
     * by the same ids — this is the cheapest possible proof that the two
     * surfaces share one vocabulary.
     */
    public function testProvidedImageFillReachesTheRenderer(): void
    {
        $this->seedGalleryFile('fixtures/in-allowed.png');

        $this->render(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
            ['images' => [
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_ALLOWED_ID,
            ]],
        );

        self::assertArrayHasKey(
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID,
            $this->lastRenderCall()['images'],
        );
    }

    /**
     * A picture the slot's folders do not allow is refused here just as it is
     * on export — the preview must never show a fill the export would reject.
     */
    public function testImageFromAForbiddenFolderIsRefused(): void
    {
        $result = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
            ['images' => [
                TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID => TestDataFixture::FILE_IN_OTHER_ID,
            ]],
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('image is not in an allowed folder', $result['text']);
    }

    /**
     * THE difference from `export_variant`. An overflowing fill still produces
     * a picture, and the warning has to carry both the container and the pixels
     * — that is what the agent shortens the text against.
     *
     * The fake only throws on the STRICT call, which is exactly the shape of
     * the real thing: overflow is measurable only on the strict path, so the
     * tool probes strictly and then re-renders leniently for the picture.
     */
    public function testContainerOverflowIsWarnedAndTheImageStillComesBack(): void
    {
        $browser = $this->browser();
        // The overflow is pre-armed on the fake INSTANCE; a kernel reboot
        // between the handshake and the call would silently replace it.
        $browser->disableReboot();
        $this->rendererFake()->throwContainerOverflow = new ContainerOverflow('container-1', 42.5);

        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertFalse($result['isError'], 'Overflow is a warning here, never a refusal.');
        self::assertSame('image', $result['image']['type']);

        $warnings = $result['summary']['warnings'];
        self::assertIsArray($warnings);
        self::assertCount(1, $warnings);
        self::assertIsString($warnings[0]);
        self::assertStringContainsString('container-1', $warnings[0]);
        self::assertStringContainsString('42.5 px', $warnings[0]);
        self::assertStringContainsString('export_variant will refuse this fill', $warnings[0]);

        // Only the LENIENT render produced bytes — the strict probe failed
        // before the fake recorded anything.
        self::assertFalse($this->lastRenderCall()['strictContainerOverflow']);
    }

    /**
     * maxLength is NOT relaxed the way overflow is: a value the export would
     * refuse must be refused here too, or the preview becomes a trap.
     */
    public function testOverlongValueIsRefusedExactlyAsTheExportRefusesIt(): void
    {
        $result = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::ORIENTATION_VARIANT_ID,
            ['inputs' => [TestDataFixture::ORIENTATION_INPUT_INTRO_ID => str_repeat('A', 121)]],
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('exceeds max length of 120', $result['text']);
    }

    /**
     * Unknown ids are silently ignored by the resolvers (the REST contract).
     * For an agent, silence is the worst possible answer — a fill keyed by
     * NAMES renders the untouched design and looks like the tool did nothing.
     */
    public function testIdsThatAddressNothingAreWarnedAboutRatherThanIgnoredInSilence(): void
    {
        $summary = $this->render(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::ORIENTATION_VARIANT_ID,
            [
                'inputs' => ['intro' => 'keyed by name, not by id'],
                'images' => ['00000000-0000-0000-0000-0000000000aa' => TestDataFixture::FILE_IN_ALLOWED_ID],
            ],
        );

        $warnings = $summary['warnings'];
        self::assertIsArray($warnings);
        self::assertCount(2, $warnings);
        self::assertIsString($warnings[0]);
        self::assertIsString($warnings[1]);
        self::assertStringContainsString('match no text input', $warnings[0]);
        self::assertStringContainsString('intro', $warnings[0]);
        self::assertStringContainsString('match no image placeholder', $warnings[1]);
    }

    /**
     * A locked input is addressable-looking and unwritable — the other way an
     * agent's value can vanish without a trace.
     */
    public function testAddressingALockedInputIsWarnedAbout(): void
    {
        $summary = $this->render(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
            ['inputs' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_LOCKED_ID => 'ignored']],
        );

        $warnings = $summary['warnings'];
        self::assertIsArray($warnings);
        self::assertCount(1, $warnings);
        self::assertIsString($warnings[0]);
        self::assertStringContainsString('locked by the designer', $warnings[0]);
    }

    /**
     * The Done-when "shared" case: the voter is what decides, so a user the
     * project is shared with renders the same variant the owner does.
     */
    public function testSharedUserCanRenderTheSameVariant(): void
    {
        $summary = $this->render(TestDataFixture::MCP_TOKEN_SHARED_USER, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertSame(TestDataFixture::ORIENTATION_VARIANT_ID, $summary['variantId']);
    }

    /**
     * THE anti-enumeration guarantee, worded identically to
     * `describe_variant`'s: a variant USER_1 may not see and an id behind which
     * there is nothing must fail with the very same words.
     */
    public function testForeignVariantIsIndistinguishableFromAnUnknownId(): void
    {
        $foreign = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID,
        );
        $unknown = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, '00000000-0000-0000-0000-0000000000ff');

        self::assertTrue($foreign['isError']);
        self::assertTrue($unknown['isError']);

        // Only the echoed id may differ.
        self::assertSame(
            str_replace(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID, '<id>', $foreign['text']),
            str_replace('00000000-0000-0000-0000-0000000000ff', '<id>', $unknown['text']),
        );

        self::assertStringContainsString('was not found, or this account cannot access it', $foreign['text']);
    }

    /**
     * A string that cannot be a variant id is NOT folded into the not-found
     * message: it leaks nothing, and an agent that sent a template id or a name
     * needs to be told so.
     */
    public function testMalformedVariantIdIsRejectedWithAnActionableMessage(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'Orientation Template');

        self::assertTrue($result['isError']);
        self::assertStringContainsString('is not a valid template variant id', $result['text']);
        self::assertStringContainsString('find_templates', $result['text']);
    }

    /**
     * The description is what makes an agent reach for THIS tool while
     * iterating and for `export_variant` when it is done, so the load-bearing
     * sentences are locked here — together with the generated schema, which is
     * derived from reflection at compile time and is the only proof the
     * arguments can arrive at all. `inputs`/`images` are JSON OBJECTS keyed by
     * id: inferred from the PHP `array` they would be advertised as JSON
     * ARRAYS, and the SDK's own request validator would then reject every real
     * call before the tool ran.
     */
    public function testToolIsAdvertisedWithAnAgentFacingDescriptionAndAnObjectKeyedSchema(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: TestDataFixture::MCP_TOKEN_READ_ONLY);

        $result = self::decode($browser->getResponse())['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['tools']);

        $tool = null;

        foreach ($result['tools'] as $candidate) {
            self::assertIsArray($candidate);

            if ($candidate['name'] === 'render_variant') {
                $tool = $candidate;
            }
        }

        self::assertIsArray($tool, 'render_variant is not advertised to a templates:read token.');

        $description = $tool['description'];
        self::assertIsString($description);
        // Substrings must not span a line break — the description is the
        // reflowed `__invoke()` docblock, so a wrapped sentence never matches.
        self::assertStringContainsString('This is the FAST LOOK', $description);
        self::assertStringContainsString('export_variant for the full-size lossless PNG', $description);
        self::assertStringContainsString('not refused', $description);

        $schema = $tool['inputSchema'];
        self::assertIsArray($schema);
        $properties = $schema['properties'];
        self::assertIsArray($properties);
        self::assertSame(['variantId'], $schema['required'], 'A bare render must need nothing but the id.');

        foreach (['inputs', 'images'] as $property) {
            $declared = $properties[$property];
            self::assertIsArray($declared);
            self::assertSame('object', $declared['type'], sprintf('%s is a map keyed by id, not a list.', $property));
        }
    }

    /**
     * The one test that really talks to Gotenberg: a real A4 variant rendered
     * through the real pipeline, downscaled, and handed back as an MCP image
     * block. Everything above runs against the fake, which cannot prove that
     * Chromium's WebP encoder and ImageMagick's WebP decoder actually agree.
     *
     * Excluded from the default suite (`phpunit.xml.dist` excludes the
     * `gotenberg` group) because it needs the container up; run it with
     * `vendor/bin/phpunit --group gotenberg`.
     *
     * The tool is assembled by hand rather than called over `/_mcp`: the test
     * environment aliases the renderer interface to the fake for every other
     * suite, and swapping that alias globally would make this file's own fast
     * tests depend on Gotenberg too. Only {@see Security} is a double — the
     * voter has nothing to do with rendering, and every other collaborator is
     * the real service.
     */
    #[Group('gotenberg')]
    public function testRendersARealPrintVariantThroughGotenberg(): void
    {
        self::createClient();
        $container = self::getContainer();

        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        // Every collaborator straight out of the container. The concrete
        // renderer, NOT the interface: the test env aliases the interface to
        // the fake for every other suite (config/services_test.php).
        $tool = new RenderVariantTool(
            $security,
            $container->get(TemplateVariantRepository::class),
            $container->get(TemplateVariantImageRenderer::class),
            $container->get(ResolveTextOverrides::class),
            $container->get(ResolveRichTextOptions::class),
            $container->get(ResolveImageOverrides::class),
            new DownscaleImage(),
        );

        $result = $tool(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, [
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Gotenberg',
        ]);

        self::assertFalse($result->isError);
        self::assertCount(2, $result->content);

        $image = $result->content[1];
        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/webp', $image->mimeType);

        $bytes = base64_decode($image->data, true);
        self::assertIsString($bytes);

        $size = getimagesizefromstring($bytes);
        self::assertNotFalse($size, 'Gotenberg did not return a readable image.');
        self::assertSame(IMAGETYPE_WEBP, $size[2]);
        // The exact downscaled A4 — deliberately not "≤ 1200", which a 1×1
        // stand-in would also satisfy. These numbers can only come from a real
        // 2480 × 3508 Chromium screenshot that really went through ImageMagick.
        self::assertSame([848, 1200], [$size[0], $size[1]]);

        $summary = json_decode(self::textBlock($result), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertTrue($summary['downscaled']);
        self::assertSame(848, $summary['width']);
        self::assertSame(1200, $summary['height']);
        self::assertSame([], $summary['warnings']);
    }

    /**
     * The JSON summary out of a {@see CallToolResult} built in-process.
     */
    private static function textBlock(CallToolResult $result): string
    {
        $text = $result->content[0];
        self::assertInstanceOf(TextContent::class, $text);
        // TextContent::$text is `mixed` in the SDK — the constructor stringifies
        // whatever it is handed, but the property type does not say so.
        self::assertIsString($text->text);

        return $text->text;
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * Puts real bytes behind a gallery fixture row. Resolving an image fill
     * reads the picture's natural size straight from the object store (a local
     * directory under test), so a row without a file is a 400 rather than a
     * fill — and the local adapter writes to disk, so one seed survives the
     * kernel reboot between the handshake and the call.
     */
    private function seedGalleryFile(string $path): void
    {
        // Boot through the browser: `createClient()` refuses to run after the
        // kernel is already up, and `getContainer()` would boot it.
        $this->browser();

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);

        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write($path, $png);
    }

    /**
     * Calls the tool and returns its decoded summary, failing the test if the
     * tool reported an error.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function render(string $token, string $variantId, array $arguments = []): array
    {
        $result = $this->call($token, $variantId, $arguments);

        self::assertFalse($result['isError'], $result['text']);

        return $result['summary'];
    }

    /**
     * The raw tool outcome, split into its two content blocks. A tool error is
     * an ordinary HTTP 200 JSON-RPC RESULT carrying `isError: true` — that is
     * the MCP contract, so the model can read the message and correct itself
     * instead of seeing a protocol failure. An error carries only the text
     * block, which is why `image` and `summary` are empty in that case.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{isError: bool, text: string, summary: array<string, mixed>, image: array<string, mixed>}
     */
    private function call(string $token, string $variantId, array $arguments = []): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'render_variant',
            'arguments' => ['variantId' => $variantId] + $arguments,
        ], $sessionId, $token);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = self::decode($response);
        self::assertArrayNotHasKey('error', $payload, (string) $response->getContent());

        $result = $payload['result'];
        self::assertIsArray($result);

        $content = $result['content'];
        self::assertIsArray($content);
        self::assertIsArray($content[0]);

        $text = $content[0]['text'];
        self::assertIsString($text);

        $isError = $result['isError'] === true;

        if ($isError) {
            return ['isError' => true, 'text' => $text, 'summary' => [], 'image' => []];
        }

        self::assertCount(2, $content, 'A successful render answers with a summary AND a picture.');
        self::assertIsArray($content[1]);

        $summary = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);

        /** @var array<string, mixed> $summary */
        /** @var array<string, mixed> $image */
        $image = $content[1];

        return ['isError' => false, 'text' => $text, 'summary' => $summary, 'image' => $image];
    }

    /**
     * What the renderer was actually asked to draw.
     *
     * @return array{variantId: string, texts: array<string, string>, hidden: array<string, bool>, images: array<string, array<string, mixed>>, imagesHidden: list<string>, mode: string, strictContainerOverflow: bool, format: string}
     */
    private function lastRenderCall(): array
    {
        $fake = $this->rendererFake();

        self::assertNotSame([], $fake->calls, 'The renderer was never called.');

        $call = $fake->calls[count($fake->calls) - 1];

        return [
            'variantId' => $call['variantId'],
            'texts' => $call['texts'],
            'hidden' => $call['hidden'],
            'images' => $call['images'],
            'imagesHidden' => $call['imagesHidden'],
            'mode' => $call['mode'],
            'strictContainerOverflow' => $call['strictContainerOverflow'],
            'format' => $call['format'],
        ];
    }

    private function rendererFake(): FakeTemplateVariantImageRenderer
    {
        // In the test env the renderer interface aliases to the fake (see
        // config/services_test.php). PHPStan reads the dev container.xml, where
        // the alias points at the real implementation.
        $renderer = self::getContainer()->get(TemplateVariantImageRendererInterface::class);
        /** @phpstan-ignore staticMethod.impossibleType */
        self::assertInstanceOf(FakeTemplateVariantImageRenderer::class, $renderer);

        return $renderer;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
