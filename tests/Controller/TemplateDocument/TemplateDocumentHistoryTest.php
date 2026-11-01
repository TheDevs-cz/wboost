<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\DocumentFillKeys;

/**
 * "Historie exportů" + "Uložit rozpracované" on the document fill page: an
 * export is re-loadable via `?version=`, each page getting its own value back;
 * a draft saves without rendering and answers the shared fill-draft contract.
 */
final class TemplateDocumentHistoryTest extends WebTestCase
{
    public function testExportIsReloadableAndDraftsSave(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $a = TemplateDocumentTestData::a4Template($em, 'Úvod');
        $b = TemplateDocumentTestData::a4Template($em, 'Ceník');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$a['template'], $b['template']]);

        $document = $em->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);
        $keys = DocumentFillKeys::forDocument($document);
        $keyA = $keys->keyFor($a['template']->toString(), TemplateDocumentTestData::SHARED_INPUT_ID);
        $keyB = $keys->keyFor($b['template']->toString(), TemplateDocumentTestData::SHARED_INPUT_ID);

        $client->request('POST', '/template-document/' . $documentId . '/export', [
            'format' => 'png',
            'textValues' => [$keyA => 'První', $keyB => 'Druhá'],
        ]);
        self::assertResponseIsSuccessful();

        $versions = self::getContainer()->get(TemplateDocumentExportVersionRepository::class)->forDocument($documentId);
        self::assertCount(1, $versions);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill?version=' . $versions[0]->id);
        self::assertResponseIsSuccessful();
        self::assertSame('První', $crawler->filter('[name="textValues[' . $keyA . ']"]')->attr('value'));
        self::assertSame('Druhá', $crawler->filter('[name="textValues[' . $keyB . ']"]')->attr('value'));
        self::assertSame($versions[0]->id->toString(), $crawler->filter('input[name="draftVersionId"]')->attr('value'));
        self::assertCount(1, $crawler->filter('#export-history-menu [data-export-version-row]'));
        // The row's pin / rename controls submit through anchors OUTSIDE the fill form.
        self::assertCount(1, $crawler->filter('#export-version-form-anchors form[action="/document-export-version/' . $versions[0]->id . '/pin"]'));
        self::assertCount(0, $crawler->filter('form.document-fill form'));

        $token = (string) $crawler->filter('input[name="_fill_draft_token"]')->attr('value');
        $client->request('POST', '/template-document/' . $documentId . '/fill-draft', [
            '_fill_draft_token' => $token,
            'textValues' => [$keyA => 'Rozpracovaný', $keyB => 'Druhá'],
        ]);
        self::assertResponseIsSuccessful();
        $draftId = $client->getResponse()->headers->get('X-Fill-Draft-Version');
        self::assertIsString($draftId);
        self::assertStringContainsString('export-history-menu', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Rozpracováno', (string) $client->getResponse()->getContent());
    }

    public function testVersionsAreRenamedAndPinnedInPlaceAndListedOnTheHistoryPage(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $a = TemplateDocumentTestData::a4Template($em, 'Úvod');
        $documentId = TemplateDocumentTestData::document($em, self::getContainer()->get(MessageBusInterface::class), [$a['template']]);

        $document = $em->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);
        $key = DocumentFillKeys::forDocument($document)->keyFor($a['template']->toString(), TemplateDocumentTestData::SHARED_INPUT_ID);

        $client->request('POST', '/template-document/' . $documentId . '/export', ['format' => 'png', 'textValues' => [$key => 'Nabídka pro Novákovy']]);
        self::assertResponseIsSuccessful();
        $versionId = self::getContainer()->get(TemplateDocumentExportVersionRepository::class)->forDocument($documentId)[0]->id->toString();

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/fill?version=' . $versionId);
        $renameToken = (string) $crawler->filter('[data-export-version-rename="' . $versionId . '"] input[name="_token"]')->attr('value');
        $pinToken = (string) $crawler->filter('[data-export-version-pin="' . $versionId . '"] input[name="_token"]')->attr('value');

        // Turbo-submitted from the fill page → a stream, the menu re-rendered in place.
        $client->request('POST', '/document-export-version/' . $versionId . '/rename', ['_token' => $renameToken, 'name' => 'Finální', 'loaded' => $versionId], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('export-history-menu-body', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Finální', (string) $client->getResponse()->getContent());

        // A plain POST (the history page) is redirected.
        $client->request('POST', '/document-export-version/' . $versionId . '/pin', ['_token' => $pinToken, 'pinned' => '1']);
        self::assertResponseRedirects('/template-document/' . $documentId . '/export-history');

        $em->clear();
        $version = self::getContainer()->get(TemplateDocumentExportVersionRepository::class)->get(Uuid::fromString($versionId));
        self::assertSame('Finální', $version->name);
        self::assertNotNull($version->pinnedAt);

        $crawler = $client->request('GET', '/template-document/' . $documentId . '/export-history');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tr[data-export-version-pinned]'));
        self::assertStringContainsString('Nabídka pro Novákovy', $crawler->filter('table')->text());
    }
}
