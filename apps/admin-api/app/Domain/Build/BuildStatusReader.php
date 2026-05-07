<?php

namespace App\Domain\Build;

/**
 * ビルド状態の読み出し境界 (interface)。
 *
 * Application 層から Infrastructure 層 (GitHub Actions workflow runs API 等) を
 * 呼び出す契約。Phase 5.3 で AWS Amplify から GitHub Actions へ移行済。
 */
interface BuildStatusReader
{
    /**
     * 直近のビルドジョブを最大 limit 件まで取得する (新しい順)。
     *
     * @return BuildStatus[]
     *
     * @throws BuildServiceNotConfiguredException サービス未構成
     */
    public function listRecent(int $limit): array;
}
