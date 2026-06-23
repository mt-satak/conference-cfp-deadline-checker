<?php

use App\Application\Conferences\ListConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceSortKey;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\SortOrder;
use Illuminate\Support\Carbon;

/**
 * /admin/conferences (一覧画面) の Blade SSR Feature テスト。
 *
 * UseCase を Mockery で差し替えて Domain Entity を渡し、Blade が期待通り
 * 描画しているかを assertSee で検証する。
 */
beforeEach(function () {
    // Vite は test 時には manifest 不在で例外を投げるためダミー化する。
    test()->withoutVite();
});
function makeUiSampleConference(
    string $name = 'PHPカンファレンス2026',
    ConferenceStatus $status = ConferenceStatus::Published,
): Conference {
    return new Conference(
        conferenceId: '550e8400-e29b-41d4-a716-446655440000',
        name: $name,
        trackName: '一般 CfP',
        officialUrl: 'https://example.com',
        cfpUrl: 'https://example.com/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: '2026-05-01',
        cfpEndDate: '2026-07-15',
        categories: ['cat-1', 'cat-2'],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: $status,
    );
}

it('GET /admin/conferences は 200 を返し、UseCase の結果を一覧表示する', function () {
    // Given: UseCase が 1 件返すモック
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([makeUiSampleConference()]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then
    $response->assertStatus(200);
    $response->assertSee('PHPカンファレンス2026', false);
    // trackName は Issue #204 で admin 一覧表示から撤去 (= 入力欄も同時撤去のため不一致防止)
    $response->assertDontSee('一般 CfP', false);
    $response->assertSee('2026-07-15', false); // CfP 締切
    $response->assertSee('offline', false);   // format
    $response->assertSee('1 件', false);
});

it('一覧に一括削除ツールバーと行 checkbox が表示される (Issue #219)', function () {
    // Given: 1 件
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([makeUiSampleConference()]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then: bulk-delete form / 全選択 / 行 checkbox (form 紐付け) が出る
    $response->assertStatus(200);
    $response->assertSee('action="'.route('admin.conferences.bulk-delete').'"', false);
    $response->assertSee('data-bulk-select-all', false);
    $response->assertSee('name="ids[]"', false);
    $response->assertSee('form="bulkDeleteForm"', false);
    $response->assertSee('選択した行を削除', false);
});

it('一覧が 0 件のときは一括削除ツールバーを表示しない (Issue #219)', function () {
    // Given: 0 件
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then: empty state のみでツールバーは無い
    $response->assertStatus(200);
    $response->assertDontSee('data-bulk-delete-form', false);
});

it('GET /admin/conferences は 0 件で empty state を表示する', function () {
    // Given: 0 件
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then
    $response->assertStatus(200);
    $response->assertSee('該当するカンファレンスがありません', false);
});

it('GET /admin/conferences のナビでカンファレンス項目がアクティブ', function () {
    // Given
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then: アクティブクラスがカンファレンスリンクに当たる経路を踏む
    $response->assertStatus(200);
    expect($response->getContent())->toContain('font-semibold text-blue-700');
});

it('Published / Draft の status バッジが行ごとに表示される (Phase 0.5)', function () {
    // Given: Published 1 件 + Draft 1 件
    $published = makeUiSampleConference('Published カンファ', ConferenceStatus::Published);
    $draft = makeUiSampleConference('Draft カンファ', ConferenceStatus::Draft);
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$published, $draft]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then: 公開中 / 下書き 両ラベルが描画される
    $response->assertStatus(200);
    $response->assertSee('公開中', false);
    $response->assertSee('下書き', false);
});

it('?status=draft で UseCase に [Draft] フィルタが渡る', function () {
    // Given
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with([ConferenceStatus::Draft], null, SortOrder::Asc)
        ->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences?status=draft');

    // Then
    $response->assertStatus(200);
});

it('?status=published で UseCase に [Published] フィルタが渡る', function () {
    // Given
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with([ConferenceStatus::Published], null, SortOrder::Asc)
        ->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences?status=published');

    // Then
    $response->assertStatus(200);
});

it('?status=archived で UseCase に [Archived] フィルタが渡る (Issue #165)', function () {
    // Given
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with([ConferenceStatus::Archived], null, SortOrder::Asc)
        ->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences?status=archived');

    // Then
    $response->assertStatus(200);
});

it('?status=active (= デフォルト) で UseCase に [Draft, Published] が渡る (Issue #165)', function () {
    // Given: Active タブは「Draft + Published」を意味する仮想 status 値。
    // Archived を一覧から自動的にノイズとして消すための仕掛け。
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with([ConferenceStatus::Draft, ConferenceStatus::Published], null, SortOrder::Asc)
        ->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences?status=active');

    // Then
    $response->assertStatus(200);
});

it('?status 未指定は active タブ相当の挙動になる (Issue #165 デフォルト挙動)', function () {
    // Given: 一覧画面に直接アクセスしたら Archived は出ないようにする。
    // = ?status=active と同等のフィルタ ([Draft, Published]) を UseCase に渡す。
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with([ConferenceStatus::Draft, ConferenceStatus::Published], null, SortOrder::Asc)
        ->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then
    $response->assertStatus(200);
});

it('admin タブに「アーカイブ」が含まれる (Issue #165)', function () {
    // Given: タブ表示確認のためテスト用にエラーなく描画されるよう mock
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then: タブのラベルに「アーカイブ」が含まれる + リンク先が ?status=archived
    $response->assertStatus(200);
    $response->assertSee('アーカイブ', false);
    $response->assertSee('?status=archived', false);
});

it('Draft 行には「公開する」ショートカットボタンが表示される', function () {
    // Given: Draft 1 件
    $draft = makeUiSampleConference('Draft カンファ', ConferenceStatus::Draft);
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$draft]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then: publish エンドポイントへの form と submit ボタンが含まれる
    $response->assertStatus(200);
    $response->assertSee('/admin/conferences/550e8400-e29b-41d4-a716-446655440000/publish', false);
    $response->assertSee('公開する', false);
});

