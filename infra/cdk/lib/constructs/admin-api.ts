import * as path from 'node:path';
import { Duration, Stack } from 'aws-cdk-lib';
import { type Table } from 'aws-cdk-lib/aws-dynamodb';
import {
  Architecture,
  Code,
  Function as LambdaFunction,
  FunctionUrlAuthType,
  type IFunctionUrl,
  LayerVersion,
  Runtime,
} from 'aws-cdk-lib/aws-lambda';
import { Construct } from 'constructs';

/**
 * AdminApi Construct のオプション
 *
 * conferences / categories / donations: 管理 API がアクセスする DynamoDB テーブル。
 * これらに対する読み書き権限が Lambda 実行ロールへ自動付与される。
 */
export interface AdminApiProps {
  readonly conferences: Table;
  readonly categories: Table;
  readonly donations: Table;
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
      ),
      layers: [phpLayer],
      architecture: Architecture.X86_64,
      // CloudFront のオリジン応答タイムアウト (デフォルト 30 秒) より少し短くする
      timeout: Duration.seconds(28),
      memorySize: 1024,
      environment: {
        // Bref の標準環境変数
        BREF_LOOP_MAX: '250',
        // 管理 API が参照する DynamoDB テーブル名 (Lambda 実行時に解決)
        DYNAMODB_CONFERENCES_TABLE: props.conferences.tableName,
        DYNAMODB_CATEGORIES_TABLE: props.categories.tableName,
        DYNAMODB_DONATIONS_TABLE: props.donations.tableName,
      },
      description: 'Admin API for Conference CfP Deadline Checker',
    });

    // DynamoDB の読み書き権限を最小スコープで付与。
    // grantReadWriteData は GetItem/PutItem/UpdateItem/DeleteItem/Scan/Query 等を許可。
    props.conferences.grantReadWriteData(this.function);
    props.categories.grantReadWriteData(this.function);
    props.donations.grantReadWriteData(this.function);

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
