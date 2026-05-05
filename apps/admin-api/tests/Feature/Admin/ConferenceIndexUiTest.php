<?php

use App\Application\Conferences\ListConferencesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceStatus;

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
    $response->assertSee('一般 CfP', false);  // trackName 表示
    $response->assertSee('2026-07-15', false); // CfP 締切
    $response->assertSee('offline', false);   // format
    $response->assertSee('1 件', false);
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

it('?status=draft で UseCase に Draft フィルタが渡る', function () {
    // Given
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->with(ConferenceStatus::Draft)->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences?status=draft');

    // Then
    $response->assertStatus(200);
});

it('?status=published で UseCase に Published フィルタが渡る', function () {
    // Given
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->with(ConferenceStatus::Published)->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences?status=published');

    // Then
    $response->assertStatus(200);
});

it('?status 未指定はフィルタなしで UseCase が呼ばれる', function () {
    // Given
    $useCase = Mockery::mock(ListConferencesUseCase::class);
    $useCase->shouldReceive('execute')->once()->with(null)->andReturn([]);
    app()->instance(ListConferencesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/conferences');

    // Then
    $response->assertStatus(200);
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
