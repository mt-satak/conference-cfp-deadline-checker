import * as path from 'node:path';
import { Duration, Stack } from 'aws-cdk-lib';
import { type Table } from 'aws-cdk-lib/aws-dynamodb';
import { Rule, RuleTargetInput, Schedule } from 'aws-cdk-lib/aws-events';
import { LambdaFunction as LambdaTarget } from 'aws-cdk-lib/aws-events-targets';
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
  /**
   * Laravel APP_URL (Issue #67)。
   *
   * AppServiceProvider::boot() の `URL::forceRootUrl()` の引数に渡す。
   * https で始まる文字列を渡すと URL Generator が APP_URL ベースで URL を
   * 生成するようになり、CloudFront → Lambda Function URL 転送で Host が
   * 書き換わっても CSS/JS/ナビゲーションが正しく解決される。
   *
   * StaticSiteDistribution.distributionDomainName を CFN ref で参照すると
   * AdminApi → Distribution → AdminApiFunctionUrl → AdminApi の循環参照に
   * なるため、bin/ 側で文字列として確定したものを props で受け取る方式にする。
   */
  readonly appUrl: string;
  /**
   * CloudFront Custom Origin Header `X-CloudFront-Secret` の値 (Issue #77)。
   *
   * Lambda Function URL の AuthType を NONE に切り替えたため、CloudFront 経由か
   * 直アクセスかを判別するための secret。Lambda 環境変数 `CLOUDFRONT_ORIGIN_SECRET`
   * として渡され、Laravel の CloudFrontSecretMiddleware が検証する。
   *
   * StaticSite 側でも同じ secret を CloudFront → Function URL の Custom Origin
   * Header に仕込む必要があるため、MainStack で Secret を 1 つ生成して両方に
   * 渡す形を採る。
   */
  readonly cloudfrontOriginSecret: string;
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
  /**
   * 自動巡回 (Issue #152 Phase 1) 用 Lambda 関数。
   *
   * 既存 admin-api Lambda と同じ asset (= apps/admin-api/) を共有しつつ、
   * handler を `artisan` (Bref console mode) に切り替えて artisan コマンドを
   * 実行する。EventBridge schedule (週 1) から起動される。
   */
  public readonly autoCrawlFunction: LambdaFunction;

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

    // ── GitHub App 認証用 Secrets Manager シークレット (Phase 5.3 / Issue #110) ──
    // admin UI のビルドボタンから GitHub Actions deploy.yml を workflow_dispatch
    // で起動するため、GitHub App の認証 3 値 (app_id, installation_id, private_key)
    // を Secrets Manager に置く。長期 PAT を Lambda 上に置かないために GitHub App
    // 経由で 1 時間有効な installation token を都度発行する設計
    // (memory project_no_api_keys_policy.md の方針に整合)。
    //
    // この Secret は CDK 管理外 (= AWS console / CLI で事前作成、CDK は参照のみ)
    // とする。理由:
    //  - private_key は GitHub App 設定画面で発行された .pem ファイルの中身で、
    //    CDK のコードに焼くと CloudFormation テンプレートに値が残ってしまう
    //  - cdk destroy で誤って消えると GitHub App の private_key を再発行する手間が
    //    かかる (rotate 手順との整合)
    //
    // 投入手順 (1 回限り、deploy 前):
    //   aws secretsmanager create-secret \
    //     --name cfp/admin-api-github-app \
    //     --description "GitHub App credentials for admin-api Lambda" \
    //     --secret-string '{"app_id":"...","installation_id":"...","private_key":"...(改行は \\n でエスケープ)"}'
    //
    // 値の更新 (rotate 時):
    //   aws secretsmanager update-secret \
    //     --secret-id cfp/admin-api-github-app \
    //     --secret-string '{...}'
    //
    // 詳細手順は Issue #110 / PR で案内。
    const githubAppSecret = Secret.fromSecretNameV2(
      this,
      'GitHubAppSecret',
      'cfp/admin-api-github-app',
    );

    // Bref の統合 PHP レイヤー (公開アカウント ID 873528684822 は Bref のもの)。
    // 旧アカウント 534081306603 は廃止済み: 取得しようとすると Lambda 側で
    // GetLayerVersion が 403 (no resource-based policy) で失敗する。
    // 最新の ARN / バージョンは https://runtimes.bref.sh/?region=ap-northeast-1 で確認。
    const phpLayer = LayerVersion.fromLayerVersionArn(
      this,
      'PhpLayer',
      `arn:aws:lambda:${region}:873528684822:layer:php-85:14`,
    );

    // admin-api の Lambda asset (= apps/admin-api/) を共有変数として切り出す。
    // - this.function (Web FPM): handler=public/index.php
    // - this.autoCrawlFunction (Console): handler=artisan
    // 両者は同じ Lambda asset (= 同じ S3 object) を使うため、deploy 時の
    // ビルド時間と CFN リソース数を最小化できる。
    const adminApiCode = Code.fromAsset(
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
    );

    // Lambda 関数本体 (Web FPM)。
    // PROVIDED_AL2023 ランタイム + Bref レイヤーで PHP 8.5 を実行。
    // ハンドラ `public/index.php` は Bref の FPM モードを起動するシグナル。
    this.function = new LambdaFunction(this, 'Function', {
      runtime: Runtime.PROVIDED_AL2023,
      handler: 'public/index.php',
      code: adminApiCode,
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
        // Lambda の filesystem は read-only (`/tmp` 以外) なので file 系 driver は使わない。
        // SESSION は cookie driver (signed + encrypted cookie に session 全体を格納) を採用。
        // - array driver は in-memory のため Lambda 別インスタンス間で session/CSRF が共有
        //   できず、POST フォーム送信が 419 (CSRF mismatch) になる (Issue #81)。
        // - cookie driver なら APP_KEY で暗号化された cookie に乗るので Lambda 側ストレージ不要。
        // - session に大きなデータを入れない前提 (cookie 4 KB 上限)。本 admin UI は CSRF token
        //   と軽微な flash messages のみで問題なし。
        SESSION_DRIVER: 'cookie',
        // ── Session cookie セキュリティ強化 (Issue #130 / 公開前最終チェック) ──
        // - SESSION_SECURE_COOKIE=true: cookie に Secure 属性を付与し HTTPS 経路のみで送信。
        //   本 admin UI は CloudFront 経由の HTTPS のみで利用される (HTTP は CloudFront で
        //   HTTPS にリダイレクト) ため、Secure 属性は必ず付ける。
        //   未指定だと config/session.php のデフォルトに依存 (= null = リクエストの
        //   secure 状態に追従) で、誤って HTTP 経路に流出するリスクが残る。
        // - SESSION_SAME_SITE=strict: admin UI は同一オリジンからの遷移のみで使う前提
        //   (= 外部からの遷移は Basic 認証で弾かれる) なので strict で CSRF 防御を最大化。
        //   既存の VerifyOrigin middleware と二重防御。
        SESSION_SECURE_COOKIE: 'true',
        SESSION_SAME_SITE: 'strict',
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
        // ブラウザから見た本来の URL (CloudFront ドメイン)。
        // AppServiceProvider::boot() で URL::forceRootUrl() の引数として使われる。
        APP_URL: props.appUrl,
        // CloudFront Custom Origin Header の secret (Issue #77)。
        // Function URL の AuthType=NONE に切り替えたため、CloudFront 経由か
        // 直アクセスかを CloudFrontSecretMiddleware で判別する材料。
        CLOUDFRONT_ORIGIN_SECRET: props.cloudfrontOriginSecret,
        // ── GitHub App 認証用 env (Phase 5.3 / Issue #110) ──
        // 3 値は Secrets Manager の `cfp/admin-api-github-app` シークレット
        // (JSON 構造) から CFN deploy 時に展開される。`unsafeUnwrap()` を経由する
        // が CFN テンプレートには `{{resolve:secretsmanager:...}}` 関数として残るため
        // 実値はテンプレートに焼き込まれない。
        //
        // owner / repo / workflow_file / ref は plain な値 (= 漏洩リスクが無く
        // パブリック情報) なので env に直接書く。これらが variation する場合は
        // config/github_app.php の env 経由で local override 可能。
        GITHUB_APP_ID: githubAppSecret
          .secretValueFromJson('app_id')
          .unsafeUnwrap(),
        GITHUB_APP_INSTALLATION_ID: githubAppSecret
          .secretValueFromJson('installation_id')
          .unsafeUnwrap(),
        GITHUB_APP_PRIVATE_KEY: githubAppSecret
          .secretValueFromJson('private_key')
          .unsafeUnwrap(),
        GITHUB_APP_REPO_OWNER: 'mt-satak',
        GITHUB_APP_REPO_NAME: 'conference-cfp-deadline-checker',
        GITHUB_APP_WORKFLOW_FILE: 'deploy.yml',
        GITHUB_APP_WORKFLOW_REF: 'main',
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

    // ── AWS Marketplace 自動 subscribe 権限 (Issue #83) ──
    // 新 Bedrock の仕様で foundation model 初回 invoke 時に Marketplace 経由で自動
    // subscribe が走る。そのため Lambda 実行ロールに最小限の Marketplace 権限が必要。
    // subscribe は AWS アカウント単位で永続するため、1 回成功すれば実用上は不要だが、
    // 新モデル追加時に再度必要になるため付けっぱなしで運用する。
    // Resource: * は subscribe 対象 product ARN を事前に予測できない仕様上の制約。
    // Subscribe action は serverless foundation model の従量課金モデルでは追加リスクなし。
    this.function.addToRolePolicy(
      new PolicyStatement({
        effect: Effect.ALLOW,
        actions: [
          'aws-marketplace:ViewSubscriptions',
          'aws-marketplace:Subscribe',
        ],
        resources: ['*'],
      }),
    );

    // Function URL を AuthType=NONE で発行 (Issue #77)。
    //
    // 当初は AuthType=AWS_IAM + CloudFront OAC で運用していたが、CloudFront OAC
    // と Lambda Function URL を POST リクエストで組み合わせると SigV4 署名検証が
    // mismatch して 403 になる既知の互換性問題があり、GET は動くが POST が壊れて
    // 機能不全だった (Issue #75 で Lambda@Edge での Authorization 削除を試した
    // が解決せず)。
    //
    // 代替策: AuthType=NONE で OAC を撤廃し、CloudFront → Function URL の通信に
    // Custom Origin Header `X-CloudFront-Secret: <props.cloudfrontOriginSecret>`
    // を仕込む。Laravel の CloudFrontSecretMiddleware がこの header を検証し、
    // Function URL 直アクセスを 403 で弾く (= secret を知らない外部攻撃者は
    // 直接 Function URL を叩いても admin routes には到達できない)。
    this.functionUrl = this.function.addFunctionUrl({
      authType: FunctionUrlAuthType.NONE,
    });

    // ── 自動巡回 Lambda console (Issue #152 Phase 1) ──
    // 同じ admin-api asset (= adminApiCode) を共有しつつ handler を 'artisan' に
    // 切り替えて Bref の console モードで artisan コマンドを実行する。EventBridge
    // schedule (週 1) から起動される設計。
    //
    // BREF_RUNTIME='console' で Bref が console handler として bootstrap し、
    // Lambda invoke の payload `{cli: 'command-name'}` を受け取って artisan を実行する。
    //
    // タイムアウト: 15 分 (Lambda 上限)。30 件 x ~5 秒の LLM 抽出で十分余裕。
    this.autoCrawlFunction = new LambdaFunction(this, 'AutoCrawlFunction', {
      runtime: Runtime.PROVIDED_AL2023,
      handler: 'artisan',
      code: adminApiCode,
      layers: [phpLayer],
      architecture: Architecture.X86_64,
      timeout: Duration.minutes(15),
      memorySize: 1024,
      environment: {
        BREF_RUNTIME: 'console',
        BREF_LOOP_MAX: '1',
        // ── Laravel ランタイム環境変数 (= 既存の admin-api Lambda と同じ値で揃える) ──
        APP_KEY: appKeySecret.secretValue.unsafeUnwrap(),
        APP_ENV: 'production',
        APP_DEBUG: 'false',
        SESSION_DRIVER: 'cookie',
        SESSION_SECURE_COOKIE: 'true',
        SESSION_SAME_SITE: 'strict',
        CACHE_STORE: 'array',
        DYNAMODB_CONFERENCES_TABLE: props.conferences.tableName,
        DYNAMODB_CATEGORIES_TABLE: props.categories.tableName,
        // 自動巡回は LLM 抽出を Bedrock 経由で行うため Bedrock 環境変数が必須
        LLM_PROVIDER: 'bedrock',
        LLM_MODEL: 'jp.anthropic.claude-sonnet-4-6',
        LLM_REGION: region,
        // APP_URL は admin UI 用 (URL 生成) なので auto-crawl では未使用だが、
        // Laravel boot 時に config('app.url') 解決で警告が出ないよう同値で渡す
        APP_URL: props.appUrl,
        // CloudFront 関連 secret は admin-api 専用 (Function URL 経由の認可) で
        // auto-crawl では使わないため省略
      },
      description: 'Auto-crawl scheduled task for Conference CfP Deadline Checker (Issue #152 Phase 1)',
    });

    // DynamoDB 権限: Phase 1a は観測のみで副作用なしのため read 権限だけで十分。
    // Phase 1b で Draft 作成を入れたら grantReadWriteData に切り替える。
    props.conferences.grantReadData(this.autoCrawlFunction);
    props.categories.grantReadData(this.autoCrawlFunction);

    // Bedrock 権限 (= 既存の admin-api function と同じ Sonnet 4.6 限定)
    this.autoCrawlFunction.addToRolePolicy(
      new PolicyStatement({
        effect: Effect.ALLOW,
        actions: ['bedrock:InvokeModel', 'bedrock:Converse'],
        resources: [
          `arn:aws:bedrock:${region}::foundation-model/anthropic.claude-sonnet-4-6*`,
          `arn:aws:bedrock:${region}:*:inference-profile/*anthropic.claude-sonnet-4-6*`,
          `arn:aws:bedrock:*::foundation-model/anthropic.claude-sonnet-4-6*`,
        ],
      }),
    );

    // AWS Marketplace subscribe 権限 (= 初回 Bedrock 呼出で必要、Issue #83)
    this.autoCrawlFunction.addToRolePolicy(
      new PolicyStatement({
        effect: Effect.ALLOW,
        actions: [
          'aws-marketplace:ViewSubscriptions',
          'aws-marketplace:Subscribe',
        ],
        resources: ['*'],
      }),
    );

    // EventBridge schedule: 週 1 (JST 月曜 09:00 = UTC 月曜 00:00)
    // payload `{cli: 'conferences:auto-crawl'}` で artisan command を Bref console に渡す。
    new Rule(this, 'AutoCrawlSchedule', {
      schedule: Schedule.cron({
        weekDay: 'MON',
        hour: '0',
        minute: '0',
      }),
      description: 'Weekly auto-crawl of registered conferences (Issue #152 Phase 1a)',
      targets: [
        new LambdaTarget(this.autoCrawlFunction, {
          event: RuleTargetInput.fromObject({
            cli: 'conferences:auto-crawl',
          }),
        }),
      ],
    });
  }
}
