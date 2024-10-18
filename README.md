# BEdita/Instrumentation plugin for BEdita

[![Github Actions](https://github.com/bedita/instrumentation/workflows/php/badge.svg)](https://github.com/bedita/instrumentation/actions?query=workflow%3Aphp)
[![image](https://img.shields.io/packagist/v/bedita/instrumentation.svg?label=stable)](https://packagist.org/packages/bedita/instrumentation)

This library provides auto-instrumentation for BEdita applications, according to [OpenTelemetry](https://opentelemetry.io/) specifications.

## Requirements

This library requires PHP 8.1+ and the [OpenTelemetry PHP extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation).

Other optional requirements:
- `ext-grpc` required to use gRPC as transport for the OTLP exporter
- `ext-protobuf` significant performance improvement for otlp+protobuf exporting
- `ext-zlib` if you want to compress exported data
- `open-telemetry/opentelemetry-auto-psr15` auto-instrumentation for CakePHP middlewares (PSR-15)

## Installation

```shell
composer require bedita/instrumentation
```

Note that installing this library by itself does not generate traces. You need to install and configure the [OpenTelemetry SDK](https://opentelemetry.io/docs/languages/php/instrumentation/#initialize-the-sdk)
and at least an [exporter](https://opentelemetry.io/docs/languages/php/exporters/):

```shell
composer require open-telemetry/sdk open-telemetry/exporter-otlp
```

## Configuration

OpenTelemetry's auto-instrumentation is completely configurable through environment variables.
See the [SDK configuration](https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/) documentation
and the [PHP-specific documentation](https://opentelemetry.io/docs/languages/php/sdk/#configuration).

This library provides the following instrumentations, which can be enabled or disabled individually using their respective names:
- `bedita` main instrumentation (currently does nothing by itself)
- `bedita.client` CakePHP HTTP client (requires `bedita`)
- `cakephp` CakePHP HTTP server, controllers and commands ([project](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/CakePHP))
- `pdo` PHP PDO ([project](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/PDO))
- `psr3` loggers compliant with PSR-3 standard ([project](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Psr3))
- `psr16` cache engines compliant with PSR-16 standard ([project](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Psr16))

### Example configuration

This configuration enables auto-instrumentation and the export of traces and logs using the otlp+protobuf protocol to a local [backend](https://opentelemetry.io/ecosystem/vendors/):

```dotenv
# PHP injection
export OTEL_PHP_AUTOLOAD_ENABLED=true

# Export
export OTEL_LOG_LEVEL="info"
export OTEL_EXPORTER_OTLP_PROTOCOL="http/protobuf"
export OTEL_EXPORTER_OTLP_ENDPOINT="http://localhost:4318/"

# Tracing
# export OTEL_TRACES_SAMPLER="parentbased_traceidratio"
# export OTEL_TRACES_SAMPLER_ARG="0.1"
export OTEL_PHP_DETECTORS="env,host,os,sdk"
export OTEL_RESOURCE_ATTRIBUTES="service.namespace=my-namespace"
export OTEL_SERVICE_NAME="my-service"

# Propagation
export OTEL_PROPAGATORS="tracecontext,baggage"

# Instrumentation
export OTEL_PHP_EXCLUDED_URLS="/status"
# export OTEL_PHP_DISABLED_INSTRUMENTATIONS="pdo"

# Log
# see: https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Psr3#mode
export OTEL_PHP_PSR3_MODE="export"
```
