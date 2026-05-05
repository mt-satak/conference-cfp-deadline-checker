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

1. **既存 OIDC Provider の有無を確認**
   ```sh
   aws iam list-open-id-connect-providers \
     --query "OpenIDConnectProviderList[?contains(Arn, 'token.actions.githubusercontent.com')].Arn" \
     --output text
   ```
   AWS アカウントは `token.actions.githubusercontent.com` の OIDC Provider を
   1 つしか持てないため、別ツール / 別リポジトリで作成済の場合は次手順で
   `--context existingOidcProviderArn=...` を渡して既存を import する。
   何も表示されなければ新規作成 (context 未指定) で OK。

2. **diff で内容確認**
   ```sh
   # ケース A: 既存 Provider 無し (新規作成)
   pnpm cdk diff CfpDeadlineCheckerCiStack

   # ケース B: 既存 Provider あり (import)
   pnpm cdk diff CfpDeadlineCheckerCiStack \
     --context existingOidcProviderArn=arn:aws:iam::<ACCOUNT_ID>:oidc-provider/token.actions.githubusercontent.com
   ```
   - ケース A: IAM OIDC Provider 1 個 + IAM Role 1 個 + ポリシー
   - ケース B: IAM Role 1 個 + ポリシー (Provider はリソースとして作られない)

3. **デプロイ**
   ```sh
   # ケース A: 新規作成
   pnpm cdk deploy CfpDeadlineCheckerCiStack

   # ケース B: 既存 import (本番アカウントで他リポジトリが OIDC を使用中の例)
   pnpm cdk deploy CfpDeadlineCheckerCiStack \
     --context existingOidcProviderArn=arn:aws:iam::<ACCOUNT_ID>:oidc-provider/token.actions.githubusercontent.com
   ```

4. **出力された Role ARN を控える**
   ```sh
   aws cloudformation describe-stacks \
     --stack-name CfpDeadlineCheckerCiStack \
     --query "Stacks[0].Outputs[?OutputKey=='GitHubActionsDeployRoleArn'].OutputValue" \
     --output text
   ```
   出力例: `arn:aws:iam::123456789012:role/GitHubActionsAdminApiDeployRole`

   ⚠ **OIDC Provider ARN と混同しないこと**:
   - OIDC Provider ARN (`arn:...:oidc-provider/...`) は `--context` で渡す側、GitHub には登録しない
   - Role ARN (`arn:...:role/GitHubActionsAdminApiDeployRole`) を GitHub Variables に登録する

5. **GitHub リポジトリ Variables に登録**
   - `https://github.com/mt-satak/conference-cfp-deadline-checker/settings/variables/actions`
   - 新規 Variable: `AWS_DEPLOY_ROLE_ARN` = 手順 4 の Role ARN
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

## 本番アプリの初回デプロイ手順 (Edge / Main スタック / Issue #54)

`CfpDeadlineCheckerEdgeStack` (us-east-1) と `CfpDeadlineCheckerStack` (ap-northeast-1) を
デプロイして本番アプリを稼働させる手順。**初回のみ参照**、以降は GitHub Actions の
deploy workflow が代行する。

### 前提条件

1. **AWS アカウントへの SSO ログイン**
   ```sh
   aws sso login --profile <profile-name>
   export AWS_PROFILE=<profile-name>
   aws sts get-caller-identity   # → アカウント ID が返れば OK
   ```

2. **CDK bootstrap (両リージョン必須)**
   ```sh
   ACCOUNT=$(aws sts get-caller-identity --query Account --output text)
   cdk bootstrap aws://$ACCOUNT/ap-northeast-1
   cdk bootstrap aws://$ACCOUNT/us-east-1   # EdgeStack (Lambda@Edge / WAF / ACM) 用
   ```
   既に bootstrap 済の場合は idempotent なのでスキップ可。

