import * as cdk from 'aws-cdk-lib';
import { Construct } from 'constructs';
import { DataTables } from './constructs/data-tables';
import { StaticSite } from './constructs/static-site';

export class CfpDeadlineCheckerStack extends cdk.Stack {
  constructor(scope: Construct, id: string, props?: cdk.StackProps) {
    super(scope, id, props);

    const dataTables = new DataTables(this, 'DataTables');

    const staticSite = new StaticSite(this, 'StaticSite');

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
    // TODO: Lambda@Edge for Basic Auth (us-east-1 stack)
    // TODO: AWS WAF for /admin/*
    // TODO: Route 53 + ACM
    // TODO: Secrets Manager
    // TODO: EventBridge schedule (daily safety net build)
  }
}
