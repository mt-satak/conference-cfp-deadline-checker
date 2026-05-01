import {
  CreateTableCommand,
  DynamoDBClient,
  ResourceInUseException,
  type CreateTableCommandInput,
} from '@aws-sdk/client-dynamodb';
import { BatchWriteCommand, DynamoDBDocumentClient } from '@aws-sdk/lib-dynamodb';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const ENDPOINT = process.env.DYNAMODB_ENDPOINT ?? 'http://localhost:8000';
const REGION = 'ap-northeast-1';

const client = new DynamoDBClient({
  endpoint: ENDPOINT,
  region: REGION,
  credentials: { accessKeyId: 'dummy', secretAccessKey: 'dummy' },
});

const doc = DynamoDBDocumentClient.from(client);

const TABLES: CreateTableCommandInput[] = [
  {
    TableName: 'cfp-conferences',
    KeySchema: [{ AttributeName: 'conferenceId', KeyType: 'HASH' }],
    AttributeDefinitions: [{ AttributeName: 'conferenceId', AttributeType: 'S' }],
    BillingMode: 'PAY_PER_REQUEST',
  },
  {
    TableName: 'cfp-categories',
    KeySchema: [{ AttributeName: 'categoryId', KeyType: 'HASH' }],
    AttributeDefinitions: [{ AttributeName: 'categoryId', AttributeType: 'S' }],
    BillingMode: 'PAY_PER_REQUEST',
  },
  {
    TableName: 'cfp-donations',
    KeySchema: [{ AttributeName: 'donationId', KeyType: 'HASH' }],
    AttributeDefinitions: [
      { AttributeName: 'donationId', AttributeType: 'S' },
      { AttributeName: 'gsi1pk', AttributeType: 'S' },
      { AttributeName: 'gsi1sk', AttributeType: 'S' },
    ],
    BillingMode: 'PAY_PER_REQUEST',
    GlobalSecondaryIndexes: [
      {
        IndexName: 'gsi1',
        KeySchema: [
          { AttributeName: 'gsi1pk', KeyType: 'HASH' },
          { AttributeName: 'gsi1sk', KeyType: 'RANGE' },
        ],
        Projection: { ProjectionType: 'ALL' },
      },
    ],
  },
];

interface SeedCategory {
  categoryId: string;
  name: string;
  slug: string;
  displayOrder: number;
  axis: string;
  exampleConferences?: string[];
}

interface SeedFile {
  categories: SeedCategory[];
}

async function createTableIdempotent(input: CreateTableCommandInput): Promise<void> {
  try {
    await client.send(new CreateTableCommand(input));
    console.log(`Created table: ${input.TableName}`);
  } catch (error) {
    if (error instanceof ResourceInUseException) {
      console.log(`Table already exists, skipping: ${input.TableName}`);
      return;
    }
    throw error;
  }
}

async function seedCategories(): Promise<void> {
  const seedPath = resolve(import.meta.dirname, '../../../data/seeds/categories.json');
  const seed = JSON.parse(readFileSync(seedPath, 'utf8')) as SeedFile;

  const now = new Date().toISOString();
  const items = seed.categories.map((c) => ({
    PutRequest: {
      Item: {
        categoryId: c.categoryId,
        name: c.name,
        slug: c.slug,
        displayOrder: c.displayOrder,
        axis: c.axis,
        createdAt: now,
        updatedAt: now,
      },
    },
  }));

  for (let i = 0; i < items.length; i += 25) {
    const chunk = items.slice(i, i + 25);
    await doc.send(
      new BatchWriteCommand({
        RequestItems: { 'cfp-categories': chunk },
      }),
    );
  }
  console.log(`Seeded ${items.length} categories`);
}

async function main(): Promise<void> {
  console.log(`Initializing DynamoDB Local at ${ENDPOINT}`);
  for (const table of TABLES) {
    await createTableIdempotent(table);
  }
  await seedCategories();
  console.log('Done.');
}

main().catch((error: unknown) => {
  console.error(error);
  process.exit(1);
});
