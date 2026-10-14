<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Landing;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\LandingLead;
use WBoost\Web\Repository\LandingLeadRepository;
use WBoost\Web\Value\LandingLeadSource;

/**
 * POST /poptavka — the write endpoint behind the static landing pages
 * (landing/src/index.html, landing/src/klienti.html).
 */
final class LandingLeadControllerTest extends WebTestCase
{
    public function testDesignerLeadIsStoredAndEmailed(): void
    {
        $browser = self::createClient();

        $this->post($browser, [
            'source' => 'grafici',
            'name' => '  Jana   Grafická ',
            'email' => 'jana@example.com',
            'phone' => '777 123 456',
            'website' => 'https://jana.example',
            'fax' => '',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['ok' => true], $this->json($browser));

        $lead = $this->onlyLead();
        self::assertSame(LandingLeadSource::Designers, $lead->source);
        self::assertSame('Jana Grafická', $lead->name);
        self::assertSame('jana@example.com', $lead->email);
        self::assertSame('777 123 456', $lead->phone);
        self::assertSame('https://jana.example', $lead->website);

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'info@wantoo.cz');
        self::assertEmailAddressContains($email, 'Reply-To', 'jana@example.com');
        self::assertEmailHtmlBodyContains($email, 'https://jana.example');
        self::assertEmailHtmlBodyContains($email, '/admin/landing-leads');
    }

    public function testClientLeadKeepsInterestsAndDesigner(): void
    {
        $browser = self::createClient();

        $this->post($browser, [
            'source' => 'klienti',
            'name' => 'Obecní úřad',
            'email' => 'urad@example.com',
            'company' => 'Obec Lhota',
            'interests' => 'Plakát na akci, Stories, , Plakát na akci',
            'hasDesigner' => 'Ano',
            'designerName' => 'Pepa Grafik',
            'designerEmail' => 'pepa@example.com',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $lead = $this->onlyLead();
        self::assertSame(LandingLeadSource::Clients, $lead->source);
        self::assertSame('Obec Lhota', $lead->company);
        self::assertSame(['Plakát na akci', 'Stories'], $lead->interests);
        self::assertTrue($lead->hasDesigner);
        self::assertSame('Pepa Grafik', $lead->designerName);
        self::assertSame('pepa@example.com', $lead->designerEmail);
    }

    public function testDesignerFieldsAreIgnoredWithoutADesigner(): void
    {
        $browser = self::createClient();

        $this->post($browser, [
            'source' => 'klienti',
            'name' => 'Firma',
            'email' => 'firma@example.com',
            'hasDesigner' => 'Ne',
            'designerName' => 'leftover',
            'designerEmail' => 'not-an-email',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $lead = $this->onlyLead();
        self::assertFalse($lead->hasDesigner);
        self::assertNull($lead->designerName);
        self::assertNull($lead->designerEmail);
    }

    public function testChatMessageNeedsNoName(): void
    {
        $browser = self::createClient();

        $this->post($browser, [
            'source' => 'chat',
            'email' => 'chat@example.com',
            'message' => "Dobrý den,\nkolik to stojí?",
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $lead = $this->onlyLead();
        self::assertSame(LandingLeadSource::Chat, $lead->source);
        self::assertNull($lead->name);
        self::assertSame("Dobrý den,\nkolik to stojí?", $lead->message);
    }

    public function testInvalidInputIsA422WithFieldErrors(): void
    {
        $browser = self::createClient();

        $this->post($browser, ['source' => 'grafici', 'email' => 'nope']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = $this->json($browser);
        self::assertFalse($body['ok']);
        self::assertIsArray($body['errors']);
        self::assertArrayHasKey('email', $body['errors']);
        self::assertArrayHasKey('name', $body['errors']);
        self::assertSame([], $this->leads());
        self::assertEmailCount(0);
    }

    public function testChatWithoutMessageIsRejected(): void
    {
        $browser = self::createClient();

        $this->post($browser, ['source' => 'chat', 'email' => 'chat@example.com']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertArrayHasKey('message', (array) $this->json($browser)['errors']);
    }

    public function testUnknownSourceIsRejected(): void
    {
        $browser = self::createClient();

        $this->post($browser, ['source' => 'elsewhere', 'name' => 'X', 'email' => 'x@example.com']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->leads());
    }

    public function testHoneypotPretendsSuccessButStoresNothing(): void
    {
        $browser = self::createClient();

        $this->post($browser, ['source' => 'grafici', 'name' => 'Bot', 'email' => 'bot@example.com', 'fax' => '123']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([], $this->leads());
        self::assertEmailCount(0);
    }

    public function testWithoutJavaScriptTheBrowserIsSentBackToThePage(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/poptavka', ['source' => 'grafici', 'name' => 'Bez JS', 'email' => 'nojs@example.com']);
        self::assertResponseRedirects('/?odeslano#kontakt', Response::HTTP_SEE_OTHER);

        $browser->request('POST', '/poptavka', ['source' => 'klienti', 'email' => 'bad']);
        self::assertResponseRedirects('/klienti?chyba#formular', Response::HTTP_SEE_OTHER);
    }

    public function testOnlyPostIsRouted(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/poptavka');

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    /**
     * Same technique as ClientRegistrationTest: one kernel across requests so
     * the in-memory limiter storage (config/packages/test/rate_limiter.php)
     * keeps its count.
     */
    public function testEndpointIsRateLimitedPerAddress(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();

        $accepted = 0;

        for ($attempt = 0; $attempt < 22; $attempt++) {
            $this->post($browser, ['source' => 'chat', 'email' => 'flood@example.com', 'message' => (string) $attempt]);

            if ($browser->getResponse()->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS) {
                break;
            }

            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            $accepted++;
        }

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame(20, $accepted);
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(KernelBrowser $browser, array $fields): void
    {
        $browser->request('POST', '/poptavka', $fields, server: ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<LandingLead>
     */
    private function leads(): array
    {
        return self::getContainer()->get(LandingLeadRepository::class)->newestFirst();
    }

    private function onlyLead(): LandingLead
    {
        $leads = $this->leads();
        self::assertCount(1, $leads);

        return $leads[0];
    }
}
