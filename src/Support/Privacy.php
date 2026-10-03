<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Support;

use ArtisanBuild\TelltaleContracts\Event;
use stdClass;

final class Privacy
{
    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function event(array $event): array
    {
        if (isset($event['name']) && is_string($event['name'])) {
            $event['name'] = self::bounded($event['name'], Event::MAX_NAME_LENGTH);
        }

        if (isset($event['props']) && is_array($event['props'])) {
            $event['props'] = self::object($event['props']);
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

            $sanitized[self::bounded($key, Event::MAX_PROPERTY_KEY_LENGTH)] = self::value($value);
        }

        return $sanitized;
    }

    private static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            $scrubbed = preg_replace(
                '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
                '[redacted-email]',
                $value,
            ) ?? $value;

            $scrubbed = preg_replace_callback(
                '/(?<!\w)\+?\d[\d\s().-]{6,}\d(?!\w)/',
                static function (array $matches): string {
                    $digits = preg_replace('/\D/', '', $matches[0]) ?? '';

                    return strlen($digits) >= 7 ? '[redacted-phone]' : $matches[0];
                },
                $scrubbed,
            ) ?? $scrubbed;

            return self::bounded($scrubbed, Event::MAX_PROPERTY_STRING_LENGTH);
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

    private static function bounded(string $value, int $maximumBytes): string
    {
        if (preg_match('//u', $value) !== 1) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return mb_strcut($value, 0, $maximumBytes, 'UTF-8');
    }
}
