<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Fonts;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use WBoost\Web\Entity\User;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\UploaderHelper;

/**
 * @implements ProviderInterface<ProjectFontResponse>
 */
final readonly class ProjectFontsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private ProjectRepository $projectRepository,
        private GetFonts $getFonts,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     * @return list<ProjectFontResponse>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        $projectId = $uriVariables['projectId'] ?? null;

        if (!is_string($projectId) || !Uuid::isValid($projectId)) {
            throw new BadRequestHttpException('Invalid project id.');
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw new NotFoundHttpException();
        }

        // Same visibility rule as the web UI (ProjectVoter): owner, admin, or
        // a user the project is shared with. 404 (not 403) so foreign
        // projects' existence isn't leaked.
        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw new NotFoundHttpException();
        }

        $responses = [];

        foreach ($this->getFonts->allForProject($project->id) as $font) {
            foreach ($font->faces as $face) {
                // Family string parity with the canvases / ResolveRichTextOptions:
                // "FontName (FaceName)" is what textStyle.fontFamily carries.
                $responses[] = new ProjectFontResponse(
                    family: sprintf('%s (%s)', $font->name, $face->name),
                    fontName: $font->name,
                    faceName: $face->name,
                    weight: $face->weight,
                    style: $face->style,
                    url: $this->uploaderHelper->getPublicPath($face->filePath),
                );
            }
        }

        return $responses;
    }
}
