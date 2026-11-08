<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Font;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use WBoost\Web\Exceptions\UnsupportedFontFile;
use WBoost\Web\Value\ExpandedFontFace;
use WBoost\Web\Value\ExpandedVariableFont;

/**
 * Variable fonts (one file, `fvar` design axes + named instances such as
 * Thin…Black × Italic) are expanded at upload into one STATIC font per named
 * instance — so the rest of the app only ever sees ordinary static cuts:
 * the fonts page, the editor/fill font lists, Fabric, the Gotenberg render
 * and the print PDF behave exactly as for cuts uploaded one by one.
 *
 * Instancing is fontTools' `varLib.instancer` (pure Python, vendored as the
 * pinned wheel in resources/fonttools/ and run with the base image's
 * python3 — no image change); resources/fonttools/expand_variable_font.py
 * also rewrites each cut's name table / OS/2 / head / post the way a
 * foundry's own statics are named. Inter (18 instances) takes ~7 s on 4
 * worker processes.
 */
final readonly class VariableFontExpander
{
    private const int TIMEOUT_SECONDS = 180;

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/fonttools')]
        private string $toolsDirectory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether the file is a TTF/OTF/WOFF carrying an `fvar` table — read off
     * the table directory alone, nothing else is parsed. WOFF2 and anything
     * unreadable answer false (the ordinary upload path refuses those).
     */
    public function isVariable(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            // 44 bytes = the WOFF header; an sfnt header is the first 12.
            $header = (string) fread($handle, 44);
            if (strlen($header) < 12) {
                return false;
            }

            $signature = substr($header, 0, 4);
            if ($signature === 'wOFF') {
                $numTables = self::uint16($header, 12) ?? 0;
                $offset = 44;
                $recordSize = 20;
            } elseif (in_array($signature, ["\x00\x01\x00\x00", 'OTTO', 'true'], true)) {
                $numTables = self::uint16($header, 4) ?? 0;
                $offset = 12;
                $recordSize = 16;
            } else {
                return false;
            }

            if ($numTables < 1 || $numTables > 256) {
                return false;
            }

            if (fseek($handle, $offset) !== 0) {
                return false;
            }
            $directory = (string) fread($handle, max(1, $numTables * $recordSize));

            for ($i = 0; $i < $numTables; $i++) {
                if (substr($directory, $i * $recordSize, 4) === 'fvar') {
                    return true;
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws UnsupportedFontFile when fontTools cannot instance the file
     */
    public function expand(string $path, string $fileName = ''): ExpandedVariableFont
    {
        $fileName = $fileName !== '' ? $fileName : basename($path);
        $directory = sys_get_temp_dir() . '/wboost-varfont-' . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0o700) && !is_dir($directory)) {
            throw UnsupportedFontFile::variableNotExpandable($fileName);
        }

        $process = new Process(
            ['python3', '-I', $this->toolsDirectory . '/expand_variable_font.py', $this->wheel(), $path, $directory],
            $directory,
            null,
            null,
            self::TIMEOUT_SECONDS,
        );

        try {
            $process->run();
        } catch (\Throwable $failure) {
            $this->discardDirectory($directory);
            $this->logger->error('Variable font expansion failed to run: {error}', ['error' => $failure->getMessage(), 'file' => $fileName]);

            throw UnsupportedFontFile::variableNotExpandable($fileName);
        }

        $expanded = $process->isSuccessful() ? $this->fromManifest($process->getOutput(), $directory) : null;

        if ($expanded === null) {
            $this->discardDirectory($directory);
            $this->logger->error('Variable font expansion failed: {error}', [
                'error' => mb_substr($process->getErrorOutput(), -2000),
                'exitCode' => $process->getExitCode(),
                'file' => $fileName,
            ]);

            throw UnsupportedFontFile::variableNotExpandable($fileName);
        }

        return $expanded;
    }

    public function discard(ExpandedVariableFont $expanded): void
    {
        $this->discardDirectory($expanded->directory);
    }

    private function fromManifest(string $output, string $directory): null|ExpandedVariableFont
    {
        try {
            $manifest = json_decode($output, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($manifest) || !is_string($manifest['family'] ?? null) || !is_array($manifest['faces'] ?? null)) {
            return null;
        }

        $faces = [];
        foreach ($manifest['faces'] as $face) {
            if (
                !is_array($face)
                || !is_string($face['file'] ?? null)
                || !is_string($face['fullName'] ?? null)
                || !is_int($face['weight'] ?? null)
                || !is_bool($face['italic'] ?? null)
                // The script only ever writes into the directory it was given.
                || dirname($face['file']) !== $directory
                || !is_file($face['file'])
            ) {
                return null;
            }

            $faces[] = new ExpandedFontFace(
                $face['file'],
                basename($face['file']),
                $face['fullName'],
                $face['weight'],
                $face['italic'],
            );
        }

        if ($faces === []) {
            return null;
        }

        return new ExpandedVariableFont($manifest['family'], $directory, $faces);
    }

    private function wheel(): string
    {
        $wheels = glob($this->toolsDirectory . '/fonttools-*-py3-none-any.whl');

        return $wheels !== false && $wheels !== [] ? $wheels[0] : $this->toolsDirectory . '/fonttools.whl';
    }

    private function discardDirectory(string $directory): void
    {
        $files = glob($directory . '/*');
        foreach ($files !== false ? $files : [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }

    private static function uint16(string $bytes, int $offset): null|int
    {
        if (strlen($bytes) < $offset + 2) {
            return null;
        }

        $value = unpack('n', $bytes, $offset);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : null;
    }
}
