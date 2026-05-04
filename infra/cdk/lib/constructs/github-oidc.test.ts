import { App, Stack } from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import { describe, it } from 'vitest';
import { GitHubOidc, type GitHubOidcProps } from './github-oidc';

/**
 * GitHubOidc Construct の単体テスト。
 *
 * CDK の Template assertion API で生成される CloudFormation 上のリソース定義を
 * 検証する。実 AWS デプロイは行わない。
 */

function synthTemplate(overrides: Partial<GitHubOidcProps> = {}): Template {
  const app = new App();
  const stack = new Stack(app, 'TestStack');
  new GitHubOidc(stack, 'OidcUnderTest', {
    githubOrg: 'test-org',
    githubRepo: 'test-repo',
    ...overrides,
  });
  return Template.fromStack(stack);
}

describe('GitHubOidc', () => {
  it('GitHub Actions の OIDC Identity Provider を作成する', () => {
    // Given/When: デフォルト設定で synth する
    const template = synthTemplate();

    // Then: token.actions.githubusercontent.com が IAM OIDC Provider として登録される
    template.hasResourceProperties('Custom::AWSCDKOpenIdConnectProvider', {
      Url: 'https://token.actions.githubusercontent.com',
      ClientIDList: ['sts.amazonaws.com'],
    });
  });

  it('IAM Role を 1 つ作成し、デフォルトでは main ブランチに subject claim を限定する', () => {
    // Given/When
    const template = synthTemplate();

    // Then: subject claim が main 限定であることを確認する
    template.hasResourceProperties(
      'AWS::IAM::Role',
      Match.objectLike({
        AssumeRolePolicyDocument: {
          Statement: Match.arrayWith([
            Match.objectLike({
              Effect: 'Allow',
              Action: 'sts:AssumeRoleWithWebIdentity',
              Condition: Match.objectLike({
                StringLike: {
                  'token.actions.githubusercontent.com:sub': [
                    'repo:test-org/test-repo:ref:refs/heads/main',
                  ],
                },
                StringEquals: {
                  'token.actions.githubusercontent.com:aud': 'sts.amazonaws.com',
                },
              }),
            }),
          ]),
        },
      }),
    );
  });

  it('subjectClaims を渡すと trust ポリシーの subject 条件を上書きできる', () => {
    // Given/When: PR 込みで許可するパターンを渡す
    const template = synthTemplate({
      subjectClaims: ['repo:test-org/test-repo:*'],
    });

    // Then: 渡した値がそのまま StringLike に入る
    template.hasResourceProperties(
      'AWS::IAM::Role',
      Match.objectLike({
        AssumeRolePolicyDocument: {
          Statement: Match.arrayWith([
            Match.objectLike({
              Condition: Match.objectLike({
                StringLike: {
                  'token.actions.githubusercontent.com:sub': ['repo:test-org/test-repo:*'],
                },
              }),
            }),
          ]),
        },
      }),
    );
  });

  it('CDK bootstrap roles のみを assume できる権限ポリシーを付与する', () => {
    // Given/When: デフォルト qualifier (hnb659fds) で synth
    const template = synthTemplate();

    // Then: 4 種の cdk-hnb659fds-*-role-* に対して sts:AssumeRole 許可
    template.hasResourceProperties(
      'AWS::IAM::Policy',
      Match.objectLike({
        PolicyDocument: {
          Statement: Match.arrayWith([
            Match.objectLike({
              Effect: 'Allow',
              Action: 'sts:AssumeRole',
              Resource: [
                'arn:aws:iam::*:role/cdk-hnb659fds-deploy-role-*',
                'arn:aws:iam::*:role/cdk-hnb659fds-file-publishing-role-*',
                'arn:aws:iam::*:role/cdk-hnb659fds-image-publishing-role-*',
                'arn:aws:iam::*:role/cdk-hnb659fds-lookup-role-*',
              ],
            }),
          ]),
        },
      }),
    );
  });

  it('cdkQualifier を渡すと bootstrap role の ARN パターンを切り替える', () => {
    // Given/When: 独自 qualifier
    const template = synthTemplate({ cdkQualifier: 'custom123' });

    // Then: ARN リソースが custom123 を含む
    template.hasResourceProperties(
      'AWS::IAM::Policy',
      Match.objectLike({
        PolicyDocument: {
          Statement: Match.arrayWith([
            Match.objectLike({
              Resource: Match.arrayWith([
                'arn:aws:iam::*:role/cdk-custom123-deploy-role-*',
              ]),
            }),
          ]),
        },
      }),
    );
  });

  it('Role の物理名はデフォルトで GitHubActionsAdminApiDeployRole', () => {
    // Given/When
    const template = synthTemplate();

    // Then
    template.hasResourceProperties(
      'AWS::IAM::Role',
      Match.objectLike({
        RoleName: 'GitHubActionsAdminApiDeployRole',
      }),
    );
  });

  it('roleName を渡すと物理名を上書きできる', () => {
    // Given/When
    const template = synthTemplate({ roleName: 'CustomRoleName' });

    // Then
    template.hasResourceProperties(
      'AWS::IAM::Role',
      Match.objectLike({
        RoleName: 'CustomRoleName',
      }),
    );
  });

  it('セッション最大時間は 1 時間 (3600 秒) に制限する', () => {
    // Given/When
    const template = synthTemplate();

    // Then
    template.hasResourceProperties(
      'AWS::IAM::Role',
      Match.objectLike({
        MaxSessionDuration: 3600,
      }),
    );
  });
});
