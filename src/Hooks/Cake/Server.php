<?php
declare(strict_types=1);

namespace BEdita\Instrumentation\Hooks\Cake;

use Cake\Core\HttpApplicationInterface;
use Cake\Http\Runner;
use Cake\Http\Server as CakeServer;
use Cake\Routing\Router;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use function http_response_code;
use function is_int;
use function is_string;
use function OpenTelemetry\Instrumentation\hook;
use function register_shutdown_function;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;

class Server
{
    /**
     * Instrumentation name.
     *
     * @var string
     */
    public const NAME = 'bedita.server';

    /**
     * Context key holding the server span, so the runner hook can find it.
     *
     * @var \OpenTelemetry\Context\ContextKeyInterface<\OpenTelemetry\API\Trace\SpanInterface>|null
     */
    protected static ?ContextKeyInterface $spanKey = null;

    /**
     * Register instrumentation.
     *
     * @param \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation Instrumentation instance
     * @return void
     */
    public static function register(CachedInstrumentation $instrumentation): void
    {
        $instrumentation = new CachedInstrumentation(
            'com.bedita.instrumentation.server',
            schemaUrl: Version::VERSION_1_32_0->url(),
        );

        // Server::run bootstraps the app (BEdita loads configuration from the database) before building the request,
        // so the span starts from the raw globals and gets the request attributes later, in the runner hook.
        hook(
            CakeServer::class,
            'run',
            pre: static function (
                CakeServer $server,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): void {
                $request = $params[0] ?? null;
                if ($request instanceof ServerRequestInterface) {
                    $headers = $request->getHeaders();
                    $method = $request->getMethod();
                } else {
                    $headers = self::headersFromGlobals();
                    $method = $_SERVER['REQUEST_METHOD'] ?? null;
                }
                $method = is_string($method) && $method !== '' ? $method : null;

                $parent = Globals::propagator()->extract($headers);
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($method ?? 'HTTP')
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno);
                if ($method !== null) {
                    $builder->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $method);
                }
                $span = $builder->startSpan();

                Context::storage()->attach($span->storeInContext($parent)->with(self::spanKey(), $span));
            },
            post: static function (
                CakeServer $server,
                array $params,
                ?ResponseInterface $response,
                ?Throwable $exception,
            ): void {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());

                $request = Router::getRequest();
                $route = $request?->getParam('_matchedRoute');
                if ($request && is_string($route) && $route !== '') {
                    $span->setAttribute(TraceAttributes::HTTP_ROUTE, $route);
                    $span->updateName(sprintf('%s %s', $request->getMethod(), $route));
                }

                // Server::run always returns a response, so neither is set only when PHP aborts (fatal error).
                // This post hook then runs before the shutdown functions: keep the span current until Cake's fatal
                // error handler, registered earlier, has logged and rendered the error.
                if (!$response && !$exception) {
                    register_shutdown_function(static function () use ($scope, $span): void {
                        $scope->detach();
                        $code = http_response_code();
                        if (is_int($code)) {
                            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $code);
                        }
                        $span->setStatus(StatusCode::STATUS_ERROR, 'Request aborted');
                        $span->end();
                    });

                    return;
                }

                $scope->detach();
                if ($response) {
                    $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                    $span->setAttribute(
                        TraceAttributes::HTTP_RESPONSE_BODY_SIZE,
                        $response->getHeaderLine('Content-Length'),
                    );
                    // HTTP semconv: 4xx are the client's fault and leave a server span's status unset.
                    if ($response->getStatusCode() >= 500) {
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

        // Runner::run receives the request Server::run builds after bootstrap.
        hook(
            Runner::class,
            'run',
            pre: static function (Runner $runner, array $params): void {
                $request = $params[1] ?? null;
                $span = Context::getCurrent()->get(self::spanKey());
                if (
                    !self::isApplicationRun($params)
                    || !$request instanceof ServerRequestInterface
                    || !$span instanceof SpanInterface
                ) {
                    return;
                }

                $span->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::URL_FULL, (string)$request->getUri())
                    ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                    ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort());
            },
        );
    }

    /**
     * Context key holding the server span.
     *
     * @return \OpenTelemetry\Context\ContextKeyInterface<\OpenTelemetry\API\Trace\SpanInterface>
     */
    protected static function spanKey(): ContextKeyInterface
    {
        return self::$spanKey ??= Context::createKey(self::NAME);
    }

    /**
     * Request headers read from `$_SERVER`, for trace context propagation before Cake builds the request.
     *
     * @return array<string, string>
     */
    protected static function headersFromGlobals(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        return $headers;
    }

    /**
     * Whether the runner is the application's outermost one, not the nested runner of route-scoped middleware.
     *
     * @param array<mixed> $params Runner::run arguments
     * @return bool
     */
    protected static function isApplicationRun(array $params): bool
    {
        return ($params[2] ?? null) instanceof HttpApplicationInterface;
    }
}
