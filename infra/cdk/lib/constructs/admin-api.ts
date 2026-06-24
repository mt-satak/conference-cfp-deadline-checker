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
import { StringParameter } from 'aws-cdk-lib/aws-ssm';
import { Construct } from 'constructs';
import { DeletePastTask } from './delete-past-task';

/**
 * AdminApi Construct のオプション
 *
 * conferences / categories / cfpSources: 管理 API がアクセスする DynamoDB テーブル。
 * これらに対する読み書き権限が Lambda 実行ロールへ自動付与される。
 */
export interface AdminApiProps {
  readonly conferences: Table;
  readonly categories: Table;
  readonly cfpSources: Table;
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

  /**
   * 週次自動 CfP 発見 (Issue #200 PR-3) 用 Lambda 関数。
   *
   * 既存 admin-api Lambda と同じ asset を共有しつつ Bref console mode で
   * `conferences:discover-new --apply` を実行する。EventBridge schedule
   * (月曜 JST 08:00) から起動。AutoCrawl (月曜 JST 09:00) の 1 時間前に走らせて
   * 新規 Draft を投入してから既存 Published の差分検知に進む順序にする。
   */
  public readonly discoverConferencesFunction: LambdaFunction;

  /**
   * 開催日を過ぎた Conference を全ステータス対象でハード削除する Lambda console
   * + EventBridge schedule (毎週 月曜 JST 08:00、Issue #221 PR-1)。
   *
   * 詳細は DeletePastTask construct のクラスドキュメント参照。
   */
  public readonly deletePastTask: DeletePastTask;

