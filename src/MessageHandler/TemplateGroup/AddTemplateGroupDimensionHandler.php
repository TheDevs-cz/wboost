<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateGroup;

use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateGroupNotFound;
use WBoost\Web\Message\TemplateGroup\AddTemplateGroupDimension;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\BackgroundMode;

#[AsMessageHandler]
readonly final class AddTemplateGroupDimensionHandler
{
    public function __construct(
        private TemplateGroupRepository $templateGroupRepository,
        private GetTemplateGroupMembers $members,
        private TemplateRepository $templateRepository,
        private TemplateVariantRepository $variantRepository,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
        private Filesystem $filesystem,
        private BackgroundLayer $backgroundLayer,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @throws TemplateGroupNotFound
     */
    public function __invoke(AddTemplateGroupDimension $message): void
    {
        $group = $this->templateGroupRepository->get($message->groupId);
        $template = $this->members->template($group->id);

        // A group without a template (its template was deleted) gets one lazily.
        if ($template === null) {
            $template = new Template(
                $this->provideIdentity->next(),
                $group->project,
                null,
                $this->clock->now(),
                $group->name,
                null,
                $this->templateRepository->count($group->project->id),
            );

            $template->assignToGroup($group);
            $this->templateRepository->add($template);
        }

        $variantId = $message->variantId;

        // New dimensions are layer-mode: the (optional) background is a
        // regular canvas object seeded below, not the canvas-level slot.
        $backgroundImagePath = null;
        $canvas = null;

        [$bytes, $extension] = $this->backgroundSource($message->backgroundImage, $group->id);

        if ($bytes !== null) {
            $timestamp = $this->clock->now()->getTimestamp();

            $backgroundImagePath = "custom-templates/$variantId/background-$timestamp.$extension";
            $this->filesystem->write($backgroundImagePath, $bytes);

            $size = getimagesizefromstring($bytes);

            $canvas = $this->backgroundLayer->applyToCanvas('{}', $this->backgroundLayer->buildObject(
                $this->uploaderHelper->getPublicPath($backgroundImagePath),
                $backgroundImagePath,
                is_array($size) ? $size[0] : null,
                is_array($size) ? $size[1] : null,
                $message->dimension->width(),
                $message->dimension->height(),
            ));
        }

        $variant = new TemplateVariant(
            $variantId,
            $template,
            $message->dimension,
            $backgroundImagePath,
            $this->clock->now(),
            BackgroundMode::Layer,
        );

        if ($canvas !== null) {
            $variant->editCanvas($canvas, [], null);
        }

        $variant->assignToGroup($group);
        $this->variantRepository->add($variant);
    }

    /**
     * The background bytes for a new dimension. Without an upload it INHERITS
     * the group's existing background picture: a dimension that silently ends
     * up with no background renders its design over transparency, and whatever
     * full-canvas artwork sits lowest reads as the background — the layer
     * stack looks scrambled even though the object order is identical to every
     * other dimension.
     *
     * Only the PICTURE is shared. Each variant gets its own copy of the file
     * (so a later change on one dimension never reaches the others) and the
     * cover fit is computed from scratch for this dimension's canvas — cover
     * is an absolute function of (image, canvas size), never a scaled copy.
     *
     * @return array{null|string, string} bytes (null = no background), extension
     */
    private function backgroundSource(null|UploadedFile $upload, UuidInterface $groupId): array
    {
        if ($upload !== null) {
            return [$upload->getContent(), $upload->guessExtension() ?? 'png'];
        }

        foreach ($this->members->variants($groupId) as $member) {
            if ($member->backgroundImage === null) {
                continue;
            }

            $extension = pathinfo($member->backgroundImage, PATHINFO_EXTENSION);

            return [
                $this->filesystem->read($member->backgroundImage),
                $extension !== '' ? $extension : 'png',
            ];
        }

        return [null, 'png'];
    }
}
