<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Support;

use ArtisanBuild\TelltaleContracts\ErrorDetails;
use ArtisanBuild\TelltaleContracts\Event;
use stdClass;

final class Privacy
{
    public static function text(string $value, int $maximumBytes): string
    {
        return self::bounded(self::scrub($value), $maximumBytes);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function event(array $event): array
    {
        if (isset($event['name']) && is_string($event['name'])) {
            $event['name'] = self::text($event['name'], Event::MAX_NAME_LENGTH);
        }

        if (isset($event['props']) && is_array($event['props'])) {
            $event['props'] = self::object($event['props']);
        }

        if (isset($event['error']) && is_array($event['error'])) {
            $event['error'] = self::error($event['error']);
        }

        return $event;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private static function object(array $values): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $sanitized[self::text($key, Event::MAX_PROPERTY_KEY_LENGTH)] = self::value($value);
        }

        return $sanitized;
    }

    private static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value, Event::MAX_PROPERTY_STRING_LENGTH);
        }

        if ($value instanceof stdClass) {
            return (object) self::object(get_object_vars($value));
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::value(...), $value);
            }

            return self::object($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $error
     * @return array<string, mixed>
     */
    private static function error(array $error): array
    {
        if (isset($error['class']) && is_string($error['class'])) {
            $error['class'] = self::text($error['class'], 255);
        }

        if (isset($error['message']) && is_string($error['message'])) {
            $error['message'] = self::text($error['message'], ErrorDetails::MAX_MESSAGE_LENGTH);
        }

        if (isset($error['file']) && is_string($error['file'])) {
            $file = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $error['file']) === 1
                ? basename(str_replace('\\', '/', $error['file']))
                : $error['file'];
            $error['file'] = self::text($file, ErrorDetails::MAX_FILE_LENGTH);
        }

        if (isset($error['stack']) && is_array($error['stack'])) {
            $error['stack'] = array_map(
                static fn (mixed $frame): mixed => is_string($frame)
                    ? self::text(self::stripPaths($frame), ErrorDetails::MAX_STACK_FRAME_LENGTH)
                    : $frame,
                array_slice($error['stack'], 0, ErrorDetails::MAX_STACK_FRAMES),
            );
        }

        if (isset($error['fingerprint']) && is_string($error['fingerprint'])) {
            $error['fingerprint'] = self::text($error['fingerprint'], 255);
        }

        return $error;
    }

    private static function stripPaths(string $value): string
    {
        return preg_replace_callback(
            '/(?:[A-Za-z]:)?[^\s()]+[\\\\\/](?<file>[^\\\\\/\s():]+\.php)/',
            static fn (array $match): string => $match['file'],
            $value,
        ) ?? $value;
    }

    private static function bounded(string $value, int $maximumBytes): string
    {
        if (preg_match('//u', $value) !== 1) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return mb_strcut($value, 0, $maximumBytes, 'UTF-8');
    }

    private static function scrub(string $value): string
    {
        $scrubbed = preg_replace(
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
            '[redacted-email]',
            $value,
        ) ?? $value;

        return preg_replace_callback(
            '/(?<!\w)\+?\d[\d\s().-]{6,}\d(?!\w)/',
            static function (array $matches): string {
                $digits = preg_replace('/\D/', '', $matches[0]) ?? '';

                return strlen($digits) >= 7 ? '[redacted-phone]' : $matches[0];
            },
            $scrubbed,
        ) ?? $scrubbed;
    }
}
