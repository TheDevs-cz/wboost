<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ExportFillValues;

/**
 * Curating the export history: naming and pinning versions from the fill
 * page (banner + dropdown) and the dedicated history page, which lists
 * EVERY version pinned-first with a digest of its fill; all of it POST +
 * CSRF, gated on the fill surface's VIEW permission, returning to a LOCAL
 * path only.
 *
 * @covers \WBoost\Web\Controller\Template\RenameTemplateExportVersionController
 * @covers \WBoost\Web\Controller\Template\PinTemplateExportVersionController
 * @covers \WBoost\Web\Controller\Template\TemplateVariantExportHistoryController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportHistoryController
 * @covers \WBoost\Web\MessageHandler\Template\RenameTemplateExportVersionHandler
 * @covers \WBoost\Web\MessageHandler\Template\PinTemplateExportVersionHandler
 * @covers \WBoost\Web\Services\Security\TemplateExportVersionVoter
 * @covers \WBoost\Web\Services\Template\ExportVersionRedirect
 */
final class TemplateExportVersionCurationTest extends WebTestCase
{
    public function testRenameAndPinFromTheFillPageThenTheHistoryPageListsPinnedFirst(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $older = $this->export($client, 'Verze první', new DateTimeImmutable('2026-09-01 10:00:00'));
        $newer = $this->export($client, 'Verze druhá', new DateTimeImmutable('2026-09-02 10:00:00'));

        // Loaded-version banner carries the rename form (every dropdown row
        // carries an inline one too); its token is the real one the page
        // hands out.
        $crawler = $client->request('GET', $this->fillPageUrl() . '?version=' . $older->id->toString());
        self::assertResponseIsSuccessful();
        $renameForm = $crawler->filter('#export-history-banner [data-export-version-rename]');
        self::assertCount(1, $renameForm);
        self::assertCount(2, $crawler->filter('#export-history-menu-body [data-controller="export-version-row"] [data-export-version-rename]'));
        $renameToken = $renameForm->filter('input[name="_token"]')->attr('value');
        self::assertIsString($renameToken);

        $client->request('POST', '/export-version/' . $older->id->toString() . '/rename', [
            '_token' => $renameToken,
            'name' => '  Jarní kampaň  ',
            'redirect' => $this->fillPageUrl() . '?version=' . $older->id->toString(),
        ]);
        self::assertResponseRedirects($this->fillPageUrl() . '?version=' . $older->id->toString());
        self::assertSame('Jarní kampaň', $this->reload($older)->name);

        // Pin from the dropdown row (every row carries the toggle).
        $crawler = $client->request('GET', $this->fillPageUrl());
        self::assertResponseIsSuccessful();
        $pinForms = $crawler->filter('[data-export-version-pin]');
        self::assertCount(2, $pinForms);
        self::assertStringNotContainsString('Připnuté verze', (string) $client->getResponse()->getContent());
        $pinToken = $pinForms->first()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($pinToken);

        $client->request('POST', '/export-version/' . $older->id->toString() . '/pin', [
            '_token' => $pinToken,
            'pinned' => '1',
            'redirect' => $this->fillPageUrl(),
        ]);
        self::assertResponseRedirects($this->fillPageUrl());
        self::assertTrue($this->reload($older)->isPinned());

        // The dropdown now has a pinned section, the name replaces the date.
        $crawler = $client->request('GET', $this->fillPageUrl());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Připnuté verze', $content);
        self::assertStringContainsString('Jarní kampaň', $content);
        self::assertStringContainsString('Zobrazit vše (2)', $content);
        self::assertCount(1, $crawler->filter(sprintf('a[href="%s"]', $this->historyPageUrl())));

        // The history page: the pinned (older) version first, digest per row.
        $crawler = $client->request('GET', $this->historyPageUrl());
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tr[data-export-version]');
        self::assertCount(2, $rows);
        self::assertSame($older->id->toString(), $rows->first()->attr('data-export-version'));
        self::assertNotNull($rows->first()->attr('data-export-version-pinned'));
        self::assertNull($rows->last()->attr('data-export-version-pinned'));
        self::assertSame('Jarní kampaň', $rows->first()->filter('input[name="name"]')->attr('value'));
        self::assertStringContainsString('headline:', $rows->first()->text());
        self::assertStringContainsString('Verze první', $rows->first()->text());
        self::assertStringContainsString('Verze druhá', $rows->last()->text());
        self::assertCount(1, $rows->first()->filter(sprintf('a[href*="version=%s"]', $older->id->toString())));

        // Unpin + unname from the history page, redirect back to it.
        $pinToken = $rows->first()->filter('[data-export-version-pin] input[name="_token"]')->attr('value');
        self::assertIsString($pinToken);
        $client->request('POST', '/export-version/' . $older->id->toString() . '/pin', [
            '_token' => $pinToken,
            'pinned' => '0',
            'redirect' => $this->historyPageUrl(),
        ]);
        self::assertResponseRedirects($this->historyPageUrl());
        self::assertFalse($this->reload($older)->isPinned());

        $client->request('POST', '/export-version/' . $older->id->toString() . '/rename', [
            '_token' => $renameToken,
            'name' => '',
            'redirect' => $this->historyPageUrl(),
        ]);
        self::assertResponseRedirects($this->historyPageUrl());
        self::assertNull($this->reload($older)->name);

        // Unpinned again: freshest first.
        $crawler = $client->request('GET', $this->historyPageUrl());
        self::assertSame($newer->id->toString(), $crawler->filter('tr[data-export-version]')->first()->attr('data-export-version'));
    }

