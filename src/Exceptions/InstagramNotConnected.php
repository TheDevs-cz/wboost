<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * The project has no usable Instagram connection (never connected, or
 * flagged for reconnect).
 */
final class InstagramNotConnected extends MetaApiError
{
    public function __construct()
    {
        parent::__construct('The project has no usable Instagram connection.');
    }

    public function userMessage(): string
    {
        return 'Projekt nemá propojený Instagram. Propojte ho na stránce Facebook a Instagram v menu projektu.';
    }
}
