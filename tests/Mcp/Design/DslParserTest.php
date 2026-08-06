<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidDesignDocument;
use WBoost\Web\Mcp\Design\Dsl\BackgroundElement;
use WBoost\Web\Mcp\Design\Dsl\ContainerElement;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;
use WBoost\Web\Mcp\Design\Dsl\DslErrorCode;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Mcp\Design\Dsl\ElementKind;
use WBoost\Web\Mcp\Design\Dsl\ImageElement;
use WBoost\Web\Mcp\Design\Dsl\PlacementArea;
use WBoost\Web\Mcp\Design\Dsl\Rect;
use WBoost\Web\Mcp\Design\Dsl\TextAlign;
use WBoost\Web\Mcp\Design\Dsl\TextElement;

/**
 * The strict DSL parser (S4-T1), tested as the pure function it is — no
 * kernel, no container, no project. That is the point of the layer: the parser
 * validates the DOCUMENT, never the project it will be compiled against, so
 * "is this font real?" cannot leak into "is this well-formed?".
 *
 * Three properties are worth more than the individual cases and are asserted
 * throughout:
 *
 * 1. **Nothing is silently accepted.** A hallucinated key, a mistyped enum
 *    member, a container that would be dropped downstream — every one of them
 *    is an error naming a path, not a default quietly applied.
 * 2. **Every problem is reported at once.** An agent that gets five problems
 *    in one response fixes them in one turn.
 * 3. **The defaults have exactly one authoritative statement** — the table in
 *    {@see self::testAppliesDocumentedDefaults()}.
 */
final class DslParserTest extends TestCase
{
    private const string ASSET = '0f9a1c34-8b2e-4c5a-9b0d-1f2e3a4b5c6d';
    private const string ASSET_2 = '11111111-2222-4333-8444-555555555555';
    private const string DIRECTORY = '99999999-8888-4777-8666-555555555555';
    private const string FACE = 'Hero New (Hero New ExtraBold)';

    // -----------------------------------------------------------------
    // the happy path — every element kind, every field intact
    // -----------------------------------------------------------------

    public function testParsesAFullDocumentWithEveryElementKind(): void
    {
        $document = DslParser::parse(self::fullDocument());

        self::assertSame(1080, $document->canvas->width);
        self::assertSame(1350, $document->canvas->height);
        self::assertNull($document->canvas->backgroundImageAssetId);
        self::assertSame('#111111', $document->canvas->backgroundFill);

        self::assertCount(5, $document->elements);
        self::assertSame(['bg', 'headline', 'subhead', 'photo', 'body'], $document->elementIds());
    }

    public function testParsesTheBackgroundElement(): void
    {
        $background = DslParser::parse(self::fullDocument())->backgroundElement();

        self::assertInstanceOf(BackgroundElement::class, $background);
        self::assertSame('bg', $background->id);
        self::assertSame(self::ASSET, $background->assetId);
        self::assertTrue($background->fillable);
        self::assertSame(ElementKind::Background, $background->kind());
    }

    public function testParsesATextElementWithEveryField(): void
    {
        $text = DslParser::parse(self::fullDocument())->element('headline');

        self::assertInstanceOf(TextElement::class, $text);
        self::assertSame('SLEVA 50 %', $text->text);
        self::assertSame(self::FACE, $text->font);
        self::assertSame(96.0, $text->size);
        self::assertSame('#ffffff', $text->color);
        self::assertSame(TextAlign::Left, $text->align);
        self::assertSame(1.16, $text->lineHeight);
        self::assertSame(ElementKind::Text, $text->kind());

        $at = $text->placement->at;
        self::assertNotNull($at);
        self::assertSame(PlacementArea::Top, $at->area);
        self::assertSame(1, $at->colStart);
        self::assertSame(12, $at->colEnd);
        self::assertSame(12, $at->columnSpan());
        self::assertSame(80.0, $at->marginX);
        self::assertSame(40.0, $at->offsetY);

        self::assertSame('Nadpis', $text->input->name);
        self::assertSame(24, $text->input->maxLength);
        self::assertTrue($text->input->uppercase);
        self::assertFalse($text->input->hidable);
        self::assertFalse($text->input->locked);
        self::assertFalse($text->input->richText);
        self::assertSame('SLEVA 50 %', $text->input->sampleValue);
    }

    public function testParsesAnImageElementWithEveryField(): void
    {
        $image = DslParser::parse(self::fullDocument())->element('photo');

        self::assertInstanceOf(ImageElement::class, $image);
        self::assertSame(self::ASSET_2, $image->assetId);
        self::assertSame(480.0, $image->placement->height);
        self::assertTrue($image->isPlaceholder());
        self::assertSame(ElementKind::Image, $image->kind());

        $input = $image->input;
        self::assertNotNull($input);
        self::assertSame('Foto', $input->name);
        self::assertTrue($input->placeholder);
        self::assertTrue($input->allowMove);
        self::assertFalse($input->allowResize);
        self::assertFalse($input->allowRotate);
        self::assertTrue($input->hidable);
        self::assertSame([self::DIRECTORY], $input->allowedDirectories);
    }

