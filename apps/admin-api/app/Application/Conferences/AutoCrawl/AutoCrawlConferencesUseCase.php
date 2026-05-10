<?php

declare(strict_types=1);

namespace App\Application\Conferences\AutoCrawl;

use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\ExtractConferenceDraftUseCase;
use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 自動巡回 UseCase (Issue #152 Phase 1, Issue #188 で挙動変更)。
 *
 * 動作:
 *   1. ConferenceRepository::findAll() で全件取得
 *   2. status=Published のみを対象に絞る (Draft や Archived 系は対象外)
 *   3. Issue #188: `pendingChanges !== null` の Conference は **スキップ** (= 人間レビュー中)
 *   4. 各 conference の officialUrl を ExtractConferenceDraftUseCase で再抽出
 *   5. 抽出値と既存値を比較。LLM が null を返したフィールドは「変更なし」扱い
 *      (= 公式サイトトップから情報を拾えなかっただけで誤りとは限らない)。
 *      非 null 値で異なる場合のみ「差分あり」とみなす
 *   6. Issue #188: 差分があれば Published 行の `pendingChanges` フィールドに保存
 *      (Draft 別行は作らない)。actual フィールド (cfpUrl 等) は不変 (= 人間ゲート保証)
 *   7. 抽出失敗 (HtmlFetch / LlmExtraction) は次の URL に進む (= 部分成功)
 *   8. AutoCrawlResult として件数サマリ + 更新 Conference ID 一覧を返す
 *
 * Issue #188 設計判断:
 *   - skip-if-pending: pending 待ちの Conference は次回 AutoCrawl でも再検知しない。
 *     Apply/Reject (PR-3 で実装) で pendingChanges がクリアされるまで、レビュー中の
 *     diff が新しい diff で書き換わる事態を構造的に防止する。
 *   - actual 不変: AutoCrawl は actual フィールドを直接書き換えない。書き換え対象は
 *     pendingChanges のみで、人間が Apply UseCase を実行して初めて actual に反映される。
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
        $skippedHasPending = 0;
        $failedUrls = [];
        $pendingChangesUpdatedIds = [];

        foreach ($published as $conference) {
            $totalChecked++;

            // Issue #188: pending 待ちの Conference は再抽出しない (= 人間ゲート保証)。
            // null と [] (= レビュー解消直後の状態) は「保留差分なし」として通常巡回し、
            // 1 件以上のフィールドが入っている時のみ skip する (= empty 判定)。
            if (! empty($conference->pendingChanges)) {
                $skippedHasPending++;
                Log::info('auto-crawl: skipped (has pending changes)', [
                    'channel' => 'auto-crawl',
                    'conference_id' => $conference->conferenceId,
                    'official_url' => $conference->officialUrl,
                    'pending_field_count' => count($conference->pendingChanges),
                ]);

                continue;
            }

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

                $updatedConference = $this->withPendingChanges($conference, $diffs);
                $this->conferenceRepository->save($updatedConference);
                $pendingChangesUpdatedIds[] = $updatedConference->conferenceId;

                Log::info('auto-crawl: pending changes updated', [
                    'channel' => 'auto-crawl',
                    'conference_id' => $conference->conferenceId,
                    'official_url' => $conference->officialUrl,
                    'pending_fields' => array_keys($diffs),
                    'pending_changes' => $diffs,
                ]);
            }
        }

        return new AutoCrawlResult(
            totalChecked: $totalChecked,
            diffDetected: $diffDetected,
            extractionFailed: $extractionFailed,
            failedUrls: $failedUrls,
            pendingChangesUpdatedIds: $pendingChangesUpdatedIds,
            skippedHasPending: $skippedHasPending,
        );
    }

    /**
     * 既存 Conference と LLM 抽出 ConferenceDraft の差分を検出する。
     *
     * 判定ルール:
     * - LLM が null を返したフィールドは「変更なし」扱い (= 既存値を維持)
     * - 非 null 値で異なる場合のみ「差分あり」
     *
     * 比較対象は CfP 運用で重要なフィールドに絞る。
     *
     * format フィールドは ConferenceFormat enum で渡されるが、pendingChanges として
     * DynamoDB に永続化する際に Marshaler が enum を扱えないため、ここで `.value`
     * 文字列に正規化する。比較自体は enum で行う (= 等価判定の意味は変わらない)。
     *
     * @return array<string, array{old: string|null, new: string|null}> field => {old, new}
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
                $diffs[$field] = [
                    'old' => $old instanceof ConferenceFormat ? $old->value : $old,
                    'new' => $new instanceof ConferenceFormat ? $new->value : $new,
                ];
            }
        }

        return $diffs;
    }

    /**
     * Issue #188: 既存 Published Conference に pendingChanges を載せた新インスタンスを返す。
     *
     * - actual フィールド (cfpUrl 等) は変更しない (= 人間が Apply するまで不変)
     * - pendingChanges に diff (field => {old, new}) を保存
     * - updatedAt は現在時刻に更新 (= 「いつ pending を検知したか」を簡易追跡)
     * - status は Published のまま (= 別行の Draft は作らない)
     * - conferenceId / createdAt / その他の actual は維持
     *
     * format は ConferenceFormat enum で渡されるため diffs にそのまま入る。
     * pendingChanges として保存すると DynamoDB Marshaler が non-scalar を扱えない可能性が
     * あるため、Repository 側で string 値に変換する責務を持たせる (= toItem で対応)。
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $diffs
     */
    private function withPendingChanges(Conference $existing, array $diffs): Conference
    {
        $now = Carbon::now('Asia/Tokyo')->toIso8601String();

        return new Conference(
            conferenceId: $existing->conferenceId,
            name: $existing->name,
            trackName: $existing->trackName,
            officialUrl: $existing->officialUrl,
            cfpUrl: $existing->cfpUrl,
            eventStartDate: $existing->eventStartDate,
            eventEndDate: $existing->eventEndDate,
            venue: $existing->venue,
            format: $existing->format,
            cfpStartDate: $existing->cfpStartDate,
            cfpEndDate: $existing->cfpEndDate,
            categories: $existing->categories,
            description: $existing->description,
            themeColor: $existing->themeColor,
            createdAt: $existing->createdAt,
            updatedAt: $now,
            status: $existing->status,
            pendingChanges: $diffs,
        );
    }
}
