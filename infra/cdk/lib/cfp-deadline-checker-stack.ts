import * as cdk from 'aws-cdk-lib';
import { Construct } from 'constructs';
import { AdminApi } from './constructs/admin-api';
import { DataTables } from './constructs/data-tables';
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
 */
export interface CfpDeadlineCheckerStackProps extends cdk.StackProps {
  readonly webAclArn?: string;
  readonly basicAuthFunctionVersionArn?: string;
}

/**
 * メインスタック
 *
 * ap-northeast-1 にデプロイされる。以下のリソースを内包:
 * - DynamoDB 3 テーブル
 * - 管理 API Lambda (Bref + PHP-FPM)
 * - 静的サイト + 管理画面ルーティング (S3 + CloudFront)
 * - (今後追加) Route 53 + ACM / EventBridge 等
 */
export class CfpDeadlineCheckerStack extends cdk.Stack {
  constructor(
    scope: Construct,
    id: string,
    props?: CfpDeadlineCheckerStackProps,
  ) {
    super(scope, id, props);

    const dataTables = new DataTables(this, 'DataTables');

    // 管理 API Lambda。DynamoDB 3 テーブルへの最小権限を持つ。
    const adminApi = new AdminApi(this, 'AdminApi', {
      conferences: dataTables.conferences,
      categories: dataTables.categories,
      donations: dataTables.donations,
    });

    // 静的サイト + 管理画面ルーティング。
    // /admin/* は AdminApi の Function URL に Lambda@Edge 経由でルーティング。
    const staticSite = new StaticSite(this, 'StaticSite', {
      webAclArn: props?.webAclArn,
      adminFunctionUrl: adminApi.functionUrl,
      basicAuthFunctionVersionArn: props?.basicAuthFunctionVersionArn,
    });

    new cdk.CfnOutput(this, 'ConferencesTableName', {
      value: dataTables.conferences.tableName,
      description: 'DynamoDB conferences table name',
    });

    new cdk.CfnOutput(this, 'CategoriesTableName', {
      value: dataTables.categories.tableName,
      description: 'DynamoDB categories table name',
    });

    new cdk.CfnOutput(this, 'DonationsTableName', {
      value: dataTables.donations.tableName,
      description: 'DynamoDB donations table name',
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

    // TODO: Route 53 + ACM (step 4 で追加)
    // TODO: EventBridge schedule for daily build (step 5 で追加)
  }
}
