<?php

namespace App\Providers;

use App\Application\Conferences\Extraction\ConferenceDraftExtractor;
use App\Application\Conferences\Extraction\HtmlFetcher;
use App\Domain\Build\BuildStatusReader;
use App\Domain\Build\BuildTriggerer;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryRepository;
use App\Domain\Conferences\ConferenceRepository;
use App\Infrastructure\DynamoDb\DynamoDbCategoryRepository;
use App\Infrastructure\DynamoDb\DynamoDbConferenceRepository;
use App\Infrastructure\GitHubApp\FirebaseGitHubAppClient;
use App\Infrastructure\GitHubApp\GitHubActionsBuildStatusReader;
use App\Infrastructure\GitHubApp\GitHubActionsBuildTriggerer;
use App\Infrastructure\GitHubApp\GitHubAppClient;
use App\Infrastructure\Http\DnsHostValidator;
use App\Infrastructure\Http\DnsResolver;
use App\Infrastructure\Http\HostValidator;
use App\Infrastructure\Http\LaravelHtmlFetcher;
use App\Infrastructure\Http\PhpDnsResolver;
use App\Infrastructure\LlmExtraction\BedrockConferenceDraftExtractor;
use App\Infrastructure\LlmExtraction\MockConferenceDraftExtractor;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\URL;
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
        $this->registerBuildServices();
        $this->registerLlmExtractionServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->forceUrlSchemeIfHttps();
    }

    /**
     * APP_URL が https で始まる場合、UrlGenerator の root URL とスキームを APP_URL
     * 基準で強制する。
     *
     * Issue #67: CloudFront → Lambda Function URL 経由のリクエストでは Host ヘッダが
     * Function URL ドメインに書き換わる (SigV4 の都合)。Laravel のデフォルトは
     * Host ヘッダから URL を生成するため、生成 URL がすべて Function URL ドメインを
     * 指してしまい、ブラウザから直アクセスすると AWS_IAM 認証で 403 になる。
     *
     * http (= ローカル開発) の場合は何もしない。
     */
    private function forceUrlSchemeIfHttps(): void
    {
        $appUrl = config('app.url');
        if (! is_string($appUrl) || ! str_starts_with($appUrl, 'https://')) {
            return;
        }

        URL::forceRootUrl($appUrl);
        URL::forceScheme('https');
    }

    /**
     * DynamoDB クライアントをシングルトンとして登録する。
     *
     * - 本番: Lambda 実行ロールの認証情報 (SDK のデフォルトチェーンが動的に取得)
     * - 開発: AWS_DYNAMODB_ENDPOINT を指定すると DynamoDB Local に接続
     *         (必要なら credentials も渡す)
     *
     * env() は config/dynamodb.php 内のみで使用する (Larastan 推奨)。
     */
    private function registerDynamoDbClient(): void
    {
        $this->app->singleton(DynamoDbClient::class, function (): DynamoDbClient {
            return new DynamoDbClient($this->buildDynamoDbConfig());
        });
    }

    /**
     * DynamoDbClient 用の AWS SDK config 配列を組み立てる (Issue #72)。
     *
     * credentials を渡すのは endpoint 設定時 (= DynamoDB Local) のみに限定。
     * 本番環境では渡さず SDK のデフォルトチェーンに委ねる。
     *
     * 理由: Bref 起動時の `php artisan config:cache` で env() の値が固定化される。
     * Lambda は AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY を実行ロールから自動注入
     * するが、これを config:cache 経由で固定化すると ~30-60 分後の credentials
     * rotate で `UnrecognizedClientException` (security token invalid) になる。
     * config 経由で credentials を渡さなければ、SDK が起動毎に env から動的に
     * 読み取り、rotate に追随できる (= 同じ環境変数を SDK 側が直接見る挙動)。
     *
     * @return array<string, mixed>
     */
    protected function buildDynamoDbConfig(): array
    {
        $region = config('dynamodb.region');
        $config = [
            'version' => 'latest',
            'region' => is_string($region) && $region !== '' ? $region : 'ap-northeast-1',
        ];

        $endpoint = config('dynamodb.endpoint');
        if (! is_string($endpoint) || $endpoint === '') {
            return $config;
        }

        $config['endpoint'] = $endpoint;
        $accessKey = config('dynamodb.credentials.key');
        $secretKey = config('dynamodb.credentials.secret');
        if (is_string($accessKey) && $accessKey !== '' && is_string($secretKey) && $secretKey !== '') {
            $config['credentials'] = [
                'key' => $accessKey,
                'secret' => $secretKey,
            ];
        }

        return $config;
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
     * Build (静的サイト再ビルド) 系の Domain interface を GitHub Actions 実装に
     * 紐付ける (Phase 5.3 / Issue #110)。
     *
     * AWS Amplify から GitHub Actions の workflow_dispatch 経路に切替済み。
     * 認証は GitHub App (1 時間有効な installation token) を使う。
     *
     * GitHub App の 3 値 (app_id / installation_id / private_key) が未設定でも
     * DI 自体は成功させ、実呼出時 (TriggerBuildUseCase / ListBuildStatusesUseCase)
     * に BuildServiceNotConfiguredException を投げる設計 (HTTP 層で 503 整形)。
     *
     * env() は config/github_app.php 内のみで使用する (Larastan 推奨)。
     */
    private function registerBuildServices(): void
    {
        $this->app->singleton(GitHubAppClient::class, FirebaseGitHubAppClient::class);

        $this->app->bind(BuildTriggerer::class, function (Application $app): GitHubActionsBuildTriggerer {
            return new GitHubActionsBuildTriggerer(
                client: $app->make(GitHubAppClient::class),
                appId: $this->stringConfig('github_app.app_id'),
                installationId: $this->stringConfig('github_app.installation_id'),
                privateKey: $this->stringConfig('github_app.private_key'),
                owner: $this->stringConfig('github_app.repo_owner') ?? 'mt-satak',
                repo: $this->stringConfig('github_app.repo_name') ?? 'conference-cfp-deadline-checker',
                workflowFileName: $this->stringConfig('github_app.workflow_file') ?? 'deploy.yml',
                ref: $this->stringConfig('github_app.workflow_ref') ?? 'main',
            );
        });

        $this->app->bind(BuildStatusReader::class, function (Application $app): GitHubActionsBuildStatusReader {
            return new GitHubActionsBuildStatusReader(
                client: $app->make(GitHubAppClient::class),
                appId: $this->stringConfig('github_app.app_id'),
                installationId: $this->stringConfig('github_app.installation_id'),
                privateKey: $this->stringConfig('github_app.private_key'),
                owner: $this->stringConfig('github_app.repo_owner') ?? 'mt-satak',
                repo: $this->stringConfig('github_app.repo_name') ?? 'conference-cfp-deadline-checker',
                workflowFileName: $this->stringConfig('github_app.workflow_file') ?? 'deploy.yml',
            );
        });
    }

    /**
     * config の値を string|null に正規化する小道具 (binding コールバック用)。
     */
    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * LLM URL 抽出系 (Issue #40 Phase 3) の interface を実装に紐付ける。
     *
     * - HostValidator: 常に DnsHostValidator (= SSRF 防御層、本番もテストも同じ)
     * - HtmlFetcher: 常に LaravelHtmlFetcher (= Laravel HTTP facade ベース)
     * - ConferenceDraftExtractor: LLM_PROVIDER 環境変数で切替
     *   - mock (default): MockConferenceDraftExtractor (固定レスポンス、AWS 不要)
     *   - bedrock: BedrockConferenceDraftExtractor (本番、IAM ロールで Bedrock 認証)
     *
     * Bedrock 利用時の availableSlugs (= categorySlugs 候補) は CategoryRepository から
     * findAll() で読み込んで slug のみ抽出して渡す。CategoryRepository は DynamoDB バック
     * なのでカテゴリ追加時に prompt cache がリセットされるが、本機能の利用頻度が低い
     * (= 抽出 1 回 / 数日〜数週間レベル) のためコスト影響は微少。
     *
     * env() は config/llm.php 内のみで使用する (Larastan 推奨)。
     */
    private function registerLlmExtractionServices(): void
    {
        $this->app->bind(DnsResolver::class, PhpDnsResolver::class);
        $this->app->bind(HostValidator::class, DnsHostValidator::class);

        $this->app->bind(HtmlFetcher::class, function (Application $app): LaravelHtmlFetcher {
            return new LaravelHtmlFetcher($app->make(HostValidator::class));
        });

        $this->app->singleton(BedrockRuntimeClient::class, function (): BedrockRuntimeClient {
            $region = config('llm.region');

            return new BedrockRuntimeClient([
                'version' => 'latest',
                'region' => is_string($region) ? $region : 'ap-northeast-1',
            ]);
        });

        $this->app->bind(ConferenceDraftExtractor::class, function (Application $app): ConferenceDraftExtractor {
            $provider = config('llm.provider');

            if ($provider !== 'bedrock') {
                return new MockConferenceDraftExtractor;
            }

            $modelId = config('llm.model');
            /** @var Category[] $categories */
            $categories = $app->make(CategoryRepository::class)->findAll();
            $availableSlugs = array_values(array_map(
                static fn ($c): string => $c->slug,
                $categories,
            ));

            return new BedrockConferenceDraftExtractor(
                client: $app->make(BedrockRuntimeClient::class),
                modelId: is_string($modelId) ? $modelId : 'anthropic.claude-sonnet-4-6',
                availableSlugs: $availableSlugs,
            );
        });
    }
}
