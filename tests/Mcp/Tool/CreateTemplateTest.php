<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\McpAccessToken;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpTokenGenerator;
use WBoost\Web\Message\Template\StoreTemplateVariantPreviewImage;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;
use WBoost\Web\Value\BackgroundMode;

/**
 * `create_template` — a new standalone template + its first variant, the MCP
 * twin of the web's "Nová šablona" form.
 *
 * What is load-bearing: the new variant is immediately usable by the rest of
 * the tool chain (`find_templates`, `describe_variant`, `set_design`), it is
 * created the way the web creates one (layer background mode, next sort
 * position), and a refusal — every one of them — leaves no row behind.
 *
 * @covers \WBoost\Web\Mcp\Tool\CreateTemplateTool
 */
final class CreateTemplateTest extends WebTestCase
{
    /** A face string project 1 really owns — {@see TestDataFixture}'s Rubik. */
    private const string FONT = 'Rubik (Rubik Regular)';

    /** A design-scoped token of SHARED_USER (project 1 is shared with them read-only), minted per test. */
    private const string SHARED_DESIGN_TOKEN = 'wb_mcp_test-design-token-shared-user';

    private null|KernelBrowser $browser = null;

    /**
     * Thumbnails `set_design` writes — object storage outlives the DAMA rollback.
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

    public function testCreatesAPixelTemplateTheWayTheWebDoes(): void
    {
        [$templates, $variants] = $this->counts();

        $created = $this->create(['name' => '  Jarní plakát  ', 'dimension' => ['unit' => 'px', 'width' => 1200, 'height' => 628]]);

        self::assertSame('Jarní plakát', $created['templateName'], 'The name is trimmed.');
        self::assertSame(TestDataFixture::PROJECT_1_ID, $created['projectId']);
        self::assertNull($created['categoryId']);
        self::assertNull($created['categoryName']);
        self::assertSame(
            ['label' => '1200 × 628 px', 'preset' => null, 'unit' => 'px', 'unitWidth' => 1200, 'unitHeight' => 628, 'width' => 1200, 'height' => 628, 'printFormat' => false, 'bleedMm' => 0, 'bleedPx' => 0, 'cropMarks' => false],
            $created['dimension'],
        );
        self::assertStringStartsWith('http', $created['editorUrl'], 'editorUrl must be absolute — an agent pastes it to a human.');
        self::assertStringContainsString('/template-variant/' . $created['variantId'] . '/editor', $created['editorUrl']);
        self::assertStringContainsString('set_design', $created['status']);

        self::assertSame([$templates + 1, $variants + 1], $this->counts());

        $this->em()->clear();
        $variant = $this->em()->find(TemplateVariant::class, Uuid::fromString($created['variantId']));
        self::assertInstanceOf(TemplateVariant::class, $variant);
        self::assertSame(BackgroundMode::Layer, $variant->backgroundMode, 'Every variant created today is layer-mode.');
        self::assertSame($created['templateId'], $variant->template->id->toString());
        self::assertSame(TestDataFixture::PROJECT_1_ID, $variant->template->project->id->toString());
        self::assertNull($variant->template->category);

        $highestOther = $this->em()->createQuery('SELECT MAX(t.position) FROM ' . Template::class . ' t WHERE t.project = :project AND t.id != :id')
            ->setParameter('project', TestDataFixture::PROJECT_1_ID)
            ->setParameter('id', $created['templateId'])
            ->getSingleScalarResult();
        self::assertGreaterThan((int) $highestOther, $variant->template->position, 'The handler files it last, as on the web.');
    }

    public function testCreatesAPrintTemplateWithBleedAndCropMarksInACategory(): void
    {
        $created = $this->create([
            'name' => 'Leták A5',
            'dimension' => ['unit' => 'mm', 'width' => 148, 'height' => 210, 'bleedMm' => 3, 'cropMarks' => true],
            'categoryId' => TestDataFixture::TEMPLATE_CATEGORY_1_ID,
        ]);

        self::assertSame(TestDataFixture::TEMPLATE_CATEGORY_1_ID, $created['categoryId']);
        self::assertSame(TestDataFixture::TEMPLATE_CATEGORY_1_NAME, $created['categoryName']);
        self::assertSame(
            ['label' => '148 × 210 mm', 'preset' => null, 'unit' => 'mm', 'unitWidth' => 148, 'unitHeight' => 210, 'width' => 1748, 'height' => 2480, 'printFormat' => true, 'bleedMm' => 3, 'bleedPx' => 35, 'cropMarks' => true],
            $created['dimension'],
        );

        // describe_variant reports the very same dimension.
        $described = $this->payload($this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'describe_variant', ['variantId' => $created['variantId']]));
        self::assertSame($created['dimension'], $described['dimension']);
        self::assertSame([], $described['inputs'], 'The template starts empty.');

        $template = $this->found($created['templateId']);
        self::assertSame(TestDataFixture::TEMPLATE_CATEGORY_1_ID, $template['categoryId']);
    }

    public function testAPrintFormatDefaultsToTheStandardBleedWithoutMarks(): void
    {
        $created = $this->create(['name' => 'Vizitka', 'dimension' => ['unit' => 'cm', 'width' => 9, 'height' => 5]]);

        self::assertSame('cm', $created['dimension']['unit']);
        self::assertSame(1063, $created['dimension']['width']);
        self::assertSame(591, $created['dimension']['height']);
        self::assertEquals(5, $created['dimension']['bleedMm']);
        self::assertFalse($created['dimension']['cropMarks']);

        $none = $this->create(['name' => 'Vizitka bez spadávky', 'dimension' => ['unit' => 'mm', 'width' => 90, 'height' => 50, 'bleedMm' => 0]]);
        self::assertEquals(0, $none['dimension']['bleedMm'], 'An explicit 0 is no bleed, not the default.');
    }

    public function testCreatesFromAPreset(): void
    {
        $created = $this->create(['name' => 'Instagram story', 'dimension' => ['preset' => '9:16']]);

        self::assertSame(
            ['label' => '9:16', 'preset' => '9:16', 'unit' => 'px', 'unitWidth' => 1080, 'unitHeight' => 1920, 'width' => 1080, 'height' => 1920, 'printFormat' => false, 'bleedMm' => 0, 'bleedPx' => 0, 'cropMarks' => false],
            $created['dimension'],
        );
    }

    /**
     * The dimension `get_context` / `describe_variant` report can be sent back
     * as it is: there `width` / `height` are canvas PIXELS, so `unitWidth` /
     * `unitHeight` have to win.
     */
    public function testAcceptsADimensionCopiedFromDescribeVariant(): void
    {
        $described = $this->payload($this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'describe_variant', ['variantId' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]));
        $source = $described['dimension'];
        self::assertIsArray($source);
        self::assertSame('mm', $source['unit']);

