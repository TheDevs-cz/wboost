<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Manual\EnsureManualGalleryFolder;

/**
 * The folder a manual editor's gallery picker modal opens in
 * (`_manual_gallery_modal.html.twig`): `Manuál / <manual>`, created on first
 * use so the modal's uploads land there.
 */
readonly final class ManualGalleryPicker
{
    public function __construct(
        private MessageBusInterface $bus,
        private ManualGalleryFolders $folders,
    ) {
    }

    public function directoryId(Manual $manual): null|string
    {
        $folder = $this->folders->find($manual);

        if ($folder === null) {
            $this->bus->dispatch(new EnsureManualGalleryFolder($manual->id));
            $folder = $this->folders->find($manual);
        }

        return $folder?->id->toString();
    }
}
