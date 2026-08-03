<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

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
