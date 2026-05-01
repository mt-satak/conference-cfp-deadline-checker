#!/usr/bin/env node
import 'source-map-support/register';
import * as cdk from 'aws-cdk-lib';
import { CfpDeadlineCheckerStack } from '../lib/cfp-deadline-checker-stack';
import { EdgeStack } from '../lib/edge-stack';

const app = new cdk.App();

// AWS アカウントは IAM ユーザーの環境変数 (CDK_DEFAULT_ACCOUNT) から取得する。
// 未設定の場合は環境非依存の合成（synth）になり、deploy 時に解決される。
const account = process.env.CDK_DEFAULT_ACCOUNT;

// ── ドメイン設定 (任意) ──
// ドメイン取得後、以下のように指定して有効化する:
//   pnpm cdk deploy --context domainName=cfp.example.com --context rootDomain=example.com
// rootDomain: Hosted Zone を作るドメイン (apex)
// domainName: 実際のサイト配信ホスト名 (rootDomain と同じ apex でも可)
// 両方指定された場合のみ Route 53 / ACM / カスタムドメイン関連リソースを生成する。
const domainName = app.node.tryGetContext('domainName') as string | undefined;
const rootDomain = app.node.tryGetContext('rootDomain') as string | undefined;

// ── 運用アラーム通知先 (任意) ──
// 例: pnpm cdk deploy --context alertEmail=ops@example.com
// 受信者側で確認リンクをクリックするまで通知は届かない。
const alertEmail = app.node.tryGetContext('alertEmail') as string | undefined;

// ── EdgeStack: us-east-1 にデプロイ ──
// CloudFront に紐付く Lambda@Edge / WAF / Secrets Manager / ACM 証明書 /
// Route 53 Hosted Zone は仕様上 us-east-1 必須。crossRegionReferences を
// 有効化することで、メインスタックが別リージョンから us-east-1 の出力を
// 参照できる (CDK が裏で SSM Parameter Store を介して値を共有する)。
const edgeStack = new EdgeStack(app, 'CfpDeadlineCheckerEdgeStack', {
  env: { account, region: 'us-east-1' },
  crossRegionReferences: true,
  domainName,
  rootDomain,
  description:
    'Edge resources (Lambda@Edge / WAF / Secrets / ACM / Route53) for CFP Deadline Checker',
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
  basicAuthFunctionVersionArn: edgeStack.basicAuthFunctionVersion.functionArn,
  domainName,
  hostedZoneId: edgeStack.hostedZoneId,
  zoneName: edgeStack.zoneName,
  certificateArn: edgeStack.certificateArn,
  alertEmail,
  description: 'Conference CfP Deadline Checker - main stack',
});

// EdgeStack の出力に依存するためデプロイ順を明示する
mainStack.addDependency(edgeStack);