  constructor(scope: Construct, id: string, props: AdminApiProps) {
    super(scope, id);

    const region = Stack.of(this).region;

    // ── Laravel APP_KEY (SSM Parameter Store, Issue #206 #1 で Secrets Manager から移行) ──
    // Laravel は暗号化用に 32 byte (256 bit) key を APP_KEY 環境変数で要求する
    // (Encrypter::supported() 内で `mb_strlen($key, '8bit') === 32` を validation)。
    //
    // Standard String パラメータ (無料) を採用した理由 (Issue #206 #1):
    //  - SecureString は CloudFormation の dynamic reference が Lambda 環境変数に
    //    使えないため、起動時 SDK 取得 (= cold start 増 + コード変更) が必要になる
    //  - 値はどのみち Lambda 環境変数としてコンソールに表示されるため、平文 String
    //    でも実質的なセキュリティ低下はない (どちらも IAM で読み取りを制御)
    //
    // パラメータは CDK 管理外 (= CLI で事前作成、CDK は参照のみ)。
    // valueForStringParameter は deploy 時に値を解決するため、値はテンプレートに
    // 焼き込まれない (= 旧 secretsmanager dynamic reference と同じ性質)。
    //
    // 投入手順 (1 回限り、deploy 前。値は 32 文字 ASCII 英数字のみ):
    //   `excludePunctuation` 相当の英数字 32 文字を生成して渡す。Laravel docs 推奨の
    //   `base64:<encoded>` 形式は使わない (base64_decode で 24 byte になり起動失敗する)。
    //     aws ssm put-parameter --name /cfp/admin-api/app-key --type String \
    //       --value "$(openssl rand -base64 48 | tr -dc 'a-zA-Z0-9' | head -c 32)"
    //
    // rotate 時は put-parameter --overwrite で値を更新 → deploy.yml を workflow_dispatch
    // で再 deploy (= 次回 deploy 時に新値が Lambda env に反映される)。
    // NOTE: APP_KEY を変えると既存セッション cookie と暗号化済みデータが無効になる。
    const appKey = StringParameter.valueForStringParameter(
      this,
      '/cfp/admin-api/app-key',
    );

    // ── GitHub App 認証 3 値 (SSM Parameter Store, Issue #110 / #206 #1 で移行) ──
    // admin UI のビルドボタンから GitHub Actions deploy.yml を workflow_dispatch
    // で起動するため、GitHub App の認証 3 値 (app_id, installation_id, private_key)
    // を SSM Parameter Store に置く。長期 PAT を Lambda 上に置かないために GitHub App
    // 経由で 1 時間有効な installation token を都度発行する設計
    // (memory project_no_api_keys_policy.md の方針に整合)。
    //
    // パラメータは CDK 管理外 (= CLI で事前作成、CDK は参照のみ) とする。理由:
    //  - private_key は GitHub App 設定画面で発行された .pem ファイルの中身で、
    //    CDK のコードに焼くと CloudFormation テンプレートに値が残ってしまう
    //  - cdk destroy で誤って消えると GitHub App の private_key を再発行する手間がかかる
    //
    // 旧 Secrets Manager (cfp/admin-api-github-app) は JSON 1 本だったが、SSM の
    // dynamic reference は JSON キー抽出をサポートしないため 3 パラメータに分割した。
    //
    // 投入手順 (1 回限り、deploy 前):
    //   aws ssm put-parameter --name /cfp/admin-api/github-app/app-id --type String --value "..."
    //   aws ssm put-parameter --name /cfp/admin-api/github-app/installation-id --type String --value "..."
    //   aws ssm put-parameter --name /cfp/admin-api/github-app/private-key --type String \
    //     --value "$(cat key.pem)"   # 複数行 PEM をそのまま渡せる (4KB 以内)
    //
    // ── private_key rotate 手順 (Issue #177 #6 / #206 #1 で SSM 版に更新) ──
    //
    // 想定タイミング:
    //  - private_key 流出が疑われる時 (= GitHub App 設定画面 / git history / logs に
    //    誤コミット等)
    //  - 定期 rotate (個人開発では年 1 回程度を目安)
    //
    // 手順:
    //  1. https://github.com/settings/apps/<app名> の Private keys セクションで
    //     "Generate a private key" を押下し、新しい .pem ファイルをダウンロード。
    //     旧鍵はまだ削除しない (= rollback 用に残す)。
    //  2. SSM パラメータを新 .pem で更新 (app_id / installation_id は据え置き):
    //       aws ssm put-parameter --name /cfp/admin-api/github-app/private-key \
    //         --type String --overwrite --value "$(cat new-key.pem)"
    //  3. deploy.yml を workflow_dispatch で叩いて再 deploy (= valueForStringParameter は
    //     deploy 時解決のため、再 deploy しないと Lambda env に反映されない)。
    //  4. admin UI のビルドボタン押下で動作確認 → BuildController が 200 を返す
    //     ことを確認。失敗時は CloudWatch Logs で Lambda 側のエラーを確認し、必要なら
    //     旧鍵に戻して原因調査 (= 2. を旧 .pem で再実行 + 再 deploy で rollback)。
    //  5. 動作確認 OK なら GitHub App 設定画面で旧 .pem を Delete。
    const githubAppId = StringParameter.valueForStringParameter(
      this,
      '/cfp/admin-api/github-app/app-id',
    );
    const githubAppInstallationId = StringParameter.valueForStringParameter(
      this,
      '/cfp/admin-api/github-app/installation-id',
    );
    const githubAppPrivateKey = StringParameter.valueForStringParameter(
      this,
      '/cfp/admin-api/github-app/private-key',
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
        APP_KEY: appKey,
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
        // Issue #200 PR-1: 週次自動 CfP 発見の巡回対象 URL を保持するテーブル
        DYNAMODB_CFP_SOURCES_TABLE: props.cfpSources.tableName,
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
        // ── GitHub App 認証用 env (Phase 5.3 / Issue #110, #206 #1 で SSM 移行) ──
        // 3 値は SSM Parameter Store (/cfp/admin-api/github-app/*) から CFN deploy 時に
        // 解決される (= CFN Parameter type AWS::SSM::Parameter::Value<String>)。
        // 実値はテンプレートに焼き込まれない。
        //
        // owner / repo / workflow_file / ref は plain な値 (= 漏洩リスクが無く
        // パブリック情報) なので env に直接書く。これらが variation する場合は
        // config/github_app.php の env 経由で local override 可能。
        GITHUB_APP_ID: githubAppId,
        GITHUB_APP_INSTALLATION_ID: githubAppInstallationId,
        GITHUB_APP_PRIVATE_KEY: githubAppPrivateKey,
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
    // Issue #200 PR-1: 管理画面から source CRUD を行うため read+write 必要
    props.cfpSources.grantReadWriteData(this.function);

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
        APP_KEY: appKey,
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

    // DynamoDB 権限: Phase 1b で差分検知時に Draft Conference を新規作成するため
    // conferences は read+write 必要。categories は LLM 解決保留 (Phase 2 で対応)
    // のため read のみで十分。
    props.conferences.grantReadWriteData(this.autoCrawlFunction);
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

    // ── 自動 CfP 発見 Lambda console + EventBridge schedule (Issue #200 PR-3) ──
    // AutoCrawl と同パターン: admin-api asset を共有しつつ Bref console モードで
    // `conferences:discover-new --apply` を実行する。月曜 JST 08:00 (= UTC 日曜 23:00)
    // 起動で、AutoCrawl (月曜 09:00) の 1 時間前に新規 Draft を投入してから既存
    // Published の差分検知が走る順序にする。
    this.discoverConferencesFunction = new LambdaFunction(this, 'DiscoverConferencesFunction', {
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
        APP_KEY: appKey,
        APP_ENV: 'production',
        APP_DEBUG: 'false',
        SESSION_DRIVER: 'cookie',
        SESSION_SECURE_COOKIE: 'true',
        SESSION_SAME_SITE: 'strict',
        CACHE_STORE: 'array',
        DYNAMODB_CONFERENCES_TABLE: props.conferences.tableName,
        DYNAMODB_CATEGORIES_TABLE: props.categories.tableName,
        DYNAMODB_CFP_SOURCES_TABLE: props.cfpSources.tableName,
        LLM_PROVIDER: 'bedrock',
        // 詳細抽出 (ConferenceDraftExtractor) 用。人間レビューに乗る Draft の品質に
        // 直結するため Sonnet を維持。
        LLM_MODEL: 'jp.anthropic.claude-sonnet-4-6',
        // Issue #206 #2: URL 列挙 (ListConferenceUrlsExtractor) は単純抽出タスクの
        // ため安価な Haiku 4.5 に分離 (単価 Sonnet の 1/3 以下)。jp.* プロファイルで
        // 国内推論のデータレジデンシは維持。
        LLM_MODEL_DISCOVERY: 'jp.anthropic.claude-haiku-4-5-20251001-v1:0',
        LLM_REGION: region,
        APP_URL: props.appUrl,
      },
      description: 'Weekly auto CfP discovery task for Conference CfP Deadline Checker (Issue #200 PR-3)',
    });

    // DynamoDB 権限:
    // - cfp_sources: read (= 巡回対象の取得)
    // - conferences: read+write (= 既存 dedup + 新規 Draft 投入)
    // - categories: read のみ (= 念のため categorySlugs 解決の余地、現状は categories=[] で投入)
    props.cfpSources.grantReadData(this.discoverConferencesFunction);
    props.conferences.grantReadWriteData(this.discoverConferencesFunction);
    props.categories.grantReadData(this.discoverConferencesFunction);

    // Bedrock 権限: Sonnet 4.6 (詳細抽出) + Haiku 4.5 (URL 列挙、Issue #206 #2) の
    // 2 モデルに限定。他モデルは IAM レベルで呼び出せない (= 最小権限の原則)。
    this.discoverConferencesFunction.addToRolePolicy(
      new PolicyStatement({
        effect: Effect.ALLOW,
        actions: ['bedrock:InvokeModel', 'bedrock:Converse'],
        resources: [
          `arn:aws:bedrock:${region}::foundation-model/anthropic.claude-sonnet-4-6*`,
          `arn:aws:bedrock:${region}:*:inference-profile/*anthropic.claude-sonnet-4-6*`,
          `arn:aws:bedrock:*::foundation-model/anthropic.claude-sonnet-4-6*`,
          `arn:aws:bedrock:${region}::foundation-model/anthropic.claude-haiku-4-5*`,
          `arn:aws:bedrock:${region}:*:inference-profile/*anthropic.claude-haiku-4-5*`,
          `arn:aws:bedrock:*::foundation-model/anthropic.claude-haiku-4-5*`,
        ],
      }),
    );

    // AWS Marketplace subscribe 権限 (= 初回 Bedrock 呼出で必要、Issue #83)
    this.discoverConferencesFunction.addToRolePolicy(
      new PolicyStatement({
        effect: Effect.ALLOW,
        actions: [
          'aws-marketplace:ViewSubscriptions',
          'aws-marketplace:Subscribe',
        ],
        resources: ['*'],
      }),
    );

    // EventBridge schedule: 週 1 (JST 月曜 08:00 = UTC 日曜 23:00)
    // weekDay='SUN' + hour='23' で UTC 日曜 23:00 = JST 月曜 08:00 を表す。
    // AutoCrawl (月曜 09:00 JST) の 1 時間前に走らせる順序にする。
    // payload `{cli: 'conferences:discover-new --apply'}` で実投入モードを指定。
    new Rule(this, 'DiscoverConferencesSchedule', {
      schedule: Schedule.cron({
        weekDay: 'SUN',
        hour: '23',
        minute: '0',
      }),
      description: 'Weekly auto CfP discovery (Issue #200 PR-3, JST Mon 08:00 = UTC Sun 23:00)',
      targets: [
        new LambdaTarget(this.discoverConferencesFunction, {
          event: RuleTargetInput.fromObject({
            cli: 'conferences:discover-new --apply',
          }),
        }),
      ],
    });

    // ── Delete-past Lambda console + EventBridge schedule (Issue #221 PR-1) ──
    // 開催日を過ぎた Conference を全ステータス対象でハード削除する週次タスク
    // (月曜 JST 08:00)。詳細は DeletePastTask construct のクラスドキュメント参照。
    this.deletePastTask = new DeletePastTask(this, 'DeletePastTask', {
      adminApiCode,
      phpLayer,
      appKey,
      appUrl: props.appUrl,
      conferences: props.conferences,
      architecture: Architecture.X86_64,
    });
  }
}