    public function testParsesAContainerElementWithEveryField(): void
    {
        $container = DslParser::parse(self::fullDocument())->element('body');

        self::assertInstanceOf(ContainerElement::class, $container);
        self::assertSame(['headline', 'subhead'], $container->memberIds);
        self::assertSame([], $container->childIds);
        self::assertSame(400.0, $container->maxHeight);
        self::assertSame(24.0, $container->gap);
        self::assertSame(60.0, $container->spaceAfter);
        self::assertSame(ElementKind::Container, $container->kind());
    }

    public function testParsesNestedContainersAndLetsANestedOneOmitMaxHeight(): void
    {
        $document = DslParser::parse(self::documentWith(
            self::textElement(['id' => 'a']),
            self::textElement(['id' => 'b']),
            self::textElement(['id' => 'c']),
            ['kind' => 'container', 'id' => 'inner', 'members' => ['a', 'b']],
            ['kind' => 'container', 'id' => 'root', 'members' => ['c'], 'children' => ['inner'], 'maxHeight' => 900],
        ));

        $inner = $document->element('inner');
        $root = $document->element('root');

        self::assertInstanceOf(ContainerElement::class, $inner);
        self::assertInstanceOf(ContainerElement::class, $root);
        self::assertNull($inner->maxHeight, 'A nested container has no bound — only the root gates overflow (plan §4.4).');
        self::assertSame(900.0, $root->maxHeight);
        self::assertSame(['c', 'inner'], $root->referencedIds());
    }

    public function testElementOrderIsPreservedBecauseItIsTheStackOrder(): void
    {
        $document = DslParser::parse(self::documentWith(
            ['kind' => 'background', 'id' => 'bg'],
            self::textElement(['id' => 'bottom']),
            self::imageElement(['id' => 'middle']),
            self::textElement(['id' => 'top']),
            ['kind' => 'container', 'id' => 'flow', 'members' => ['bottom', 'top'], 'maxHeight' => 400],
        ));

        self::assertSame(['bg', 'bottom', 'middle', 'top', 'flow'], $document->elementIds());
        self::assertSame(
            ['bg', 'bottom', 'middle', 'top'],
            array_map(static fn ($element): string => $element->id, $document->drawableElements()),
            'Container definitions take no slot in the stack order.',
        );
    }

    public function testAcceptsAnEmptyElementList(): void
    {
        $document = DslParser::parse(['canvas' => ['width' => 100, 'height' => 100], 'elements' => []]);

        self::assertSame([], $document->elements);
        self::assertNull($document->backgroundElement());
    }

    public function testParsesJsonAndReportsMalformedJsonAsSuch(): void
    {
        $document = DslParser::parseJson('{"canvas":{"width":100,"height":100},"elements":[]}');
        self::assertSame(100, $document->canvas->width);

        $exception = self::reject('{"canvas":');
        self::assertSame(DslErrorCode::MalformedDocument, $exception->violations[0]->code);
        self::assertStringContainsString('not valid JSON', $exception->getMessage());
    }

    // -----------------------------------------------------------------
    // defaults — the single authoritative statement
    // -----------------------------------------------------------------

    /**
     * Every optional key and the value it takes when omitted. If a default
     * changes, this test is the place it is decided; nothing else states them.
     *
     * | path                          | default                      |
     * |-------------------------------|------------------------------|
     * | canvas.background             | absent (no image, no fill)   |
     * | element.at.col                | [1, 12] (the full grid)      |
     * | element.at.marginX / marginY  | 0                            |
     * | element.at.offsetX / offsetY  | 0                            |
     * | text.color                    | "#000000" (Fabric's default) |
     * | text.align                    | "left"                       |
     * | text.lineHeight               | 1.16 (Fabric's default)      |
     * | text.input                    | materialized, all defaults   |
     * | text.input.name               | null                         |
     * | text.input.maxLength          | null (uncapped)              |
     * | text.input.uppercase          | false                        |
     * | text.input.hidable            | false                        |
     * | text.input.locked             | false                        |
     * | text.input.richText           | false                        |
     * | text.input.sampleValue        | null (the designed text)     |
     * | image.asset                   | null (empty slot)            |
     * | image.input                   | null → DECORATIVE image      |
     * | image.input.placeholder       | true (when the block exists) |
     * | image.input.allowMove         | true                         |
     * | image.input.allowResize       | true                         |
     * | image.input.allowRotate       | false                        |
     * | image.input.hidable           | false                        |
     * | image.input.allowedDirectories| [] = the WHOLE gallery       |
     * | background.asset              | null → transparent render    |
     * | background.fillable           | false                        |
     * | container.members / children  | []                           |
     * | container.maxHeight           | null (nested only)           |
     * | container.gap                 | null (designed gaps kept)    |
     * | container.spaceAfter          | null (no clearance)          |
     */
    public function testAppliesDocumentedDefaults(): void
    {
        $document = DslParser::parse(self::documentWith(
            ['kind' => 'background', 'id' => 'bg'],
            ['kind' => 'text', 'id' => 'bare', 'text' => 'x', 'font' => self::FACE, 'size' => 40, 'at' => ['area' => 'middle']],
            ['kind' => 'image', 'id' => 'decorative', 'at' => ['area' => 'bottom']],
            ['kind' => 'image', 'id' => 'slot', 'at' => ['area' => 'bottom'], 'input' => []],
        ));

        self::assertNull($document->canvas->backgroundImageAssetId);
        self::assertNull($document->canvas->backgroundFill);

        $background = $document->element('bg');
        self::assertInstanceOf(BackgroundElement::class, $background);
        self::assertNull($background->assetId);
        self::assertFalse($background->fillable);

        $text = $document->element('bare');
        self::assertInstanceOf(TextElement::class, $text);
        self::assertSame('#000000', $text->color);
        self::assertSame(TextAlign::Left, $text->align);
        self::assertSame(1.16, $text->lineHeight);
        $at = $text->placement->at;
        self::assertNotNull($at);
        self::assertSame([1, 12], [$at->colStart, $at->colEnd]);
        self::assertSame([0.0, 0.0, 0.0, 0.0], [$at->marginX, $at->marginY, $at->offsetX, $at->offsetY]);
        self::assertNull($text->input->name);
        self::assertNull($text->input->maxLength);
        self::assertFalse($text->input->uppercase);
        self::assertFalse($text->input->hidable);
        self::assertFalse($text->input->locked);
        self::assertFalse($text->input->richText);
        self::assertNull($text->input->sampleValue);

        $decorative = $document->element('decorative');
        self::assertInstanceOf(ImageElement::class, $decorative);
        self::assertNull($decorative->assetId);
        self::assertNull($decorative->input);
        self::assertFalse($decorative->isPlaceholder());

        $slot = $document->element('slot');
        self::assertInstanceOf(ImageElement::class, $slot);
        self::assertNotNull($slot->input);
        self::assertTrue($slot->input->placeholder, 'An "input" block on an image means a fillable slot.');
        self::assertTrue($slot->input->allowMove);
        self::assertTrue($slot->input->allowResize);
        self::assertFalse($slot->input->allowRotate);
        self::assertFalse($slot->input->hidable);
        self::assertSame([], $slot->input->allowedDirectories);
    }

