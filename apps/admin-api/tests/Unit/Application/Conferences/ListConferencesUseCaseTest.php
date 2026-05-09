<?php

use App\Application\Conferences\ListConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceSortKey;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\SortOrder;

/**
 * ListConferencesUseCase の単体テスト。
 *
 * UseCase の責務は「Repository から全件取得して呼び出し元に返す」のみ。
 * フィルタ・ソート・ページネーション等は呼び出し側 (HTTP コントローラ等) で行う。
 */
function listUseCaseSampleConference(
    string $id,
    string $name,
    ConferenceStatus $status = ConferenceStatus::Published,
    ?string $cfpEndDate = '2026-07-15',
    ?string $eventStartDate = '2026-09-19',
    string $createdAt = '2026-04-15T10:30:00+09:00',
): Conference {
    return new Conference(
        conferenceId: $id,
        name: $name,
        trackName: null,
        officialUrl: 'https://example.com',
        cfpUrl: 'https://example.com/cfp',
        eventStartDate: $eventStartDate,
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: $cfpEndDate,
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: null,
        themeColor: null,
        createdAt: $createdAt,
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: $status,
    );
}

it('Repository->findAll() の結果をそのまま返す', function () {
    // Given: Repository が 2 件の Conference を返すようモックする
    $expected = [
        listUseCaseSampleConference('550e8400-e29b-41d4-a716-446655440000', 'A'),
        listUseCaseSampleConference('660e8400-e29b-41d4-a716-446655440001', 'B'),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($expected);

    // When: UseCase を実行する
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute();

    // Then: Repository の戻りがそのまま返される
    expect($result)->toBe($expected);
});

it('Repository が空配列を返した場合は空配列をそのまま返す', function () {
    // Given: Repository が空配列を返すようモックする
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn([]);

    // When: UseCase を実行する
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute();

    // Then: 空配列が返る
    expect($result)->toBe([]);
});

it('statusFilters=[Draft] で Published を除外する (Phase 0.5)', function () {
    // Given: Draft 1 件 + Published 2 件
    $all = [
        listUseCaseSampleConference('id-1', 'Draft 1', ConferenceStatus::Draft),
        listUseCaseSampleConference('id-2', 'Published A', ConferenceStatus::Published),
        listUseCaseSampleConference('id-3', 'Published B', ConferenceStatus::Published),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When: Draft フィルタで実行
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute([ConferenceStatus::Draft]);

    // Then: Draft の 1 件のみ返る
    expect($result)->toHaveCount(1);
    expect($result[0]->name)->toBe('Draft 1');
});

it('statusFilters=[Published] で Draft を除外する', function () {
    // Given: Draft 1 件 + Published 2 件
    $all = [
        listUseCaseSampleConference('id-1', 'Draft 1', ConferenceStatus::Draft),
        listUseCaseSampleConference('id-2', 'Published A', ConferenceStatus::Published),
        listUseCaseSampleConference('id-3', 'Published B', ConferenceStatus::Published),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute([ConferenceStatus::Published]);

    // Then: Published 2 件 (添字は 0,1 にリインデックスされる)
    expect($result)->toHaveCount(2);
    expect($result[0]->name)->toBe('Published A');
    expect($result[1]->name)->toBe('Published B');
});

it('statusFilters=[Draft, Published] で Archived のみを除外する (= Active タブの挙動、Issue #165)', function () {
    // Given: Draft / Published / Archived 各 1 件 (= 3 ステータス全部混在)
    $all = [
        listUseCaseSampleConference('id-1', 'Draft 1', ConferenceStatus::Draft),
        listUseCaseSampleConference('id-2', 'Published A', ConferenceStatus::Published),
        listUseCaseSampleConference('id-3', 'Archived past', ConferenceStatus::Archived),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When: Active 相当 (Draft + Published) で絞る
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute([ConferenceStatus::Draft, ConferenceStatus::Published]);

    // Then: Archived だけ除外される
    expect($result)->toHaveCount(2);
    $names = array_map(fn ($c) => $c->name, $result);
    expect($names)->toContain('Draft 1');
    expect($names)->toContain('Published A');
    expect($names)->not->toContain('Archived past');
});

it('statusFilters=[Archived] で Archived のみ返る (Issue #165)', function () {
    // Given
    $all = [
        listUseCaseSampleConference('id-1', 'Draft 1', ConferenceStatus::Draft),
        listUseCaseSampleConference('id-2', 'Published A', ConferenceStatus::Published),
        listUseCaseSampleConference('id-3', 'Archived past', ConferenceStatus::Archived),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute([ConferenceStatus::Archived]);

    // Then: Archived のみ
    expect($result)->toHaveCount(1);
    expect($result[0]->name)->toBe('Archived past');
});

it('statusFilters=null は filter 無し (= Archived も含めて全件返す、Issue #165)', function () {
    // Given
    $all = [
        listUseCaseSampleConference('id-1', 'Draft 1', ConferenceStatus::Draft),
        listUseCaseSampleConference('id-2', 'Published A', ConferenceStatus::Published),
        listUseCaseSampleConference('id-3', 'Archived past', ConferenceStatus::Archived),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When: filter なしで全件取得
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute(null);

    // Then: 3 件全部返る
    expect($result)->toHaveCount(3);
});

it('既定で cfpEndDate 昇順にソートされる (Phase A)', function () {
    // Given: cfpEndDate 順序がバラバラの 3 件 (Repository 戻りの順序は非決定的という想定)
    $all = [
        listUseCaseSampleConference('id-late', 'Late', cfpEndDate: '2026-09-30'),
        listUseCaseSampleConference('id-early', 'Early', cfpEndDate: '2026-05-07'),
        listUseCaseSampleConference('id-mid', 'Mid', cfpEndDate: '2026-07-15'),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When: フィルタ・ソート指定なしで execute
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute();

    // Then: cfpEndDate 昇順 (= 締切が近い順、本アプリのデフォルト)
    expect($result)->toHaveCount(3);
    expect($result[0]->name)->toBe('Early');
    expect($result[1]->name)->toBe('Mid');
    expect($result[2]->name)->toBe('Late');
});

it('cfpEndDate=null の Conference (Draft) はソート時に末尾に集められる', function () {
    // Given: cfpEndDate に null と確定値が混在 (順序を [非null, null, 非null] にして
    // comparator の "$bv === null" 経路 (= a 確定値 / b null) も usort 内部で踏むようにする)
    $all = [
        listUseCaseSampleConference('id-late', 'Late', cfpEndDate: '2026-09-30'),
        listUseCaseSampleConference('id-null', 'Draft', ConferenceStatus::Draft, cfpEndDate: null),
        listUseCaseSampleConference('id-early', 'Early', cfpEndDate: '2026-05-07'),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute();

    // Then: 確定値が昇順、null は末尾
    expect($result)->toHaveCount(3);
    expect($result[0]->name)->toBe('Early');
    expect($result[1]->name)->toBe('Late');
    expect($result[2]->name)->toBe('Draft');
});

it('sortKey=EventStartDate / order=desc を指定すると開催開始日降順', function () {
    // Given: eventStartDate がバラバラ
    $all = [
        listUseCaseSampleConference('id-1', 'Earlier', eventStartDate: '2026-08-01'),
        listUseCaseSampleConference('id-2', 'Latest', eventStartDate: '2026-12-15'),
        listUseCaseSampleConference('id-3', 'Middle', eventStartDate: '2026-10-01'),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute(null, ConferenceSortKey::EventStartDate, SortOrder::Desc);

    // Then: eventStartDate 降順
    expect($result)->toHaveCount(3);
    expect($result[0]->name)->toBe('Latest');
    expect($result[1]->name)->toBe('Middle');
    expect($result[2]->name)->toBe('Earlier');
});

it('sortKey=Name で名前 (string) 昇順ソートされる', function () {
    // Given: 名前順がバラバラ
    $all = [
        listUseCaseSampleConference('id-1', 'Zebra'),
        listUseCaseSampleConference('id-2', 'Alpha'),
        listUseCaseSampleConference('id-3', 'Mango'),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute(null, ConferenceSortKey::Name, SortOrder::Asc);

    // Then: name 昇順
    expect($result[0]->name)->toBe('Alpha');
    expect($result[1]->name)->toBe('Mango');
    expect($result[2]->name)->toBe('Zebra');
});

it('降順指定でも null は末尾に置かれる (UX 一貫性)', function () {
    // Given: cfpEndDate に null + 確定値混在
    $all = [
        listUseCaseSampleConference('id-null', 'No CfP', ConferenceStatus::Draft, cfpEndDate: null),
        listUseCaseSampleConference('id-late', 'Late', cfpEndDate: '2026-09-30'),
        listUseCaseSampleConference('id-early', 'Early', cfpEndDate: '2026-05-07'),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When: 降順
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute(null, ConferenceSortKey::CfpEndDate, SortOrder::Desc);

    // Then: 確定値は降順、null は依然として末尾 (= 「未確定はいつも視界の端」)
    expect($result)->toHaveCount(3);
    expect($result[0]->name)->toBe('Late');
    expect($result[1]->name)->toBe('Early');
    expect($result[2]->name)->toBe('No CfP');
});

/**
 * compareNullable 静的ヘルパの全分岐を直接検証 (Issue #47 Phase A)。
 *
 * usort の比較ペア順序は PHP のソート実装 (Timsort) に依存して非決定的なため、
 * 入力配列パターンだけでは line 63 ($bv === null → return -1) などの一部分岐を
 * 安定的にカバーできない。helper を直接呼び出して xdebug C1 100% を担保する。
 */
it('compareNullable(両方非null, 昇順) は数値比較相当の値を返す', function () {
    expect(ListConferencesUseCase::compareNullable('a', 'b', SortOrder::Asc))->toBeLessThan(0);
    expect(ListConferencesUseCase::compareNullable('b', 'a', SortOrder::Asc))->toBeGreaterThan(0);
    expect(ListConferencesUseCase::compareNullable('a', 'a', SortOrder::Asc))->toBe(0);
});

it('compareNullable(両方非null, 降順) は符号反転する', function () {
    expect(ListConferencesUseCase::compareNullable('a', 'b', SortOrder::Desc))->toBeGreaterThan(0);
    expect(ListConferencesUseCase::compareNullable('b', 'a', SortOrder::Desc))->toBeLessThan(0);
});

it('compareNullable は null を常に末尾扱い (昇順 / 降順 共通)', function () {
    // (null, 非null): null が後 → 1 を返す
    expect(ListConferencesUseCase::compareNullable(null, 'a', SortOrder::Asc))->toBe(1);
    expect(ListConferencesUseCase::compareNullable(null, 'a', SortOrder::Desc))->toBe(1);

    // (非null, null): 非null が前 → -1 を返す
    expect(ListConferencesUseCase::compareNullable('a', null, SortOrder::Asc))->toBe(-1);
    expect(ListConferencesUseCase::compareNullable('a', null, SortOrder::Desc))->toBe(-1);
});

it('compareNullable(両方null) は 0 を返す (= 元の順序維持)', function () {
    expect(ListConferencesUseCase::compareNullable(null, null, SortOrder::Asc))->toBe(0);
    expect(ListConferencesUseCase::compareNullable(null, null, SortOrder::Desc))->toBe(0);
});

it('comparator(非null, null) ペアを通す (= 確定値が先 / null が後の 2 件)', function () {
    // Given: 2 件で [非 null, null] の順 → usort comparator が
    // (arr[0]=非null, arr[1]=null) を 1 回呼んで line "$bv===null → return -1" 経路を踏む
    $all = [
        listUseCaseSampleConference('id-1', 'Has CfP', cfpEndDate: '2026-05-07'),
        listUseCaseSampleConference('id-2', 'No CfP', ConferenceStatus::Draft, cfpEndDate: null),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute();

    // Then: 既に望ましい順序、確定値が先、null が末尾
    expect($result)->toHaveCount(2);
    expect($result[0]->name)->toBe('Has CfP');
    expect($result[1]->name)->toBe('No CfP');
});

it('両方 null 同士のソート比較は元の相対順序を保つ (return 0)', function () {
    // Given: cfpEndDate がともに null の Draft を 2 件
    $all = [
        listUseCaseSampleConference('id-1', 'First Draft', ConferenceStatus::Draft, cfpEndDate: null),
        listUseCaseSampleConference('id-2', 'Second Draft', ConferenceStatus::Draft, cfpEndDate: null),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute();

    // Then: 元の順序を保つ (= comparator が両方 null で 0 を返している経路)
    expect($result)->toHaveCount(2);
    expect($result[0]->name)->toBe('First Draft');
    expect($result[1]->name)->toBe('Second Draft');
});

it('createdAt ソートで日時文字列の比較が機能する', function () {
    // Given: createdAt がバラバラ (= CreatedAt キー経路と string 比較を踏む)
    $all = [
        listUseCaseSampleConference('id-1', 'Newer', createdAt: '2026-04-15T10:30:00+09:00'),
        listUseCaseSampleConference('id-2', 'Older', createdAt: '2026-01-01T00:00:00+09:00'),
        listUseCaseSampleConference('id-3', 'Newest', createdAt: '2026-05-01T00:00:00+09:00'),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute(null, ConferenceSortKey::CreatedAt, SortOrder::Asc);

    // Then: 古い順
    expect($result[0]->name)->toBe('Older');
    expect($result[1]->name)->toBe('Newer');
    expect($result[2]->name)->toBe('Newest');
});

it('cfpStartDate ソートのコード経路もカバーされる', function () {
    // Given: cfpStartDate を一部設定 (デフォルトの listUseCaseSampleConference は null なのでもう 1 件カスタム作る)
    $all = [
        new Conference(
            conferenceId: 'id-1', name: 'Late Start', trackName: null,
            officialUrl: 'https://x.example.com', cfpUrl: null,
            eventStartDate: null, eventEndDate: null, venue: null, format: null,
            cfpStartDate: '2026-08-01', cfpEndDate: null, categories: [],
            description: null, themeColor: null,
            createdAt: '2026-01-01T00:00:00+09:00', updatedAt: '2026-01-01T00:00:00+09:00',
            status: ConferenceStatus::Draft,
        ),
        new Conference(
            conferenceId: 'id-2', name: 'Early Start', trackName: null,
            officialUrl: 'https://x.example.com', cfpUrl: null,
            eventStartDate: null, eventEndDate: null, venue: null, format: null,
            cfpStartDate: '2026-04-01', cfpEndDate: null, categories: [],
            description: null, themeColor: null,
            createdAt: '2026-01-01T00:00:00+09:00', updatedAt: '2026-01-01T00:00:00+09:00',
            status: ConferenceStatus::Draft,
        ),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute(null, ConferenceSortKey::CfpStartDate, SortOrder::Asc);

    // Then
    expect($result[0]->name)->toBe('Early Start');
    expect($result[1]->name)->toBe('Late Start');
});

it('status フィルタとソートは併用可能', function () {
    // Given: Draft / Published 混在 + cfpEndDate バラバラ
    $all = [
        listUseCaseSampleConference('id-1', 'Pub Late', ConferenceStatus::Published, cfpEndDate: '2026-09-30'),
        listUseCaseSampleConference('id-2', 'Draft', ConferenceStatus::Draft, cfpEndDate: null),
        listUseCaseSampleConference('id-3', 'Pub Early', ConferenceStatus::Published, cfpEndDate: '2026-05-07'),
    ];
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findAll')->once()->andReturn($all);

    // When: Published フィルタ + cfpEndDate 昇順
    $useCase = new ListConferencesUseCase($repository);
    $result = $useCase->execute([ConferenceStatus::Published]);

    // Then: Published 2 件のみが昇順
    expect($result)->toHaveCount(2);
    expect($result[0]->name)->toBe('Pub Early');
    expect($result[1]->name)->toBe('Pub Late');
});
