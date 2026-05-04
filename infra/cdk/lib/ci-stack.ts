import * as cdk from 'aws-cdk-lib';
import { Construct } from 'constructs';
import { GitHubOidc } from './constructs/github-oidc';

/**
 * CI/CD 専用スタック (us-east-1 / ap-northeast-1 どちらでも可。IAM はグローバル)。
 *
 * メインスタックや EdgeStack のライフサイクルから独立させる目的で別スタックに切り出す:
 * - GitHub Actions Role / OIDC Provider はアカウント全体で「1 度作って永続」のもの
 * - メインスタック (admin-api 等) を rebuild / destroy しても OIDC は残したい
 * - CI 向けの追加リソース (Slack 通知用 SNS 等) を後で足しても他スタックに混ざらない
 */
export interface CfpCiStackProps extends cdk.StackProps {
  /** GitHub オーガナイゼーション名 (個人アカウントでも同様に渡す) */
  readonly githubOrg: string;
  /** GitHub リポジトリ名 */
  readonly githubRepo: string;
  /**
   * CDK bootstrap で使用している qualifier。`cdk bootstrap --qualifier` で
   * 独自値を使っている場合のみ指定。
   */
  readonly cdkQualifier?: string;
  /**
   * 既存 GitHub OIDC Identity Provider の ARN。AWS アカウントは同 URL の
   * Provider を 1 つしか持てないため、別ツール / 別リポジトリが既に作成済の
   * 場合は本オプションで既存をそのまま import する。
   *
   * 形式: `arn:aws:iam::<ACCOUNT_ID>:oidc-provider/token.actions.githubusercontent.com`
   *
   * 取得方法:
   * ```sh
   * aws iam list-open-id-connect-providers \
   *   --query "OpenIDConnectProviderList[?contains(Arn, 'token.actions.githubusercontent.com')].Arn" \
   *   --output text
   * ```
   */
  readonly existingOidcProviderArn?: string;
}

export class CfpCiStack extends cdk.Stack {
  constructor(scope: Construct, id: string, props: CfpCiStackProps) {
    super(scope, id, props);

    const oidc = new GitHubOidc(this, 'GitHubOidc', {
      githubOrg: props.githubOrg,
      githubRepo: props.githubRepo,
      cdkQualifier: props.cdkQualifier,
      existingProviderArn: props.existingOidcProviderArn,
    });

    new cdk.CfnOutput(this, 'GitHubActionsDeployRoleArn', {
      value: oidc.deployRole.roleArn,
      description:
        'GitHub Actions が assume するデプロイ用 Role の ARN。' +
        'GitHub リポジトリの Variables (AWS_DEPLOY_ROLE_ARN) に設定する',
      exportName: 'CfpDeadlineCheckerCi-DeployRoleArn',
    });

    new cdk.CfnOutput(this, 'GitHubOidcProviderArn', {
      value: oidc.provider.openIdConnectProviderArn,
      description:
        'GitHub Actions OIDC Provider の ARN (アカウント内 IAM Identity Provider)',
    });
  }
}
