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
use WBoost\Web\Mcp\Design\CandidateRenderer;
use WBoost\Web\Mcp\Design\DesignPreflight;
use WBoost\Web\Mcp\Design\DesignVariants;
use WBoost\Web\Mcp\Tool\PreviewDesignTool;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Image\DownscaleImage;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\Mcp\TestingMcpClient;

/**
 * `preview_design` (S5-T2) — the loop an authoring agent iterates on, and the
 * first `templates:design` tool the server has ever exposed.
 *
 * ## What is load-bearing here
 *
 * 1. **The ORDER of parse → variant fit → lint → compile → render.** A font
 *    error must stop the call BEFORE Gotenberg is touched, and the only honest
 *    way to assert that is a renderer that counts its calls — with a positive
 *    control in the same kernel, so "zero calls" cannot be explained by a fake
 *    that was never wired in ({@see testAFontErrorBlocksAndTheRendererIsNeverCalled()}).
 * 2. **Nothing persists.** The variant row is re-read from the database and
 *    then flushed, which is the half a plain before/after SELECT misses: a
 *    mutation of the MANAGED entity would sit in the unit of work until
 *    something else flushed it.
 * 3. **Warnings ride WITH the picture, errors replace it.** The two outcomes
 *    share one reply shape, and `rendered` / `errorCount` / `warningCount` are
 *    what tell them apart without reading prose.
 * 4. **Slugs carry identity.** A design whose slug matches an existing input
 *    must preview against that input's UUID — otherwise the preview is of a
 *    different variant than the one `set_design` would write.
 */
final class PreviewDesignTest extends WebTestCase
{
    /** A face string project 1 really owns — {@see TestDataFixture}'s Rubik. */
    private const string FONT = 'Rubik (Rubik Regular)';

    /** A face string no project owns. */
    private const string FOREIGN_FONT = 'Comic Sans MS (Comic Sans MS Regular)';

    /** One browser per test method — `createClient()` may only be called once. */
    private null|KernelBrowser $browser = null;

    // =================================================================
    // the happy path
    // =================================================================

    /**
     * A clean design comes back as a WebP picture with an EMPTY issue list.
     *
     * The empty list is the assertion that matters: a linter that always has
     * something to say is one an agent learns to skip, so "no issues" has to be
     * a reachable outcome for a perfectly ordinary design.
     */
    public function testACleanDesignReturnsAPictureAndNoIssuesAtAll(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::ORIENTATION_VARIANT_ID, self::cleanDesign());

        self::assertFalse($result['isError'], $result['text']);

        $summary = $result['summary'];
        self::assertTrue($summary['rendered']);
        self::assertSame(0, $summary['errorCount']);
        self::assertSame(0, $summary['warningCount']);
        self::assertSame([], $summary['issues']);
        self::assertStringContainsString('found nothing to fix', self::text($summary['status']));
        self::assertStringContainsString('Nothing was saved', self::text($summary['status']));

        // The variant's real size is reported even though the picture is a
        // downscaled copy — an agent measuring anything off the preview needs
        // both numbers, and the next design document needs the canvas one.
        self::assertSame(1080, $summary['canvasWidth']);
        self::assertSame(1080, $summary['canvasHeight']);
        self::assertSame('image/webp', $summary['format']);

        self::assertSame('image', $result['image']['type']);
        self::assertSame('image/webp', $result['image']['mimeType']);

        $data = $result['image']['data'];
        self::assertIsString($data);
        $bytes = base64_decode($data, true);
        self::assertIsString($bytes);
        // The fake emits FORMAT-MATCHING bytes, so this proves the renderer was
        // asked for WebP rather than being allowed to keep the PNG default that
        // every export path depends on.
        self::assertSame('RIFF', substr($bytes, 0, 4), 'The returned bytes are not a WebP.');
        self::assertSame('WEBP', substr($bytes, 8, 4), 'The returned bytes are not a WebP.');

