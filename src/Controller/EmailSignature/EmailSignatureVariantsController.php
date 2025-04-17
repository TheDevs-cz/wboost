<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\EmailSignature;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\EmailSignatureTemplate;
use WBoost\Web\Services\Security\EmailSignatureTemplateVoter;
use WBoost\Web\Value\TemplateDimension;

final class EmailSignatureVariantsController extends AbstractController
{
    #[Route(path: '/email-signature/{id}/variants', name: 'email_signature_variants')]
    #[IsGranted(EmailSignatureTemplateVoter::VIEW, 'template')]
    public function __invoke(EmailSignatureTemplate $template): Response
    {
        return $this->render('email_signature_variants.html.twig', [
            'project' => $template->project,
            'email_template' => $template,
            'variants' => $template->variants(),
        ]);
    }
}
