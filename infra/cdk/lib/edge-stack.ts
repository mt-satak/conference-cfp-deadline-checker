import * as path from 'node:path';
import * as cdk from 'aws-cdk-lib';
import {
  CompositePrincipal,
  ManagedPolicy,
  Role,
  ServicePrincipal,
} from 'aws-cdk-lib/aws-iam';
import { Runtime, type IVersion } from 'aws-cdk-lib/aws-lambda';
import { NodejsFunction } from 'aws-cdk-lib/aws-lambda-nodejs';
import { Secret } from 'aws-cdk-lib/aws-secretsmanager';
import { CfnWebACL } from 'aws-cdk-lib/aws-wafv2';
import { Construct } from 'constructs';

/**
 * us-east-1 にデプロイされるエッジ系リソース用スタック
 *
 * このスタックには以下の 3 つを定義する:
 * 1. Basic 認証用 Secrets Manager シークレット
 * 2. /admin/* の Basic 認証を担う Lambda@Edge 関数
 * 3. CloudFront 用 AWS WAF WebACL（CLOUDFRONT スコープ）
 *
 * CloudFront に紐付くこれらのリソースは仕様上 us-east-1 必須のため、
 * メインスタック (ap-northeast-1) とは分離して配置する。
 */
export class EdgeStack extends cdk.Stack {
  public readonly basicAuthFunctionVersion: IVersion;
  public readonly webAclArn: string;
  public readonly basicAuthSecret: Secret;

