<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Mcp\Design\CompilationContextFactory;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * {@see CompilationContextFactory} — the one part of a compile that reads the
 * database, and therefore the only part that needs a kernel.
 *
 * What is asserted here is the boundary, not the compile: which ids resolve,
 * which quietly do not, and that the font list is exactly what `get_context`
 * hands the agent. The §4 invariants themselves live in `DesignCompilerTest`,
 * against a hand-built context.
 */
final class CompilationContextFactoryTest extends KernelTestCase
{
    public function testFontsAreTheProjectsFaceStringsVerbatim(): void
    {
        $context = $this->contextFor(TestDataFixture::PROJECT_1_ID, []);

        // Exactly the strings `Font::faceFamily()` builds and the render
        // template registers `@font-face` under — a design must reproduce one
        // of them byte for byte.
        self::assertSame(['Rubik (Rubik Regular)', 'Rubik (Rubik Bold)'], $context->allowedFonts);
        self::assertTrue($context->allowsFont('Rubik (Rubik Bold)'));
        self::assertFalse($context->allowsFont('Rubik'));
        self::assertFalse($context->allowsFont('rubik (rubik bold)'));
    }

    public function testAProjectWithoutFontsOffersNone(): void
    {
        self::assertSame([], $this->contextFor(TestDataFixture::PROJECT_2_ID, [])->allowedFonts);
    }

    public function testOnlyTheAssetsTheDocumentNamesAreResolved(): void
    {
        $context = $this->contextFor(TestDataFixture::PROJECT_1_ID, [
            ['kind' => 'image', 'id' => 'photo', 'asset' => TestDataFixture::FILE_IN_ALLOWED_ID, 'x' => 0, 'y' => 0, 'width' => 100],
        ]);

        self::assertSame([TestDataFixture::FILE_IN_ALLOWED_ID], array_keys($context->assets));

        $asset = $context->asset(TestDataFixture::FILE_IN_ALLOWED_ID);

        self::assertNotNull($asset);
        self::assertSame('fixtures/in-allowed.png', $asset->path);
        self::assertStringEndsWith('/fixtures/in-allowed.png', $asset->url);
        // Natural size is READ FROM THE BUCKET, so it is deliberately not
        // asserted here: whether these fixture rows have bytes behind them
        // depends on what else has run. Both branches are covered where they
        // matter — in `DesignCompilerTest`, against a context built by hand.
        self::assertSame($asset->width === null, $asset->height === null);
    }

    /**
     * Every failure mode collapses to an ABSENT entry rather than an exception,
     * so the compiler can report which ELEMENT is wrong. Trashed images matter
     * most here: the row still exists and still has a storage object, so nothing
     * but an explicit check would notice — and `ResolveImageOverrides` would
     * refuse the same id at fill time anyway.
     */
    public function testUnresolvableIdsAreSimplyAbsent(): void
    {
        $context = $this->contextFor(TestDataFixture::PROJECT_1_ID, [
            ['kind' => 'image', 'id' => 'a', 'asset' => TestDataFixture::FILE_TRASHED_ID, 'x' => 0, 'y' => 0, 'width' => 100],
            ['kind' => 'image', 'id' => 'b', 'asset' => Uuid::uuid4()->toString(), 'x' => 0, 'y' => 0, 'width' => 100],
            ['kind' => 'image', 'id' => 'c', 'asset' => TestDataFixture::FILE_IN_ROOT_ID, 'x' => 0, 'y' => 0, 'width' => 100],
        ]);

        self::assertSame([TestDataFixture::FILE_IN_ROOT_ID], array_keys($context->assets));
    }

    public function testAnotherProjectsPictureIsNotReachable(): void
    {
        $context = $this->contextFor(TestDataFixture::PROJECT_2_ID, [
            ['kind' => 'image', 'id' => 'a', 'asset' => TestDataFixture::FILE_IN_ROOT_ID, 'x' => 0, 'y' => 0, 'width' => 100],
        ]);

        self::assertSame([], $context->assets);
    }

    public function testTheBackgroundShorthandAndTheBackgroundElementAreBothResolved(): void
    {
        $factory = self::getContainer()->get(CompilationContextFactory::class);
        $project = self::getContainer()->get(ProjectRepository::class)
            ->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));

        $shorthand = $factory->forProject($project, DslParser::parse([
            'canvas' => ['width' => 1080, 'height' => 1080, 'background' => ['image' => TestDataFixture::FILE_IN_ROOT_ID]],
            'elements' => [],
        ]));

        self::assertSame([TestDataFixture::FILE_IN_ROOT_ID], array_keys($shorthand->assets));

        $element = $this->contextFor(TestDataFixture::PROJECT_1_ID, [
            ['kind' => 'background', 'id' => 'bg', 'asset' => TestDataFixture::FILE_IN_ROOT_ID],
        ]);

        self::assertSame([TestDataFixture::FILE_IN_ROOT_ID], array_keys($element->assets));
    }

    /**
     * @param list<array<string, mixed>> $elements
     */
    private function contextFor(string $projectId, array $elements): \WBoost\Web\Mcp\Design\CompilationContext
    {
        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString($projectId));

        return self::getContainer()->get(CompilationContextFactory::class)->forProject(
            $project,
            DslParser::parse(['canvas' => ['width' => 1080, 'height' => 1080], 'elements' => $elements]),
        );
    }
}
