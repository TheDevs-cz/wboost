<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\ExportEvent;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Mcp\Tool\ExportVariantTool;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportedTemplateType;

/**
 * `export_variant` (S3-T3) — the deliverable, driven end to end over `/_mcp`.
 *
 * ## What is load-bearing here
 *
 * 1. **The bytes are PNG, at the variant's full size.**
 *    {@see FakeTemplateVariantImageRenderer} emits FORMAT-MATCHING bytes, so a
 *    PNG magic-byte assertion cannot pass while WebP was requested — which is
 *    exactly the mistake a copy of `render_variant` would make. The size half
 *    is only decidable against a real render, so it is asserted in the
 *    `gotenberg` test on real pixels.
 * 2. **Overflow REFUSES, and says something an agent can act on.** This is the
 *    contract inversion relative to `render_variant`: there the picture comes
 *    back with a warning, here nothing comes back and the message has to name
 *    the inputs and the pixels.
 * 3. **`templates:export` is a scope of its own.** A `templates:read` token is
 *    the whole point of the MCP scope model; if it could take away a full-size
 *    lossless deliverable, the model would be decoration. Proven in BOTH halves
 *    — not advertised, and refused when called by name anyway.
 * 4. **Usage is recorded, and only on success.** `/admin/usage` counts what
 *    users took away; an export that ended in a refusal took nothing away.
 */
final class ExportVariantTest extends WebTestCase
{
    /**
     * One browser per test method, created on first use — `createClient()` may
     * only be called once per test, and the handshake alone costs two requests.
     */
    private null|KernelBrowser $browser = null;