    /**
     * The fill pages curate IN PLACE: their anchors and buttons are
     * Turbo-enabled, and a Turbo-submitted pin / rename is answered with a
     * Turbo Stream re-rendering the dropdown rows, the loaded-version banner
     * and the anchors — no redirect, no flash. The history page keeps the
     * plain forms and the redirect (the page IS the list).
     */
    public function testFillPageCurationAnswersTurboStreamsInPlace(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $older = $this->export($client, 'Verze první', new DateTimeImmutable('2026-09-01 10:00:00'));
        $newer = $this->export($client, 'Verze druhá', new DateTimeImmutable('2026-09-02 10:00:00'));

        $loadedUrl = $this->fillPageUrl() . '?version=' . $older->id->toString();
        $crawler = $client->request('GET', $loadedUrl);
        self::assertResponseIsSuccessful();

        // Turbo takes a submission over only when the form AND its submitter
        // opt in (the site is `<html data-turbo="false">`); the anchors carry
        // the loaded version for the banner the stream re-renders.
        $anchors = $crawler->filter('#export-version-form-anchors form');
        self::assertCount(4, $anchors);
        foreach ($anchors as $anchor) {
            self::assertInstanceOf(\DOMElement::class, $anchor);
            self::assertSame('true', $anchor->getAttribute('data-turbo'));
        }
        self::assertCount(4, $crawler->filter('#export-version-form-anchors input[name="loaded"][value="' . $older->id->toString() . '"]'));
        $submitters = $crawler->filter('[data-export-version-pin] button, [data-export-version-rename] button[type="submit"]');
        self::assertGreaterThan(0, $submitters->count());
        foreach ($submitters as $button) {
            self::assertInstanceOf(\DOMElement::class, $button);
            self::assertSame('true', $button->getAttribute('data-turbo'));
        }
        // The dropdown stays open across those clicks, and every row has the
        // inline rename editor next to its pin.
        $toggle = $crawler->filter('#export-history-menu-body')->closest('.dropdown')?->filter('[data-bs-toggle="dropdown"]');
        self::assertNotNull($toggle);
        self::assertSame('outside', $toggle->attr('data-bs-auto-close'));
        $rows = $crawler->filter('#export-history-menu-body [data-controller="export-version-row"]');
        self::assertCount(2, $rows);
        self::assertCount(1, $rows->first()->filter('[data-export-version-rename] input[name="name"][data-export-version-row-target="input"]'));
        self::assertCount(1, $rows->first()->filter('button[data-action="export-version-row#edit"]'));

        $pinToken = $rows->first()->filter('[data-export-version-pin] input[name="_token"]')->attr('value');
        self::assertIsString($pinToken);
        $renameToken = $rows->first()->filter('[data-export-version-rename] input[name="_token"]')->attr('value');
        self::assertIsString($renameToken);

        $turbo = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];

