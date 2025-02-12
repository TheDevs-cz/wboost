<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Email;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\MailTextInput;

final class EmailExportController extends AbstractController
{
    #[Route(path: '/project/{id}/emails/export', name: 'email_export')]
    #[IsGranted(ProjectVoter::VIEW, 'project')]
    public function __invoke(Project $project): Response
    {
        $inputs = [];

        $inputs[] = new MailTextInput(
            name: 'Kontakt- Jméno a příjmení',
            maxLength: null,
            uppercase: false,
            description: null,
        );

        $inputs[] = new MailTextInput(
            name: 'Kontakt - Pozice/funkce',
            maxLength: null,
            uppercase: false,
            description: null,
        );

        $inputs[] = new MailTextInput(
            name: 'Kontakt - Telefonní číslo',
            maxLength: null,
            uppercase: false,
            description: null,
        );

        $inputs[] = new MailTextInput(
            name: 'Kontakt - E-mail',
            maxLength: null,
            uppercase: false,
            description: null,
        );

        $inputs[] = new MailTextInput(
            name: 'Adresa - Místo',
            maxLength: null,
            uppercase: false,
            description: null,
        );

        $inputs[] = new MailTextInput(
            name: 'Adresa - Ulice',
            maxLength: null,
            uppercase: false,
            description: null,
        );

        $inputs[] = new MailTextInput(
            name: 'Adresa - PSČ',
            maxLength: null,
            uppercase: false,
            description: null,
        );

        $inputs[] = new MailTextInput(
            name: 'Adresa - Město',
            maxLength: null,
            uppercase: false,
            description: null,
        );

        return $this->render('email_export.html.twig', [
            'project' => $project,
            'inputs' => $inputs,
        ]);
    }
}
