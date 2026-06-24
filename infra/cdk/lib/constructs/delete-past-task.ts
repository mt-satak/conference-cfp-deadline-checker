import { Duration } from 'aws-cdk-lib';
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
 * DeletePastTask Construct のオプション (Issue #221 PR-1)。
 *
 * 既存 admin-api Lambda と同じ Bref + PHP layer を共有しつつ、
 * handler を `artisan` に切り替えて `conferences:delete-past --apply` を実行する。
 */
export interface DeletePastTaskProps {
  /** admin-api と同じ Bref asset (Code.fromAsset で生成済み) */
  readonly adminApiCode: Code;
  /** admin-api と同じ Bref PHP runtime layer */
  readonly phpLayer: ILayerVersion;
  /** Laravel APP_KEY (admin-api Lambda と同値。SSM 解決トークン string、Issue #206 #1) */
  readonly appKey: string;
  /** Laravel APP_URL (= admin URL、boot 時の警告抑制用) */
  readonly appUrl: string;
  /** 操作対象の DynamoDB conferences テーブル */
  readonly conferences: ITable;
  /** Lambda の architecture (admin-api と同じ X86_64 を渡す想定) */
  readonly architecture: Architecture;
}

/**
 * 開催日を過ぎた Conference を全ステータス対象でハード削除する Lambda console
 * + EventBridge schedule (Issue #221 PR-1)。
 *
 * Bref console モード:
 *   handler='artisan' + BREF_RUNTIME='console' で Lambda invoke の payload
 *   `{cli: 'conferences:delete-past --apply'}` を artisan command として実行する。
 *   --apply 付き = 実削除 (コマンドのデフォルトは安全側の dry-run)。
 *
 * EventBridge schedule:
 *   毎週 月曜 JST 08:00 (= UTC 日曜 23:00)。weekDay='SUN' + hour='23'。
 *   既存 DiscoverConferences (同 月曜 08:00) と同居するが、EventBridge ルールは
 *   併存可能。削除 → 発見の順序依存は無い (削除対象は過去イベント、発見対象は新規)。
 *   実行前に対象の存在チェックを行い、対象が無ければ削除をスキップする
 *   (UseCase 側で candidates が空なら no-op、翌週再チェック)。
 *
 * Lambda timeout:
 *   5 分。LLM 抽出など重い処理は無く、DynamoDB の Scan + DeleteItem のみ。
 *
 * IAM:
 *   conferences テーブルへ read+write (Scan で対象抽出 + DeleteItem)。
 */
export class DeletePastTask extends Construct {
  public readonly function: LambdaFunction;
  public readonly schedule: Rule;

  constructor(scope: Construct, id: string, props: DeletePastTaskProps) {
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
        APP_KEY: props.appKey,
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
        'Weekly delete past conferences (all statuses, hard delete) (Issue #221 PR-1)',
    });

    // Scan で対象抽出 + DeleteItem するため read+write 権限が必要。
    props.conferences.grantReadWriteData(this.function);

    // EventBridge schedule: 毎週 月曜 JST 08:00 = UTC 日曜 23:00。
    // payload `{cli: 'conferences:delete-past --apply'}` で Bref console に渡す。
    this.schedule = new Rule(this, 'Schedule', {
      schedule: Schedule.cron({
        weekDay: 'SUN',
        hour: '23',
        minute: '0',
      }),
      description:
        'Weekly delete-past task (JST Mon 08:00 = UTC Sun 23:00) (Issue #221 PR-1)',
      targets: [
        new LambdaTarget(this.function, {
          event: RuleTargetInput.fromObject({
            cli: 'conferences:delete-past --apply',
          }),
        }),
      ],
    });
  }
}
