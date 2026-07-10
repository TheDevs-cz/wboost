<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\EmailSignature;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @covers \WBoost\Web\Controller\EmailSignature\EmailSignatureDemoVcardQrCodeController
 */
final class EmailSignatureDemoVcardQrCodeControllerTest extends WebTestCase
{
    public function testServesPngAnonymously(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/email-signature-demo/vcard-qr-code.png');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringStartsWith("\x89PNG", $content);
    }
}
