<?php

declare(strict_types=1);

namespace App\Application\Conferences\Discovery;

use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\ExtractConferenceDraftUseCase;
use App\Application\Conferences\Extraction\HtmlFetcher;
use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Domain\CfpSources\CfpSource;
use App\Domain\CfpSources\CfpSourceRepository;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\OfficialUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 週次自動 CfP 発見 UseCase (Issue #200 PR-3)。
 *
 * 動作:
 *   1. CfpSourceRepository::findAll() → enabled=true の source のみ抽出
 *   2. ConferenceRepository::findAll() で既存 officialUrl 集合を構築 (= 正規化後)
 *   3. 各 source について HtmlFetcher で HTML 取得 → ListConferenceUrlsExtractor で URL 列挙
 *      失敗時は fail-soft で次の source に進む
 *   4. 抽出 URL を正規化後比較で既存と dedup、新規 URL のみ candidate に追加
 *      (= source 間の重複も排除)
 *   5. dryRun=true: 候補件数だけカウントして save しない
 *      dryRun=false: 各新規 URL に対して ExtractConferenceDraftUseCase で詳細抽出 →
 *                    Conference Entity (status=Draft, discoveryMetadata 付き) を save
 *
 * 設計判断:
 *   - dryRun は安全装置 (= 初回実行で挙動確認できる)
 *   - 既存 ConferenceRepository::findAll() で dedup する = 件数想定 (50〜200) では O(N) で十分
 *   - discoveryMetadata に sourceId を入れることで「どの source から発見されたか」を追跡可能
 *     (= admin がノイズの多い source を判別して disable できる)
 *
 * 観測ログ:
 *   - source 単位の成功 / 失敗
 *   - 抽出 URL 件数
 *   - Draft 作成件数
 *   CloudWatch に流れて週次レビューの素材になる。
 */
class DiscoverConferencesUseCase
{
    public function __construct(
        private readonly CfpSourceRepository $sourceRepository,
        private readonly ConferenceRepository $conferenceRepository,
        private readonly HtmlFetcher $htmlFetcher,
        private readonly ListConferenceUrlsExtractor $listExtractor,
        private readonly ExtractConferenceDraftUseCase $extractDraftUseCase,
    ) {}

    public function execute(bool $dryRun = true): DiscoverConferencesResult
    {
        $sources = $this->sourceRepository->findAll();
        $enabledSources = array_values(array_filter(
            $sources,
            static fn (CfpSource $s): bool => $s->enabled,
        ));

        // 既存 Conference の officialUrl 集合 (正規化後)
        $existingUrls = [];
        foreach ($this->conferenceRepository->findAll() as $c) {
            $existingUrls[OfficialUrl::normalize($c->officialUrl)] = true;
        }

        $totalCandidateUrls = 0;
        $sourcesFailed = 0;
        $failedSourceUrls = [];
        $createdDraftIds = [];
        $extractionFailed = 0;
        // 「今回の run 内で見たことがある正規化後 URL」を保持 (= source 間 dedup)
        $seenInThisRun = [];

        foreach ($enabledSources as $source) {
            // ── HTML 取得 + URL 列挙 ──
            try {
                $html = $this->htmlFetcher->fetch($source->url);
                $extractedUrls = $this->listExtractor->extract($source->url, $html);
            } catch (HtmlFetchFailedException|LlmExtractionFailedException $e) {
                $sourcesFailed++;
                $failedSourceUrls[] = $source->url;
                Log::warning('discover: source crawl failed', [
                    'channel' => 'discover',
                    'source_id' => $source->sourceId,
                    'source_url' => $source->url,
                    'exception_type' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);

                continue;
            } catch (Throwable $e) {
                $sourcesFailed++;
                $failedSourceUrls[] = $source->url;
                Log::error('discover: source unexpected exception', [
                    'channel' => 'discover',
                    'source_id' => $source->sourceId,
                    'source_url' => $source->url,
                    'exception_type' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);

                continue;
            }

            $totalCandidateUrls += count($extractedUrls);

            // ── 新規 URL (= 既存 / run 内重複でない) を抽出 ──
            $newUrlsForThisSource = [];
            foreach ($extractedUrls as $candidateUrl) {
                $normalized = OfficialUrl::normalize($candidateUrl);
                if (isset($existingUrls[$normalized])) {
                    continue;
                }
                if (isset($seenInThisRun[$normalized])) {
                    continue;
                }
                $seenInThisRun[$normalized] = true;
                $newUrlsForThisSource[] = $candidateUrl;
            }

            if ($dryRun || $newUrlsForThisSource === []) {
                continue;
            }

            // ── 各新規 URL の詳細抽出 + Draft 保存 ──
            foreach ($newUrlsForThisSource as $newUrl) {
                try {
                    $draft = $this->extractDraftUseCase->execute($newUrl);
                } catch (HtmlFetchFailedException|LlmExtractionFailedException $e) {
                    $extractionFailed++;
                    Log::warning('discover: draft extraction failed', [
                        'channel' => 'discover',
                        'source_id' => $source->sourceId,
                        'candidate_url' => $newUrl,
                        'exception_type' => $e::class,
                        'exception_message' => $e->getMessage(),
                    ]);

                    continue;
                } catch (Throwable $e) {
                    $extractionFailed++;
                    Log::error('discover: draft extraction unexpected exception', [
                        'channel' => 'discover',
                        'source_id' => $source->sourceId,
                        'candidate_url' => $newUrl,
                        'exception_type' => $e::class,
                        'exception_message' => $e->getMessage(),
                    ]);

                    continue;
                }

                $conference = $this->buildDraftConference($draft, $source->sourceId);
                $this->conferenceRepository->save($conference);
                $createdDraftIds[] = $conference->conferenceId;

                Log::info('discover: draft created', [
                    'channel' => 'discover',
                    'source_id' => $source->sourceId,
                    'conference_id' => $conference->conferenceId,
                    'official_url' => $conference->officialUrl,
                ]);
            }
        }

        return new DiscoverConferencesResult(
            dryRun: $dryRun,
            totalSources: count($enabledSources),
            sourcesFailed: $sourcesFailed,
            totalCandidateUrls: $totalCandidateUrls,
            newCandidateUrls: count($seenInThisRun),
            draftsCreated: count($createdDraftIds),
            extractionFailed: $extractionFailed,
            failedSourceUrls: $failedSourceUrls,
            createdDraftIds: $createdDraftIds,
        );
    }

    /**
     * ConferenceDraft + sourceId から、Draft で save 可能な Conference Entity を構築する。
     *
     * - conferenceId: 新規 UUID
     * - createdAt / updatedAt: 現在時刻 (JST)
     * - discoveryMetadata: {discoveredAt = 現在時刻, sourceId}
     * - status: Draft (= 人間レビュー前提)
     * - officialUrl: draft.officialUrl があればそれを優先、無ければ sourceUrl (= 安全側)
     *
     * Draft の必須項目欠落 (cfpUrl 等が LLM で抽出できなかった場合) は null のまま保持。
     * Published 昇格は別 UI で行う (= ConferenceController::publish が必須項目を検証)。
     */
    private function buildDraftConference(ConferenceDraft $draft, string $sourceId): Conference
    {
        $now = Carbon::now('Asia/Tokyo')->toIso8601String();
        $officialUrl = $draft->officialUrl ?? $draft->sourceUrl;

        return new Conference(
            conferenceId: (string) Str::uuid(),
            name: $draft->name ?? '(タイトル未取得)',
            trackName: $draft->trackName,
            officialUrl: $officialUrl,
            cfpUrl: $draft->cfpUrl,
            eventStartDate: $draft->eventStartDate,
            eventEndDate: $draft->eventEndDate,
            venue: $draft->venue,
            format: $draft->format,
            cfpStartDate: $draft->cfpStartDate,
            cfpEndDate: $draft->cfpEndDate,
            // categorySlugs → categoryId 解決は Phase 2 (Issue #95 後追い)。
            // 自動発見では categories=[] で投入し、人間レビュー時に admin UI で選んでもらう。
            categories: [],
            description: $draft->description,
            themeColor: $draft->themeColor,
            createdAt: $now,
            updatedAt: $now,
            status: ConferenceStatus::Draft,
            discoveryMetadata: [
                'discoveredAt' => $now,
                'sourceId' => $sourceId,
            ],
        );
    }
}
