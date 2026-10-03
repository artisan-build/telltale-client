<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Capture;

use ArtisanBuild\TelltaleClient\Support\Privacy;
use ArtisanBuild\TelltaleContracts\ErrorDetails;
use Throwable;

final class ErrorSanitizer
{
    public function fromThrowable(Throwable $exception): ErrorDetails
    {
        $file = $this->file($exception->getFile());
        $stack = [];

        foreach (array_slice($exception->getTrace(), 0, ErrorDetails::MAX_STACK_FRAMES) as $frame) {
            $call = (string) ($frame['class'] ?? '').(string) ($frame['type'] ?? '').$frame['function'];
            $location = isset($frame['file']) ? $this->file((string) $frame['file']) : 'internal';
            $line = isset($frame['line']) ? max(1, (int) $frame['line']) : 1;
            $stack[] = Privacy::text("{$call} ({$location}:{$line})", ErrorDetails::MAX_STACK_FRAME_LENGTH);
        }

        return $this->details(
            class: $exception::class,
            message: $exception->getMessage(),
            file: $file,
            line: max(1, $exception->getLine()),
            stack: $stack,
        );
    }

    public function fromRemote(string $class, string $message, ?string $trace): ErrorDetails
    {
        $stack = [];

        if ($trace !== null) {
            foreach (array_slice(preg_split('/\R/', $trace) ?: [], 0, ErrorDetails::MAX_STACK_FRAMES) as $frame) {
                $frame = preg_replace_callback(
                    '/(?:[A-Za-z]:)?[^\s()]+[\\\\\/](?<file>[^\\\\\/\s():]+\.php)/',
                    static fn (array $match): string => $match['file'],
                    $frame,
                ) ?? $frame;
                $stack[] = Privacy::text($frame, ErrorDetails::MAX_STACK_FRAME_LENGTH);
            }
        }

        return $this->details($class, $message, 'async-task', 1, $stack);
    }

    /**
     * @param  list<string>  $stack
     */
    private function details(string $class, string $message, string $file, int $line, array $stack): ErrorDetails
    {
        $class = Privacy::text($class !== '' ? $class : 'Throwable', 255);
        $message = Privacy::text($message, ErrorDetails::MAX_MESSAGE_LENGTH);
        $file = Privacy::text($file !== '' ? $file : 'unknown', ErrorDetails::MAX_FILE_LENGTH);
        $fingerprint = hash('sha256', implode('|', [$class, $file, (string) $line, $stack[0] ?? '']));

        return new ErrorDetails($class, $message, $file, max(1, $line), $stack, $fingerprint);
    }

    private function file(string $file): string
    {
        $base = str_replace('\\', '/', base_path());
        $normalized = str_replace('\\', '/', $file);

        if ($normalized === $base || str_starts_with($normalized, $base.'/')) {
            return ltrim(substr($normalized, strlen($base)), '/');
        }

        return basename($normalized);
    }
}
