<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Mcp\Schema\Content\ImageContent;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Mcp\Design\CandidateRenderer;
use WBoost\Web\Mcp\Design\DesignOverwriteGuard;
use WBoost\Web\Mcp\Design\DesignPreflight;
use WBoost\Web\Mcp\Design\DesignVariants;
use WBoost\Web\Mcp\Tool\SetDesignTool;
use WBoost\Web\Message\Template\StoreTemplateVariantPreviewImage;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Image\DownscaleImage;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Fakes\FakeTemplateVariantImageRenderer;
use WBoost\Web\Tests\Mcp\TestingMcpClient;

/**
 * `set_design` (S5-T3) — the commit, and the only MCP tool that can destroy
 * somebody's work.
 *
 * ## What is load-bearing here
 *
 * 1. **The data-loss guard.** A variant whose background was uploaded through
 *    the add/edit-variant form has a picture the DSL cannot name, and writing
 *    any document over it blanks that background. The write is REFUSED, the
 *    refusal names what would be lost, and — the assertion that actually
 *    matters — the row is byte-identical afterwards. An explicit
 *    `acknowledgeLosses` is the only way through, and it turns the same
 *    findings into warnings so the transcript records the destruction.
 * 2. **Nothing is written unless everything passed.** A blocked document leaves
 *    the row untouched *through a `flush()`*, which is the half a before/after
 *    SELECT misses: a mutation of the managed entity would otherwise sit in the
 *    unit of work waiting for something else in the request to write it.
 * 3. **Slugs carry identity across writes.** Two `set_design` calls with the
 *    same slugs must produce the same `inputId` UUIDs — that is the entire
 *    point of §4.1, and what keeps saved fills and API consumers resolving.
 * 4. **The thumbnail is real.** The row's `preview_image_path` is set AND the
 *    object exists in storage; the tool renders it server-side because the
 *    canvas handler is told `previewImageDataUri: ''` (keep what you have).
 *
 * ⚠️ Object storage is NOT transactional. DAMA rolls the database back between
 * tests; the thumbnails this suite writes are removed in {@see tearDown()}.
 */
final class SetDesignTest extends WebTestCase
{
    /** A face string project 1 really owns — {@see TestDataFixture}'s Rubik. */
    private const string FONT = 'Rubik (Rubik Regular)';

    /** A face string no project owns. */
    private const string FOREIGN_FONT = 'Comic Sans MS (Comic Sans MS Regular)';

    /** One browser per test method — `createClient()` may only be called once. */
    private null|KernelBrowser $browser = null;

    /**
     * Thumbnails written during a test. Minio (a local directory in the test
     * env) survives the DAMA rollback, so every object this suite creates is
     * removed by hand.
     *
     * @var list<string>
     */
    private array $storedObjects = [];

    protected function tearDown(): void
    {
        if ($this->browser !== null) {
            $filesystem = self::getContainer()->get(Filesystem::class);

            foreach ($this->storedObjects as $path) {
                if ($filesystem->fileExists($path)) {
                    $filesystem->delete($path);
                }
            }
        }

        $this->storedObjects = [];

        parent::tearDown();
    }

    // =================================================================
    // the happy path — and the round trip
    // =================================================================

    /**
     * THE done-when: a design written by `set_design` comes back out of
     * `describe_variant`.
     *
     * The round trip is asserted through the REAL tool over `/_mcp` rather than
     * against the canvas column, because `describe_variant` is what an agent
     * actually reads back — it re-derives every frame through
     * `TextInputObjectBinder`'s positional contract, so a canvas that were
     * written in an order the binder disagrees with would show up here as
     * mismatched frames rather than as a passing byte comparison.
     */
    public function testACleanDesignIsSavedAndRoundTripsThroughDescribeVariant(): void
    {
        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::BLANK_VARIANT_ID, self::cleanDesign());

        self::assertFalse($result['isError'], $result['text']);

