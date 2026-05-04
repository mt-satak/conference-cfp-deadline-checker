# CDK Infrastructure

`Conference CfP Deadline Checker` の AWS インフラを管理する AWS CDK プロジェクト。

## スタック構成

| スタック | リージョン | 役割 |
|---|---|---|
| `CfpDeadlineCheckerCiStack` | ap-northeast-1 (IAM はグローバル) | GitHub Actions OIDC + Deploy Role |
| `CfpDeadlineCheckerEdgeStack` | us-east-1 | WAF / Lambda@Edge / ACM / Route 53 |
| `CfpDeadlineCheckerStack` | ap-northeast-1 | DynamoDB / 管理 API Lambda / 静的サイト / オペレーション |

## コマンド

```sh
pnpm typecheck      # tsc --noEmit
pnpm test           # vitest run
pnpm synth          # cdk synth (全スタック)
pnpm diff           # cdk diff
pnpm deploy         # cdk deploy
```

特定スタックだけを操作する場合:

```sh
pnpm cdk diff CfpDeadlineCheckerCiStack
pnpm cdk deploy CfpDeadlineCheckerCiStack
```

## CI / CD セットアップ手順 (Issue #19 Phase 1 / 初回のみ)

GitHub Actions が AWS にデプロイできるようにする初回セットアップ手順。
**1 度実行すれば永続**で、以降のアプリ更新ではこのスタックを触らない。

### 前提

- AWS アカウント (本番環境) の IAM ユーザーで認証済み (`aws sts get-caller-identity` が通る)
- CDK bootstrap 済み (`cdk bootstrap aws://<ACCOUNT_ID>/ap-northeast-1` 実行済み、
  qualifier はデフォルト `hnb659fds`)

### 手順

1. **diff で内容確認**
   ```sh
   cd infra/cdk
   pnpm cdk diff CfpDeadlineCheckerCiStack
   ```
   - 新規作成される: IAM OIDC Provider 1 個、IAM Role 1 個 + そのポリシー
   - **既に他用途で `token.actions.githubusercontent.com` の OIDC Provider が
     アカウントにある場合** はエラーになる (重複作成不可)。その場合は
     既存 Provider を import する形に Construct を切替えて再 PR する

2. **デプロイ**
   ```sh
   pnpm cdk deploy CfpDeadlineCheckerCiStack
   ```

3. **出力された Role ARN を控える**
   ```sh
   aws cloudformation describe-stacks \
     --stack-name CfpDeadlineCheckerCiStack \
     --query "Stacks[0].Outputs[?OutputKey=='GitHubActionsDeployRoleArn'].OutputValue" \
     --output text
   ```
   出力例: `arn:aws:iam::123456789012:role/GitHubActionsAdminApiDeployRole`

4. **GitHub リポジトリ Variables に登録**
   - `https://github.com/mt-satak/conference-cfp-deadline-checker/settings/variables/actions`
   - 新規 Variable: `AWS_DEPLOY_ROLE_ARN` = 手順 3 の ARN
   - Issue #19 Phase 3 のワークフローがこの値を `role-to-assume` で参照する

### 信頼関係の絞り込み

デフォルトでは `repo:mt-satak/conference-cfp-deadline-checker:ref:refs/heads/main`
の subject claim のみを許可。これにより:

- main ブランチ以外の workflow run からは AssumeRole 不可
- fork からの PR 由来 workflow も不可
- 機能ブランチ・tag・release run も不可 (必要になったら `subjectClaims` を拡張)

PR check の workflow は AWS 操作を必要としないため、本 Role を assume しない
構成にする (Issue #19 Phase 2)。

### 権限境界

`GitHubActionsAdminApiDeployRole` 自体は AWS リソースを直接操作できない。CDK が
bootstrap で作成した `cdk-hnb659fds-deploy-role-*` 等を `sts:AssumeRole` するだけ。
実際の CloudFormation / Lambda / S3 等の操作は CDK Role の権限範囲で行われる。

これにより本 Role が万一漏洩しても、CDK bootstrap で許可された範囲外の操作は
できないため、被害を最小化できる (例: 任意の IAM Role 作成や別 AWS サービスへの
影響は不可)。
