<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\TemplateDocument;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TemplateDocumentTestData;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * "Společná pole" on the document page: suggestions, add / edit / delete
 * through the one fields controller, refusals as flash, stale bindings.
 *
 * @covers \WBoost\Web\Controller\TemplateDocument\TemplateDocumentFieldsController
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentFieldsView
 */
final class TemplateDocumentFieldsControllerTest extends WebTestCase
{
    public function testCreateEditAndDeleteASharedField(): void
    {
        $client = $this->adminClient();
        [$documentId, $first, $second] = $this->twoPageDocument();
        $token = $this->token($client, $documentId);

        // Create — both pages bound.
        $client->request('POST', '/template-document/' . $documentId . '/fields', [
            '_token' => $token,
            'fieldId' => '',
            'name' => 'Klient',
            'kind' => 'text',
            'bindings' => [
                $first->toString() => TemplateDocumentTestData::SHARED_INPUT_ID,
                $second->toString() => TemplateDocumentTestData::SHARED_INPUT_ID,
            ],
        ]);
        self::assertResponseRedirects('/template-document/' . $documentId . '#shared-fields');
        $fields = $this->document($documentId)->fields();
        self::assertCount(1, $fields);
        self::assertSame('Klient', $fields[0]->name);
        self::assertSame(DocumentFieldKind::Text, $fields[0]->kind);
        self::assertCount(2, $fields[0]->bindings());
        $fieldId = $fields[0]->id->toString();

        $crawler = $client->followRedirect();
        self::assertStringContainsString('Společné pole „Klient" uloženo.', $crawler->filter('body')->text());
        self::assertCount(1, $crawler->filter('[data-document-field="' . $fieldId . '"]'));
        self::assertCount(0, $crawler->filter('[data-document-field-suggestion]'), 'A bound input is no longer suggested.');

        // Edit — renamed, page 2 unbound; the id is kept.
        $client->request('POST', '/template-document/' . $documentId . '/fields', [
            '_token' => $token,
            'fieldId' => $fieldId,
            'name' => 'Odběratel',
            'kind' => 'text',
            'bindings' => [
                $first->toString() => TemplateDocumentTestData::SHARED_INPUT_ID,
                $second->toString() => '',
            ],
        ]);
        self::assertResponseRedirects();
        $fields = $this->document($documentId)->fields();
        self::assertCount(1, $fields);
        self::assertSame($fieldId, $fields[0]->id->toString());
        self::assertSame('Odběratel', $fields[0]->name);
        self::assertCount(1, $fields[0]->bindings());

        // Delete.
        $client->request('POST', '/template-document/' . $documentId . '/fields', [
            '_token' => $token,
            'fieldId' => $fieldId,
            'delete' => '1',
        ]);
        self::assertResponseRedirects();
        self::assertSame([], $this->document($documentId)->fields());
    }

    public function testARefusedBindingComesBackAsAFlash(): void
    {
        $client = $this->adminClient();
        [$documentId, $first] = $this->twoPageDocument();
        $token = $this->token($client, $documentId);

        $client->request('POST', '/template-document/' . $documentId . '/fields', [
            '_token' => $token,
            'name' => 'Obrázek',
            // An IMAGE field bound to a TEXT input — not bindable.
            'kind' => 'image',
            'bindings' => [$first->toString() => TemplateDocumentTestData::SHARED_INPUT_ID],
        ]);

        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertStringContainsString('nelze propojit', $crawler->filter('.alert-danger, .alert')->text());
        self::assertSame([], $this->document($documentId)->fields(), 'Nothing was stored.');
    }

    public function testInvalidCsrfIsRefused(): void
    {
        $client = $this->adminClient();
        [$documentId] = $this->twoPageDocument();

        $client->request('POST', '/template-document/' . $documentId . '/fields', ['_token' => 'nope', 'name' => 'X']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuggestionsAndTheStaleBindingBadge(): void
    {
        $client = $this->adminClient();
        [$documentId, $first, $second, $firstVariant] = $this->twoPageDocument();

        // Duplicated pages share the inputId → one-click suggestion.
        $crawler = $client->request('GET', '/template-document/' . $documentId);
        self::assertResponseIsSuccessful();
        $suggestion = $crawler->filter('[data-document-field-suggestion]');
        self::assertCount(1, $suggestion);
        self::assertSame('Jméno', $suggestion->filter('input[name="name"]')->attr('value'));
        self::assertCount(2, $suggestion->filter('input[name^="bindings["]'));
        self::assertCount(1, $crawler->filter('a[href="/template-document/' . $documentId . '/fill"]'), 'The header leads to the fill page.');

        $client->submit($suggestion->form());
        self::assertResponseRedirects();
        self::assertCount(1, $this->document($documentId)->fields());

        // The input disappears from page 1 in the editor → the binding is stale.
        $variant = $this->em()->find(TemplateVariant::class, $firstVariant);
        self::assertInstanceOf(TemplateVariant::class, $variant);
        $variant->editCanvas('{"version":"5.2.4","objects":[]}', [], null);
        $this->em()->flush();

        $crawler = $client->request('GET', '/template-document/' . $documentId);
        self::assertCount(1, $crawler->filter('[data-document-field-stale]'));

        // A further edit through the controller drops the stale binding
        // instead of failing on it.
        $fieldId = $this->document($documentId)->fields()[0]->id->toString();
        $client->request('POST', '/template-document/' . $documentId . '/fields', [
            '_token' => $this->token($client, $documentId),
            'fieldId' => $fieldId,
            'name' => 'Jméno klienta',
            'kind' => 'text',
            'bindings' => [$second->toString() => TemplateDocumentTestData::SHARED_INPUT_ID],
        ]);
        self::assertResponseRedirects();
        $fields = $this->document($documentId)->fields();
        self::assertSame('Jméno klienta', $fields[0]->name);
        self::assertCount(1, $fields[0]->bindings());
    }

    /**
     * @return array{UuidInterface, UuidInterface, UuidInterface, UuidInterface}
     */
    private function twoPageDocument(): array
    {
        $first = TemplateDocumentTestData::a4Template($this->em(), 'Strana A');
        $second = TemplateDocumentTestData::a4Template($this->em(), 'Strana B');
        $documentId = TemplateDocumentTestData::document($this->em(), $this->bus(), [$first['template'], $second['template']]);

        return [$documentId, $first['template'], $second['template'], $first['variant']];
    }

    private function token(KernelBrowser $client, UuidInterface $documentId): string
    {
        $crawler = $client->request('GET', '/template-document/' . $documentId);

        return (string) $crawler->filter('form[action="/template-document/' . $documentId . '/fields"] input[name="_token"]')->first()->attr('value');
    }

    private function document(UuidInterface $documentId): TemplateDocument
    {
        $this->em()->clear();
        $document = $this->em()->find(TemplateDocument::class, $documentId);
        self::assertInstanceOf(TemplateDocument::class, $document);

        return $document;
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        return $client;
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
