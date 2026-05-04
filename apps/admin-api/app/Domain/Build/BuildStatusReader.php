<?php

namespace App\Domain\Build;

/**
 * ビルド状態の読み出し境界 (interface)。
 *
 * Application 層から Infrastructure 層 (AWS Amplify ListJobs API 等) を呼び出す契約。
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