    public function testATextElementAlwaysCarriesAnInputBecauseOfThePositionalBinding(): void
    {
        $document = DslParser::parse(self::documentWith(self::textElement()));
        $text = $document->element('headline');

        self::assertInstanceOf(TextElement::class, $text);
        // Not null — a VISIBLE textbox is inputs[i] by construction
        // (TextInputObjectBinder, plan §4.1 invariant 1); omitting "input"
        // only means "take the defaults".
        self::assertNull($text->input->name);
    }

    public function testAnExplicitNullForAnOptionalKeyIsTreatedAsAbsent(): void
    {
        $document = DslParser::parse(self::documentWith([
            'kind' => 'image',
            'id' => 'photo',
            'asset' => null,
            'at' => ['area' => 'bottom', 'col' => null, 'marginX' => null],
            'height' => null,
            'input' => null,
        ]));

        $image = $document->element('photo');
        self::assertInstanceOf(ImageElement::class, $image);
        self::assertNull($image->assetId);
        self::assertNull($image->input);
        self::assertNull($image->placement->height);
    }

    // -----------------------------------------------------------------
    // absolute wins over semantic — per property
    // -----------------------------------------------------------------

    public function testAbsoluteGeometryOverridesTheSemanticPlacementPerProperty(): void
    {
        $document = DslParser::parse(self::documentWith([
            'kind' => 'text',
            'id' => 'headline',
            'text' => 'x',
            'font' => self::FACE,
            'size' => 40,
            'at' => ['area' => 'top', 'col' => [1, 12]],
            'x' => 80,
        ]));

        $text = $document->element('headline');
        self::assertInstanceOf(TextElement::class, $text);

        $placement = $text->placement;
        self::assertTrue($placement->hasSemanticPlacement());
        self::assertFalse($placement->isFullyAbsolute());

        $resolved = $placement->resolve(new Rect(0.0, 120.0, 1080.0, null));
        self::assertSame(80.0, $resolved->x, 'The authored x wins.');
        self::assertSame(120.0, $resolved->y, 'y still comes from the grid.');
        self::assertSame(1080.0, $resolved->width, 'width still comes from the grid.');
        self::assertNull($resolved->height, 'A textbox height is Fabric-computed (plan §4.2 invariant 6).');
    }

    public function testAnImageMayTakeItsBoxFromTheGridAndOnlyItsHeightAbsolutely(): void
    {
        $document = DslParser::parse(self::documentWith([
            'kind' => 'image',
            'id' => 'photo',
            'at' => ['area' => 'bottom', 'col' => [1, 12]],
            'height' => 480,
        ]));

        $image = $document->element('photo');
        self::assertInstanceOf(ImageElement::class, $image);

        $resolved = $image->placement->resolve(new Rect(0.0, 600.0, 1080.0, 480.0));
        self::assertSame([0.0, 600.0, 1080.0, 480.0], [$resolved->x, $resolved->y, $resolved->width, $resolved->height]);
        self::assertSame(1080.0, $resolved->right());
        self::assertSame(1080.0, $resolved->bottom());
    }

