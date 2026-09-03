<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Manual;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Manual\EditManualPageText;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ManualPage;

/**
 * @covers \WBoost\Web\MessageHandler\Manual\EditManualPageTextHandler
 * @covers \WBoost\Web\Twig\Components\ManualPageTextComponent
 * @covers \WBoost\Web\Value\ManualPage
 * @covers \WBoost\Web\Value\ManualPageText
 */
final class ManualPageTextsTest extends WebTestCase
{
    private const string PREVIEW_URL = '/nahled-manualu/project-1/manual-1';

    public function testPagesRenderTheirDefaultTextsAndNoPencilForAReader(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString(ManualPage::PrimaryColors->defaultTitle(), $content);
        self::assertStringContainsString(ManualPage::PrimaryColors->defaultDescription(), $content);

        // The manual is public; the editing chrome is not.
        self::assertCount(0, $crawler->filter('.manual-page-text-edit'));
    }

    public function testAdminGetsAPencilPerPage(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();

        // One per rendered page: the fixture manual has no logo, so what
        // renders is the primary + secondary colour pages.
        self::assertCount(2, $crawler->filter('.manual-page-text-edit'));
        self::assertCount(1, $crawler->filter('#manualPageTextModal-primary_colors'));
        self::assertCount(1, $crawler->filter('#manualPageTextModal-secondary_colors'));
    }

    public function testOverrideReplacesTitleAndDescriptionAndBlankRestoresTheDefault(): void
    {
        $browser = self::createClient();

        $this->dispatchEdit('Naše barvy', "První odstavec.\n\nDruhý odstavec.");

        $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('Naše barvy', $content);
        self::assertStringContainsString('<p>První odstavec.</p>', $content);
        self::assertStringContainsString('<p>Druhý odstavec.</p>', $content);
        self::assertStringNotContainsString(ManualPage::PrimaryColors->defaultDescription(), $content);
        // The OTHER page keeps its own default — overrides are per page.
        self::assertStringContainsString(ManualPage::SecondaryColors->defaultTitle(), $content);

        // Clearing both fields drops the override instead of blanking the page.
        $this->dispatchEdit(null, '   ');

        self::assertSame([], $this->manual()->pageTexts);

        $browser->request('GET', self::PREVIEW_URL);
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString(ManualPage::PrimaryColors->defaultTitle(), $content);
        self::assertStringContainsString(ManualPage::PrimaryColors->defaultDescription(), $content);
    }

    /**
     * An override is user input, so it is escaped — unlike the developer-written
     * default, which is deliberately rendered as HTML.
     */
    public function testOverrideIsEscaped(): void
    {
        $browser = self::createClient();

        $this->dispatchEdit('<script>alert(1)</script>', '<b>tučně</b> a <script>alert(2)</script>');

        $browser->request('GET', self::PREVIEW_URL);
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $content);
        self::assertStringNotContainsString('<script>alert(2)</script>', $content);
        self::assertStringNotContainsString('<b>tučně</b>', $content);
        self::assertStringContainsString('&lt;b&gt;tučně&lt;/b&gt;', $content);
    }

    /**
     * The textarea is pre-filled with the default as plain text, so an admin
     * edits the wording instead of retyping it.
     */
    public function testDefaultDescriptionIsOfferedAsPlainText(): void
    {
        $plain = ManualPage::HorizontalMonochrome->defaultDescriptionAsPlainText();

        self::assertStringNotContainsString('<', $plain);
        self::assertStringStartsWith('Černobílá varianta loga', $plain);
        // The three <p> blocks survive as blank-line separated paragraphs.
        self::assertCount(3, explode("\n\n", $plain));
    }

    private function dispatchEdit(null|string $title, null|string $description): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new EditManualPageText(
            Uuid::fromString(TestDataFixture::MANUAL_1_ID),
            ManualPage::PrimaryColors,
            $title,
            $description,
        ));

        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function manual(): Manual
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manual = $entityManager->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        assert($manual instanceof Manual);

        return $manual;
    }
}
