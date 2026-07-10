<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\EmailSignature;

use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Services\VcardQrCodeGenerator;

/**
 * Sample vCard QR used by template-level demo emails (no variant → no real
 * vCard data). Public (access_control whitelist) so recipients' mail clients
 * can load it, like the per-variant QR endpoint.
 */
final class EmailSignatureDemoVcardQrCodeController extends AbstractController
{
    public function __construct(
        readonly private VcardQrCodeGenerator $vcardQrCodeGenerator,
    ) {
    }

    #[Route(path: '/email-signature-demo/vcard-qr-code.png', name: 'email_signature_demo_vcard_qr_code')]
    public function __invoke(): Response
    {
        $qrCode = $this->vcardQrCodeGenerator->generateDemoQrCode();

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $response = new Response($result->getString(), headers: [
            'Content-Type' => 'image/png',
        ]);

        // The content is constant — cache aggressively; bump the ETag suffix
        // if the sample contact ever changes.
        $response->setMaxAge(31536000);
        $response->setSharedMaxAge(31536000);
        $response->setPublic();
        $response->setEtag(md5('email-signature-demo-vcard-qr-v1'));

        return $response;
    }
}
