import * as cdk from 'aws-cdk-lib';
import { CloudFrontTarget } from 'aws-cdk-lib/aws-route53-targets';
import { ARecord, HostedZone, RecordTarget } from 'aws-cdk-lib/aws-route53';
import { Secret } from 'aws-cdk-lib/aws-secretsmanager';
import { Construct } from 'constructs';
import { AdminApi } from './constructs/admin-api';
import { DataTables } from './constructs/data-tables';
import { Operations } from './constructs/operations';
import { StaticSite } from './constructs/static-site';

/**
 * メインスタック (ap-northeast-1) のプロパティ
 *
 * webAclArn:
 *   us-east-1 の EdgeStack で作成された WAF WebACL の ARN。
 *   CloudFront Distribution に関連付ける。crossRegionReferences 経由で渡される。
 *
 * basicAuthFunctionVersionArn:
 *   us-east-1 の Lambda@Edge (Basic 認証) Version ARN。
 *   /admin/* ビヘイビアの Viewer Request トリガーとして関連付ける。
 *
 * domainName / hostedZoneId / zoneName / certificateArn:
 *   独自ドメインを使う場合のみ指定。すべて渡されたとき、CloudFront に
 *   カスタムドメインを設定し、Route 53 にエイリアスレコードを作成する。
 *
 * alertEmail:
 *   運用アラームの通知先メールアドレス。指定すると SNS トピックに購読が
 *   自動登録される (受信者側で確認リンクをクリックする必要あり)。
 */
export interface CfpDeadlineCheckerStackProps extends cdk.StackProps {
  readonly webAclArn?: string;
  /**
   * Lambda@Edge の関数バージョン ARN。
   *
   * 通常は EdgeStack の crossRegionReferences (= Custom::CrossRegionExportReader)
   * で SSM 経由解決された token が渡される。`bin/cfp-deadline-checker.ts` 側で
   * `--context basicAuthArnDirect=...` が指定されている場合のみ直値の文字列が
   * 渡される (= aws-cdk#29009 回避用、ExportsReader リソース自体を生成しない)。
   * MainStack コードはこの差分を意識せずどちらも同様に扱う。
   */
  readonly basicAuthFunctionVersionArn?: string;
  readonly domainName?: string;
  readonly hostedZoneId?: string;
  readonly zoneName?: string;
  readonly certificateArn?: string;
  readonly alertEmail?: string;
  /**
   * Laravel APP_URL (Issue #67)。
   *
   * CloudFront → Lambda Function URL 転送では Host が Function URL ドメインに
   * 書き換わるため、Laravel に「ブラウザから見た本来の URL」を環境変数で教える必要が
   * ある。この値は AdminApi の Lambda 環境変数 `APP_URL` に直接渡され、
   * AppServiceProvider::boot() で `URL::forceRootUrl()` の引数になる。
   *
   * `staticSite.distribution.distributionDomainName` を参照すると AdminApi →
   * Distribution → AdminApiFunctionUrl → AdminApi の CFN 循環参照になる
   * (PR #68 で実害発覚) ため、bin/ 側で文字列として確定して渡す。
   */
  readonly appUrl: string;
}

/**
 * メインスタック
 *
 * ap-northeast-1 にデプロイされる。以下のリソースを内包:
 * - DynamoDB 2 テーブル (conferences / categories)
 * - 管理 API Lambda (Bref + PHP-FPM)
 * - 静的サイト + 管理画面ルーティング (S3 + CloudFront)
 * - 独自ドメイン使用時の Route 53 エイリアスレコード
 * - (今後追加) EventBridge 等
 */
