<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads the plugin's pure helper functions WITHOUT bootstrapping Piwigo,
 * so tests run instantly without a database or web server.
 */

require_once __DIR__ . '/../../plugins/MagicLinkLogin/include/functions.php';
