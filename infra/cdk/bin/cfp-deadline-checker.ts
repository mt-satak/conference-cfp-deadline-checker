#!/usr/bin/env node
import 'source-map-support/register';
import * as cdk from 'aws-cdk-lib';
import { CfpDeadlineCheckerStack } from '../lib/cfp-deadline-checker-stack';
import { EdgeStack } from '../lib/edge-stack';

const app = new cdk.App();

// AWS アカウントは IAM ユーザーの環境変数 (CDK_DEFAULT_ACCOUNT) から取得する。
// 未設定の場合は環境非依存の合成（synth）になり、deploy 時に解決される。
const account = process.env.CDK_DEFAULT_ACCOUNT;

// ── EdgeStack: us-east-1 にデプロイ ──
// CloudFront に紐付く Lambda@Edge / WAF / Secrets Manager は仕様上 us-east-1 必須。
// crossRegionReferences を有効化することで、メインスタックが別リージョンから
// このスタックの出力 (WebACL ARN 等) を参照できるようになる
// (CDK が裏で SSM Parameter Store を介して値を共有する)。
const edgeStack = new EdgeStack(app, 'CfpDeadlineCheckerEdgeStack', {
  env: { account, region: 'us-east-1' },
  crossRegionReferences: true,
  description: 'Edge resources (Lambda@Edge / WAF / Secrets) for CFP Deadline Checker',
});

// ── メインスタック: ap-northeast-1 にデプロイ ──
// 利用者の主要ロケーションが日本のため、データ層・API 層は東京リージョン。
const mainStack = new CfpDeadlineCheckerStack(app, 'CfpDeadlineCheckerStack', {
  env: {
    account,
    region: process.env.CDK_DEFAULT_REGION ?? 'ap-northeast-1',
  },
  crossRegionReferences: true,
  webAclArn: edgeStack.webAclArn,
  description: 'Conference CfP Deadline Checker - main stack',
});

// EdgeStack の出力に依存するためデプロイ順を明示する
mainStack.addDependency(edgeStack);
