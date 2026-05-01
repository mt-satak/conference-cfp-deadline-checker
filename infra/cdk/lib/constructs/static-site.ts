import { Duration, RemovalPolicy } from 'aws-cdk-lib';
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
import { type IFunctionUrl } from 'aws-cdk-lib/aws-lambda';
import { Version } from 'aws-cdk-lib/aws-lambda';
import { BlockPublicAccess, Bucket, BucketEncryption } from 'aws-cdk-lib/aws-s3';
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
 * basicAuthFunctionVersionArn:
 *   us-east-1 の Lambda@Edge (Basic 認証) Version ARN。指定すると
 *   /admin/* のビヘイビアに Viewer Request トリガーとして関連付ける。
 *   adminFunctionUrl と同時指定することを想定。
 */
export interface StaticSiteProps {
  readonly webAclArn?: string;
  readonly adminFunctionUrl?: IFunctionUrl;
  readonly basicAuthFunctionVersionArn?: string;
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
 */
export class StaticSite extends Construct {
  public readonly bucket: Bucket;
  public readonly distribution: Distribution;

  constructor(scope: Construct, id: string, props: StaticSiteProps = {}) {
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
    const additionalBehaviors: Record<string, BehaviorOptions> | undefined =
      props.adminFunctionUrl && props.basicAuthFunctionVersionArn
        ? {
            'admin/*': {
              // Lambda Function URL を OAC 経由のオリジンとして登録。
              // CloudFront のリクエスト署名を CloudFront 側で行うことで、
              // Function URL の直接ヒットを 403 でブロックできる。
              origin: FunctionUrlOrigin.withOriginAccessControl(
                props.adminFunctionUrl,
              ),
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
          }
        : undefined;

    // ── CloudFront Distribution ──
    // 既定動作は S3 オリジンへのアクセスで、HTTP は HTTPS にリダイレクト。
    // WAF WebACL が指定されていれば Distribution に関連付ける。
    this.distribution = new Distribution(this, 'Distribution', {
      comment: 'Conference CfP Deadline Checker',
      webAclId: props.webAclArn,
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
  }
}
