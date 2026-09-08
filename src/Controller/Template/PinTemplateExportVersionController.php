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
use WBoost\Web\Message\Template\PinTemplateExportVersion;
use WBoost\Web\Services\Security\TemplateExportVersionVoter;
use WBoost\Web\Services\Template\ExportVersionRedirect;
use WBoost\Web\Services\Template\ExportVersionTurboStream;

/**
 * Pin / unpin a stored export version (`pinned=1|0`). POST + CSRF from the
 * pin buttons on the history page, the fill page's dropdown rows and its
 * loaded-version banner. A fill page's Turbo-submitted toggle gets a Turbo
 * Stream re-rendering the history chrome in place (no reload, no flash —
 * the flipped pin IS the confirmation); a plain POST is redirected back
 * where it came from.
 */
final class PinTemplateExportVersionController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'template_export_version_pin';

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ExportVersionRedirect $redirect,
        readonly private ExportVersionTurboStream $turboStream,
    ) {
    }

    #[Route(path: '/export-version/{versionId}/pin', name: 'template_export_version_pin', methods: ['POST'])]
    #[IsGranted(TemplateExportVersionVoter::MANAGE, 'version')]
    public function __invoke(
        #[MapEntity(id: 'versionId')]
        TemplateExportVersion $version,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $pinned = $request->request->getBoolean('pinned');

        $this->bus->dispatch(new PinTemplateExportVersion($version->id, $pinned));

        $stream = $this->turboStream->respond($request, $version);
        if ($stream !== null) {
            return $stream;
        }

        $this->addFlash('success', $pinned ? 'Verze byla připnuta – zůstane nahoře a nikdy se nepromaže.' : 'Verze byla odepnuta.');

        return $this->redirect($this->redirect->target($request, $version));
    }
}
