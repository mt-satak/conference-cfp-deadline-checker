<?php

/**
 * LLM URL 抽出 (Issue #40 Phase 3) 用設定。
 *
 * env() の直接参照は本ファイル内に閉じ込める (Larastan 推奨、config キャッシュ後の
 * 動作不能を防ぐ)。アプリ側は config('llm.xxx') 経由で参照する。
 *
 * セキュリティ方針 (project memory `project_no_api_keys_policy.md` 準拠):
 * - 長期 API キー (Anthropic / OpenAI 等) は本ファイル / .env / コードに置かない
 * - LLM 認証は AWS Bedrock 経由のみ (= IAM ロール / SSO の短期トークン)
 * - 本ファイルにはモデル ID / プロバイダ切替フラグ / リージョンのみ
 */
return [
    /*
     * LLM プロバイダ切替。
     * - mock (デフォルト): ローカル開発・テスト用 Stub。AWS 不要、決定論的レスポンス
     * - bedrock: AWS Bedrock の Claude Sonnet 4.6 (本番 + ローカル本番検証用)
     */
    'provider' => env('LLM_PROVIDER', 'mock'),

    /*
     * Bedrock モデル ID。
     * 例: anthropic.claude-sonnet-4-6-20251010-v1:0
     * リージョン横断推論プロファイル ID (apac.anthropic.claude-sonnet-4-6...) は
     * Tokyo region では推奨される。デプロイ環境ごとに調整する。
     */
    'model' => env('LLM_MODEL', 'anthropic.claude-sonnet-4-6'),

    /*
     * AWS リージョン。Bedrock 用。dynamodb.php と同じ値が一般的だが、Bedrock は
     * モデル可用性がリージョン依存なので独立に設定できるようにする。
     */
    'region' => env('LLM_REGION', env('AWS_DEFAULT_REGION', 'ap-northeast-1')),
];
