<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
// The tests run without OpenEMR; only its QueryUtils is stood in for.
if (!class_exists(\OpenEMR\Common\Database\QueryUtils::class)) {
    require __DIR__ . '/Stubs/QueryUtils.php';
}
