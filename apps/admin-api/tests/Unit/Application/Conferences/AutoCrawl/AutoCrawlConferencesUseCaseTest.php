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
 * AutoCrawlConferencesUseCase の単体テスト。
 *
 * Issue #152 Phase 1: 既知 Published URL の再抽出 + 差分検知 + Draft 別行の生成。
 * Issue #169: Draft 重複防止 (= findDraftByOfficialUrl で既存 Draft の ID を引き継ぐ)。
 *
 * Issue #188 で本 UseCase の挙動を変更:
 * - 差分検知時に Draft 別行を作らず、Published 行内の `pendingChanges` フィールドへ保存。
 * - `pendingChanges !== null` の Published は再検知の対象外 (= skip-if-pending)。
 *   レビュー中に新しい diff が混入する事態を構造的に防止 (人間ゲート保証)。
 * - Published の actual フィールド (cfpUrl 等) は AutoCrawl では絶対に書き換えない。
 *   書き換えるのは pendingChanges のみで、Apply UseCase (PR-3) で初めて actual に反映される。
 *
 * これに伴い:
 * - `findDraftByOfficialUrl` 経路は不要になり、Mock 期待からも外す。
 * - `AutoCrawlResult.createdDraftIds` → `pendingChangesUpdatedIds` にリネーム。
 * - `AutoCrawlResult.skippedHasPending` 追加 (= スキップ件数の観測用)。
 *
 * 比較対象フィールド:
 *   cfpUrl / eventStartDate / eventEndDate / venue / format / cfpStartDate / cfpEndDate
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

/**
 * @param  array<string, array{old: mixed, new: mixed}>  $pendingChanges
 */
