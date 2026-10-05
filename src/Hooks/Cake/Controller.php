<?php
declare(strict_types=1);

namespace BEdita\Instrumentation\Hooks\Cake;

use Cake\Controller\Controller as CakeController;
use Cake\Core\Exception\HttpErrorCodeInterface;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Throwable;
use function get_class;
use function is_string;
use function OpenTelemetry\Instrumentation\hook;
use function sprintf;

class Controller
{
    /**
     * Register instrumentation.
     *
     * @param \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation Instrumentation instance
     * @return void
     */
    public static function register(CachedInstrumentation $instrumentation): void
    {
        $instrumentation = new CachedInstrumentation(
            'com.bedita.instrumentation.controller',
            schemaUrl: Version::VERSION_1_32_0->url(),
        );

        hook(
            CakeController::class,
            'invokeAction',
            pre: static function (
                CakeController $controller,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): void {
                $action = $controller->getRequest()->getParam('action');
                $name = get_class($controller);
                if (is_string($action)) {
                    $name = sprintf('%s::%s', $name, $action);
                }

                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder($name)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $name)
                    ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->startSpan();

                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function (
                CakeController $controller,
                array $params,
                mixed $return,
                ?Throwable $exception,
            ): void {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }
                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($exception) {
                    $span->recordException($exception);
                    // Same rule as the server span.
                    if (!$exception instanceof HttpErrorCodeInterface || $exception->getCode() >= 500) {
                        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    }
                }

                $span->end();
            },
        );
    }
}
