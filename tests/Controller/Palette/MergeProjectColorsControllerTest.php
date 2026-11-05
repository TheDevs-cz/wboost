<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Palette;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * Paleta barev F7 P4: the "Stejný HEX" flag + "Sloučit" dialog (default
 * survivor, per-choice preview, permissions, CSRF, refusals) and the
 * template usage chip / delete-dialog sentence.
 *
 * Fixture: Project 1 (owner USER_1, read-only share for SHARED_USER) has red
 * C8102E (primary) + blue 004E7C (secondary, no print data), both added
 * colours of Manual 1. The duplicate tests add an UNUSED "004e7c" with print
 * data after them.
 *
 * @covers \WBoost\Web\Controller\Palette\MergeProjectColorsController
 * @covers \WBoost\Web\Controller\Palette\ProjectPaletteController
 * @covers \WBoost\Web\Services\Palette\PaletteColorMerge
 * @covers \WBoost\Web\Query\GetPaletteTemplateUsage
 */
final class MergeProjectColorsControllerTest extends WebTestCase
{
    private const string PAGE_URL = '/project/' . TestDataFixture::PROJECT_1_ID . '/paleta';
    private const string MERGE_URL = '/project/' . TestDataFixture::PROJECT_1_ID . '/paleta/sloucit';
    private const string DUPLICATE_ID = '0f7c0000-0000-7000-8000-0000000000d1';
    private const string DIALOG = '#pal-merge-004e7c';

    public function testEditorSeesTheFlagAndADialogWithAPreviewPerChoice(): void
    {
        $browser = $this->editor();
        $this->insertDuplicate();

        $crawler = $browser->request('GET', self::PAGE_URL);
        self::assertResponseIsSuccessful();

        // Both same-HEX cards are flagged; red is not.
        foreach ([TestDataFixture::PROJECT_1_COLOR_BLUE_ID, self::DUPLICATE_ID] as $id) {
            $flag = $crawler->filter('#pal-' . $id . ' .pal-dup');
            self::assertCount(1, $flag, $id);
            self::assertStringContainsString('Stejný HEX má i další barva', $flag->text());
            self::assertSame(self::DIALOG, $flag->filter('button')->attr('data-bs-target'));
        }
        self::assertCount(0, $crawler->filter('#pal-' . TestDataFixture::PROJECT_1_COLOR_RED_ID . ' .pal-dup'));

        $dialog = $crawler->filter(self::DIALOG);
        self::assertCount(1, $dialog);
        self::assertSame([TestDataFixture::PROJECT_1_COLOR_BLUE_ID, self::DUPLICATE_ID], $dialog->filter('input[name="colors[]"]')->each(static fn ($input): string => (string) $input->attr('value')));
        // Default survivor = the colour more manuals use (blue: Manual 1; the duplicate: none).
        self::assertSame(TestDataFixture::PROJECT_1_COLOR_BLUE_ID, $dialog->filter('input[name="survivor"][checked]')->attr('value'));

        // Keeping blue: it gains the duplicate's print data — Manual 1 sees that.
        $keepBlue = $dialog->filter('[data-merge-preview="' . TestDataFixture::PROJECT_1_COLOR_BLUE_ID . '"]');
        self::assertNull($keepBlue->attr('hidden'));
        self::assertStringContainsString('doplní se CMYK 100/40/0/10, Pantone 301 C', $keepBlue->text());
        self::assertStringContainsString('Manual 1 — CMYK – → 100/40/0/10; Pantone – → 301 C', $keepBlue->text());
        // Template print: the HEX had no CMYK among linked colours, now it has.
        self::assertStringContainsString('tiskové PDF ale převede #004E7C na CMYK 100/40/0/10', $keepBlue->text());

        // Keeping the duplicate: Manual 1 changes print data, role and the HEX spelling.
        $keepDuplicate = $dialog->filter('[data-merge-preview="' . self::DUPLICATE_ID . '"]');
        self::assertNotNull($keepDuplicate->attr('hidden'));
        self::assertStringContainsString('role Sekundární → Bez role', $keepDuplicate->text());
    }

    public function testReadOnlyMemberSeesNoFlagAndNoDialog(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::SHARED_USER_EMAIL);
        $this->insertDuplicate();