function makePublishedConferenceWithPending(string $id, string $officialUrl, array $pendingChanges): Conference
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
        cfpEndDate: '2026-07-15',
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: ConferenceStatus::Published,
        pendingChanges: $pendingChanges,
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
        expect($result->skippedHasPending)->toBe(0);
    });

    it('全フィールド一致なら diffDetected は 0 で save も呼ばれない', function () {
        // Given
        $conference = makePublishedConference('c1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);
        // Issue #188: 差分なしなら Published 行を再保存する必要も無い
        $repo->shouldNotReceive('save');

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

    // ── Issue #188: 差分検知時は Published 行の pendingChanges に保存 ──

    it('cfpEndDate が変わったら Published 行の pendingChanges に保存され actual は変わらない', function () {
        // Given: 既存 cfpEndDate=2026-07-15、抽出値=2026-08-01 (= 締切延長)
        $conference = makePublishedConference('c1', 'https://a.example.com/', '2026-07-15');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);

        // Phase 1b で findDraftByOfficialUrl を期待していたが、Issue #188 では不要
        $repo->shouldNotReceive('findDraftByOfficialUrl');

        $savedConference = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$savedConference) {
                $savedConference = $c;

                return true;
            }));

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
        expect($result->pendingChangesUpdatedIds)->toBe(['c1']);  // 同 ID (= Published 行を保存)
        /** @var Conference $savedConference */
        expect($savedConference->conferenceId)->toBe('c1');  // 新規 UUID ではなく元の ID
        expect($savedConference->status)->toBe(ConferenceStatus::Published);  // Published のまま
        // actual 値は人間が Apply するまで不変 (= 人間ゲート保証)
        expect($savedConference->cfpEndDate)->toBe('2026-07-15');
        // pendingChanges に diff が入る
        expect($savedConference->pendingChanges)->toBe([
            'cfpEndDate' => ['old' => '2026-07-15', 'new' => '2026-08-01'],
        ]);
    });

    it('venue が変わったら pendingChanges.venue に old/new で記録される', function () {
        // Given
        $conference = makePublishedConference('c1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);

        $savedConference = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$savedConference) {
                $savedConference = $c;

                return true;
            }));

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
        expect($result->pendingChangesUpdatedIds)->toBe(['c1']);
        /** @var Conference $savedConference */
        expect($savedConference->pendingChanges)->toBe([
            'venue' => ['old' => '東京', 'new' => 'オンライン'],
        ]);
        expect($savedConference->venue)->toBe('東京');  // actual は不変
    });

    it('format が変わったら pendingChanges には enum->value 文字列で記録される (= DDB Marshaler 対応)', function () {
        // Given: format が Offline → Hybrid に変わる
        $conference = makePublishedConference('c1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);

        $savedConference = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$savedConference) {
                $savedConference = $c;

                return true;
            }));

        $newDraft = new ConferenceDraft(
            sourceUrl: $conference->officialUrl,
            cfpUrl: $conference->cfpUrl,
            eventStartDate: $conference->eventStartDate,
            eventEndDate: $conference->eventEndDate,
            venue: $conference->venue,
            format: ConferenceFormat::Hybrid,  // ← enum 値で渡る
            cfpEndDate: $conference->cfpEndDate,
        );
        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')->once()->andReturn($newDraft);

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then: pendingChanges には enum ではなく文字列で保存される (DDB Marshaler 対応)
        expect($result->diffDetected)->toBe(1);
        /** @var Conference $savedConference */
        expect($savedConference->pendingChanges)->toBe([
            'format' => ['old' => 'offline', 'new' => 'hybrid'],
        ]);
        expect($savedConference->format)->toBe(ConferenceFormat::Offline);  // actual 不変
    });

    it('複数フィールドが同時に変わったら全て pendingChanges に含まれる', function () {
        // Given: cfpEndDate と venue の両方が変わる
        $conference = makePublishedConference('c1', 'https://a.example.com/', '2026-07-15');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);

        $savedConference = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$savedConference) {
                $savedConference = $c;

                return true;
            }));

        $newDraft = new ConferenceDraft(
            sourceUrl: $conference->officialUrl,
            cfpUrl: $conference->cfpUrl,
            eventStartDate: $conference->eventStartDate,
            eventEndDate: $conference->eventEndDate,
            venue: '東京 (千代田区)',
            format: $conference->format,
            cfpEndDate: '2026-08-01',
        );
        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')->once()->andReturn($newDraft);

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        /** @var Conference $savedConference */
        expect($savedConference->pendingChanges)->toBe([
            'venue' => ['old' => '東京', 'new' => '東京 (千代田区)'],
            'cfpEndDate' => ['old' => '2026-07-15', 'new' => '2026-08-01'],
        ]);
    });

    it('LLM が null を返したフィールドは無視 (= 既存値維持で diff 0 / save 呼ばれず)', function () {
        // Phase 1a 観測結果: 公式サイトトップに CfP 情報が無いと LLM が null を返す。
        // この場合は既存 admin 値を信頼して diff 扱いしない。
        $conference = makePublishedConference('c1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);
        $repo->shouldNotReceive('save');

        // 全フィールド null の抽出結果
        $emptyDraft = new ConferenceDraft(sourceUrl: $conference->officialUrl);
        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')->once()->andReturn($emptyDraft);

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->totalChecked)->toBe(1);
        expect($result->diffDetected)->toBe(0);
        expect($result->pendingChangesUpdatedIds)->toBe([]);
    });

    it('LLM が一部 null + 一部新値の場合、新値部分のみ pendingChanges に含まれる', function () {
        // 例: cfpUrl=null (拾えず) / venue='詳細追加' (LLM が詳細化)
        $conference = makePublishedConference('c1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);
        $savedConference = null;
        $repo->shouldReceive('save')
            ->once()
            ->with(Mockery::on(function (Conference $c) use (&$savedConference) {
                $savedConference = $c;

                return true;
            }));

        $partialDraft = new ConferenceDraft(
            sourceUrl: $conference->officialUrl,
            cfpUrl: null,  // ← null (拾えず): pendingChanges 対象外
            venue: '東京 (千代田区)',  // ← 詳細化: pending として記録
            // 他は null
        );
        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')->once()->andReturn($partialDraft);

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->diffDetected)->toBe(1);
        /** @var Conference $savedConference */
        // pendingChanges には venue だけ記録 (cfpUrl は null 抽出だったので含まれない)
        expect($savedConference->pendingChanges)->toBe([
            'venue' => ['old' => '東京', 'new' => '東京 (千代田区)'],
        ]);
        expect($savedConference->venue)->toBe('東京');  // actual 不変
    });

    // ── Issue #188: skip-if-pending (人間ゲート保証) ──

    it('pendingChanges が既にある Conference は再抽出されず skippedHasPending が増える', function () {
        // Given: pending 待ちの Conference (= レビュー中)
        $pending = makePublishedConferenceWithPending(
            'c1',
            'https://a.example.com/',
            ['cfpEndDate' => ['old' => '2026-07-15', 'new' => '2026-08-01']],
        );
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$pending]);
        // skip するので extract / save とも呼ばれない
        $repo->shouldNotReceive('save');

        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldNotReceive('execute');

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->totalChecked)->toBe(1);
        expect($result->diffDetected)->toBe(0);
        expect($result->skippedHasPending)->toBe(1);
        expect($result->pendingChangesUpdatedIds)->toBe([]);
    });

    it('pending 0 件の空配列 (= レビュー解消直後の状態) は skip 対象ではなく通常巡回される', function () {
        // Given: pendingChanges = [] は「保留差分なし」を null と区別したい場合に使う。
        //        skip-if-pending の判定は厳密に null !== の比較で行うため、空配列は再検知される。
        $conference = new Conference(
            conferenceId: 'c1',
            name: 'Reviewed Conf',
            trackName: null,
            officialUrl: 'https://a.example.com/',
            cfpUrl: 'https://example.com/cfp',
            eventStartDate: '2026-09-19',
            eventEndDate: '2026-09-20',
            venue: '東京',
            format: ConferenceFormat::Offline,
            cfpStartDate: null,
            cfpEndDate: '2026-07-15',
            categories: [],
            description: null,
            themeColor: null,
            createdAt: '2026-04-15T10:30:00+09:00',
            updatedAt: '2026-04-15T10:30:00+09:00',
            status: ConferenceStatus::Published,
            pendingChanges: [],
        );
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$conference]);
        // 全一致なら save は呼ばれず skipped にもカウントされない
        $repo->shouldNotReceive('save');

        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        $extract->shouldReceive('execute')->once()->andReturn(makeMatchingDraft($conference));

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then: 通常巡回 (= skip されない)
        expect($result->totalChecked)->toBe(1);
        expect($result->skippedHasPending)->toBe(0);
        expect($result->diffDetected)->toBe(0);
    });

    it('pending あり + pending なし が混在しても pending あり側だけスキップされる', function () {
        // Given: 2 件中 1 件 pending、1 件 通常
        $pending = makePublishedConferenceWithPending(
            'pending-1',
            'https://a.example.com/',
            ['venue' => ['old' => 'X', 'new' => 'Y']],
        );
        $normal = makePublishedConference('normal-1', 'https://b.example.com/', '2026-07-15');

        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$pending, $normal]);

        // pending 側は extract / save が呼ばれない、normal 側だけ save が呼ばれる
        $repo->shouldReceive('save')->once();

        $extract = Mockery::mock(ExtractConferenceDraftUseCase::class);
        // normal 側だけ extract される
        $extract->shouldReceive('execute')
            ->once()
            ->with('https://b.example.com/')
            ->andReturn(new ConferenceDraft(
                sourceUrl: 'https://b.example.com/',
                cfpUrl: $normal->cfpUrl,
                eventStartDate: $normal->eventStartDate,
                eventEndDate: $normal->eventEndDate,
                venue: $normal->venue,
                format: $normal->format,
                cfpEndDate: '2026-08-01',
            ));

        $useCase = new AutoCrawlConferencesUseCase($repo, $extract);

        // When
        $result = $useCase->execute();

        // Then
        expect($result->totalChecked)->toBe(2);
        expect($result->skippedHasPending)->toBe(1);
        expect($result->diffDetected)->toBe(1);
        expect($result->pendingChangesUpdatedIds)->toBe(['normal-1']);
    });

    // ── 例外処理 (既存挙動を維持) ──

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
