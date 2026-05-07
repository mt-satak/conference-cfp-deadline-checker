#!/usr/bin/env node
import 'source-map-support/register';
import * as cdk from 'aws-cdk-lib';
import { CfpCiStack } from '../lib/ci-stack';
import { CfpDeadlineCheckerStack } from '../lib/cfp-deadline-checker-stack';
import { EdgeStack } from '../lib/edge-stack';

const app = new cdk.App();

// AWS アカウントは IAM ユーザーの環境変数 (CDK_DEFAULT_ACCOUNT) から取得する。
// 未設定の場合は環境非依存の合成（synth）になり、deploy 時に解決される。
const account = process.env.CDK_DEFAULT_ACCOUNT;

// ── GitHub OIDC / CI 用設定 ──
// 既定値は本リポジトリのオーナー / リポジトリ名。fork 等で変える場合は
// `pnpm cdk deploy --context githubOrg=foo --context githubRepo=bar` で上書き。
const githubOrg = (app.node.tryGetContext('githubOrg') as string | undefined) ?? 'mt-satak';
const githubRepo =
  (app.node.tryGetContext('githubRepo') as string | undefined) ?? 'conference-cfp-deadline-checker';

// 既存 GitHub OIDC Provider がアカウントに既にある場合、その ARN を渡すと
// 新規作成せず import する。AWS アカウント全体で 1 つしか持てない制約への対応。
//   pnpm cdk deploy CfpDeadlineCheckerCiStack \
//     --context existingOidcProviderArn=arn:aws:iam::<ACCOUNT>:oidc-provider/token.actions.githubusercontent.com
const existingOidcProviderArn = app.node.tryGetContext('existingOidcProviderArn') as
  | string
  | undefined;

// ── ドメイン設定 (任意) ──
// ドメイン取得後、以下のように指定して有効化する:
//   pnpm cdk deploy --context domainName=cfp.example.com --context rootDomain=example.com
// rootDomain: Hosted Zone を作るドメイン (apex)
// domainName: 実際のサイト配信ホスト名 (rootDomain と同じ apex でも可)
// 両方指定された場合のみ Route 53 / ACM / カスタムドメイン関連リソースを生成する。
const domainName = app.node.tryGetContext('domainName') as string | undefined;
const rootDomain = app.node.tryGetContext('rootDomain') as string | undefined;

// ── 既存ドメインリソース参照モード (Phase 6.0 / Issue #119) ──
// Route 53 console でドメインを購入した場合、Route 53 が hosted zone を
// 自動作成し、ACM 証明書も別途 console で発行している前提。CDK で新規作成せず
// 既存リソースを参照する経路。
//   pnpm cdk deploy \
//     --context customDomainHostedZoneId=Z068674386S4F3UC1CEQ \
//     --context customDomainCertificateArn=arn:aws:acm:us-east-1:...:certificate/...
// 値は cdk.json の context にも書ける (= deploy.yml から context 引数なしでも動く)。
const customDomainHostedZoneId = app.node.tryGetContext(
  'customDomainHostedZoneId',
) as string | undefined;
const customDomainCertificateArn = app.node.tryGetContext(
  'customDomainCertificateArn',
) as string | undefined;

// ── Laravel APP_URL (Issue #67) ──
// CloudFront → Lambda Function URL 転送では Host が書き換わるため、Laravel に
// 「ブラウザから見た本来の URL」を環境変数で教える必要がある。
// 優先順位:
//   1. props.domainName (custom domain): `https://${domainName}`
//   2. context appUrl (`pnpm cdk deploy --context appUrl=https://...`)
//   3. デフォルト: 既知の CloudFront default domain (本番運用環境のもの)
//
// `staticSite.distribution.distributionDomainName` を CFN ref で参照すると
// AdminApi → Distribution → AdminApiFunctionUrl → AdminApi の循環参照に
// なるため、ここで synth 時に文字列として確定する。
const appUrlContext = app.node.tryGetContext('appUrl') as string | undefined;
const adminApiAppUrl = domainName
  ? `https://${domainName}`
  : (appUrlContext ?? 'https://d1fz1i6glcp2yn.cloudfront.net');

// ── 運用アラーム通知先 (任意) ──
// 例: pnpm cdk deploy --context alertEmail=ops@example.com
// 受信者側で確認リンクをクリックするまで通知は届かない。
const alertEmail = app.node.tryGetContext('alertEmail') as string | undefined;

// ── Lambda@Edge ARN 直接指定 (= aws-cdk#29009 回避用、通常 deploy では未指定) ──
// crossRegionReferences の SSM dynamic reference に CFN が pin した古い timestamp が
// 解決できなくなった場合のリカバリ用。一時的に直値を渡して deploy すると、
// ExportsReader Custom Resource が template から消え、stack の古い参照が解放される。
// 利用後は context なしの通常 deploy で crossRegionReferences が再構築される。
// 例:
//   pnpm cdk deploy CfpDeadlineCheckerStack \
//     --context basicAuthArnDirect=arn:aws:lambda:us-east-1:<acct>:function:<name>:<version>
const basicAuthArnDirect = app.node.tryGetContext('basicAuthArnDirect') as string | undefined;

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
  existingHostedZoneId: customDomainHostedZoneId,
  existingCertificateArn: customDomainCertificateArn,
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
  // basicAuthArnDirect が指定されている場合は edgeStack の token を参照しないことで
  // ExportsReader Custom Resource 生成自体を抑止する。token を参照すると CDK は
  // crossRegionReferences の参照を template に出してしまうため、`??` の右側を遅延評価
  // するために short-circuit を活用する。
  basicAuthFunctionVersionArn:
    basicAuthArnDirect ?? edgeStack.basicAuthFunctionVersion.functionArn,
  domainName,
  hostedZoneId: edgeStack.hostedZoneId,
  zoneName: edgeStack.zoneName,
  certificateArn: edgeStack.certificateArn,
  alertEmail,
  appUrl: adminApiAppUrl,
  description: 'Conference CfP Deadline Checker - main stack',
});

// EdgeStack の出力に依存するためデプロイ順を明示する
mainStack.addDependency(edgeStack);

// ── CiStack: 独立したライフサイクル ──
// GitHub Actions OIDC Provider と Deploy Role を持つ。アプリスタック (Edge / Main)
// から独立しており、メインスタックの destroy / rebuild に影響されない。
// IAM はグローバルだが配置リージョンは指定が必要なため ap-northeast-1 にする。
new CfpCiStack(app, 'CfpDeadlineCheckerCiStack', {
  env: {
    account,
    region: process.env.CDK_DEFAULT_REGION ?? 'ap-northeast-1',
  },
  githubOrg,
  githubRepo,
  existingOidcProviderArn,
  description:
    'CI/CD resources (GitHub Actions OIDC + Deploy Role) for CFP Deadline Checker',
});
