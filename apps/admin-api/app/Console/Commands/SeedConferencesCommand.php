<?php

namespace App\Console\Commands;

use App\Domain\Categories\CategoryRepository;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * カンファレンスの一括シーディングコマンド (Issue #40 Phase 1)。
 *
 * 想定運用:
 * - 本番初期化: 1 度実行して `data/seeds/conferences.json` の種データを投入
 * - 開発環境: ローカル DynamoDB に同じシードを入れる
 * - 再実行: idempotent (ConferenceRepository::save() は conferenceId ベースの upsert)
 *
 * 使い方:
 *   php artisan conferences:seed                            # デフォルトシード投入
 *   php artisan conferences:seed --source=path/to/file.json # 別ファイル
 *   php artisan conferences:seed --dry-run                  # 投入予定のみ表示
 *
 * Phase 0.5 (Issue #41) 連携:
 * - JSON 内の各エントリは status='draft' / 'published' を指定
 * - draft では cfpUrl / eventStartDate / eventEndDate / venue / format / cfpEndDate / categorySlugs を省略可能
 * - published では従来通り全項目必須 (本コマンドが事前検証して欠落で FAILURE)
 *
 * カテゴリは categorySlugs (slug 配列) で指定し、本コマンド内で
 * CategoryRepository::findBySlug() を使って UUID 配列に解決する (可読性優先)。
 * 未知 slug は FAILURE。
 */
class SeedConferencesCommand extends Command
{
    protected $signature = 'conferences:seed
                            {--source= : 読み込む JSON ファイルパス。未指定時は data/seeds/conferences.json}
                            {--dry-run : 投入せず投入予定のみ表示}';

    protected $description = 'data/seeds/conferences.json から ConferenceRepository に upsert する';

    public function handle(
        ConferenceRepository $conferenceRepository,
        CategoryRepository $categoryRepository,
    ): int {
        $sourceOption = $this->option('source');
        $path = is_string($sourceOption) && $sourceOption !== ''
            ? $sourceOption
            : base_path('../../data/seeds/conferences.json');

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
                $conference = $this->toConference($row, $now, $categoryRepository);

                if ($dryRun) {
                    $this->line(sprintf(
                        '  would seed: [%s] %-40s slug=%-20s cfpEnd=%s',
                        $conference->status->value,
                        $conference->name,
                        implode(',', $this->extractSlugs($row)),
                        $conference->cfpEndDate ?? '(none)',
                    ));
                } else {
                    $conferenceRepository->save($conference);
                }
                $count++;
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $verb = $dryRun ? 'would seed' : 'seeded';
        $this->info("{$count} conferences {$verb} from {$path}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSeedRows(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! isset($decoded['conferences']) || ! is_array($decoded['conferences'])) {
            throw new RuntimeException('Seed JSON must have a top-level "conferences" array');
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $decoded['conferences'];

        return $rows;
    }

    /**
     * 1 行の seed エントリを Conference Entity に変換する。
     *
     * @param  array<string, mixed>  $row
     */
    private function toConference(array $row, string $now, CategoryRepository $categoryRepository): Conference
    {
        $statusValue = $row['status'] ?? 'published';
        if (! is_string($statusValue)) {
            throw new RuntimeException('Field status must be a string');
        }
        $status = ConferenceStatus::tryFrom($statusValue);
        if ($status === null) {
            throw new RuntimeException("Unknown status value: {$statusValue}");
        }

        $conferenceId = $this->stringField($row, 'conferenceId');
        $name = $this->stringField($row, 'name');
        $officialUrl = $this->stringField($row, 'officialUrl');

        $cfpUrl = $this->nullableStringField($row, 'cfpUrl');
        $eventStartDate = $this->nullableStringField($row, 'eventStartDate');
        $eventEndDate = $this->nullableStringField($row, 'eventEndDate');
        $venue = $this->nullableStringField($row, 'venue');
        $cfpEndDate = $this->nullableStringField($row, 'cfpEndDate');
        $cfpStartDate = $this->nullableStringField($row, 'cfpStartDate');
        $trackName = $this->nullableStringField($row, 'trackName');
        $description = $this->nullableStringField($row, 'description');
        $themeColor = $this->nullableStringField($row, 'themeColor');

        $formatValue = $this->nullableStringField($row, 'format');
        $format = $formatValue !== null ? ConferenceFormat::tryFrom($formatValue) : null;
        if ($formatValue !== null && $format === null) {
            throw new RuntimeException("Unknown format value: {$formatValue}");
        }

        $categories = $this->resolveCategorySlugs($this->extractSlugs($row), $categoryRepository);

        // Published 確定状態の事前検証 (= 投入後にバリデーション違反になる種データを防ぐ)
        if ($status === ConferenceStatus::Published) {
            $this->assertPublishedComplete($name, [
                'cfpUrl' => $cfpUrl,
                'eventStartDate' => $eventStartDate,
                'eventEndDate' => $eventEndDate,
                'venue' => $venue,
                'format' => $format,
                'cfpEndDate' => $cfpEndDate,
                'categories' => $categories,
            ]);
        }

        return new Conference(
            conferenceId: $conferenceId,
            name: $name,
            trackName: $trackName,
            officialUrl: $officialUrl,
            cfpUrl: $cfpUrl,
            eventStartDate: $eventStartDate,
            eventEndDate: $eventEndDate,
            venue: $venue,
            format: $format,
            cfpStartDate: $cfpStartDate,
            cfpEndDate: $cfpEndDate,
            categories: $categories,
            description: $description,
            themeColor: $themeColor,
            createdAt: $now,
            updatedAt: $now,
            status: $status,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function extractSlugs(array $row): array
    {
        $raw = $row['categorySlugs'] ?? [];
        if (! is_array($raw)) {
            throw new RuntimeException('Field categorySlugs must be an array of strings');
        }
        $slugs = [];
        foreach ($raw as $value) {
            if (! is_string($value) || $value === '') {
                throw new RuntimeException('Field categorySlugs must contain non-empty strings');
            }
            $slugs[] = $value;
        }

        return $slugs;
    }

    /**
     * categorySlugs を CategoryRepository で UUID 配列に解決する。
     *
     * @param  array<int, string>  $slugs
     * @return array<int, string>
     */
    private function resolveCategorySlugs(array $slugs, CategoryRepository $categoryRepository): array
    {
        $ids = [];
        foreach ($slugs as $slug) {
            $category = $categoryRepository->findBySlug($slug);
            if ($category === null) {
                throw new RuntimeException("Unknown category slug: {$slug}");
            }
            $ids[] = $category->categoryId;
        }

        return $ids;
    }

    /**
     * Published 状態が要求する非 null 項目を事前検証 (Conference 構築前)。
     *
     * @param  array{
     *     cfpUrl: string|null,
     *     eventStartDate: string|null,
     *     eventEndDate: string|null,
     *     venue: string|null,
     *     format: ConferenceFormat|null,
     *     cfpEndDate: string|null,
     *     categories: array<int, string>,
     * }  $fields
     */
    private function assertPublishedComplete(string $name, array $fields): void
    {
        $missing = [];
        foreach ($fields as $key => $value) {
            if ($key === 'categories') {
                if ($value === []) {
                    $missing[] = $key;
                }

                continue;
            }
            if ($value === null) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(
                "Published row \"{$name}\" is missing required fields: ".implode(', ', $missing),
            );
        }
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
    private function nullableStringField(array $row, string $key): ?string
    {
        if (! array_key_exists($key, $row)) {
            return null;
        }
        $value = $row[$key];
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new RuntimeException("Field {$key} must be a string when present");
        }
        if ($value === '') {
            return null;
        }

        return $value;
    }
}