        $call = $this->lastRenderCall();
        self::assertSame('webp', $call['format']);
        // Lenient: the linter already predicts overflow, and a preview that
        // refused to draw an overflowing design would be the one reply that
        // helps nobody.
        self::assertFalse($call['strictContainerOverflow']);
        self::assertNull($call['slice'], 'a candidate render must never take the sliced (cacheable) path');
    }

    /**
     * Warnings do not block: the picture comes back AND the concerns are listed,
     * each with the severity that says so.
     */
    public function testWarningsAreReturnedTogetherWithThePicture(): void
    {
        $result = $this->call(
            TestDataFixture::MCP_TOKEN_DESIGN_ONLY,
            TestDataFixture::ORIENTATION_VARIANT_ID,
            self::designWithWarnings(),
        );

        self::assertFalse($result['isError'], 'A warning must never be an error.');

        $summary = $result['summary'];
        self::assertTrue($summary['rendered']);
        self::assertSame(0, $summary['errorCount']);
        self::assertSame(2, $summary['warningCount']);
        self::assertSame('image', $result['image']['type']);

        // Document order within the element: the linter runs colour before
        // geometry, and the merged list preserves each stage's own ordering.
        self::assertSame(
            ['color_not_in_palette', 'out_of_canvas_bounds'],
            self::codes($summary),
        );

        foreach (self::issues($summary) as $issue) {
            self::assertSame('warning', $issue['severity']);
            self::assertSame('lint', $issue['stage']);
            self::assertSame('headline', $issue['slug']);
            self::assertStringStartsWith('elements[0]', self::text($issue['path']));
        }

        self::assertStringContainsString('2 warnings are worth reading', self::text($summary['status']));
    }

    // =================================================================
    // the blocking half
    // =================================================================

    /**
     * THE done-when. A font this project does not have is an ERROR, and it stops
     * the call before any render is attempted.
     *
     * The zero is made non-vacuous by the control in the same test and the same
     * kernel: a clean design DOES reach the renderer, so the count staying at 1
     * across the second call is the tool declining to render, not the fake
     * failing to be wired in. The kernel is pinned (`disableReboot`) because a
     * reboot between the two calls would hand back a fresh fake with an
     * accidentally-correct zero.
     */
    public function testAFontErrorBlocksAndTheRendererIsNeverCalled(): void
    {
        $browser = $this->browser();
        $browser->disableReboot();

        $control = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::ORIENTATION_VARIANT_ID, self::cleanDesign());
        self::assertFalse($control['isError'], $control['text']);
        self::assertCount(1, $this->rendererFake()->calls, 'control: a clean design must reach the renderer');

        $result = $this->call(
            TestDataFixture::MCP_TOKEN_DESIGN_ONLY,
            TestDataFixture::ORIENTATION_VARIANT_ID,
            self::designWithAForeignFontAndAnOffCanvasElement(),
        );

        self::assertCount(
            1,
            $this->rendererFake()->calls,
            'a font error must block BEFORE any render is attempted — the renderer was called again',
        );

        self::assertTrue($result['isError']);

        $summary = $result['summary'];
        self::assertFalse($summary['rendered']);
        self::assertNull($summary['format'], 'nothing was drawn, so there is no image to describe');
        self::assertNull($summary['width']);
        self::assertNull($summary['height']);
        self::assertNull($summary['downscaled']);
        self::assertSame([], $result['image'], 'a blocked pass answers with the summary only');

        self::assertSame(1, $summary['errorCount']);
        self::assertStringContainsString('1 error is in the way', self::text($summary['status']));

        // …and the advisory findings of the SAME pass travel with it. This is
        // the whole reason the linter reports font_not_allowed instead of
        // letting the compiler abort: one round trip, not two.
        self::assertSame(1, $summary['warningCount']);
        self::assertStringContainsString('1 warning is advisory', self::text($summary['status']));

        self::assertSame(['font_not_allowed', 'out_of_canvas_bounds'], self::codes($summary));

        $error = self::issues($summary)[0];
        self::assertSame('error', $error['severity']);
        self::assertSame('lint', $error['stage']);
        self::assertSame('elements[0].font', $error['path']);
        self::assertSame('headline', $error['slug']);
        self::assertStringContainsString(self::FOREIGN_FONT, self::text($error['message']));
        // Self-correcting: the message names the faces that WOULD have worked.
        self::assertStringContainsString(self::FONT, self::text($error['message']));

        self::assertSame('warning', self::issues($summary)[1]['severity']);
    }

    /**
     * A malformed document is answered COMPLETELY: every violation the parser
     * found, each addressed by its own path, in one reply. Five problems fixed
     * in one turn instead of five.
     */
    public function testEveryParseViolationIsReportedAtOnceWithItsPath(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::ORIENTATION_VARIANT_ID, [
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                // `fontSize` is the hallucinated key; `font` is then missing.
                ['kind' => 'text', 'id' => 'headline', 'text' => 'Hi', 'fontSize' => 48, 'size' => 48, 'x' => 0, 'y' => 0, 'width' => 500],
                // …and the slug is already taken.
                ['kind' => 'text', 'id' => 'headline', 'text' => 'Again', 'font' => self::FONT, 'size' => 20, 'x' => 0, 'y' => 600, 'width' => 500],
            ],
        ]);

        self::assertTrue($result['isError']);

        $summary = $result['summary'];
        self::assertFalse($summary['rendered']);
        self::assertSame(0, $summary['warningCount'], 'a document that does not parse cannot be linted');

        $issues = self::issues($summary);
        self::assertCount(3, $issues);

        self::assertSame(
            [
                ['unknown_key', 'elements[0].fontSize'],
                ['missing_key', 'elements[0].font'],
                ['duplicate_id', 'elements[1].id'],
            ],
            array_map(static fn (array $issue): array => [$issue['code'], $issue['path']], $issues),
        );

        foreach ($issues as $issue) {
            self::assertSame('error', $issue['severity']);
            self::assertSame('parse', $issue['stage'], 'the stage is what tells the agent to re-read the grammar');
            self::assertArrayNotHasKey('slug', $issue, 'a document that failed to parse has no elements to name');
        }

        // The unknown-key message suggests the key that was meant, which is the
        // difference between a fixable mistake and a guess.
        self::assertStringContainsString('Did you mean "size"?', self::text($issues[0]['message']));
    }

    /**
     * A well-formed document naming a picture this project does not have is
     * refused by the COMPILE stage — a different stage, because it is a
     * different fix (call `list_gallery`, not re-read the grammar).
     */
    public function testAnUnknownGalleryAssetIsReportedAsACompileError(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::ORIENTATION_VARIANT_ID, [
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                ['kind' => 'image', 'id' => 'photo', 'asset' => '00000000-0000-0000-0000-0000000000ff', 'x' => 80, 'y' => 80, 'width' => 400, 'height' => 300],
            ],
        ]);

        self::assertTrue($result['isError']);

        $summary = $result['summary'];
        self::assertFalse($summary['rendered']);
        self::assertSame(['asset_not_found'], self::codes($summary));

        $issue = self::issues($summary)[0];
        self::assertSame('compile', $issue['stage']);
        self::assertSame('elements[0].asset', $issue['path']);
        self::assertStringContainsString('list_gallery', self::text($issue['message']));
    }

    /**
     * A design authored for another canvas size is a WARNING, not a refusal —
     * the render uses the variant's size, so the picture itself is the clearest
     * possible description of what went wrong.
     */
    public function testADesignWrittenForAnotherCanvasSizeIsWarnedAboutAndStillDrawn(): void
    {
        $design = self::cleanDesign();
        $design['canvas'] = ['width' => 2480, 'height' => 3508];

        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::ORIENTATION_VARIANT_ID, $design);

        self::assertFalse($result['isError']);
        self::assertTrue($result['summary']['rendered']);

        $issue = self::issues($result['summary'])[0];
        self::assertSame('canvas_size_mismatch', $issue['code']);
        self::assertSame('variant', $issue['stage']);
        self::assertSame('warning', $issue['severity']);
        self::assertSame('canvas', $issue['path']);
        self::assertStringContainsString('2480 x 3508', self::text($issue['message']));
        self::assertStringContainsString('1080 x 1080', self::text($issue['message']));
    }

    // =================================================================
    // "does not persist"
    // =================================================================

    /**
     * The variant row is untouched by a preview — and stays untouched through a
     * `flush()`, which is what catches a mutation still sitting in Doctrine's
     * unit of work waiting for something else in the request to write it.
     */
    public function testNothingIsPersisted(): void
    {
        $browser = $this->browser();
        $browser->disableReboot();

        $before = $this->row();

        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::ORIENTATION_VARIANT_ID, self::cleanDesign());
        self::assertFalse($result['isError'], $result['text']);

        // The positive control: the preview really did draw the CANDIDATE
        // canvas, so "nothing changed" is not trivially true of a no-op.
        $call = $this->lastRenderCall();
        self::assertNotSame($before['canvas'], $call['canvas'], 'the preview must have rendered a different canvas');

        $after = $this->row();
        self::assertSame($before, $after, 'preview_design must not write the variant row');
        self::assertSame($before['canvas'], $after['canvas']);
        self::assertSame($before['preview_image_path'], $after['preview_image_path']);

        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame($before, $this->row(), 'the managed entity must not even be DIRTY after a preview');
    }

    /**
     * Slug → `inputId` stability. A design whose slug matches an input the
     * variant already has previews against THAT input's UUID; only genuinely new
     * slugs mint.
     *
     * Read off the renderer's recorded call, because the candidate variant is
     * the only place these ids exist — nothing was written, so there is no row
     * to inspect.
     */
    public function testSlugsThatAlreadyNameAnInputKeepTheirIds(): void
    {
        $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::ORIENTATION_VARIANT_ID, [
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                // "intro" is the name of the variant's first text input, so
                // DesignSlug names it "intro" on both sides.
                ['kind' => 'text', 'id' => 'intro', 'text' => 'Lead', 'font' => self::FONT, 'size' => 40, 'x' => 80, 'y' => 100, 'width' => 900],
                ['kind' => 'text', 'id' => 'brand-new', 'text' => 'Fresh', 'font' => self::FONT, 'size' => 30, 'x' => 80, 'y' => 700, 'width' => 900],
            ],
        ]);

        $inputIds = $this->lastRenderCall()['inputIds'];
        self::assertCount(2, $inputIds);

        self::assertSame(
            TestDataFixture::ORIENTATION_INPUT_INTRO_ID,
            $inputIds[0],
            'a slug that already names an input must not be handed a new id — every saved fill and every API consumer keys on it',
        );

        self::assertNotSame(TestDataFixture::ORIENTATION_INPUT_INTRO_ID, $inputIds[1]);
        self::assertTrue(Uuid::isValid($inputIds[1]), 'a genuinely new slug mints a UUID');
        self::assertSame(4, Uuid::fromString($inputIds[1])->getVersion(), 'inputIds are v4 (plan §4.1-2)');
    }

    // =================================================================
    // authorisation
    // =================================================================

    /**
     * A GROUPED variant is previewed, not refused — deliberately.
     *
     * Plan §4.5-22 refuses group-created variants at the WRITE boundary,
     * mirroring `TemplateVariantEditorController`'s redirect, because a
     * single-variant save would be clobbered by the next group save. A preview
     * writes nothing, so the same refusal here would only stop an agent from
     * LOOKING at a design it is allowed to reason about. S5-T3 adds the refusal
     * where it means something.
     */
    public function testAGroupedVariantIsPreviewedRatherThanRefused(): void
    {
        $result = $this->call(
            TestDataFixture::MCP_TOKEN_DESIGN_ONLY,
            TestDataFixture::GROUPED_PRESET_VARIANT_ID,
            self::cleanDesign(),
        );

        self::assertFalse($result['isError'], $result['text']);
        self::assertTrue($result['summary']['rendered']);
        self::assertSame(TestDataFixture::GROUPED_PRESET_VARIANT_ID, $result['summary']['variantId']);
    }

    /**
     * A variant this account cannot see is indistinguishable from one that does
     * not exist — the same anti-enumeration rule, and the very same wording, the
     * fill tools use.
     */
    public function testForeignVariantIsIndistinguishableFromAnUnknownId(): void
    {
        $foreign = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID, self::cleanDesign());
        $unknown = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, '00000000-0000-0000-0000-0000000000ff', self::cleanDesign());

        self::assertTrue($foreign['isError']);
        self::assertTrue($unknown['isError']);

        self::assertSame(
            str_replace(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID, '<id>', $foreign['text']),
            str_replace('00000000-0000-0000-0000-0000000000ff', '<id>', $unknown['text']),
        );

        self::assertStringContainsString('was not found, or this account cannot access it', $foreign['text']);
    }

    /**
     * …but a variant the caller may VIEW and not EDIT gets the REAL reason.
     *
     * The anti-enumeration rule protects existence, and a viewer has already
     * been told the variant exists — `find_templates` lists it and
     * `render_variant` draws it. Answering "not found" there would hide nothing
     * and would send the agent hunting for a wrong id.
     *
     * Driven in-process: no fixture token combines `templates:design` with an
     * account that only has viewing rights, and the HTTP scope gate would answer
     * first anyway.
     */
    public function testAVariantThatMayBeViewedButNotEditedGetsTheRealReason(): void
    {
        $this->browser();

        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => $attribute === TemplateVariantVoter::VIEW,
        );

        $variants = new DesignVariants($security, self::getContainer()->get(TemplateVariantRepository::class));

        try {
            $variants->editable(TestDataFixture::ORIENTATION_VARIANT_ID);

            self::fail('a view-only account must not be able to design.');
        } catch (\Mcp\Exception\ToolCallException $refusal) {
            self::assertStringContainsString('can be read by this account but not designed on', $refusal->getMessage());
            self::assertStringContainsString('render_variant', $refusal->getMessage());
            self::assertStringNotContainsString('was not found', $refusal->getMessage());
        }
    }

    /**
     * `preview_design` is the FIRST `templates:design` tool: until now that
     * scope had no production tool behind it. So both halves are pinned here —
     * a read token is not shown it and cannot call it anyway (a filter is not an
     * authorisation boundary), and the design token can do both.
     *
     * The other direction — a `templates:design` token still seeing every
     * `templates:read` tool through {@see \WBoost\Web\Mcp\Security\McpScope::grants()}
     * — is pinned by `ScopeFilteringTest::testDesignTokenInheritsTheReadToolsThroughImplication()`.
     */
    public function testReadOnlyTokenNeitherSeesNorMayCallTheDesignTool(): void
    {
        self::assertNotContains('preview_design', $this->listTools(TestDataFixture::MCP_TOKEN_READ_ONLY));

        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'preview_design',
            'arguments' => ['variantId' => TestDataFixture::ORIENTATION_VARIANT_ID, 'design' => self::cleanDesign()],
        ], $sessionId, TestDataFixture::MCP_TOKEN_READ_ONLY);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('scope="templates:design"', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function testDesignScopedTokenSeesTheToolAndMayCallIt(): void
    {
        self::assertContains('preview_design', $this->listTools(TestDataFixture::MCP_TOKEN_DESIGN_ONLY));
    }

    // =================================================================
    // the advertised contract
    // =================================================================

    /**
     * The description is what makes a model reach for this tool in the middle of
     * an authoring loop, and the generated schema is the only proof the
     * arguments can arrive at all: `design` is a NESTED JSON OBJECT, and
     * inferred from the PHP `array` it would be advertised as a JSON ARRAY —
     * whereupon the SDK's own request validator rejects every real call before
     * the tool body runs.
     */
    public function testToolIsAdvertisedWithAnObjectSchemaForTheDesignDocument(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_DESIGN_ONLY);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: TestDataFixture::MCP_TOKEN_DESIGN_ONLY);

        $result = self::decode($browser->getResponse())['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['tools']);

        $tool = null;

        foreach ($result['tools'] as $candidate) {
            self::assertIsArray($candidate);

            if ($candidate['name'] === 'preview_design') {
                $tool = $candidate;
            }
        }

        self::assertIsArray($tool, 'preview_design is not advertised to a templates:design token.');

        $description = $tool['description'];
        self::assertIsString($description);
        // Substrings must not span a line break — the description is the
        // reflowed `__invoke()` docblock, so a wrapped sentence never matches.
        self::assertStringContainsString('WITHOUT SAVING ANYTHING', $description);
        self::assertStringContainsString('This is the iteration loop', $description);
        self::assertStringContainsString('warnings always come back WITH the picture', $description);
        self::assertStringContainsString('An unknown font face is an error, not a warning', $description);

        $schema = $tool['inputSchema'];
        self::assertIsArray($schema);
        self::assertSame(['variantId', 'design'], $schema['required']);

        $properties = $schema['properties'];
        self::assertIsArray($properties);
        $design = $properties['design'];
        self::assertIsArray($design);
        self::assertSame('object', $design['type'], 'design is a nested object, not a list.');
    }

    // =================================================================
    // the real thing
    // =================================================================

    /**
     * The one test that really talks to Gotenberg: a DSL document parsed,
     * linted, compiled and drawn by the real pipeline into real WebP bytes, with
     * the variant row proven untouched afterwards.
     *
     * Excluded from the default suite (`phpunit.xml.dist` excludes the
     * `gotenberg` group); run it with `vendor/bin/phpunit --group gotenberg`.
     *
     * Assembled by hand rather than called over `/_mcp`, exactly as
     * `RenderVariantTest` does it: the test environment aliases the renderer
     * interface to the fake for every other suite, and swapping that alias
     * globally would make this file's fast tests depend on Gotenberg too. Only
     * {@see Security} is a double — the voter has nothing to do with rendering.
     */
    #[Group('gotenberg')]
    public function testRendersARealDesignThroughGotenbergWithoutWritingAnything(): void
    {
        $this->browser();
        $container = self::getContainer();

        $before = $this->row();

        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        $tool = new PreviewDesignTool(
            new DesignVariants($security, $container->get(TemplateVariantRepository::class)),
            $container->get(DesignPreflight::class),
            new CandidateRenderer(
                // The CONCRETE renderer: the test env aliases the interface to
                // the fake (config/services_test.php).
                $container->get(TemplateVariantImageRenderer::class),
                $container->get(ResolveTextOverrides::class),
                $container->get(ResolveRichTextOptions::class),
            ),
            new DownscaleImage(),
        );

        $result = $tool(TestDataFixture::ORIENTATION_VARIANT_ID, self::cleanDesign());

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
        // 1080 × 1080 is already inside the 1200 px bound, so the real render
        // comes back at the variant's exact size.
        self::assertSame([1080, 1080], [$size[0], $size[1]]);

        $summary = json_decode(self::textBlock($result), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($summary);
        self::assertTrue($summary['rendered']);
        self::assertSame([], $summary['issues']);
        self::assertFalse($summary['downscaled']);

        self::assertSame($before, $this->row(), 'a real preview must still write nothing');
    }

    // =================================================================
    // fixtures
    // =================================================================

    /**
     * Two texts, project fonts, inside the canvas, default (neutral) colour —
     * the linter has nothing to say about it.
     *
     * @return array<string, mixed>
     */
    private static function cleanDesign(): array
    {
        return [
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                [
                    'kind' => 'text', 'id' => 'headline', 'text' => 'Stand-in headline',
                    'font' => self::FONT, 'size' => 72, 'x' => 80, 'y' => 120, 'width' => 920,
                    'input' => ['name' => 'Headline'],
                ],
                [
                    'kind' => 'text', 'id' => 'legal', 'text' => 'Small print',
                    'font' => self::FONT, 'size' => 24, 'x' => 80, 'y' => 860, 'width' => 920,
                    'input' => ['name' => 'Legal'],
                ],
            ],
        ];
    }

    /**
     * One element, two warnings: it runs off the right edge and it is painted in
     * a colour no brand manual of this project knows.
     *
     * @return array<string, mixed>
     */
    private static function designWithWarnings(): array
    {
        return [
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                [
                    'kind' => 'text', 'id' => 'headline', 'text' => 'Too far right',
                    'font' => self::FONT, 'size' => 64, 'color' => '#123456',
                    'x' => 800, 'y' => 100, 'width' => 600,
                ],
            ],
        ];
    }

    /**
     * The blocking fixture, deliberately ALSO carrying an advisory finding: the
     * reply has to contain both.
     *
     * @return array<string, mixed>
     */
    private static function designWithAForeignFontAndAnOffCanvasElement(): array
    {
        return [
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                [
                    'kind' => 'text', 'id' => 'headline', 'text' => 'Wrong face',
                    'font' => self::FOREIGN_FONT, 'size' => 64, 'x' => 80, 'y' => 100, 'width' => 900,
                ],
                [
                    'kind' => 'text', 'id' => 'runaway', 'text' => 'Off the page',
                    'font' => self::FONT, 'size' => 40, 'x' => 900, 'y' => 400, 'width' => 500,
                ],
            ],
        ];
    }

    // =================================================================
    // helpers
    // =================================================================

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * The persisted row, read straight from the database rather than from the
     * entity: an in-memory entity would happily report a value Doctrine has not
     * written (and, after a mutation, one it is about to).
     *
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $this->browser();

        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT canvas, preview_image_path, background_image, background_mode, inputs, image_inputs
             FROM template_variant WHERE id = ?',
            [TestDataFixture::ORIENTATION_VARIANT_ID],
        );

        self::assertIsArray($row);

        return $row;
    }

    /**
     * The raw tool outcome, split into its blocks. A tool error is an ordinary
     * HTTP 200 JSON-RPC RESULT carrying `isError: true` — that is the MCP
     * contract, so the model can read it and correct itself. Both outcomes carry
     * the SAME JSON summary in block 0; only the picture is conditional.
     *
     * @param array<string, mixed> $design
     *
     * @return array{isError: bool, text: string, summary: array<string, mixed>, image: array<string, mixed>}
     */
    private function call(string $token, string $variantId, array $design): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'preview_design',
            'arguments' => ['variantId' => $variantId, 'design' => $design],
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
        // NOT JSON_THROW_ON_ERROR: a refusal that is NOT about the document (an
        // unusable id, a variant this account cannot reach, a busy renderer) is
        // a plain sentence rather than the summary shape, and that is the
        // contract — those failures predate the design pipeline and are worded
        // identically to the fill tools'.
        $summary = json_decode($text, true);

        if (!is_array($summary)) {
            return ['isError' => $isError, 'text' => $text, 'summary' => [], 'image' => []];
        }

        /** @var array<string, mixed> $summary */
        if ($isError) {
            self::assertCount(1, $content, 'a blocked pass answers with the summary and nothing else.');

            return ['isError' => true, 'text' => $text, 'summary' => $summary, 'image' => []];
        }

        self::assertCount(2, $content, 'a rendered preview answers with a summary AND a picture.');
        self::assertIsArray($content[1]);

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

        /** @var list<string> $names */
        $names = [];

        foreach ($result['tools'] as $tool) {
            self::assertIsArray($tool);
            $name = $tool['name'] ?? null;
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    /**
     * A `mixed` out of a decoded JSON reply, narrowed to the string it has to
     * be. Every field asserted through this one is part of the tool's contract,
     * so "it is a string" is worth failing on rather than casting away.
     */
    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return list<array<string, mixed>>
     */
    private static function issues(array $summary): array
    {
        $issues = $summary['issues'] ?? null;
        self::assertIsArray($issues);

        /** @var list<array<string, mixed>> $typed */
        $typed = [];

        foreach ($issues as $issue) {
            self::assertIsArray($issue);
            /** @var array<string, mixed> $issue */
            $typed[] = $issue;
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return list<string>
     */
    private static function codes(array $summary): array
    {
        return array_map(
            static fn (array $issue): string => self::text($issue['code']),
            self::issues($summary),
        );
    }

    /**
     * What the renderer was actually asked to draw.
     *
     * @return array{variantId: string, canvas: string, inputIds: list<string>, slice: null|array{int, null|int, bool}, strictContainerOverflow: bool, format: string}
     */
    private function lastRenderCall(): array
    {
        $fake = $this->rendererFake();

        self::assertNotSame([], $fake->calls, 'The renderer was never called.');

        $call = $fake->calls[count($fake->calls) - 1];

        return [
            'variantId' => $call['variantId'],
            'canvas' => $call['canvas'],
            'inputIds' => $call['inputIds'],
            'slice' => $call['slice'],
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
