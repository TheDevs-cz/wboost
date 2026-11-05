<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * @covers \WBoost\Web\Controller\Manual\ManualColorsController
 */
final class ManualColorsControllerTest extends WebTestCase
{
    /**
     * A blank HEX input maps back as NULL (Symfony has no view transformer for it),
     * which used to blow up on ManualColorFormData::$color instead of failing validation.
     */
    public function testCustomColorWithoutHexFailsValidationInsteadOfCrashing(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/colors');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form();

        // A row the user started filling (Pantone) but left the HEX empty.
        $values = self::withExtraCustomColor($form->getPhpValues(), ['pantone' => '123 C']);

        $browser->request('POST', $form->getUri(), $values);

        // 422 = AbstractController::render() saw an invalid submitted form (not a crash).
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Vyplňte prosím HEX kód barvy.', (string) $browser->getResponse()->getContent());
    }

    /**
     * The drag&drop controller stamps an "order" onto every row, so a row the user
     * added but never filled in is not empty to Symfony — it must still be dropped.
     */
    public function testBlankCustomColorRowIsIgnored(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/colors');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form();

        $values = self::withExtraCustomColor($form->getPhpValues(), []);

        $browser->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();

        $crawler = $browser->followRedirect();

        self::assertCount(
            2,
            $crawler->filter('input[name^="manual_colors_form[customColors]"][name$="[color]"]'),
            'The blank row must not have been persisted.',
        );
    }

    /**
     * RAL is stored per colour and shown in the manual only once filled.
     */
    public function testRalIsSavedAndRenderedInTheManual(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/colors');
        self::assertResponseIsSuccessful();

        // Nothing filled yet — the manual prints no RAL line.
        $browser->request('GET', '/nahled-manualu/project-1/manual-1');
        self::assertStringNotContainsString('RAL:', (string) $browser->getResponse()->getContent());

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/colors');
        $form = $crawler->selectButton('Uložit')->form();
        $values = $form->getPhpValues();

        /** @var array{customColors: array<int, array<string, string>>} $formValues */
        $formValues = $values['manual_colors_form'];
        $formValues['customColors'][0]['ral'] = 'RAL 3020';
        $values['manual_colors_form'] = $formValues;

        $browser->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        // Round-trips into the form…
        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/colors');
        self::assertSame(
            'RAL 3020',
            $crawler->filter('input[name="manual_colors_form[customColors][0][ral]"]')->attr('value'),
        );

        // …and reaches the manual, next to the other codes.
        $browser->request('GET', '/nahled-manualu/project-1/manual-1');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('RAL:', $content);
        self::assertStringContainsString('RAL 3020', $content);
    }

    /**
     * F7 R2: the old colours page still edits through — its type select now
     * sets the PROJECT palette colour's role (D2), the manual's colour pages
     * follow, and the JSONB mirror R1 code reads is rewritten.
     */
    public function testTypeChangeEditsThePaletteRoleAndTheMirror(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/manual/' . TestDataFixture::MANUAL_1_ID . '/colors');
        $form = $crawler->selectButton('Uložit')->form();
        $values = $form->getPhpValues();

        /** @var array{customColors: array<int, array<string, string>>} $formValues */
        $formValues = $values['manual_colors_form'];
        self::assertSame('primary', $formValues['customColors'][0]['type']);
        $formValues['customColors'][0]['type'] = 'secondary';
        $values['manual_colors_form'] = $formValues;

        $browser->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        $connection = self::getContainer()->get(Connection::class);
        self::assertSame('secondary', $connection->fetchOne('SELECT role FROM project_color WHERE id = ?', [TestDataFixture::PROJECT_1_COLOR_RED_ID]));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM manual_palette_color WHERE project_color_id = ?', [TestDataFixture::PROJECT_1_COLOR_RED_ID]), 'the manual keeps its palette colour');

        $mirror = $connection->fetchOne('SELECT custom_colors FROM manual WHERE id = ?', [TestDataFixture::MANUAL_1_ID]);
        self::assertIsString($mirror);
        self::assertSame(['secondary', 'secondary'], array_column((array) json_decode($mirror, true), 'type'));

        // Both colours are secondary now — the primary page is gone.
        $browser->request('GET', '/nahled-manualu/project-1/manual-1');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('Primární barevná paleta', $content);
        self::assertStringContainsString('Sekundární barevná paleta', $content);
        self::assertSame(2, substr_count($content, 'class="manual-preview-color'));
    }

    /**
     * Appends one custom colour row, ordered like the drag&drop controller stamps it.
     *
     * @param mixed[] $values
     * @param array<string, string> $row
     *
     * @return mixed[]
     */
    private static function withExtraCustomColor(array $values, array $row): array
    {
        /** @var array{customColors: array<int, array<string, string>>} $formValues */
        $formValues = $values['manual_colors_form'];

        $formValues['customColors'][] = $row + [
            'order' => (string) count($formValues['customColors']),
            'color' => '',
            'pantone' => '',
            'ral' => '',
            'type' => '',
            'c' => '',
            'm' => '',
            'y' => '',
            'k' => '',
        ];

        $values['manual_colors_form'] = $formValues;

        return $values;
    }
}
