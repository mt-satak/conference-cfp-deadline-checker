<?php

namespace App\Infrastructure\Http;

use App\Application\Conferences\Extraction\HtmlFetchFailedException;

/**
 * SSRF 防御のためのホスト検証ポート (Issue #40 Phase 3 PR-2)。
 *
 * LaravelHtmlFetcher が依存し、DI で本番 / テスト実装を切替可能にする。
 * 本番: DnsHostValidator (DNS 解決 → IP プライベートレンジ判定)
 * テスト: 自由に許可・拒否を制御する Stub を使う (= Http::fake() より先に
 * 検証が走るので、テスト用に検証パスを bypass する手段が必要)
 */
interface HostValidator
{
    /**
     * @throws HtmlFetchFailedException ホストが拒否対象の場合
     */
    public function validate(string $host): void;
}
