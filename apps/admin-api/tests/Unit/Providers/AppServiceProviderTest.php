<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;

/**
 * AppServiceProvider::boot() の URL 強制ロジックの単体テスト。
 *
 * 背景 (Issue #67):
 * CloudFront → Lambda Function URL 経由のリクエストでは Host ヘッダが
 * Function URL ドメインに書き換わる (SigV4 の都合)。Laravel のデフォルト
 * は Host ヘッダから URL を生成するため、生成 URL がすべて Function URL を
 * 指してしまい、ブラウザから直アクセスすると AWS_IAM 認証で 403 になる。
 *
 * 対策として APP_URL が https で始まる場合のみ forceRootUrl + forceScheme を
 * 適用する。http の場合 (= ローカル開発) は何もしない。
 */
it('APP_URL が https の場合 forceRootUrl と forceScheme を呼ぶ', function () {
    // Given: APP_URL が CloudFront ドメイン (https)
    config(['app.url' => 'https://cf.example.com']);
    URL::shouldReceive('forceRootUrl')->with('https://cf.example.com')->once();
    URL::shouldReceive('forceScheme')->with('https')->once();

    // When: AppServiceProvider::boot() を実行
    (new AppServiceProvider($this->app))->boot();

    // Then: Mockery が shouldReceive を自動検証する
});

it('APP_URL が http の場合 forceRootUrl は呼ばれない (ローカル開発を壊さない)', function () {
    // Given: APP_URL が http (= ローカル開発、`php artisan serve` 等)
    config(['app.url' => 'http://localhost:8000']);
    URL::shouldReceive('forceRootUrl')->never();
    URL::shouldReceive('forceScheme')->never();

    // When: AppServiceProvider::boot() を実行
    (new AppServiceProvider($this->app))->boot();

    // Then: never() が満たされる
});

/**
 * DynamoDB クライアント config 構築ロジックの単体テスト (Issue #72)。
 *
 * 背景:
 * 本番 Lambda では Bref 起動時に `php artisan config:cache` が実行され、
 * env() の値が固定化される。Lambda は実行ロールから取得した一時 credentials を
 * AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY 環境変数に自動注入するが、
 * これを config:cache 経由で固定化すると ~30-60 分後の rotate 時に失効し
 * `UnrecognizedClientException` (security token invalid) を引き起こす。
 *
 * 対策として、credentials を渡すのは endpoint 設定時 (= DynamoDB Local 接続時)
 * のみに限定し、本番環境では SDK のデフォルトチェーン (動的取得) に委ねる。
 */
it('本番想定 (endpoint 未設定) では config に credentials も endpoint も含めない', function () {
    // Given: 本番の Lambda 環境を想定。endpoint なし、しかし
    //        Lambda が AWS_ACCESS_KEY_ID 等を自動注入したケース。
    config([
        'dynamodb.region' => 'ap-northeast-1',
        'dynamodb.endpoint' => null,
        'dynamodb.credentials.key' => 'lambda-injected-temp-key',
        'dynamodb.credentials.secret' => 'lambda-injected-temp-secret',
    ]);
    $provider = new class($this->app) extends AppServiceProvider
    {
        /** @return array<string, mixed> */
        public function buildDynamoDbConfigForTest(): array
        {
            return $this->buildDynamoDbConfig();
        }
    };

    // When: config を組み立てる
    $config = $provider->buildDynamoDbConfigForTest();

    // Then: credentials も endpoint も渡さない (= SDK デフォルトチェーンに委ねる)
    expect($config)->not->toHaveKey('credentials');
    expect($config)->not->toHaveKey('endpoint');
    expect($config['region'])->toBe('ap-northeast-1');
    expect($config['version'])->toBe('latest');
});

it('開発環境 (endpoint 設定 + credentials 設定) では endpoint と credentials を含める', function () {
    // Given: DynamoDB Local 接続
    config([
        'dynamodb.region' => 'ap-northeast-1',
        'dynamodb.endpoint' => 'http://localhost:8000',
        'dynamodb.credentials.key' => 'dev-key',
        'dynamodb.credentials.secret' => 'dev-secret',
    ]);
    $provider = new class($this->app) extends AppServiceProvider
    {
        /** @return array<string, mixed> */
        public function buildDynamoDbConfigForTest(): array
        {
            return $this->buildDynamoDbConfig();
        }
    };

    // When
    /** @var array<string, mixed> $config */
    $config = $provider->buildDynamoDbConfigForTest();

    // Then
    expect($config['endpoint'])->toBe('http://localhost:8000');
    expect($config['credentials'])->toBe([
        'key' => 'dev-key',
        'secret' => 'dev-secret',
    ]);
});

it('endpoint 設定 + credentials なしの場合は endpoint だけ渡し credentials は含めない', function () {
    // Given: DynamoDB Local エンドポイントだけ指定 (= IAM 認証無し DynamoDB Local 等)
    config([
        'dynamodb.region' => 'ap-northeast-1',
        'dynamodb.endpoint' => 'http://localhost:8000',
        'dynamodb.credentials.key' => null,
        'dynamodb.credentials.secret' => null,
    ]);
    $provider = new class($this->app) extends AppServiceProvider
    {
        /** @return array<string, mixed> */
        public function buildDynamoDbConfigForTest(): array
        {
            return $this->buildDynamoDbConfig();
        }
    };

    // When
    /** @var array<string, mixed> $config */
    $config = $provider->buildDynamoDbConfigForTest();

    // Then
    expect($config['endpoint'])->toBe('http://localhost:8000');
    expect($config)->not->toHaveKey('credentials');
});