it('Published 行には「公開する」ボタンは出ない', function () {
    // Given: Published 1 件
    $published = makeUiSampleConference('Public カンファ', ConferenceStatus::Published);
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$published]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then: publish エンドポイントへの form は描画されない
    $response->assertStatus(200);
    expect($response->getContent())->not->toContain('/publish');
});

it('?sort=name&order=desc で UseCase に sortKey + Desc が渡る (Issue #47 Phase A)', function () {
    // Given: ?status 未指定なので Issue #165 のデフォルト (= active) で
    // [Draft, Published] が statusFilters に渡る
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with([ConferenceStatus::Draft, ConferenceStatus::Published], ConferenceSortKey::Name, SortOrder::Desc)
        ->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences?sort=name&order=desc');

    // Then
    $response->assertStatus(200);
});

it('既定 (?sort 未指定) で 列ヘッダの CfP 締切に ▲ 印が出る', function () {
    // Given: 1 件返す
    $conf = makeUiSampleConference('Conf', ConferenceStatus::Published);
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$conf]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then: cfpEndDate 列ヘッダに ▲ がついて表示される
    $response->assertStatus(200);
    $response->assertSee('CfP 締切 ▲', false);
});

it('?sort=name&order=desc で名称列に ▼、CfP 締切列には記号なし', function () {
    // Given
    $conf = makeUiSampleConference('Conf', ConferenceStatus::Published);
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$conf]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences?sort=name&order=desc');

    // Then
    $response->assertStatus(200);
    $response->assertSee('名称 ▼', false);
    expect($response->getContent())->not->toContain('CfP 締切 ▲');
    expect($response->getContent())->not->toContain('CfP 締切 ▼');
});

// ── Issue #200 PR-2: 「🆕 自動発見」バッジ ──

/**
 * @param  array{discoveredAt: string, sourceId: string}|null  $meta
 */
function makeDiscoveryConference(?array $meta): Conference
{
    return new Conference(
        conferenceId: 'd-conf-1',
        name: '自動発見カンファ',
        trackName: null,
        officialUrl: 'https://discovered.example.com/',
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
        createdAt: '2026-05-15T08:00:00+09:00',
        updatedAt: '2026-05-15T08:00:00+09:00',
        status: ConferenceStatus::Draft,
        discoveryMetadata: $meta,
    );
}

it('index は直近 14 日以内に自動発見された Draft に「🆕 自動発見」バッジを表示する', function () {
    // Given: Carbon::now() = 2026-05-15 JST (固定) で「直近 14 日以内」に該当する
    Carbon::setTestNow(Carbon::create(2026, 5, 15, 12, 0, 0, 'Asia/Tokyo'));

    $conf = makeDiscoveryConference([
        'discoveredAt' => '2026-05-15T08:00:00+09:00',  // today
        'sourceId' => 'source-fortee',
    ]);
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$conf]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then
    $response->assertStatus(200);
    $response->assertSee('🆕 自動発見', false);

    Carbon::setTestNow();
});

it('index は 14 日より前 (= 15 日以上前) の自動発見 Draft にはバッジを表示しない', function () {
    // Given: today=2026-05-15、discoveredAt=2026-04-30 (= 15 日前)
    Carbon::setTestNow(Carbon::create(2026, 5, 15, 12, 0, 0, 'Asia/Tokyo'));

    $conf = makeDiscoveryConference([
        'discoveredAt' => '2026-04-30T08:00:00+09:00',
        'sourceId' => 'source-fortee',
    ]);
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$conf]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then: バッジ非表示
    $response->assertStatus(200);
    $response->assertDontSee('🆕 自動発見');

    Carbon::setTestNow();
});

it('index は discoveryMetadata 無し (= 手動作成 Conference) にはバッジを表示しない', function () {
    // Given
    $conf = makeDiscoveryConference(null);
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([$conf]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then
    $response->assertStatus(200);
    $response->assertDontSee('🆕 自動発見');
});
