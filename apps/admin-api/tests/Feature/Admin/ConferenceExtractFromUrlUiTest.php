<?php

use App\Application\Categories\ListCategoriesUseCase;
use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\ExtractConferenceDraftUseCase;
use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryRepository;
use App\Domain\Conferences\ConferenceFormat;
use App\Http\Middleware\VerifyOrigin;

/**
 * POST /admin/conferences/extract-from-url の Feature テスト (Issue #40 Phase 3 PR-3)。
 *
 * シナリオ:
 * - 正常: ExtractConferenceDraftUseCase が ConferenceDraft を返す
 *   → create 画面にリダイレクト + フォーム値が old() で復元される
 * - URL 不正 (http / 形式違反): バリデーション 422 → create に戻る
 * - HtmlFetchFailedException: error フラッシュ + create に戻る
 * - LlmExtractionFailedException: error フラッシュ + create に戻る
 * - categorySlugs → categoryId 解決: CategoryRepository::findBySlug() で UUID 変換
 *   見つからない slug は無視される (= LLM 推測値の defensive 扱い)
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

function bindExtractCategoriesStubs(): void
{
    // create 画面の view 用 (一覧表示) と extractFromUrl の slug→id 解決用、両方同じ Category[] を返す
    $list = Mockery::mock(ListCategoriesUseCase::class);
    $list->shouldReceive('execute')->andReturn([
        new Category('cat-php', 'PHP', 'php', 100, CategoryAxis::A, '', ''),
        new Category('cat-frontend', 'フロントエンド', 'frontend', 400, CategoryAxis::C, '', ''),
    ]);
    app()->instance(ListCategoriesUseCase::class, $list);

    $repo = Mockery::mock(CategoryRepository::class);
    $repo->shouldReceive('findBySlug')->with('php')->andReturn(
        new Category('cat-php', 'PHP', 'php', 100, CategoryAxis::A, '', ''),
    );
    $repo->shouldReceive('findBySlug')->with('frontend')->andReturn(
        new Category('cat-frontend', 'フロントエンド', 'frontend', 400, CategoryAxis::C, '', ''),
    );
    $repo->shouldReceive('findBySlug')->andReturn(null); // その他は null
    app()->instance(CategoryRepository::class, $repo);
}

it('正常な URL を投げると ConferenceDraft が old() に flash されて create にリダイレクトする', function () {
    // Given
    bindExtractCategoriesStubs();
    $useCase = Mockery::mock(ExtractConferenceDraftUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with('https://phpcon.example.com/2026')
        ->andReturn(new ConferenceDraft(
            sourceUrl: 'https://phpcon.example.com/2026',
            name: 'PHP Conference Japan 2026',
            officialUrl: 'https://phpcon.example.com/2026',
            cfpUrl: 'https://fortee.jp/phpcon-2026/cfp',
            eventStartDate: '2026-07-20',
            eventEndDate: '2026-07-20',
            venue: '大田区産業プラザ PiO',
            format: ConferenceFormat::Offline,
            cfpStartDate: null,
            cfpEndDate: '2026-05-20',
            categorySlugs: ['php'],
            description: '国内最大の PHP カンファレンス',
            themeColor: '#777BB4',
        ));
    app()->instance(ExtractConferenceDraftUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/extract-from-url', [
        'url' => 'https://phpcon.example.com/2026',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences/create');
    $response->assertSessionHas('status'); // success フラッシュ
    // old() で復元される値を確認 (= Laravel が flash した _old_input をテストから読む)
    expect(session('_old_input.name'))->toBe('PHP Conference Japan 2026');
    expect(session('_old_input.cfpUrl'))->toBe('https://fortee.jp/phpcon-2026/cfp');
    expect(session('_old_input.format'))->toBe('offline');
    expect(session('_old_input.cfpEndDate'))->toBe('2026-05-20');
    // categorySlugs → categoryIds に解決されている
    expect(session('_old_input.categories'))->toBe(['cat-php']);
});

it('カテゴリ slug が見つからない場合は除外される (defensive)', function () {
    // Given: LLM が 'unknown-slug' を返してくる
    bindExtractCategoriesStubs();
    $useCase = Mockery::mock(ExtractConferenceDraftUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn(new ConferenceDraft(
        sourceUrl: 'https://x.example.com',
        name: 'X Conf',
        categorySlugs: ['php', 'unknown-slug', 'frontend'],
    ));
    app()->instance(ExtractConferenceDraftUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/extract-from-url', [
        'url' => 'https://x.example.com',
    ]);

    // Then: unknown-slug は除外され、php と frontend だけ
    $response->assertStatus(302);
    expect(session('_old_input.categories'))->toBe(['cat-php', 'cat-frontend']);
});

it('http:// は URL バリデーションで弾かれる (= UseCase 未呼出)', function () {
    // Given
    bindExtractCategoriesStubs();
    $useCase = Mockery::mock(ExtractConferenceDraftUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(ExtractConferenceDraftUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/extract-from-url', [
        'url' => 'http://insecure.example.com',
    ]);

    // Then: バリデーション失敗で 302、errors に url
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['url']);
});

it('URL 形式不正は弾かれる', function () {
    // Given
    bindExtractCategoriesStubs();
    $useCase = Mockery::mock(ExtractConferenceDraftUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(ExtractConferenceDraftUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/extract-from-url', ['url' => 'not-a-url']);

    // Then
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['url']);
});

it('HtmlFetchFailedException が発生したら error フラッシュで create に戻る', function () {
    // Given
    bindExtractCategoriesStubs();
    $useCase = Mockery::mock(ExtractConferenceDraftUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(HtmlFetchFailedException::statusError('https://x.example.com', 404));
    app()->instance(ExtractConferenceDraftUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/extract-from-url', [
        'url' => 'https://x.example.com',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences/create');
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('取り込み');
});

it('LlmExtractionFailedException が発生したら error フラッシュで create に戻る', function () {
    // Given
    bindExtractCategoriesStubs();
    $useCase = Mockery::mock(ExtractConferenceDraftUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(LlmExtractionFailedException::quotaExceeded('https://x.example.com'));
    app()->instance(ExtractConferenceDraftUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/extract-from-url', [
        'url' => 'https://x.example.com',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertSessionHas('error');
});
