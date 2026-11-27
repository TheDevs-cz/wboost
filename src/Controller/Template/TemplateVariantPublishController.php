<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\FacebookNotConnected;
use WBoost\Web\Exceptions\FacebookPermissionMissing;
use WBoost\Web\Exceptions\FacebookTokenExpired;
use WBoost\Web\Exceptions\InstagramNotConnected;
use WBoost\Web\Exceptions\InstagramPermissionMissing;
use WBoost\Web\Exceptions\InstagramRateLimited;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Message\SocialAccount\MarkSocialAccountNeedsReconnect;
use WBoost\Web\Services\AiScan\Template\UnfilledExportGuard;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Meta\GetFacebookDestinations;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Meta\PublishToFacebookPage;
use WBoost\Web\Services\Instagram\InstagramPublishing;
use WBoost\Web\Services\Instagram\ProjectInstagramConnection;
use WBoost\Web\Services\Instagram\PublishToProjectInstagram;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\FillFormRequestParser;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveShapeColorOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\Template\RecordExportVersion;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;
use WBoost\Web\Value\ExportFillValues;
use WBoost\Web\Value\InstagramPostKind;

/**
 * Direct publish of a filled variant to the PROJECT's Instagram account
 * (project_social_account, Unified Login) or to one of the user's own
 * Facebook Pages (personal Facebook link). Same POST body as the download
 * endpoint (the JS re-posts the fill form via fetch) plus `platform`,
 * `caption` and — Facebook only — `targetId` (page id, validated against the
 * user's OWN pages). Instagram's destination is never posted: it is resolved
 * from the variant's project. A 9:16 variant goes to Instagram as a story.
 * Responds JSON — the fill page stays put, no state is lost.
 *
 * Only variants in a social-network format (dimension preset set) can be
 * published — a free-form print/px variant has no social destination.
 *
 * Deliberately synchronous: the user needs actionable feedback (expired
 * token → reconnect CTA), and Messenger's retrying async transport could
 * double-post. The response is buffered (FrankenPHP-safe).
 */
final class TemplateVariantPublishController extends AbstractController
{
    public function __construct(
        private readonly TemplateVariantImageRendererInterface $renderer,
        private readonly ResolveTextOverrides $resolveTextOverrides,
        private readonly ResolveRichTextOptions $resolveRichTextOptions,
        private readonly ResolveImageOverrides $resolveImageOverrides,
        private readonly FillFormRequestParser $fillFormParser,
        private readonly GetFacebookDestinations $destinations,
        private readonly PublishToFacebookPage $publishToFacebookPage,
        private readonly PublishToProjectInstagram $publishToProjectInstagram,
        private readonly ProjectInstagramConnection $instagram,
        private readonly InstagramPublishing $instagramPublishing,
        private readonly RecordExportUsage $recordExportUsage,
        private readonly RecordExportVersion $recordExportVersion,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly ReleaseSessionLock $releaseSessionLock,
        private readonly ResolveShapeColorOverrides $resolveShapeColorOverrides,
        private readonly UnfilledExportGuard $unfilledGuard,
    ) {
    }

