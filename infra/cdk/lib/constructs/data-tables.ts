import { Annotations, RemovalPolicy } from 'aws-cdk-lib';
import {
  AttributeType,
  BillingMode,
  Table,
  TableEncryption,
} from 'aws-cdk-lib/aws-dynamodb';
import { Construct } from 'constructs';

/**
 * DataTables の起動モード (Issue #28)。
 *
 * - 'production' (default): RETAIN + deletionProtection: true
 *   通常運用、本番データ保護を最優先 (architecture.md §11.2 S7)
 * - 'dev': DESTROY + deletionProtection: false
 *   初回セットアップ等、deploy 失敗→再試行を繰り返す段階で
 *   "Resource of type 'AWS::DynamoDB::Table' already exists" エラーを避ける
 *   ためのモード。CDK Annotation で警告を出して誤運用を防ぐ。
 */
export type DataTablesEnv = 'dev' | 'production';

export interface DataTablesProps {
  /**
   * 起動モード。未指定なら 'production' (= 現状維持の安全側)。
   */
  readonly env?: DataTablesEnv;
}

/**
 * DynamoDB テーブル (conferences / categories) を構築する Construct。
 *
 * デフォルトは本番運用前提 (RETAIN + deletionProtection 有効)。
 * Issue #28 で env='dev' フラグを追加し、初回セットアップでの摩擦を軽減できる
 * ようにした。
 */
export class DataTables extends Construct {
  public readonly conferences: Table;
  public readonly categories: Table;

  constructor(scope: Construct, id: string, props?: DataTablesProps) {
    super(scope, id);

    const isDev = props?.env === 'dev';
    const removalPolicy = isDev ? RemovalPolicy.DESTROY : RemovalPolicy.RETAIN;
    const deletionProtection = !isDev;

    this.conferences = new Table(this, 'Conferences', {
      tableName: 'cfp-conferences',
      partitionKey: { name: 'conferenceId', type: AttributeType.STRING },
      billingMode: BillingMode.PAY_PER_REQUEST,
      encryption: TableEncryption.AWS_MANAGED,
      pointInTimeRecoverySpecification: {
        pointInTimeRecoveryEnabled: true,
      },
      deletionProtection,
      timeToLiveAttribute: 'ttl',
      removalPolicy,
    });

    this.categories = new Table(this, 'Categories', {
      tableName: 'cfp-categories',
      partitionKey: { name: 'categoryId', type: AttributeType.STRING },
      billingMode: BillingMode.PAY_PER_REQUEST,
      encryption: TableEncryption.AWS_MANAGED,
      pointInTimeRecoverySpecification: {
        pointInTimeRecoveryEnabled: true,
      },
      deletionProtection,
      removalPolicy,
    });

    if (isDev) {
      // dev フラグの誤運用を防ぐための警告。本番運用に切り替えた後に dev を
      // 残したまま deploy しないよう synth 出力で目立つ形で出す。
      Annotations.of(this).addWarning(
        "DataTables env='dev' is enabled: tables will be DESTROYED on stack delete and deletionProtection is OFF. Use this only for initial setup; remove '--context env=dev' once data is loaded.",
      );
    }
  }
}
