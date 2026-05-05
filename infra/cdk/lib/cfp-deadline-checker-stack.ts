import * as cdk from 'aws-cdk-lib';
import { CloudFrontTarget } from 'aws-cdk-lib/aws-route53-targets';
import { ARecord, HostedZone, RecordTarget } from 'aws-cdk-lib/aws-route53';
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
  readonly basicAuthFunctionVersionArn?: string;
  readonly domainName?: string;
  readonly hostedZoneId?: string;
  readonly zoneName?: string;
  readonly certificateArn?: string;
  readonly alertEmail?: string;
  /**
   * Lambda@Edge ARN を直接指定する場合 (= crossRegionReferences をバイパス)。
   * 通常は EdgeStack の Custom::CrossRegionExportReader で SSM 経由解決するが、
   * Lambda@Edge のバージョン更新時に CFN が古い dynamic reference を pin してしまい
   * stack update が詰まる既知のバグ (= aws-cdk#29009) があるため、その回避用に
   * deploy 時に context で一時的に直値を渡せるようにする。
   */
  readonly basicAuthFunctionVersionArnDirect?: string;
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
    props?: CfpDeadlineCheckerStackProps,
  ) {
    super(scope, id, props);

    const dataTables = new DataTables(this, 'DataTables');

    // 管理 API Lambda。DynamoDB 2 テーブルへの最小権限を持つ。
    const adminApi = new AdminApi(this, 'AdminApi', {
      conferences: dataTables.conferences,
      categories: dataTables.categories,
    });

    // 静的サイト + 管理画面ルーティング。
    // /admin/* は AdminApi の Function URL に Lambda@Edge 経由でルーティング。
    // Lambda@Edge ARN は通常 EdgeStack から crossRegionReferences で取得 (= props.basicAuthFunctionVersionArn)。
    // ただし aws-cdk#29009 の既知バグで Lambda 更新時に CFN が古い SSM dynamic ref を pin
    // してしまい stack update が詰まることがあるため、その回避手段として
    // basicAuthFunctionVersionArnDirect が渡された場合は SSM 経由でなく直値を使う。
    // 直値モードでは ExportsReader Custom Resource が template から消えるため、CFN の
    // 古い参照も同時に解放される。回復後は context を外して通常モードに戻す。
    const basicAuthArn =
      props?.basicAuthFunctionVersionArnDirect ?? props?.basicAuthFunctionVersionArn;

    const staticSite = new StaticSite(this, 'StaticSite', {
      webAclArn: props?.webAclArn,
      adminFunctionUrl: adminApi.functionUrl,
      basicAuthFunctionVersionArn: basicAuthArn,
      domainName: props?.domainName,
      certificateArn: props?.certificateArn,
    });

    // 運用観測 (CloudWatch アラーム + SNS 通知トピック)。
    // alertEmail が指定されていればメール購読まで自動セットアップする。
    const operations = new Operations(this, 'Operations', {
      adminApiFunction: adminApi.function,
      alertEmail: props?.alertEmail,
    });

    // ── 独自ドメインのエイリアスレコード (任意) ──
    // domainName / hostedZoneId / zoneName が渡された場合のみ、Route 53 に
    // CloudFront を指す A レコード (ALIAS) を作成する。
    // hostedZoneId と zoneName は EdgeStack で生成された値を crossRegionReferences
    // 経由で受け取り、HostedZone.fromHostedZoneAttributes で参照する。
    if (props?.domainName && props?.hostedZoneId && props?.zoneName) {
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
