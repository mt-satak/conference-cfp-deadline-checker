<?php

use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Infrastructure\Amplify\AmplifyBuildTriggerer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;

/**
 * AmplifyBuildTriggerer の単体テスト (Guzzle クライアントモック使用)。
 */
beforeEach(function () {
    Carbon::setTestNow('2026-05-04T10:00:00+09:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('webhook URL が null なら BuildServiceNotConfiguredException', function () {
    // Given
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldNotReceive('request');

    // When/Then
    $triggerer = new AmplifyBuildTriggerer($http, null);
    expect(fn () => $triggerer->trigger())
        ->toThrow(BuildServiceNotConfiguredException::class);
});

it('webhook URL が空文字なら BuildServiceNotConfiguredException', function () {
    // Given
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldNotReceive('request');

    // When/Then
    $triggerer = new AmplifyBuildTriggerer($http, '');
    expect(fn () => $triggerer->trigger())
        ->toThrow(BuildServiceNotConfiguredException::class);
});

it('webhook URL があれば POST で叩き、現在時刻 (Asia/Tokyo) を返す', function () {
    // Given: HTTP 200 を返すモック
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->with('POST', 'https://hooks.example.com/abc', Mockery::on(function (array $opts) {
            return ($opts['headers']['Content-Type'] ?? null) === 'application/json'
                && ($opts['body'] ?? null) === '{}';
        }))
        ->andReturn(new Response(200));

    // When
    $triggerer = new AmplifyBuildTriggerer($http, 'https://hooks.example.com/abc');
    $requestedAt = $triggerer->trigger();

    // Then: Carbon::setTestNow で固定した時刻
    expect($requestedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('webhook が 5xx を返したら RuntimeException', function () {
    // Given
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')
        ->once()
        ->andReturn(new Response(503));

    // When/Then
    $triggerer = new AmplifyBuildTriggerer($http, 'https://hooks.example.com/abc');
    expect(fn () => $triggerer->trigger())
        ->toThrow(RuntimeException::class);
});
