import { Duration } from 'aws-cdk-lib';
import {
  Alarm,
  ComparisonOperator,
  TreatMissingData,
} from 'aws-cdk-lib/aws-cloudwatch';
import { SnsAction } from 'aws-cdk-lib/aws-cloudwatch-actions';
import { type IFunction } from 'aws-cdk-lib/aws-lambda';
import { Topic } from 'aws-cdk-lib/aws-sns';
import { EmailSubscription } from 'aws-cdk-lib/aws-sns-subscriptions';
import { Construct } from 'constructs';

/**
 * Operations Construct のオプション
 *
 * adminApiFunction:
 *   監視対象となる管理 API Lambda 関数。エラー / スロットル / レイテンシの
 *   メトリクスにアラームを掛ける。
 *
 * alertEmail (任意):
 *   アラーム発火時に通知メールを送る宛先。指定しない場合は SNS トピックのみ
 *   作成され、サブスクリプションは別途手動で行う必要がある。
 */
export interface OperationsProps {
  readonly adminApiFunction: IFunction;
  readonly alertEmail?: string;
}

/**
 * 運用観測用 Construct
 *
 * SNS トピックと CloudWatch アラームをまとめて管理する。
 *
 * 設定するアラーム:
 * 1. AdminApi のエラー (5 分間に 1 件以上)
 * 2. AdminApi のスロットル (5 分間に 1 件以上)
 * 3. AdminApi の P99 実行時間 (25 秒超を 2 回連続)
 *
 * いずれも SNS トピックへ通知。alertEmail が渡された場合は自動で
 * メール購読を作成する。
 *
 * NOTE:
 * EventBridge による日次ビルドは Amplify の Webhook URL が確定してから
 * 別途追加する。Amplify アプリ自体が未作成のため、本 Construct の
 * スコープ外とした。
 */
export class Operations extends Construct {
  public readonly alarmTopic: Topic;

  constructor(scope: Construct, id: string, props: OperationsProps) {
    super(scope, id);

    // SNS トピック (アラーム通知の中継点)。
    // 後から複数の購読者 (メール / Slack 連携 Lambda 等) を追加できるよう、
    // 個別アラームではなく Topic を介する構成にする。
    this.alarmTopic = new Topic(this, 'AlarmTopic', {
      displayName: 'CFP Deadline Checker - Operational Alarms',
    });

    // メールアドレスが渡されたら確認メールを送って購読する。
    // 受信者側で確認リンクをクリックするまで通知は届かない。
    if (props.alertEmail) {
      this.alarmTopic.addSubscription(new EmailSubscription(props.alertEmail));
    }

    const snsAction = new SnsAction(this.alarmTopic);

    // ── アラーム 1: Lambda エラー件数 ──
    // Lambda 関数本体が例外を投げた回数。Bref / Laravel の例外ハンドラで
    // catch し切れなかった場合に Lambda が失敗扱いとなりカウントされる。
    new Alarm(this, 'AdminApiErrors', {
      alarmName: 'cfp-admin-api-errors',
      alarmDescription: 'Admin API Lambda is producing errors',
      metric: props.adminApiFunction.metricErrors({
        period: Duration.minutes(5),
      }),
      threshold: 1,
      evaluationPeriods: 1,
      comparisonOperator:
        ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD,
      treatMissingData: TreatMissingData.NOT_BREACHING,
    }).addAlarmAction(snsAction);

    // ── アラーム 2: Lambda スロットル ──
    // 同時実行数の上限に達してリクエストが棄却された回数。
    // 管理画面利用者が少ない想定でも、攻撃時や暴走バグで急増する可能性がある。
    new Alarm(this, 'AdminApiThrottles', {
      alarmName: 'cfp-admin-api-throttles',
      alarmDescription: 'Admin API Lambda is being throttled',
      metric: props.adminApiFunction.metricThrottles({
        period: Duration.minutes(5),
      }),
      threshold: 1,
      evaluationPeriods: 1,
      comparisonOperator:
        ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD,
      treatMissingData: TreatMissingData.NOT_BREACHING,
    }).addAlarmAction(snsAction);

    // ── アラーム 3: Lambda 実行時間 (P99) ──
    // タイムアウト 28 秒に対し P99 が 25 秒を超え始めたら遅延の予兆として通知。
    // 2 回連続で閾値超過した場合のみ発火することで、瞬間的なピークでの
    // 誤通知を抑える。
    new Alarm(this, 'AdminApiDuration', {
      alarmName: 'cfp-admin-api-duration',
      alarmDescription: 'Admin API Lambda P99 duration is approaching timeout',
      metric: props.adminApiFunction.metricDuration({
        statistic: 'p99',
        period: Duration.minutes(5),
      }),
      threshold: 25_000, // ミリ秒単位 (25 秒)
      evaluationPeriods: 2,
      comparisonOperator: ComparisonOperator.GREATER_THAN_THRESHOLD,
      treatMissingData: TreatMissingData.NOT_BREACHING,
    }).addAlarmAction(snsAction);
  }
}
