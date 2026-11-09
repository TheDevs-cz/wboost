<?php

declare(strict_types=1);

namespace WBoost\Web\Value\ManualContent;

/**
 * One element of a content-page segment. The `type` discriminator is what the
 * JSONB document and the editor carry; `id` is a client-generated stable key
 * (drag & drop, P3 references), never interpreted by the server.
 */
interface ContentElement
{
    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function id(): string;

    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
