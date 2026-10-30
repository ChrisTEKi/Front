<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a file within "assets/"
 * - "entrypoint" set to true for any module that will be included directly
 *   in a Twig template via {{ importmap('app') }}
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
];
