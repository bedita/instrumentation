<?php
declare(strict_types=1);

use BEdita\Instrumentation\BEditaInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (class_exists(Sdk::class) && Sdk::isInstrumentationDisabled(BEditaInstrumentation::NAME) === true) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry BEdita auto-instrumentation', E_USER_WARNING);

    return;
}

BEditaInstrumentation::register();
