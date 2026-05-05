<?php

namespace App\Infrastructure\Http;

/**
 * dns_get_record をラップする本番用 DnsResolver 実装 (Issue #40 Phase 3 PR-2)。
 *
 * A (IPv4) / AAAA (IPv6) の両方を取得して返す。
 * 解決失敗 / レコード無しは空配列で返し、呼び出し元が判断する。
 */
class PhpDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            return [];
        }
        $ips = [];
        foreach ($records as $rec) {
            if (isset($rec['ip']) && is_string($rec['ip'])) {
                $ips[] = $rec['ip'];
            } elseif (isset($rec['ipv6']) && is_string($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }

        return $ips;
    }
}