        $summary = $result['summary'];
        self::assertTrue($summary['saved']);
        self::assertSame(0, $summary['errorCount']);
        self::assertSame(0, $summary['warningCount']);
        self::assertSame([], $summary['issues'], 'an empty variant has nothing to lose and a clean design nothing to fix');
        self::assertSame(1080, $summary['canvasWidth']);
        self::assertSame(1080, $summary['canvasHeight']);
        self::assertStringContainsString('Saved.', self::text($summary['status']));
        self::assertStringContainsString(
            '/template-variant/' . TestDataFixture::BLANK_VARIANT_ID . '/editor',
            self::text($summary['editorUrl']),
        );
        self::assertStringStartsWith('http', self::text($summary['editorUrl']), 'editorUrl must be absolute — an agent pastes it to a human');

        self::assertSame('image', $result['image']['type']);
        self::assertSame('image/png', $result['image']['mimeType']);
        self::assertSame('image/png', $summary['format']);

        // …and now read it back the way an agent would.
        $described = $this->describe(TestDataFixture::BLANK_VARIANT_ID);

        $inputs = $described['inputs'];
        self::assertIsArray($inputs);
        self::assertCount(2, $inputs, 'the design declared two text elements');

        self::assertSame(
            [['Headline', false, false], ['Legal', false, false]],
            array_map(
                static function (mixed $input): array {
                    self::assertIsArray($input);

                    return [$input['name'], $input['locked'], $input['hidable']];
                },
                $inputs,
            ),
            'the designed input metadata must survive the write, in document order',
        );

        // The ids describe_variant publishes are the ids that were written —
        // this is the handle every fill and every API consumer uses.
        self::assertSame(
            $this->inputIds(TestDataFixture::BLANK_VARIANT_ID),
            array_map(
                static function (mixed $input): string {
                    self::assertIsArray($input);

                    return self::text($input['id']);
                },
                $inputs,
            ),
        );

        self::assertFalse($described['grouped']);
        self::assertSame([], $described['imageInputs']);
        self::assertSame([], $described['containers']);

