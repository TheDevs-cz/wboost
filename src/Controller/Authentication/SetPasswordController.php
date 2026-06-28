<?php
declare(strict_types=1);

namespace WBoost\Web\Controller\Authentication;

use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Exceptions\InvalidPasswordResetToken;
use WBoost\Web\FormData\SetPasswordFormData;
use WBoost\Web\FormType\SetPasswordFormType;
use WBoost\Web\Message\User\ResetPassword;
use WBoost\Web\Repository\PasswordResetTokenRepository;

final class SetPasswordController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private PasswordResetTokenRepository $passwordResetTokenRepository,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(path: '/set-password/{token}', name: 'set_password')]
    public function __invoke(string $token, Request $request): Response
    {
        try {
            $resetToken = $this->passwordResetTokenRepository->getValid($token, $this->clock->now());
        } catch (InvalidPasswordResetToken) {
            return $this->render('set_password.html.twig', [
                'invalid' => true,
            ]);
        }

        // An invitee's password is still empty until they activate the account here.
        $isInvitation = $resetToken->user->password === '';

        $formData = new SetPasswordFormData();
        $form = $this->createForm(SetPasswordFormType::class, $formData);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->bus->dispatch(
                    new ResetPassword($token, $formData->password),
                );

                $this->addFlash('success', $isInvitation
                    ? 'Účet byl aktivován a jste přihlášeni.'
                    : 'Vaše heslo bylo změněno a jste přihlášeni.');

                return $this->redirectToRoute('homepage');
            } catch (HandlerFailedException $failedException) {
                $realException = $failedException->getPrevious();

                if ($realException instanceof InvalidPasswordResetToken) {
                    $this->addFlash('danger', 'Odkaz pro nastavení hesla je neplatný nebo vypršel.');

                    return $this->redirectToRoute('forgotten_password');
                }

                throw $realException ?? $failedException;
            }
        }

        return $this->render('set_password.html.twig', [
            'invalid' => false,
            'isInvitation' => $isInvitation,
            'form' => $form,
        ]);
    }
}
