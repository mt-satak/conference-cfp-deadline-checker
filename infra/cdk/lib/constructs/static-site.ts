import { Duration, RemovalPolicy } from 'aws-cdk-lib';
import {
  AllowedMethods,
  CachePolicy,
  Distribution,
  HeadersFrameOption,
  HeadersReferrerPolicy,
  HttpVersion,
  PriceClass,
  ResponseHeadersPolicy,
  SecurityPolicyProtocol,
  ViewerProtocolPolicy,
} from 'aws-cdk-lib/aws-cloudfront';
import { S3BucketOrigin } from 'aws-cdk-lib/aws-cloudfront-origins';
import { BlockPublicAccess, Bucket, BucketEncryption } from 'aws-cdk-lib/aws-s3';
import { Construct } from 'constructs';

/**
 * StaticSite Construct のオプション
 *
 * webAclArn: us-east-1 の EdgeStack で作成された WAF WebACL の ARN。
 *            指定すると CloudFront Distribution に WAF を関連付ける。
 */
export interface StaticSiteProps {
  readonly webAclArn?: string;
}

/**
 * 静的サイト配信 Construct
 *
 * S3 バケット (プライベート) と CloudFront Distribution を作成し、
 * Origin Access Control (OAC) で CloudFront のみが S3 にアクセス可能な
 * 構成にする。レスポンスにはセキュリティヘッダ (HSTS / CSP 等) を付与。
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
