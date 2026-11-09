<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Manual;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Exceptions\IntroContentPageCannotBeDeleted;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Message\Manual\AddManualContentPage;
use WBoost\Web\Message\Manual\DeleteManualContentPage;
use WBoost\Web\Message\Manual\RenameManualContentPage;
use WBoost\Web\Message\Manual\SaveManualContentPage;
use WBoost\Web\Message\Manual\SortManualPages;
use WBoost\Web\Query\GetManualPageList;
use WBoost\Web\Services\Manual\ManualPagePositions;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\HeadingElement;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\ManualPageListItem;
use WBoost\Web\Value\MockupPageLayout;

/**
 * Content pages P2: custom pages (add / rename / delete) and the ONE
 * position space they share with the mockup pages.
 */
final class ManualContentPagesTest extends KernelTestCase
{
    private const string MOCKUP_A = '00000000-0000-0000-0000-0000000007a1';
    private const string MOCKUP_B = '00000000-0000-0000-0000-0000000007a2';
    private const string FOREIGN_MOCKUP = '00000000-0000-0000-0000-0000000007a3';
    private const string INTRO = '00000000-0000-0000-0000-0000000007c0';
    private const string FOREIGN_CONTENT = '00000000-0000-0000-0000-0000000007c9';

    public function testANewCustomPageGoesToTheEndOfTheSharedList(): void
    {
        $this->insertMockup(self::MOCKUP_A, TestDataFixture::MANUAL_1_ID, 0);
        $this->insertMockup(self::MOCKUP_B, TestDataFixture::MANUAL_1_ID, 1);
        $this->insertIntro();

        $pageId = Uuid::uuid7();
        $this->bus()->dispatch(new AddManualContentPage($this->manualId(), $pageId, '  Tón komunikace ', MockupPageLayout::Layout8));

        $row = $this->connection()->fetchAssociative('SELECT role, name, layout, position, segments::text AS segments FROM manual_content_page WHERE id = ?', [$pageId->toString()]);
        self::assertIsArray($row);
        self::assertSame('custom', $row['role']);
        self::assertSame('Tón komunikace', $row['name']);
        self::assertSame('layout-8', $row['layout']);
        self::assertSame(2, $row['position']);
        // One empty segment per slot of the layout.
        self::assertCount(count(MockupPageLayout::Layout8->slots()), (array) json_decode(is_string($row['segments']) ? $row['segments'] : '', true));

        // …and a mockup page added after it goes after it.
        self::assertSame(3, $this->positions()->next($this->manualId()));

        self::assertSame(
            ['mockup:' . self::MOCKUP_A, 'mockup:' . self::MOCKUP_B, 'content:' . $pageId->toString()],
            $this->list(),
        );
    }

    public function testTheFirstPageOfAManualStartsAtZero(): void
    {
        $this->insertIntro();

        self::assertSame(0, $this->positions()->next($this->manualId()));
    }

    public function testAPageNeedsAName(): void
    {
        try {
            $this->bus()->dispatch(new AddManualContentPage($this->manualId(), Uuid::uuid7(), "  \n ", MockupPageLayout::Layout7));
            self::fail('A blank name must be refused.');
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf(InvalidManualContent::class, $failed->getPrevious());
        }
    }

    public function testRename(): void
    {
        $pageId = $this->addCustom('Původní');

        $this->bus()->dispatch(new RenameManualContentPage($pageId, ' Nový název '));

        self::assertSame('Nový název', $this->connection()->fetchOne('SELECT name FROM manual_content_page WHERE id = ?', [$pageId->toString()]));

        $this->expectException(HandlerFailedException::class);
        $this->bus()->dispatch(new RenameManualContentPage($pageId, ''));
    }

    public function testDeleteRemovesACustomPage(): void
    {
        $pageId = $this->addCustom('Smazat mě');

        $this->bus()->dispatch(new DeleteManualContentPage($pageId));

        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM manual_content_page WHERE id = ?', [$pageId->toString()]));
    }

