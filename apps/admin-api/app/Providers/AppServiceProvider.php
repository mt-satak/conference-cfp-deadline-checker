<?php

namespace App\Providers;

use App\Domain\Build\BuildStatusReader;
use App\Domain\Build\BuildTriggerer;
use App\Domain\Categories\CategoryRepository;
use App\Domain\Conferences\ConferenceRepository;
use App\Infrastructure\Amplify\AmplifyBuildStatusReader;
use App\Infrastructure\Amplify\AmplifyBuildTriggerer;
use App\Infrastructure\DynamoDb\DynamoDbCategoryRepository;
use App\Infrastructure\DynamoDb\DynamoDbConferenceRepository;
use Aws\Amplify\AmplifyClient;
use Aws\DynamoDb\DynamoDbClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
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
        $this->registerAmplifyClient();
        $this->registerBuildServices();
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

        $this->app->bind(CategoryRepository::class, function (Application $app): DynamoDbCategoryRepository {
            $tableName = config('dynamodb.tables.categories');

            return new DynamoDbCategoryRepository(
                $app->make(DynamoDbClient::class),
                is_string($tableName) ? $tableName : 'cfp-categories',
            );
        });
    }

    /**
     * AWS Amplify クライアントをシングルトンで登録する。
     * env() は config/amplify.php 内のみで使用する (Larastan 推奨)。
     */
    private function registerAmplifyClient(): void
    {
        $this->app->singleton(AmplifyClient::class, function (): AmplifyClient {
            $region = config('amplify.region');

            return new AmplifyClient([
                'version' => 'latest',
                'region' => is_string($region) ? $region : 'ap-northeast-1',
            ]);
        });
    }

    /**
     * Build (静的サイト再ビルド) 系の Domain interface を Amplify 実装に紐付ける。
     *
     * Amplify Webhook URL / App ID が未設定でも DI 自体は成功させ、
     * 実呼出時 (TriggerBuildUseCase / ListBuildStatusesUseCase) に
     * BuildServiceNotConfiguredException を投げる設計 (HTTP 層で 503 整形)。
     */
    private function registerBuildServices(): void
    {
        $this->app->bind(BuildTriggerer::class, function (Application $app): AmplifyBuildTriggerer {
            $webhookUrl = config('amplify.webhook_url');

            return new AmplifyBuildTriggerer(
                $app->make(ClientInterface::class),
                is_string($webhookUrl) && $webhookUrl !== '' ? $webhookUrl : null,
            );
        });

        $this->app->bind(BuildStatusReader::class, function (Application $app): AmplifyBuildStatusReader {
            $appId = config('amplify.app_id');
            $branchName = config('amplify.branch_name');

            return new AmplifyBuildStatusReader(
                $app->make(AmplifyClient::class),
                is_string($appId) && $appId !== '' ? $appId : null,
                is_string($branchName) ? $branchName : 'main',
            );
        });

        // Guzzle HTTP Client を ClientInterface 経由で解決可能にする
        // (Build 以外でも将来 HTTP 連携が増えた場合に共有できる)
        $this->app->singleton(ClientInterface::class, fn (): GuzzleClient => new GuzzleClient);
    }
}
