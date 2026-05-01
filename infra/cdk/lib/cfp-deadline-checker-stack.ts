import * as cdk from 'aws-cdk-lib';
import { Construct } from 'constructs';
import { DataTables } from './constructs/data-tables';

export class CfpDeadlineCheckerStack extends cdk.Stack {
  constructor(scope: Construct, id: string, props?: cdk.StackProps) {
    super(scope, id, props);

    const dataTables = new DataTables(this, 'DataTables');

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

    // TODO: S3 bucket for static site
    // TODO: Lambda PHP (admin API) via Bref
    // TODO: Lambda@Edge for Basic Auth (us-east-1 stack)
    // TODO: CloudFront distribution
    // TODO: AWS WAF for /admin/*
    // TODO: Route 53 + ACM
    // TODO: Secrets Manager
    // TODO: EventBridge schedule (daily safety net build)
  }
}
