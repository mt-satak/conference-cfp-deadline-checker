import { App, Stack } from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import { describe, expect, it } from 'vitest';
import { AuditTrail } from './audit-trail';

/**
 * AuditTrail Construct の単体テスト (Issue #130 #12: CloudTrail 監査ログ)。
 *
 * 個人開発規模で安価に動かすことを意図した設定:
 *   - Multi-region trail (= 別リージョン経由の痕跡隠しを防止、コスト変わらず)
 *   - Management events のみ記録 (= データイベントは過剰なので入れない)
 *   - File integrity validation 有効 (= 無料、改竄検知)
 *   - SSE-S3 暗号化 (= KMS は cost 増)
 *   - S3 lifecycle で 90 日後に自動削除 (= ストレージコスト最小化)
 *   - logBucket は RemovalPolicy.RETAIN (= 監査ログを誤削除しない)
 *
 * Template assertion で CloudFormation 出力を検証 (実 AWS デプロイは行わない)。
 */
function synthTemplate(): Template {
    const app = new App();
    const stack = new Stack(app, 'TestStack');
    new AuditTrail(stack, 'AuditTrailUnderTest');

    return Template.fromStack(stack);
}

describe('AuditTrail Construct', () => {
    it('Multi-region trail を作成する', () => {
        // Given/When
        const template = synthTemplate();

        // Then
        template.hasResourceProperties('AWS::CloudTrail::Trail', {
            IsMultiRegionTrail: true,
        });
    });

    it('Log file integrity validation を有効化する', () => {
        // Given/When
        const template = synthTemplate();

        // Then
        template.hasResourceProperties('AWS::CloudTrail::Trail', {
            EnableLogFileValidation: true,
        });
    });

    it('Trail 自体が IsLogging: true (= 起動状態) で作成される', () => {
        // Given/When
        const template = synthTemplate();

        // Then
        template.hasResourceProperties('AWS::CloudTrail::Trail', {
            IsLogging: true,
        });
    });

    it('S3 bucket は SSE-S3 (AES256) 暗号化で作成される', () => {
        // Given/When
        const template = synthTemplate();

        // Then
        template.hasResourceProperties('AWS::S3::Bucket', {
            BucketEncryption: {
                ServerSideEncryptionConfiguration: [
                    Match.objectLike({
                        ServerSideEncryptionByDefault: { SSEAlgorithm: 'AES256' },
                    }),
                ],
            },
        });
    });

    it('S3 bucket は public access を全 block する', () => {
        // Given/When
        const template = synthTemplate();

        // Then
        template.hasResourceProperties('AWS::S3::Bucket', {
            PublicAccessBlockConfiguration: {
                BlockPublicAcls: true,
                BlockPublicPolicy: true,
                IgnorePublicAcls: true,
                RestrictPublicBuckets: true,
            },
        });
    });

    it('S3 bucket は 90 日 lifecycle で expire される', () => {
        // Given/When
        const template = synthTemplate();

        // Then
        template.hasResourceProperties('AWS::S3::Bucket', {
            LifecycleConfiguration: {
                Rules: Match.arrayWith([
                    Match.objectLike({
                        Status: 'Enabled',
                        ExpirationInDays: 90,
                    }),
                ]),
            },
        });
    });

    it('S3 bucket は誤削除防止のため RETAIN ポリシー', () => {
        // Given/When
        const template = synthTemplate();

        // Then
        template.hasResource('AWS::S3::Bucket', {
            DeletionPolicy: 'Retain',
            UpdateReplacePolicy: 'Retain',
        });
    });

    it('CloudTrail から S3 への書き込み権限が bucket policy に付与される', () => {
        // Given/When
        const template = synthTemplate();

        // Then: cloudtrail.amazonaws.com Principal が AclCheck / write 用ポリシーを持つ
        template.hasResourceProperties('AWS::S3::BucketPolicy', {
            PolicyDocument: Match.objectLike({
                Statement: Match.arrayWith([
                    Match.objectLike({
                        Principal: { Service: 'cloudtrail.amazonaws.com' },
                    }),
                ]),
            }),
        });
    });
});
