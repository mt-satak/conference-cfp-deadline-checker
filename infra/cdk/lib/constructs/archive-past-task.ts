import { Duration, type SecretValue } from 'aws-cdk-lib';
import { type ITable } from 'aws-cdk-lib/aws-dynamodb';
import { Rule, RuleTargetInput, Schedule } from 'aws-cdk-lib/aws-events';
import { LambdaFunction as LambdaTarget } from 'aws-cdk-lib/aws-events-targets';
import {
  type Architecture,
  type Code,
  Function as LambdaFunction,
  type ILayerVersion,
  Runtime,
} from 'aws-cdk-lib/aws-lambda';
import { Construct } from 'constructs';

/**
 * ArchivePastTask Construct のオプション (Issue #165 Phase 3)。
 *
 * 既存 admin-api Lambda と同じ Bref + PHP layer を共有しつつ、
 * handler を `artisan` に切り替えて `conferences:archive-past` を実行する。
 */
export interface ArchivePastTaskProps {
  /** admin-api と同じ Bref asset (Code.fromAsset で生成済み) */
  readonly adminApiCode: Code;
  /** admin-api と同じ Bref PHP runtime layer */
  readonly phpLayer: ILayerVersion;
  /** Laravel APP_KEY (admin-api Lambda と同値で揃える) */
  readonly appKey: SecretValue;
  /** Laravel APP_URL (= admin URL、boot 時の警告抑制用) */
  readonly appUrl: string;
  /** 操作対象の DynamoDB conferences テーブル */
  readonly conferences: ITable;
  /** Lambda の architecture (admin-api と同じ X86_64 を渡す想定) */
  readonly architecture: Architecture;
}

/**
 * 開催日を過ぎた Published Conference を Archived 状態に遷移させる Lambda console
 * + EventBridge schedule (Issue #165 Phase 3)。
 *
 * Bref console モード:
 *   handler='artisan' + BREF_RUNTIME='console' で Lambda invoke の payload
 *   `{cli: 'conferences:archive-past'}` を artisan command として実行する。
 *
 * EventBridge schedule:
 *   毎朝 JST 06:00 (= UTC 21:00 前日)。CDK の Schedule.cron は UTC 基準なので
 *   `hour: '21'` とすると UTC 21:00 = JST 翌日 06:00 になる。
 *   日次起動の意図: 終了日翌朝にすぐアーカイブして admin の一覧を清浄に保つ。
 *
 * Lambda timeout:
 *   5 分。AutoCrawl (Issue #152) の 15 分より短く設定。LLM 抽出など重い処理を
 *   含まず、純粋に DynamoDB の Scan + 各候補の UpdateItem だけなので 5 分で十分。
 *
 * IAM:
 *   conferences テーブルへ read+write (status を Archived に更新するため)。
 *   categories 等の他テーブルは触らないため権限不要。
 */
export class ArchivePastTask extends Construct {
  public readonly function: LambdaFunction;
  public readonly schedule: Rule;

  constructor(scope: Construct, id: string, props: ArchivePastTaskProps) {
    super(scope, id);

    this.function = new LambdaFunction(this, 'Function', {
      runtime: Runtime.PROVIDED_AL2023,
      handler: 'artisan',
      code: props.adminApiCode,
      layers: [props.phpLayer],
      architecture: props.architecture,
      timeout: Duration.minutes(5),
      memorySize: 512,
      environment: {
        BREF_RUNTIME: 'console',
        BREF_LOOP_MAX: '1',
        // ── Laravel ランタイム環境変数 (= admin-api Lambda と揃える) ──
        APP_KEY: props.appKey.unsafeUnwrap(),
        APP_ENV: 'production',
        APP_DEBUG: 'false',
        SESSION_DRIVER: 'cookie',
        SESSION_SECURE_COOKIE: 'true',
        SESSION_SAME_SITE: 'strict',
        CACHE_STORE: 'array',
        DYNAMODB_CONFERENCES_TABLE: props.conferences.tableName,
        // boot 時の config('app.url') 警告抑制用 (= 実際の URL 生成には使われない)
        APP_URL: props.appUrl,
      },
      description:
        'Archive past Published conferences to keep the admin list clean (Issue #165 Phase 3)',
    });

    // status を Archived に更新するため write 権限が必要。
    props.conferences.grantReadWriteData(this.function);

    // EventBridge schedule: 毎朝 JST 06:00 = UTC 21:00 (前日)
    // payload `{cli: 'conferences:archive-past'}` で Bref console に artisan command を渡す。
    this.schedule = new Rule(this, 'Schedule', {
      schedule: Schedule.cron({
        minute: '0',
        hour: '21',
      }),
      description:
        'Daily archive-past task (JST 06:00) for past conferences (Issue #165 Phase 3)',
      targets: [
        new LambdaTarget(this.function, {
          event: RuleTargetInput.fromObject({
            cli: 'conferences:archive-past',
          }),
        }),
      ],
    });
  }
}
