<?php

declare(strict_types=1);

use App\Application\Conferences\Discovery\DiscoverConferencesResult;
use App\Application\Conferences\Discovery\DiscoverConferencesUseCase;
use App\Application\Conferences\Discovery\ListConferenceUrlsExtractor;
use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\ExtractConferenceDraftUseCase;
use App\Application\Conferences\Extraction\HtmlFetcher;
use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Domain\CfpSources\CfpSource;
use App\Domain\CfpSources\CfpSourceRepository;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;

/**
 * DiscoverConferencesUseCase の単体テスト (Issue #200 PR-3)。
 *
 * 動作:
 *   1. CfpSourceRepository::findAll() で全 source を取得し enabled=true のみ巡回
 *   2. 各 source URL を HtmlFetcher で取得 → ListConferenceUrlsExtractor で個別 URL 列挙
 *   3. 既存 Conference の officialUrl 集合と正規化後比較で新規 URL のみ抽出
 *   4. dryRun=true: 候補件数だけカウントして save 呼ばない
 *      dryRun=false: 各新規 URL に対して ExtractConferenceDraftUseCase で詳細抽出 →
 *                   Conference Entity (status=Draft, discoveryMetadata={discoveredAt, sourceId}) を save
 *   5. DiscoverConferencesResult として件数 + ID 一覧を返す
 *
 * 例外:
 *   source 単位の HTML 取得失敗 / LLM URL 列挙失敗は fail-soft (= 次の source に進む)
 *   URL 単位の詳細抽出失敗も fail-soft (= 次の URL に進む)
 */
function makeDiscoverSource(string $id, string $url, bool $enabled = true): CfpSource
{
    return new CfpSource(
        sourceId: $id,
        name: "Source {$id}",
        url: $url,
        enabled: $enabled,
        createdAt: '2026-05-15T08:00:00+09:00',
        updatedAt: '2026-05-15T08:00:00+09:00',
    );
}

function makeDiscoverExistingPublished(string $id, string $officialUrl): Conference
{
    return new Conference(
        conferenceId: $id,
        name: "Existing {$id}",
        trackName: null,
        officialUrl: $officialUrl,
        cfpUrl: null,
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: null,
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: '2026-07-15',
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2026-04-01T10:00:00+09:00',
        updatedAt: '2026-04-01T10:00:00+09:00',
        status: ConferenceStatus::Published,
    );
}

function makeDiscoverDraftFromUrl(string $url, ?string $name = null): ConferenceDraft
{
    return new ConferenceDraft(
        sourceUrl: $url,
        name: $name ?? 'Auto Discovered',
        officialUrl: $url,
        cfpEndDate: '2026-09-30',
    );
}

