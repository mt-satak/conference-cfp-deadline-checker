<?php

use App\Application\Categories\ListCategoriesUseCase;
use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceStatus;
use App\Http\Middleware\VerifyOrigin;

/**
 * /admin/conferences/{id}/edit + PUT /admin/conferences/{id} の Feature テスト。
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

function makeEditUiSampleConference(string $id = '550e8400-e29b-41d4-a716-446655440000'): Conference
{
    return new Conference(
        conferenceId: $id,
        name: 'PHPカンファレンス2026',
        trackName: '一般 CfP',
        officialUrl: 'https://example.com',
        cfpUrl: 'https://example.com/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: '2026-05-01',
        cfpEndDate: '2026-07-15',
        categories: ['cat-1'],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );
}

function bindEditCategoriesUseCaseStub(): void
{
    $list = Mockery::mock(ListCategoriesUseCase::class);
    $list->shouldReceive('execute')->andReturn([
        new Category('cat-1', 'PHP', 'php', 100, CategoryAxis::A, '', ''),
        new Category('cat-2', 'Python', 'python', 200, CategoryAxis::A, '', ''),
    ]);
    app()->instance(ListCategoriesUseCase::class, $list);
}

it('GET /admin/conferences/{id}/edit はフォーム + 既存値 + 削除セクションを返す', function () {
    // Given
    bindEditCategoriesUseCaseStub();
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')->once()->andReturn(makeEditUiSampleConference());
    app()->instance(GetConferenceUseCase::class, $get);

    // When
    $response = $this->get('/admin/conferences/550e8400-e29b-41d4-a716-446655440000/edit');

    // Then
    $response->assertStatus(200);
    $response->assertSee('カンファレンス編集', false);
    $response->assertSee('value="PHPカンファレンス2026"', false);  // name の既存値
    $response->assertSee('value="2026-07-15"', false);  // cfpEndDate
    $response->assertSee('削除する', false);  // 削除セクション
});

it('GET /admin/conferences/{id}/edit は該当無しなら 404', function () {
    // Given
    bindEditCategoriesUseCaseStub();
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')
        ->once()
        ->andThrow(ConferenceNotFoundException::withId('missing'));
    app()->instance(GetConferenceUseCase::class, $get);

    // When
    $response = $this->get('/admin/conferences/missing/edit');

    // Then
    $response->assertStatus(404);
});

it('PUT /admin/conferences/{id} は成功時に index にリダイレクト + フラッシュ', function () {
    // Given
    bindEditCategoriesUseCaseStub();
    $update = Mockery::mock(UpdateConferenceUseCase::class);
    $update->shouldReceive('execute')
        ->once()
        ->andReturn(makeEditUiSampleConference());
    app()->instance(UpdateConferenceUseCase::class, $update);

    // When
    $response = $this->put('/admin/conferences/550e8400-e29b-41d4-a716-446655440000', [
        'name' => 'PHPカンファレンス2026 改',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences');
    $response->assertSessionHas('status');
});

it('PUT /admin/conferences/{id} は該当無しなら 404', function () {
    // Given
    bindEditCategoriesUseCaseStub();
    $update = Mockery::mock(UpdateConferenceUseCase::class);
    $update->shouldReceive('execute')
        ->once()
        ->andThrow(ConferenceNotFoundException::withId('missing'));
    app()->instance(UpdateConferenceUseCase::class, $update);

    // When
    $response = $this->put('/admin/conferences/missing', ['name' => 'X']);

    // Then
    $response->assertStatus(404);
});

it('GET edit は Published 状態のバッジを表示する (Phase 0.5)', function () {
    // Given
    bindEditCategoriesUseCaseStub();
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')->once()->andReturn(makeEditUiSampleConference());
    app()->instance(GetConferenceUseCase::class, $get);

    // When
    $response = $this->get('/admin/conferences/550e8400-e29b-41d4-a716-446655440000/edit');

    // Then
    $response->assertStatus(200);
    $response->assertSee('公開中', false);
});

it('GET edit は Draft 状態のバッジを表示する', function () {
    // Given: Draft の Conference
    bindEditCategoriesUseCaseStub();
    $draft = new Conference(
        conferenceId: 'draft-id',
        name: 'Draft カンファ',
        trackName: null,
        officialUrl: 'https://draft.example.com',
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
    $get = Mockery::mock(GetConferenceUseCase::class);
    $get->shouldReceive('execute')->once()->andReturn($draft);
    app()->instance(GetConferenceUseCase::class, $get);

    // When
    $response = $this->get('/admin/conferences/draft-id/edit');

    // Then
    $response->assertStatus(200);
    $response->assertSee('下書き', false);
});

it('PUT /admin/conferences/{id} で status を published に更新できる (Phase 0.5)', function () {
    // Given: status=published 送信時に UseCase に enum で渡る
    bindEditCategoriesUseCaseStub();
    $captured = null;
    $update = Mockery::mock(UpdateConferenceUseCase::class);
    $update->shouldReceive('execute')
        ->once()
        ->with('id-1', Mockery::on(function (array $fields) use (&$captured): bool {
            $captured = $fields;

            return true;
        }))
        ->andReturn(makeEditUiSampleConference());
    app()->instance(UpdateConferenceUseCase::class, $update);

    // When
    $response = $this->put('/admin/conferences/id-1', ['status' => 'published']);

    // Then
    $response->assertStatus(302);
    /** @var array<string, mixed> $captured */
    expect($captured)->toBeArray();
    expect($captured['status'])->toBe(ConferenceStatus::Published);
});
