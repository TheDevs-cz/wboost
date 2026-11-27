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
use WBoost\Web\Exceptions\FacebookPageNotConnected;
use WBoost\Web\Exceptions\FacebookPagePermissionMissing;
use WBoost\Web\Exceptions\FacebookPageRateLimited;
use WBoost\Web\Exceptions\FacebookPageTokenExpired;
use WBoost\Web\Exceptions\FacebookPermissionMissing;
use WBoost\Web\Exceptions\FacebookTokenExpired;
use WBoost\Web\Exceptions\InstagramNotConnected;
use WBoost\Web\Exceptions\InstagramPermissionMissing;
use WBoost\Web\Exceptions\InstagramRateLimited;
use WBoost\Web\Exceptions\InstagramTokenExpired;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Message\SocialAccount\MarkSocialAccountNeedsReconnect;
use WBoost\Web\Message\SocialPost\RecordSocialPost;
use WBoost\Web\Services\AiScan\Template\UnfilledExportGuard;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Meta\GetFacebookDestinations;
use WBoost\Web\Services\ReleaseSessionLock;
use WBoost\Web\Services\Meta\PublishToFacebookPage;
use WBoost\Web\Services\FacebookPage\FacebookPagePublishing;
use WBoost\Web\Services\FacebookPage\ProjectFacebookPageConnection;
use WBoost\Web\Services\FacebookPage\PublishToProjectFacebookPage;
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
use WBoost\Web\Value\SocialPlatform;