    #[Route(
        path: '/template-variant/{variantId}/publish',
        name: 'template_variant_publish',
        methods: ['POST'],
    )]
    #[IsGranted(TemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        if ($variant->dimension->preset === null) {
            return new JsonResponse(
                ['error' => 'Publikovat na sociální sítě lze pouze varianty ve formátu pro sociální sítě.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $platform = $request->request->getString('platform');
        $targetId = $request->request->getString('targetId');
        $caption = trim($request->request->getString('caption'));

        if (!in_array($platform, ['facebook', 'instagram'], true) || ($platform === 'facebook' && $targetId === '')) {
            return new JsonResponse(['error' => 'Neplatný požadavek na publikování.'], Response::HTTP_BAD_REQUEST);
        }

        // Publishing is an export: a scanned template still showing the grey
        // stand-in is not posted until the fill page confirmed
        // (`allowUnfilled=1`). Always JSON here, like every answer of this
        // endpoint.
        $confirmation = $this->unfilledGuard->webConfirmation(
            $request,
            $variant->template->project,
            $this->generateUrl('template_variant_export', ['variantId' => $variant->id]),
            [[$variant, $request->request->all('images')]],
            json: true,
        );

        if ($confirmation !== null) {
            return $confirmation;
        }

        // Everything below is slow outbound work (Meta Graph calls + a
        // Gotenberg render); the session is done once the voter has run.
        $this->releaseSessionLock->release($request);

        if ($platform === 'instagram') {
            return $this->publishToInstagram($variant, $request, $caption);
        }

        $account = $this->destinations->connectedAccount($user);

        if ($account === null) {
            return new JsonResponse(
                ['error' => 'Nejprve propojte svůj facebookový účet ve svém profilu.', 'reconnect' => true],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $pages = $this->destinations->pagesForAccount($account);
            $page = $this->destinations->resolvePage($pages, $targetId);

            if ($page === null) {
                return new JsonResponse(
                    ['error' => 'Vybraná stránka nebyla mezi vašimi facebookovými stránkami nalezena.'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $postId = $this->publishToFacebookPage->publish($page, $this->renderVariant($variant, $request), $caption);
        } catch (FacebookNotConnected $exception) {
            return new JsonResponse(['error' => $exception->userMessage(), 'reconnect' => true], Response::HTTP_CONFLICT);
        } catch (FacebookTokenExpired $exception) {
            $this->bus->dispatch(new MarkSocialAccountNeedsReconnect($account->id->toString()));

            return new JsonResponse(['error' => $exception->userMessage(), 'reconnect' => true], Response::HTTP_CONFLICT);
        } catch (FacebookPermissionMissing $exception) {
            return new JsonResponse(['error' => $exception->userMessage(), 'reconnect' => true], Response::HTTP_CONFLICT);
        } catch (MetaApiError $exception) {
            $this->logger->error('Publishing to a social network failed.', [
                'exception' => $exception,
                'variantId' => $variant->id->toString(),
                'platform' => $platform,
            ]);

            return new JsonResponse(['error' => $exception->userMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $this->recordPublish($variant, $request, ExportChannel::Facebook);

        return new JsonResponse(['ok' => true, 'platform' => $platform, 'postId' => $postId]);
    }

    private function publishToInstagram(TemplateVariant $variant, Request $request, string $caption): Response
    {
        if (!$this->instagramPublishing->isAvailable()) {
            return new JsonResponse(['error' => 'Publikování na Instagram zatím není k dispozici.'], Response::HTTP_FORBIDDEN);
        }

        $preset = $variant->dimension->preset;
        assert($preset !== null);
        $kind = InstagramPostKind::forPreset($preset);

        if (mb_strlen($caption) > 2200) {
            return new JsonResponse(['error' => 'Popisek může mít nejvýš 2 200 znaků.'], Response::HTTP_BAD_REQUEST);
        }

        $account = $this->instagram->usable($variant->template->project);

        if ($account === null) {
            return new JsonResponse(
                ['error' => 'Projekt nemá propojený Instagram (nebo propojení vypršelo).', 'reconnect' => true],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $publication = $this->publishToProjectInstagram->publish($account, $this->renderVariant($variant, $request), $caption, $kind);
        } catch (InstagramNotConnected | InstagramTokenExpired | InstagramPermissionMissing $exception) {
            return new JsonResponse(['error' => $exception->userMessage(), 'reconnect' => true], Response::HTTP_CONFLICT);
        } catch (InstagramRateLimited $exception) {
            return new JsonResponse(['error' => $exception->userMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (MetaApiError $exception) {
            $this->logger->error('Publishing to Instagram failed.', [
                'exception' => $exception,
                'variantId' => $variant->id->toString(),
                'projectId' => $variant->template->project->id->toString(),
            ]);

            return new JsonResponse(['error' => $exception->userMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $this->recordPublish($variant, $request, ExportChannel::Instagram);

        return new JsonResponse([
            'ok' => true,
            'platform' => 'instagram',
            'postId' => $publication->mediaId,
            'permalink' => $publication->permalink,
            'kind' => $publication->kind->value,
            'account' => $account->username,
        ]);
    }

    private function recordPublish(TemplateVariant $variant, Request $request, ExportChannel $channel): void
    {
        $this->recordExportUsage->record($variant, $channel);
        $this->recordExportVersion->recordVariant($variant, $channel, ExportFillValues::fromVariantWebForm(
            $request->request->all('textValues'),
            $request->request->all('hiddenValues'),
            $request->request->all('images'),
            $request->request->all('fontValues'),
            $request->request->all('shapeColorValues'),
        )->withRows($this->fillFormParser->parseRowCounts($request, $variant))
            ->withOffsets([$variant->id->toString() => $this->fillFormParser->parseOffsets($request, RepeatableRows::expand($variant))]), $request->request->getString('draftVersionId'));
    }

    /**
     * The exact same render the download endpoint produces (lenient overflow
     * — the fill page blocks publishing client-side while a container
     * overflows, mirroring the export button).
     */
    private function renderVariant(TemplateVariant $variant, Request $request): string
    {
        // Resolve + render the EXPANDED clone (repeatable rows); `$variant`
        // itself stays the managed entity the caller records against.
        $rowCounts = $this->fillFormParser->parseRowCounts($request, $variant);
        $variant = RepeatableRows::expand($variant);

        $overrides = $this->resolveTextOverrides->resolve(
            $variant->inputs,
            $this->fillFormParser->parseTextValues($request),
            truncateOverflow: true,
            richTextOptions: $this->resolveRichTextOptions->forVariant($variant),
        )->withShapeColors($this->resolveShapeColorOverrides->forVariant($variant, $request->request->all('shapeColorValues'), lenient: true))
            ->withRowCounts($rowCounts)
            ->withOffsets($this->fillFormParser->parseOffsets($request, $variant));

        $imageOverrides = $this->resolveImageOverrides->resolve(
            $variant->imageInputs,
            $variant->template->project->id,
            $this->fillFormParser->parseImageValues($request),
        );

        return $this->renderer->renderToBytes($variant, $overrides, $imageOverrides);
    }
}
