<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\AiScan\RequestAiScan;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\AiScanAccess;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\AiScan\AiScanChannel;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanRefusal;

/**
 * The upload: `POST /project/{projectId}/ai-scan` (multipart: `picture`,
 * `kind`, `requestId`, `consent`, `options`, `calendarId`, `_token`).
 *
 * **Start permission, enforced here and again in the handler**: the caller
 * must be entitled ({@see AiScanAccess::isEntitled()}) — otherwise
 * `not_enabled`, before the file is even looked at; the operating mode and
 * every limit answer with their own code. Answers are JSON either way.
 */
final class StartAiScanController extends AbstractController
{
    use AiScanResponses;

    public const string CSRF_TOKEN_ID = 'ai_scan';

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private AiScanAccess $access,
        readonly private AiScanRepository $repository,
        readonly private AiScans $scans,
    ) {
    }

    #[Route(path: '/project/{projectId}/ai-scan', name: 'ai_scan_start', requirements: ['projectId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        $user = $this->getUser();
        assert($user instanceof User);

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$this->access->isEntitled($user, $project)) {
            return $this->refusal(AiScanRefusal::NotEnabled);
        }

        $kind = AiScanKind::tryFrom($request->request->getString('kind', AiScanKind::Template->value));
        $picture = $request->files->get('picture');

        if ($kind === null || !$picture instanceof UploadedFile || !$picture->isValid()) {
            return $this->refusal(AiScanRefusal::UnsupportedImage, ['reason' => 'unreadable']);
        }

        $calendarId = $request->request->getString('calendarId');

        $result = $this->attempt($this->bus, new RequestAiScan(
            scanId: $this->provideIdentity->next(),
            requestId: $request->request->getString('requestId'),
            projectId: $project->id,
            userId: $user->id,
            kind: $kind,
            channel: AiScanChannel::Web,
            filePath: $picture->getPathname(),
            originalName: $picture->getClientOriginalName(),
            options: self::options($request->request->getString('options')),
            consentGiven: $request->request->getBoolean('consent'),
            calendarId: Uuid::isValid($calendarId) ? Uuid::fromString($calendarId) : null,
        ));

        if ($result instanceof JsonResponse) {
            return $result;
        }

        assert($result instanceof UuidInterface);

        return new JsonResponse(['ok' => true, 'scan' => $this->scans->view($this->repository->get($result))], Response::HTTP_CREATED);
    }

    /**
     * The start options: a small flat object of scalars — nothing here is
     * trusted or interpreted by the app, it travels to the scanner.
     *
     * @return array<string, bool|int|string>
     */
    private static function options(string $json): array
    {
        $decoded = $json === '' ? [] : json_decode($json, true);
        $options = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $key) === 1 && count($options) < 20
                && (is_bool($value) || is_int($value) || (is_string($value) && preg_match('/^[A-Za-z0-9_.\-]{0,64}$/', $value) === 1))) {
                $options[$key] = $value;
            }
        }

        return $options;
    }
}
