<?php

namespace App\Infrastructure\Http;

/**
 * ホスト名 → IP 解決の小さな抽象 (Issue #40 Phase 3 PR-2)。
 *
 * DnsHostValidator から DNS 解決処理を分離してテスト容易性を確保する。
 * 本番: PhpDnsResolver (dns_get_record)
 * テスト: 固定値を返す stub
 */
interface DnsResolver
{
    /**
     * 与えられたホスト名を解決して A / AAAA レコードの IP 文字列配列を返す。
     * 解決失敗時は空配列。例外は投げない。
     *
     * @return string[]
     */
    public function resolve(string $host): array;
}
