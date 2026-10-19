<?php

declare(strict_types=1);

use BookingApi\App;
use BookingApi\Database;
use BookingApi\Http\Request;
use BookingApi\Payment\SignatureVerifier;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App(Database::fromEnv(), new SignatureVerifier((string) getenv('WEBHOOK_SECRET')));
$app->handle(Request::fromGlobals())->send();
