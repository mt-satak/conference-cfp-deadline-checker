<?php

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Infrastructure\DynamoDb\DynamoDbConferenceRepository;
use Aws\DynamoDb\DynamoDbClient;

/**
 * DynamoDbConferenceRepository の DynamoDB Local との結合テスト。
 *
 * 前提:
 *   - `pnpm db:up` で DynamoDB Local が localhost:8000 で起動中
 *   - `pnpm db:init` で cfp-conferences テーブルが作成済
 *
 * 起動していない環境ではスキップする。
 *
 * テストデータは UUID で隔離し、各テスト終了時に明示的に削除する
 * (本番と同じ cfp-conferences テーブルを使うが、conferenceId が
 *  ランダムなので他データと衝突しない)。
 */

const DDB_LOCAL_ENDPOINT = 'http://localhost:8000';
const DDB_LOCAL_REGION = 'ap-northeast-1';
const DDB_LOCAL_TABLE = 'cfp-conferences';

function dynamoDbLocalClient(): DynamoDbClient
{
    return new DynamoDbClient([
        'version' => 'latest',
        'region' => DDB_LOCAL_REGION,
        'endpoint' => DDB_LOCAL_ENDPOINT,
        'credentials' => ['key' => 'dummy', 'secret' => 'dummy'],
        'http' => ['connect_timeout' => 1, 'timeout' => 3],
    ]);
}

function skipIfDynamoDbLocalUnavailable(DynamoDbClient $client): void
{
    try {
        $client->listTables();
    } catch (\Throwable) {
        test()->markTestSkipped('DynamoDB Local が起動していません (pnpm db:up を実行してください)');
    }
}

it('save → findById → deleteById → findById の往復が DynamoDB Local で機能する', function () {
    // Given: DynamoDB Local 接続 + 衝突回避のための一意な UUID + テストデータ
    $client = dynamoDbLocalClient();
    skipIfDynamoDbLocalUnavailable($client);
    $repository = new DynamoDbConferenceRepository($client, DDB_LOCAL_TABLE);

    $id = '99999999-aaaa-4bbb-8ccc-' . substr(bin2hex(random_bytes(6)), 0, 12);
    $conference = new Conference(
        conferenceId: $id,
        name: 'Integration Test Conference',
        trackName: 'Test Track',
        officialUrl: 'https://integration-test.example.com',
        cfpUrl: 'https://integration-test.example.com/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: 'テスト会場',
        format: ConferenceFormat::Hybrid,
        cfpStartDate: '2026-05-01',
        cfpEndDate: '2026-07-15',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: 'This is an integration test record.',
        themeColor: '#FF6B6B',
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );

    try {
        // When (1): save → findById で往復させる
        $repository->save($conference);
        $loaded = $repository->findById($id);

        // Then (1): 保存・取得した内容が一致する
        expect($loaded)->not->toBeNull();
        expect($loaded->conferenceId)->toBe($id);
        expect($loaded->name)->toBe('Integration Test Conference');
        expect($loaded->trackName)->toBe('Test Track');
        expect($loaded->format)->toBe(ConferenceFormat::Hybrid);
        expect($loaded->categories)->toBe(['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02']);
        expect($loaded->themeColor)->toBe('#FF6B6B');

        // When (2): deleteById を呼ぶ
        $deleted = $repository->deleteById($id);

        // Then (2): 削除成功 (true) で、findById は null、再 deleteById は false
        expect($deleted)->toBeTrue();
        expect($repository->findById($id))->toBeNull();
        expect($repository->deleteById($id))->toBeFalse();
    } finally {
        // テスト中に例外で抜けた場合のための保険クリーンアップ
        try {
            $repository->deleteById($id);
        } catch (\Throwable) {
            // 何もしない (掃除漏れは次回テスト時に上書きされるか別 ID 利用なので影響軽微)
        }
    }
});
