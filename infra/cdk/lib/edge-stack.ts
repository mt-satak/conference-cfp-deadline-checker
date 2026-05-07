import * as path from 'node:path';
import * as cdk from 'aws-cdk-lib';
import { Certificate, CertificateValidation } from 'aws-cdk-lib/aws-certificatemanager';
import {
  CompositePrincipal,
  ManagedPolicy,
  Role,
  ServicePrincipal,
} from 'aws-cdk-lib/aws-iam';
import { Runtime, type IVersion } from 'aws-cdk-lib/aws-lambda';
import { NodejsFunction } from 'aws-cdk-lib/aws-lambda-nodejs';
import { HostedZone, type IHostedZone } from 'aws-cdk-lib/aws-route53';
import { Secret } from 'aws-cdk-lib/aws-secretsmanager';
import { CfnWebACL } from 'aws-cdk-lib/aws-wafv2';
import { Construct } from 'constructs';

/**
 * EdgeStack のオプション
 *
 * domainName / rootDomain:
 *   ドメイン取得後にコンテキスト経由で渡される。
 *   - rootDomain: Hosted Zone を作るドメイン (例: "example.com")
 *   - domainName: 実際にサイトを配信するホスト名 (例: "cfp.example.com")
 *   両方が指定されている場合のみ Route 53 Hosted Zone と
 *   ACM 証明書を生成する。未指定時は DNS リソースは作成しない。
 *
 * existingHostedZoneId / existingCertificateArn (Phase 6.0 / Issue #119):
 *   Route 53 console でドメインを購入した場合、Route 53 が hosted zone を
 *   自動作成し、ACM 証明書も別途 console で発行されている。これらの既存
 *   リソースを CDK で新規作成せず参照する経路。
 *   - existingHostedZoneId: Route 53 で自動作成された hosted zone の ID
 *   - existingCertificateArn: ACM (us-east-1) で発行済みの証明書 ARN
 *   いずれも指定された場合のみ既存参照モード、未指定なら新規作成。
 */
export interface EdgeStackProps extends cdk.StackProps {
  readonly domainName?: string;
  readonly rootDomain?: string;
  readonly existingHostedZoneId?: string;
  readonly existingCertificateArn?: string;
}

/**
 * us-east-1 にデプロイされるエッジ系リソース用スタック
 *
 * 必須リソース:
 * 1. Basic 認証用 Secrets Manager シークレット
 * 2. /admin/* の Basic 認証を担う Lambda@Edge 関数
 * 3. CloudFront 用 AWS WAF WebACL（CLOUDFRONT スコープ）
 *
 * 任意リソース (ドメイン指定時のみ):
 * 4. Route 53 Hosted Zone (rootDomain で指定された値)
 * 5. ACM 証明書 (domainName 用、DNS 検証で自動発行)
 *
 * CloudFront に紐付くこれらのリソースは仕様上 us-east-1 必須のため、
 * メインスタック (ap-northeast-1) とは分離して配置する。
 */
export class EdgeStack extends cdk.Stack {
  public readonly basicAuthFunctionVersion: IVersion;
  public readonly webAclArn: string;
  public readonly basicAuthSecret: Secret;
  public readonly hostedZone?: IHostedZone;
  public readonly hostedZoneId?: string;
  public readonly zoneName?: string;
  public readonly certificateArn?: string;

  constructor(scope: Construct, id: string, props?: EdgeStackProps) {
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
        // ESM 出力時、esbuild は CJS 依存 (AWS SDK v3 内部) を解決するために
        // `__require()` ヘルパーを生成するが、ESM ランタイムでは require が
        // 未定義のため `Dynamic require of "buffer" is not supported` で落ちる。
        // banner で `createRequire(import.meta.url)` を注入して require を
        // ESM 上に存在させ、bundle 内の `__require` を機能させる。
        banner:
          "import { createRequire } from 'module'; const require = createRequire(import.meta.url);",
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

    // ── 5. Route 53 Hosted Zone と ACM 証明書 (任意) ──
    // ドメインが取得済みでコンテキストで渡された場合のみ作成する。
    // Hosted Zone は AWS アカウントレベルでグローバルだが、CDK 上は
    // 何らかのスタックに所属させる必要があるため、us-east-1 の EdgeStack に置く。
    // ACM 証明書は CloudFront 用なので必ず us-east-1 必須。
    //
    // Phase 6.0 (Issue #119): Route 53 console からドメインを購入した場合、
    // Route 53 が hosted zone を自動作成しているため、existingHostedZoneId が
    // 指定されたら fromHostedZoneAttributes で既存参照モードに切り替える。
    // ACM 証明書も同様に existingCertificateArn が指定されたら既存参照モード。
    if (props?.domainName && props?.rootDomain) {
      if (props?.existingHostedZoneId) {
        this.hostedZone = HostedZone.fromHostedZoneAttributes(
          this,
          'ImportedHostedZone',
          {
            hostedZoneId: props.existingHostedZoneId,
            zoneName: props.rootDomain,
          },
        );
      } else {
        this.hostedZone = new HostedZone(this, 'HostedZone', {
          zoneName: props.rootDomain,
          comment: `Hosted zone for ${props.rootDomain}`,
        });
      }
      this.hostedZoneId = this.hostedZone.hostedZoneId;
      this.zoneName = this.hostedZone.zoneName;

      // ACM 証明書 (CloudFront 用なので us-east-1 で発行)
      // existingCertificateArn が指定されていればその ARN を採用、
      // なければ DNS 検証で自動発行・自動更新する Certificate を新規作成。
      if (props?.existingCertificateArn) {
        this.certificateArn = props.existingCertificateArn;
      } else {
        const certificate = new Certificate(this, 'Certificate', {
          domainName: props.domainName,
          validation: CertificateValidation.fromDns(this.hostedZone),
        });
        this.certificateArn = certificate.certificateArn;

        new cdk.CfnOutput(this, 'CertificateArn', {
          value: certificate.certificateArn,
          description: 'ACM certificate ARN for CloudFront',
        });
      }

      // hosted zone Outputs は新規作成時のみ (= NS 値が必要なのは新規作成時のみ)。
      // 既存参照時は Route 53 が NS を自動設定済みなので不要。
      if (!props?.existingHostedZoneId) {
        new cdk.CfnOutput(this, 'HostedZoneId', {
          value: this.hostedZone.hostedZoneId,
          description: 'Route 53 hosted zone ID',
        });

        new cdk.CfnOutput(this, 'HostedZoneNameServers', {
          value: cdk.Fn.join(',', this.hostedZone.hostedZoneNameServers ?? []),
          description: 'Route 53 hosted zone name servers (configure at registrar)',
        });
      }
    }

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
