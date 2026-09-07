<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Message\Template\RenameTemplateExportVersion;
use WBoost\Web\Services\Security\TemplateExportVersionVoter;
use WBoost\Web\Services\Template\ExportVersionRedirect;

/**
 * Name a stored export version (blank = unname). POST + CSRF from the inline
 * forms on the history page and the fill page's loaded-version banner; the
 * form's `redirect` field brings the user back to the surface they were on.
 */
final class RenameTemplateExportVersionController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'template_export_version_rename';

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ExportVersionRedirect $redirect,
    ) {
    }

    #[Route(path: '/export-version/{versionId}/rename', name: 'template_export_version_rename', methods: ['POST'])]
    #[IsGranted(TemplateExportVersionVoter::MANAGE, 'version')]
    public function __invoke(
        #[MapEntity(id: 'versionId')]
        TemplateExportVersion $version,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $name = trim($request->request->getString('name'));

        $this->bus->dispatch(new RenameTemplateExportVersion($version->id, $name === '' ? null : $name));

        $this->addFlash('success', $name === '' ? 'Název verze byl odebrán.' : 'Verze byla pojmenována.');

        return $this->redirect($this->redirect->target($request, $version));
    }
}
