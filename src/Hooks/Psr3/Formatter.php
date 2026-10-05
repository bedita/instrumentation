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

namespace BEdita\Instrumentation\Hooks\Psr3;

use JsonSerializable;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use Stringable;
use Throwable;
use function gettype;
use function json_decode;
use function json_encode;
use function json_last_error_msg;

class Formatter
{
    use LogsMessagesTrait;

    /**
     * Format a PSR-3 context as log record attributes.
     *
     * @param array<mixed> $context PSR-3 context
     * @return array<mixed>
     */
    public static function format(array $context): array
    {
        $formatted = [];
        foreach ($context as $key => $value) {
            if ($key === 'exception' && $value instanceof Throwable) {
                $formatted[$key] = self::formatThrowable($value);

                continue;
            }

            switch (gettype($value)) {
                case 'integer':
                case 'double':
                case 'boolean':
                    $formatted[$key] = $value;

                    break;
                case 'string':
                case 'array':
                    // Handle UTF-8 encoding issues
                    $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
                    if ($encoded === false) {
                        self::logWarning('Failed to encode value: ' . json_last_error_msg());
                    } else {
                        $formatted[$key] = json_decode($encoded);
                    }

                    break;
                case 'object':
                    if ($value instanceof Stringable) {
                        $formatted[$key] = (string)$value;
                    } elseif ($value instanceof JsonSerializable) {
                        $formatted[$key] = $value->jsonSerialize();
                    }

                    break;
            }
        }

        return $formatted;
    }

    /**
     * Format a throwable and its previous ones.
     *
     * @param \Throwable|null $exception Throwable
     * @return array<string, mixed>
     */
    private static function formatThrowable(?Throwable $exception): array
    {
        if ($exception) {
            return [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
                'previous' => self::formatThrowable($exception->getPrevious()),
            ];
        }

        return [];
    }
}
