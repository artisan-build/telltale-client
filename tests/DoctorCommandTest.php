<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('checks reachability and registration without printing credential material', function (): void {
    $ingest = bin2hex(random_bytes(32));
    $token = bin2hex(random_bytes(48));
    config()->set('telltale.ingest', $ingest);
    Http::fake(function (Request $request) use ($token) {
        if ($request->url() === 'https://telltale.test') {
            return Http::response('Telltale', 200);
        }

        return Http::response(['install_token' => $token], 201);
    });

    $this->artisan('telltale:doctor')
        ->expectsOutputToContain('Configuration is valid.')
        ->expectsOutputToContain('server is reachable.')
        ->expectsOutputToContain('Registration succeeded.')
        ->doesntExpectOutputToContain($ingest)
        ->doesntExpectOutputToContain($token)
        ->assertExitCode(0);
});

it('fails usefully for invalid configuration without making a request', function (): void {
    config()->set('telltale.ingest', null);
    Http::preventStrayRequests();

    $this->artisan('telltale:doctor')
        ->expectsOutputToContain('configuration is incomplete or invalid.')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('fails registration generically and redacts server response material', function (): void {
    $marker = bin2hex(random_bytes(48));
    Http::fake([
        'https://telltale.test' => Http::response('Telltale', 200),
        'https://telltale.test/api/register' => Http::response(['message' => $marker], 401),
    ]);

    $this->artisan('telltale:doctor')
        ->expectsOutputToContain('Registration failed.')
        ->doesntExpectOutputToContain($marker)
        ->assertExitCode(1);
});
