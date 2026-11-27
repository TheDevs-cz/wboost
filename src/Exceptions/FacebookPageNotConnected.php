<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * The project has no usable Facebook Page connection.
 */
final class FacebookPageNotConnected extends MetaApiError
{
    public function __construct()
    {
        parent::__construct('The project has no usable Facebook Page connection.');
    }

    public function userMessage(): string
    {
        return 'Projekt nemá propojenou facebookovou stránku (nebo propojení vypršelo).';
    }
}
