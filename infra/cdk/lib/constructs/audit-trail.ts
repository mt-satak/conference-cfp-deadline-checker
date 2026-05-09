import { Duration, RemovalPolicy } from 'aws-cdk-lib';
import { ReadWriteType, Trail } from 'aws-cdk-lib/aws-cloudtrail';
import {
  BlockPublicAccess,
  Bucket,
  BucketEncryption,
  type IBucket,
} from 'aws-cdk-lib/aws-s3';
import { Construct } from 'constructs';

/**
 * AWS アカウント全域の API 監査ログを取る Construct (Issue #130 #12)。
 *
 * 個人開発で「誰が・いつ・どこで・何をしたか」を必要に応じて遡及調査できる
 * 状態を最低コストで維持することを意図する。
 *
 * 設計方針:
 * - Multi-region trail (= 別リージョン経由の痕跡隠しを防止、コスト変わらず)
 * - Management events のみ記録 (Read + Write 両方)
 *   - Data events (S3 オブジェクト・Lambda invoke 等) は対費用効果が低いため不採用
 *   - Insights events も同上
 * - File integrity validation (= 改竄検知ハッシュ、無料)
 * - SSE-S3 暗号化 (KMS は cost 増のため不採用)
 * - S3 lifecycle: 90 日後に expire (= 個人開発の audit としては十分)
 * - Bucket は RemovalPolicy.RETAIN (= 監査ログを誤削除しない)
 *
 * 想定コスト:
 * - 最初の 1 trail の management events: 無料
 * - S3 ストレージ (~50MB/月、90 日サイクル): ~$0.004/月
 * - 合計: 月 1 円未満
 */
export class AuditTrail extends Construct {
  public readonly trail: Trail;
  public readonly logBucket: IBucket;

  constructor(scope: Construct, id: string) {
    super(scope, id);

    // 監査ログ保管用 S3 bucket。
    // RETAIN にしておくことで、stack 削除時にも誤って消えないようにする
    // (= 万一 stack を作り直す必要が出た時でも過去ログは温存される)。
    this.logBucket = new Bucket(this, 'LogBucket', {
      encryption: BucketEncryption.S3_MANAGED,
      blockPublicAccess: BlockPublicAccess.BLOCK_ALL,
      enforceSSL: true,
      versioned: false,
      lifecycleRules: [
        {
          id: 'expire-old-audit-logs',
          enabled: true,
          expiration: Duration.days(90),
        },
      ],
      removalPolicy: RemovalPolicy.RETAIN,
    });

    this.trail = new Trail(this, 'Trail', {
      bucket: this.logBucket,
      // Multi-region: 全リージョンの API 呼び出しを 1 trail で集約
      isMultiRegionTrail: true,
      // ログ改竄検知用ハッシュを別途配信 (= 無料)
      enableFileValidation: true,
      // Read + Write 両方の管理イベント (= console / API / SDK 経由のあらゆる操作)
      managementEvents: ReadWriteType.ALL,
    });
  }
}
