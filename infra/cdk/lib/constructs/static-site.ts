import * as path from 'node:path';
import { Duration, RemovalPolicy, Stack } from 'aws-cdk-lib';
import {
  Certificate,
  type ICertificate,
} from 'aws-cdk-lib/aws-certificatemanager';
import {
  AllowedMethods,
  type BehaviorOptions,
  CachePolicy,
  Distribution,
  HeadersFrameOption,
  HeadersReferrerPolicy,
  HttpVersion,
  LambdaEdgeEventType,
  OriginRequestPolicy,
  PriceClass,
  ResponseHeadersPolicy,
  SecurityPolicyProtocol,
  ViewerProtocolPolicy,
} from 'aws-cdk-lib/aws-cloudfront';
import {
  FunctionUrlOrigin,
  S3BucketOrigin,
} from 'aws-cdk-lib/aws-cloudfront-origins';
import { ServicePrincipal } from 'aws-cdk-lib/aws-iam';
import { type IFunction, type IFunctionUrl } from 'aws-cdk-lib/aws-lambda';
import { Version } from 'aws-cdk-lib/aws-lambda';
import { BlockPublicAccess, Bucket, BucketEncryption } from 'aws-cdk-lib/aws-s3';
import { BucketDeployment, Source } from 'aws-cdk-lib/aws-s3-deployment';
import { Construct } from 'constructs';

/**
 * StaticSite Construct のオプション
 *
 * webAclArn:
 *   us-east-1 の EdgeStack で作成された WAF WebACL の ARN。指定すると
 *   CloudFront Distribution に WAF を関連付ける。
 *
 * adminFunctionUrl:
 *   管理 API Lambda の Function URL。指定すると /admin/* パスが
 *   この URL をオリジンとして使う動的ビヘイビアを追加する。
 *
 * adminFunction:
 *   管理 API Lambda の Function 本体。CloudFront OAC for Lambda Function URL
 *   は `lambda:InvokeFunctionUrl` だけでなく `lambda:InvokeFunction` の
 *   Resource Policy も要求するため、明示的に Permission を付与する目的で受ける。
 *   adminFunctionUrl と同時指定する想定。
 *
 * basicAuthFunctionVersionArn:
 *   us-east-1 の Lambda@Edge (Basic 認証) Version ARN。指定すると
 *   /admin/* のビヘイビアに Viewer Request トリガーとして関連付ける。
 *   adminFunctionUrl と同時指定することを想定。
 *
 * domainName / certificateArn:
 *   独自ドメインを使う場合に指定。両方が揃ったときのみ CloudFront に
 *   カスタムドメイン (Aliases) と TLS 証明書を関連付ける。
 *   未指定時は CloudFront のデフォルトドメイン (xxxxx.cloudfront.net) で配信。
 */
export interface StaticSiteProps {
  readonly webAclArn?: string;
  readonly adminFunctionUrl?: IFunctionUrl;
  readonly adminFunction?: IFunction;
  readonly basicAuthFunctionVersionArn?: string;
  readonly domainName?: string;
  readonly certificateArn?: string;
  /**
   * CloudFront → Lambda Function URL に付与する Custom Origin Header の secret 値 (Issue #77)。
   *
   * Function URL の AuthType=NONE に切り替えたため、CloudFront 経由か直アクセスかを
   * 判別する材料として `X-CloudFront-Secret: <secret>` を Origin に仕込む。
   * AdminApi 側にも同じ secret を Lambda 環境変数 (`CLOUDFRONT_ORIGIN_SECRET`) で
   * 渡し、CloudFrontSecretMiddleware がこのヘッダを検証する。
   */
  readonly cloudfrontOriginSecret: string;
}

