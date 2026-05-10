<?php

use App\Application\Categories\ListCategoriesUseCase;
use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\ListConferencesUseCase;
use App\Application\Conferences\PendingChanges\ApplyPendingChangesUseCase;
use App\Application\Conferences\PendingChanges\RejectPendingChangesUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceStatus;
use App\Http\Middleware\VerifyOrigin;

/**
 * Issue #188 PR-3: Admin Blade UI での pendingChanges 表示 + Apply/Reject の Feature テスト。
 *
 * 検証範囲:
 * - 一覧画面: pendingChanges あり Conference に「保留中変更あり」バッジ
 * - 編集画面: pendingChanges 表示 + Apply/Reject フォーム
 * - POST /pending/apply: ApplyPendingChangesUseCase 呼出 + edit リダイレクト
 * - POST /pending/reject: RejectPendingChangesUseCase 呼出 + edit リダイレクト
 * - 404 ハンドリング (ConferenceNotFoundException)
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

function pendingUiPublished(string $id, ?array $pendingChanges = null): Conference
{
    return new Conference(
        conferenceId: $id,
        name: "Conf {$id}",
        trackName: null,
        officialUrl: 'https://x.example.com/',
        cfpUrl: 'https://x.example.com/cfp',
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

// ── Index 画面: バッジ表示 ──

it('index: pendingChanges あり Conference は「保留中変更あり (N件)」バッジを表示', function () {
    // Given: pendingChanges 2 件あり
    $conf = pendingUiPublished('with-pending', [
        'cfpEndDate' => ['old' => '2026-07-15', 'new' => '2026-08-01'],
        'venue' => ['old' => '東京', 'new' => 'オンライン'],
    ]);
    $list = Mockery::mock(ListConferencesUseCase::class);
    $list->shouldReceive('execute')->once()->andReturn([$conf]);
    app()->instance(ListConferencesUseCase::class, $list);

    // When
    $response = $this->get('/admin/conferences');

    // Then
    $response->assertStatus(200);
    $response->assertSee('保留中変更あり', escape: false);
    $response->assertSee('(2 件)', escape: false);
});

it('index: pendingChanges なし Conference にはバッジが出ない', function () {
    // Given: pending なし
    $conf = pendingUiPublished('no-pending', null);
    $list = Mockery::mock(ListConferencesUseCase::class);
    $list->shouldReceive('execute')->once()->andReturn([$conf]);
    app()->instance(ListConferencesUseCase::class, $list);

    // When
    $response = $this->get('/admin/conferences');

    // Then
    $response->assertStatus(200);
    $response->assertDontSee('保留中変更あり');
});

it('index: pendingChanges = [] (空配列) もバッジ非表示 (= 0 件は保留無しとして扱う)', function () {
    // Given: pending 空配列
    $conf = pendingUiPublished('empty-pending', []);
    $list = Mockery::mock(ListConferencesUseCase::class);
    $list->shouldReceive('execute')->once()->andReturn([$conf]);
    app()->instance(ListConferencesUseCase::class, $list);

    // When
    $response = $this->get('/admin/conferences');

    // Then
    $response->assertStatus(200);
    $response->assertDontSee('保留中変更あり');
});

// ── Edit 画面: pending セクション表示 ──

it('edit: pendingChanges ありなら「保留中の変更」セクションと old/new 値を表示する', function () {
    // Given: cfpEndDate 保留差分
    $conf = pendingUiPublished('edit-with-pending', [
        'cfpEndDate' => ['old' => '2026-07-15', 'new' => '2026-08-01'],
    ]);
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')->once()->with('edit-with-pending')->andReturn($conf);
    app()->instance(GetConferenceUseCase::class, $get);

    $listCat = Mockery::mock(ListCategoriesUseCase::class);
    $listCat->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListCategoriesUseCase::class, $listCat);

    // When
    $response = $this->get('/admin/conferences/edit-with-pending/edit');

    // Then
    $response->assertStatus(200);
    $response->assertSee('保留中の変更', escape: false);
    $response->assertSee('CfP 締切', escape: false);  // 日本語ラベル
    $response->assertSee('2026-07-15', escape: false);  // old
    $response->assertSee('2026-08-01', escape: false);  // new
    $response->assertSee('全て適用', escape: false);
    $response->assertSee('破棄', escape: false);
});

it('edit: pendingChanges なしなら「保留中の変更」セクション非表示', function () {
    // Given: pending 無し
    $conf = pendingUiPublished('edit-no-pending', null);
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')->once()->andReturn($conf);
    app()->instance(GetConferenceUseCase::class, $get);

    $listCat = Mockery::mock(ListCategoriesUseCase::class);
    $listCat->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListCategoriesUseCase::class, $listCat);

    // When
    $response = $this->get('/admin/conferences/edit-no-pending/edit');

    // Then
    $response->assertStatus(200);
    $response->assertDontSee('保留中の変更');
    $response->assertDontSee('全て適用');
});

// ── POST apply ──

it('apply: ApplyPendingChangesUseCase を呼んで edit にリダイレクト + 成功 flash', function () {
    // Given: UseCase が Published を返す
    $applied = pendingUiPublished('apply-target', null);  // applied なので pending クリア後
    $useCase = Mockery::mock(ApplyPendingChangesUseCase::class);
    $useCase->shouldReceive('execute')->once()->with('apply-target')->andReturn($applied);
    app()->instance(ApplyPendingChangesUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/apply-target/pending/apply');

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences/apply-target/edit');
    $response->assertSessionHas('status');
    expect(session('status'))->toContain('適用しました');
});

it('apply: ConferenceNotFoundException で 404', function () {
    // Given
    $useCase = Mockery::mock(ApplyPendingChangesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with('missing')
        ->andThrow(ConferenceNotFoundException::withId('missing'));
    app()->instance(ApplyPendingChangesUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/missing/pending/apply');

    // Then
    $response->assertStatus(404);
});

// ── POST reject ──

it('reject: RejectPendingChangesUseCase を呼んで edit にリダイレクト + 成功 flash', function () {
    // Given
    $rejected = pendingUiPublished('reject-target', null);
    $useCase = Mockery::mock(RejectPendingChangesUseCase::class);
    $useCase->shouldReceive('execute')->once()->with('reject-target')->andReturn($rejected);
    app()->instance(RejectPendingChangesUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/reject-target/pending/reject');

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences/reject-target/edit');
    $response->assertSessionHas('status');
    expect(session('status'))->toContain('破棄しました');
});

it('reject: ConferenceNotFoundException で 404', function () {
    // Given
    $useCase = Mockery::mock(RejectPendingChangesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(ConferenceNotFoundException::withId('missing'));
    app()->instance(RejectPendingChangesUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/missing/pending/reject');

    // Then
    $response->assertStatus(404);
});
