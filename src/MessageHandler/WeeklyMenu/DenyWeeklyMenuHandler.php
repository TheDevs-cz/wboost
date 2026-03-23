<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\WeeklyMenu;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use WBoost\Web\Entity\WeeklyMenuApprovalAuditLog;
use WBoost\Web\Exceptions\InvalidApprovalHash;
use WBoost\Web\Exceptions\WeeklyMenuNotFound;
use WBoost\Web\Message\WeeklyMenu\DenyWeeklyMenu;
use WBoost\Web\Repository\WeeklyMenuApprovalAuditLogRepository;
use WBoost\Web\Repository\WeeklyMenuRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\WeeklyMenuApprovalStatus;

#[AsMessageHandler]
readonly final class DenyWeeklyMenuHandler
{
    public function __construct(
        private WeeklyMenuRepository $weeklyMenuRepository,
        private WeeklyMenuApprovalAuditLogRepository $auditLogRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private Environment $twig,
    ) {
    }

    /**
     * @throws WeeklyMenuNotFound
     * @throws InvalidApprovalHash
     */
    public function __invoke(DenyWeeklyMenu $message): void
    {
        $menu = $this->weeklyMenuRepository->get($message->menuId);

        if ($menu->approvalHash === null || !hash_equals($menu->approvalHash, $message->hash)) {
            throw new InvalidApprovalHash();
        }

        if ($menu->approvalStatus !== WeeklyMenuApprovalStatus::Pending) {
            throw new \LogicException('Menu is not in pending approval state.');
        }

        $menu->deny($message->comment);

        $commentText = $message->comment !== null && $message->comment !== '' ? ' - komentář: ' . $message->comment : '';
        $auditLog = new WeeklyMenuApprovalAuditLog(
            $this->provideIdentity->next(),
            $menu,
            $this->clock->now(),
            'Zamítnuto' . $commentText,
            $menu->approvalEmail,
            $message->comment,
        );
        $this->auditLogRepository->save($auditLog);

        if ($menu->requestedByEmail !== null) {
            $html = $this->twig->render('emails/weekly_menu_approval_result.html.twig', [
                'menu' => $menu,
                'result' => 'denied',
                'comment' => $message->comment,
            ]);

            $email = (new Email())
                ->from('robot@wboost.cz')
                ->to($menu->requestedByEmail)
                ->subject('Jídelníček "' . $menu->name . '" byl zamítnut')
                ->html($html);

            $this->mailer->send($email);
        }
    }
}
