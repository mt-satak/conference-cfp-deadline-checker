<?php

declare(strict_types=1);

use App\Application\Conferences\AutoCrawl\AutoCrawlConferencesUseCase;
use App\Application\Conferences\AutoCrawl\AutoCrawlResult;
use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\ExtractConferenceDraftUseCase;
use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;

/**
 * AutoCrawlConferencesUseCase の単体テスト (Issue #152 Phase 1a)。
 *
 * Phase 1a の MVP 設計:
 *   - 既存 Published conference を全件 fetch
 *   - 各 officialUrl を ExtractConferenceDraftUseCase で再抽出
 *   - 抽出値と既存値を比較 → 差分があれば Result に件数記録
 *   - DB への副作用なし (= 観測のみ、Draft 作成は Phase 1b で実装)
 *   - 抽出失敗時は次の URL に進む (= 部分成功扱い)
 *
 * 比較対象フィールド:
 *   cfpUrl / eventStartDate / eventEndDate / venue / format / cfpStartDate / cfpEndDate
 *   (= name / officialUrl は通常変わらない、categorySlugs は admin 解決後の UUID
 *    と LLM 出力 slug の比較なので Phase 1a では除外)
 */
function makePublishedConference(string $id, string $officialUrl, ?string $cfpEndDate = '2026-12-31'): Conference
{
    return new Conference(
        conferenceId: $id,
        name: "Conference {$id}",
        trackName: null,
        officialUrl: $officialUrl,
        cfpUrl: 'https://example.com/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: $cfpEndDate,
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: ConferenceStatus::Published,
    );
}

function makeDraftConference(string $id, string $officialUrl): Conference
{
    return new Conference(
        conferenceId: $id,
        name: "Draft {$id}",
        trackName: null,
        officialUrl: $officialUrl,
        cfpUrl: null,
        eventStartDate: null,
        eventEndDate: null,
        venue: null,
        format: null,
        cfpStartDate: null,
        cfpEndDate: null,
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: ConferenceStatus::Draft,
    );
}

function makeMatchingDraft(Conference $conference): ConferenceDraft
{
    return new ConferenceDraft(
        sourceUrl: $conference->officialUrl,
        name: $conference->name,
        officialUrl: $conference->officialUrl,
        cfpUrl: $conference->cfpUrl,
        eventStartDate: $conference->eventStartDate,
        eventEndDate: $conference->eventEndDate,
        venue: $conference->venue,
        format: $conference->format,
        cfpStartDate: $conference->cfpStartDate,
        cfpEndDate: $conference->cfpEndDate,
    );
}

describe('AutoCrawlConferencesUseCase', function () {
    it('Published のみを巡回対象にして Draft はスキップする', function () {
        // Given: Published 1 件 + Draft 1 件
        $published = makePublishedConference('pub-1', 'https://a.example.com/2026');
        $draft = makeDraftConference('draft-1', 'https://b.example.com/2026');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$published, $draft]);

        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        // Published 1 件分の execute だけ呼ばれる (Draft の URL は呼ばれない)
        $extract->shouldReceive('execute')
            ->once()
            ->with('https://a.example.com/2026')
            ->andReturn(makeMatchingDraft($published));

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result)->toBeInstanceOf(AutoCrawlResult::class);
        expect($result->totalChecked)->toBe(1);
        expect($result->diffDetected)->toBe(0);
        expect($result->extractionFailed)->toBe(0);
    });

    it('全フィールド一致なら diffDetected は 0', function () {
        // Given
        $conference = makePublishedConference('c1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);

        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')->once()->andReturn(makeMatchingDraft($conference));

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->totalChecked)->toBe(1);
        expect($result->diffDetected)->toBe(0);
        expect($result->extractionFailed)->toBe(0);
    });

    it('cfpEndDate が変わったら diffDetected が増える', function () {
        // Given: 既存は 2026-07-15、抽出結果は 2026-08-01 (= 締切延長)
        $conference = makePublishedConference('c1', 'https://a.example.com/', '2026-07-15');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);

        $newDraft = new ConferenceDraft(
            sourceUrl: $conference->officialUrl,
            cfpUrl: $conference->cfpUrl,
            eventStartDate: $conference->eventStartDate,
            eventEndDate: $conference->eventEndDate,
            venue: $conference->venue,
            format: $conference->format,
            cfpEndDate: '2026-08-01',  // ← 変更点
        );
        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')->once()->andReturn($newDraft);

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->totalChecked)->toBe(1);
        expect($result->diffDetected)->toBe(1);
        expect($result->extractionFailed)->toBe(0);
    });

    it('venue が変わったら diffDetected が増える', function () {
        // Given
        $conference = makePublishedConference('c1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);

        $newDraft = new ConferenceDraft(
            sourceUrl: $conference->officialUrl,
            cfpUrl: $conference->cfpUrl,
            eventStartDate: $conference->eventStartDate,
            eventEndDate: $conference->eventEndDate,
            venue: 'オンライン',  // ← 変更
            format: $conference->format,
            cfpEndDate: $conference->cfpEndDate,
        );
        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')->once()->andReturn($newDraft);

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->diffDetected)->toBe(1);
    });

    it('HtmlFetchFailedException が出たら extractionFailed を増やして次に進む', function () {
        // Given: 2 件のうち 1 件が fetch 失敗
        $c1 = makePublishedConference('c1', 'https://a.example.com/');
        $c2 = makePublishedConference('c2', 'https://b.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$c1, $c2]);

        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')
            ->once()
            ->with('https://a.example.com/')
            ->andThrow(new HtmlFetchFailedException('fetch failed'));
        $extract->shouldReceive('execute')
            ->once()
            ->with('https://b.example.com/')
            ->andReturn(makeMatchingDraft($c2));

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->totalChecked)->toBe(2);
        expect($result->diffDetected)->toBe(0);
        expect($result->extractionFailed)->toBe(1);
        expect($result->failedUrls)->toBe(['https://a.example.com/']);
    });

    it('LlmExtractionFailedException も extractionFailed として処理', function () {
        // Given
        $c1 = makePublishedConference('c1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$c1]);

        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')
            ->once()
            ->andThrow(new LlmExtractionFailedException('llm failed'));

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->extractionFailed)->toBe(1);
    });

    it('Published が 0 件なら totalChecked = 0 (= 何もしない)', function () {
        // Given: Draft のみ
        $draft = makeDraftConference('d1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$draft]);

        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldNotReceive('execute');

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->totalChecked)->toBe(0);
    });
});
