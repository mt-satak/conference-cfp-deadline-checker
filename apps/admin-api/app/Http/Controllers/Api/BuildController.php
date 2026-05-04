<?php

namespace App\Http\Controllers\Api;

use App\Application\Build\ListBuildStatusesUseCase;
use App\Application\Build\TriggerBuildUseCase;
use App\Domain\Build\BuildStatus;
use App\Http\Presenters\BuildStatusPresenter;
use Illuminate\Http\JsonResponse;

/**
 * Build (静的サイト再ビルド) リソースの HTTP エンドポイント群。
 *
 * 設計方針 (Standard DDD):
 * - HTTP リクエスト/レスポンスの変換のみを担当
 * - ビルド起動 / ステータス取得は UseCase に委譲
 * - 永続化先は外部サービス (AWS Amplify) で、Domain 層 interface 経由で抽象化
 *
 * OpenAPI 仕様 (data/openapi.yaml) の Build タグ参照。
 *
 * 503 SERVICE_UNAVAILABLE: Amplify アプリ未構成時。Domain 層が
 * BuildServiceNotConfiguredException を投げ、AdminApiExceptionRenderer が整形する。
 */
class BuildController extends BaseController
{
    /**
     * POST /admin/api/build/trigger (operationId: triggerBuild)
     *
     * 202 Accepted: 非同期受付。受付時刻 (Asia/Tokyo) を返す。
     * 503: Amplify Webhook URL 未構成時。
     */
    public function trigger(TriggerBuildUseCase $useCase): JsonResponse
    {
        $requestedAt = $useCase->execute();

        return $this->accepted([
            'requestedAt' => $requestedAt,
            'message' => 'Build triggered. It typically takes 1-2 minutes to complete.',
        ]);
    }

    /**
     * GET /admin/api/build/status (operationId: getBuildStatus)
     *
     * 200: 直近 10 件までのビルド履歴を新しい順で返す。
     *      meta.latestStatus に最新ジョブのステータス (履歴 0 件なら省略)。
     * 503: Amplify アプリ ID 未構成時。
     */
    public function status(ListBuildStatusesUseCase $useCase): JsonResponse
    {
        $statuses = $useCase->execute();

        $data = array_map(
            static fn (BuildStatus $s): array => BuildStatusPresenter::toArray($s),
            $statuses,
        );

        $meta = [];
        if ($statuses !== []) {
            // 取得結果は新しい順想定なので先頭が最新
            $meta['latestStatus'] = $statuses[0]->status->value;
        }

        return $this->ok($data, $meta);
    }
}
