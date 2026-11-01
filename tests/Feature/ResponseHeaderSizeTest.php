<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

it('keeps the Link header small however many scripts a page preloads', function () {
    // The hosting's web server answers 500 once a response's headers pass 8 KB.
    $assets = [];
    foreach (range(1, 150) as $i) {
        $assets["https://billing.klokads.com/build/assets/SomeLongPageChunkName$i-AbCdEf12.js"] = ['rel="modulepreload"', 'crossorigin'];
    }
    Vite::partialMock()->shouldReceive('preloadedAssets')->andReturn($assets);
    Route::middleware('web')->get('/_header-size-test', fn () => response('ok'));

    $response = $this->get('/_header-size-test');

    $link = (string) $response->headers->get('Link');
    expect(substr_count($link, 'rel="modulepreload"'))->toBe(20)
        ->and(strlen($link))->toBeLessThan(8192 - 3000)
        ->and(strlen((string) $response->headers))->toBeLessThan(8192);
});