        // Pin from a row: the stream carries the re-rendered rows (a pinned
        // section now), the banner and the anchors; nothing is redirected.
        $client->request('POST', '/export-version/' . $newer->id->toString() . '/pin', [
            '_token' => $pinToken,
            'pinned' => '1',
            'redirect' => $loadedUrl,
            'loaded' => $older->id->toString(),
        ], [], $turbo);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $client->getResponse()->headers->get('Content-Type'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="export-history-menu-body">', $content);
        self::assertStringContainsString('<turbo-stream action="replace" target="export-history-banner">', $content);
        self::assertStringContainsString('<turbo-stream action="replace" target="export-version-form-anchors">', $content);
        self::assertStringContainsString('Připnuté verze', $content);
        self::assertStringContainsString('Formulář je předvyplněný hodnotami exportu', $content);
        // The re-rendered controls stay Turbo-enabled and keep the page.
        self::assertStringContainsString('data-turbo="true"', $content);
        self::assertStringContainsString('name="redirect" value="' . $loadedUrl . '"', $content);
        self::assertTrue($this->reload($newer)->isPinned());

        // Rename (the row's or the banner's form, same endpoint): the banner
        // AND the row relabel in the same answer.
        $client->request('POST', '/export-version/' . $older->id->toString() . '/rename', [
            '_token' => $renameToken,
            'name' => 'Jarní kampaň',
            'redirect' => $loadedUrl,
            'loaded' => $older->id->toString(),
        ], [], $turbo);
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<strong>Jarní kampaň</strong>', $content);
        self::assertStringContainsString('<div class="text-truncate fw-semibold">', $content);
        self::assertSame('Jarní kampaň', $this->reload($older)->name);

        // Without a loaded version there is no banner to re-render.
        $client->request('POST', '/export-version/' . $newer->id->toString() . '/pin', [
            '_token' => $pinToken,
            'pinned' => '0',
            'redirect' => $this->fillPageUrl(),
        ], [], $turbo);
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('target="export-history-menu-body"', $content);
        self::assertStringNotContainsString('target="export-history-banner"', $content);
        self::assertStringNotContainsString('Připnuté verze', $content);
        self::assertFalse($this->reload($newer)->isPinned());

        // A stream answer queues no flash: the next page load is clean.
        $client->request('GET', $this->fillPageUrl());
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Verze byla připnuta', $content);
        self::assertStringNotContainsString('Verze byla odepnuta', $content);
        self::assertStringNotContainsString('Verze byla pojmenována', $content);

        // The history page's anchors are plain forms: its pin / rename
        // navigate (the page IS the list), and its buttons don't opt in.
        $crawler = $client->request('GET', $this->historyPageUrl());
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('#export-version-form-anchors form'));
        self::assertCount(0, $crawler->filter('#export-version-form-anchors form[data-turbo]'));
        self::assertCount(0, $crawler->filter('#export-version-form-anchors input[name="loaded"]'));
        self::assertCount(0, $crawler->filter('[data-export-version-pin] button[data-turbo], [data-export-version-rename] button[data-turbo]'));
    }

    public function testForeignRedirectsFallBackToTheHistoryPageAndBadTokensAreRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $version = $this->export($client, 'Verze');

        $crawler = $client->request('GET', $this->historyPageUrl());
        $token = $crawler->filter('[data-export-version-pin] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        foreach (['https://evil.example/phish', '//evil.example/phish', '/\\evil.example', "/ok\r\nLocation: x", ''] as $redirect) {
            $client->request('POST', '/export-version/' . $version->id->toString() . '/pin', [
                '_token' => $token,
                'pinned' => '1',
                'redirect' => $redirect,
            ]);
            self::assertResponseRedirects($this->historyPageUrl(), message: sprintf('redirect=%s', $redirect));
        }

        $client->request('POST', '/export-version/' . $version->id->toString() . '/pin', [
            '_token' => 'forged',
            'pinned' => '0',
            'redirect' => $this->historyPageUrl(),
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($version)->isPinned());

        $client->request('POST', '/export-version/' . $version->id->toString() . '/rename', [
            '_token' => 'forged',
            'name' => 'Nope',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload($version)->name);
    }

    public function testCurationIsGatedOnTheFillSurfacesViewPermission(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $version = $this->export($client, 'Verze');
        $crawler = $client->request('GET', $this->historyPageUrl());
        $token = $crawler->filter('[data-export-version-pin] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        // A shared user of the project can curate — the history is shared.
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);
        $client->request('GET', $this->historyPageUrl());
        self::assertResponseIsSuccessful();

        // An outsider can neither see nor touch it (the GET has no CSRF in
        // play, so it is the voter alone that answers; the POSTs are gated by
        // the same attribute before the controller's token check runs).
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);
        $client->request('GET', $this->historyPageUrl());
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/export-version/' . $version->id->toString() . '/pin', ['_token' => $token, 'pinned' => '1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/export-version/' . $version->id->toString() . '/rename', ['_token' => $token, 'name' => 'Nope']);
        self::assertResponseStatusCodeSame(403);

        $reloaded = $this->reload($version);
        self::assertFalse($reloaded->isPinned());
        self::assertNull($reloaded->name);
    }

    public function testGroupHistoryPageAndPinningAGroupVersion(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', [
            'textValues' => [TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň'],
        ]);
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var list<TemplateExportVersion> $versions */
        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['group' => TestDataFixture::TEMPLATE_GROUP_1_ID]);
        self::assertCount(1, $versions);
        $version = $versions[0];

        $historyUrl = '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export-history';
        $fillUrl = '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill';

        $crawler = $client->request('GET', $historyUrl);
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('tr[data-export-version]');
        self::assertCount(1, $row);
        self::assertStringContainsString('headline:', $row->text());
        self::assertStringContainsString('Letní kampaň', $row->text());
        self::assertCount(1, $row->filter(sprintf('a[href="%s?version=%s"]', $fillUrl, $version->id->toString())));

        $token = $row->filter('[data-export-version-pin] input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->request('POST', '/export-version/' . $version->id->toString() . '/pin', [
            '_token' => $token,
            'pinned' => '1',
            'redirect' => 'not a path',
        ]);
        // A non-path redirect lands on the GROUP history page.
        self::assertResponseRedirects($historyUrl);
        self::assertTrue($this->reload($version)->isPinned());

        $client->request('GET', $fillUrl);
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Připnuté verze', $content);
        self::assertStringContainsString('Zobrazit vše (1)', $content);
    }

    /**
     * Exports through the real download endpoint, then stamps the version
     * with an explicit export time: two exports in one test land within the
     * same second, and the history orders by that timestamp.
     */
    private function export(KernelBrowser $client, string $headline, null|DateTimeImmutable $exportedAt = null): TemplateExportVersion
    {
        $client->request('POST', '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/download', [
            'textValues' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => $headline],
        ]);
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $version = $entityManager->getRepository(TemplateExportVersion::class)->findOneBy([
            'variant' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
            'fillValuesHash' => ExportFillValues::fromVariantWebForm(
                [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => $headline],
                [],
                [],
            )->hash(),
        ]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);

        if ($exportedAt !== null) {
            $version->lastExportedAt = $exportedAt;
            $entityManager->flush();
        }

        return $version;
    }

    private function reload(TemplateExportVersion $version): TemplateExportVersion
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(TemplateExportVersion::class, $version->id);
        self::assertInstanceOf(TemplateExportVersion::class, $reloaded);

        return $reloaded;
    }

    private function fillPageUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export';
    }

    private function historyPageUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export-history';
    }
}
