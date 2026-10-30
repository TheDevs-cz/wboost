<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
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
        self::assertCount(1, $crawler->filter('#export-history-menu .dropdown-item'));

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
}
