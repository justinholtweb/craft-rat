<?php

/**
 * Codeception bootstrap.
 *
 * The plugin has no Craft installation of its own, so the suite stands one up
 * against the `tests/_craft` skeleton and the DDEV database container. Craft's
 * own Codeception module (craft\test\Craft) installs Craft into a throwaway
 * schema and wraps each test in a transaction.
 */

use craft\test\TestSetup;
use Dotenv\Dotenv;

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_ROOT_PATH', dirname(__DIR__));
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');

// Craft reads its DB connection out of the environment.
Dotenv::createUnsafeImmutable(__DIR__, '.env')->load();

TestSetup::configureCraft();
