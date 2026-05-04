import { Duration } from 'aws-cdk-lib';
import {
  Effect,
  OpenIdConnectProvider,
  PolicyStatement,
  Role,
  WebIdentityPrincipal,
} from 'aws-cdk-lib/aws-iam';
import { Construct } from 'constructs';

/**
 * GitHubOidc Construct のオプション。
 *
 * githubOrg / githubRepo:
 *   trust ポリシーで `repo:<org>/<repo>:...` の subject claim を許可する対象。
 *
 * subjectClaims (任意):
 *   許可する GitHub Actions workflow の subject パターン。デフォルトは
 *   `refs/heads/main` 上の workflow のみ (= main マージ時のデプロイ専用)。
 *   PR 上の任意ブランチからの assume を許可したい場合は `repo:<org>/<repo>:*`
 *   を渡すが、本リポジトリでは PR ジョブが AWS にアクセスする要件はないため
 *   既定値の通り main 限定推奨。
 *
 * cdkQualifier (任意):
 *   CDK bootstrap stack の qualifier。`cdk bootstrap --qualifier xxx` で
 *   独自値を使っている場合に指定する。デフォルト値は CDK 標準の `hnb659fds`。
 *
 * roleName (任意):
 *   作成する IAM Role の物理名。デフォルトは "GitHubActionsAdminApiDeployRole"。
 *   GitHub Actions 側 (workflow の `role-to-assume`) で参照するため、命名後の
 *   変更は注意。
 */
export interface GitHubOidcProps {
  readonly githubOrg: string;
  readonly githubRepo: string;
  readonly subjectClaims?: readonly string[];
  readonly cdkQualifier?: string;
  readonly roleName?: string;
}

/**
 * GitHub Actions から AWS にアクセスするための OIDC 連携リソースを構築する。
 *
 * 構成:
 *   1. OpenID Connect Provider (`token.actions.githubusercontent.com`)
 *      - AWS アカウントごとに 1 つだけ作成可能 (重複作成は CloudFormation エラー)
 *   2. IAM Role
 *      - trust ポリシーで GitHub Actions のみ assume 可
 *      - subject claim で対象リポジトリ + ブランチを限定
 *   3. AssumeRole 権限
 *      - 直接デプロイ権限を持たず、CDK bootstrap で作成された
 *        `cdk-<qualifier>-deploy-role-*` 等を assume するに留める
 *      - これにより「本 Role は CDK 経由のデプロイ以外できない」状態を維持
 *
 * GitHub Actions 側の使い方 (workflow):
 *   ```yaml
 *   - uses: aws-actions/configure-aws-credentials@v4
 *     with:
 *       role-to-assume: ${{ vars.AWS_DEPLOY_ROLE_ARN }}  # 本 Role の ARN
 *       aws-region: ap-northeast-1
 *   - run: pnpm --filter @cfp/cdk run cdk deploy --require-approval never CfpDeadlineCheckerStack
 *   ```
 *
 * セキュリティ設計:
 *   - subject claim を main ブランチに限定するため、機能ブランチや fork PR
 *     からは assume できない
 *   - OIDC + role assume なので long-lived アクセスキーを不要とする
 *   - maxSessionDuration を 1 時間に絞り、漏洩時の被害範囲を最小化
 */
export class GitHubOidc extends Construct {
  /**
   * AWS アカウントに登録される GitHub OIDC Identity Provider。
   * 同一アカウント内で他用途に再利用したい場合は本プロパティ経由で参照する
   * (新規 OpenIdConnectProvider を別途作成すると CloudFormation で衝突する)。
   */
  public readonly provider: OpenIdConnectProvider;

  /**
   * GitHub Actions が assume する IAM Role。
   * 物理名 (Role ARN) を GitHub リポジトリ Variables 等で参照する。
   */
  public readonly deployRole: Role;

  constructor(scope: Construct, id: string, props: GitHubOidcProps) {
    super(scope, id);

    this.provider = new OpenIdConnectProvider(this, 'Provider', {
      url: 'https://token.actions.githubusercontent.com',
      clientIds: ['sts.amazonaws.com'],
    });

    const subjectClaims = props.subjectClaims ?? [
      `repo:${props.githubOrg}/${props.githubRepo}:ref:refs/heads/main`,
    ];

    this.deployRole = new Role(this, 'DeployRole', {
      roleName: props.roleName ?? 'GitHubActionsAdminApiDeployRole',
      assumedBy: new WebIdentityPrincipal(this.provider.openIdConnectProviderArn, {
        StringEquals: {
          'token.actions.githubusercontent.com:aud': 'sts.amazonaws.com',
        },
        StringLike: {
          'token.actions.githubusercontent.com:sub': [...subjectClaims],
        },
      }),
      description:
        'GitHub Actions から CDK 経由で admin-api 等をデプロイするためのアサンプ用 Role',
      maxSessionDuration: Duration.hours(1),
    });

    const qualifier = props.cdkQualifier ?? 'hnb659fds';
    // CDK bootstrap stack が account/region 毎に作成する 4 種の Role を assume する。
    // ARN の region 部分は wildcard にしてマルチリージョン deploy に対応する。
    this.deployRole.addToPolicy(
      new PolicyStatement({
        effect: Effect.ALLOW,
        actions: ['sts:AssumeRole'],
        resources: [
          `arn:aws:iam::*:role/cdk-${qualifier}-deploy-role-*`,
          `arn:aws:iam::*:role/cdk-${qualifier}-file-publishing-role-*`,
          `arn:aws:iam::*:role/cdk-${qualifier}-image-publishing-role-*`,
          `arn:aws:iam::*:role/cdk-${qualifier}-lookup-role-*`,
        ],
      }),
    );
  }
}