/**
 * 静的サイト配信 + 管理画面ルーティング Construct
 *
 * 既定ビヘイビア (デフォルト動作):
 *   S3 バケットを Origin Access Control 経由で配信。HTML/CSS/JS 等の
 *   公開ページを担当する。
 *
 * /admin/* ビヘイビア (任意):
 *   adminFunctionUrl が渡されたとき有効化。Lambda Function URL を
 *   オリジンとし、Lambda@Edge の Basic 認証を Viewer Request で実行。
 *   キャッシュは無効化し動的レスポンスをそのまま返す。
 *
 * 独自ドメイン (任意):
 *   domainName + certificateArn が渡されたとき、CloudFront に
 *   Aliases (Cname) と TLS 証明書を関連付ける。
 */
export class StaticSite extends Construct {
  public readonly bucket: Bucket;
  public readonly distribution: Distribution;

  constructor(scope: Construct, id: string, props: StaticSiteProps) {
    super(scope, id);

    // ── S3 バケット ──
    // パブリックアクセスは全ブロックし、CloudFront 経由のみで配信する。
    // SSL 強制と S3 マネージド暗号化を有効化。誤削除防止のため RETAIN。
    this.bucket = new Bucket(this, 'SiteBucket', {
      blockPublicAccess: BlockPublicAccess.BLOCK_ALL,
      encryption: BucketEncryption.S3_MANAGED,
      enforceSSL: true,
      versioned: false,
      removalPolicy: RemovalPolicy.RETAIN,
    });

    // ── レスポンスヘッダポリシー ──
    // セキュリティ要件 S4 で定義されたヘッダ群を全レスポンスに付与する。
    // CSP は情報サイト用途を想定しつつ、Astro 等のフレームワークが必要とする
    // 最低限の許可（インラインスタイル等）を含めている。
    const securityHeaders = new ResponseHeadersPolicy(this, 'SecurityHeaders', {
      responseHeadersPolicyName: 'cfp-security-headers',
      securityHeadersBehavior: {
        strictTransportSecurity: {
          accessControlMaxAge: Duration.days(730),
          includeSubdomains: true,
          preload: true,
          override: true,
        },
        contentTypeOptions: { override: true },
        frameOptions: {
          frameOption: HeadersFrameOption.DENY,
          override: true,
        },
        referrerPolicy: {
          referrerPolicy: HeadersReferrerPolicy.STRICT_ORIGIN_WHEN_CROSS_ORIGIN,
          override: true,
        },
        contentSecurityPolicy: {
          contentSecurityPolicy: [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
          ].join('; '),
          override: true,
        },
      },
    });

    // ── /admin/* 用 ビヘイビア定義 (条件付き) ──
    // 管理 API の Function URL と Lambda@Edge の Version ARN が両方渡されたら
    // 動的ビヘイビアを構築する。片方欠けている場合は構成不全のため何もしない。
    //
    // Vite が生成する `/build/*` の CSS/JS は **Lambda 経由で配信しない**。
    // Bref FPM は静的ファイルを配信せず Laravel routing に丸ごと流すため、
    // public/build/* に対応する route が無ければ 404。CloudFront errorResponses
    // で 404 → /404.html (S3) へリダイレクトされ S3 AccessDenied になる
    // (PR #70 でこれを試したが上記理由で動かなかった)。
    // 代わりに BucketDeployment で apps/admin-api/public/build/ を S3 に
    // アップロードし、CloudFront default behavior (S3) から配信する方式を採用。
    // Path pattern は `admin*` (= 末尾 wildcard) を採用。`admin/*` だと `*` が
    // `/` の後の文字を要求するため `/admin` (末尾スラッシュなし) にマッチせず、
    // Laravel の `route('admin.home')` が出力する `/admin` が default behavior
    // (S3) に流れて 403 になっていた (Issue #67 のダッシュボードリンク問題)。
    // `admin*` は `/admin`, `/admin/`, `/admin/foo` すべてをマッチする。
    // 副作用として `/admina`, `/admin-foo` 等もマッチするが、本プロジェクトでは
    // `admin` で始まる無関係 URL は使わないため実害なし。
    const additionalBehaviors: Record<string, BehaviorOptions> | undefined =
      props.adminFunctionUrl && props.basicAuthFunctionVersionArn
        ? {
            'admin*': {
              // Lambda Function URL を OAC なしの Origin として登録 (Issue #77)。
              // OAC + POST の SigV4 mismatch 問題を回避するため Custom Origin
              // Header `X-CloudFront-Secret` を CloudFront 側から付与し、
              // Laravel CloudFrontSecretMiddleware で検証する方式に変更。
              origin: new FunctionUrlOrigin(props.adminFunctionUrl, {
                customHeaders: {
                  'X-CloudFront-Secret': props.cloudfrontOriginSecret,
                },
              }),
              // 動的レスポンスのため CloudFront ではキャッシュしない
              cachePolicy: CachePolicy.CACHING_DISABLED,
              // Host を除く全ヘッダ・クエリ・Cookie をオリジンへ転送
              originRequestPolicy:
                OriginRequestPolicy.ALL_VIEWER_EXCEPT_HOST_HEADER,
              viewerProtocolPolicy: ViewerProtocolPolicy.REDIRECT_TO_HTTPS,
              // 管理画面では POST/PUT/DELETE 等も使うため全メソッド許可
              allowedMethods: AllowedMethods.ALLOW_ALL,
              compress: true,
              responseHeadersPolicy: securityHeaders,
              edgeLambdas: [
                {
                  functionVersion: Version.fromVersionArn(
                    this,
                    'ImportedBasicAuthVersion',
                    props.basicAuthFunctionVersionArn,
                  ),
                  eventType: LambdaEdgeEventType.VIEWER_REQUEST,
                },
              ],
            },
            // /api/public/*: 公開フロント (Astro) 向け read-only API (Issue #91 / Phase 4.2)
            'api/public*': {
              // 同じ AdminApi Function URL を Origin として共有。CloudFrontSecretMiddleware
              // で Function URL 直アクセスを防ぐ点も /admin/* と同じ。
              origin: new FunctionUrlOrigin(props.adminFunctionUrl, {
                customHeaders: {
                  'X-CloudFront-Secret': props.cloudfrontOriginSecret,
                },
              }),
              // 公開 read-only API は CDN キャッシュ可能 (= 同一データを複数の build /
              // ブラウザに配信)。CACHING_OPTIMIZED は default 24h キャッシュ。
              // admin UI でデータ更新後は CloudFront invalidation か rebuild trigger で
              // キャッシュをクリアする運用 (Phase 5 で整備)。
              cachePolicy: CachePolicy.CACHING_OPTIMIZED,
              originRequestPolicy:
                OriginRequestPolicy.ALL_VIEWER_EXCEPT_HOST_HEADER,
              viewerProtocolPolicy: ViewerProtocolPolicy.REDIRECT_TO_HTTPS,
              // GET / HEAD のみ許可 (= read-only)
              allowedMethods: AllowedMethods.ALLOW_GET_HEAD,
              compress: true,
              responseHeadersPolicy: securityHeaders,
              // Lambda@Edge basic auth は付けない (= 公開 API なので誰でも GET 可能)。
              // Function URL 直アクセス防御は CloudFrontSecretMiddleware が担当。
            },
          }
        : undefined;

    // ── カスタムドメイン (任意) ──
    // domainName + certificateArn が両方揃った時だけ証明書を取り込み、
    // CloudFront にエイリアスを設定する。
    let domainNames: string[] | undefined;
    let certificate: ICertificate | undefined;
    if (props.domainName && props.certificateArn) {
      domainNames = [props.domainName];
      certificate = Certificate.fromCertificateArn(
        this,
        'ImportedCertificate',
        props.certificateArn,
      );
    }

    // ── CloudFront Distribution ──
    // 既定動作は S3 オリジンへのアクセスで、HTTP は HTTPS にリダイレクト。
    // WAF WebACL が指定されていれば Distribution に関連付ける。
    this.distribution = new Distribution(this, 'Distribution', {
      comment: 'Conference CfP Deadline Checker',
      webAclId: props.webAclArn,
      domainNames,
      certificate,
      defaultBehavior: {
        origin: S3BucketOrigin.withOriginAccessControl(this.bucket),
        viewerProtocolPolicy: ViewerProtocolPolicy.REDIRECT_TO_HTTPS,
        allowedMethods: AllowedMethods.ALLOW_GET_HEAD,
        cachePolicy: CachePolicy.CACHING_OPTIMIZED,
        responseHeadersPolicy: securityHeaders,
        compress: true,
      },
      additionalBehaviors,
      defaultRootObject: 'index.html',
      httpVersion: HttpVersion.HTTP2_AND_3,
      priceClass: PriceClass.PRICE_CLASS_200,
      minimumProtocolVersion: SecurityPolicyProtocol.TLS_V1_2_2021,
      errorResponses: [
        {
          httpStatus: 404,
          responseHttpStatus: 404,
          responsePagePath: '/404.html',
          ttl: Duration.minutes(5),
        },
      ],
    });

    // ── Lambda Function URL のアクセス制御 (Issue #77) ──
    // 当初は AuthType=AWS_IAM + OAC + InvokeFunction 権限の組み合わせだったが、
    // OAC + POST の SigV4 mismatch 問題のため AuthType=NONE + Custom Origin Header
    // 方式に切り替えた。AuthType=NONE なので `lambda:InvokeFunctionUrl` /
    // `lambda:InvokeFunction` の Resource Policy は不要。
    // 代わりに CloudFrontSecretMiddleware (Laravel 側) が Custom Origin Header を
    // 検証することで Function URL 直アクセスを防ぐ。

    // ── Vite ビルド成果物 (apps/admin-api/public/build/) を S3 に配置 (Issue #67) ──
    // Laravel @vite が生成する CSS/JS の URL `/build/assets/app-*.{css,js}` を
    // CloudFront default behavior (S3) から配信するための同期。
    // Bref FPM は静的ファイルを配信しないため Lambda 経由には載せられない。
    // ファイル名 hash で immutable なので prune: false で既存 (404.html 等) は
    // 残す。デプロイ前に `cd apps/admin-api && npm install && npm run build`
    // で public/build/ を生成しておくこと (CI/CD 化は Issue #19 Phase 2 で対応予定)。
    new BucketDeployment(this, 'AdminViteBuildDeployment', {
      sources: [
        Source.asset(
          path.join(
            __dirname,
            '..',
            '..',
            '..',
            '..',
            'apps',
            'admin-api',
            'public',
            'build',
          ),
        ),
      ],
      destinationBucket: this.bucket,
      destinationKeyPrefix: 'build',
      prune: false,
      // CloudFront edge cache を即時更新するため /build/* を invalidation 対象にする
      distribution: this.distribution,
      distributionPaths: ['/build/*'],
    });

    // ── 公開フロント (Astro) の静的ビルド成果物を S3 root に配置 (Issue #98 / Phase 5.1) ──
    // CloudFront default behavior (S3) から配信される。
    // path 衝突確認:
    //   /              → 公開フロント (本デプロイ)
    //   /categories/*  → 公開フロント (本デプロイ)
    //   /_astro/*      → Astro build assets (本デプロイ)
    //   /admin/*       → admin UI Lambda
    //   /api/public/*  → admin-api Lambda
    //   /build/*       → admin Vite assets (上記 AdminViteBuildDeployment)
    //   /404.html      → S3 errorResponses
    //
    // デプロイ前に必ず:
    //   cd apps/public-site && PUBLIC_API_BASE_URL=https://<domain> pnpm build
    // を実行して dist/ を生成しておくこと。CI/CD 化は Phase 5.2 で対応予定。
    //
    // prune: false で他の BucketDeployment が置いたファイル (= /build/*) を消さない。
    // distributionPaths: ['/*'] で全 path を invalidate (= デフォルト 24h cache を強制更新)。
    new BucketDeployment(this, 'PublicSiteDeployment', {
      sources: [
        Source.asset(
          path.join(
            __dirname,
            '..',
            '..',
            '..',
            '..',
            'apps',
            'public-site',
            'dist',
          ),
        ),
      ],
      destinationBucket: this.bucket,
      prune: false,
      distribution: this.distribution,
      distributionPaths: ['/*'],
    });
  }
}
