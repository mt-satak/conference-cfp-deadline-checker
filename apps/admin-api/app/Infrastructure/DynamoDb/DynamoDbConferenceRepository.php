<?php

namespace App\Infrastructure\DynamoDb;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\OfficialUrl;
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
        $this->marshaler = new Marshaler;
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
     * 引数 / DB 内 conference の officialUrl を OfficialUrl::normalize() で
     * 正規化してから比較する (Issue #152 Phase 1)。表記揺れを吸収。
     *
     * 件数規模 (50〜200) なので O(N) の全件 scan で十分。
     */
    public function findByOfficialUrl(string $officialUrl): ?Conference
    {
        $target = OfficialUrl::normalize($officialUrl);

        foreach ($this->findAll() as $conference) {
            if (OfficialUrl::normalize($conference->officialUrl) === $target) {
                return $conference;
            }
        }

        return null;
    }

    /**
     * status=Draft の中から officialUrl 一致の Conference を 1 件取得する (Issue #169)。
     *
     * 同 URL の Draft が複数あれば最新 createdAt を返す。AutoCrawl が「重複していたら
     * 最新の 1 件を更新」する挙動を保証するため。
     *
     * 件数規模 (Draft 50 件未満想定) なので O(N) の全件 scan + memory 内比較で十分。
     */
    public function findDraftByOfficialUrl(string $officialUrl): ?Conference
    {
        $target = OfficialUrl::normalize($officialUrl);
        $matched = [];

        foreach ($this->findAll() as $conference) {
            if ($conference->status !== ConferenceStatus::Draft) {
                continue;
            }
            if (OfficialUrl::normalize($conference->officialUrl) === $target) {
                $matched[] = $conference;
            }
        }

        if ($matched === []) {
            return null;
        }

        // 最新 createdAt を返す (= ISO 8601 文字列の辞書順 = 時系列順)
        usort($matched, static fn (Conference $a, Conference $b): int => strcmp($b->createdAt, $a->createdAt));

        return $matched[0];
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

    public function countByCategoryId(string $categoryId): int
    {
        // categories は List<String> 属性。DynamoDB の `contains` 関数で部分一致検索。
        // Categories 削除時の参照整合性チェック用 (HTTP 409 判定)。
        // 想定件数が小さい (50〜200) ため Scan 全件 + FilterExpression で十分。
        // Select=COUNT で結果項目を取得せず件数だけ返す (転送量とパース節約)。
        $result = $this->client->scan([
            'TableName' => $this->tableName,
            'FilterExpression' => 'contains(categories, :categoryId)',
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([':categoryId' => $categoryId]),
            'Select' => 'COUNT',
        ]);

        $count = $result['Count'] ?? 0;

        return is_int($count) ? $count : 0;
    }

    /**
     * Conference Entity → DynamoDB に書き込む配列 (Marshaler に渡す前の plain PHP 形式)。
     *
     * @return array<string, mixed>
     */
    private function toItem(Conference $conference): array
    {
        // Draft / Published 共通の必須属性のみ最初に積む。
        // status による任意項目 (cfpEndDate, eventStartDate 等) は null チェックで条件付け。
        $item = [
            'conferenceId' => $conference->conferenceId,
            'name' => $conference->name,
            'officialUrl' => $conference->officialUrl,
            'categories' => $conference->categories,
            'createdAt' => $conference->createdAt,
            'updatedAt' => $conference->updatedAt,
            'status' => $conference->status->value,
        ];

        // null フィールドは属性自体を載せない (DynamoDB は null も保存可能だが、後の Scan/Get で
        //  「キー不在」と「null 値」を区別する必要が出るため、単純に存在しない状態にしておく)
        if ($conference->trackName !== null) {
            $item['trackName'] = $conference->trackName;
        }
        if ($conference->cfpUrl !== null) {
            $item['cfpUrl'] = $conference->cfpUrl;
        }
        if ($conference->eventStartDate !== null) {
            $item['eventStartDate'] = $conference->eventStartDate;
        }
        if ($conference->eventEndDate !== null) {
            $item['eventEndDate'] = $conference->eventEndDate;
        }
        if ($conference->venue !== null) {
            $item['venue'] = $conference->venue;
        }
        if ($conference->format !== null) {
            $item['format'] = $conference->format->value;
        }
        if ($conference->cfpStartDate !== null) {
            $item['cfpStartDate'] = $conference->cfpStartDate;
        }
        if ($conference->cfpEndDate !== null) {
            $item['cfpEndDate'] = $conference->cfpEndDate;
            // TTL は cfpEndDate 確定時のみ付与。Draft の未確定アイテムは TTL なし
            // (= 自動削除されない)。Published 昇格時に save が再発行され TTL が付く。
            $item['ttl'] = $this->computeTtl($conference->cfpEndDate);
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

        $formatValue = $this->nullableString($item, 'format');

        return new Conference(
            conferenceId: $this->stringify($item, 'conferenceId'),
            name: $this->stringify($item, 'name'),
            trackName: $this->nullableString($item, 'trackName'),
            officialUrl: $this->stringify($item, 'officialUrl'),
            cfpUrl: $this->nullableString($item, 'cfpUrl'),
            eventStartDate: $this->nullableString($item, 'eventStartDate'),
            eventEndDate: $this->nullableString($item, 'eventEndDate'),
            venue: $this->nullableString($item, 'venue'),
            format: $formatValue !== null ? ConferenceFormat::tryFrom($formatValue) : null,
            cfpStartDate: $this->nullableString($item, 'cfpStartDate'),
            cfpEndDate: $this->nullableString($item, 'cfpEndDate'),
            categories: $stringCategories,
            description: $this->nullableString($item, 'description'),
            themeColor: $this->nullableString($item, 'themeColor'),
            createdAt: $this->stringify($item, 'createdAt'),
            updatedAt: $this->stringify($item, 'updatedAt'),
            status: $this->resolveStatus($item),
        );
    }

    /**
     * status 属性を ConferenceStatus enum に解決する。
     *
     * 後方互換のため、属性欠落 (Phase 0.5 導入前のレガシーアイテム) または
     * 未知値は Published に丸めて fail-safe 復元する。例外は投げない。
     *
     * @param  array<string, mixed>  $item
     */
    private function resolveStatus(array $item): ConferenceStatus
    {
        $value = $item['status'] ?? null;
        if (! is_string($value)) {
            return ConferenceStatus::Published;
        }

        return ConferenceStatus::tryFrom($value) ?? ConferenceStatus::Published;
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
