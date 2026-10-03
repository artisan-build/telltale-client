<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Capture;

use Illuminate\Contracts\Config\Repository;
use Throwable;

final class ContextCollector
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return match ($this->platform()) {
            'mobile' => $this->mobile(),
            'desktop' => $this->desktop(),
            default => ['platform' => 'unknown'],
        };
    }

    public function platform(): string
    {
        $configured = $this->config->get('telltale.capture.platform', 'auto');

        if ($configured === 'mobile' || $configured === 'desktop') {
            return $configured;
        }

        if (class_exists('Native\\Mobile\\NativeServiceProvider')) {
            return 'mobile';
        }

        if (class_exists('Native\\Desktop\\NativeServiceProvider')) {
            return 'desktop';
        }

        return 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function mobile(): array
    {
        $context = ['platform' => 'mobile'];
        $this->putScalar($context, 'app_version', $this->config->get('nativephp.version'));
        $this->putScalar($context, 'app_build', $this->config->get('nativephp.version_code'));
        $info = $this->call('Native\\Mobile\\Facades\\Device', 'getInfo');

        if (is_string($info)) {
            $decoded = json_decode($info, true);

            if (is_array($decoded)) {
                $this->putScalar($context, 'device_model', $decoded['model'] ?? null);
                $this->putScalar($context, 'os', $decoded['operatingSystem'] ?? null);
                $this->putScalar($context, 'os_version', $decoded['osVersion'] ?? null);
                $this->putScalar($context, 'locale', $decoded['language'] ?? null);
            }
        }

        $network = $this->call('Native\\Mobile\\Facades\\Network', 'status');

        if (is_object($network)) {
            $network = get_object_vars($network);
        }

        if (is_array($network)) {
            foreach (['connected', 'type', 'isExpensive', 'isConstrained'] as $key) {
                if (array_key_exists($key, $network) && (is_scalar($network[$key]) || $network[$key] === null)) {
                    $context['network_'.$this->snake($key)] = $network[$key];
                }
            }
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function desktop(): array
    {
        $context = ['platform' => 'desktop'];
        $this->putScalar($context, 'app_version', $this->call('Native\\Desktop\\Facades\\App', 'version'));
        $this->putScalar($context, 'platform_name', $this->call('Native\\Desktop\\Facades\\Process', 'platform'));
        $this->putScalar($context, 'architecture', $this->call('Native\\Desktop\\Facades\\Process', 'arch'));
        $this->putScalar($context, 'locale', $this->call('Native\\Desktop\\Facades\\App', 'getLocale'));
        $this->putScalar($context, 'timezone', $this->call('Native\\Desktop\\Facades\\System', 'timezone'));

        return $context;
    }

    private function call(string $class, string $method): mixed
    {
        if (! class_exists($class) || ! is_callable([$class, $method])) {
            return null;
        }

        try {
            return $class::$method();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function putScalar(array &$context, string $key, mixed $value): void
    {
        if (is_scalar($value) && (string) $value !== '') {
            $context[$key] = $value;
        }
    }

    private function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
