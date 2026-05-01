import {
  DeleteTableCommand,
  DynamoDBClient,
  ResourceNotFoundException,
} from '@aws-sdk/client-dynamodb';

const ENDPOINT = process.env.DYNAMODB_ENDPOINT ?? 'http://localhost:8000';
const REGION = 'ap-northeast-1';

const client = new DynamoDBClient({
  endpoint: ENDPOINT,
  region: REGION,
  credentials: { accessKeyId: 'dummy', secretAccessKey: 'dummy' },
});

const TABLE_NAMES = ['cfp-conferences', 'cfp-categories', 'cfp-donations'];

async function deleteTableIfExists(name: string): Promise<void> {
  try {
    await client.send(new DeleteTableCommand({ TableName: name }));
    console.log(`Deleted table: ${name}`);
  } catch (error) {
    if (error instanceof ResourceNotFoundException) {
      console.log(`Table not found, skipping: ${name}`);
      return;
    }
    throw error;
  }
}

async function main(): Promise<void> {
  console.log(`Resetting DynamoDB Local at ${ENDPOINT}`);
  for (const name of TABLE_NAMES) {
    await deleteTableIfExists(name);
  }
  console.log('Done.');
}

main().catch((error: unknown) => {
  console.error(error);
  process.exit(1);
});
