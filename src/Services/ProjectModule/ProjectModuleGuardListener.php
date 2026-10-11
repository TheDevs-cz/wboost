<?php

declare(strict_types=1);

namespace WBoost\Web\Services\ProjectModule;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Attribute\RequiresProjectModule;
use WBoost\Web\Entity\Project;

/**
 * Sends a request for a switched-off module's landing page to the project's
 * "Objevte další moduly" page, where the module can be switched on. Runs on
 * CONTROLLER_ARGUMENTS so the Project is already resolved (and the
 * #[IsGranted] check already passed — it listens earlier on the same event).
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: -10)]
final readonly class ProjectModuleGuardListener
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $attributes = $event->getAttributes(RequiresProjectModule::class);
        if ($attributes === []) {
            return;
        }

        $project = null;
        foreach ($event->getArguments() as $argument) {
            if ($argument instanceof Project) {
                $project = $argument;
                break;
            }
        }

        if ($project === null) {
            return;
        }

        foreach ($attributes as $attribute) {
            if ($project->hasModule($attribute->module)) {
                continue;
            }

            $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add(
                    'info',
                    sprintf('Modul %s není v tomto projektu zapnutý.', $attribute->module->label()),
                );
            }

            $redirect = new RedirectResponse($this->urlGenerator->generate('project_modules', [
                'projectId' => $project->id->toString(),
            ]));

            $event->setController(static fn (): RedirectResponse => $redirect);
            $event->setArguments([]);

            return;
        }
    }
}
