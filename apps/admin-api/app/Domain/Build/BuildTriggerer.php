<?php

namespace App\Domain\Build;

/**
 * ビルドの起動を担う境界 (interface)。
 *
 * Application 層から Infrastructure 層 (AWS Amplify Webhook 等) を呼び出す契約。
 * 実装は外部サービス連携 (Amplify / 他 CI 等) に置き換え可能。
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
