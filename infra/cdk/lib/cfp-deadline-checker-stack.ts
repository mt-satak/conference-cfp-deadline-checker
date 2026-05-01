import * as cdk from 'aws-cdk-lib';
import { Construct } from 'constructs';
import { DataTables } from './constructs/data-tables';
import { StaticSite } from './constructs/static-site';

/**
 * メインスタック (ap-northeast-1) のプロパティ
 *
 * webAclArn: us-east-1 の EdgeStack で作成された WAF WebACL の ARN。
 *            CloudFront Distribution に関連付ける。
 *            crossRegionReferences 経由で渡される。
 */
export interface CfpDeadlineCheckerStackProps extends cdk.StackProps {
  readonly webAclArn?: string;
}

/**
 * メインスタック
 *
 * ap-northeast-1 にデプロイされる。以下のリソースを内包:
 * - DynamoDB 3 テーブル
 * - 静的サイト用 S3 + CloudFront
 * - (今後追加) Lambda PHP / EventBridge / 各種 IAM
 */
export class CfpDeadlineCheckerStack extends cdk.Stack {
  constructor(
    scope: Construct,
    id: string,
    props?: CfpDeadlineCheckerStackProps,
  ) {
    super(scope, id, props);

    const dataTables = new DataTables(this, 'DataTables');

    // 静的サイトの配信構成。WAF WebACL ARN が渡されれば関連付ける。
    const staticSite = new StaticSite(this, 'StaticSite', {
      webAclArn: props?.webAclArn,
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

    // TODO: Lambda PHP (admin API) via Bref
    // TODO: /admin/* CloudFront behavior with Lambda@Edge auth (step 3 で追加)
    // TODO: Route 53 + ACM (step 4 で追加)
    // TODO: EventBridge schedule (step 5 で追加)
  }
}
