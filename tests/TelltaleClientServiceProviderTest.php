<?php

declare(strict_types=1);

use ArtisanBuild\TelltaleClient\TelltaleClientServiceProvider;

it('loads the client service provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(TelltaleClientServiceProvider::class, true);
});
