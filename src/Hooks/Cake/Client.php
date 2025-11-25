<?php
/**
 * Copyright 2024 OpenTelemetry Contributors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
declare(strict_types=1);

namespace BEdita\Instrumentation\Hooks\Cake;

use Cake\Http\Client as CakeClient;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use function get_cfg_var;
use function OpenTelemetry\Instrumentation\hook;
use function sprintf;
use function strtolower;

class Client
{
    /**
     * Instrumentation name.
     *
     * @var string
     */
    public const NAME = 'bedita.client';

    /**
     * Register instrumentation.
     *
     * @param \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation Instrumentation instance
     * @return void
     */
    public static function register(CachedInstrumentation $instrumentation): void
    {
        $instrumentation = new CachedInstrumentation(
            'com.bedita.instrumentation.client',
            schemaUrl: Version::VERSION_1_32_0->url(),
        );

        hook(
            CakeClient::class,
            'send',
            pre: static function (
                CakeClient $client,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): ?array {
                $request = $params[0] ?? null;
                if (!$request instanceof RequestInterface) {
                    Context::storage()->attach(Context::getCurrent());

                    return null;
                }

                $propagator = Globals::propagator();
                $parentContext = Context::getCurrent();

                $spanBuilder = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s', $request->getMethod())) // @phpstan-ignore argument.type
                    ->setParent($parentContext)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::URL_FULL, (string)$request->getUri())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort())
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);

                foreach ($propagator->fields() as $field) {
                    $request = $request->withoutHeader($field);
                }
                //@todo could we use SDK Configuration to retrieve this, and move into a key such as OTEL_PHP_xxx?
                foreach ((array)(get_cfg_var('otel.instrumentation.http.request_headers') ?: []) as $header) {
                    if ($request->hasHeader($header)) {
                        $spanBuilder->setAttribute(
                            sprintf('http.request.header.%s', strtolower($header)),
                            $request->getHeader($header),
                        );
                    }
                }

                $span = $spanBuilder->startSpan();
                $context = $span->storeInContext($parentContext);
                $propagator->inject($request, HeadersPropagator::instance(), $context);

                Context::storage()->attach($context);

                return [$request];
            },
            post: static function (
                CakeClient $client,
                array $params,
                ?ResponseInterface $response,
                ?Throwable $exception,
            ): void {
                $scope = Context::storage()->scope();
                $scope?->detach();

                //@todo do we need the second part of this 'or'?
                if (!$scope || $scope->context() === Context::getCurrent()) {
                    return;
                }

                $span = Span::fromContext($scope->context());

                if ($response) {
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                    $span->setAttribute(
                        TraceAttributes::HTTP_RESPONSE_BODY_SIZE,
                        $response->getHeaderLine('Content-Length'),
                    );

                    foreach ((array)(get_cfg_var('otel.instrumentation.http.response_headers') ?: []) as $header) {
                        if ($response->hasHeader($header)) {
                            /** @psalm-suppress ArgumentTypeCoercion */
                            $span->setAttribute(
                                sprintf('http.response.header.%s', strtolower($header)),
                                $response->getHeader($header),
                            );
                        }
                    }
                    if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                }
                if ($exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                $span->end();
            },
        );
    }
}
