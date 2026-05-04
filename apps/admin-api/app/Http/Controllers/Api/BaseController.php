<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * admin-api 全エンドポイントの基底コントローラ。
 *
 * OpenAPI 仕様 (data/openapi.yaml) で定義されたレスポンス形式を
 * 必ず守るためのヘルパを集約する。各エンドポイントはこれを継承し、
 * ok() / created() / noContent() / error() のいずれかで応答すること。
 *
 * 形式:
 *   成功 (2xx): {"data": ..., "meta": {...}} または 204 (空ボディ)
 *   失敗 (4xx/5xx): {"error": {"code": ..., "message": ..., "details": [...]}}
 *
 * 例外発生時のレスポンス整形は別途グローバル例外ハンドラ (bootstrap/app.php) で行う。
 */
abstract class BaseController extends Controller
{
    /**
     * 200 OK レスポンス。
     *
     * @param  mixed  $data  ペイロード本体 (配列・オブジェクト・スカラ可)
     * @param  array<string, mixed>  $meta  ページネーション・件数等のメタ情報
     */
    public function ok(mixed $data, array $meta = []): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            // 空 meta でも JSON 上は {} (object) として返す
            // (OpenAPI の meta: object 型と整合させるため)
            'meta' => (object) $meta,
        ], Response::HTTP_OK);
    }

    /**
     * 201 Created レスポンス。新規作成系エンドポイント (POST) で使う。
     */
    public function created(mixed $data): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
        ], Response::HTTP_CREATED);
    }

    /**
     * 204 No Content レスポンス。削除系エンドポイント (DELETE) で使う。
     */
    public function noContent(): JsonResponse
    {
        // JsonResponse(null, 204) はボディに "null" を書いてしまうため、
        // 文字列 '' をそのまま渡してから JSON モードを無効化する
        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
        $response->setContent('');

        return $response;
    }

    /**
     * エラーレスポンス。
     *
     * @param  string  $code  OpenAPI Error.code 列挙値 (例: VALIDATION_FAILED)
     * @param  string  $message  人間可読なエラーメッセージ
     * @param  int  $status  HTTP ステータスコード
     * @param  array<int, array{field: string, rule: string}>  $details  フィールドレベル詳細 (任意)
     */
    public function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }
}