    public function testTheIntroCannotBeDeleted(): void
    {
        $this->insertIntro();

        try {
            $this->bus()->dispatch(new DeleteManualContentPage(Uuid::fromString(self::INTRO)));
            self::fail('The intro must not be deletable.');
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf(IntroContentPageCannotBeDeleted::class, $failed->getPrevious());
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT 1 FROM manual_content_page WHERE id = ?', [self::INTRO]));
    }

    public function testSortWritesOneSharedOrderAcrossBothTables(): void
    {
        $this->insertMockup(self::MOCKUP_A, TestDataFixture::MANUAL_1_ID, 0);
        $this->insertMockup(self::MOCKUP_B, TestDataFixture::MANUAL_1_ID, 1);
        $this->insertMockup(self::FOREIGN_MOCKUP, TestDataFixture::MANUAL_2_ID, 7);
        $this->insertIntro();
        $this->insertContent(self::FOREIGN_CONTENT, TestDataFixture::MANUAL_2_ID, 'custom', 5);
        $first = $this->addCustom('První obsahová');
        $second = $this->addCustom('Druhá obsahová');

        $this->bus()->dispatch(new SortManualPages($this->manualId(), [
            'content:' . $second->toString(),
            'mockup:' . self::MOCKUP_B,
            'mockup:' . self::FOREIGN_MOCKUP,     // another manual's — skipped
            'content:' . self::FOREIGN_CONTENT,   // another manual's — skipped
            'content:' . self::INTRO,             // the intro is pinned — skipped
            'mockup:' . self::INTRO,              // wrong kind for the id — unknown
            'content:not-a-uuid',
            self::MOCKUP_A,                       // untyped — skipped
            42,                                   // not even a string
            'mockup:' . self::MOCKUP_A,
            'content:' . $first->toString(),
        ]));

        self::assertSame(0, $this->contentPosition($second));
        self::assertSame(1, $this->mockupPosition(self::MOCKUP_B));
        self::assertSame(9, $this->mockupPosition(self::MOCKUP_A));
        self::assertSame(10, $this->contentPosition($first));
        self::assertSame(7, $this->mockupPosition(self::FOREIGN_MOCKUP));
        self::assertSame(5, $this->contentPosition(Uuid::fromString(self::FOREIGN_CONTENT)));
        self::assertSame(0, $this->contentPosition(Uuid::fromString(self::INTRO)));

        self::assertSame([
            'content:' . $second->toString(),
            'mockup:' . self::MOCKUP_B,
            'mockup:' . self::MOCKUP_A,
            'content:' . $first->toString(),
        ], $this->list());
    }

    public function testACustomPageCreatedByItsFirstSaveAlsoGoesToTheEnd(): void
    {
        $this->insertMockup(self::MOCKUP_A, TestDataFixture::MANUAL_1_ID, 4);
        $pageId = Uuid::uuid7();

        $this->bus()->dispatch(new SaveManualContentPage(
            $this->manualId(),
            $pageId,
            ManualContentPageRole::Custom,
            'Uložená',
            MockupPageLayout::Layout7,
            [new ContentSegment(elements: [new HeadingElement('h1', 1, 'Ahoj')])],
        ));

        self::assertSame(5, $this->contentPosition($pageId));
    }

    public function testTheRenumberMigrationKeepsTheRenderedOrder(): void
    {
        // A tie (prod had two pages at 6) and a list not starting at 0.
        $this->insertMockup(self::MOCKUP_A, TestDataFixture::MANUAL_1_ID, 6, '2025-03-26 18:25:24');
        $this->insertMockup(self::MOCKUP_B, TestDataFixture::MANUAL_1_ID, 6, '2025-03-26 18:15:03');
        $this->insertMockup('00000000-0000-0000-0000-0000000007a4', TestDataFixture::MANUAL_1_ID, 2);
        $this->insertMockup(self::FOREIGN_MOCKUP, TestDataFixture::MANUAL_2_ID, 3);

        $before = $this->list();

        $this->connection()->executeStatement(ManualPagePositions::RENUMBER_MOCKUP_PAGES_SQL);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame($before, $this->list());
        self::assertSame(['mockup:00000000-0000-0000-0000-0000000007a4', 'mockup:' . self::MOCKUP_B, 'mockup:' . self::MOCKUP_A], $before);
        self::assertSame(0, $this->mockupPosition('00000000-0000-0000-0000-0000000007a4'));
        self::assertSame(1, $this->mockupPosition(self::MOCKUP_B));
        self::assertSame(2, $this->mockupPosition(self::MOCKUP_A));
        self::assertSame(0, $this->mockupPosition(self::FOREIGN_MOCKUP));

        // Idempotent.
        self::assertSame(0, $this->connection()->executeStatement(ManualPagePositions::RENUMBER_MOCKUP_PAGES_SQL));
    }

    public function testTypedIds(): void
    {
        $id = '00000000-0000-0000-0000-0000000007a1';

        self::assertSame(ManualPageListItem::MOCKUP, ManualPageListItem::parseTypedId('mockup:' . $id)[0] ?? null);
        $content = ManualPageListItem::parseTypedId('content:' . $id);
        self::assertNotNull($content);
        self::assertSame(ManualPageListItem::CONTENT, $content[0]);
        self::assertSame($id, $content[1]->toString());
        self::assertNull(ManualPageListItem::parseTypedId($id));
        self::assertNull(ManualPageListItem::parseTypedId('font:' . $id));
        self::assertNull(ManualPageListItem::parseTypedId('mockup:nope'));
    }

    private function addCustom(string $name): UuidInterface
    {
        $pageId = Uuid::uuid7();
        $this->bus()->dispatch(new AddManualContentPage($this->manualId(), $pageId, $name, MockupPageLayout::Layout7));

        return $pageId;
    }

    /**
     * @return list<string>
     */
    private function list(): array
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return array_map(
            static fn (ManualPageListItem $item): string => $item->typedId(),
            self::getContainer()->get(GetManualPageList::class)->forManual($this->manualId()),
        );
    }

