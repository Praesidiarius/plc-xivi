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
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    'bootstrap/dist/css/bootstrap.min.css' => ['version' => '5.3.8', 'type' => 'css'],
    'bootstrap-icons/font/bootstrap-icons.min.css' => ['version' => '1.13.1', 'type' => 'css'],
    'bootstrap' => ['version' => '5.3.8'],
    '@popperjs/core' => ['version' => '2.11.8'],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@symfony/ux-live-component' => ['path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js'],
    // Tom Select is what the autocomplete controller attaches to a select
    // (XIV-36), and it brings its own two. **Only the Bootstrap 5 stylesheet**:
    // the recipe offers four, of which this application can use exactly one, and
    // the other three would be downloaded into assets/vendor/ and served to
    // nobody. Which one is chosen is in assets/controllers.json.
    'tom-select' => ['version' => '2.6.2'],
    '@orchidjs/sifter' => ['version' => '1.1.0'],
    '@orchidjs/unicode-variants' => ['version' => '1.1.2'],
    'tom-select/dist/css/tom-select.bootstrap5.css' => ['version' => '2.6.2', 'type' => 'css'],
    'chart.js' => ['version' => '4.5.1'],
    '@kurkle/color' => ['version' => '0.3.4'],
    // What makes the arrange page's rows draggable ([XIV-165]). MIT, no
    // dependencies of its own, which is why nothing was added beside it here.
    // It is loaded by assets/controllers/arrange_fields_controller.js and by
    // nothing else, and that controller is the one place to read for what a
    // drop actually writes: dragging is a way of typing the numbers this
    // product already stored, not a second way of storing an order.
    'sortablejs' => ['version' => '1.15.7'],
];
