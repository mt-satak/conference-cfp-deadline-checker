<?php

declare(strict_types=1);

namespace App\Infrastructure\DynamoDb;

use App\Domain\CfpSources\CfpSource;
use App\Domain\CfpSources\CfpSourceRepository;
use App\Domain\Conferences\OfficialUrl;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

/**
 * CfpSourceRepository の DynamoDB 実装 (Issue #200 PR-1)。
 *
 * - 本番: AWS DynamoDB (cfp-sources テーブル)
 * - 開発: DynamoDB Local
 *
 * 想定件数は数〜数十件なので findByUrl は Scan + フィルタで十分。
 * URL の重複検査は OfficialUrl::normalize を介して表記揺れを吸収する
 * (= Conference 側と同じロジック)。
 */
class DynamoDbCfpSourceRepository implements CfpSourceRepository
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

        /** @var array<int, array<string, mixed>> $items */
        $items = $result['Items'] ?? [];

        $sources = [];
        foreach ($items as $item) {
            $sources[] = $this->toCfpSource($this->unmarshalAsArray($item));
        }

        return $sources;
    }

    public function findById(string $sourceId): ?CfpSource
    {
        $result = $this->client->getItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['sourceId' => $sourceId]),
        ]);

        if (! isset($result['Item'])) {
            return null;
        }

        /** @var array<string, mixed> $rawItem */
        $rawItem = $result['Item'];

        return $this->toCfpSource($this->unmarshalAsArray($rawItem));
    }

    /**
     * url 一致で 1 件取得する。OfficialUrl::normalize で表記揺れを吸収して比較する。
     *
     * NOTE: 全件 Scan して normalize 比較。件数 (数〜数十) なら問題なし。GSI 不採用。
     */
    public function findByUrl(string $url): ?CfpSource
    {
        $normalizedTarget = OfficialUrl::normalize($url);

        foreach ($this->findAll() as $source) {
            if (OfficialUrl::normalize($source->url) === $normalizedTarget) {
                return $source;
            }
        }

        return null;
    }

    public function save(CfpSource $source): void
    {
        $this->client->putItem([
            'TableName' => $this->tableName,
            'Item' => $this->marshaler->marshalItem($this->toItem($source)),
        ]);
    }

    public function deleteById(string $sourceId): bool
    {
        $result = $this->client->deleteItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['sourceId' => $sourceId]),
            'ReturnValues' => 'ALL_OLD',
        ]);

        return isset($result['Attributes']);
    }

    /**
     * @return array<string, mixed>
     */
    private function toItem(CfpSource $source): array
    {
        return [
            'sourceId' => $source->sourceId,
            'name' => $source->name,
            'url' => $source->url,
            'enabled' => $source->enabled,
            'createdAt' => $source->createdAt,
            'updatedAt' => $source->updatedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toCfpSource(array $item): CfpSource
    {
        return new CfpSource(
            sourceId: $this->stringify($item, 'sourceId'),
            name: $this->stringify($item, 'name'),
            url: $this->stringify($item, 'url'),
            // 欠落 (= レガシー / 未設定) は true 扱い (= 通常巡回対象として扱う defensive default)
            enabled: $this->boolify($item, 'enabled', true),
            createdAt: $this->stringify($item, 'createdAt'),
            updatedAt: $this->stringify($item, 'updatedAt'),
        );
    }

    /**
     * Marshaler::unmarshalItem の戻り型 (array|stdClass) を array<string, mixed> に正規化。
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function unmarshalAsArray(array $item): array
    {
        $result = $this->marshaler->unmarshalItem($item);
        $array = is_array($result) ? $result : (array) $result;
        $normalized = [];
        foreach ($array as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
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
    private function boolify(array $item, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $item)) {
            return $default;
        }

        return (bool) $item[$key];
    }
}
