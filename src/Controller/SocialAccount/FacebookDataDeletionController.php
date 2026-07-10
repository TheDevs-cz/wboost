<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialAccount;

use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Message\SocialAccount\DeleteSocialAccountByProviderUserId;
use WBoost\Web\Value\SocialProvider;

/**
 * Meta's server-to-server Data Deletion Callback (required app setting): a
 * person asked Facebook to delete the data this app holds about them. Meta
 * POSTs a `signed_request` (HMAC-SHA256, app secret); we drop the linked
 * SocialAccount and answer with a status URL + confirmation code.
 *
 * https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback/
 */
final class FacebookDataDeletionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        #[Autowire('%env(FACEBOOK_APP_SECRET)%')]
        #[SensitiveParameter]
        readonly private string $appSecret,
    ) {
    }

    #[Route(path: '/oauth/facebook/data-deletion', name: 'facebook_data_deletion', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $signedRequest = $request->request->getString('signed_request');

        $providerUserId = $this->parseSignedRequestUserId($signedRequest);

        if ($providerUserId === null) {
            return new JsonResponse(['error' => 'Invalid signed_request.'], Response::HTTP_BAD_REQUEST);
        }

        $this->bus->dispatch(new DeleteSocialAccountByProviderUserId(SocialProvider::Facebook, $providerUserId));

        // Not a secret — just an opaque reference Meta shows to the person.
        $confirmationCode = substr(hash('sha256', 'fb-deletion-' . $providerUserId), 0, 16);

        return new JsonResponse([
            'url' => $this->generateUrl(
                'facebook_data_deletion_status',
                ['code' => $confirmationCode],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'confirmation_code' => $confirmationCode,
        ]);
    }

    private function parseSignedRequestUserId(string $signedRequest): null|string
    {
        $parts = explode('.', $signedRequest, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = $parts;

        $signature = self::base64UrlDecode($encodedSignature);
        $payload = self::base64UrlDecode($encodedPayload);

        if ($signature === null || $payload === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $encodedPayload, $this->appSecret, true);

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode($payload, true);

        if (!is_array($data)) {
            return null;
        }

        $userId = $data['user_id'] ?? null;

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    private static function base64UrlDecode(string $input): null|string
    {
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
