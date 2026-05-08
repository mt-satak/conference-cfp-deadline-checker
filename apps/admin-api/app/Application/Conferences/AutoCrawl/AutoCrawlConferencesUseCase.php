<?php

declare(strict_types=1);

namespace App\Application\Conferences\AutoCrawl;

use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\ExtractConferenceDraftUseCase;
use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 自動巡回 UseCase (Issue #152 Phase 1a, 観測のみ)。
 *
 * 動作:
 *   1. ConferenceRepository::findAll() で全件取得
 *   2. status=Published のみを対象に絞る (Draft や Closed 系は対象外)
 *   3. 各 conference の officialUrl を ExtractConferenceDraftUseCase で再抽出
 *   4. 抽出値と既存値を比較 (cfpUrl / eventStartDate / eventEndDate / venue / format /
 *      cfpStartDate / cfpEndDate)、差分があれば 件数記録 + Log::info 出力
 *   5. 抽出失敗 (HtmlFetch / LlmExtraction) は次の URL に進む (= 部分成功)
 *   6. AutoCrawlResult として件数サマリを返す
 *
 * 副作用:
 *   - DB への書き込みは **なし** (= Phase 1a は観測のみ。Phase 1b で Draft 作成を実装)
 *   - Log への INFO / WARNING 出力のみ (CloudWatch Logs へ流れる)
 *
 * 比較対象から除外:
 *   - name / officialUrl: 通常変わらない (= 変わったら別 conference 扱いされるべき)
 *   - categories: LLM 出力 slug と既存 categoryId UUID の比較が複雑 (= Phase 1b で扱う)
 *   - description / themeColor / trackName: 表示優先度が低く頻繁に変わる
 */
class AutoCrawlConferencesUseCase
{
    public function __construct(
        private readonly ConferenceRepository $conferenceRepository,
        private readonly ExtractConferenceDraftUseCase $extractUseCase,
    ) {}

    public function execute(): AutoCrawlResult
    {
        $allConferences = $this->conferenceRepository->findAll();
        $published = array_values(array_filter(
            $allConferences,
            fn (Conference $c): bool => $c->status === ConferenceStatus::Published,
        ));

        $totalChecked = 0;
        $diffDetected = 0;
        $extractionFailed = 0;
        $failedUrls = [];

        foreach ($published as $conference) {
            $totalChecked++;
            try {
                $draft = $this->extractUseCase->execute($conference->officialUrl);
            } catch (HtmlFetchFailedException|LlmExtractionFailedException $e) {
                $extractionFailed++;
                $failedUrls[] = $conference->officialUrl;
                Log::warning('auto-crawl: extraction failed', [
                    'channel' => 'auto-crawl',
                    'conference_id' => $conference->conferenceId,
                    'official_url' => $conference->officialUrl,
                    'exception_type' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);

                continue;
            } catch (Throwable $e) {
                // 想定外の例外も観測フェーズでは fail-soft 扱い (= 次の URL に進む)
                $extractionFailed++;
                $failedUrls[] = $conference->officialUrl;
                Log::error('auto-crawl: unexpected exception', [
                    'channel' => 'auto-crawl',
                    'conference_id' => $conference->conferenceId,
                    'official_url' => $conference->officialUrl,
                    'exception_type' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);

                continue;
            }

            $diffs = $this->detectDiff($conference, $draft);
            if (! empty($diffs)) {
                $diffDetected++;
                Log::info('auto-crawl: diff detected', [
                    'channel' => 'auto-crawl',
                    'conference_id' => $conference->conferenceId,
                    'official_url' => $conference->officialUrl,
                    'diff_fields' => array_keys($diffs),
                    'diffs' => $diffs,
                ]);
            }
        }

        return new AutoCrawlResult(
            totalChecked: $totalChecked,
            diffDetected: $diffDetected,
            extractionFailed: $extractionFailed,
            failedUrls: $failedUrls,
        );
    }

    /**
     * 既存 Conference と LLM 抽出 ConferenceDraft の差分を検出する。
     * 比較対象は CfP 運用で重要なフィールドに絞る。
     *
     * @return array<string, array{old: mixed, new: mixed}> field => {old, new}
     */
    private function detectDiff(Conference $existing, ConferenceDraft $draft): array
    {
        $diffs = [];

        $compareFields = [
            'cfpUrl' => [$existing->cfpUrl, $draft->cfpUrl],
            'eventStartDate' => [$existing->eventStartDate, $draft->eventStartDate],
            'eventEndDate' => [$existing->eventEndDate, $draft->eventEndDate],
            'venue' => [$existing->venue, $draft->venue],
            'format' => [$existing->format, $draft->format],
            'cfpStartDate' => [$existing->cfpStartDate, $draft->cfpStartDate],
            'cfpEndDate' => [$existing->cfpEndDate, $draft->cfpEndDate],
        ];

        foreach ($compareFields as $field => [$old, $new]) {
            if ($old !== $new) {
                $diffs[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $diffs;
    }
}
