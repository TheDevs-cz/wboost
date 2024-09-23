<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@symfony/stimulus-bundle' => [
        'path' => '@symfony/stimulus-bundle/loader.js',
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.5',
    ],
    'bootstrap' => [
        'version' => '5.3.3',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    'bootstrap/dist/css/bootstrap.min.css' => [
        'version' => '5.3.3',
        'type' => 'css',
    ],
    'fabric' => [
        'version' => '5.2.4',
    ],
    'fontfaceobserver' => [
        'version' => '2.3.0',
    ],
    'dragula' => [
        'version' => '3.7.3',
    ],
    'contra/emitter' => [
        'version' => '1.9.4',
    ],
    'crossvent' => [
        'version' => '1.5.5',
    ],
    'atoa' => [
        'version' => '1.0.0',
    ],
    'ticky' => [
        'version' => '1.0.1',
    ],
    'custom-event' => [
        'version' => '1.0.1',
    ],
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],
];
