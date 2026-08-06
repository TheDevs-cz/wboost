<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\OAuth2;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\OAuth2\ApproveOAuthClient;
use WBoost\Web\Services\OAuth2\ConsentSession;
use WBoost\Web\Services\OAuth2\DescribeConsentScopes;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\ConsentDecision;

/**
 * The consent screen — the interstitial that turns "a logged-in user opened a
 * link" into "a user agreed to give this application these permissions"
 * (S8-T5).
 *
 * It is deliberately NOT part of the OAuth endpoint. `/api/authorize` is
 * league's stateless controller and stays that way;
 * {@see \WBoost\Web\Services\OAuth2\ResolveAuthorizationRequestListener} parks
 * the request in the session and sends the browser here, and this action sends
 * it straight back with the answer recorded. Everything OAuth-shaped — the PKCE
 * challenge, the `state`, the redirect URI — is replayed verbatim from the
 * SERVER-side copy, so nothing in this form can redirect the user anywhere they
 * did not just come from.
 *
 * Both outcomes go back through `/api/authorize`, including the refusal: the
 * client is entitled to the RFC-correct `access_denied` at its own redirect URI
 * (with its `state`), and league is what knows how to build it. Answering a
 * denial with a wboost error page instead would leave a connector hanging on a
 * screen its user has already left.
 */
final class ConsentController extends AbstractController
{
    /**
     * The token id of the consent form. This form grants access to the user's
     * data — a cross-site POST that could approve an application would hand the
     * whole screen's purpose back to the attacker.
     */
    private const string CSRF_TOKEN_ID = 'oauth_consent';

    public function __construct(
        readonly private ConsentSession $consentSession,
        readonly private DescribeConsentScopes $describeConsentScopes,
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/oauth/consent', name: 'oauth_consent', methods: ['GET', 'POST'])]
    #[IsGranted(AuthenticatedVoter::IS_AUTHENTICATED_FULLY)]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $pending = $this->consentSession->pending();

        // No parked request: a bookmarked URL, a reloaded page after the
        // decision was already made, or an expired session. There is nothing to
        // answer and nothing to redirect to — the connector will have to start
        // the flow again — so say so instead of rendering an empty form that
        // cannot do anything.
        if ($pending === null) {
            $this->addFlash('warning', 'Požadavek na propojení aplikace už neplatí. Zkuste propojení spustit znovu z dané aplikace.');

            return $this->redirectToRoute('homepage');
        }

        if ($request->isMethod(Request::METHOD_POST)) {
            if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            // Anything that is not an explicit approval is a refusal — the
            // fail-closed reading of a form whose two buttons differ by one
            // parameter.
            $approved = $request->request->has('approve');

            if ($approved) {
                $this->bus->dispatch(new ApproveOAuthClient(
                    $this->provideIdentity->next(),
                    $user->id,
                    $pending->clientIdentifier,
                    // The EFFECTIVE set — exactly the lines the screen showed,
                    // implications included. See DescribeConsentScopes.
                    $this->describeConsentScopes->effectiveValues($pending->scopes),
                ));
            }

            $this->consentSession->recordDecision(
                $user->id->toString(),
                $pending,
                $approved ? ConsentDecision::Approved : ConsentDecision::Denied,
            );

            return $this->redirect($this->generateUrl('oauth2_authorize') . '?' . $pending->authorizeQuery);
        }

        return $this->render('oauth_consent.html.twig', [
            'pending' => $pending,
            'scopes' => $this->describeConsentScopes->describe($pending->scopes),
            'csrfTokenId' => self::CSRF_TOKEN_ID,
        ]);
    }
}
