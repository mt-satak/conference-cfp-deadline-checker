<?php

use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceStatus;
use App\Http\Middleware\VerifyOrigin;

/**
 * POST /admin/conferences/{id}/publish (Phase 0.5 / Issue #41 PR-3) の Feature テスト。
 *
 * 一覧画面の Draft 行から 1 クリックで公開するショートカット。
 * 既存エンティティの Published 必須項目を Repository データで検証し、欠落時は
 * edit 画面へ戻して error フラッシュを出す動作を確認する。
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

/**
 * Published 必須項目すべてが揃った Draft Conference (publish 可能な状態)。
 */
function publishReadyDraft(): Conference
{
    return new Conference(
        conferenceId: 'draft-ready',
        name: 'PHPカンファレンス2026',
        trackName: null,
        officialUrl: 'https://phpcon.example.com/2026',
        cfpUrl: 'https://phpcon.example.com/2026/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: '2026-07-15',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: ConferenceStatus::Draft,
    );
}

/**
 * Published 必須項目が欠落した不完全な Draft Conference。
 */
function publishUnreadyDraft(): Conference
{
    return new Conference(
        conferenceId: 'draft-unready',
        name: '未確定カンファ',
        trackName: null,
        officialUrl: 'https://draft.example.com',
        cfpUrl: null,           // 不足
        eventStartDate: null,   // 不足
        eventEndDate: null,     // 不足
        venue: null,            // 不足
        format: null,           // 不足
        cfpStartDate: null,
        cfpEndDate: null,       // 不足
        categories: [],         // 不足
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: ConferenceStatus::Draft,
    );
}

it('publish: 必須項目が揃った Draft は Published に昇格して index にリダイレクト', function () {
    // Given: Get が完全な Draft を返し、Update が status=Published で呼ばれる期待
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')->once()->with('draft-ready')->andReturn(publishReadyDraft());
    app()->instance(GetConferenceUseCase::class, $get);

    $captured = null;
    $update = Mockery::mock(UpdateConferenceUseCase::class);
    $update->shouldReceive('execute')
        ->once()
        ->with('draft-ready', Mockery::on(function (array $fields) use (&$captured): bool {
            $captured = $fields;

            return true;
        }))
        ->andReturn(publishReadyDraft());
    app()->instance(UpdateConferenceUseCase::class, $update);

    // When
    $response = $this->post('/admin/conferences/draft-ready/publish');

    // Then: 302 → index、success フラッシュ、UseCase に Published 単独更新が伝わる
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences');
    $response->assertSessionHas('status');
    expect(session('status'))->toContain('PHPカンファレンス2026');
    /** @var array<string, mixed> $captured */
    expect($captured)->toBeArray();
    expect($captured['status'])->toBe(ConferenceStatus::Published);
    expect($captured)->toHaveCount(1); // status のみ更新 (他フィールドは触らない)
});

it('publish: 必須項目欠落の Draft は edit に戻して error フラッシュ + Update 未呼出', function () {
    // Given: Get が不完全な Draft を返す
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')->once()->andReturn(publishUnreadyDraft());
    app()->instance(GetConferenceUseCase::class, $get);

    $update = Mockery::mock(UpdateConferenceUseCase::class);
    $update->shouldNotReceive('execute');
    app()->instance(UpdateConferenceUseCase::class, $update);

    // When
    $response = $this->post('/admin/conferences/draft-unready/publish');

    // Then: 302 → edit、error フラッシュに不足項目名が含まれる
    // (Issue #121: categories は不足項目から除外されたため期待しない)
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences/draft-unready/edit');
    $response->assertSessionHas('error');
    $errorMessage = session('error');
    assert(is_string($errorMessage));
    expect($errorMessage)->toContain('cfpUrl');
    expect($errorMessage)->toContain('eventStartDate');
    expect($errorMessage)->toContain('venue');
    expect($errorMessage)->toContain('format');
    expect($errorMessage)->toContain('cfpEndDate');
    expect($errorMessage)->not->toContain('categories');
});

it('publish: categories=[] でも他必須項目が揃っていれば Published 化できる (Issue #121)', function () {
    // Given: cfpUrl 等は揃っているが categories だけ空の Draft
    $draft = new Conference(
        conferenceId: 'draft-no-categories',
        name: 'カテゴリ未設定カンファ',
        trackName: null,
        officialUrl: 'https://no-cat.example.com',
        cfpUrl: 'https://no-cat.example.com/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: '2026-07-15',
        categories: [], // Issue #121: 空でも publish 可
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: ConferenceStatus::Draft,
    );
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')->once()->with('draft-no-categories')->andReturn($draft);
    app()->instance(GetConferenceUseCase::class, $get);

    $update = Mockery::mock(UpdateConferenceUseCase::class);
    $update->shouldReceive('execute')
        ->once()
        ->with('draft-no-categories', Mockery::on(function (array $fields): bool {
            return $fields['status'] === ConferenceStatus::Published;
        }))
        ->andReturn($draft);
    app()->instance(UpdateConferenceUseCase::class, $update);

    // When
    $response = $this->post('/admin/conferences/draft-no-categories/publish');

    // Then: 302 → index、success フラッシュ
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences');
    $response->assertSessionHas('status');
});

it('publish: 該当 Conference が無ければ 404', function () {
    // Given: Get が NotFound を投げる
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')->once()->andThrow(ConferenceNotFoundException::withId('missing'));
    app()->instance(GetConferenceUseCase::class, $get);

    $update = Mockery::mock(UpdateConferenceUseCase::class);
    $update->shouldNotReceive('execute');
    app()->instance(UpdateConferenceUseCase::class, $update);

    // When
    $response = $this->post('/admin/conferences/missing/publish');

    // Then: 404
    $response->assertStatus(404);
});
