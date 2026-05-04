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

        // AWS SDK の Result は ArrayAccess (mixed value) なので明示的にナローイング。
        // Items は AttributeValue 配列の配列 (= array<int, array<string, mixed>>)。
        /** @var array<int, array<string, mixed>> $items */
        $items = $result['Items'] ?? [];

        $conferences = [];
        foreach ($items as $item) {
            $conferences[] = $this->toConference($this->unmarshalAsArray($item));
        }

        return $conferences;
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

        /** @var array<string, mixed> $rawItem */
        $rawItem = $result['Item'];

        return $this->toConference($this->unmarshalAsArray($rawItem));
    }

    /**
     * Marshaler::unmarshalItem の戻り型は array|stdClass union だが、第 2 引数
     * (mapAsObject) を省略 = false の運用なので必ず array<string, mixed> で返ることを
     * 型レベルで担保するヘルパ。
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function unmarshalAsArray(array $item): array
    {
        $result = $this->marshaler->unmarshalItem($item);
        // mapAsObject = false なので必ず array が返る (ランタイム保証)。
        // PHPStan が array<string, mixed> に narrow できないため明示変換する。
        $array = is_array($result) ? $result : (array) $result;
        $normalized = [];
        foreach ($array as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
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
        $categories = $item['categories'] ?? [];
        // DynamoDB の List 型は配列、Set 型もアプリ側では配列扱い。
        // categories は array<string> 想定だが防御的に文字列化する。
        $categoriesArray = is_array($categories) ? $categories : [];
        $stringCategories = array_values(array_map(
            static fn ($v): string => is_scalar($v) ? (string) $v : '',
            $categoriesArray,
        ));

        return new Conference(
            conferenceId: $this->stringify($item, 'conferenceId'),
            name: $this->stringify($item, 'name'),
            trackName: $this->nullableString($item, 'trackName'),
            officialUrl: $this->stringify($item, 'officialUrl'),
            cfpUrl: $this->stringify($item, 'cfpUrl'),
            eventStartDate: $this->stringify($item, 'eventStartDate'),
            eventEndDate: $this->stringify($item, 'eventEndDate'),
            venue: $this->stringify($item, 'venue'),
            format: ConferenceFormat::from($this->stringify($item, 'format')),
            cfpStartDate: $this->nullableString($item, 'cfpStartDate'),
            cfpEndDate: $this->stringify($item, 'cfpEndDate'),
            categories: $stringCategories,
            description: $this->nullableString($item, 'description'),
            themeColor: $this->nullableString($item, 'themeColor'),
            createdAt: $this->stringify($item, 'createdAt'),
            updatedAt: $this->stringify($item, 'updatedAt'),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function stringify(array $item, string $key): string
    {
        $value = $item[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function nullableString(array $item, string $key): ?string
    {
        if (! array_key_exists($key, $item) || $item[$key] === null) {
            return null;
        }
        $value = $item[$key];

        return is_scalar($value) ? (string) $value : null;
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