    private function insertMockup(string $id, string $manualId, int $position, string $createdAt = '2026-11-05 10:00:00'): void
    {
        $this->connection()->insert('manual_mockup_page', [
            'id' => $id,
            'created_at' => $createdAt,
            'layout' => MockupPageLayout::cases()[0]->value,
            'name' => 'Vizitky ' . $position,
            'manual_id' => $manualId,
            'images' => '[]',
            'position' => $position,
            'image_downloads' => '[]',
        ]);
    }

    private function insertIntro(): void
    {
        $this->insertContent(self::INTRO, TestDataFixture::MANUAL_1_ID, 'intro', 0);
    }

    private function insertContent(string $id, string $manualId, string $role, int $position): void
    {
        $this->connection()->insert('manual_content_page', [
            'id' => $id,
            'manual_id' => $manualId,
            'role' => $role,
            'name' => 'Stránka',
            'layout' => 'layout-7',
            'segments' => '[{"verticalAlign":"top","elements":[]}]',
            'position' => $position,
            'created_at' => '2026-11-09 12:00:00',
            'updated_at' => '2026-11-09 12:00:00',
        ]);
    }

    private function mockupPosition(string $id): int
    {
        $position = $this->connection()->fetchOne('SELECT position FROM manual_mockup_page WHERE id = ?', [$id]);
        self::assertIsInt($position);

        return $position;
    }

    private function contentPosition(UuidInterface $id): int
    {
        $position = $this->connection()->fetchOne('SELECT position FROM manual_content_page WHERE id = ?', [$id->toString()]);
        self::assertIsInt($position);

        return $position;
    }

    private function positions(): ManualPagePositions
    {
        return self::getContainer()->get(ManualPagePositions::class);
    }

    private function manualId(): UuidInterface
    {
        return Uuid::fromString(TestDataFixture::MANUAL_1_ID);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }
}
