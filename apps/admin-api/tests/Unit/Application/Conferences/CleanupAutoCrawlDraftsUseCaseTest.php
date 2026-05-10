<?php

declare(strict_types=1);

use App\Application\Conferences\CleanupAutoCrawlDrafts\CleanupAutoCrawlDraftsResult;
use App\Application\Conferences\CleanupAutoCrawlDrafts\CleanupAutoCrawlDraftsUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;

/**
 * CleanupAutoCrawlDraftsUseCase の単体テスト (Issue #188 PR-2)。
 *
 * 目的: PR-1 で AutoCrawl が Draft 別行を作らない設計に切り替わった後、
 *       過去の AutoCrawl が生成した Draft 行を一括削除するヘルパ UseCase。
 *
 * 識別基準:
 *   Draft.officialUrl (正規化後) が Published.officialUrl (正規化後) のいずれかと
 *   一致する場合、その Draft は AutoCrawl 起源とみなして削除対象にする。
 *
 *   admin が手動で作る Draft は新規 conference 用なので URL 重複しない (= 保護される)。
 *
 * 動作モード:
 *   - dryRun = true (デフォルト挙動): 削除候補 ID 一覧を返すだけで実削除しない
 *   - dryRun = false: deleteById を呼んで実削除し、削除済 ID を返す
 *
 * 正規化:
 *   OfficialUrl::normalize() で scheme / host / trailing slash / query / fragment 等の
 *   表記揺れを吸収する (= AutoCrawl 経路と同じ判定軸)。
 */
function makeCleanupPublished(string $id, string $officialUrl): Conference
{
    return new Conference(
        conferenceId: $id,
        name: "Pub {$id}",
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

function makeCleanupDraft(string $id, string $officialUrl): Conference
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
        createdAt: '2026-04-01T10:00:00+09:00',
        updatedAt: '2026-04-01T10:00:00+09:00',
        status: ConferenceStatus::Draft,
    );
}

function makeCleanupArchived(string $id, string $officialUrl): Conference
{
    return new Conference(
        conferenceId: $id,
        name: "Arc {$id}",
        trackName: null,
        officialUrl: $officialUrl,
        cfpUrl: null,
        eventStartDate: '2025-09-19',
        eventEndDate: '2025-09-20',
        venue: null,
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: '2025-07-15',
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2025-04-01T10:00:00+09:00',
        updatedAt: '2025-04-01T10:00:00+09:00',
        status: ConferenceStatus::Archived,
    );
}