3. **Bedrock モデルアクセス確認** (= LLM URL 抽出機能の前提)
   - 現状の AWS Bedrock は **「初回呼び出しで自動有効化」** 方式 (旧 Model access ページは廃止済)
     ただし Anthropic モデルは初回時に use case 詳細提出が要求される場合あり
   - **ap-northeast-1 では foundation model 直接呼び出しは on-demand 非対応** のため
     横断推論プロファイル経由必須:
     - `jp.anthropic.claude-sonnet-4-6` (日本国内データレジデンシ、推奨)
     - `global.anthropic.claude-sonnet-4-6` (世界横断、capacity 重視)
   - 利用可能性 + アクセス権の確認:
     ```sh
     # 1. Foundation model がアカウントから見えるか
     aws bedrock list-foundation-models --region ap-northeast-1 \
       --query "modelSummaries[?contains(modelId, 'sonnet-4-6')]" --output table

     # 2. 利用可能な inference profile を一覧
     aws bedrock list-inference-profiles --region ap-northeast-1 \
       --query "inferenceProfileSummaries[?contains(inferenceProfileId, 'sonnet-4-6')]" --output table

     # 3. 軽量呼び出しテスト (実コスト約 0.01 円)
     aws bedrock-runtime converse \
       --region ap-northeast-1 \
       --model-id jp.anthropic.claude-sonnet-4-6 \
       --messages '[{"role":"user","content":[{"text":"hello"}]}]' \
       --inference-config '{"maxTokens":50}'
     ```
     応答が返れば OK。`AccessDeniedException` が出た場合は use case 提出が必要 (Bedrock コンソール
     で Sonnet 4.6 のページから提出フォームに記入)。
   - **モデル ID 変更が必要な場合**: `infra/cdk/lib/constructs/admin-api.ts` の `LLM_MODEL` 環境変数
     を変更 (デフォルトは `jp.anthropic.claude-sonnet-4-6`)。`global.*` に変えるかは可用性 vs データ
     レジデンシのトレードオフで判断

4. **(任意) カスタムドメイン使用時**
   - `domainName=<host>` (例: `cfp.example.com`)
   - `rootDomain=<root>` (例: `example.com`、Hosted Zone を作る apex)
   - 両方指定時のみ Route 53 / ACM が自動セットアップされる
   - ドメイン未取得 / 不要なら指定せず、CloudFront のデフォルト `*.cloudfront.net` で動く

5. **(任意) 運用アラーム通知**
   - `alertEmail=<email>` 指定で SNS 購読自動登録
   - 受信側で確認リンク必須

### Step 1: EdgeStack を us-east-1 にデプロイ

```sh
cd infra/cdk

# diff 確認 (差分プレビュー)
pnpm cdk diff CfpDeadlineCheckerEdgeStack

# デプロイ実行
pnpm cdk deploy CfpDeadlineCheckerEdgeStack
# (任意ドメイン使用時)
# pnpm cdk deploy CfpDeadlineCheckerEdgeStack \
#   --context domainName=cfp.example.com \
#   --context rootDomain=example.com
```

EdgeStack が作成するリソース:
- WAF WebACL (CloudFront 用)
- Lambda@Edge (Basic 認証)
- Secrets Manager Secret (Basic 認証パスワード自動生成)
- ACM 証明書 (ドメイン使用時のみ)
- Route 53 Hosted Zone (ドメイン使用時のみ)

**所要時間**: 5〜10 分 (CloudFront propagation 含む)

### Step 2: MainStack を ap-northeast-1 にデプロイ

```sh
# diff 確認
pnpm cdk diff CfpDeadlineCheckerStack

# デプロイ実行
pnpm cdk deploy CfpDeadlineCheckerStack
# (任意設定併用時)
# pnpm cdk deploy CfpDeadlineCheckerStack \
#   --context domainName=cfp.example.com \
#   --context rootDomain=example.com \
#   --context alertEmail=ops@example.com
```

MainStack が作成するリソース:
- DynamoDB 2 テーブル (`cfp-conferences`, `cfp-categories`)
- Lambda Function (admin-api、Bref + PHP-FPM、`bedrock:InvokeModel` 権限付き)
- Lambda Function URL (AWS_IAM 認証)
- S3 Bucket + CloudFront Distribution (静的サイト + 管理画面ルーティング)
- CloudWatch アラーム + SNS Topic (Operations)