export class CfpDeadlineCheckerStack extends cdk.Stack {
  constructor(
    scope: Construct,
    id: string,
    props: CfpDeadlineCheckerStackProps,
  ) {
    super(scope, id, props);

    const dataTables = new DataTables(this, 'DataTables');

    // ── CloudFront Custom Origin Header 用 secret (Issue #77) ──
    // Lambda Function URL の AuthType=NONE に切り替えたため、CloudFront 経由
    // リクエストかどうかを判別する材料として `X-CloudFront-Secret: <random>` を
    // CloudFront Origin に仕込み、Laravel の CloudFrontSecretMiddleware で検証する。
    // AdminApi (Lambda 環境変数) と StaticSite (CloudFront Origin Custom Header)
    // の両方で同じ値を参照する必要があるため、MainStack で 1 度生成して両方に渡す。
    const cloudfrontOriginSecret = new Secret(
      this,
      'CloudFrontOriginSecret',
      {
        secretName: 'cfp/admin-cloudfront-origin-secret',
        description:
          'Custom origin header value for CloudFront → AdminApi Function URL (Issue #77)',
        generateSecretString: {
          passwordLength: 48,
          excludePunctuation: true,
        },
      },
    );
    const cloudfrontOriginSecretValue =
      cloudfrontOriginSecret.secretValue.unsafeUnwrap();

    // 管理 API Lambda。DynamoDB 2 テーブルへの最小権限を持つ。
    // appUrl は bin/ 側で確定済みの文字列を受け取る (Issue #67)。
    const adminApi = new AdminApi(this, 'AdminApi', {
      conferences: dataTables.conferences,
      categories: dataTables.categories,
      appUrl: props.appUrl,
      cloudfrontOriginSecret: cloudfrontOriginSecretValue,
    });

    // 静的サイト + 管理画面ルーティング。
    // /admin/* は AdminApi の Function URL に Lambda@Edge 経由でルーティング。
    const staticSite = new StaticSite(this, 'StaticSite', {
      webAclArn: props.webAclArn,
      adminFunctionUrl: adminApi.functionUrl,
      adminFunction: adminApi.function,
      basicAuthFunctionVersionArn: props.basicAuthFunctionVersionArn,
      domainName: props.domainName,
      certificateArn: props.certificateArn,
      cloudfrontOriginSecret: cloudfrontOriginSecretValue,
    });

    // 運用観測 (CloudWatch アラーム + SNS 通知トピック)。
    // alertEmail が指定されていればメール購読まで自動セットアップする。
    const operations = new Operations(this, 'Operations', {
      adminApiFunction: adminApi.function,
      alertEmail: props.alertEmail,
    });

    // ── 独自ドメインのエイリアスレコード (任意) ──
    // domainName / hostedZoneId / zoneName が渡された場合のみ、Route 53 に
    // CloudFront を指す A レコード (ALIAS) を作成する。
    // hostedZoneId と zoneName は EdgeStack で生成された値を crossRegionReferences
    // 経由で受け取り、HostedZone.fromHostedZoneAttributes で参照する。
    if (props.domainName && props.hostedZoneId && props.zoneName) {
      const hostedZone = HostedZone.fromHostedZoneAttributes(
        this,
        'ImportedHostedZone',
        {
          hostedZoneId: props.hostedZoneId,
          zoneName: props.zoneName,
        },
      );

      new ARecord(this, 'AliasRecord', {
        zone: hostedZone,
        recordName: props.domainName,
        target: RecordTarget.fromAlias(
          new CloudFrontTarget(staticSite.distribution),
        ),
        comment: `Alias for CloudFront distribution`,
      });

      new cdk.CfnOutput(this, 'SiteUrl', {
        value: `https://${props.domainName}`,
        description: 'Public site URL with custom domain',
      });
    }

    new cdk.CfnOutput(this, 'ConferencesTableName', {
      value: dataTables.conferences.tableName,
      description: 'DynamoDB conferences table name',
    });

    new cdk.CfnOutput(this, 'CategoriesTableName', {
      value: dataTables.categories.tableName,
      description: 'DynamoDB categories table name',
    });

    new cdk.CfnOutput(this, 'AdminApiFunctionName', {
      value: adminApi.function.functionName,
      description: 'Admin API Lambda function name',
    });

    new cdk.CfnOutput(this, 'AdminApiFunctionUrl', {
      value: adminApi.functionUrl.url,
      description: 'Admin API Lambda Function URL (IAM-protected, CloudFront only)',
    });

    new cdk.CfnOutput(this, 'SiteBucketName', {
      value: staticSite.bucket.bucketName,
      description: 'S3 bucket for static site',
    });

    new cdk.CfnOutput(this, 'DistributionId', {
      value: staticSite.distribution.distributionId,
      description: 'CloudFront distribution ID',
    });

    new cdk.CfnOutput(this, 'DistributionDomainName', {
      value: staticSite.distribution.distributionDomainName,
      description: 'CloudFront distribution domain name (xxxxx.cloudfront.net)',
    });

    new cdk.CfnOutput(this, 'AlarmTopicArn', {
      value: operations.alarmTopic.topicArn,
      description: 'SNS topic ARN for operational alarms',
    });

    // TODO: EventBridge による日次保険ビルドは Amplify の Webhook URL が
    //       確定してから別コミットで追加する (Amplify アプリは現時点では未作成)。
  }
}
