<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialNetwork;

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
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\FacebookNotConnected;
use WBoost\Web\Exceptions\FacebookPermissionMissing;
use WBoost\Web\Exceptions\FacebookTokenExpired;
use WBoost\Web\Exceptions\InstagramRateLimited;
use WBoost\Web\Exceptions\MetaApiError;
use WBoost\Web\Message\SocialAccount\MarkSocialAccountNeedsReconnect;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\Meta\GetFacebookDestinations;
use WBoost\Web\Services\Meta\PublishToFacebookPage;
use WBoost\Web\Services\Meta\PublishToInstagram;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\FillFormRequestParser;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\Usage\RecordExportUsage;
use WBoost\Web\Value\ExportChannel;

/**
 * Direct publish of a filled variant to a Facebook Page or the Instagram
 * professional account linked to one. Same POST body as the download
 * endpoint (the JS re-posts the fill form via fetch) plus `platform`,
 * `targetId` (page id, validated against the user's OWN pages) and
 * `caption`. Responds JSON — the fill page stays put, no state is lost.
 *
 * Deliberately synchronous: the user needs actionable feedback (expired
 * token → reconnect CTA), and Messenger's retrying async transport could
 * double-post. The response is buffered (FrankenPHP-safe).
 */
final class SocialNetworkTemplateVariantPublishController extends AbstractController
{
    public function __construct(
        private readonly TemplateVariantImageRendererInterface $renderer,
        private readonly ResolveTextOverrides $resolveTextOverrides,
        private readonly ResolveRichTextOptions $resolveRichTextOptions,
        private readonly ResolveImageOverrides $resolveImageOverrides,
        private readonly FillFormRequestParser $fillFormParser,
        private readonly GetFacebookDestinations $destinations,
        private readonly PublishToFacebookPage $publishToFacebookPage,
        private readonly PublishToInstagram $publishToInstagram,
        private readonly RecordExportUsage $recordExportUsage,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/social-network-template-variant/{variantId}/publish',
        name: 'social_network_template_variant_publish',
        methods: ['POST'],
    )]
    #[IsGranted(SocialNetworkTemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        SocialNetworkTemplateVariant $variant,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        $platform = $request->request->getString('platform');
        $targetId = $request->request->getString('targetId');
        $caption = trim($request->request->getString('caption'));

        if (!in_array($platform, ['facebook', 'instagram'], true) || $targetId === '') {
            return new JsonResponse(['error' => 'Neplatný požadavek na publikování.'], Response::HTTP_BAD_REQUEST);
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

            if ($platform === 'instagram' && !$page->hasInstagram()) {
                return new JsonResponse(
                    ['error' => 'Vybraná stránka nemá propojený instagramový profesionální účet.'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $pngBytes = $this->renderVariant($variant, $request);

            $postId = $platform === 'facebook'
                ? $this->publishToFacebookPage->publish($page, $pngBytes, $caption)
                : $this->publishToInstagram->publish($page, $pngBytes, $caption);
        } catch (FacebookNotConnected $exception) {
            return new JsonResponse(['error' => $exception->userMessage(), 'reconnect' => true], Response::HTTP_CONFLICT);
        } catch (FacebookTokenExpired $exception) {
            $this->bus->dispatch(new MarkSocialAccountNeedsReconnect($account->id->toString()));

            return new JsonResponse(['error' => $exception->userMessage(), 'reconnect' => true], Response::HTTP_CONFLICT);
        } catch (FacebookPermissionMissing $exception) {
            return new JsonResponse(['error' => $exception->userMessage(), 'reconnect' => true], Response::HTTP_CONFLICT);
        } catch (InstagramRateLimited $exception) {
            return new JsonResponse(['error' => $exception->userMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (MetaApiError $exception) {
            $this->logger->error('Publishing to a social network failed.', [
                'exception' => $exception,
                'variantId' => $variant->id->toString(),
                'platform' => $platform,
            ]);

            return new JsonResponse(['error' => $exception->userMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $this->recordExportUsage->record(
            $variant,
            $platform === 'facebook' ? ExportChannel::Facebook : ExportChannel::Instagram,
        );

        return new JsonResponse(['ok' => true, 'platform' => $platform, 'postId' => $postId]);
    }

    /**
     * The exact same render the download endpoint produces (lenient overflow
     * — the fill page blocks publishing client-side while a container
     * overflows, mirroring the export button).
     */
    private function renderVariant(SocialNetworkTemplateVariant $variant, Request $request): string
    {
        $overrides = $this->resolveTextOverrides->resolve(
            $variant->inputs,
            $this->fillFormParser->parseTextValues($request),
            truncateOverflow: true,
            richTextOptions: $this->resolveRichTextOptions->forVariant($variant),
        );

        $imageOverrides = $this->resolveImageOverrides->resolve(
            $variant->imageInputs,
            $variant->template->project->id,
            $this->fillFormParser->parseImageValues($request),
        );

        return $this->renderer->renderToBytes($variant, $overrides, $imageOverrides);
    }
}
