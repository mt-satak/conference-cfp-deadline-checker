<?php

use App\Application\Categories\ListCategoriesUseCase;
use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
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
