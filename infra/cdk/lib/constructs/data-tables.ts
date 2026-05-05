import { RemovalPolicy } from 'aws-cdk-lib';
import {
  AttributeType,
  BillingMode,
  Table,
  TableEncryption,
} from 'aws-cdk-lib/aws-dynamodb';
import { Construct } from 'constructs';

export class DataTables extends Construct {
  public readonly conferences: Table;
  public readonly categories: Table;

  constructor(scope: Construct, id: string) {
    super(scope, id);

    this.conferences = new Table(this, 'Conferences', {
      tableName: 'cfp-conferences',
      partitionKey: { name: 'conferenceId', type: AttributeType.STRING },
      billingMode: BillingMode.PAY_PER_REQUEST,
      encryption: TableEncryption.AWS_MANAGED,
      pointInTimeRecoverySpecification: {
        pointInTimeRecoveryEnabled: true,
      },
      deletionProtection: true,
      timeToLiveAttribute: 'ttl',
      removalPolicy: RemovalPolicy.RETAIN,
    });

    this.categories = new Table(this, 'Categories', {
      tableName: 'cfp-categories',
      partitionKey: { name: 'categoryId', type: AttributeType.STRING },
      billingMode: BillingMode.PAY_PER_REQUEST,
      encryption: TableEncryption.AWS_MANAGED,
      pointInTimeRecoverySpecification: {
        pointInTimeRecoveryEnabled: true,
      },
      deletionProtection: true,
      removalPolicy: RemovalPolicy.RETAIN,
    });
  }
}
