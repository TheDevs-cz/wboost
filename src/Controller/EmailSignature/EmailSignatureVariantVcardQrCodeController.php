<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\EmailSignature;

use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\EmailSignatureVariant;
use WBoost\Web\Exceptions\NoVcardData;
use WBoost\Web\Services\VcardQrCodeGenerator;

final class EmailSignatureVariantVcardQrCodeController extends AbstractController
{
    public function __construct(
        readonly private VcardQrCodeGenerator $vcardQrCodeGenerator,
    ) {
    }

    #[Route(path: '/email-signature-variant/{variantId}/vcard-qr-code.png', name: 'email_signature_variant_vcard_qr_code')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        EmailSignatureVariant $variant,
    ): Response {
        try {
            $qrCode = $this->vcardQrCodeGenerator->generateQrCode($variant);
        } catch (NoVcardData) {
            throw $this->createNotFoundException();
        }

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        return new Response($result->getString(), headers: [
            'Content-Type' => 'image/png',
        ]);
    }
}
