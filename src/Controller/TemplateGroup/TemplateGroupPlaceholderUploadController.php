<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\TemplateGroup;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\SocialNetwork\PlaceholderImageUploader;

/**
 * "Upload your own image" for the group fill & export page — the group-scoped
 * counterpart of the per-variant web upload endpoints.
 *
 * The group form is unified by inputId UUID, so the upload is too: the slot
 * definition is taken from the FIRST member variant carrying that id (the same
 * first-occurrence-wins rule {@see GroupFillPlaceholders} uses to build the
 * form), and the shared {@see PlaceholderImageUploader} then validates the
 * placeholder + target folder exactly as it does for a single variant. The
 * stored file lands in the project gallery, so every member variant can
 * reference it afterwards.
 */
final class TemplateGroupPlaceholderUploadController extends AbstractController
{
    public function __construct(
        readonly private GetTemplateGroupMembers $members,
        readonly private PlaceholderImageUploader $uploader,
    ) {
    }

    #[Route(
        path: '/template-group/{groupId}/placeholders/{inputId}/upload',
        name: 'template_group_placeholder_upload',
        methods: ['POST'],
    )]
    #[IsGranted(TemplateGroupVoter::EDIT, 'group')]
    public function __invoke(
        #[MapEntity(id: 'groupId')]
        TemplateGroup $group,
        string $inputId,
        Request $request,
    ): Response {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Missing "file" upload.');
        }

        $variant = $this->resolveSlotVariant($group, $inputId);

        if ($variant === null) {
            throw $this->createNotFoundException('No variant of this group has such an image placeholder.');
        }

        return $this->json($this->uploader->upload(
            $variant,
            $inputId,
            $file,
            $request->request->has('directoryId') ? (string) $request->request->get('directoryId') : null,
        ));
    }

    private function resolveSlotVariant(TemplateGroup $group, string $inputId): null|SocialNetworkTemplateVariant|CustomTemplateVariant
    {
        /** @var list<SocialNetworkTemplateVariant|CustomTemplateVariant> $variants */
        $variants = [...$this->members->socialVariants($group->id), ...$this->members->customVariants($group->id)];

        foreach ($variants as $variant) {
            foreach ($variant->imageInputs as $input) {
                if ($input->inputId === $inputId) {
                    return $variant;
                }
            }
        }

        return null;
    }
}
