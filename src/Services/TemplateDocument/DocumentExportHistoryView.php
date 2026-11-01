<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Symfony\UX\Turbo\TurboStreamResponse;
use Twig\Environment;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;
use WBoost\Web\Services\Template\ExportVersionRedirect;

/**
 * The document fill page's "Historie exportů" chrome — the SAME partials as
 * the variant / group fill pages (`_export_history_menu.html.twig`, the
 * curation + draft streams, the form anchors), fed with a document's history
 * and the document's own pin / rename routes. The
 * {@see \WBoost\Web\Services\Template\ExportVersionTurboStream} counterpart:
 * a Turbo-submitted pin / rename answers with a stream re-rendering the
 * dropdown in place (the fill form keeps what the user typed), a plain POST
 * (the history page) gets the redirect.
 */
final readonly class DocumentExportHistoryView
{
    public const string PIN_ROUTE = 'template_document_export_version_pin';
    public const string RENAME_ROUTE = 'template_document_export_version_rename';

    public function __construct(
        private GetExportVersions $getExportVersions,
        private TemplateDocumentExportVersionRepository $versionRepository,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * The template context the history partials expect.
     *
     * @return array<string, mixed>
     */
    public function context(TemplateDocument $document, null|TemplateDocumentExportVersion $loaded, null|string $redirectUrl = null): array
    {
        $routeParams = ['documentId' => $document->id->toString()];

        return [
            'history' => $this->getExportVersions->forDocument($document->id),
            'loaded_version' => $loaded,
            'route_name' => 'template_document_fill',
            'route_params' => $routeParams,
            'history_url' => $this->urlGenerator->generate('template_document_export_history', $routeParams),
            'redirect_url' => $redirectUrl ?? $this->urlGenerator->generate('template_document_fill', $routeParams + ($loaded !== null ? ['version' => $loaded->id->toString()] : [])),
            'pin_route' => self::PIN_ROUTE,
            'rename_route' => self::RENAME_ROUTE,
        ];
    }

    /**
     * Null when the request is not a Turbo Stream one — the caller redirects.
     */
    public function respond(Request $request, TemplateDocumentExportVersion $version): null|Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return null;
        }

        $redirect = $request->request->getString('redirect');
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return new TurboStreamResponse($this->twig->render('_export_version_curation.stream.html.twig', $this->context(
            $version->document,
            $this->loadedVersion($request, $version),
            ExportVersionRedirect::isLocalPath($redirect) ? $redirect : null,
        )));
    }

    /**
     * Answer to "Uložit rozpracované": the saved draft becomes the page's
     * loaded version (the whole dropdown re-renders around it).
     */
    public function draftSaved(TemplateDocumentExportVersion $draft): Response
    {
        return new TurboStreamResponse($this->twig->render('_fill_draft_saved.stream.html.twig', $this->context($draft->document, $draft)));
    }

    /**
     * Where a plain (non-Turbo) pin / rename POST returns to: the local path
     * it names, else the document's history page.
     */
    public function redirectTarget(Request $request, TemplateDocumentExportVersion $version): string
    {
        $redirect = $request->request->getString('redirect');

        return ExportVersionRedirect::isLocalPath($redirect)
            ? $redirect
            : $this->urlGenerator->generate('template_document_export_history', ['documentId' => $version->document->id->toString()]);
    }

    /**
     * The version the page has loaded (the anchors' client-supplied `loaded`
     * field) — only one of the SAME document counts.
     */
    private function loadedVersion(Request $request, TemplateDocumentExportVersion $version): null|TemplateDocumentExportVersion
    {
        $loadedId = $request->request->getString('loaded');

        if ($loadedId === '' || !Uuid::isValid($loadedId)) {
            return null;
        }

        if ($version->id->toString() === $loadedId) {
            return $version;
        }

        $loaded = $this->versionRepository->find(Uuid::fromString($loadedId));

        return $loaded !== null && $loaded->document->id->equals($version->document->id) ? $loaded : null;
    }
}
