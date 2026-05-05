<?php

namespace App\Infrastructure\Http;

use App\Application\Conferences\Extraction\HtmlFetchFailedException;

/**
 * 本番用 HostValidator: DNS 解決 + IP プライベートレンジ判定 (Issue #40 Phase 3 PR-2)。
 *
 * SSRF 防御の核。検証手順:
 * 1. host が IP リテラル (10.0.0.1 / [::1] 等) なら直接 IP チェック
 * 2. ホスト名なら DnsResolver で A/AAAA 全て解決し、すべての IP が公開レンジか確認
 * 3. プライベートレンジ (RFC 1918) / loopback / link-local / metadata endpoint は拒否
 *
 * 拒否される代表例:
 * - 127.0.0.1, ::1 (loopback)
 * - 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16 (RFC 1918 プライベート)
 * - 169.254.0.0/16 (link-local: AWS EC2 metadata 169.254.169.254 を含む)
 * - fc00::/7 (IPv6 unique local)
 * - fe80::/10 (IPv6 link-local)
 *
 * DnsResolver を DI する設計により、テスト時はモック (固定 IP リスト返却) で
 * happy path / DNS 解決失敗を分離して検証できる (= 実 DNS 依存テストを排除)。
 */
class DnsHostValidator implements HostValidator
{
    public function __construct(
        private readonly DnsResolver $resolver,
    ) {}

    public function validate(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertIpPublic($host);

            return;
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw HtmlFetchFailedException::networkError("https://{$host}", 'DNS resolution failed');
        }
        foreach ($ips as $ip) {
            $this->assertIpPublic($ip);
        }
    }

    private function assertIpPublic(string $ip): void
    {
        $publicCheck = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
        if ($publicCheck === false) {
            throw HtmlFetchFailedException::networkError(
                "https://{$ip}",
                "host resolves to non-public IP: {$ip}",
            );
        }
    }
}
