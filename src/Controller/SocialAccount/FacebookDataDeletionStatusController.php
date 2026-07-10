<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\SocialAccount;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public page Meta links people to after a data-deletion request (the `url`
 * we return from the deletion callback). Deletion is synchronous, so this
 * only ever confirms completion.
 */
final class FacebookDataDeletionStatusController extends AbstractController
{
    #[Route(path: '/oauth/facebook/data-deletion/status', name: 'facebook_data_deletion_status', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('facebook_data_deletion_status.html.twig', [
            'confirmationCode' => $request->query->getString('code'),
        ]);
    }
}