describe('CleanupAutoCrawlDraftsUseCase', function () {
    it('dry-run はデフォルト挙動で deleteById を呼ばず候補 ID 一覧だけ返す', function () {
        // Given: Published と同 URL の Draft が 1 件
        $pub = makeCleanupPublished('pub-1', 'https://a.example.com/2026');
        $draft = makeCleanupDraft('draft-1', 'https://a.example.com/2026');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$pub, $draft]);
        $repo->shouldNotReceive('deleteById');

        $useCase = new CleanupAutoCrawlDraftsUseCase($repo);

        // When: dryRun (デフォルト)
        $result = $useCase->execute();

        // Then: 候補に draft-1 が含まれ、削除実行は無し
        expect($result)->toBeInstanceOf(CleanupAutoCrawlDraftsResult::class);
        expect($result->dryRun)->toBeTrue();
        expect($result->candidateIds)->toBe(['draft-1']);
        expect($result->deletedIds)->toBe([]);
    });

    it('apply (dryRun=false) は deleteById を呼んで削除済 ID 一覧を返す', function () {
        // Given: Published と同 URL の Draft が 2 件
        $pub = makeCleanupPublished('pub-1', 'https://a.example.com/2026');
        $d1 = makeCleanupDraft('draft-1', 'https://a.example.com/2026');
        $d2 = makeCleanupDraft('draft-2', 'https://a.example.com/2026');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$pub, $d1, $d2]);
        $repo->shouldReceive('deleteById')->once()->with('draft-1')->andReturn(true);
        $repo->shouldReceive('deleteById')->once()->with('draft-2')->andReturn(true);

        $useCase = new CleanupAutoCrawlDraftsUseCase($repo);

        // When: apply 実行
        $result = $useCase->execute(dryRun: false);

        // Then
        expect($result->dryRun)->toBeFalse();
        expect($result->candidateIds)->toBe(['draft-1', 'draft-2']);
        expect($result->deletedIds)->toBe(['draft-1', 'draft-2']);
    });

    it('admin 手動作成の Draft (= Published と URL 重複なし) は削除対象外', function () {
        // Given: 別 URL の Published と Draft (= admin が新規 conference 用に作った Draft 想定)
        $pub = makeCleanupPublished('pub-1', 'https://existing.example.com/');
        $manualDraft = makeCleanupDraft('manual-1', 'https://new-conference.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$pub, $manualDraft]);
        $repo->shouldNotReceive('deleteById');

        $useCase = new CleanupAutoCrawlDraftsUseCase($repo);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then: admin Draft は保護される
        expect($result->candidateIds)->toBe([]);
        expect($result->deletedIds)->toBe([]);
    });

    it('URL 表記揺れ (https vs http / trailing slash / www) は正規化して同一視する', function () {
        // Given: Published は https://a.example.com、Draft は http://www.a.example.com/ (= 同一視されるべき)
        $pub = makeCleanupPublished('pub-1', 'https://a.example.com/2026');
        $draft = makeCleanupDraft('draft-1', 'http://www.a.example.com/2026/');  // 表記揺れ
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$pub, $draft]);

        $useCase = new CleanupAutoCrawlDraftsUseCase($repo);

        // When: dry-run
        $result = $useCase->execute();

        // Then: 表記揺れも候補に
        expect($result->candidateIds)->toBe(['draft-1']);
    });

    it('Archived は Published 集合に含めない (= Archived の URL と被る Draft は削除対象外)', function () {
        // Given: 過去 conference (Archived) と同 URL の Draft (= 来年版を AutoCrawl 起源で作ったわけではない)
        // Archived は新規 AutoCrawl の対象外なので、Draft が Archived と同じ URL でも
        // それは新規 conference 用の admin 手動作成の可能性が高い → 保護する
        $arc = makeCleanupArchived('arc-1', 'https://a.example.com/2025');
        $draft = makeCleanupDraft('draft-1', 'https://a.example.com/2025');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$arc, $draft]);
        $repo->shouldNotReceive('deleteById');

        $useCase = new CleanupAutoCrawlDraftsUseCase($repo);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then: Archived 基準では削除対象にしない (= 保守的に)
        expect($result->candidateIds)->toBe([]);
        expect($result->deletedIds)->toBe([]);
    });

    it('Published 0 件 / Draft 0 件のいずれかが空でも安全に空結果を返す', function () {
        // Given: Published のみ (Draft なし)
        $pub = makeCleanupPublished('pub-1', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$pub]);
        $repo->shouldNotReceive('deleteById');

        $useCase = new CleanupAutoCrawlDraftsUseCase($repo);

        // When
        $result = $useCase->execute(dryRun: false);

        // Then
        expect($result->candidateIds)->toBe([]);
        expect($result->deletedIds)->toBe([]);
    });

    it('複数 Published + 複数 Draft が混在しても URL 一致する Draft だけ拾う', function () {
        // Given: Pub × 2 + Draft × 3 (うち 2 件が Pub と URL 一致)
        $p1 = makeCleanupPublished('p1', 'https://a.example.com/');
        $p2 = makeCleanupPublished('p2', 'https://b.example.com/');
        $d1 = makeCleanupDraft('d1', 'https://a.example.com/');         // 該当 (= p1)
        $d2 = makeCleanupDraft('d2', 'https://b.example.com/');         // 該当 (= p2)
        $d3 = makeCleanupDraft('d3', 'https://other.example.com/');     // 非該当 (= manual)
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$p1, $p2, $d1, $d2, $d3]);

        $useCase = new CleanupAutoCrawlDraftsUseCase($repo);

        // When: dry-run
        $result = $useCase->execute();

        // Then: d1 / d2 が候補、d3 は除外
        expect($result->candidateIds)->toBe(['d1', 'd2']);
    });

    it('apply 中に deleteById が false を返した場合 deletedIds から除外して残りを継続する', function () {
        // Given: 2 件削除候補。1 件は削除失敗 (= 既に消えていた等)
        $pub = makeCleanupPublished('pub-1', 'https://a.example.com/');
        $d1 = makeCleanupDraft('d1', 'https://a.example.com/');
        $d2 = makeCleanupDraft('d2', 'https://a.example.com/');
        $repo = Mockery::mock(ConferenceRepository::class);
        $repo->shouldReceive('findAll')->once()->andReturn([$pub, $d1, $d2]);
        $repo->shouldReceive('deleteById')->once()->with('d1')->andReturn(false);  // 失敗
        $repo->shouldReceive('deleteById')->once()->with('d2')->andReturn(true);   // 成功

        $useCase = new CleanupAutoCrawlDraftsUseCase($repo);

        // When: apply
        $result = $useCase->execute(dryRun: false);

        // Then: 候補は両方、削除済は d2 のみ
        expect($result->candidateIds)->toBe(['d1', 'd2']);
        expect($result->deletedIds)->toBe(['d2']);
    });
});