  constructor(scope: Construct, id: string, props?: cdk.StackProps) {
    super(scope, id, props);

    // ── 1. Basic 認証情報を保管する Secrets Manager シークレット ──
    // ユーザー名は固定 ("admin")、パスワードは CDK が 32 文字のランダム文字列を生成。
    // ローテーション時は AWS コンソール / SDK でこの値を更新するだけで Lambda@Edge 側に
    // 最大 CACHE_TTL_MS (5 分) 後に反映される。
    this.basicAuthSecret = new Secret(this, 'BasicAuthSecret', {
      secretName: 'cfp/admin-basic-auth',
      description: 'Basic auth credentials for /admin/* on CloudFront',
      generateSecretString: {
        secretStringTemplate: JSON.stringify({ username: 'admin' }),
        generateStringKey: 'password',
        excludePunctuation: true,
        passwordLength: 32,
      },
    });

    // ── 2. Lambda@Edge の IAM ロール ──
    // Lambda@Edge は通常 Lambda と異なり edgelambda.amazonaws.com の信頼が必要。
    // 加えて Secrets Manager 読み取り権限と CloudWatch Logs 書き込み権限を付与する。
    const edgeFunctionRole = new Role(this, 'BasicAuthFunctionRole', {
      assumedBy: new CompositePrincipal(
        new ServicePrincipal('lambda.amazonaws.com'),
        new ServicePrincipal('edgelambda.amazonaws.com'),
      ),
      description: 'Execution role for Lambda@Edge basic auth function',
      managedPolicies: [
        ManagedPolicy.fromAwsManagedPolicyName(
          'service-role/AWSLambdaBasicExecutionRole',
        ),
      ],
    });

    // シークレットの読み取り権限のみ最小権限で付与
    this.basicAuthSecret.grantRead(edgeFunctionRole);

    // ── 3. Lambda@Edge 関数本体 ──
    // NodejsFunction に esbuild で AWS SDK ごとバンドルさせる。
    // Lambda@Edge の Viewer Request では環境変数が使えないため、シークレット ID は
    // ハンドラコード内に定数として埋め込む（値そのものではなく ID なので問題なし）。
    const basicAuthFunction = new NodejsFunction(this, 'BasicAuthFunction', {
      entry: path.join(
        __dirname,
        '..',
        'edge-handlers',
        'basic-auth',
        'index.mjs',
      ),
      handler: 'handler',
      runtime: Runtime.NODEJS_20_X, // Lambda@Edge がサポートする最新 LTS
      role: edgeFunctionRole,
      description: 'Basic auth check for /admin/* paths',
      bundling: {
        minify: true,
        sourceMap: false,
        // SDK も含めて全部バンドル（Node 20 ランタイムには SDK 同梱されないため）
        externalModules: [],
        format: cdk.aws_lambda_nodejs.OutputFormat.ESM,
        target: 'node20',
      },
    });

    this.basicAuthFunctionVersion = basicAuthFunction.currentVersion;

    // ── 4. AWS WAF WebACL (CLOUDFRONT スコープ) ──
    // 3 つのルールを優先度順に評価:
    //   優先度 1: AWS マネージド Common rule set（XSS, SQLi 等の汎用防御）
    //   優先度 2: AWS マネージド Known bad inputs（既知の悪性パターン）
    //   優先度 3: /admin/* に対する IP ベースのレート制限
    const webAcl = new CfnWebACL(this, 'WebACL', {
      name: 'cfp-deadline-checker-webacl',
      description: 'WAF for Conference CfP Deadline Checker CloudFront',
      scope: 'CLOUDFRONT',
      defaultAction: { allow: {} },
      visibilityConfig: {
        cloudWatchMetricsEnabled: true,
        sampledRequestsEnabled: true,
        metricName: 'cfp-webacl',
      },
      rules: [
        {
          name: 'AWS-AWSManagedRulesCommonRuleSet',
          priority: 1,
          overrideAction: { none: {} },
          statement: {
            managedRuleGroupStatement: {
              vendorName: 'AWS',
              name: 'AWSManagedRulesCommonRuleSet',
            },
          },
          visibilityConfig: {
            cloudWatchMetricsEnabled: true,
            sampledRequestsEnabled: true,
            metricName: 'AWS-AWSManagedRulesCommonRuleSet',
          },
        },
        {
          name: 'AWS-AWSManagedRulesKnownBadInputsRuleSet',
          priority: 2,
          overrideAction: { none: {} },
          statement: {
            managedRuleGroupStatement: {
              vendorName: 'AWS',
              name: 'AWSManagedRulesKnownBadInputsRuleSet',
            },
          },
          visibilityConfig: {
            cloudWatchMetricsEnabled: true,
            sampledRequestsEnabled: true,
            metricName: 'AWS-AWSManagedRulesKnownBadInputsRuleSet',
          },
        },
        {
          // /admin/* に対する IP ベースのレート制限
          // 同一 IP から 5 分間で 100 リクエスト超を 1 時間ブロック
          name: 'AdminRateLimit',
          priority: 3,
          action: { block: {} },
          statement: {
            rateBasedStatement: {
              limit: 100,
              aggregateKeyType: 'IP',
              evaluationWindowSec: 300,
              scopeDownStatement: {
                byteMatchStatement: {
                  searchString: '/admin',
                  fieldToMatch: { uriPath: {} },
                  positionalConstraint: 'STARTS_WITH',
                  textTransformations: [{ priority: 0, type: 'NONE' }],
                },
              },
            },
          },
          visibilityConfig: {
            cloudWatchMetricsEnabled: true,
            sampledRequestsEnabled: true,
            metricName: 'AdminRateLimit',
          },
        },
      ],
    });

    this.webAclArn = webAcl.attrArn;

    new cdk.CfnOutput(this, 'BasicAuthFunctionVersionArn', {
      value: this.basicAuthFunctionVersion.functionArn,
      description: 'Lambda@Edge function version ARN for /admin/* basic auth',
    });

    new cdk.CfnOutput(this, 'WebAclArn', {
      value: this.webAclArn,
      description: 'WAF WebACL ARN to attach to CloudFront distribution',
    });

    new cdk.CfnOutput(this, 'BasicAuthSecretArn', {
      value: this.basicAuthSecret.secretArn,
      description: 'Secrets Manager ARN for basic auth credentials',
    });
  }
}