        $created = $this->create(['name' => 'Kopie formátu', 'dimension' => $source]);

        self::assertSame($source, $created['dimension']);
    }

    public function testTheNewVariantIsFoundAndDesignable(): void
    {
        $created = $this->create(['name' => 'Nový návrh', 'dimension' => ['preset' => '4:5']]);

        $template = $this->found($created['templateId']);
        self::assertSame('Nový návrh', $template['name']);
        self::assertFalse($template['grouped']);
        self::assertIsArray($template['variants']);
        self::assertCount(1, $template['variants']);
        self::assertIsArray($template['variants'][0]);
        self::assertSame($created['variantId'], $template['variants'][0]['id']);
        self::assertSame($created['dimension'], $template['variants'][0]['dimension']);
        self::assertSame(0, $template['variants'][0]['inputCount']);

        $this->storedObjects[] = StoreTemplateVariantPreviewImage::pathFor(Uuid::fromString($created['variantId']));

        $saved = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'set_design', [
            'variantId' => $created['variantId'],
            'design' => [
                'canvas' => ['width' => $created['dimension']['width'], 'height' => $created['dimension']['height'], 'background' => ['fill' => '#ffffff']],
                'elements' => [[
                    'kind' => 'text', 'id' => 'headline', 'text' => 'Stand-in headline',
                    'font' => self::FONT, 'size' => 72, 'x' => 80, 'y' => 120, 'width' => 920,
                    'input' => ['name' => 'Headline'],
                ]],
            ],
        ]);
        $summary = $this->payload($saved);
        self::assertTrue($summary['saved'], $saved['text']);
        self::assertSame(1080, $summary['canvasWidth']);
        self::assertSame(1350, $summary['canvasHeight']);

        $described = $this->payload($this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'describe_variant', ['variantId' => $created['variantId']]));
        self::assertIsArray($described['inputs']);
        self::assertCount(1, $described['inputs']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        $px = ['unit' => 'px', 'width' => 1080, 'height' => 1080];

        yield 'empty name' => [['name' => '   ', 'dimension' => $px], 'invalid_name:'];
        yield 'short name' => [['name' => 'ab', 'dimension' => $px], 'invalid_name:'];
        yield 'long name' => [['name' => str_repeat('ž', 31), 'dimension' => $px], 'invalid_name:'];
        yield 'dimension without a unit' => [['dimension' => ['label' => 'A4']], 'invalid_dimension:'];
        yield 'unknown unit' => [['dimension' => ['unit' => 'in', 'width' => 8, 'height' => 11]], 'invalid_dimension:'];
        yield 'missing height' => [['dimension' => ['unit' => 'px', 'width' => 1080]], 'invalid_dimension:'];
        yield 'zero width' => [['dimension' => ['unit' => 'px', 'width' => 0, 'height' => 1080]], 'invalid_dimension:'];
        yield 'negative height' => [['dimension' => ['unit' => 'mm', 'width' => 210, 'height' => -297]], 'invalid_dimension:'];
        yield 'size as text' => [['dimension' => ['unit' => 'px', 'width' => '1080', 'height' => 1080]], 'invalid_dimension:'];
        yield 'too small' => [['dimension' => ['unit' => 'px', 'width' => 15, 'height' => 1080]], 'invalid_dimension:'];
        yield 'too large px' => [['dimension' => ['unit' => 'px', 'width' => 10001, 'height' => 1080]], 'invalid_dimension:'];
        yield 'too large mm' => [['dimension' => ['unit' => 'mm', 'width' => 900, 'height' => 297]], 'invalid_dimension:'];
        yield 'bleed above the range' => [['dimension' => ['unit' => 'mm', 'width' => 210, 'height' => 297, 'bleedMm' => 10.5]], 'invalid_bleed'];
        yield 'negative bleed' => [['dimension' => ['unit' => 'mm', 'width' => 210, 'height' => 297, 'bleedMm' => -1]], 'invalid_bleed'];
        yield 'crop marks not a boolean' => [['dimension' => ['unit' => 'mm', 'width' => 210, 'height' => 297, 'cropMarks' => 'yes']], 'invalid_crop_marks'];
        yield 'bleed on px' => [['dimension' => $px + ['bleedMm' => 3]], 'print_setup_not_available'];
        yield 'crop marks on px' => [['dimension' => $px + ['cropMarks' => true]], 'print_setup_not_available'];
        yield 'bleed on a preset' => [['dimension' => ['preset' => '4:5', 'bleedMm' => 3]], 'print_setup_not_available'];
        yield 'unknown preset' => [['dimension' => ['preset' => '16:9']], 'unknown_preset:'];
        yield 'retired preset' => [['dimension' => ['preset' => '1:1']], 'unknown_preset:'];
        yield 'preset not a string' => [['dimension' => ['preset' => 45]], 'unknown_preset:'];
        yield 'nonexistent category' => [['dimension' => $px, 'categoryId' => '00000000-0000-0000-0000-0000000000ff'], 'unknown_category:'];
        yield 'malformed category' => [['dimension' => $px, 'categoryId' => 'tisk'], 'unknown_category:'];
        yield 'unknown project' => [['projectId' => '00000000-0000-0000-0000-0000000000ff', 'dimension' => $px], 'project_not_found:'];
        yield 'foreign project' => [['projectId' => TestDataFixture::PROJECT_2_ID, 'dimension' => $px], 'project_not_found:'];
        yield 'malformed project' => [['projectId' => 'Project 1', 'dimension' => $px], 'project_not_found:'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('refusals')]
    public function testRefusalsNameTheirCodeAndCreateNothing(array $arguments, string $code): void
    {
        $before = $this->counts();

        $refused = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'create_template', $arguments + ['projectId' => TestDataFixture::PROJECT_1_ID, 'name' => 'Odmítnutá šablona']);

        self::assertTrue($refused['isError'], $refused['text']);
        self::assertStringContainsString($code, $refused['text']);

        if (str_ends_with($code, ':')) {
            self::assertStringStartsWith($code, $refused['text'], 'A refusal starts with its stable code.');
        } else {
            self::assertStringStartsWith('invalid_dimension:', $refused['text'], 'A print-setup refusal is a dimension refusal that names the shared reason.');
        }

        self::assertSame($before, $this->counts(), 'A refusal creates nothing.');
    }

    public function testACategoryOfAnotherProjectIsUnknown(): void
    {
        $categoryId = Uuid::uuid7();
        $project2 = $this->em()->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_2_ID));
        self::assertInstanceOf(Project::class, $project2);
        $this->em()->persist(new TemplateCategory($categoryId, $project2, new DateTimeImmutable(), 'Cizí kategorie', 0));
        $this->em()->flush();

        $before = $this->counts();
        $arguments = ['projectId' => TestDataFixture::PROJECT_1_ID, 'name' => 'Odmítnutá šablona', 'dimension' => ['preset' => '4:5']];

        $foreign = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'create_template', $arguments + ['categoryId' => $categoryId->toString()]);
        $unknown = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'create_template', $arguments + ['categoryId' => '00000000-0000-0000-0000-0000000000ff']);

        self::assertTrue($foreign['isError'], $foreign['text']);
        self::assertStringStartsWith('unknown_category:', $foreign['text']);
        self::assertSame(
            str_replace($categoryId->toString(), '<id>', $foreign['text']),
            str_replace('00000000-0000-0000-0000-0000000000ff', '<id>', $unknown['text']),
            'A category of another project reads exactly like one that does not exist.',
        );
        self::assertSame($before, $this->counts());
    }

    public function testAForeignProjectIsIndistinguishableFromAnUnknownId(): void
    {
        $arguments = ['name' => 'Odmítnutá šablona', 'dimension' => ['preset' => '4:5']];

        $foreign = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'create_template', $arguments + ['projectId' => TestDataFixture::PROJECT_2_ID]);
        $unknown = $this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'create_template', $arguments + ['projectId' => '00000000-0000-0000-0000-0000000000ff']);

        self::assertSame(
            str_replace(TestDataFixture::PROJECT_2_ID, '<id>', $foreign['text']),
            str_replace('00000000-0000-0000-0000-0000000000ff', '<id>', $unknown['text']),
        );
        self::assertStringContainsString('was not found, or this account cannot access it', $foreign['text']);
    }

    /**
     * Scope is not permission: a design-scoped token of a user the project is
     * only SHARED with (read) may see it and may not create in it.
     */
    public function testAUserWhoCannotEditTheProjectIsRefused(): void
    {
        $sharedUser = $this->em()->getRepository(User::class)->findOneBy(['email' => TestDataFixture::SHARED_USER_EMAIL]);
        self::assertInstanceOf(User::class, $sharedUser);

        $this->em()->persist(new McpAccessToken(
            Uuid::uuid7(),
            $sharedUser,
            'Design agent of a share recipient',
            [McpScope::TemplatesDesign->value],
            (new McpTokenGenerator())->hash(self::SHARED_DESIGN_TOKEN),
            new DateTimeImmutable(),
        ));
        $this->em()->flush();

        $before = $this->counts();

        // The premise: this token really does reach the project for reading.
        $this->payload($this->call(self::SHARED_DESIGN_TOKEN, 'find_templates', ['projectId' => TestDataFixture::PROJECT_1_ID]));

        $refused = $this->call(self::SHARED_DESIGN_TOKEN, 'create_template', [
            'projectId' => TestDataFixture::PROJECT_1_ID,
            'name' => 'Odmítnutá šablona',
            'dimension' => ['preset' => '4:5'],
        ]);

        self::assertTrue($refused['isError'], $refused['text']);
        self::assertStringStartsWith('project_not_editable:', $refused['text']);
        self::assertSame($before, $this->counts());
    }

    public function testNeedsTheDesignScope(): void
    {
        $before = $this->counts();

        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);
        TestingMcpClient::request($browser, 'tools/call', ['name' => 'create_template', 'arguments' => [
            'projectId' => TestDataFixture::PROJECT_1_ID,
            'name' => 'Odmítnutá šablona',
            'dimension' => ['preset' => '4:5'],
        ]], $sessionId, TestDataFixture::MCP_TOKEN_READ_ONLY);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('templates:design', $content);
        self::assertStringNotContainsString('"variantId"', $content);
        self::assertStringNotContainsString('variantId\\"', $content);
        self::assertSame($before, $this->counts());

        self::assertNotContains('create_template', $this->toolNames(TestDataFixture::MCP_TOKEN_READ_ONLY), 'A tool the token lacks the scope for is not listed.');
        self::assertContains('create_template', $this->toolNames(TestDataFixture::MCP_TOKEN_DESIGN_ONLY));
    }

    public function testDescriptionTellsAnAgentWhatComesNext(): void
    {
        $description = $this->tools(TestDataFixture::MCP_TOKEN_ACTIVE)['create_template'] ?? '';

        self::assertStringContainsString('EMPTY', $description);
        self::assertStringContainsString('set_design', $description);
        self::assertStringContainsString('canvas.width', $description);
        self::assertStringContainsString('uncategorised', $description);
        self::assertStringContainsString('"4:5"', $description);

        foreach (['project_not_found', 'project_not_editable', 'invalid_name', 'invalid_dimension', 'unknown_preset', 'unknown_category'] as $code) {
            self::assertStringContainsString($code, $description);
        }
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $arguments
     * @return array{templateId: string, variantId: string, templateName: string, projectId: string, categoryId: null|string, categoryName: null|string, dimension: array<string, mixed>, editorUrl: string, status: string}
     */
    private function create(array $arguments): array
    {
        /** @var array{templateId: string, variantId: string, templateName: string, projectId: string, categoryId: null|string, categoryName: null|string, dimension: array<string, mixed>, editorUrl: string, status: string} $payload */
        $payload = $this->payload($this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'create_template', $arguments + ['projectId' => TestDataFixture::PROJECT_1_ID]));

        return $payload;
    }

    /**
     * The template as `find_templates` lists it.
     *
     * @return array<string, mixed>
     */
    private function found(string $templateId): array
    {
        $listing = $this->payload($this->call(TestDataFixture::MCP_TOKEN_ACTIVE, 'find_templates', ['projectId' => TestDataFixture::PROJECT_1_ID]));
        self::assertIsArray($listing['templates']);

        foreach ($listing['templates'] as $template) {
            self::assertIsArray($template);

            if ($template['id'] === $templateId) {
                /** @var array<string, mixed> $template */
                return $template;
            }
        }

        self::fail(sprintf('find_templates does not list the new template %s.', $templateId));
    }

    /**
     * @param array{isError: bool, text: string} $result
     * @return array<string, mixed>
     */
    private function payload(array $result): array
    {
        self::assertFalse($result['isError'], $result['text']);
        $payload = json_decode($result['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{isError: bool, text: string}
     */
    private function call(string $token, string $tool, array $arguments): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);
        TestingMcpClient::request($browser, 'tools/call', ['name' => $tool, 'arguments' => $arguments], $sessionId, $token);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{result?: array{isError?: bool, content: list<array{text?: string}>}} $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('result', $payload, (string) $response->getContent());

        return [
            'isError' => ($payload['result']['isError'] ?? false) === true,
            'text' => $payload['result']['content'][0]['text'] ?? '',
        ];
    }

    /**
     * @return list<string>
     */
    private function toolNames(string $token): array
    {
        return array_keys($this->tools($token));
    }

    /**
     * @return array<string, string> tool name => description
     */
    private function tools(string $token): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);
        TestingMcpClient::request($browser, 'tools/list', [], $sessionId, $token);

        /** @var array{result?: array{tools?: list<array{name: string, description: string}>}} $payload */
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return array_column($payload['result']['tools'] ?? [], 'description', 'name');
    }

    /**
     * Template and variant row counts, read from the database.
     *
     * @return array{int, int}
     */
    private function counts(): array
    {
        $em = $this->em();

        return [
            (int) $em->createQuery('SELECT COUNT(t.id) FROM ' . Template::class . ' t')->getSingleScalarResult(),
            (int) $em->createQuery('SELECT COUNT(v.id) FROM ' . TemplateVariant::class . ' v')->getSingleScalarResult(),
        ];
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    private function em(): EntityManagerInterface
    {
        $this->browser();

        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
