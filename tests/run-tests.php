<?php

declare(strict_types=1);

// Standalone tests of the WordPress-free protocol classes.
// Run: php tests/run-tests.php   or without native PHP: node tests/php-wasm/run.mjs tests/run-tests.php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/protocol-tests.php';

exit(TestRun::finish());