    public function testAFullyAbsolutePlacementNeedsNoGrid(): void
    {
        $document = DslParser::parse(self::documentWith([
            'kind' => 'text',
            'id' => 'headline',
            'text' => 'x',
            'font' => self::FACE,
            'size' => 40,
            'x' => 80,
            'y' => 120,
            'width' => 920,
        ]));

        $text = $document->element('headline');
        self::assertInstanceOf(TextElement::class, $text);
        self::assertFalse($text->placement->hasSemanticPlacement());
        self::assertTrue($text->placement->isFullyAbsolute());

        $resolved = $text->placement->resolve(null);
        self::assertSame([80.0, 120.0, 920.0], [$resolved->x, $resolved->y, $resolved->width]);
    }

    // -----------------------------------------------------------------
    // rejection — one precise error per case
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed>|list<mixed>|string $payload
     */
    #[DataProvider('rejectedDocuments')]
    public function testRejectsWithAPreciseError(
        array|string $payload,
        string $expectedPath,
        DslErrorCode $expectedCode,
        string $expectedFragment,
    ): void {
        $exception = self::reject($payload);

        self::assertSame(
            $expectedPath,
            $exception->violations[0]->path,
            'The path is the product surface: it is what the agent edits. Got: ' . $exception->getMessage(),
        );
        self::assertSame($expectedCode, $exception->violations[0]->code, $exception->getMessage());
        self::assertStringContainsString($expectedFragment, $exception->violations[0]->message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|list<mixed>|string, string, DslErrorCode, string}>
     */
    public static function rejectedDocuments(): iterable
    {
        // --- the payload itself -------------------------------------
        yield 'not an object' => [
            '[1, 2]',
            '',
            DslErrorCode::MalformedDocument,
            'must be a JSON object',
        ];

        // --- required keys ------------------------------------------
        yield 'canvas missing' => [
            ['elements' => []],
            'canvas',
            DslErrorCode::MissingKey,
            'canvas is required.',
        ];
        yield 'elements missing' => [
            ['canvas' => ['width' => 1, 'height' => 1]],
            'elements',
            DslErrorCode::MissingKey,
            'elements is required',
        ];
        yield 'canvas.width missing' => [
            ['canvas' => ['height' => 1080], 'elements' => []],
            'canvas.width',
            DslErrorCode::MissingKey,
            'canvas.width is required.',
        ];
        yield 'canvas.height missing' => [
            ['canvas' => ['width' => 1080], 'elements' => []],
            'canvas.height',
            DslErrorCode::MissingKey,
            'canvas.height is required.',
        ];
        yield 'element kind missing' => [
            self::documentWith(['id' => 'headline']),
            'elements[0].kind',
            DslErrorCode::MissingKey,
            'Allowed kinds: text, image, background, container.',
        ];
        yield 'element id missing' => [
            self::documentWith(self::textElement(remove: ['id'])),
            'elements[0].id',
            DslErrorCode::MissingKey,
            'elements[0].id is required.',
        ];
        yield 'text.text missing' => [
            self::documentWith(self::textElement(remove: ['text'])),
            'elements[0].text',
            DslErrorCode::MissingKey,
            'elements[0].text is required.',
        ];
        yield 'text.font missing' => [
            self::documentWith(self::textElement(remove: ['font'])),
            'elements[0].font',
            DslErrorCode::MissingKey,
            'elements[0].font is required.',
        ];
        yield 'text.size missing' => [
            self::documentWith(self::textElement(remove: ['size'])),
            'elements[0].size',
            DslErrorCode::MissingKey,
            'elements[0].size is required.',
        ];
        yield 'at.area missing' => [
            self::documentWith(self::textElement(['at' => ['col' => [1, 6]]])),
            'elements[0].at.area',
            DslErrorCode::MissingKey,
            'Allowed areas: top, upper, middle, lower, bottom, full.',
        ];
        yield 'no placement at all' => [
            self::documentWith(self::textElement(remove: ['at'])),
            'elements[0]',
            DslErrorCode::MissingKey,
            'Missing: x, y, width.',
        ];
        yield 'partial absolute placement' => [
            self::documentWith(self::textElement(['x' => 10, 'y' => 20], remove: ['at'])),
            'elements[0]',
            DslErrorCode::MissingKey,
            'Missing: width.',
        ];
        yield 'root container without maxHeight' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                self::textElement(['id' => 'b']),
                ['kind' => 'container', 'id' => 'body', 'members' => ['a', 'b']],
            ),
            'elements[2].maxHeight',
            DslErrorCode::MissingKey,
            'required for the top-level container',
        ];

