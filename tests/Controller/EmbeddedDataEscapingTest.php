<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\WeeklyMenu;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\FontFace;

/**
 * The 2026-11-13 sweep: what one user typed or uploaded, embedded in a page
 * somebody else opens, in a context HTML escaping does not cover — a JSON
 * `<script>` block, a JS string inside an inline handler, a CSS string.
 * (The headless render page has its own tests:
 * {@see \WBoost\Web\Tests\Services\Editor\TemplateRenderHtmlEscapingTest}.)
 */
final class EmbeddedDataEscapingTest extends WebTestCase
{
    /**
     * `<!--<script` in a text made the HTML parser swallow the block's own
     * `</script>` — the group editor of that group no longer opened.
     */
    public function testATextOfADesignCannotEndOrSwallowTheGroupEditorsDataBlock(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $hostile = 'x<!--<script>alert(1)</script><script>alert(2)</script>-->';
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = $entityManager->find(TemplateVariant::class, Uuid::fromString(TestDataFixture::GROUPED_PRESET_VARIANT_ID));
        self::assertInstanceOf(TemplateVariant::class, $variant);
        $canvas = json_encode(['version' => '7.3.1', 'objects' => [['type' => 'Textbox', 'text' => $hostile, 'left' => 0, 'top' => 0, 'width' => 100]]], JSON_THROW_ON_ERROR);
        $variant->editCanvas($canvas, [], $variant->previewImagePath, []);
        $entityManager->flush();

        $crawler = $client->request('GET', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/editor');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        $start = strpos($html, 'data-group-editor-target="variantsData">');
        self::assertNotFalse($start);
        $end = strpos($html, ']</script>', $start);
        self::assertNotFalse($end);
        $block = substr($html, $start, $end - $start);

        self::assertStringNotContainsString('<!--', $block);
        self::assertStringNotContainsString('<script', $block);
        self::assertStringNotContainsString('</script', $block);

        // …and the parsed page hands the controller the canvas as it was saved.
        $data = json_decode($crawler->filter('script[data-group-editor-target="variantsData"]')->text(null, false), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $canvases = array_column($data, 'canvas', 'id');
        self::assertSame($canvas, $canvases[TestDataFixture::GROUPED_PRESET_VARIANT_ID] ?? null);
    }

    /**
     * An address is put into `confirm('…')` inside an `onclick`. The browser
     * decodes the attribute's entities BEFORE the script runs, so HTML
     * escaping alone lets a quote end the JS string (`'` is a legal character
     * of an e-mail address).
     */
    public function testAnApprovalAddressCannotLeaveTheConfirmString(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $menu = $entityManager->find(WeeklyMenu::class, Uuid::fromString(TestDataFixture::WEEKLY_MENU_1_ID));
        self::assertInstanceOf(WeeklyMenu::class, $menu);
        $menu->edit($menu->name, $menu->validFrom, $menu->validTo, $menu->createdBy, $menu->approvedBy, "a');alert(document.domain);('@test.cz");
        $entityManager->flush();

        foreach (['/project/' . TestDataFixture::PROJECT_1_ID . '/weekly-menus', '/edit-weekly-menu/' . TestDataFixture::WEEKLY_MENU_1_ID] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();

            $handlers = $crawler->filter('button[onclick*="Odeslat žádost"]')->each(static fn ($node): string => (string) $node->attr('onclick'));
            self::assertNotSame([], $handlers, $url . ': the request button is on the page');

            foreach ($handlers as $handler) {
                // What the browser hands the JS engine (entities decoded):
                // one string literal, nothing after it but `?');`.
                self::assertStringNotContainsString("');alert(", $handler, $url);
                self::assertSame(1, preg_match("/^return confirm\\('(?:[^'\\\\]|\\\\.)*'\\);$/", $handler), $url . ': ' . $handler);
            }
        }
    }

    /**
     * A font's names come from the uploaded file's name table. A line break
     * ends a CSS string, and what follows was read as CSS by every page that
     * registers the project's fonts.
     */
    public function testAFontNameCannotLeaveItsCssString(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);
        // (no slash: a face name is a route parameter of the fonts page)
        $injected = "} body { background: url(evil.test) } @font-face { font-family: 'x";
        $entityManager->persist(new Font(
            Uuid::fromString('00000000-0000-0000-0000-00000000e511'),
            $project,
            new \DateTimeImmutable(),
            "Evil\n" . $injected,
            new FontFace("Face'\n" . $injected, 400, 'normal', "fixtures/fonts/evil'\n) " . $injected . '.ttf'),
        ));
        $entityManager->flush();

        $pages = [
            '/project/' . TestDataFixture::PROJECT_1_ID . '/fonts',
            '/template-variant/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
        ];

        foreach ($pages as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();

            $css = implode("\n", $crawler->filter('style')->each(static fn ($node): string => $node->text(null, false)))
                . "\n" . implode("\n", $crawler->filter('[style]')->each(static fn ($node): string => (string) $node->attr('style')));

            self::assertStringContainsString('Evil', $css, $url . ': the font is registered on the page');
            self::assertStringNotContainsString('url(evil.test', $css, $url);
            self::assertStringNotContainsString('body {', $css, $url);
        }
    }
}
