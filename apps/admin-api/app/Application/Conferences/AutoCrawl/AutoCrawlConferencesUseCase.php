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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 自動巡回 UseCase (Issue #152 Phase 1)。
 *
 * 動作:
 *   1. ConferenceRepository::findAll() で全件取得
 *   2. status=Published のみを対象に絞る (Draft や Closed 系は対象外)
 *   3. 各 conference の officialUrl を ExtractConferenceDraftUseCase で再抽出
 *   4. 抽出値と既存値を比較。LLM が null を返したフィールドは「変更なし」扱い
 *      (= 公式サイトトップから情報を拾えなかっただけで誤りとは限らない)。
 *      非 null 値で異なる場合のみ「差分あり」とみなす
 *   5. 差分があれば Draft Conference を新規作成 (= 既存値 + LLM が返した非 null 値で merge)。
 *      conferenceId は新規 UUID、status=Draft なので公開フロントには出ない
 *      (admin が「下書き」タブで確認 → 公開化判断する材料となる)
 *   6. 抽出失敗 (HtmlFetch / LlmExtraction) は次の URL に進む (= 部分成功)
 *   7. AutoCrawlResult として件数サマリ + 作成された Draft ID 一覧を返す
 *
 * Phase 1a (観測のみ) → Phase 1b (本実装) の差分:
 *   - diff 判定: LLM null は無視 (= 既存値を維持)
 *   - 副作用: 差分検知時に Draft 作成 (= ConferenceRepository::save)
 *
 * 比較対象から除外:
 *   - name / officialUrl: 通常変わらない (= 変わったら別 conference 扱いされるべき)
 *   - categories: LLM 出力 slug と既存 categoryId UUID の比較が複雑 (= Phase 2 で扱う)
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
        $createdDraftIds = [];

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
                // 想定外の例外も fail-soft 扱い (= 次の URL に進む)
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
                $newDraft = $this->buildDraftFromDiff($conference, $draft, $diffs);
                $this->conferenceRepository->save($newDraft);
                $createdDraftIds[] = $newDraft->conferenceId;

                Log::info('auto-crawl: draft created', [
                    'channel' => 'auto-crawl',
                    'source_conference_id' => $conference->conferenceId,
                    'new_draft_id' => $newDraft->conferenceId,
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
            createdDraftIds: $createdDraftIds,
        );
    }

    /**
     * 既存 Conference と LLM 抽出 ConferenceDraft の差分を検出する。
     *
     * Phase 1b の判定ルール (= Phase 1a 観測結果から導入):
     * - LLM が null を返したフィールドは「変更なし」扱い (= 既存値を維持)
     * - 非 null 値で異なる場合のみ「差分あり」
     *
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
            // LLM が null を返したフィールドは無視 (= 公式サイトトップに情報が無い等)
            if ($new === null) {
                continue;
            }
            if ($old !== $new) {
                $diffs[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $diffs;
    }

    /**
     * 既存 Conference をベースに、差分のあった非 null 新値で merge した Draft Conference
     * を生成する。conferenceId は新規 UUID。status=Draft なので公開フロントには出ない。
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $diffs
     */
    private function buildDraftFromDiff(
        Conference $existing,
        ConferenceDraft $draft,
        array $diffs,
    ): Conference {
        $now = Carbon::now('Asia/Tokyo')->toIso8601String();

        return new Conference(
            conferenceId: (string) Str::uuid(),
            // name / officialUrl は不変 (= 同一 conference の更新候補であることを示す)
            name: $existing->name,
            trackName: $existing->trackName,
            officialUrl: $existing->officialUrl,
            // diffs に含まれるフィールドのみ新値で上書き、それ以外は既存値を維持
            cfpUrl: array_key_exists('cfpUrl', $diffs) ? $draft->cfpUrl : $existing->cfpUrl,
            eventStartDate: array_key_exists('eventStartDate', $diffs) ? $draft->eventStartDate : $existing->eventStartDate,
            eventEndDate: array_key_exists('eventEndDate', $diffs) ? $draft->eventEndDate : $existing->eventEndDate,
            venue: array_key_exists('venue', $diffs) ? $draft->venue : $existing->venue,
            format: array_key_exists('format', $diffs) ? $draft->format : $existing->format,
            cfpStartDate: array_key_exists('cfpStartDate', $diffs) ? $draft->cfpStartDate : $existing->cfpStartDate,
            cfpEndDate: array_key_exists('cfpEndDate', $diffs) ? $draft->cfpEndDate : $existing->cfpEndDate,
            // categories は LLM 解決保留 (Phase 2 で対応)、既存維持
            categories: $existing->categories,
            description: $existing->description,
            themeColor: $existing->themeColor,
            createdAt: $now,
            updatedAt: $now,
            status: ConferenceStatus::Draft,
        );
    }
}
