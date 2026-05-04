<?php

namespace App\Infrastructure\Amplify;

use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildTriggerer;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * BuildTriggerer の AWS Amplify Webhook 実装。
 *
 * Amplify Webhook URL は Amplify コンソールの「ビルドの設定」→「ビルド トリガー」
 * から発行される (例: https://webhooks.amplify.{region}.amazonaws.com/prod/webhooks/{token})。
 * 本実装はそこへ POST するだけのシンプルな HTTP クライアント呼出。
 *
 * セキュリティ: architecture.md §11.2 S8 の通り、Webhook URL は Secrets Manager
 * 経由 / Lambda 環境変数で持ち、コードに残さない。本実装は config('amplify.webhook_url')
 * 経由で取得する。
 */
class AmplifyBuildTriggerer implements BuildTriggerer
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly ?string $webhookUrl,
    ) {}

    /**
     * @throws BuildServiceNotConfiguredException
     */
    public function trigger(): string
    {
        if ($this->webhookUrl === null || $this->webhookUrl === '') {
            throw BuildServiceNotConfiguredException::webhookUrlMissing();
        }

        $response = $this->httpClient->request('POST', $this->webhookUrl, [
            // Amplify Webhook は body 不要だが Content-Type だけ送る
            'headers' => ['Content-Type' => 'application/json'],
            'body' => '{}',
            // 上限 10 秒 (Webhook は受付即返却なので短くて良い)
            'timeout' => 10,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            // 200 系以外は予期せぬ応答。詳細メッセージ付きで例外化し、
            // HTTP 層では 500 INTERNAL_ERROR として扱う (ユーザー復旧不能のため)。
            throw new RuntimeException("Amplify webhook returned unexpected status: {$statusCode}");
        }

        return Carbon::now('Asia/Tokyo')->toIso8601String();
    }
}
