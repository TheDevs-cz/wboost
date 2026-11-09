<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Twig\Components\AbstractVariantFiller;

/**
 * "Uživatel může posunout", M2 — the single fill page's settle contract with
 * element offsets: the server stamps `data-state-hash` from its normalised
 * `offsetValues` (`O:<id>=<x>,<y>` lines, moved elements only, after the
 * `R:` group), and the REAL assets/controllers/fill_state_hash.js — run in
 * node over the mirrors exactly as a browser exposes them — must compute the
 * same value. Any drift = "spinner forever" (the settle never matches).
 *
 * @covers \WBoost\Web\Twig\Components\AbstractVariantFiller
 */
final class FillStateHashOffsetsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    /** A free textbox — movable once it carries `userMove`. */
    private const string BADGE = TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID;

    /** A container MEMBER — its own `userMove` is inert. */
    private const string HEADLINE = TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID;

    public function testTheBrowserHashOfMovedElementsMatchesTheServerStamp(): void
    {
        $client = $this->movableClient();

        // Requested -40 up with maxUp 20 → clamped to -20; x in 1/100 px.
        $rendered = $this->render($client, [self::BADGE => ['x' => '12.345', 'y' => '-40'], self::HEADLINE => ['x' => 0, 'y' => 30]]);

        $mirrors = (new Crawler($rendered))->filter('[data-offset-mirror="' . self::BADGE . '"]');
        self::assertCount(2, $mirrors, 'one x + one y mirror per movable element');
        self::assertCount(2, (new Crawler($rendered))->filter('[data-offset-mirror]'), 'none for the container member');
        self::assertSame(['12.35', '-20'], [$mirrors->eq(0)->attr('value'), $mirrors->eq(1)->attr('value')], 'the mirrors carry the CLAMPED move');

        self::assertSame($this->serverHash($rendered), $this->browserHash($rendered));
    }

    public function testAnUnmovedMovableElementHashesLikeAnImmovableDesign(): void
    {
        $plainClient = self::createClient();
        TestingLogin::logInAsUser($plainClient, TestDataFixture::USER_1_EMAIL);
        $plain = $this->render($plainClient, []);
        self::assertCount(0, (new Crawler($plain))->filter('[data-offset-mirror]'), 'no mirrors without a movable element');
        $plainHash = $this->serverHash($plain);

        self::ensureKernelShutdown();
        $client = $this->movableClient();
        $unmoved = $this->render($client, []);
        self::assertCount(2, (new Crawler($unmoved))->filter('[data-offset-mirror]'));
        self::assertSame($plainHash, $this->serverHash($unmoved), 'zero offsets add no O: line');
        self::assertSame($plainHash, $this->browserHash($unmoved));

        $moved = $this->render($client, [self::BADGE => ['x' => 0, 'y' => 5]]);
        self::assertNotSame($plainHash, $this->serverHash($moved));
    }

    public function testPhpAndJsFormatOffsetNumbersIdentically(): void
    {
        $numbers = [0.0, -0.0, 1.0, -1.0, 12.5, -3.33, 40.0, 0.1, 0.05, 0.005, -0.005, 99.995, -33.335, 1234.56, 10000.0, 1e-9, 177.165354, -1100.0];

        $js = $this->runHarness([], $numbers)['numbers'];

        self::assertSame(array_map(AbstractVariantFiller::hashNumber(...), $numbers), $js);
        self::assertSame(['0', '0', '1', '-1', '12.5', '-3.33', '40'], array_slice($js, 0, 7));
    }

    /**
     * @param array<string, array<string, mixed>> $offsetValues
     */
    private function render(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $offsetValues): string
    {
        $data = ['variant' => $this->variant()];
        if ($offsetValues !== []) {
            $data['offsetValues'] = $offsetValues;
        }

        return (string) $this->createLiveComponent(name: 'Template:VariantFiller', data: $data, client: $client)->render();
    }

    /**
     * Fixture variant 1 with "Uživatel může posunout" on the badge AND on
     * the headline (a container member — inert): ↕ up to 20 px up /
     * unlimited down, ↔ unlimited (DAMA rolls it back).
     */
    private function movableClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $variant = $this->variant();
        $canvas = json_decode($variant->canvas, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($canvas);
        self::assertIsArray($canvas['objects']);
        foreach ([self::BADGE, self::HEADLINE] as $inputId) {
            $index = array_search($inputId, TextInputObjectBinder::positionalBinding($canvas, $variant->inputs), true);
            self::assertIsInt($index);
            self::assertIsArray($canvas['objects'][$index]);
            $canvas['objects'][$index]['userMove'] = ['vertical' => true, 'horizontal' => true, 'maxUp' => 20, 'maxDown' => null, 'maxLeft' => null, 'maxRight' => null];
        }
        $variant->editCanvas(json_encode($canvas, JSON_THROW_ON_ERROR), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $client;
    }

    private function serverHash(string $rendered): string
    {
        $source = (new Crawler($rendered))->filter('#variant-backdrop-source, [data-variant-fill-overlay-target="previewSource"]');
        self::assertCount(1, $source);

        return (string) $source->attr('data-state-hash');
    }

    private function browserHash(string $rendered): string
    {
        $mirrors = [];
        foreach ((new Crawler($rendered))->filter('[data-text-mirror], [data-hide-mirror], [data-font-mirror], [data-shape-color-mirror], [data-row-count-mirror], [data-offset-mirror]') as $node) {
            \assert($node instanceof \DOMElement);
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                $attributes[$attribute->name] = $attribute->value;
            }
            $mirrors[] = [
                'attributes' => $attributes,
                'value' => self::browserValue(new Crawler($node)),
                'checked' => $node->hasAttribute('checked'),
            ];
        }

        return $this->runHarness($mirrors, [])['hash'];
    }

    /**
     * What `element.value` returns in a browser (see FillMirrorStateHashTest).
     */
    private static function browserValue(Crawler $mirror): string
    {
        if (strtolower($mirror->nodeName()) === 'textarea') {
            return $mirror->text('', false);
        }

        $value = (string) $mirror->attr('value');
        $type = strtolower((string) ($mirror->attr('type') ?? 'text'));

        return $type === 'hidden' ? $value : str_replace(["\r", "\n"], '', $value);
    }

    /**
     * @param list<array{attributes: array<string, string>, value: string, checked: bool}> $mirrors
     * @param list<float> $numbers
     *
     * @return array{hash: string, numbers: list<string>}
     */
    private function runHarness(array $mirrors, array $numbers): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $projectDir = dirname(__DIR__, 3);
        $process = new Process([$node, $projectDir . '/tests/Fixtures/fill-state-hash/harness.mjs']);
        $process->setInput(json_encode([
            'module' => $projectDir . '/assets/controllers/fill_state_hash.js',
            'mirrors' => $mirrors,
            'numbers' => $numbers,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $process->mustRun();

        /** @var array{hash: string, numbers: list<string>} $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }

    private function variant(): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));
    }
}
