<?php

namespace App\Console\Commands;

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * カテゴリの一括シーディングコマンド。
 *
 * 想定運用:
 * - 本番初期化: 1 度実行して `data/seeds/categories.json` の 34 件を投入
 * - 開発環境: ローカル DynamoDB に同じシードを入れる (既存の make db-init と同等の効果)
 * - 再実行: idempotent (CategoryRepository::save() は categoryId ベースの upsert)
 *
 * axis の意味と displayOrder の番号帯運用については Issue #38 のコメント参照。
 *
 * 使い方:
 *   php artisan categories:seed                            # デフォルトシード投入
 *   php artisan categories:seed --source=path/to/file.json # 別ファイル
 *   php artisan categories:seed --dry-run                  # 投入予定のみ表示
 */
class SeedCategoriesCommand extends Command
{
    protected $signature = 'categories:seed
                            {--source= : 読み込む JSON ファイルパス。未指定時は data/seeds/categories.json}
                            {--dry-run : 投入せず投入予定のみ表示}';

    protected $description = 'data/seeds/categories.json から CategoryRepository に upsert する';

    public function handle(CategoryRepository $repository): int
    {
        $sourceOption = $this->option('source');
        $path = is_string($sourceOption) && $sourceOption !== ''
            ? $sourceOption
            : base_path('../../data/seeds/categories.json');

        if (! is_readable($path)) {
            $this->error("Seed file not found or not readable: {$path}");

            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("Failed to read seed file: {$path}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now('Asia/Tokyo')->toIso8601String();
        $count = 0;

        try {
            $rows = $this->parseSeedRows($raw);

            foreach ($rows as $row) {
                $category = $this->toCategory($row, $now);

                if ($dryRun) {
                    $this->line(sprintf(
                        '  would seed: %-40s slug=%-20s axis=%s displayOrder=%d',
                        $category->name,
                        $category->slug,
                        $category->axis !== null ? $category->axis->value : '(none)',
                        $category->displayOrder,
                    ));
                } else {
                    $repository->save($category);
                }
                $count++;
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $verb = $dryRun ? 'would seed' : 'seeded';
        $this->info("{$count} categories {$verb} from {$path}");

        return self::SUCCESS;
    }

    /**
     * JSON 文字列を seed 行配列にパースする。
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseSeedRows(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['categories']) || ! is_array($decoded['categories'])) {
            throw new RuntimeException('Seed JSON must have a top-level "categories" array');
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $decoded['categories'];

        return $rows;
    }

    /**
     * 1 行の seed エントリを Category Entity に変換する。
     *
     * data/seeds/categories.json には `exampleConferences` 等の補助フィールドがあるが
     * Category Entity が持たないので無視する。createdAt / updatedAt は実行時刻で揃える。
     *
     * @param  array<string, mixed>  $row
     */
    private function toCategory(array $row, string $now): Category
    {
        $axisValue = $row['axis'] ?? null;
        $axis = is_string($axisValue) ? CategoryAxis::tryFrom($axisValue) : null;

        $categoryId = $this->stringField($row, 'categoryId');
        $name = $this->stringField($row, 'name');
        $slug = $this->stringField($row, 'slug');
        $displayOrder = $this->intField($row, 'displayOrder');

        return new Category(
            categoryId: $categoryId,
            name: $name,
            slug: $slug,
            displayOrder: $displayOrder,
            axis: $axis,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Missing or empty required field: {$key}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (! is_int($value)) {
            throw new RuntimeException("Field {$key} must be an integer");
        }

        return $value;
    }
}
