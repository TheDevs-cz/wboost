<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Authentication;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use WBoost\Web\Exceptions\AccessAlreadyRequested;
use WBoost\Web\Exceptions\EmailAlreadyRegistered;
use WBoost\Web\FormData\RequestAccessFormData;
use WBoost\Web\FormType\RequestAccessFormType;
use WBoost\Web\Message\User\RequestAccess;

final class RegistrationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/registration', name: 'registration')]
    public function __invoke(Request $request, #[CurrentUser] null|UserInterface $user = null): Response
    {
        if ($user !== null) {
            return $this->redirectToRoute('homepage');
        }

        $formData = new RequestAccessFormData();
        $form = $this->createForm(RequestAccessFormType::class, $formData);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->bus->dispatch(new RequestAccess($formData->email));

                $this->addFlash('success', 'Děkujeme, brzy se vám ozveme.');

                return $this->redirectToRoute('login');
            } catch (HandlerFailedException $failedException) {
                $realException = $failedException->getPrevious();

                if ($realException instanceof AccessAlreadyRequested) {
                    $this->addFlash('info', 'Tento e-mail už o registraci požádal. Brzy se vám ozveme.');

                    return $this->redirectToRoute('registration');
                }

                // Treat an already-registered e-mail as a neutral success so we don't
                // leak account existence.
                if ($realException instanceof EmailAlreadyRegistered) {
                    $this->addFlash('success', 'Děkujeme, brzy se vám ozveme.');

                    return $this->redirectToRoute('login');
                }

                throw $realException ?? $failedException;
            }
        }

        return $this->render('registration.html.twig', [
            'form' => $form,
        ]);
    }
}
