<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Mcp\Design\DesignAsset;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;

/**
 * The stand-in picture for places a scan leaves empty.
 *
 * A scan never takes a picture out of the source: every picture becomes a
 * place the user fills later. But a candidate has to render, and a fillable
 * full-page background must carry a picture (the compiler refuses one
 * without). So in **candidate renders** the reserved asset id
 * {@see ASSET_ID} resolves to one bundled neutral picture — an extra entry
 * in the compilation context that only the scan render path adds
 * ({@see \WBoost\Web\Mcp\Design\DesignPreflight::review()}'s `$extraAssets`).
 * No existing template, fill page or export sees it.
 *
 * The picture is one shared object in the public bucket, written on first
 * use from `resources/ai-scan/stand-in.png` (the renderer inlines images by
 * storage key). At accept (P3) empty places reference a per-project gallery
 * row of {@see \WBoost\Web\Value\FileSource::ScanStandIn} instead.
 */
final readonly class AiScanStandIn
{
    /** Reserved: never a real gallery id (those are UUIDv7). */
    public const string ASSET_ID = '00000000-0000-4000-8000-0000000a15ca';
    public const string PATH = 'ai-scan/stand-in-v1.png';
    public const int WIDTH = 1200;
    public const int HEIGHT = 1200;

    public function __construct(
        #[Autowire(service: 'oneup_flysystem.minio_filesystem')]
        private FilesystemOperator $filesystem,
        private UploaderHelper $uploaderHelper,
        #[Autowire('%kernel.project_dir%/resources/ai-scan/stand-in.png')]
        private string $bundledFile,
        private FileUploadRepository $files,
        private ProvideIdentity $provideIdentity,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The project's own stand-in row — what places still empty reference
     * once a proposal became a template. One per project, created on first
     * use: a copy of the bundled picture under the project's gallery prefix
     * (so project deletion and the storage inventory treat it like any other
     * file of the project), with {@see FileSource::ScanStandIn} — which no
     * gallery listing shows and the bin never takes.
     *
     * @throws FilesystemException
     */
    public function projectFile(Project $project): FileUpload
    {
        $existing = $this->findProjectFile($project);

        if ($existing !== null) {
            return $existing;
        }

        $id = $this->provideIdentity->next();
        $path = sprintf('file-upload/%s/%s.png', $project->id->toString(), $id->toString());
        $bytes = file_get_contents($this->bundledFile);

        if ($bytes === false) {
            throw new \RuntimeException('The bundled AI scan stand-in picture is missing.');
        }

        $this->filesystem->write($path, $bytes);

        $file = new FileUpload($id, $project, $this->clock->now(), FileSource::ScanStandIn, $path, null, 'zastupny-obrazek.png', self::WIDTH, self::HEIGHT);
        $this->files->add($file);

        return $file;
    }

    public function findProjectFile(Project $project): null|FileUpload
    {
        return $this->files->listByProjectAndSource($project->id, FileSource::ScanStandIn)[0] ?? null;
    }

    /**
     * @return array<string, DesignAsset>
     */
    public function assets(): array
    {
        $this->ensureStored();

        return [
            self::ASSET_ID => new DesignAsset(
                id: self::ASSET_ID,
                path: self::PATH,
                url: $this->uploaderHelper->getPublicPath(self::PATH),
                width: self::WIDTH,
                height: self::HEIGHT,
            ),
        ];
    }

    private function ensureStored(): void
    {
        try {
            if ($this->filesystem->fileExists(self::PATH)) {
                return;
            }

            $bytes = file_get_contents($this->bundledFile);

            if ($bytes !== false) {
                $this->filesystem->write(self::PATH, $bytes);
            }
        } catch (FilesystemException) {
            // The render then shows the place empty; never a reason to fail it.
        }
    }
}
