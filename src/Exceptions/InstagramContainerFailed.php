<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Instagram accepted the media container but processing ended in ERROR (or
 * never finished within our polling window) — typically an image Meta's side
 * couldn't fetch or process.
 */
final class InstagramContainerFailed extends MetaApiError
{
    public function userMessage(): string
    {
        return 'Instagram nedokázal obrázek zpracovat. Zkuste to prosím znovu.';
    }
}