        $crawler = $browser->request('GET', self::PAGE_URL);
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.pal-card'));
        self::assertCount(0, $crawler->filter('.pal-dup, [id^="pal-merge-"]'));
    }

    public function testMergeKeepsThePickedColourAndRedirectsToIt(): void
    {
        $browser = $this->editor();
        $this->insertDuplicate();
        $token = $this->token($browser);

        $browser->request('POST', self::MERGE_URL, [
            '_token' => $token,
            'survivor' => TestDataFixture::PROJECT_1_COLOR_BLUE_ID,
            'colors' => [TestDataFixture::PROJECT_1_COLOR_BLUE_ID, self::DUPLICATE_ID],
        ]);

        self::assertResponseRedirects(self::PAGE_URL . '#pal-' . TestDataFixture::PROJECT_1_COLOR_BLUE_ID);
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM project_color WHERE id = ?', [self::DUPLICATE_ID]));
        self::assertSame('301 C', $this->connection()->fetchOne('SELECT pantone FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Barvy #004E7C sloučeny', $crawler->filter('body')->text());
        self::assertCount(0, $crawler->filter('.pal-dup'));
    }

    public function testNonDuplicatesAreRefused(): void
    {
        $browser = $this->editor();
        $token = $this->token($browser);

        $browser->request('POST', self::MERGE_URL, [
            '_token' => $token,
            'survivor' => TestDataFixture::PROJECT_1_COLOR_BLUE_ID,
            'colors' => [TestDataFixture::PROJECT_1_COLOR_RED_ID],
        ]);

        self::assertResponseRedirects(self::PAGE_URL);
        self::assertSame('1', self::scalar($this->connection()->fetchOne('SELECT COUNT(*) FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_RED_ID])));
        self::assertStringContainsString('Sloučit jde jen barvy se stejným HEX', $browser->followRedirect()->filter('body')->text());
    }

    public function testCsrfViewMembersOutsidersAndForeignColoursAreRejected(): void
    {
        $browser = $this->editor();
        $this->insertDuplicate();
        $data = ['survivor' => TestDataFixture::PROJECT_1_COLOR_BLUE_ID, 'colors' => [self::DUPLICATE_ID]];

        $browser->request('POST', self::MERGE_URL, [...$data, '_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);

        // A survivor of another project, posted to this project's endpoint.
        $foreign = '0f7c0000-0000-7000-8000-0000000000d2';
        $this->connection()->executeStatement(
            "INSERT INTO project_color (id, project_id, hex, hex_key, position, created_at) VALUES (?, ?, '004E7C', '#004e7c', 0, NOW())",
            [$foreign, TestDataFixture::PROJECT_2_ID],
        );
        $browser->request('POST', self::MERGE_URL, ['_token' => $this->token($browser), 'survivor' => $foreign, 'colors' => [TestDataFixture::PROJECT_1_COLOR_BLUE_ID]]);
        self::assertResponseStatusCodeSame(404);

        foreach ([TestDataFixture::SHARED_USER_EMAIL, TestDataFixture::USER_2_EMAIL] as $email) {
            self::ensureKernelShutdown();
            $browser = self::createClient();
            TestingLogin::logInAsUser($browser, $email);
            $browser->request('POST', self::MERGE_URL, [...$data, '_token' => 'whatever']);
            self::assertResponseStatusCodeSame(403, $email);
        }

        self::assertSame('1', self::scalar($this->connection()->fetchOne('SELECT COUNT(*) FROM project_color WHERE id = ?', [self::DUPLICATE_ID])));
    }

    public function testCardsCountTheTemplatesUsingTheHexAndTheDeleteDialogNamesThem(): void
    {
        $browser = $this->editor();

        // Clean slate, then blue in two templates (one of them a group of
        // three variants), red offered by one recolourable shape.
        $this->connection()->executeStatement(
            "UPDATE template_variant SET canvas = '{\"objects\": []}', inputs = '[]' WHERE template_id IN (SELECT id FROM template WHERE project_id = ?)",
            [TestDataFixture::PROJECT_1_ID],
        );
        $this->connection()->executeStatement(
            "UPDATE template_variant SET canvas = '{\"objects\": [{\"type\": \"textbox\", \"fill\": \"#004E7C\"}]}' WHERE template_id IN (?, ?)",
            [TestDataFixture::CUSTOM_TEMPLATE_1_ID, TestDataFixture::GROUPED_TEMPLATE_ID],
        );
        $this->connection()->executeStatement(
            "UPDATE template_variant SET canvas = '{\"objects\": [{\"type\": \"rect\", \"fill\": \"#000\", \"colorChoice\": \"fill\", \"allowedColors\": [\"#c8102e\"]}]}' WHERE template_id = ?",
            [TestDataFixture::BLANK_TEMPLATE_ID],
        );

        $crawler = $browser->request('GET', self::PAGE_URL);
        self::assertResponseIsSuccessful();

        $blue = $crawler->filter('#pal-' . TestDataFixture::PROJECT_1_COLOR_BLUE_ID . ' .pal-tpl');
        self::assertSame('2 šablony', trim($blue->text()));
        self::assertStringContainsString('Custom Template 1, Group Campaign', (string) $blue->attr('title'));
        self::assertSame('1 šablona', trim($crawler->filter('#pal-' . TestDataFixture::PROJECT_1_COLOR_RED_ID . ' .pal-tpl')->text()));

        $delete = $crawler->filter('#pal-del-' . TestDataFixture::PROJECT_1_COLOR_RED_ID);
        self::assertStringContainsString('HEX #C8102E používá 1 šablona (Blank Canvas) — šablony si ho ponechají.', $delete->text());

        // No template uses the HEX: no chip, the dialog says so.
        $this->connection()->executeStatement("UPDATE template_variant SET canvas = '{\"objects\": []}' WHERE template_id = ?", [TestDataFixture::BLANK_TEMPLATE_ID]);
        $crawler = $browser->request('GET', self::PAGE_URL);
        self::assertCount(0, $crawler->filter('#pal-' . TestDataFixture::PROJECT_1_COLOR_RED_ID . ' .pal-tpl'));
        self::assertStringContainsString('Žádná šablona tento HEX nepoužívá.', $crawler->filter('#pal-del-' . TestDataFixture::PROJECT_1_COLOR_RED_ID)->text());
    }

    private function insertDuplicate(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO project_color (id, project_id, hex, hex_key, role, cmyk, pantone, position, created_at) VALUES (?, ?, '004e7c', '#004e7c', NULL, '[\"100\", \"40\", \"0\", \"10\"]', '301 C', 2, NOW())",
            [self::DUPLICATE_ID, TestDataFixture::PROJECT_1_ID],
        );
    }

    private function editor(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        return $browser;
    }

    private function token(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', self::PAGE_URL);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-project-palette-csrf-value]')->attr('data-project-palette-csrf-value');
        self::assertIsString($token);

        return $token;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private static function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
