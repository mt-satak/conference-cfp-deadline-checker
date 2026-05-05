<?php

use App\Application\Categories\ListCategoriesUseCase;
use App\Application\Conferences\CreateConferenceInput;
use App\Application\Conferences\CreateConferenceUseCase;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Http\Middleware\VerifyOrigin;

/**
 * /admin/conferences/{create,store} の Blade SSR Feature テスト。
 */
beforeEach(function () {
    test()->withoutVite();
    // POST 系は VerifyOrigin が Origin / Referer 検証を行うが、UI テスト時は素通しする
    test()->withoutMiddleware(VerifyOrigin::class);
});

function makeUiCategoryStub(string $id, string $name, int $order = 100): Category
{
    return new Category(
        categoryId: $id,
        name: $name,
        slug: strtolower($name),
        displayOrder: $order,
        axis: CategoryAxis::A,
        createdAt: '2026-01-01T00:00:00+09:00',
        updatedAt: '2026-01-01T00:00:00+09:00',
    );
}

function bindListCategoriesUseCaseStub(array $categories): void
{
    $listMock = Mockery::mock(ListCategoriesUseCase::class);
    $listMock->shouldReceive('execute')->andReturn($categories);
    app()->instance(ListCategoriesUseCase::class, $listMock);
}

it('GET /admin/conferences/create はフォームを 200 で返す', function () {
    // Given: カテゴリ 2 件 (フォームの選択肢として表示される)
    bindListCategoriesUseCaseStub([
        makeUiCategoryStub('cat-1', 'PHP'),
        makeUiCategoryStub('cat-2', 'Python'),
    ]);

    // When
    $response = $this->get('/admin/conferences/create');

    // Then
    $response->assertStatus(200);
    $response->assertSee('カンファレンス新規作成', false);
    $response->assertSee('PHP', false);    // カテゴリ checkbox label
    $response->assertSee('Python', false);
    $response->assertSee('name="name"', false);
    $response->assertSee('name="categories[]"', false);
});

it('POST /admin/conferences は成功時に index にリダイレクト + フラッシュ', function () {
    // Given: UseCase が Conference を返すモック
    bindListCategoriesUseCaseStub([]);
    $useCase = Mockery::mock(CreateConferenceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(Mockery::type(CreateConferenceInput::class))
        ->andReturn(new Conference(
            conferenceId: 'aaa',
            name: 'PHPカンファレンス2026',
            trackName: null,
            officialUrl: 'https://example.com',
            cfpUrl: 'https://example.com/cfp',
            eventStartDate: '2026-09-19',
            eventEndDate: '2026-09-20',
            venue: '東京',
            format: ConferenceFormat::Offline,
            cfpStartDate: null,
            cfpEndDate: '2026-07-15',
            categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
            description: null,
            themeColor: null,
            createdAt: '2026-05-04T10:00:00+09:00',
            updatedAt: '2026-05-04T10:00:00+09:00',
        ));
    app()->instance(CreateConferenceUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences', [
        'name' => 'PHPカンファレンス2026',
        'officialUrl' => 'https://example.com',
        'cfpUrl' => 'https://example.com/cfp',
        'eventStartDate' => '2026-09-19',
        'eventEndDate' => '2026-09-20',
        'venue' => '東京',
        'format' => 'offline',
        'cfpEndDate' => '2026-07-15',
        'categories' => ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
    ]);

    // Then: 302 redirect to index + flash message
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences');
    $response->assertSessionHas('status');
    expect(session('status'))->toContain('PHPカンファレンス2026');
});

it('POST /admin/conferences はバリデーション違反時に 422/302 で戻り、errors を flash', function () {
    // Given: 必須項目欠落 (name 等)
    bindListCategoriesUseCaseStub([]);

    // When
    $response = $this->post('/admin/conferences', []);

    // Then: 302 with validation errors flashed
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['name', 'officialUrl', 'cfpUrl', 'eventStartDate', 'venue', 'format']);
});