**所要時間**: 5〜15 分 (CloudFront 反映に追加で数分かかる)

### Step 3: Output を控える

```sh
aws cloudformation describe-stacks \
  --stack-name CfpDeadlineCheckerStack \
  --region ap-northeast-1 \
  --query "Stacks[0].Outputs" \
  --output table
```

主な出力:
- `DistributionDomainName`: 公開アクセス URL (`*.cloudfront.net`)
- `AdminApiFunctionUrl`: Lambda Function URL (CloudFront 経由のみ叩ける)
- `AdminApiFunctionName`: Lambda 関数名 (CloudWatch Logs / `aws lambda invoke` 等で使用)
- `ConferencesTableName` / `CategoriesTableName`: DynamoDB 名

### Step 4: 初期データ投入

DynamoDB は空でデプロイされるため、admin-api の seed コマンドを Lambda 上で実行する必要がある。
**現状 Bref Console handler 未実装**のため、初回投入の選択肢:

- (a) **AWS SDK で Lambda Invoke**: ローカルから Lambda の Function URL 経由で API を直接叩いて 1 件ずつ POST
- (b) **CloudShell から DynamoDB に直接 PutItem**: スクリプトで `data/seeds/categories.json` / `conferences.json` を流し込む
- (c) **Bref Console handler を別 Issue で追加**: `php artisan` を Lambda で実行できるようにする

(c) が将来的にきれいだが工数高。当面は (a) or (b) で凌ぐ。詳細は別 Issue で扱う候補。

### Step 5: 動作確認

1. **Basic 認証パスワード取得**
   ```sh
   aws secretsmanager get-secret-value \
     --secret-id <BasicAuthSecret-ARN> \
     --region us-east-1 \
     --query SecretString --output text
   ```

2. **CloudFront URL にアクセス**
   - `https://<DistributionDomainName>/admin/conferences`
   - Basic 認証ダイアログにユーザ名 `admin` + 上記パスワード入力
   - 一覧画面が空状態で表示されれば OK

3. **Bedrock LLM 抽出を実 URL で試す**
   - `/admin/conferences/create` で公式 URL を入れて「取り込む」
   - フォームが prefill されれば成功

4. **CloudWatch Logs で抽出ログを確認**
   ```sh
   aws logs tail /aws/lambda/<AdminApiFunctionName> --region ap-northeast-1 --since 5m \
     | grep "llm.extraction"
   ```
   `conference draft extraction succeeded` の行が出ればログ整形 OK。
   `input_tokens` / `output_tokens` が記録されている事を確認。

### よくある失敗 / 対処

| 失敗 | 原因 | 対処 |
|---|---|---|
| `cdk deploy` が `region not found` で失敗 | リージョン未 bootstrap | `cdk bootstrap aws://<ACCOUNT>/<REGION>` |
| LLM 抽出で `AccessDeniedException` | Bedrock モデルアクセス未承認 | コンソールで Model access を再申請 |
| LLM 抽出で `ResourceNotFoundException` | モデル ID が region で存在しない | `LLM_MODEL` を `apac.anthropic.claude-sonnet-4-6` に変更して再 deploy |
| CloudFront にアクセスして 403 / 401 ループ | Basic 認証パスワード忘れ | Secrets Manager から再取得 |
| Lambda Cold Start で 502 | Bref レイヤー初回ロード | 1〜2 分待って再アクセス |

### ロールバック

```sh
# スタック単位で削除
pnpm cdk destroy CfpDeadlineCheckerStack
pnpm cdk destroy CfpDeadlineCheckerEdgeStack
```

ただし DynamoDB は `removalPolicy: RETAIN` + `deletionProtection: true` のため、
スタック削除後もテーブルは残る (= データが消えない安全側設計)。
完全に削除する場合は AWS コンソールで deletionProtection を OFF にしてから手動削除。
