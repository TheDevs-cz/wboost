<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Instagram;

use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Message\Instagram\DeleteInstagramConnections;
use WBoost\Web\Services\Meta\MetaSignedRequest;

/**
 * Meta's server-to-server callbacks for the WBoost Meta app that hosts
 * Instagram Unified Login (App settings → Basic → data deletion callback;
 * Facebook Login for Business → Settings → deauthorize callback). Both POST
 * a `signed_request` signed with the META app secret
 * (`INSTAGRAM_META_APP_SECRET`). Its `user_id` is matched against both the
 * stored Instagram account id and the token owner id — which one Meta sends
 * for a Unified Login connection is not documented.
 *
 * - Deauthorize: the person removed WBoost (Instagram, or the business
 *   portfolio's connected apps) → every project connection matching the id
 *   is dropped, Instagram AND Facebook Page rows (same Meta app).
 * - Data deletion: the person asked for their data to be deleted → same,
 *   answered with a status URL + confirmation code.
 */
final class InstagramMetaCallbackController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        #[Autowire('%env(INSTAGRAM_META_APP_SECRET)%')]
        #[SensitiveParameter]
        readonly private string $appSecret,
    ) {
    }

    #[Route(path: '/oauth/instagram/deauthorize', name: 'instagram_deauthorize', methods: ['POST'])]
    public function deauthorize(Request $request): Response
    {
        $userId = MetaSignedRequest::userId($request->request->getString('signed_request'), $this->appSecret);

        if ($userId === null) {
            return new JsonResponse(['error' => 'Invalid signed_request.'], Response::HTTP_BAD_REQUEST);
        }

        $this->bus->dispatch(new DeleteInstagramConnections($userId));

        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/oauth/instagram/data-deletion', name: 'instagram_data_deletion', methods: ['POST'])]
    public function dataDeletion(Request $request): Response
    {
        $userId = MetaSignedRequest::userId($request->request->getString('signed_request'), $this->appSecret);

        if ($userId === null) {
            return new JsonResponse(['error' => 'Invalid signed_request.'], Response::HTTP_BAD_REQUEST);
        }

        $this->bus->dispatch(new DeleteInstagramConnections($userId));

        // Not a secret — just an opaque reference Meta shows to the person.
        $confirmationCode = substr(hash('sha256', 'ig-deletion-' . $userId), 0, 16);

        return new JsonResponse([
            'url' => $this->generateUrl('instagram_data_deletion_status', ['code' => $confirmationCode], UrlGeneratorInterface::ABSOLUTE_URL),
            'confirmation_code' => $confirmationCode,
        ]);
    }

    #[Route(path: '/oauth/instagram/data-deletion/status', name: 'instagram_data_deletion_status', methods: ['GET'])]
    public function status(Request $request): Response
    {
        return $this->render('facebook_data_deletion_status.html.twig', [
            'confirmationCode' => $request->query->getString('code'),
            'network' => 'instagram',
        ]);
    }
}