        // ⚠️ `frame` is null on a freshly compiled textbox, and that is the
        // COMPILER's contract rather than a defect here: §4.2-6 deliberately
        // omits `height`, because Fabric computes it from the wrapped content
        // and authoring one would desynchronise container reflow from the
        // design. `describe_variant` documents `frame` as nullable for exactly
        // this reason. What the write does own is the geometry it stated, so
        // that is asserted against the canvas itself.
        self::assertSame(
            [[80.0, 120.0, 920.0], [80.0, 860.0, 920.0]],
            array_map(
                static function (array $object): array {
                    return array_map(
                        static function (mixed $value): float {
                            self::assertIsNumeric($value);

                            return (float) $value;
                        },
                        [$object['left'], $object['top'], $object['width']],
                    );
                },
                $this->canvasObjects(TestDataFixture::BLANK_VARIANT_ID),
            ),
        );
    }

    /**
     * The thumbnail is rendered server-side and really lands: the row points at
     * `custom-templates/preview/{variantId}.png` and the object EXISTS.
     *
     * Both halves are needed. `EditTemplateVariantCanvasEditor` is dispatched
     * with `previewImageDataUri: ''`, which its handler reads as "keep the
     * existing thumbnail" — so a row pointing at a path proves nothing unless
     * the bytes are there, and bytes with no pointer would never be shown.
     */
    public function testTheThumbnailIsRenderedStoredAndPersisted(): void
    {
        $path = $this->expectThumbnail(TestDataFixture::BLANK_VARIANT_ID);

        self::assertNull($this->row(TestDataFixture::BLANK_VARIANT_ID)['preview_image_path'], 'precondition: the fixture has no thumbnail');

        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::BLANK_VARIANT_ID, self::cleanDesign());
        self::assertFalse($result['isError'], $result['text']);

        self::assertTrue($result['summary']['thumbnailUpdated']);
        self::assertSame($path, $this->row(TestDataFixture::BLANK_VARIANT_ID)['preview_image_path']);

        $filesystem = self::getContainer()->get(Filesystem::class);
        self::assertTrue($filesystem->fileExists($path), 'the thumbnail object was never written to storage');
        self::assertSame(
            "\x89PNG",
            substr($filesystem->read($path), 0, 4),
            'the stored thumbnail must be a PNG — the key says .png and the app serves it as one',
        );

        // One Gotenberg call per commit: the returned picture and the stored
        // thumbnail are two crops of the SAME render, which is what lets the
        // reply claim "this is exactly what was saved".
        self::assertCount(1, $this->rendererFake()->calls);

        $call = $this->lastRenderCall();
        self::assertSame('png', $call['format']);
        self::assertFalse(
            $call['strictContainerOverflow'],
            'the commit renders LENIENT: the linter reports overflow as a warning, and a warning must never block',
        );
        self::assertNull($call['slice'], 'a candidate render must never take the sliced (cacheable) path');
    }

    // =================================================================
    // 🚨 the data-loss guard
    // =================================================================

    /**
     * The hazard S4-T5 found, refused.
     *
     * `FORM_BACKGROUND_VARIANT_ID` carries a background stored at
     * `custom-templates/{variantId}/background-*.png` with no `file_upload` row
     * — the production shape, hit by 1 of 5 sampled real canvases. The DSL
     * addresses pictures by gallery id only, so no document can name it, and
     * writing one would leave the variant with no background at all.
     *
     * Three things are asserted, and the third is the point of the whole test:
     * the write is refused, the refusal says exactly what would be lost and how
     * to proceed, and the row is UNCHANGED — through a `flush()`, so a mutation
     * parked in the unit of work cannot pass for "nothing happened".
     */
    public function testAnUnnameableBackgroundRefusesTheWriteAndLeavesTheRowUntouched(): void
    {
        $browser = $this->browser();
        $browser->disableReboot();

        $before = $this->row(TestDataFixture::FORM_BACKGROUND_VARIANT_ID);

        $result = $this->call(
            TestDataFixture::MCP_TOKEN_DESIGN_ONLY,
            TestDataFixture::FORM_BACKGROUND_VARIANT_ID,
            self::cleanDesign(),
        );

        self::assertTrue($result['isError'], 'a write that would destroy an unnameable background must be REFUSED, not warned about');

        $summary = $result['summary'];
        self::assertFalse($summary['saved']);
        self::assertNull($summary['format'], 'nothing was drawn, because nothing was going to be written');
        self::assertSame([], $result['image']);

        // The document itself is flawless — every finding is about the design
        // being replaced. That separation is what `stage: overwrite` exists for.
        foreach (self::issues($summary) as $issue) {
            self::assertSame('overwrite', $issue['stage']);
            self::assertSame('error', $issue['severity']);
        }

        self::assertSame(
            ['object_restacked', 'asset_unresolved'],
            self::codes($summary),
            'the stored canvas keeps isBackground at index 1 — §4.3-11 is an invariant of the COMPILER, not of what is stored',
        );

        $unresolved = self::issues($summary)[1];
        $message = self::text($unresolved['message']);
        self::assertStringContainsString(TestDataFixture::FORM_BACKGROUND_PATH, $message, 'the refusal must name the picture that would be lost');
        self::assertStringContainsString('leaves the variant with NO background', $message);
        // …and the preferred fix, which is to keep the thing rather than to
        // accept losing it.
        self::assertStringContainsString('upload the picture to the gallery first', $message);

        // The escape hatch is taught exactly once, in the sentence a model
        // reads first — and only on a refusal, so it cannot be reached before
        // its cost has been enumerated.
        $status = self::text($summary['status']);
        self::assertStringContainsString('NOTHING was saved', $status);
        self::assertStringContainsString('acknowledgeLosses: true', $status);
        self::assertStringContainsString('would DESTROY what they list', $status);

        // Gotenberg is never touched: a refused write has no picture to draw.
        self::assertSame([], $this->rendererFake()->calls);

        $after = $this->row(TestDataFixture::FORM_BACKGROUND_VARIANT_ID);
        self::assertSame($before, $after, 'a refused set_design must not write a single column');

        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame(
            $before,
            $this->row(TestDataFixture::FORM_BACKGROUND_VARIANT_ID),
            'the managed entity must not even be DIRTY after a refusal',
        );
    }

    /**
     * …and the way through. An agent that is deliberately REPLACING the design
     * must not be blocked forever by a background it never intended to keep.
     *
     * The same findings come back as WARNINGS — that is the record of what was
     * destroyed, and it is why the acknowledgement is a boolean rather than a
     * silent bypass.
     */
    public function testAcknowledgingTheLossesLetsTheWriteThroughAndRecordsThemAsWarnings(): void
    {
        $this->expectThumbnail(TestDataFixture::FORM_BACKGROUND_VARIANT_ID);

        $result = $this->call(
            TestDataFixture::MCP_TOKEN_DESIGN_ONLY,
            TestDataFixture::FORM_BACKGROUND_VARIANT_ID,
            self::cleanDesign(),
            acknowledgeLosses: true,
        );

        self::assertFalse($result['isError'], $result['text']);

        $summary = $result['summary'];
        self::assertTrue($summary['saved']);
        self::assertSame(0, $summary['errorCount']);
        self::assertSame(2, $summary['warningCount']);

        self::assertSame(['object_restacked', 'asset_unresolved'], self::codes($summary));

        foreach (self::issues($summary) as $issue) {
            self::assertSame('overwrite', $issue['stage']);
            self::assertSame('warning', $issue['severity'], 'an acknowledged loss is recorded, not hidden');
        }

        self::assertStringContainsString('what the previous design lost', self::text($summary['status']));

        // And the destruction really happened — that is what was acknowledged.
        // The old canvas held a background layer; the written one does not, and
        // the denormalized layer-mode pointer is nulled with it.
        $row = $this->row(TestDataFixture::FORM_BACKGROUND_VARIANT_ID);
        self::assertNull($row['background_image']);
        self::assertStringNotContainsString(TestDataFixture::FORM_BACKGROUND_PATH, self::text($row['canvas']));
    }

    /**
     * The guard fires at the boundary between a browser-authored design and a
     * DSL-authored one, and never again: a canvas THIS tool wrote decompiles
     * losslessly, so the second write needs no acknowledgement.
     *
     * Without this property the tool would be unusable — an agent iterating on
     * its own design would have to acknowledge losses it caused itself.
     */
    public function testAVariantThisToolWroteNeedsNoAcknowledgementNextTime(): void
    {
        $browser = $this->browser();
        $browser->disableReboot();
        $this->expectThumbnail(TestDataFixture::FORM_BACKGROUND_VARIANT_ID);

        $first = $this->call(
            TestDataFixture::MCP_TOKEN_DESIGN_ONLY,
            TestDataFixture::FORM_BACKGROUND_VARIANT_ID,
            self::cleanDesign(),
            acknowledgeLosses: true,
        );
        self::assertTrue($first['summary']['saved']);

        $second = $this->call(
            TestDataFixture::MCP_TOKEN_DESIGN_ONLY,
            TestDataFixture::FORM_BACKGROUND_VARIANT_ID,
            self::cleanDesign(),
        );

        self::assertFalse($second['isError'], $second['text']);
        self::assertTrue($second['summary']['saved']);
        self::assertSame([], $second['summary']['issues'], 'a design this tool wrote must decompile losslessly');
    }

    // =================================================================
    // identity
    // =================================================================

    /**
     * THE done-when for §4.1: two writes with the same slugs produce the same
     * `inputId` UUIDs.
     *
     * This is what every saved fill, every API consumer and every container
     * membership is keyed on, and it is the entire reason the DSL has slugs at
     * all. The renamed slug in the second design is the control: identity
     * follows the NAME, so a slug that changed must mint — otherwise "ids are
     * stable" would be indistinguishable from "ids are positional".
     */
    public function testInputIdsSurviveASecondWriteWithTheSameSlugs(): void
    {
        $browser = $this->browser();
        $browser->disableReboot();
        $this->expectThumbnail(TestDataFixture::BLANK_VARIANT_ID);

        $first = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::BLANK_VARIANT_ID, self::cleanDesign());
        self::assertTrue($first['summary']['saved'], $first['text']);

        $before = $this->inputIds(TestDataFixture::BLANK_VARIANT_ID);
        self::assertCount(2, $before);

        foreach ($before as $inputId) {
            self::assertTrue(Uuid::isValid($inputId));
            self::assertSame(4, Uuid::fromString($inputId)->getVersion(), 'inputIds are v4 (plan §4.1-2)');
        }

        // Same "headline" slug carrying different copy and different geometry;
        // "legal" RENAMED to "small-print"; plus one genuinely new element.
        $second = [
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                [
                    'kind' => 'text', 'id' => 'headline', 'text' => 'Rewritten headline',
                    'font' => self::FONT, 'size' => 72, 'x' => 80, 'y' => 200, 'width' => 920,
                    'input' => ['name' => 'Headline'],
                ],
                [
                    'kind' => 'text', 'id' => 'small-print', 'text' => 'Small print',
                    'font' => self::FONT, 'size' => 24, 'x' => 80, 'y' => 860, 'width' => 920,
                    'input' => ['name' => 'Legal'],
                ],
                [
                    'kind' => 'text', 'id' => 'kicker', 'text' => 'New line',
                    'font' => self::FONT, 'size' => 30, 'x' => 80, 'y' => 500, 'width' => 920,
                ],
            ],
        ];

        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::BLANK_VARIANT_ID, $second);
        self::assertTrue($result['summary']['saved'], $result['text']);

        $after = $this->inputIds(TestDataFixture::BLANK_VARIANT_ID);
        self::assertCount(3, $after);

        self::assertSame(
            $before[0],
            $after[0],
            'the "headline" slug named the same input in both calls and MUST have kept its id',
        );

        self::assertNotSame($before[1], $after[1], 'a RENAMED slug names a different input, so it mints');
        self::assertNotContains($after[1], $before);
        self::assertNotContains($after[2], $before);
        self::assertNotSame($after[1], $after[2]);
    }

    // =================================================================
    // refusals that are not about the document
    // =================================================================

    /**
     * THE done-when for §4.5-22. A group-created variant is refused, and the
     * refusal points at where the design really lives.
     *
     * `preview_design` deliberately ACCEPTS the same variant — drawing one
     * changes nothing. Only the write refuses, mirroring
     * `TemplateVariantEditorController`'s redirect to the group editor.
     */
    public function testAGroupedVariantIsRefusedWithTheGroupToolMessage(): void
    {
        $browser = $this->browser();
        $browser->disableReboot();

        $before = $this->row(TestDataFixture::GROUPED_PRESET_VARIANT_ID);

        $result = $this->call(
            TestDataFixture::MCP_TOKEN_DESIGN_ONLY,
            TestDataFixture::GROUPED_PRESET_VARIANT_ID,
            self::cleanDesign(),
        );

        self::assertTrue($result['isError']);
        self::assertSame([], $result['summary'], 'a refusal that is not about the document is a plain sentence');

        $message = $result['text'];
        self::assertStringContainsString(TestDataFixture::GROUPED_PRESET_VARIANT_ID, $message);
        self::assertStringContainsString('synchronized template group "Group Campaign"', $message);
        self::assertStringContainsString('shares ONE design', $message);
        self::assertStringContainsString('overwritten by the next group save', $message);
        // The next step, which is the whole point of a refusal message.
        self::assertStringContainsString('/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/editor', $message);
        self::assertStringContainsString('grouped: true', $message, 'the flag describe_variant already publishes predicts this refusal');

        self::assertSame($before, $this->row(TestDataFixture::GROUPED_PRESET_VARIANT_ID));

        // A hand-added variant on the SAME grouped template carries no group
        // and stays writable — the distinction the message promises.
        $ungrouped = $this->call(
            TestDataFixture::MCP_TOKEN_DESIGN_ONLY,
            TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID,
            self::storyDesign(),
        );
        $this->expectThumbnail(TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID);

        self::assertFalse($ungrouped['isError'], $ungrouped['text']);
        self::assertTrue($ungrouped['summary']['saved']);
    }

    /**
     * An error blocks and NOTHING is written — asserted through a `flush()`,
     * and with the renderer proven untouched so "nothing changed" cannot be
     * explained by a call that silently did nothing.
     */
    public function testAnErrorBlocksAndNothingIsWritten(): void
    {
        $browser = $this->browser();
        $browser->disableReboot();

        $before = $this->row(TestDataFixture::BLANK_VARIANT_ID);

        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::BLANK_VARIANT_ID, [
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                [
                    'kind' => 'text', 'id' => 'headline', 'text' => 'Wrong face',
                    'font' => self::FOREIGN_FONT, 'size' => 64, 'x' => 80, 'y' => 100, 'width' => 900,
                ],
            ],
        ]);

        self::assertTrue($result['isError']);

        $summary = $result['summary'];
        self::assertFalse($summary['saved']);
        self::assertFalse($summary['thumbnailUpdated']);
        self::assertSame(['font_not_allowed'], self::codes($summary));
        self::assertSame('lint', self::issues($summary)[0]['stage']);
        self::assertStringContainsString('still has its previous design', self::text($summary['status']));
        self::assertStringNotContainsString(
            'acknowledgeLosses',
            self::text($summary['status']),
            'the acknowledgement is only ever taught when an overwrite finding is what blocked',
        );

        self::assertSame([], $this->rendererFake()->calls, 'a blocked document must never reach Gotenberg');

        self::assertSame($before, $this->row(TestDataFixture::BLANK_VARIANT_ID));

        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame($before, $this->row(TestDataFixture::BLANK_VARIANT_ID), 'the managed entity must not even be DIRTY');
    }

    /**
     * Warnings never block a commit: the design is written AND the concerns
     * come back with it.
     *
     * This is the promise {@see DesignPreflight} exists to keep — a document
     * that previewed with warnings and a picture must commit, or "warning"
     * would mean two different things in two halves of one loop.
     */
    public function testWarningsRideAlongWithASuccessfulWrite(): void
    {
        $this->expectThumbnail(TestDataFixture::BLANK_VARIANT_ID);

        $result = $this->call(TestDataFixture::MCP_TOKEN_DESIGN_ONLY, TestDataFixture::BLANK_VARIANT_ID, [
            'canvas' => ['width' => 1080, 'height' => 1080],
            'elements' => [
                [
                    'kind' => 'text', 'id' => 'headline', 'text' => 'Too far right',
                    'font' => self::FONT, 'size' => 64, 'color' => '#123456',
                    'x' => 800, 'y' => 100, 'width' => 600,
                ],
            ],
        ]);

        self::assertFalse($result['isError'], 'a warning must never block the commit');

        $summary = $result['summary'];
        self::assertTrue($summary['saved']);
        self::assertSame(0, $summary['errorCount']);
        self::assertSame(2, $summary['warningCount']);
        self::assertSame(['color_not_in_palette', 'out_of_canvas_bounds'], self::codes($summary));
        self::assertSame('image', $result['image']['type']);

        foreach (self::issues($summary) as $issue) {
            self::assertSame('lint', $issue['stage']);
            self::assertSame('warning', $issue['severity']);
        }

        self::assertSame($this->expectThumbnail(TestDataFixture::BLANK_VARIANT_ID), $this->row(TestDataFixture::BLANK_VARIANT_ID)['preview_image_path']);
    }

    // =================================================================
    // authorisation
    // =================================================================

    /**
     * The scope gate, both directions. A `templates:read` token is not shown the
     * tool AND cannot call it — a filtered listing is not an authorisation
     * boundary.
     */
    public function testReadOnlyTokenNeitherSeesNorMayCallTheWriteTool(): void
    {
        self::assertNotContains('set_design', $this->listTools(TestDataFixture::MCP_TOKEN_READ_ONLY));

        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'set_design',
            'arguments' => ['variantId' => TestDataFixture::BLANK_VARIANT_ID, 'design' => self::cleanDesign()],
        ], $sessionId, TestDataFixture::MCP_TOKEN_READ_ONLY);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('scope="templates:design"', (string) $response->headers->get('WWW-Authenticate'));

        self::assertNull(
            $this->row(TestDataFixture::BLANK_VARIANT_ID)['preview_image_path'],
            'a refused call must not have reached the tool body',
        );
    }

    public function testDesignScopedTokenSeesTheToolAndMayCallIt(): void
    {
        self::assertContains('set_design', $this->listTools(TestDataFixture::MCP_TOKEN_DESIGN_ONLY));
    }

    /**
     * A variant this account cannot see is indistinguishable from one that does
     * not exist — the same anti-enumeration wording every other variant tool
     * uses. `DesignVariants` reuses `VariantFill`'s factories precisely so a
     * server cannot teach an agent two vocabularies for one dead end.
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

    // =================================================================
    // the advertised contract
    // =================================================================

    /**
     * The description is what makes a model reach for this tool — and, more
     * importantly here, what stops it from reaching for `acknowledgeLosses`
     * before it has been refused once. The schema is the only proof the
     * arguments can arrive at all: `design` is a nested JSON OBJECT, and
     * inferred from the PHP `array` it would be advertised as a JSON ARRAY,
     * whereupon the SDK's own validator rejects every real call.
     */
    public function testToolIsAdvertisedWithAnObjectSchemaAndAnOptionalAcknowledgement(): void
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

            if ($candidate['name'] === 'set_design') {
                $tool = $candidate;
            }
        }

        self::assertIsArray($tool, 'set_design is not advertised to a templates:design token.');

        $description = $tool['description'];
        self::assertIsString($description);
        // Substrings must not span a line break — the description is the
        // reflowed `__invoke()` docblock, so a wrapped sentence never matches.
        self::assertStringContainsString('replacing whatever design it had', $description);
        self::assertStringContainsString('that saving would therefore DESTROY', $description);
        self::assertStringContainsString('acknowledgeLosses: true', $description);
        self::assertStringContainsString('Slugs are identity', $description);

        $schema = $tool['inputSchema'];
        self::assertIsArray($schema);
        self::assertSame(
            ['variantId', 'design'],
            $schema['required'],
            'acknowledgeLosses must be OPTIONAL — a required flag would be answered before it was understood',
        );

        $properties = $schema['properties'];
        self::assertIsArray($properties);

        $design = $properties['design'];
        self::assertIsArray($design);
        self::assertSame('object', $design['type'], 'design is a nested object, not a list.');

        $acknowledge = $properties['acknowledgeLosses'];
        self::assertIsArray($acknowledge);
        self::assertSame('boolean', $acknowledge['type']);
    }

    // =================================================================
    // the real thing
    // =================================================================

    /**
     * The one test that really talks to Gotenberg: a DSL document compiled and
     * drawn by the real pipeline, written to the row, and its thumbnail stored
     * as real PNG bytes in the object store.
     *
     * Excluded from the default suite (`phpunit.xml.dist` excludes the
     * `gotenberg` group); run it with `vendor/bin/phpunit --group gotenberg`.
     *
     * Assembled by hand rather than called over `/_mcp`, exactly as
     * `PreviewDesignTest` does it: the test environment aliases the renderer
     * interface to the fake for every other suite, and swapping that alias
     * globally would make this file's fast tests depend on Gotenberg too.
     */
    #[Group('gotenberg')]
    public function testWritesARealRenderedThumbnailThroughGotenberg(): void
    {
        $this->browser();
        $container = self::getContainer();

        $path = $this->expectThumbnail(TestDataFixture::BLANK_VARIANT_ID);

        $security = self::createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        $tool = new SetDesignTool(
            new DesignVariants(
                $security,
                $container->get(TemplateVariantRepository::class),
                $container->get(UrlGeneratorInterface::class),
            ),
            $container->get(DesignOverwriteGuard::class),
            $container->get(DesignPreflight::class),
            new CandidateRenderer(
                // The CONCRETE renderer: the test env aliases the interface to
                // the fake (config/services_test.php).
                $container->get(TemplateVariantImageRenderer::class),
                $container->get(ResolveTextOverrides::class),
                $container->get(ResolveRichTextOptions::class),
            ),
            new DownscaleImage(),
            $container->get(MessageBusInterface::class),
            $container->get(UrlGeneratorInterface::class),
        );

        $result = $tool(TestDataFixture::BLANK_VARIANT_ID, self::cleanDesign());

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
        // 1080 × 1080 is already inside the 1200 px reply bound.
        self::assertSame([1080, 1080], [$size[0], $size[1]]);

        $stored = $container->get(Filesystem::class);
        self::assertTrue($stored->fileExists($path), 'the thumbnail was not stored');

        $thumbnail = getimagesizefromstring($stored->read($path));
        self::assertNotFalse($thumbnail);
        self::assertSame(IMAGETYPE_PNG, $thumbnail[2]);
        // Capped at 1000 px, the same bound the browser editor uses for its own
        // capture — so the stored thumbnail IS downscaled from the render.
        self::assertSame([1000, 1000], [$thumbnail[0], $thumbnail[1]]);

        self::assertSame($path, $this->row(TestDataFixture::BLANK_VARIANT_ID)['preview_image_path']);
    }

    // =================================================================
    // fixtures
    // =================================================================

    /**
     * Two texts, project fonts, inside the canvas, default (neutral) colour —
     * the linter has nothing to say about it. Deliberately identical to
     * `PreviewDesignTest`'s: a document that previews cleanly must commit.
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
     * The same design for the 1080 × 1920 story variant — the canvas size has
     * to match the variant or the reply carries a `canvas_size_mismatch`
     * warning that has nothing to do with what is being asserted.
     *
     * @return array<string, mixed>
     */
    private static function storyDesign(): array
    {
        $design = self::cleanDesign();
        $design['canvas'] = ['width' => 1080, 'height' => 1920];

        return $design;
    }

    // =================================================================
    // helpers
    // =================================================================

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * Registers the thumbnail this test is about to create for cleanup, and
     * returns its path. Object storage outlives the DAMA rollback.
     */
    private function expectThumbnail(string $variantId): string
    {
        $this->browser();

        $path = StoreTemplateVariantPreviewImage::pathFor(Uuid::fromString($variantId));

        if (!in_array($path, $this->storedObjects, true)) {
            $this->storedObjects[] = $path;
        }

        return $path;
    }

    /**
     * The persisted row, read straight from the database rather than from the
     * entity: an in-memory entity would happily report a value Doctrine has not
     * written (and, after a mutation, one it is about to).
     *
     * @return array<string, mixed>
     */
    private function row(string $variantId): array
    {
        $this->browser();

        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT canvas, preview_image_path, background_image, background_mode, inputs, image_inputs
             FROM template_variant WHERE id = ?',
            [$variantId],
        );

        self::assertIsArray($row);

        return $row;
    }

    /**
     * The variant's persisted `inputId`s, in their stored (positional) order,
     * read out of the JSONB column — the only place these ids exist.
     *
     * @return list<string>
     */
    private function inputIds(string $variantId): array
    {
        $decoded = json_decode(self::text($this->row($variantId)['inputs']), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var list<string> $ids */
        $ids = [];

        foreach ($decoded as $input) {
            self::assertIsArray($input);
            $ids[] = self::text($input['inputId']);
        }

        return $ids;
    }

    /**
     * The variant's persisted canvas objects, in stack order.
     *
     * @return list<array<string, mixed>>
     */
    private function canvasObjects(string $variantId): array
    {
        $decoded = json_decode(self::text($this->row($variantId)['canvas']), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $objects = $decoded['objects'] ?? null;
        self::assertIsArray($objects);

        /** @var list<array<string, mixed>> $typed */
        $typed = [];

        foreach ($objects as $object) {
            self::assertIsArray($object);
            /** @var array<string, mixed> $object */
            $typed[] = $object;
        }

        return $typed;
    }

    /**
     * `describe_variant`, called for real over `/_mcp` — the round trip is only
     * a round trip if the reading half is the production tool.
     *
     * @return array<string, mixed>
     */
    private function describe(string $variantId): array
    {
        $browser = $this->browser();
        $token = TestDataFixture::MCP_TOKEN_DESIGN_ONLY;
        $sessionId = TestingMcpClient::connect($browser, $token);

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'describe_variant',
            'arguments' => ['variantId' => $variantId],
        ], $sessionId, $token);

        $payload = self::decode($browser->getResponse());
        $result = $payload['result'];
        self::assertIsArray($result);
        self::assertNotTrue($result['isError'] ?? false, (string) $browser->getResponse()->getContent());

        $content = $result['content'];
        self::assertIsArray($content);
        self::assertIsArray($content[0]);

        $described = json_decode(self::text($content[0]['text']), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($described);

        /** @var array<string, mixed> $described */
        return $described;
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
    private function call(string $token, string $variantId, array $design, bool $acknowledgeLosses = false): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        $arguments = ['variantId' => $variantId, 'design' => $design];

        if ($acknowledgeLosses) {
            $arguments['acknowledgeLosses'] = true;
        }

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'set_design',
            'arguments' => $arguments,
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
        // unusable id, a grouped variant, a busy renderer) is a plain sentence
        // rather than the summary shape, and that is the contract.
        $summary = json_decode($text, true);

        if (!is_array($summary)) {
            return ['isError' => $isError, 'text' => $text, 'summary' => [], 'image' => []];
        }

        /** @var array<string, mixed> $summary */
        if ($isError) {
            self::assertCount(1, $content, 'a blocked write answers with the summary and nothing else.');

            return ['isError' => true, 'text' => $text, 'summary' => $summary, 'image' => []];
        }

        self::assertCount(2, $content, 'a committed design answers with a summary AND a picture.');
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
