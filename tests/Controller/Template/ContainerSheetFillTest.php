<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\EditorTextInput;

/**
 * One pencil per container on the three fill pages: a top-level container
 * with ≥ 2 editable fields gets ONE box + pencil (`openSheet`), its members
 * lose their own pencils / eyes, the sheet head lists the fields, and a
 * container with a single editable field keeps the per-field pencil.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\FillContainerSheets
 * @covers \WBoost\Web\Twig\Components\AbstractVariantFiller
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentFillPage
 */
final class ContainerSheetFillTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string BLOCK = 'cccccccc-0000-4000-8000-0000000000c1';
    private const string SECOND_ID = 'cccccccc-0000-4000-8000-0000000000e2';

    public function testSinglePageGivesTheContainerOnePencilAndItsMembersNone(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->setContainer(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, [
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_LOCKED_ID,
        ]);

        $html = (string) $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        )->render();
        $crawler = new Crawler($html);

        $box = $crawler->filter(sprintf('.fill-box--container[data-sheet-box="%s"]', self::BLOCK));
        self::assertCount(1, $box);
        self::assertCount(1, $box->filter(sprintf('[data-action="variant-fill-overlay#openSheet"][data-variant-fill-overlay-sheet-param="%s"]', self::BLOCK)));
        self::assertCount(1, $box->filter(sprintf('[data-hide-toggle="%s"]', self::BLOCK)), 'the hidable container\'s eye sits on its box');
        self::assertSame(
            [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_LOCKED_ID],
            explode(' ', (string) $box->attr('data-sheet-members')),
            'the outline spans every member, the locked one too',
        );

        foreach ([TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID] as $memberId) {
            $member = $crawler->filter(sprintf('.fill-overlay .fill-box[data-inputid="%s"]', $memberId));
            self::assertCount(1, $member);
            self::assertSame(self::BLOCK, $member->attr('data-sheet-member'));
            self::assertSame($memberId, $member->attr('data-variant-fill-overlay-focus-param'));
            self::assertCount(0, $member->filter('.fill-box__tools'), 'no per-member pencil / eye on the preview');
            self::assertCount(1, $crawler->filter(sprintf('.fill-popovers > .fill-popover[data-inputid="%s"]', $memberId)), 'the field\'s one card the sheet shows');
        }

        // Not a member: the badge keeps its own pencil.
        $badge = $crawler->filter(sprintf('.fill-overlay .fill-box[data-inputid="%s"]', TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_BADGE_ID));
        self::assertCount(1, $badge->filter('[data-action="variant-fill-overlay#openPopover"]'));
        self::assertNull($badge->attr('data-sheet-member'));

        $head = $crawler->filter(sprintf('.fill-popovers > .fill-sheet__head[data-fill-sheet="%s"]', self::BLOCK));
        self::assertCount(1, $head);
        self::assertStringContainsString('Blok', $head->text());
        self::assertSame(
            [
                ['kind' => 'field', 'id' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID, 'depth' => 0],
                ['kind' => 'field', 'id' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_TAGLINE_ID, 'depth' => 0],
            ],
            json_decode((string) $head->attr('data-sheet-entries'), true),
        );
    }

    public function testAContainerWithOneEditableFieldKeepsPerFieldPencils(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $this->setContainer(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID, [
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_LOCKED_ID,
        ]);

        $crawler = new Crawler((string) $this->createLiveComponent(
            name: 'Template:VariantFiller',
            data: ['variant' => $this->variant(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID)],
            client: $client,
        )->render());

        self::assertCount(0, $crawler->filter('.fill-box--container'));
        self::assertCount(0, $crawler->filter('.fill-sheet__head'));
        self::assertCount(1, $crawler->filter(sprintf('.fill-overlay .fill-box[data-inputid="%s"] [data-action="variant-fill-overlay#openPopover"]', TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID)));
    }

    public function testGroupPageDrawsTheContainerBoxOnEveryDimensionAndOneSheet(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        foreach ([TestDataFixture::GROUPED_PRESET_VARIANT_ID, TestDataFixture::GROUPED_FREEFORM_VARIANT_ID] as $variantId) {
            $this->addSecondTextAndContainer($variantId);
        }

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill');
        self::assertResponseIsSuccessful();

        self::assertCount(2, $crawler->filter(sprintf('.fill-surface .fill-box--container[data-sheet-box="%s"]', self::BLOCK)), 'one per dimension');
        self::assertCount(1, $crawler->filter(sprintf('.fill-popovers > .fill-sheet__head[data-fill-sheet="%s"]', self::BLOCK)), 'one sheet');
        self::assertCount(2, $crawler->filter(sprintf('.fill-box[data-inputid="%s"][data-sheet-member="%s"]', TestDataFixture::GROUP_SHARED_INPUT_ID, self::BLOCK)));
        self::assertCount(0, $crawler->filter(sprintf('.fill-box[data-inputid="%s"] [data-action="variant-fill-overlay#openPopover"]', TestDataFixture::GROUP_SHARED_INPUT_ID)));
    }

    public function testDocumentPageKeysTheSheetPerPage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $first = TemplateDocumentTestData::richA4Template($em, 'Strana A');
        $second = TemplateDocumentTestData::richA4Template($em, 'Strana B');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$first['template'], $second['template']]);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill');
        self::assertResponseIsSuccessful();

        $firstKey = $this->key($first['template'], 'container-1');
        $secondKey = $this->key($second['template'], 'container-1');
        self::assertCount(1, $crawler->filter(sprintf('.fill-popovers > .fill-sheet__head[data-fill-sheet="%s"]', $firstKey)));
        self::assertCount(1, $crawler->filter(sprintf('.fill-popovers > .fill-sheet__head[data-fill-sheet="%s"]', $secondKey)));
        self::assertCount(0, $crawler->filter('[data-fill-sheet="container-1"], [data-sheet-box="container-1"]'), 'No real container id leaks.');

        $memberKey = $this->key($first['template'], TemplateDocumentTestData::SHARED_INPUT_ID);
        self::assertSame($firstKey, $crawler->filter(sprintf('.fill-box[data-inputid="%s"]', $memberKey))->attr('data-sheet-member'));
        /** @var list<array{id: string}> $entries */
        $entries = json_decode((string) $crawler->filter(sprintf('[data-fill-sheet="%s"]', $firstKey))->attr('data-sheet-entries'), true);
        self::assertSame($memberKey, $entries[0]['id']);
    }

    /**
     * @param list<string> $memberIds
     */
    private function setContainer(string $variantId, array $memberIds): void
    {
        $variant = $this->variant($variantId);
        $canvas = json_decode($variant->canvas, true);
        $canvas = is_array($canvas) ? $canvas : [];
        $canvas['containers'] = [[
            'id' => self::BLOCK, 'maxHeight' => 900, 'name' => 'Blok', 'hidable' => true,
            'memberInputIds' => $memberIds, 'memberContainerIds' => [],
        ]];
        $variant->editCanvas((string) json_encode($canvas), $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /**
     * Group variants carry ONE shared text: append a second textbox (+ its
     * positional input) and group both into a container.
     */
    private function addSecondTextAndContainer(string $variantId): void
    {
        $variant = $this->variant($variantId);
        $canvas = json_decode($variant->canvas, true);
        $canvas = is_array($canvas) ? $canvas : [];
        $objects = is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];
        $objects[] = [
            'type' => 'Textbox', 'inputId' => self::SECOND_ID, 'text' => 'Podtitul',
            'left' => 60, 'top' => 600, 'width' => 600, 'height' => 50, 'fontSize' => 40,
            'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
        ];
        $canvas['objects'] = $objects;
        $canvas['containers'] = [[
            'id' => self::BLOCK, 'maxHeight' => 900,
            'memberInputIds' => [TestDataFixture::GROUP_SHARED_INPUT_ID, self::SECOND_ID], 'memberContainerIds' => [],
        ]];
        $inputs = array_values($variant->inputs);
        $inputs[] = new EditorTextInput(self::SECOND_ID, 'subtitle', null, false, false, null, false);
        $variant->editCanvas((string) json_encode($canvas), $inputs, $variant->previewImagePath, $variant->imageInputs);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function key(UuidInterface $templateId, string $id): string
    {
        return Uuid::uuid5($templateId->toString(), $id)->toString();
    }

    private function variant(string $variantId): TemplateVariant
    {
        return self::getContainer()->get(TemplateVariantRepository::class)->get(Uuid::fromString($variantId));
    }
}
