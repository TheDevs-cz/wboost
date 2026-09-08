<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Symfony\UX\Turbo\TurboStreamResponse;
use Twig\Environment;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Query\GetExportVersions;
use WBoost\Web\Repository\TemplateExportVersionRepository;

/**
 * The fill pages curate the export history IN PLACE. Their pin / rename
 * anchor forms are Turbo-enabled (`data-turbo="true"` under the site-wide
 * `<html data-turbo="false">`), so the POST arrives with the Turbo Stream
 * media type first in `Accept` — and instead of the redirect, which would
 * reload the page and throw away whatever the user has typed into the fill
 * form, the answer is a stream re-rendering the three fragments the change
 * can touch: the dropdown's rows (`#export-history-menu-body` — a pin moves
 * the row between the sections, a rename relabels it), the loaded-version
 * banner (`#export-history-banner` — name, pin label) and the out-of-line
 * form anchors (`#export-version-form-anchors` — a version that just entered
 * the "recent" rows needs an anchor of its own to be curatable at all).
 *
 * The history page keeps the redirect: its anchors are not Turbo-enabled,
 * the whole page IS the list and a reload there loses nothing.
 */
final readonly class ExportVersionTurboStream
{
    public function __construct(
        private GetExportVersions $getExportVersions,
        private TemplateExportVersionRepository $versionRepository,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * Null when the request is not a Turbo Stream one (a plain form POST
     * gets its redirect) or the version has no fill surface to re-render.
     */
    public function respond(Request $request, TemplateExportVersion $version): null|Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return null;
        }

        if ($version->variant !== null) {
            $routeName = 'template_variant_export';
            $routeParams = ['variantId' => $version->variant->id];
            $historyUrl = $this->urlGenerator->generate('template_variant_export_history', $routeParams);
            $history = $this->getExportVersions->forVariant($version->variant->id);
        } elseif ($version->group !== null) {
            $routeName = 'template_group_fill';
            $routeParams = ['groupId' => $version->group->id];
            $historyUrl = $this->urlGenerator->generate('template_group_export_history', $routeParams);
            $history = $this->getExportVersions->forGroup($version->group->id);
        } else {
            return null;
        }

        // The re-rendered controls return to the same page the ones that
        // submitted did; the same local-path rule as the redirect.
        $redirect = $request->request->getString('redirect');
        $redirectUrl = ExportVersionRedirect::isLocalPath($redirect)
            ? $redirect
            : $this->urlGenerator->generate($routeName, $routeParams);

        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return new TurboStreamResponse($this->twig->render('_export_version_curation.stream.html.twig', [
            'history' => $history,
            'loaded_version' => $this->loadedVersion($request, $version),
            'route_name' => $routeName,
            'route_params' => $routeParams,
            'history_url' => $historyUrl,
            'redirect_url' => $redirectUrl,
        ]));
    }

    /**
     * The version the page has loaded (`?version=<id>`, carried by the
     * anchors' `loaded` field): the banner to re-render. Only a version of
     * the SAME fill surface counts — the field is client-supplied.
     */
    private function loadedVersion(Request $request, TemplateExportVersion $version): null|TemplateExportVersion
    {
        $loadedId = $request->request->getString('loaded');

        if ($loadedId === '' || !Uuid::isValid($loadedId)) {
            return null;
        }

        if ($version->id->toString() === $loadedId) {
            return $version;
        }

        $loaded = $this->versionRepository->find(Uuid::fromString($loadedId));

        if ($loaded === null) {
            return null;
        }

        $sameSurface = $version->variant !== null
            ? ($loaded->variant !== null && $loaded->variant->id->equals($version->variant->id))
            : ($version->group !== null && $loaded->group !== null && $loaded->group->id->equals($version->group->id));

        return $sameSurface ? $loaded : null;
    }
}
