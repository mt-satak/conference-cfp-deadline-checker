<?php

namespace App\Infrastructure\DynamoDb;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ConferenceRepository の DynamoDB 実装。
 *
 * - 本番: AWS DynamoDB (cfp-conferences テーブル)
 * - 開発: DynamoDB Local (AWS_DYNAMODB_ENDPOINT で endpoint 上書き)
 *
 * Domain 層の Conference Entity と DynamoDB の AttributeValue 形式を
 * Marshaler で相互変換する。TTL 属性 (cfpEndDate の翌日 00:00 JST UNIX
 * timestamp) は Repository 層の責務として save 時に付与する
 * (Conference Entity 自体には載せない)。
 */
class DynamoDbConferenceRepository implements ConferenceRepository
{
    private readonly Marshaler $marshaler;

    public function __construct(
        private readonly DynamoDbClient $client,
        private readonly string $tableName,
    ) {
        $this->marshaler = new Marshaler();
    }

    public function findAll(): array
    {
        $result = $this->client->scan([
            'TableName' => $this->tableName,
        ]);

        $items = $result['Items'] ?? [];

        return array_values(array_map(
            fn (array $item) => $this->toConference($this->marshaler->unmarshalItem($item)),
            $items,
        ));
    }

    public function findById(string $conferenceId): ?Conference
    {
        $result = $this->client->getItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['conferenceId' => $conferenceId]),
        ]);

        if (! isset($result['Item'])) {
            return null;
        }

        return $this->toConference($this->marshaler->unmarshalItem($result['Item']));
    }

    public function save(Conference $conference): void
    {
        $this->client->putItem([
            'TableName' => $this->tableName,
            'Item' => $this->marshaler->marshalItem($this->toItem($conference)),
        ]);
    }

    public function deleteById(string $conferenceId): bool
    {
        // ReturnValues=ALL_OLD で削除前のアイテムを取得し、その有無で実際に
        // 削除が行われたかを判定する (idempotent な DeleteItem の戻りは
        // 標準では成否が分からないため)。
        $result = $this->client->deleteItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['conferenceId' => $conferenceId]),
            'ReturnValues' => 'ALL_OLD',
        ]);

        return isset($result['Attributes']);
    }

    /**
     * Conference Entity → DynamoDB に書き込む配列 (Marshaler に渡す前の plain PHP 形式)。
     *
     * @return array<string, mixed>
     */
    private function toItem(Conference $conference): array
    {
        $item = [
            'conferenceId' => $conference->conferenceId,
            'name' => $conference->name,
            'officialUrl' => $conference->officialUrl,
            'cfpUrl' => $conference->cfpUrl,
            'eventStartDate' => $conference->eventStartDate,
            'eventEndDate' => $conference->eventEndDate,
            'venue' => $conference->venue,
            'format' => $conference->format->value,
            'cfpEndDate' => $conference->cfpEndDate,
            'categories' => $conference->categories,
            'createdAt' => $conference->createdAt,
            'updatedAt' => $conference->updatedAt,
            'ttl' => $this->computeTtl($conference->cfpEndDate),
        ];

        // optional フィールドは null の場合は項目自体を載せない
        // (DynamoDB には null 値の保存も可能だが、後の Scan/Get で
        //  「キーが存在しない」と「null 値」を区別する必要が出るため、
        //  単純に存在しない状態にしておく)
        if ($conference->trackName !== null) {
            $item['trackName'] = $conference->trackName;
        }
        if ($conference->cfpStartDate !== null) {
            $item['cfpStartDate'] = $conference->cfpStartDate;
        }
        if ($conference->description !== null) {
            $item['description'] = $conference->description;
        }
        if ($conference->themeColor !== null) {
            $item['themeColor'] = $conference->themeColor;
        }

        return $item;
    }

    /**
     * Marshaler->unmarshalItem の結果 (plain PHP 配列) → Conference Entity。
     *
     * @param  array<string, mixed>  $item
     */
    private function toConference(array $item): Conference
    {
        return new Conference(
            conferenceId: (string) $item['conferenceId'],
            name: (string) $item['name'],
            trackName: isset($item['trackName']) ? (string) $item['trackName'] : null,
            officialUrl: (string) $item['officialUrl'],
            cfpUrl: (string) $item['cfpUrl'],
            eventStartDate: (string) $item['eventStartDate'],
            eventEndDate: (string) $item['eventEndDate'],
            venue: (string) $item['venue'],
            format: ConferenceFormat::from((string) $item['format']),
            cfpStartDate: isset($item['cfpStartDate']) ? (string) $item['cfpStartDate'] : null,
            cfpEndDate: (string) $item['cfpEndDate'],
            categories: array_values(array_map('strval', (array) ($item['categories'] ?? []))),
            description: isset($item['description']) ? (string) $item['description'] : null,
            themeColor: isset($item['themeColor']) ? (string) $item['themeColor'] : null,
            createdAt: (string) $item['createdAt'],
            updatedAt: (string) $item['updatedAt'],
        );
    }

    /**
     * cfpEndDate (YYYY-MM-DD JST) の翌日 00:00 JST を UNIX timestamp で返す。
     * DynamoDB TTL 属性として使う (data/schema.md 参照)。
     */
    private function computeTtl(string $cfpEndDate): int
    {
        $tz = new DateTimeZone('Asia/Tokyo');

        return (new DateTimeImmutable($cfpEndDate.' 00:00:00', $tz))
            ->modify('+1 day')
            ->getTimestamp();
    }
}
