import * as path from 'node:path';
import { Duration, Stack } from 'aws-cdk-lib';
import { type Table } from 'aws-cdk-lib/aws-dynamodb';
import { Effect, PolicyStatement } from 'aws-cdk-lib/aws-iam';
import {
  Architecture,
  Code,
  Function as LambdaFunction,
  FunctionUrlAuthType,
  type IFunctionUrl,
  LayerVersion,
  Runtime,
} from 'aws-cdk-lib/aws-lambda';
import { Secret } from 'aws-cdk-lib/aws-secretsmanager';
import { Construct } from 'constructs';

/**
 * AdminApi Construct のオプション
 *
 * conferences / categories: 管理 API がアクセスする DynamoDB テーブル。
 * これらに対する読み書き権限が Lambda 実行ロールへ自動付与される。
 */
export interface AdminApiProps {
  readonly conferences: Table;
  readonly categories: Table;
}

/**
 * 管理 API (PHP / Laravel + Bref) Lambda Construct
 *
 * 構成要素:
 * - Bref が公開する PHP-FPM ランタイム層 (php-85 統合レイヤー) を利用
 * - 公開コードは apps/admin-api/ を Code.fromAsset で参照
 * - DynamoDB 3 テーブルへの最小権限を付与
 * - Function URL (AWS_IAM 認証) を発行。CloudFront から OAC 経由でのみ
 *   呼び出せるようにし、URL の直接ヒットを防ぐ
 *
 * NOTE:
 * Bref 3.x からは php-XX-fpm の専用レイヤーは廃止され、php-XX 単一レイヤーが
 * Function / FPM / Console すべてのモードに対応する仕様に変更された。
 * モードはハンドラ名で判定され、Laravel のような Web アプリは
 * `public/index.php` を指定すると FPM モードで起動する。
 *
 * 採用バージョン:
 * - PHP 8.5 (Bref が現時点でサポートする最新安定版)
 * - php-85 layer version 14 (ap-northeast-1)
 *
 * Bref のレイヤーバージョンは https://runtimes.bref.sh/?region=ap-northeast-1
 * で随時更新されるため、デプロイ前に最新版確認を推奨。
 */
export class AdminApi extends Construct {
  public readonly function: LambdaFunction;
  public readonly functionUrl: IFunctionUrl;

