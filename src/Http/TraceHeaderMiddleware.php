<?php

declare(strict_types=1);

namespace ArtisanBuild\TelltaleClient\Http;

use ArtisanBuild\TelltaleClient\Contracts\TelltaleClient;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Psr\Http\Message\RequestInterface;

final class TraceHeaderMiddleware
{
    public const HEADER = 'X-Telltale-Session';

    public function __construct(
        private readonly TelltaleClient $telltale,
        private readonly Repository $config,
    ) {}

    public function add(RequestInterface $request): RequestInterface
    {
        if (! $this->config->get('telltale.trace_header.enabled', true) || $this->isTelltaleTransport($request)) {
            return $request;
        }

        $header = $this->telltale->correlationHeader();

        return $header === null ? $request : $request->withHeader(self::HEADER, $header);
    }

    public function __invoke(callable $handler): Closure
    {
        return fn (RequestInterface $request, array $options): mixed => $handler($this->add($request), $options);
    }

    private function isTelltaleTransport(RequestInterface $request): bool
    {
        $base = $this->config->get('telltale.url');

        if (! is_string($base) || $base === '') {
            return false;
        }

        $baseParts = parse_url($base);
        $uri = $request->getUri();

        if (! is_array($baseParts)
            || strcasecmp((string) ($baseParts['host'] ?? ''), $uri->getHost()) !== 0
            || (($baseParts['port'] ?? null) !== null && (int) $baseParts['port'] !== $uri->getPort())) {
            return false;
        }

        $basePath = '/'.trim((string) ($baseParts['path'] ?? ''), '/');
        $basePath = $basePath === '/' ? '' : $basePath;
        $path = rtrim($uri->getPath(), '/');

        return in_array($path, [$basePath.'/api/register', $basePath.'/api/ingest'], true);
    }
}
