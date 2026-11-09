<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualPage;

/**
 * The row `Version20261109140100` inserts for one legacy manual, from its raw
 * columns (`intro_image`, `page_texts` JSON) — kept out of the migration so
 * it can be tested. Builds through {@see IntroContentPageDefaults}, i.e. the
 * same page the add / edit manual handlers create.
 */
final class IntroContentPageMigration
{
    /**
     * @return array{id: string, manualId: string, name: string, layout: string, segments: string}
     */
    public static function plan(string $manualId, string $introImage, null|string $pageTextsJson): array
    {
        $title = null;
        $description = null;

        $pageTexts = $pageTextsJson !== null ? json_decode($pageTextsJson, true) : null;
        $intro = is_array($pageTexts) ? ($pageTexts[ManualPage::Intro->value] ?? null) : null;

        if (is_array($intro)) {
            $title = is_string($intro['title'] ?? null) ? $intro['title'] : null;
            $description = is_string($intro['description'] ?? null) ? $intro['description'] : null;
        }

        return [
            'id' => Uuid::uuid7()->toString(),
            'manualId' => $manualId,
            'name' => IntroContentPageDefaults::name($title),
            'layout' => IntroContentPageDefaults::LAYOUT->value,
            'segments' => json_encode(
                ContentSegment::listToArray(IntroContentPageDefaults::segments($description, $introImage)),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        ];
    }
}