describe('DiscoverConferencesUseCase', function () {
    it('dry-run: 各 source の HTML を取り URL 列挙 → 候補件数だけカウントし save 呼ばない', function () {
        // Given: source 1 件、HTML から URL 2 件抽出、既存重複なし
        $source = makeDiscoverSource('s-1', 'https://fortee.jp/events');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$source]);

        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);
        $confRepo->shouldNotReceive('save');

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')
            ->once()
            ->with('https://fortee.jp/events')
            ->andReturn('<html>...</html>');

        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')
            ->once()
            ->andReturn(['https://a.example.com/2026', 'https://b.example.com/2026']);

        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extractDraft->shouldNotReceive('execute');

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: true);

        // Then
        expect($result)->toBeInstanceOf(DiscoverConferencesResult::class);
        expect($result->dryRun)->toBeTrue();
        expect($result->totalSources)->toBe(1);
        expect($result->totalCandidateUrls)->toBe(2);
        expect($result->newCandidateUrls)->toBe(2);
        expect($result->draftsCreated)->toBe(0);
        expect($result->createdDraftIds)->toBe([]);
    });

    it('apply: 新規 URL ごとに ExtractConferenceDraftUseCase で詳細抽出 → Draft で save する', function () {
        // Given
        $source = makeDiscoverSource('s-1', 'https://fortee.jp/events');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$source]);

        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')->once()->andReturn('<html>...</html>');

        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')
            ->once()
            ->andReturn(['https://a.example.com/2026', 'https://b.example.com/2026']);

        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extractDraft->shouldReceive('execute')
            ->once()->with('https://a.example.com/2026')
            ->andReturn(makeDiscoverDraftFromUrl('https://a.example.com/2026', 'Conf A'));
        $extractDraft->shouldReceive('execute')
            ->once()->with('https://b.example.com/2026')
            ->andReturn(makeDiscoverDraftFromUrl('https://b.example.com/2026', 'Conf B'));

        // 2 件 save される
        $saved = [];
        $confRepo->shouldReceive('save')
            ->twice()
            ->with(Mockery::on(function (Conference $c) use (&$saved) {
                $saved[] = $c;

                return true;
            }));

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then
        expect($result->dryRun)->toBeFalse();
        expect($result->draftsCreated)->toBe(2);
        expect($result->createdDraftIds)->toHaveCount(2);

        // Conference は status=Draft / discoveryMetadata 付き
        expect($saved)->toHaveCount(2);
        /** @var Conference $first */
        $first = $saved[0];
        expect($first->status)->toBe(ConferenceStatus::Draft);
        expect($first->discoveryMetadata)->not->toBeNull();
        expect($first->discoveryMetadata['sourceId'] ?? null)->toBe('s-1');
        expect($first->discoveryMetadata['discoveredAt'] ?? '')->not->toBe('');
    });

    it('既存 officialUrl と一致する URL は新規候補から除外 (= 正規化後比較)', function () {
        // Given: 既存 Published が a.example.com で持っているので、source 由来の同 URL はスキップ
        $source = makeDiscoverSource('s-1', 'https://fortee.jp/events');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$source]);

        $existing = makeDiscoverExistingPublished('pub-1', 'https://a.example.com/2026');
        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([$existing]);

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')->once()->andReturn('<html>...</html>');

        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')
            ->once()
            ->andReturn([
                'http://www.a.example.com/2026/',  // 表記揺れ重複 (= 同一視されるべき)
                'https://b.example.com/2026',  // 新規
            ]);

        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extractDraft->shouldReceive('execute')
            ->once()->with('https://b.example.com/2026')
            ->andReturn(makeDiscoverDraftFromUrl('https://b.example.com/2026'));
        $confRepo->shouldReceive('save')->once();

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then
        expect($result->totalCandidateUrls)->toBe(2);
        expect($result->newCandidateUrls)->toBe(1);  // a.example.com は表記揺れで重複扱い
        expect($result->draftsCreated)->toBe(1);
    });

    it('enabled=false の source はスキップ (= 巡回対象外)', function () {
        // Given
        $enabled = makeDiscoverSource('s-1', 'https://fortee.jp/events', true);
        $disabled = makeDiscoverSource('s-2', 'https://connpass.com/explore', false);
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$enabled, $disabled]);

        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        // enabled 側だけ fetch される
        $htmlFetcher->shouldReceive('fetch')->once()->with('https://fortee.jp/events')->andReturn('<html>...</html>');
        $htmlFetcher->shouldNotReceive('fetch')->with('https://connpass.com/explore');

        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')->once()->andReturn([]);

        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: true);

        // Then: totalSources=1 (= enabled 件数)
        expect($result->totalSources)->toBe(1);
    });

    it('HtmlFetchFailedException は fail-soft で sourcesFailed カウントして次に進む', function () {
        // Given: source 2 件、1 件目で HTML 取得失敗
        $s1 = makeDiscoverSource('s-1', 'https://a.example.com/');
        $s2 = makeDiscoverSource('s-2', 'https://b.example.com/');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$s1, $s2]);

        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')
            ->once()->with('https://a.example.com/')
            ->andThrow(new HtmlFetchFailedException('fetch failed'));
        $htmlFetcher->shouldReceive('fetch')
            ->once()->with('https://b.example.com/')
            ->andReturn('<html>...</html>');

        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')->once()->andReturn([]);

        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: true);

        // Then
        expect($result->sourcesFailed)->toBe(1);
        expect($result->failedSourceUrls)->toBe(['https://a.example.com/']);
        expect($result->totalSources)->toBe(2);
    });

    it('LlmExtractionFailedException も fail-soft 扱い (= sourcesFailed)', function () {
        // Given
        $s1 = makeDiscoverSource('s-1', 'https://a.example.com/');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$s1]);

        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')->once()->andReturn('<html>...</html>');

        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')->once()->andThrow(LlmExtractionFailedException::modelError('https://a.example.com/', 'fake'));

        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: true);

        // Then
        expect($result->sourcesFailed)->toBe(1);
    });

    it('新規 URL の詳細抽出失敗は extractionFailed カウントして次の URL に進む', function () {
        // Given
        $s1 = makeDiscoverSource('s-1', 'https://fortee.jp/events');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$s1]);

        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')->once()->andReturn('<html>...</html>');

        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')
            ->once()
            ->andReturn(['https://fail.example.com/', 'https://ok.example.com/']);

        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);
        // 1 件目失敗、2 件目成功
        $extractDraft->shouldReceive('execute')
            ->once()->with('https://fail.example.com/')
            ->andThrow(new HtmlFetchFailedException('fail'));
        $extractDraft->shouldReceive('execute')
            ->once()->with('https://ok.example.com/')
            ->andReturn(makeDiscoverDraftFromUrl('https://ok.example.com/'));

        $confRepo->shouldReceive('save')->once();

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then
        expect($result->newCandidateUrls)->toBe(2);
        expect($result->draftsCreated)->toBe(1);
        expect($result->extractionFailed)->toBe(1);
    });

    it('複数 source 間で重複する URL は 1 回だけ処理 (= dedup)', function () {
        // Given: 2 つの source が同じ URL を返す
        $s1 = makeDiscoverSource('s-1', 'https://a.example.com/');
        $s2 = makeDiscoverSource('s-2', 'https://b.example.com/');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$s1, $s2]);

        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')->twice()->andReturn('<html>...</html>');

        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')
            ->twice()
            ->andReturn(['https://overlap.example.com/']);  // 両 source が同 URL を返す

        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);
        // 1 回だけ呼ばれる
        $extractDraft->shouldReceive('execute')->once()->andReturn(makeDiscoverDraftFromUrl('https://overlap.example.com/'));
        $confRepo->shouldReceive('save')->once();

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then: 重複 URL は 1 回しか処理されない
        expect($result->totalCandidateUrls)->toBe(2);  // 集計上は raw 件数
        expect($result->newCandidateUrls)->toBe(1);  // dedup 後の新規
        expect($result->draftsCreated)->toBe(1);
    });

    it('source 0 件 (= 全部 disabled or 空) でも安全に空結果を返す', function () {
        // Given
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([]);

        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: true);

        // Then
        expect($result->totalSources)->toBe(0);
        expect($result->totalCandidateUrls)->toBe(0);
        expect($result->newCandidateUrls)->toBe(0);
        expect($result->createdDraftIds)->toBe([]);
    });
});

