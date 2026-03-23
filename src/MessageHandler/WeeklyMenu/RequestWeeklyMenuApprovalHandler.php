<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use WBoost\Web\Entity\WeeklyMenuApprovalAuditLog;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\RequestWeeklyMenuApproval;
use WBoost\Web\Repository\WeeklyMenuApprovalAuditLogRepository;
use WBoost\Web\Repository\WeeklyMenuRepository;
use WBoost\Web\Services\ProvideIdentity;

#[AsMessageHandler]
readonly final class RequestWeeklyMenuApprovalHandler
{
    public function __construct(
        private WeeklyMenuRepository $weeklyMenuRepository,
        private WeeklyMenuApprovalAuditLogRepository $auditLogRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     */
    public function __invoke(RequestWeeklyMenuApproval $message): void
    {
        $menu = $this->weeklyMenuRepository->get($message->menuId);

        if ($menu->approvalEmail === null) {
            throw new \LogicException('Cannot request approval without approval email configured.');
        }

        $hash = bin2hex(random_bytes(32));
        $menu->requestApproval($hash, $message->requestedByEmail);

        $auditLog = new WeeklyMenuApprovalAuditLog(
            $this->provideIdentity->next(),
            $menu,
            $this->clock->now(),
            'Odeslána žádost o schválení na ' . $menu->approvalEmail,
            $message->requestedByEmail,
        );
        $this->auditLogRepository->save($auditLog);

        $approvalUrl = $this->urlGenerator->generate('weekly_menu_approval', [
            'menuId' => $menu->id,
            'hash' => $hash,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $publicUrl = $this->urlGenerator->generate('public_weekly_menu', [
            'menuId' => $menu->id,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $html = $this->twig->render('emails/weekly_menu_approval_request.html.twig', [
            'menu' => $menu,
            'approvalUrl' => $approvalUrl,
            'publicUrl' => $publicUrl,
            'requestedByEmail' => $message->requestedByEmail,
        ]);

        assert($menu->approvalEmail !== null);

        $email = (new Email())
            ->to($menu->approvalEmail)
            ->subject('Žádost o schválení jídelníčku: ' . $menu->name)
            ->html($html);

        $this->mailer->send($email);
    }
}
