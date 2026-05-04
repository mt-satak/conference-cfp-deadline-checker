<?php

namespace App\Infrastructure\DynamoDb;

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryRepository;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

/**
 * CategoryRepository の DynamoDB 実装。
 *
 * - 本番: AWS DynamoDB (cfp-categories テーブル)
 * - 開発: DynamoDB Local
 *
 * Domain 層の Category Entity と DynamoDB AttributeValue 形式を Marshaler で
 * 相互変換する。findByName / findBySlug は GSI 不採用 (件数 30〜50 件、
 * Scan で十分) として全件 Scan + フィルタで実装する。
 */
class DynamoDbCategoryRepository implements CategoryRepository
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

        /** @var array<int, array<string, mixed>> $items */
        $items = $result['Items'] ?? [];

        $categories = [];
        foreach ($items as $item) {
            $categories[] = $this->toCategory($this->unmarshalAsArray($item));
        }

        return $categories;
    }

    public function findById(string $categoryId): ?Category
    {
        $result = $this->client->getItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['categoryId' => $categoryId]),
        ]);

        if (! isset($result['Item'])) {
            return null;
        }

        /** @var array<string, mixed> $rawItem */
        $rawItem = $result['Item'];

        return $this->toCategory($this->unmarshalAsArray($rawItem));
    }

    public function findByName(string $name): ?Category
    {
        return $this->findFirstByAttribute('name', $name);
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->findFirstByAttribute('slug', $slug);
    }

    public function save(Category $category): void
    {
        $this->client->putItem([
            'TableName' => $this->tableName,
            'Item' => $this->marshaler->marshalItem($this->toItem($category)),
        ]);
    }

    public function deleteById(string $categoryId): bool
    {
        $result = $this->client->deleteItem([
            'TableName' => $this->tableName,
            'Key' => $this->marshaler->marshalItem(['categoryId' => $categoryId]),
            'ReturnValues' => 'ALL_OLD',
        ]);

        return isset($result['Attributes']);
    }

    /**
     * findByName / findBySlug の共通実装。Scan + FilterExpression で 1 件取得。
     * 件数 30〜50 想定なので GSI を導入せず Scan で済ます。
     */
    private function findFirstByAttribute(string $attributeName, string $value): ?Category
    {
        $result = $this->client->scan([
            'TableName' => $this->tableName,
            'FilterExpression' => '#attr = :val',
            'ExpressionAttributeNames' => ['#attr' => $attributeName],
            'ExpressionAttributeValues' => $this->marshaler->marshalItem([':val' => $value]),
            'Limit' => 1,
        ]);

        /** @var array<int, array<string, mixed>> $items */
        $items = $result['Items'] ?? [];
        if ($items === []) {
            return null;
        }

        return $this->toCategory($this->unmarshalAsArray($items[0]));
    }

    /**
     * Category Entity → DynamoDB に書き込む配列。
     *
     * @return array<string, mixed>
     */
    private function toItem(Category $category): array
    {
        $item = [
            'categoryId' => $category->categoryId,
            'name' => $category->name,
            'slug' => $category->slug,
            'displayOrder' => $category->displayOrder,
            'createdAt' => $category->createdAt,
            'updatedAt' => $category->updatedAt,
        ];

        // axis は optional
        if ($category->axis !== null) {
            $item['axis'] = $category->axis->value;
        }

        return $item;
    }

    /**
     * unmarshalAsArray の結果 (plain PHP 配列) → Category Entity。
     *
     * @param  array<string, mixed>  $item
     */
    private function toCategory(array $item): Category
    {
        $axisValue = $item['axis'] ?? null;
        $axis = is_string($axisValue) ? CategoryAxis::tryFrom($axisValue) : null;

        return new Category(
            categoryId: $this->stringify($item, 'categoryId'),
            name: $this->stringify($item, 'name'),
            slug: $this->stringify($item, 'slug'),
            displayOrder: $this->intify($item, 'displayOrder'),
            axis: $axis,
            createdAt: $this->stringify($item, 'createdAt'),
            updatedAt: $this->stringify($item, 'updatedAt'),
        );
    }

    /**
     * Marshaler::unmarshalItem の戻り型 (array|stdClass) を array<string, mixed>
     * に正規化する (DynamoDbConferenceRepository と同パターン)。
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
    private function intify(array $item, string $key): int
    {
        $value = $item[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