    /**
     * The happy path, and the two facts everything else rests on: the reply
     * carries a real image block, and its bytes are really PNG.
     */
    public function testReturnsAFullSizePngImageBlockAlongsideItsSummary(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        self::assertFalse($result['isError'], $result['text']);

        self::assertSame('image', $result['image']['type']);
        self::assertSame('image/png', $result['image']['mimeType']);

        $data = $result['image']['data'];
        self::assertIsString($data);

        $bytes = base64_decode($data, true);
        self::assertIsString($bytes);
        self::assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8), 'The returned bytes are not a PNG.');

        $summary = $result['summary'];
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, $summary['variantId']);
        self::assertSame('Custom Template 1', $summary['templateName']);
        self::assertSame('Project 1', $summary['projectName']);
        self::assertSame('image/png', $summary['format']);
        self::assertSame([], $summary['warnings']);
        self::assertSame(strlen($bytes), $summary['sizeBytes']);

        // A4 at 300 DPI — the FULL size, never a preview bound.
        self::assertSame(2480, $summary['width']);
        self::assertSame(3508, $summary['height']);

        $call = $this->lastRenderCall();
        self::assertSame('png', $call['format'], 'The renderer must be asked for PNG explicitly.');
        self::assertTrue($call['strictContainerOverflow'], 'An export always renders strictly.');
    }

    /**
     * The fill vocabulary is the REST export's, so a value moved between the
     * two surfaces means the same thing. Asserted on what the RENDERER
     * received, not on the request: the ids, maxLength and the sample fallback
     * all sit between the two.
     */
    public function testProvidedTextReachesTheRendererAndOmittedInputsFallBackToTheirSample(): void
    {
        $this->export(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::ORIENTATION_VARIANT_ID,
            ['inputs' => [TestDataFixture::ORIENTATION_INPUT_INTRO_ID => 'Exported by an agent']],
        );

        $texts = $this->lastRenderCall()['texts'];
        self::assertSame('Exported by an agent', $texts[TestDataFixture::ORIENTATION_INPUT_INTRO_ID] ?? null);

        $this->export(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        $texts = $this->lastRenderCall()['texts'];
        self::assertSame('Welcome to the show', $texts[TestDataFixture::ORIENTATION_INPUT_INTRO_ID] ?? null);
    }

    /**
     * The Done-when tracking half: a successful export is one {@see ExportEvent}
     * on the MCP channel, denormalised exactly as the web and API chokepoints
     * write it — including the acting user, which for MCP is the token's owner.
     */
    public function testSuccessfulExportRecordsAnExportEventOnTheMcpChannel(): void
    {
        $this->export(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        $events = $this->exportEvents(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertSame(ExportChannel::Mcp, $event->channel);
        self::assertSame(ExportedTemplateType::Template, $event->templateType);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $event->projectId->toString());
        self::assertSame(TestDataFixture::USER_1_EMAIL, $event->ownerEmail);
        self::assertNotNull($event->triggeredByUserId);
        self::assertSame(TestDataFixture::USER_1_ID, $event->triggeredByUserId->toString());
    }

    /**
     * The ORDERING assertion, and the reason recording is the last line of the
     * success path rather than the first line of the handler: a refused export
     * delivered no file, so counting it would inflate the only number
     * `/admin/usage` exists to report.
     */
    public function testRefusedExportRecordsNothing(): void
    {
        $this->armContainerOverflow(new ContainerOverflow(TestDataFixture::ORIENTATION_ROOT_CONTAINER_ID, 12.0));

        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertTrue($result['isError'], 'An overflowing fill must not export.');
        self::assertSame([], $this->exportEvents(TestDataFixture::ORIENTATION_VARIANT_ID));
    }

    /**
     * THE actionable-error requirement. `ContainerOverflow` carries a container
     * UUID and a pixel count; neither is something an agent can act on, so the
     * message names the container's fillable INPUTS — walked through the
     * nesting tree, since overflow is always reported on the ROOT container and
     * the fixture's root holds one input plus a child holding two more.
     */
    public function testContainerOverflowIsRefusedWithAMessageNamingTheInputsAndThePixels(): void
    {
        $this->armContainerOverflow(new ContainerOverflow(TestDataFixture::ORIENTATION_ROOT_CONTAINER_ID, 12.0));

        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertTrue($result['isError']);

        // The pixels, exactly as measured.
        self::assertStringContainsString('12 px', $result['text']);
        // The container's own budget, so "shorten by how much" has a scale.
        self::assertStringContainsString('700 px', $result['text']);

        // Every fillable input of the tree, by NAME and by the id that fixes
        // it — including the two that sit inside the NESTED child.
        self::assertStringContainsString('"intro"', $result['text']);
        self::assertStringContainsString(TestDataFixture::ORIENTATION_INPUT_INTRO_ID, $result['text']);
        self::assertStringContainsString('"bullets"', $result['text']);
        self::assertStringContainsString('"tasks"', $result['text']);

        // And what to DO, including the one remedy that is not the agent's.
        self::assertStringContainsString('shorten one of them', $result['text']);
        self::assertStringContainsString('maxHeight', $result['text']);
        self::assertStringContainsString('render_variant', $result['text']);
    }

    /**
     * A container the canvas does not describe (a stale definition, a truncated
     * Gotenberg body — {@see ContainerOverflow::tryFromGotenbergError()} yields
     * a null id for that one) still has to produce a refusal an agent can move
     * on from, rather than a message about an input list that could not be
     * built.
     */
    public function testOverflowOfAnUnlocatableContainerStillExplainsItself(): void
    {
        $this->armContainerOverflow(new ContainerOverflow(null, 3.5));

        $result = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('(unknown)', $result['text']);
        self::assertStringContainsString('3.5 px', $result['text']);
        self::assertStringContainsString('describe_variant', $result['text']);
    }

    /**
     * The rich-text contract, translated. The REST export answers a structured
     * 400 `{code: "font_not_allowed", allowedFonts: [...]}`; an agent cannot
     * read a body it never receives, so the code AND the allowed list are
     * folded into the sentence — the list is what makes it actionable rather
     * than merely accurate.
     */
    public function testFontOutsideTheVariantsPaletteIsRefusedWithTheAllowedFontsInTheMessage(): void
    {
        $result = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            ['inputs' => [
                TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => ['runs' => [
                    ['text' => 'x', 'fontFamily' => 'Comic Sans (Regular)'],
                ]],
            ]],
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('font_not_allowed', $result['text']);
        self::assertStringContainsString('Comic Sans (Regular)', $result['text']);
        self::assertStringContainsString('Rubik (Rubik Regular), Rubik (Rubik Bold)', $result['text']);
    }

    /**
     * The same translation with no context to append — the sentence must not
     * degrade into a dangling "Allowed values — ." when the exception carries
     * nothing extra.
     */
    public function testInvalidColourIsRefusedWithItsCode(): void
    {
        $result = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            ['inputs' => [
                TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID => ['runs' => [
                    ['text' => 'x', 'color' => 'definitely-not-hex'],
                ]],
            ]],
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('invalid_color', $result['text']);
        self::assertStringContainsString('#c8102e', $result['text'], 'The message should show the shape of a valid colour.');
        self::assertStringNotContainsString('Allowed values — .', $result['text']);
    }

    /**
     * maxLength is enforced identically to the REST export: an over-long value
     * is refused, never truncated. A deliverable the user did not ask for is
     * worse than no deliverable.
     */
    public function testOverlongValueIsRefusedRatherThanTruncated(): void
    {
        $result = $this->call(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::ORIENTATION_VARIANT_ID,
            ['inputs' => [TestDataFixture::ORIENTATION_INPUT_INTRO_ID => str_repeat('A', 121)]],
        );

        self::assertTrue($result['isError']);
        self::assertStringContainsString('exceeds max length of 120', $result['text']);
        self::assertSame([], $this->exportEvents(TestDataFixture::ORIENTATION_VARIANT_ID));
    }

    /**
     * THE anti-enumeration guarantee: a variant USER_1 may not see and an id
     * behind which there is nothing must fail with the very same words — or an
     * export token becomes an id-probing oracle for every project in the
     * database.
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
     * The FIRST tool whose scope is not `templates:read`, so this is where the
     * scope machinery stops being proven by test fixtures alone. Both halves
     * matter and they are independent: a filter is not an authorisation
     * boundary, so the tool is also called BY NAME, exactly as a client with a
     * cached listing would.
     */
    public function testReadOnlyTokenNeitherSeesNorMayCallTheExportTool(): void
    {
        $browser = $this->browser();

        self::assertNotContains(
            'export_variant',
            $this->listTools(TestDataFixture::MCP_TOKEN_READ_ONLY),
            'A templates:read token must not learn that export_variant exists.',
        );

        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);
        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'export_variant',
            'arguments' => ['variantId' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID],
        ], $sessionId, TestDataFixture::MCP_TOKEN_READ_ONLY);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        // The challenge names the scope that was MISSING, so an agent can tell
        // its user exactly what the token needs.
        $challenge = (string) $response->headers->get('WWW-Authenticate');
        self::assertStringStartsWith('Bearer ', $challenge);
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('scope="templates:export"', $challenge);

        $body = self::decode($response);
        self::assertSame('insufficient_scope', $body['error'] ?? null);
        self::assertSame('templates:export', $body['scope'] ?? null);

        self::assertSame([], $this->exportEvents(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID));
    }

    /**
     * The other half, on a token holding `templates:export` and NOTHING else —
     * `MCP_TOKEN_ACTIVE` holds every scope and so could never tell "export
     * unlocked it" apart from "design did". The read tools it also sees are the
     * implication closure at work: `templates:export` grants `templates:read`.
     */
    public function testExportScopedTokenSeesTheToolAndCanCallIt(): void
    {
        $tools = $this->listTools(TestDataFixture::MCP_TOKEN_EXPORT_ONLY);

        self::assertContains('export_variant', $tools);
        self::assertContains('describe_variant', $tools, 'templates:export implies templates:read.');

        $result = $this->call(TestDataFixture::MCP_TOKEN_EXPORT_ONLY, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        self::assertFalse($result['isError'], $result['text']);
        self::assertSame('image/png', $result['image']['mimeType']);
    }

    /**
     * The description is what makes an agent reach for the EXPENSIVE tool only
     * when it is done iterating, so the load-bearing sentences are locked here
     * — together with the generated schema, which is derived from reflection at
     * compile time and is the only proof the arguments can arrive at all.
     * `inputs`/`images` are JSON OBJECTS keyed by id: inferred from the PHP
     * `array` they would be advertised as JSON ARRAYS, and the SDK's own
     * request validator would then reject every real call before the tool ran.
     */
    public function testToolIsAdvertisedWithAnAgentFacingDescriptionAndAnObjectKeyedSchema(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_EXPORT_ONLY);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: TestDataFixture::MCP_TOKEN_EXPORT_ONLY);

        $result = self::decode($browser->getResponse())['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['tools']);

        $tool = null;

        foreach ($result['tools'] as $candidate) {
            self::assertIsArray($candidate);

            if ($candidate['name'] === 'export_variant') {
                $tool = $candidate;
            }
        }

        self::assertIsArray($tool, 'export_variant is not advertised to a templates:export token.');

        $description = $tool['description'];
        self::assertIsString($description);
        // Substrings must not span a line break — the description is the
        // reflowed `__invoke()` docblock, so a wrapped sentence never matches.
        self::assertStringContainsString('This is the DELIVERABLE', $description);
        self::assertStringContainsString('counted in the project', $description);
        self::assertStringContainsString('REFUSED here rather than drawn', $description);

        $schema = $tool['inputSchema'];
        self::assertIsArray($schema);
        $properties = $schema['properties'];
        self::assertIsArray($properties);
        self::assertSame(['variantId'], $schema['required'], 'A bare export must need nothing but the id.');

        foreach (['inputs', 'images'] as $property) {
            $declared = $properties[$property];
            self::assertIsArray($declared);
            self::assertSame('object', $declared['type'], sprintf('%s is a map keyed by id, not a list.', $property));
        }
    }

    /**
     * The one test that really talks to Gotenberg: a real A4 variant through
     * the real pipeline, handed back as an MCP image block. Everything above
     * runs against the fake, which cannot prove that the PNG really comes out
     * at the variant's designed size — and "it is a PNG" would pass against a
     * 1 × 1 stand-in, which is exactly what a downscaling copy-paste from
     * `render_variant` would produce.
     *
     * Excluded from the default suite (`phpunit.xml.dist` excludes the
     * `gotenberg` group) because it needs the container up; run it with
     * `vendor/bin/phpunit --group gotenberg`.
     *
     * The tool is assembled by hand rather than called over `/_mcp`: the test
     * environment aliases the renderer interface to the fake for every other
     * suite, and swapping that alias globally would make this file's own fast
     * tests depend on Gotenberg too. Only {@see Security} is a double — the
     * voter has nothing to do with rendering.
     */
    #[Group('gotenberg')]
    public function testExportsARealPrintVariantAtItsFullSize(): void
    {
        self::createClient();
        $container = self::getContainer();

        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        // Every collaborator straight out of the container. The concrete
        // renderer, NOT the interface: the test env aliases the interface to
        // the fake for every other suite (config/services_test.php).
        $tool = new ExportVariantTool(
            new VariantFill(
                $security,
                $container->get(TemplateVariantRepository::class),
                $container->get(ResolveTextOverrides::class),
                $container->get(ResolveRichTextOptions::class),
                $container->get(ResolveImageOverrides::class),
            ),
            $container->get(TemplateVariantImageRenderer::class),
            $container->get(RecordExportUsage::class),
        );

        $result = $tool(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, [
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => 'Gotenberg',
        ]);

        self::assertFalse($result->isError);
        self::assertCount(2, $result->content);

        $image = $result->content[1];
        self::assertInstanceOf(ImageContent::class, $image);
        self::assertSame('image/png', $image->mimeType);

        $bytes = base64_decode($image->data, true);
        self::assertIsString($bytes);

        $size = getimagesizefromstring($bytes);
        self::assertNotFalse($size, 'Gotenberg did not return a readable image.');
        self::assertSame(IMAGETYPE_PNG, $size[2]);
        // The REAL pixels: A4 at 300 DPI, full size, nothing downscaled.
        self::assertSame([2480, 3508], [$size[0], $size[1]]);

        $summary = json_decode(self::textBlock($result), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertSame(2480, $summary['width']);
        self::assertSame(3508, $summary['height']);
        self::assertSame(strlen($bytes), $summary['sizeBytes']);
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
     * Pre-arms the renderer fake to fail the way an overflowing fill really
     * fails. The overflow is set on the fake INSTANCE, so a kernel reboot
     * between the handshake and the call would silently replace it.
     */
    private function armContainerOverflow(ContainerOverflow $overflow): void
    {
        $this->browser()->disableReboot();
        $this->rendererFake()->throwContainerOverflow = $overflow;
    }

    /**
     * Calls the tool and returns its decoded summary, failing the test if the
     * tool reported an error.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function export(string $token, string $variantId, array $arguments = []): array
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
            'name' => 'export_variant',
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

        self::assertCount(2, $content, 'A successful export answers with a summary AND a picture.');
        self::assertIsArray($content[1]);

        $summary = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);

        /** @var array<string, mixed> $summary */
        /** @var array<string, mixed> $image */
        $image = $content[1];

        return ['isError' => false, 'text' => $text, 'summary' => $summary, 'image' => $image];
    }

    /**
     * @return list<string>
     */
    private function listTools(string $token): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: $token);

        $result = self::decode($browser->getResponse())['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['tools']);

        $names = [];

        foreach ($result['tools'] as $tool) {
            self::assertIsArray($tool);
            $name = $tool['name'];
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Every tracked export of one variant. The fixtures seed none, so an empty
     * result really means "this call recorded nothing".
     *
     * @return list<ExportEvent>
     */
    private function exportEvents(string $variantId): array
    {
        // Boot through the browser: `createClient()` refuses to run after the
        // kernel is already up, and `getContainer()` would boot it.
        $this->browser();

        return self::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(ExportEvent::class)
            ->findBy(['variantId' => Uuid::fromString($variantId)]);
    }

    /**
     * What the renderer was actually asked to draw.
     *
     * @return array{variantId: string, texts: array<string, string>, images: array<string, array<string, mixed>>, mode: string, strictContainerOverflow: bool, format: string}
     */
    private function lastRenderCall(): array
    {
        $fake = $this->rendererFake();

        self::assertNotSame([], $fake->calls, 'The renderer was never called.');

        $call = $fake->calls[count($fake->calls) - 1];

        return [
            'variantId' => $call['variantId'],
            'texts' => $call['texts'],
            'images' => $call['images'],
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
