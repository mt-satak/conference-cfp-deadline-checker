<?php

use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use App\Infrastructure\Http\DnsHostValidator;
use App\Infrastructure\Http\DnsResolver;
use App\Infrastructure\Http\PhpDnsResolver;

/**
 * DnsHostValidator の SSRF 防御ロジック (Issue #40 Phase 3 PR-2)。
 *
 * DnsResolver は interface 経由で DI するため、テストでは固定値を返す
 * Stub Resolver を使い、実 DNS 解決依存を排除する。
 *
 * IP リテラル経路 (FILTER_VALIDATE_IP マッチ) と、ホスト名経路 (resolver
 * 経由) の両方をカバーする。
 */
function makeResolverReturning(array $ips): DnsResolver
{
    return new class($ips) implements DnsResolver
    {
        /**
         * @param  string[]  $ips
         */
        public function __construct(private readonly array $ips) {}

        public function resolve(string $host): array
        {
            return $this->ips;
        }
    };
}

it('IPv4 loopback (127.0.0.1) は拒否する', function () {
    $validator = new DnsHostValidator(makeResolverReturning([]));

    expect(fn () => $validator->validate('127.0.0.1'))
        ->toThrow(HtmlFetchFailedException::class, 'non-public');
});

it('IPv4 プライベート (10.0.0.1) は拒否する', function () {
    $validator = new DnsHostValidator(makeResolverReturning([]));

    expect(fn () => $validator->validate('10.0.0.1'))
        ->toThrow(HtmlFetchFailedException::class);
});

it('IPv4 プライベート (172.16.0.1) は拒否する', function () {
    $validator = new DnsHostValidator(makeResolverReturning([]));

    expect(fn () => $validator->validate('172.16.0.1'))
        ->toThrow(HtmlFetchFailedException::class);
});

it('IPv4 プライベート (192.168.0.1) は拒否する', function () {
    $validator = new DnsHostValidator(makeResolverReturning([]));

    expect(fn () => $validator->validate('192.168.0.1'))
        ->toThrow(HtmlFetchFailedException::class);
});

it('AWS EC2 metadata endpoint (169.254.169.254) は拒否する', function () {
    $validator = new DnsHostValidator(makeResolverReturning([]));

    expect(fn () => $validator->validate('169.254.169.254'))
        ->toThrow(HtmlFetchFailedException::class);
});

it('IPv6 loopback (::1) は拒否する', function () {
    $validator = new DnsHostValidator(makeResolverReturning([]));

    expect(fn () => $validator->validate('::1'))
        ->toThrow(HtmlFetchFailedException::class);
});

it('IPv6 link-local (fe80::1) は拒否する', function () {
    $validator = new DnsHostValidator(makeResolverReturning([]));

    expect(fn () => $validator->validate('fe80::1'))
        ->toThrow(HtmlFetchFailedException::class);
});

it('公開 IPv4 (8.8.8.8) は通す', function () {
    $validator = new DnsHostValidator(makeResolverReturning([]));

    // 例外を投げないことを確認 (= validate は void 戻り)
    $validator->validate('8.8.8.8');

    expect(true)->toBeTrue();
});

it('解決できないホスト名 (resolver が空配列を返す) は HtmlFetchFailedException', function () {
    $validator = new DnsHostValidator(makeResolverReturning([]));

    expect(fn () => $validator->validate('this-host-does-not-exist.invalid'))
        ->toThrow(HtmlFetchFailedException::class, 'DNS resolution failed');
});

it('ホスト名が公開 IPv4 に解決される場合は通す', function () {
    // Given: resolver が公開 IP を返す
    $validator = new DnsHostValidator(makeResolverReturning(['8.8.8.8']));

    // When/Then: 例外なし
    $validator->validate('public.example.com');
    expect(true)->toBeTrue();
});

it('ホスト名がプライベート IP に解決されたら拒否する (DNS rebinding 等への防御)', function () {
    // Given: resolver が RFC 1918 プライベートを返す (= 攻撃シナリオ: 公開ドメインを
    // 動的にプライベート IP に解決させる DNS rebinding)
    $validator = new DnsHostValidator(makeResolverReturning(['10.0.0.5']));

    // When/Then
    expect(fn () => $validator->validate('attacker-controlled.example.com'))
        ->toThrow(HtmlFetchFailedException::class, 'non-public');
});

it('複数 IP の中に 1 つでもプライベートがあれば拒否する', function () {
    // Given: 公開 IP と プライベート IP の混在 (= マルチホームの罠への防御)
    $validator = new DnsHostValidator(makeResolverReturning(['8.8.8.8', '10.0.0.1']));

    // When/Then
    expect(fn () => $validator->validate('mixed.example.com'))
        ->toThrow(HtmlFetchFailedException::class, 'non-public');
});

it('PhpDnsResolver は dns_get_record を呼び結果を返す (実環境スモーク)', function () {
    // Given: 実 DnsResolver で公開ドメインを引く
    $resolver = new PhpDnsResolver;

    // When: example.com (RFC 2606 reserved だが IANA が公開 IP に解決させている)
    $ips = $resolver->resolve('example.com');

    // Then: 1 件以上の IP 文字列が返る (= dns_get_record 経路カバー)
    expect($ips)->not->toBe([]);
    foreach ($ips as $ip) {
        expect(filter_var($ip, FILTER_VALIDATE_IP))->not->toBeFalse();
    }
});

it('PhpDnsResolver は解決失敗時に空配列を返す', function () {
    // Given
    $resolver = new PhpDnsResolver;

    // When: RFC 6761 で予約済の .invalid TLD は確実に解決失敗
    $ips = $resolver->resolve('this-host-does-not-exist.invalid');

    // Then
    expect($ips)->toBe([]);
});
