<?php

namespace App\Domain\Build;

/**
 * ビルドの起動を担う境界 (interface)。
 *
 * Application 層から Infrastructure 層 (GitHub Actions workflow_dispatch 等) を
 * 呼び出す契約。実装は外部サービス連携 (Phase 5.3 で AWS Amplify から GitHub
 * Actions へ移行済) に置き換え可能。
 */
interface BuildTriggerer
{
    /**
     * ビルドをトリガーする (非同期、即時返却)。
     *
     * @return string ビルド要求の受付時刻 (ISO 8601、Asia/Tokyo)
     *
     * @throws BuildServiceNotConfiguredException サービス未構成
     */
    public function trigger(): string;
}
