<?php

declare(strict_types=1);

use Symfony\Config\SecurityConfig;

return static function (SecurityConfig $securityConfig): void {
    $securityConfig->firewall('dev')
        ->pattern('^/(_(profiler|wdt)|css|images|js)/')
        ->security(false);

    $securityConfig->firewall('stateless')
        ->pattern('^(/-/health-check|/media/cache|/sitemap)')
        ->stateless(true)
        ->security(false);

    /*
    $securityConfig->accessControl()
        ->path('^/(muj-profil|upravit-profil|pridat-cas|puzzle-stopky|zapnout-stopky|stopky|upravit-cas|smazat-cas|ulozit-stopky|porovnat-s-puzzlerem|pridat-hrace-k-oblibenym|odebrat-hrace-z-oblibenych|feedback|notifikace)|(en/(save-stopwatch|add-time|compare-with-puzzler|delete-time|edit-profile|edit-time|my-profile|stopwatch|start-stopwatch|puzzle-stopwatch|add-player-to-favorites|remove-player-from-favorites|feedback|notifications))')
        ->roles([AuthenticatedVoter::IS_AUTHENTICATED_FULLY]);

    $securityConfig->accessControl()
        ->path('^/')
        ->roles([AuthenticatedVoter::PUBLIC_ACCESS]);
    */
};
