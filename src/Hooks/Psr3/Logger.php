<?php
declare(strict_types=1);

namespace BEdita\Instrumentation\Hooks\Psr3;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\SemConv\Version;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;
use ValueError;
use function array_diff_key;
use function array_key_exists;
use function array_pop;
use function class_parents;
use function class_uses;
use function in_array;
use function is_array;
use function is_string;
use function OpenTelemetry\Instrumentation\hook;

/**
 * Export-mode replacement for the `psr3` instrumentation, which leaves severity text and timestamp unset.
 */
class Logger
{
    /**
     * Instrumentation name.
     *
     * @var string
     */
    public const NAME = 'bedita.psr3';

    /**
     * Whether a logger class uses LoggerTrait, by class name.
     *
     * @var array<string, bool>
     */
    protected static array $usesLoggerTrait = [];

    /**
     * Register instrumentation.
     *
     * @param \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation Instrumentation instance
     * @return void
     */
    public static function register(CachedInstrumentation $instrumentation): void
    {
        $instrumentation = new CachedInstrumentation(
            'com.bedita.instrumentation.psr3',
            schemaUrl: Version::VERSION_1_32_0->url(),
        );

        $pre = static function (
            LoggerInterface $logger,
            array $params,
            string $class,
            string $function,
        ) use ($instrumentation): void {
            // LoggerTrait proxies the level methods to log(): handling both would emit every record twice.
            if ($function !== 'log' && self::usesLoggerTrait($logger)) {
                return;
            }

            if ($function === 'log') {
                [$level, $body, $context] = [$params[0] ?? null, $params[1] ?? '', $params[2] ?? []];
            } else {
                [$level, $body, $context] = [$function, $params[0] ?? '', $params[1] ?? []];
            }
            if ($level instanceof Stringable) {
                $level = (string)$level;
            }
            if (!is_string($level)) {
                return;
            }
            try {
                $severity = Severity::fromPsr3($level);
            } catch (ValueError) {
                return;
            }

            $record = (new LogRecord($body instanceof Stringable ? (string)$body : $body))
                ->setTimestamp(Clock::getDefault()->now())
                ->setSeverityNumber($severity)
                ->setSeverityText($level);
            foreach (Formatter::format(is_array($context) ? $context : []) as $key => $value) {
                $record->setAttribute((string)$key, $value);
            }
            $instrumentation->logger()->emit($record);
        };

        $functions = ['log', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
        foreach ($functions as $function) {
            hook(LoggerInterface::class, $function, pre: $pre);
        }
    }

    /**
     * Whether the logger, or any parent class or trait, uses LoggerTrait.
     *
     * @param \Psr\Log\LoggerInterface $logger Logger instance
     * @return bool
     */
    protected static function usesLoggerTrait(LoggerInterface $logger): bool
    {
        $class = $logger::class;
        if (array_key_exists($class, self::$usesLoggerTrait)) {
            return self::$usesLoggerTrait[$class];
        }

        $traits = [];
        foreach ([$class => $class] + (class_parents($class) ?: []) as $current) {
            $traits += class_uses($current) ?: [];
        }
        $pending = $traits;
        while ($pending) {
            $nested = class_uses(array_pop($pending)) ?: [];
            $pending += array_diff_key($nested, $traits);
            $traits += $nested;
        }

        return self::$usesLoggerTrait[$class] = in_array(LoggerTrait::class, $traits, true);
    }
}
