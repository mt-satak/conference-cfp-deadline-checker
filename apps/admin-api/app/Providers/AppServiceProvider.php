<?php

namespace App\Providers;

use App\Domain\Conferences\ConferenceRepository;
use App\Infrastructure\DynamoDb\DynamoDbConferenceRepository;
use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerDynamoDbClient();
        $this->registerRepositories();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * DynamoDB クライアントをシングルトンとして登録する。
     *
     * - 本番: Lambda 実行ロールの認証情報 (key / secret は空、SDK が自動取得)
     * - 開発: AWS_DYNAMODB_ENDPOINT を指定すると DynamoDB Local に接続
     *         (ダミー認証情報を渡す)
     */
    private function registerDynamoDbClient(): void
    {
        $this->app->singleton(DynamoDbClient::class, function () {
            $config = [
                'version' => 'latest',
                'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
            ];

            // AWS_ACCESS_KEY_ID と AWS_SECRET_ACCESS_KEY が両方設定されている時のみ
            // 明示的に credentials を渡す。空 / 未設定の場合は SDK のデフォルトチェーン
            // (Lambda 実行ロール / 環境変数 / IAM ロール 等) に委ねる。
            $accessKey = env('AWS_ACCESS_KEY_ID');
            $secretKey = env('AWS_SECRET_ACCESS_KEY');
            if ($accessKey !== null && $accessKey !== '' && $secretKey !== null && $secretKey !== '') {
                $config['credentials'] = [
                    'key' => $accessKey,
                    'secret' => $secretKey,
                ];
            }

            $endpoint = env('AWS_DYNAMODB_ENDPOINT');
            if (is_string($endpoint) && $endpoint !== '') {
                $config['endpoint'] = $endpoint;
            }

            return new DynamoDbClient($config);
        });
    }

    /**
     * Domain 層 (interface) と Infrastructure 層 (実装) の紐付けを登録する。
     */
    private function registerRepositories(): void
    {
        $this->app->bind(ConferenceRepository::class, function ($app) {
            return new DynamoDbConferenceRepository(
                $app->make(DynamoDbClient::class),
                env('DYNAMODB_CONFERENCES_TABLE', 'cfp-conferences'),
            );
        });
    }
}
