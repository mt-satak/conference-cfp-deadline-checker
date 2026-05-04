<?php

namespace App\Providers;

use App\Domain\Conferences\ConferenceRepository;
use App\Infrastructure\DynamoDb\DynamoDbConferenceRepository;
use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Contracts\Foundation\Application;
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
     *
     * env() は config/dynamodb.php 内のみで使用する (Larastan 推奨)。
     */
    private function registerDynamoDbClient(): void
    {
        $this->app->singleton(DynamoDbClient::class, function (): DynamoDbClient {
            $region = config('dynamodb.region');
            $config = [
                'version' => 'latest',
                'region' => is_string($region) ? $region : 'ap-northeast-1',
            ];

            // AWS_ACCESS_KEY_ID と AWS_SECRET_ACCESS_KEY が両方設定されている時のみ
            // 明示的に credentials を渡す。空 / 未設定の場合は SDK のデフォルトチェーン
            // (Lambda 実行ロール / 環境変数 / IAM ロール 等) に委ねる。
            $accessKey = config('dynamodb.credentials.key');
            $secretKey = config('dynamodb.credentials.secret');
            if (is_string($accessKey) && $accessKey !== '' && is_string($secretKey) && $secretKey !== '') {
                $config['credentials'] = [
                    'key' => $accessKey,
                    'secret' => $secretKey,
                ];
            }

            $endpoint = config('dynamodb.endpoint');
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
        $this->app->bind(ConferenceRepository::class, function (Application $app): DynamoDbConferenceRepository {
            $tableName = config('dynamodb.tables.conferences');

            return new DynamoDbConferenceRepository(
                $app->make(DynamoDbClient::class),
                is_string($tableName) ? $tableName : 'cfp-conferences',
            );
        });
    }
}