describe('DiscoverConferencesUseCase 公式リンク条件付き follow (Issue #224)', function () {
    it('1 ページ目に欠損があり officialUrl が別ページなら追加抽出してマージする', function () {
        // Given: source の個別ページ (fortee) は薄く、公式サイトに実データがある想定
        $source = makeDiscoverSource('s-1', 'https://fortee.jp/events');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$source]);

        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);

        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')->once()->andReturn('<html>...</html>');

        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')->once()->andReturn(['https://fortee.jp/conf-x']);

        // 1 ページ目: name + cfpEndDate + officialUrl のみ (venue/開催日/format/cfpUrl 欠損)
        $page1 = new ConferenceDraft(
            sourceUrl: 'https://fortee.jp/conf-x',
            name: 'Conf X',
            officialUrl: 'https://conf-x.example.com/',
            cfpEndDate: '2026-09-30',
        );
        // 2 ページ目 (公式): 欠損していた項目を持つ
        $page2 = new ConferenceDraft(
            sourceUrl: 'https://conf-x.example.com/',
            name: 'Conf X 公式',
            cfpUrl: 'https://conf-x.example.com/cfp',
            eventStartDate: '2026-11-01',
            eventEndDate: '2026-11-02',
            venue: '東京',
            format: ConferenceFormat::Hybrid,
        );
        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extractDraft->shouldReceive('execute')->once()->with('https://fortee.jp/conf-x')->andReturn($page1);
        $extractDraft->shouldReceive('execute')->once()->with('https://conf-x.example.com/')->andReturn($page2);

        $saved = null;
        $confRepo->shouldReceive('save')->once()->with(Mockery::on(function (Conference $c) use (&$saved) {
            $saved = $c;

            return true;
        }));

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then: マージされ欠損が埋まった Conference が保存される
        /** @var Conference $saved */
        expect($saved->name)->toBe('Conf X');                  // 1 ページ目優先
        expect($saved->cfpEndDate)->toBe('2026-09-30');        // 1 ページ目維持
        expect($saved->venue)->toBe('東京');                    // 公式で補完
        expect($saved->eventStartDate)->toBe('2026-11-01');    // 公式で補完
        expect($saved->format)->toBe(ConferenceFormat::Hybrid); // 公式で補完
        expect($saved->cfpUrl)->toBe('https://conf-x.example.com/cfp');
        expect($result->officialFollowCount)->toBe(1);
        expect($result->draftsCreated)->toBe(1);
    });

    it('1 ページ目で必須項目が揃っていれば追加抽出しない', function () {
        // Given: 完全な 1 ページ目
        $source = makeDiscoverSource('s-1', 'https://fortee.jp/events');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$source]);
        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);
        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')->once()->andReturn('<html>...</html>');
        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')->once()->andReturn(['https://fortee.jp/conf-y']);

        $complete = new ConferenceDraft(
            sourceUrl: 'https://fortee.jp/conf-y',
            name: 'Conf Y',
            officialUrl: 'https://conf-y.example.com/',
            cfpUrl: 'https://conf-y.example.com/cfp',
            eventStartDate: '2026-11-01',
            eventEndDate: '2026-11-02',
            venue: '東京',
            format: ConferenceFormat::Offline,
            cfpEndDate: '2026-09-30',
        );
        // 追加抽出は呼ばれない (= execute は 1 回のみ)
        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extractDraft->shouldReceive('execute')->once()->with('https://fortee.jp/conf-y')->andReturn($complete);
        $confRepo->shouldReceive('save')->once();

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then
        expect($result->officialFollowCount)->toBe(0);
    });

    it('officialUrl が候補 URL と同一なら追加抽出しない', function () {
        // Given: 欠損はあるが officialUrl == 候補 URL (= 同じページを 2 度叩かない)
        $source = makeDiscoverSource('s-1', 'https://fortee.jp/events');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$source]);
        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);
        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')->once()->andReturn('<html>...</html>');
        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')->once()->andReturn(['https://fortee.jp/conf-z']);

        // officialUrl が候補 URL と同一 (正規化後一致)
        $sameUrl = new ConferenceDraft(
            sourceUrl: 'https://fortee.jp/conf-z',
            name: 'Conf Z',
            officialUrl: 'https://fortee.jp/conf-z',
            cfpEndDate: '2026-09-30',
        );
        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extractDraft->shouldReceive('execute')->once()->with('https://fortee.jp/conf-z')->andReturn($sameUrl);
        $confRepo->shouldReceive('save')->once();

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then
        expect($result->officialFollowCount)->toBe(0);
    });

    it('追加抽出が失敗しても 1 ページ目の Draft で保存する (非致命)', function () {
        // Given: 欠損あり + officialUrl 別ページだが、公式ページ抽出が失敗
        $source = makeDiscoverSource('s-1', 'https://fortee.jp/events');
        $sourceRepo = Mockery::mock(CfpSourceRepository::class);
        $sourceRepo->shouldReceive('findAll')->once()->andReturn([$source]);
        $confRepo = Mockery::mock(ConferenceRepository::class);
        $confRepo->shouldReceive('findAll')->once()->andReturn([]);
        $htmlFetcher = Mockery::mock(HtmlFetcher::class);
        $htmlFetcher->shouldReceive('fetch')->once()->andReturn('<html>...</html>');
        $listExtractor = Mockery::mock(ListConferenceUrlsExtractor::class);
        $listExtractor->shouldReceive('extract')->once()->andReturn(['https://fortee.jp/conf-w']);

        $page1 = new ConferenceDraft(
            sourceUrl: 'https://fortee.jp/conf-w',
            name: 'Conf W',
            officialUrl: 'https://conf-w.example.com/',
            cfpEndDate: '2026-09-30',
        );
        $extractDraft = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extractDraft->shouldReceive('execute')->once()->with('https://fortee.jp/conf-w')->andReturn($page1);
        $extractDraft->shouldReceive('execute')->once()->with('https://conf-w.example.com/')
            ->andThrow(new HtmlFetchFailedException('official page fetch failed'));

        $saved = null;
        $confRepo->shouldReceive('save')->once()->with(Mockery::on(function (Conference $c) use (&$saved) {
            $saved = $c;

            return true;
        }));

        $useCase = new DiscoverConferencesUseCase($sourceRepo, $confRepo, $htmlFetcher, $listExtractor, $extractDraft);

        // When: 例外を投げず保存まで到達
        $result = $useCase->execute(dryRun: false);

        // Then: 1 ページ目の内容で保存 (venue は null のまま)、件数はカウント
        /** @var Conference $saved */
        expect($saved->name)->toBe('Conf W');
        expect($saved->venue)->toBeNull();
        expect($result->draftsCreated)->toBe(1);
        // follow は試行したが補完できなかった (= カウントは試行ベースで 1)
        expect($result->officialFollowCount)->toBe(1);
    });
});