/**
 * Direct publish of a filled variant to the PROJECT's Instagram account or
 * Facebook Page (project_social_account, Facebook Login for Business) — or,
 * the older path, to one of the user's own Facebook Pages (personal Facebook
 * link, `targetId` validated against the user's OWN pages). Same POST body as
 * the download endpoint (the JS re-posts the fill form via fetch) plus
 * `platform`, `caption` and `targetId`. `platform=facebook` WITHOUT a
 * `targetId` = the project's Page (needs FacebookPagePublishing); the
 * project destinations are never posted, they are resolved from the variant's
 * project. A 9:16 variant goes to Instagram as a story.
 * Responds JSON — the fill page stays put, no state is lost.
 *
 * Every format can be published (2026-11-27): Instagram gets a picture outside
 * its feed ratios padded (`InstagramImageFit`), Facebook takes any size.
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
        private readonly FacebookPagePublishing $facebookPagePublishing,
        private readonly ProjectFacebookPageConnection $facebookPage,
        private readonly PublishToProjectFacebookPage $publishToProjectFacebookPage,
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
        $platform = $request->request->getString('platform');
        $targetId = $request->request->getString('targetId');
        $caption = trim($request->request->getString('caption'));

        $projectPage = $platform === 'facebook' && $targetId === '';

        if (!in_array($platform, ['facebook', 'instagram'], true) || ($projectPage && !$this->facebookPagePublishing->isAvailable())) {
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
            return $this->publishToInstagram($variant, $request, $caption, $user);
        }

        if ($projectPage) {
            return $this->publishToProjectPage($variant, $request, $caption, $user);
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

        $fillValues = $this->recordPublish($variant, $request, ExportChannel::Facebook);
        // The personal-link path posts to the user's own Page — still a post
        // of this project's template, so it is remembered too.
        $this->recordSocialPost($variant, $user, SocialPlatform::Facebook, 'feed', $page->id, $page->name, $postId, 'https://www.facebook.com/' . $postId, $caption, $fillValues, ['pageId' => $page->id, 'postId' => $postId]);

        return new JsonResponse(['ok' => true, 'platform' => $platform, 'postId' => $postId, 'account' => $page->name]);
    }

    private function publishToInstagram(TemplateVariant $variant, Request $request, string $caption, User $user): Response
    {
        if (!$this->instagramPublishing->isAvailable()) {
            return new JsonResponse(['error' => 'Publikování na Instagram zatím není k dispozici.'], Response::HTTP_FORBIDDEN);
        }

        $kind = $variant->dimension->instagramPostKind();

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

        $fillValues = $this->recordPublish($variant, $request, ExportChannel::Instagram);
        $this->recordSocialPost($variant, $user, SocialPlatform::Instagram, $publication->kind->value, $account->accountId, '@' . ($account->username ?? $account->accountId), $publication->mediaId, $publication->permalink, $kind->supportsCaption() ? $caption : '', $fillValues, $publication->response);

        return new JsonResponse([
            'ok' => true,
            'platform' => 'instagram',
            'postId' => $publication->mediaId,
            'permalink' => $publication->permalink,
            'kind' => $publication->kind->value,
            'account' => $account->username,
        ]);
    }

    private function publishToProjectPage(TemplateVariant $variant, Request $request, string $caption, User $user): Response
    {
        $account = $this->facebookPage->usable($variant->template->project);

        if ($account === null) {
            return new JsonResponse(
                ['error' => (new FacebookPageNotConnected())->userMessage(), 'reconnect' => true],
                Response::HTTP_CONFLICT,
            );
        }

        try {
            $publication = $this->publishToProjectFacebookPage->publish($account, $this->renderVariant($variant, $request), $caption);
        } catch (FacebookPageNotConnected | FacebookPageTokenExpired | FacebookPagePermissionMissing $exception) {
            return new JsonResponse(['error' => $exception->userMessage(), 'reconnect' => true], Response::HTTP_CONFLICT);
        } catch (FacebookPageRateLimited $exception) {
            return new JsonResponse(['error' => $exception->userMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (MetaApiError $exception) {
            $this->logger->error('Publishing to the project Facebook Page failed.', [
                'exception' => $exception,
                'variantId' => $variant->id->toString(),
                'projectId' => $variant->template->project->id->toString(),
            ]);

            return new JsonResponse(['error' => $exception->userMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $fillValues = $this->recordPublish($variant, $request, ExportChannel::Facebook);
        $this->recordSocialPost($variant, $user, SocialPlatform::Facebook, 'feed', $account->accountId, $account->name ?? $account->accountId, $publication->postId, $publication->permalink, $caption, $fillValues, $publication->response);

        return new JsonResponse([
            'ok' => true,
            'platform' => 'facebook',
            'postId' => $publication->postId,
            'permalink' => $publication->permalink,
            'kind' => 'feed',
            'account' => $account->name ?? '',
        ]);
    }

    /**
     * Usage event + export version, like every export. Returns the fill
     * values so the social post can point at the same version (its hash).
     */
    private function recordPublish(TemplateVariant $variant, Request $request, ExportChannel $channel): ExportFillValues
    {
        $this->recordExportUsage->record($variant, $channel);
        $fillValues = ExportFillValues::fromVariantWebForm(
            $request->request->all('textValues'),
            $request->request->all('hiddenValues'),
            $request->request->all('images'),
            $request->request->all('fontValues'),
            $request->request->all('shapeColorValues'),
        )->withRows($this->fillFormParser->parseRowCounts($request, $variant))
            ->withOffsets([$variant->id->toString() => $this->fillFormParser->parseOffsets($request, RepeatableRows::expand($variant))]);
        $this->recordExportVersion->recordVariant($variant, $channel, $fillValues, $request->request->getString('draftVersionId'));

        return $fillValues;
    }

    /**
     * "Publikované příspěvky": the post with the ids Meta returned. Never
     * fails the publish — the post is live either way.
     *
     * @param array<string, scalar|null> $response
     */
    private function recordSocialPost(
        TemplateVariant $variant,
        User $user,
        SocialPlatform $platform,
        string $kind,
        string $accountId,
        string $accountName,
        string $externalId,
        null|string $permalink,
        string $caption,
        ExportFillValues $fillValues,
        array $response,
    ): void {
        try {
            $this->bus->dispatch(new RecordSocialPost(
                $variant->id,
                $user->id,
                $platform,
                $kind,
                $accountId,
                $accountName,
                $externalId,
                $permalink,
                $caption,
                $fillValues->hash(),
                $response,
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('Recording a published social post failed.', [
                'exception' => $exception,
                'variantId' => $variant->id->toString(),
                'platform' => $platform->value,
                'externalId' => $externalId,
            ]);
        }
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
