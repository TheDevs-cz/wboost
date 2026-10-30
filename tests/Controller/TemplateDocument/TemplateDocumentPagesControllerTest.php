<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;

/**
 * The web surface of multi-page documents: the listing card (pages hidden
 * behind it), the document page and its page actions, the editor strip and
 * the admin-only rollout switch.
 */
final class TemplateDocumentPagesControllerTest extends WebTestCase
{
    public function testListingShowsTheDocumentCardAndHidesItsPages(): void
    {
        $client = $this->adminClient();
        $page = TemplateDocumentTestData::a4Template($this->em(), 'Skrytá stránka');
        $loose = TemplateDocumentTestData::a4Template($this->em(), 'Volná šablona');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$page['template']], 'Cenová nabídka');

        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/templates');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-entity-id="' . $documentId . '"]'), 'The document card is listed (sortable like a template).');
        self::assertCount(0, $crawler->filter('[data-entity-id="' . $page['template'] . '"]'), 'Its page template is not.');
        self::assertCount(1, $crawler->filter('[data-entity-id="' . $loose['template'] . '"]'));
        self::assertStringContainsString('Cenová nabídka', $crawler->filter('[data-entity-id="' . $documentId . '"]')->text());
        self::assertCount(1, $crawler->filter('a[href="/project/' . TestDataFixture::PROJECT_1_ID . '/add-template-document"]'));
    }

    public function testWithTheSwitchOffPagesAreOrdinaryTemplates(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $page = TemplateDocumentTestData::a4Template($this->em(), 'Stránka');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$page['template']]);

        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/templates');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-entity-id="' . $page['template'] . '"]'), 'Non-admins still see the page as a template until rollout.');
        self::assertCount(0, $crawler->filter('[data-entity-id="' . $documentId . '"]'));

        $client->request('GET', '/template-document/' . $documentId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateFormMakesADocumentAndOpensTheAttachDialog(): void
    {
        $client = $this->adminClient();
        TemplateDocumentTestData::a4Template($this->em(), 'Připojitelná');

        $crawler = $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/add-template-document');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Vytvořit a připojit stránky')->form([
            'template_document_form[name]' => 'Ceník 2026',
            'template_document_form[dimension][unit]' => 'mm',
            'template_document_form[dimension][width]' => '210',
            'template_document_form[dimension][height]' => '297',
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#attachPagesModal[data-controller="auto-open-modal"]'));
        self::assertStringContainsString('Připojitelná', $crawler->filter('#attachPagesModal')->text());
    }

    public function testAttachSortDetachAndDeletePages(): void
    {
        $client = $this->adminClient();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'A');
        $b = TemplateDocumentTestData::a4Template($this->em(), 'B');
        $c = TemplateDocumentTestData::a4Template($this->em(), 'C');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$a['template']]);
        $base = '/template-document/' . $documentId;
        $token = $this->token($client);

        $client->request('POST', $base . '/attach', ['_token' => $token, 'templateIds' => [$b['template']->toString(), $c['template']->toString()]]);
        self::assertResponseRedirects($base);
        self::assertSame(['A', 'B', 'C'], $this->pageNames($documentId));

        $client->request('POST', $base . '/sort-pages', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sorted' => [$c['template'], $a['template'], $b['template']]], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame(['C', 'A', 'B'], $this->pageNames($documentId));

        // A stale tab posting a different SET is refused (it would detach).
        $client->request('POST', $base . '/sort-pages', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['sorted' => [$c['template']]], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);

        $client->request('POST', $base . '/page/' . $a['template'] . '/detach', ['_token' => $token]);
        self::assertSame(['C', 'B'], $this->pageNames($documentId));
        self::assertNotNull($this->em()->find(Template::class, $a['template']), 'Detach keeps the template.');

        $client->request('POST', $base . '/page/' . $b['template'] . '/delete', ['_token' => $token]);
        self::assertSame(['C'], $this->pageNames($documentId));
        self::assertNull($this->em()->find(Template::class, $b['template']), 'Delete page removes the template.');

        $crawler = $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.document-pages__item[data-entity-id="' . $c['template'] . '"]'));
    }

    public function testDuplicateInsertsTheCopyRightAfterThePage(): void
    {
        $client = $this->adminClient();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'A');
        $b = TemplateDocumentTestData::a4Template($this->em(), 'B');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$a['template'], $b['template']]);

        $client->request('POST', '/template-document/' . $documentId . '/page/' . $a['template'] . '/duplicate', ['_token' => $this->token($client)]);
        self::assertResponseRedirects();

        self::assertSame(['A', 'A (kopie)', 'B'], $this->pageNames($documentId));
    }

    public function testNewPageOpensTheEditorWithTheDocumentStrip(): void
    {
        $client = $this->adminClient();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'A');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$a['template']], 'Brožura');

        $client->request('POST', '/template-document/' . $documentId . '/add-page', ['_token' => $this->token($client), 'name' => 'Obsah']);
        self::assertResponseRedirects();
        self::assertSame(['A', 'Obsah'], $this->pageNames($documentId));

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="document-page-strip"]'));
        self::assertStringContainsString('Brožura', $crawler->filter('.breadcrumb')->text());
        self::assertStringContainsString('Strana 2', $crawler->filter('.breadcrumb')->text());

        // Without ?document the editor is the plain one.
        $client->request('GET', '/template-variant/' . $a['variant'] . '/editor');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-controller="document-page-strip"]');
    }

    public function testDeleteDocumentModes(): void
    {
        $client = $this->adminClient();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'A');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$a['template']]);

        $client->request('POST', '/template-document/' . $documentId . '/delete', ['_token' => $this->token($client), 'deletePages' => '0']);
        self::assertResponseRedirects('/project/' . TestDataFixture::PROJECT_1_ID . '/templates');
        self::assertNull($this->em()->find(TemplateDocument::class, $documentId));
        self::assertNotNull($this->em()->find(Template::class, $a['template']));
    }

    public function testPrintSettingsApplyToEveryPage(): void
    {
        $client = $this->adminClient();
        $a = TemplateDocumentTestData::a4Template($this->em(), 'A');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$a['template']]);

        $client->request('POST', '/template-document/' . $documentId . '/print-settings', ['_token' => $this->token($client), 'bleed' => '5', 'cropMarks' => '1']);
        self::assertResponseRedirects();

        $document = $this->em()->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);
        self::assertSame(5.0, $document->pages()[0]->variant->dimension->bleedMm);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        return $client;
    }

    private function token(KernelBrowser $client): string
    {
        // Render a page first so the session exists, then read the token the
        // forms would carry.
        $client->request('GET', '/project/' . TestDataFixture::PROJECT_1_ID . '/templates');
        $crawler = $client->getCrawler();
        $fromPage = $crawler->filter('input[name="_token"]');

        if ($fromPage->count() > 0) {
            return (string) $fromPage->first()->attr('value');
        }

        $manager = self::getContainer()->get(CsrfTokenManagerInterface::class);

        return $manager->getToken('template_document')->getValue();
    }

    /**
     * @return list<string>
     */
    private function pageNames(mixed $documentId): array
    {
        $this->em()->clear();
        $document = $this->em()->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);

        return array_map(static fn ($page): string => $page->template->name, $document->pages());
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