        // --- unknown keys, at every level ----------------------------
        yield 'unknown root key' => [
            ['canvas' => ['width' => 1, 'height' => 1], 'elements' => [], 'version' => 2],
            'version',
            DslErrorCode::UnknownKey,
            'Allowed keys: canvas, elements.',
        ];
        yield 'unknown canvas key' => [
            ['canvas' => ['width' => 1, 'height' => 1, 'dpi' => 300], 'elements' => []],
            'canvas.dpi',
            DslErrorCode::UnknownKey,
            'Allowed keys: width, height, background.',
        ];
        yield 'unknown canvas background key' => [
            ['canvas' => ['width' => 1, 'height' => 1, 'background' => ['color' => '#fff']], 'elements' => []],
            'canvas.background.color',
            DslErrorCode::UnknownKey,
            'Allowed keys: image, fill.',
        ];
        yield 'unknown text key with a suffix suggestion' => [
            self::documentWith(self::textElement(['fontSize' => 96])),
            'elements[0].fontSize',
            DslErrorCode::UnknownKey,
            'Did you mean "size"?',
        ];
        yield 'unknown text key with a levenshtein suggestion' => [
            self::documentWith(self::textElement(['colour' => '#fff'])),
            'elements[0].colour',
            DslErrorCode::UnknownKey,
            'Did you mean "color"?',
        ];
        yield 'unknown text key with a case-only typo' => [
            self::documentWith(self::textElement(['LineHeight' => 1.2])),
            'elements[0].LineHeight',
            DslErrorCode::UnknownKey,
            'Did you mean "lineHeight"?',
        ];
        yield 'height is not a text key' => [
            self::documentWith(self::textElement(['height' => 200])),
            'elements[0].height',
            DslErrorCode::UnknownKey,
            'is not a valid key for a "text" element',
        ];
        yield 'unknown image key' => [
            self::documentWith(self::imageElement(['src' => 'x.png'])),
            'elements[0].src',
            DslErrorCode::UnknownKey,
            'Allowed keys: kind, id, asset, at, x, y, width, height, input.',
        ];
        yield 'unknown background key' => [
            self::documentWith(['kind' => 'background', 'id' => 'bg', 'cover' => true]),
            'elements[0].cover',
            DslErrorCode::UnknownKey,
            'Allowed keys: kind, id, asset, fillable.',
        ];
        yield 'unknown container key' => [
            self::documentWith(['kind' => 'container', 'id' => 'body', 'padding' => 4]),
            'elements[0].padding',
            DslErrorCode::UnknownKey,
            'Allowed keys: kind, id, members, children, maxHeight, gap, spaceAfter.',
        ];
        yield 'unknown at key (row is deliberately not v1)' => [
            self::documentWith(self::textElement(['at' => ['area' => 'top', 'row' => [1, 2]]])),
            'elements[0].at.row',
            DslErrorCode::UnknownKey,
            'Allowed keys: area, col, marginX, marginY, offsetX, offsetY.',
        ];
        yield 'unknown text input key' => [
            self::documentWith(self::textElement(['input' => ['lists' => true]])),
            'elements[0].input.lists',
            DslErrorCode::UnknownKey,
            'Allowed keys: name, maxLength, uppercase, hidable, locked, richText, sampleValue.',
        ];
        yield 'unknown image input key' => [
            self::documentWith(self::imageElement(['input' => ['allowSkew' => true]])),
            'elements[0].input.allowSkew',
            DslErrorCode::UnknownKey,
            'is not a valid key for an image input block',
        ];

        // --- wrong types ---------------------------------------------
        yield 'canvas.width as a string' => [
            ['canvas' => ['width' => '1080', 'height' => 1080], 'elements' => []],
            'canvas.width',
            DslErrorCode::InvalidType,
            'canvas.width must be a whole number, got a string ("1080").',
        ];
        yield 'size as a string' => [
            self::documentWith(self::textElement(['size' => '96'])),
            'elements[0].size',
            DslErrorCode::InvalidType,
            'must be a number, got a string ("96").',
        ];
        yield 'uppercase as a string' => [
            self::documentWith(self::textElement(['input' => ['uppercase' => 'yes']])),
            'elements[0].input.uppercase',
            DslErrorCode::InvalidType,
            'must be a boolean (true or false), got a string ("yes").',
        ];
        yield 'elements as an object' => [
            ['canvas' => ['width' => 1, 'height' => 1], 'elements' => ['a' => []]],
            'elements',
            DslErrorCode::InvalidType,
            'must be an array of element objects in stack order',
        ];
        yield 'element as a string' => [
            ['canvas' => ['width' => 1, 'height' => 1], 'elements' => ['headline']],
            'elements[0]',
            DslErrorCode::InvalidType,
            'must be an object with a "kind" key',
        ];
        yield 'at as a string' => [
            self::documentWith(self::textElement(['at' => 'top'])),
            'elements[0].at',
            DslErrorCode::InvalidType,
            '{"area": "top", "col": [1, 12]}',
        ];
        yield 'members as a string' => [
            self::documentWith(['kind' => 'container', 'id' => 'body', 'members' => 'headline', 'maxHeight' => 10]),
            'elements[0].members',
            DslErrorCode::InvalidType,
            'must be an array of element ids',
        ];

