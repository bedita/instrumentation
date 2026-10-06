<?php
declare(strict_types=1);

namespace BEdita\Instrumentation;

use BEdita\Instrumentation\Hooks\Cake\Client;
use BEdita\Instrumentation\Hooks\Cake\Controller;
use BEdita\Instrumentation\Hooks\Cake\Server;
use BEdita\Instrumentation\Hooks\Psr3\Logger;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SemConv\TraceAttributes;

class BEditaInstrumentation
{
    /**
     * Instrumentation name.
     *
     * @var string
     */
    public const NAME = 'bedita';

    /**
     * Register instrumentation.
     *
     * @return void
     */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(static::NAME, schemaUrl: TraceAttributes::SCHEMA_URL);

        if (class_exists(Sdk::class) && !Sdk::isInstrumentationDisabled(Client::NAME)) {
            Client::register($instrumentation);
        }
        if (class_exists(Sdk::class) && !Sdk::isInstrumentationDisabled(Server::NAME)) {
            Server::register($instrumentation);
            Controller::register($instrumentation);
        }
        if (class_exists(Sdk::class) && !Sdk::isInstrumentationDisabled(Logger::NAME)) {
            Logger::register($instrumentation);
        }
    }
}
