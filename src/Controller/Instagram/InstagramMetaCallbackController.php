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
 * Meta's server-to-server callbacks for the Instagram product (App Dashboard
 * → Instagram → API setup with Instagram login → Business login settings).
 * Both POST a `signed_request` signed with the INSTAGRAM app secret:
 *
 * - Deauthorize: the person removed WBoost from their Instagram account →
 *   every project connection to it is dropped (the token is dead anyway).
 * - Data deletion: the person asked for their data to be deleted → same,
 *   answered with a status URL + confirmation code.
 */
final class InstagramMetaCallbackController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        #[Autowire('%env(INSTAGRAM_APP_SECRET)%')]
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