        // --- bad values ----------------------------------------------
        yield 'unknown kind' => [
            self::documentWith(['kind' => 'headline', 'id' => 'x']),
            'elements[0].kind',
            DslErrorCode::InvalidValue,
            'must be one of: text, image, background, container.',
        ];
        yield 'unknown align' => [
            self::documentWith(self::textElement(['align' => 'centre'])),
            'elements[0].align',
            DslErrorCode::InvalidValue,
            'must be one of: left, center, right, justify.',
        ];
        yield 'unknown area' => [
            self::documentWith(self::textElement(['at' => ['area' => 'header']])),
            'elements[0].at.area',
            DslErrorCode::InvalidValue,
            'must be one of: top, upper, middle, lower, bottom, full.',
        ];
        yield 'column out of range' => [
            self::documentWith(self::textElement(['at' => ['area' => 'top', 'col' => [0, 12]]])),
            'elements[0].at.col[0]',
            DslErrorCode::InvalidValue,
            'must be between 1 and 12, got 0.',
        ];
        yield 'column span reversed' => [
            self::documentWith(self::textElement(['at' => ['area' => 'top', 'col' => [8, 3]]])),
            'elements[0].at.col',
            DslErrorCode::InvalidValue,
            'must not be greater than the end column',
        ];
        yield 'column span of the wrong length' => [
            self::documentWith(self::textElement(['at' => ['area' => 'top', 'col' => [1, 6, 12]]])),
            'elements[0].at.col',
            DslErrorCode::InvalidValue,
            'must be an array of two column numbers',
        ];
        yield 'negative size' => [
            self::documentWith(self::textElement(['size' => -12])),
            'elements[0].size',
            DslErrorCode::InvalidValue,
            'must be greater than 0, got -12.',
        ];
        yield 'zero width' => [
            self::documentWith(self::textElement(['width' => 0])),
            'elements[0].width',
            DslErrorCode::InvalidValue,
            'must be greater than 0, got 0.',
        ];
        yield 'negative gap' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                self::textElement(['id' => 'b']),
                ['kind' => 'container', 'id' => 'body', 'members' => ['a', 'b'], 'maxHeight' => 400, 'gap' => -4],
            ),
            'elements[2].gap',
            DslErrorCode::InvalidValue,
            'must not be negative, got -4.',
        ];
        yield 'colour that is not hex' => [
            self::documentWith(self::textElement(['color' => 'white'])),
            'elements[0].color',
            DslErrorCode::InvalidValue,
            'must be a hex colour like "#c8102e" or "#fff"',
        ];
        yield 'colour with an alpha channel' => [
            self::documentWith(self::textElement(['color' => '#ffffff80'])),
            'elements[0].color',
            DslErrorCode::InvalidValue,
            'must be a hex colour',
        ];
        yield 'maxLength below one' => [
            self::documentWith(self::textElement(['input' => ['maxLength' => 0]])),
            'elements[0].input.maxLength',
            DslErrorCode::InvalidValue,
            'must be at least 1 character, got 0.',
        ];
        yield 'canvas side beyond the cap' => [
            ['canvas' => ['width' => 50000, 'height' => 1080], 'elements' => []],
            'canvas.width',
            DslErrorCode::InvalidValue,
            'must be between 1 and 20000 canvas pixels',
        ];
        yield 'asset given as a file name' => [
            self::documentWith(self::imageElement(['asset' => 'photo.jpg'])),
            'elements[0].asset',
            DslErrorCode::InvalidValue,
            'must be a gallery image id (a UUID as returned by list_gallery or upload_image), not a file name or a URL.',
        ];
        yield 'allowed directory given as a name' => [
            self::documentWith(self::imageElement(['input' => ['allowedDirectories' => ['Photos']]])),
            'elements[0].input.allowedDirectories[0]',
            DslErrorCode::InvalidValue,
            'must be a gallery folder id',
        ];
        yield 'id that is not a slug' => [
            self::documentWith(self::textElement(['id' => 'My Headline'])),
            'elements[0].id',
            DslErrorCode::InvalidValue,
            'must be a slug of at most 64 characters',
        ];
        yield 'id with an uppercase letter' => [
            self::documentWith(self::textElement(['id' => 'Headline'])),
            'elements[0].id',
            DslErrorCode::InvalidValue,
            'lowercase letters, digits',
        ];

        // --- document-level structure ---------------------------------
        yield 'duplicate slug' => [
            self::documentWith(self::textElement(), self::textElement()),
            'elements[1].id',
            DslErrorCode::DuplicateId,
            'is already used by elements[0]',
        ];
        yield 'two background elements' => [
            self::documentWith(
                ['kind' => 'background', 'id' => 'bg'],
                ['kind' => 'background', 'id' => 'bg2'],
            ),
            'elements[1]',
            DslErrorCode::InvalidStructure,
            'is a second "background" element',
        ];
        yield 'canvas background image plus a background element' => [
            [
                'canvas' => ['width' => 1080, 'height' => 1080, 'background' => ['image' => self::ASSET]],
                'elements' => [['kind' => 'background', 'id' => 'bg', 'asset' => self::ASSET_2]],
            ],
            'canvas.background.image',
            DslErrorCode::InvalidStructure,
            'both define the background layer',
        ];
        yield 'container with a single member' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                ['kind' => 'container', 'id' => 'body', 'members' => ['a'], 'maxHeight' => 400],
            ),
            'elements[1]',
            DslErrorCode::InvalidStructure,
            'would silently disappear from the saved design',
        ];
        yield 'container member that does not exist' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                ['kind' => 'container', 'id' => 'body', 'members' => ['a', 'typo'], 'maxHeight' => 400],
            ),
            'elements[1].members[1]',
            DslErrorCode::UnknownReference,
            'references "typo", which no element declares',
        ];
        yield 'container member listed twice' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                self::textElement(['id' => 'b']),
                ['kind' => 'container', 'id' => 'body', 'members' => ['a', 'a', 'b'], 'maxHeight' => 400],
            ),
            'elements[2].members[1]',
            DslErrorCode::InvalidStructure,
            'lists "a" twice',
        ];
        yield 'container member that is a fillable image placeholder' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                self::imageElement(['id' => 'slot', 'input' => ['name' => 'Foto']]),
                ['kind' => 'container', 'id' => 'body', 'members' => ['a', 'slot'], 'maxHeight' => 400],
            ),
            'elements[2].members[1]',
            DslErrorCode::InvalidStructure,
            'is a fillable image placeholder',
        ];
        yield 'container member that is the background layer' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                ['kind' => 'background', 'id' => 'bg'],
                ['kind' => 'container', 'id' => 'body', 'members' => ['a', 'bg'], 'maxHeight' => 400],
            ),
            'elements[2].members[1]',
            DslErrorCode::InvalidStructure,
            'The background is never a container member',
        ];
        yield 'container listed in members instead of children' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                self::textElement(['id' => 'b']),
                ['kind' => 'container', 'id' => 'inner', 'members' => ['a', 'b']],
                ['kind' => 'container', 'id' => 'root', 'members' => ['a', 'inner'], 'maxHeight' => 400],
            ),
            'elements[3].members[1]',
            DslErrorCode::InvalidStructure,
            'listing it in "children", not in "members"',
        ];
        yield 'non-container listed in children' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                self::textElement(['id' => 'b']),
                ['kind' => 'container', 'id' => 'body', 'members' => ['a'], 'children' => ['b'], 'maxHeight' => 400],
            ),
            'elements[2].children[0]',
            DslErrorCode::InvalidStructure,
            'which is not a container',
        ];
        yield 'container nested in two parents' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                self::textElement(['id' => 'b']),
                self::textElement(['id' => 'c']),
                ['kind' => 'container', 'id' => 'inner', 'members' => ['a', 'b']],
                ['kind' => 'container', 'id' => 'left', 'members' => ['c'], 'children' => ['inner'], 'maxHeight' => 400],
                ['kind' => 'container', 'id' => 'right', 'members' => ['c'], 'children' => ['inner'], 'maxHeight' => 400],
            ),
            'elements[5].children[0]',
            DslErrorCode::InvalidStructure,
            'A container has exactly one parent',
        ];
        yield 'container nested in itself' => [
            self::documentWith(
                self::textElement(['id' => 'a']),
                self::textElement(['id' => 'b']),
                ['kind' => 'container', 'id' => 'body', 'members' => ['a', 'b'], 'children' => ['body'], 'maxHeight' => 400],
            ),
            'elements[2].children[0]',
            DslErrorCode::InvalidStructure,
            'cannot be nested inside itself',
        ];
    }

    public function testDetectsAContainerCycle(): void
    {
        $exception = self::reject(self::documentWith(
            self::textElement(['id' => 'a']),
            self::textElement(['id' => 'b']),
            self::textElement(['id' => 'c']),
            self::textElement(['id' => 'd']),
            ['kind' => 'container', 'id' => 'one', 'members' => ['a', 'b'], 'children' => ['two'], 'maxHeight' => 400],
            ['kind' => 'container', 'id' => 'two', 'members' => ['c', 'd'], 'children' => ['one'], 'maxHeight' => 400],
        ));

        $messages = implode("\n", array_map(
            static fn ($violation): string => $violation->message,
            $exception->violations,
        ));

        self::assertStringContainsString('closes a container cycle: one -> two -> one', $messages);
        self::assertStringContainsString('Nested containers must form a tree.', $messages);
    }

    // -----------------------------------------------------------------
    // reporting behaviour
    // -----------------------------------------------------------------

    public function testReportsEveryProblemAtOnceInDocumentOrder(): void
    {
        $exception = self::reject([
            'canvas' => ['width' => 1080],
            'elements' => [
                self::textElement(['colour' => '#fff'], remove: ['font']),
                self::imageElement(['id' => 'My Photo', 'asset' => 'photo.jpg']),
            ],
        ]);

        self::assertSame(
            [
                'canvas.height',
                'elements[0].colour',
                'elements[0].font',
                'elements[1].id',
                'elements[1].asset',
            ],
            array_map(static fn ($violation): string => $violation->path, $exception->violations),
        );

        self::assertStringContainsString('The design document has 5 problems:', $exception->getMessage());
        self::assertStringContainsString('1. canvas.height is required.', $exception->getMessage());
        self::assertStringContainsString('5. elements[1].asset must be a gallery image id', $exception->getMessage());
    }

    public function testASingleProblemReadsAsOneSentence(): void
    {
        $exception = self::reject(self::documentWith(self::textElement(remove: ['font'])));

        self::assertSame(
            'The design document is invalid. elements[0].font is required. An exact face string from get_context, e.g. "Hero New (Hero New ExtraBold)".',
            $exception->getMessage(),
        );
    }

    public function testTheViolationListIsAvailableStructurallyForTheToolLayer(): void
    {
        $exception = self::reject(self::documentWith(self::textElement(remove: ['size'])));

        self::assertSame(
            [['path' => 'elements[0].size', 'code' => 'missing_key', 'message' => $exception->violations[0]->message]],
            $exception->toArray(),
        );
    }

    public function testCapsHowManyProblemsAreSpelledOutButKeepsThemAll(): void
    {
        $elements = [];

        for ($index = 0; $index < 25; $index++) {
            $elements[] = self::textElement(['id' => 'text-' . $index], remove: ['font']);
        }

        $exception = self::reject(['canvas' => ['width' => 1080, 'height' => 1080], 'elements' => $elements]);

        self::assertCount(25, $exception->violations);
        self::assertStringContainsString('The design document has 25 problems:', $exception->getMessage());
        self::assertStringContainsString('… and 5 more problem(s).', $exception->getMessage());
        self::assertStringNotContainsString('21. ', $exception->getMessage());
    }

    // -----------------------------------------------------------------
    // canonical wire form
    // -----------------------------------------------------------------

    public function testToArrayIsCanonicalAndReparsesToAnEqualDocument(): void
    {
        $document = DslParser::parse(self::fullDocument());
        $reparsed = DslParser::parse($document->toArray());

        self::assertEquals($document, $reparsed);
        self::assertSame($document->toArray(), $reparsed->toArray());
    }

    public function testToArrayNormalizesShorthandColoursAndOmittedOptionals(): void
    {
        $document = DslParser::parse(self::documentWith(self::textElement(['color' => '#FFF'])));
        $wire = $document->toArray();

        self::assertSame(['width' => 1080, 'height' => 1350, 'background' => null], $wire['canvas']);
        self::assertSame('#ffffff', $wire['elements'][0]['color']);
        self::assertSame('left', $wire['elements'][0]['align']);
        self::assertSame(1.16, $wire['elements'][0]['lineHeight']);
        self::assertArrayHasKey('input', $wire['elements'][0]);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed>|list<mixed>|string $payload
     */
    private static function reject(array|string $payload): InvalidDesignDocument
    {
        try {
            is_string($payload) ? DslParser::parseJson($payload) : DslParser::parse($payload);
        } catch (InvalidDesignDocument $exception) {
            self::assertNotSame([], $exception->violations);

            return $exception;
        }

        self::fail('Expected the design document to be rejected, but it parsed.');
    }

    /**
     * The §3.4 example document, one field per key so a parse can be checked
     * field by field.
     *
     * @return array<string, mixed>
     */
    private static function fullDocument(): array
    {
        return [
            'canvas' => [
                'width' => 1080,
                'height' => 1350,
                'background' => ['fill' => '#111111'],
            ],
            'elements' => [
                ['kind' => 'background', 'id' => 'bg', 'asset' => self::ASSET, 'fillable' => true],
                [
                    'kind' => 'text',
                    'id' => 'headline',
                    'text' => 'SLEVA 50 %',
                    'at' => ['area' => 'top', 'col' => [1, 12], 'marginX' => 80, 'offsetY' => 40],
                    'font' => self::FACE,
                    'size' => 96,
                    'color' => '#ffffff',
                    'align' => 'left',
                    'lineHeight' => 1.16,
                    'input' => [
                        'name' => 'Nadpis',
                        'maxLength' => 24,
                        'uppercase' => true,
                        'hidable' => false,
                        'locked' => false,
                        'richText' => false,
                        'sampleValue' => 'SLEVA 50 %',
                    ],
                ],
                [
                    'kind' => 'text',
                    'id' => 'subhead',
                    'text' => 'Na vše do konce týdne',
                    'at' => ['area' => 'upper', 'col' => [1, 8], 'marginX' => 80],
                    'font' => self::FACE,
                    'size' => 42,
                    'align' => 'center',
                    'input' => ['name' => 'Podnadpis', 'richText' => true],
                ],
                [
                    'kind' => 'image',
                    'id' => 'photo',
                    'at' => ['area' => 'bottom', 'col' => [1, 12]],
                    'height' => 480,
                    'asset' => self::ASSET_2,
                    'input' => [
                        'name' => 'Foto',
                        'placeholder' => true,
                        'allowMove' => true,
                        'allowResize' => false,
                        'allowRotate' => false,
                        'hidable' => true,
                        'allowedDirectories' => [self::DIRECTORY],
                    ],
                ],
                [
                    'kind' => 'container',
                    'id' => 'body',
                    'members' => ['headline', 'subhead'],
                    'children' => [],
                    'maxHeight' => 400,
                    'gap' => 24,
                    'spaceAfter' => 60,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> ...$elements
     * @return array<string, mixed>
     */
    private static function documentWith(array ...$elements): array
    {
        return [
            'canvas' => ['width' => 1080, 'height' => 1350],
            'elements' => array_values($elements),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $remove
     * @return array<string, mixed>
     */
    private static function textElement(array $overrides = [], array $remove = []): array
    {
        $element = [
            'kind' => 'text',
            'id' => 'headline',
            'text' => 'Hello',
            'font' => self::FACE,
            'size' => 96,
            'at' => ['area' => 'top'],
        ];

        foreach ($remove as $key) {
            unset($element[$key]);
        }

        return array_merge($element, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function imageElement(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'image',
            'id' => 'photo',
            'at' => ['area' => 'bottom'],
        ], $overrides);
    }
}
