<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Font;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * F8 (2026-11-05): the manual's Fonty tab is gone — a brand manual's fonts
 * (role, colour, cuts, order) are edited in the "Písma v manuálech" box of
 * the project Fonty page; the manual-font routes stay and lead back there.
 */
final class FontsPageManualFontsTest extends WebTestCase
{
    private const string MANUAL_FONT_ID = '00000000-0000-0000-0000-0000000005f1';

    public function testBrandManualFontsAreListedOnTheProjectFontsPage(): void
    {
        $client = $this->clientWithBrandManualFont();

        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/fonts');

        self::assertResponseIsSuccessful();
        $section = '#manual-fonts-' . TestDataFixture::MANUAL_1_ID;
        self::assertSelectorExists($section);
        self::assertSelectorExists($section . ' [data-controller="dragula"][data-dragula-sort-url-value="/sort-manual-fonts/' . TestDataFixture::MANUAL_1_ID . '"]');
        self::assertSelectorExists($section . ' a[href="/manual/' . TestDataFixture::MANUAL_1_ID . '/add-font"]');
        self::assertSelectorExists($section . ' a[href="/edit-manual-font/' . self::MANUAL_FONT_ID . '"]');
        self::assertSelectorTextContains($section . ' .fn-mrow', 'Sekundární');
        self::assertSelectorTextContains($section . ' .fn-mrow', '#1D2A5C');

        $cuts = $crawler->filter($section . ' [data-controller="manual-font-faces-toggle"] input[type="checkbox"]');
        self::assertCount(2, $cuts);
        self::assertCount(1, $crawler->filter($section . ' input[value="Rubik Bold"][checked]'));
        self::assertCount(0, $crawler->filter($section . ' input[value="Rubik Regular"][checked]'));
    }

    public function testLogoManualsHaveNoFontsSection(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/fonts');

        self::assertResponseIsSuccessful();
        // Manual 1 is a LOGO manual in the fixtures.
        self::assertSelectorNotExists('#manual-fonts-' . TestDataFixture::MANUAL_1_ID);
    }

    public function testTheManualTabsNoLongerCarryFontyAndTheOldRouteRedirects(): void
    {
        $client = $this->clientWithBrandManualFont();

        $client->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/logos');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.man-tabs a[href="/manual/' . TestDataFixture::MANUAL_1_ID . '/fonts"]');

        $client->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/fonts');
        self::assertResponseRedirects('/project/' . TestDataFixture::PROJECT_1_ID . '/fonts#manual-fonts-' . TestDataFixture::MANUAL_1_ID);
    }

    /**
     * F11 end-to-end: saving the edit form (same font, new role/colour) keeps
     * the enabled cuts and lands back on the Fonty page.
     */
    public function testEditingAManualFontKeepsItsCuts(): void
    {
        $client = $this->clientWithBrandManualFont();

        $crawler = $client->request('GET', '/edit-manual-font/' . self::MANUAL_FONT_ID);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.page-crumbs a[href="/project/' . TestDataFixture::PROJECT_1_ID . '/fonts#manual-fonts-' . TestDataFixture::MANUAL_1_ID . '"]');

        $form = $crawler->selectButton('Uložit')->form();
        $values = $form->getPhpValues();
        $formName = array_key_first($values);
        self::assertIsString($formName);
        $fields = $values[$formName];
        self::assertIsArray($fields);
        $client->request('POST', '/edit-manual-font/' . self::MANUAL_FONT_ID, [
            $formName => array_merge($fields, ['type' => 'primary', 'color' => 'FF0000']),
        ]);

        self::assertResponseRedirects('/project/' . TestDataFixture::PROJECT_1_ID . '/fonts#manual-fonts-' . TestDataFixture::MANUAL_1_ID);
        /** @var array{type: string, color: string, font_faces: string} $row */
        $row = $this->connection()->fetchAssociative('SELECT type, color, font_faces FROM manual_font WHERE id = ?', [self::MANUAL_FONT_ID]);
        self::assertSame('primary', $row['type']);
        self::assertSame('FF0000', $row['color']);
        self::assertSame(['Rubik Bold'], json_decode($row['font_faces'], true));
    }

    private function clientWithBrandManualFont(): KernelBrowser
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $connection = $this->connection();
        $connection->update('manual', ['type' => 'brand'], ['id' => TestDataFixture::MANUAL_1_ID]);
        $connection->insert('manual_font', [
            'id' => self::MANUAL_FONT_ID,
            'manual_id' => TestDataFixture::MANUAL_1_ID,
            'font_id' => TestDataFixture::FONT_RUBIK_ID,
            'type' => 'secondary',
            'color' => '1D2A5C',
            'position' => 0,
            'created_at' => '2026-11-05 10:00:00',
            'font_faces' => json_encode(['Rubik Bold']),
        ]);

        return $client;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