  constructor(scope: Construct, id: string, props: AdminApiProps) {
    super(scope, id);

    const region = Stack.of(this).region;

    // ── Laravel APP_KEY 用 Secrets Manager シークレット ──
    // Laravel は暗号化用に 32 byte (256 bit) key を APP_KEY 環境変数で要求する
    // (Encrypter::supported() 内で `mb_strlen($key, '8bit') === 32` を validation)。
    // コードに直接書かず Secrets Manager で管理することでローテーションとアクセス
    // 監査を可能にする。
    //
    // 値の形式について:
    //   `excludePunctuation: true` で生成される 32 文字は ASCII 英数字のみのため
    //   1 文字 = 1 byte、生 32 byte として APP_KEY に渡せる。Laravel docs 推奨の
    //   `base64:<encoded>` 形式は使わない。`base64:` prefix 付きで 32 文字の
    //   random string を渡すと Laravel が base64_decode して 24 byte になり、
    //   `Unsupported cipher or incorrect key length` で起動失敗する。
    const appKeySecret = new Secret(this, 'AppKeySecret', {
      secretName: 'cfp/admin-api-app-key',
      description: 'Laravel APP_KEY for admin-api Lambda (raw 32-byte ASCII)',
      generateSecretString: {
        passwordLength: 32,
        excludePunctuation: true,
      },
    });

    // Bref の統合 PHP レイヤー (公開アカウント ID 873528684822 は Bref のもの)。
    // 旧アカウント 534081306603 は廃止済み: 取得しようとすると Lambda 側で
    // GetLayerVersion が 403 (no resource-based policy) で失敗する。
    // 最新の ARN / バージョンは https://runtimes.bref.sh/?region=ap-northeast-1 で確認。
    const phpLayer = LayerVersion.fromLayerVersionArn(
      this,
      'PhpLayer',
      `arn:aws:lambda:${region}:873528684822:layer:php-85:14`,
    );

    // Lambda 関数本体。
    // PROVIDED_AL2023 ランタイム + Bref レイヤーで PHP 8.5 を実行。
    // ハンドラ `public/index.php` は Bref の FPM モードを起動するシグナル。
    // 現時点ではプレースホルダーのため 503 を返すのみ。
    this.function = new LambdaFunction(this, 'Function', {
      runtime: Runtime.PROVIDED_AL2023,
      handler: 'public/index.php',
      code: Code.fromAsset(
        path.join(__dirname, '..', '..', '..', '..', 'apps', 'admin-api'),
        {
          // Lambda の unzipped 250MB 制限に収めるため、ランタイム不要なファイルを除外。
          // 本番デプロイは composer install --no-dev で行う前提だが、誤って dev 含み
          // で deploy された場合も exclude で防御する。
          exclude: [
            // テスト系
            'tests/**',
            'phpunit.xml',
            'phpstan.neon',
            'phpstan-baseline.neon',
            // ローカル / 開発時のキャッシュ・出力
            'storage/coverage/**',
            'storage/logs/**',
            'storage/framework/cache/**',
            'storage/framework/sessions/**',
            'storage/framework/views/**',
            'storage/framework/testing/**',
            // ローカル DB ファイル
            'database/database.sqlite',
            // dev 用 node 関連 (Vite / Tailwind 等、Lambda では使わない)
            // node_modules はランタイム不要。
            // public/build は Vite で生成された manifest.json + assets で、Blade
            // テンプレートの `@vite` ディレクティブが解決時に参照するため Lambda
            // にも含める (= exclude しない)。デプロイ前に `npm run build` 必須。
            // public/hot は Vite dev server 起動中のみ作られるマーカーで本番不要。
            'node_modules/**',
            'public/hot',
            // Composer / git 内部
            'composer.lock',
            '.git/**',
            // .env はランタイム環境変数で渡すため不要 (誤コミット時の二重防御)
            '.env',
            '.env.example',
            '.env.testing',
            // dev tools
            'scripts/**',
            'Makefile',
            'README.md',
            // IDE / OS
            '.idea/**',
            '.vscode/**',
            '.DS_Store',
            // dev only PHP packages の残骸 (composer install --no-dev 前提だが念のため)
            'vendor/pestphp/**',
            'vendor/phpstan/**',
            'vendor/larastan/**',
            'vendor/laravel/pint/**',
            'vendor/mockery/**',
            'vendor/nunomaduro/collision/**',
            'vendor/fakerphp/**',
            'vendor/laravel/pail/**',
            'vendor/laravel/pao/**',
          ],
        },
      ),
      layers: [phpLayer],
      architecture: Architecture.X86_64,
      // CloudFront のオリジン応答タイムアウト (デフォルト 30 秒) より少し短くする
      timeout: Duration.seconds(28),
      memorySize: 1024,
      environment: {
        // Bref の標準環境変数
        // Bref v3 では BREF_RUNTIME を明示しないと bootstrap.php が失敗する。
        // Laravel + PHP-FPM (Web) は 'fpm' を指定する。値の選択肢:
        //   - 'function': 単純な PHP 関数ハンドラ
        //   - 'fpm': PHP-FPM (Laravel/Symfony 等の Web フレームワーク)
        //   - 'console': Symfony/Laravel artisan/console コマンド
        BREF_RUNTIME: 'fpm',
        BREF_LOOP_MAX: '250',
        // ── Laravel ランタイム環境変数 ──
        // .env はバンドルされないため (誤コミット防止)、本番では Lambda 環境変数
        // ですべて渡す。
        // APP_KEY は Secrets Manager で管理する 32 文字 (= 32 byte) の ASCII 英数字を
        // 生のまま渡す (`base64:` prefix なし)。詳細は AppKeySecret 定義のコメント参照。
        APP_KEY: appKeySecret.secretValue.unsafeUnwrap(),
        APP_ENV: 'production',
        APP_DEBUG: 'false',
        // Lambda の filesystem は read-only (`/tmp` 以外) なので、file 系の
        // session/cache driver は使わず array に倒す。永続化が必要になったら
        // DynamoDB driver に切り替える。
        SESSION_DRIVER: 'array',
        CACHE_STORE: 'array',
        // 管理 API が参照する DynamoDB テーブル名 (Lambda 実行時に解決)
        DYNAMODB_CONFERENCES_TABLE: props.conferences.tableName,
        DYNAMODB_CATEGORIES_TABLE: props.categories.tableName,
        // LLM URL 抽出 (Issue #40 Phase 3): 本番は Bedrock 経由、API キー不要
        // ap-northeast-1 では foundation model 直接呼び出しは不可 (on-demand 非対応)、
        // 横断推論プロファイル経由が必須。`jp.*` 系は日本国内に閉じた推論で
        // データレジデンシを確保 (HTML が国外に出ない)。
        // 検証コマンド (deploy 前):
        //   aws bedrock list-inference-profiles --region ap-northeast-1 \
        //     --query "inferenceProfileSummaries[?contains(inferenceProfileId, 'sonnet-4-6')]"
        LLM_PROVIDER: 'bedrock',
        LLM_MODEL: 'jp.anthropic.claude-sonnet-4-6',
        LLM_REGION: region,
      },
      description: 'Admin API for Conference CfP Deadline Checker',
    });

    // DynamoDB の読み書き権限を最小スコープで付与。
    // grantReadWriteData は GetItem/PutItem/UpdateItem/DeleteItem/Scan/Query 等を許可。
    props.conferences.grantReadWriteData(this.function);
    props.categories.grantReadWriteData(this.function);

    // Bedrock InvokeModel 権限を付与 (Issue #40 Phase 3 LLM URL 抽出機能用)。
    // 利用モデルは Claude Sonnet 4.6 (anthropic.claude-sonnet-4-6 系) のみに限定。
    // リソース ARN は本リージョン内 + 横断推論プロファイル両方を許可するため、
    // foundation-model と inference-profile の両 ARN を含める。
    // 万が一プロンプトが他モデルを指定するコードが書かれても、IAM レベルで
    // 本許可外のモデルは呼び出せない (= 最小権限の原則)。
    this.function.addToRolePolicy(
      new PolicyStatement({
        effect: Effect.ALLOW,
        actions: ['bedrock:InvokeModel', 'bedrock:Converse'],
        resources: [
          `arn:aws:bedrock:${region}::foundation-model/anthropic.claude-sonnet-4-6*`,
          `arn:aws:bedrock:${region}:*:inference-profile/*anthropic.claude-sonnet-4-6*`,
          // 横断推論で他リージョンが参照される場合のフォールバック (apac.* 等)
          `arn:aws:bedrock:*::foundation-model/anthropic.claude-sonnet-4-6*`,
        ],
      }),
    );

    // Function URL を AWS_IAM 認証で発行。
    // CloudFront 側で OAC (Origin Access Control) を設定することで、
    // CloudFront 経由のリクエストだけが署名付きで関数 URL を呼び出せるようになる。
    // 直接 Function URL を叩いても 403 になるため、Lambda@Edge の Basic 認証を
    // バイパスされるリスクを排除できる。
    this.functionUrl = this.function.addFunctionUrl({
      authType: FunctionUrlAuthType.AWS_IAM,
    });
  }
}
