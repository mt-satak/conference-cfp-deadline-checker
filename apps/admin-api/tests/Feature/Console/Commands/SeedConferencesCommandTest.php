<?php

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryRepository;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceStatus;
use Illuminate\Support\Facades\Artisan;

/**
 * conferences:seed コマンドの Feature テスト (Issue #40 Phase 1)。
 *
 * 検証範囲:
 * - JSON シードを読み込んで ConferenceRepository::save() を期待回数呼ぶ
 * - categorySlugs 配列 → CategoryRepository::findBySlug() で UUID 解決される
 * - status 'draft' 行は cfpUrl 等を欠落させても通る (Phase 0.5 連携)
 * - status 'published' 行は必須項目欠落で FAILURE (= 公開して安全な状態だけ投入)
 * - --dry-run / --source / 不正 JSON / 存在しないファイル
 *
 * Repository 群は Mockery で差し替え、IO のみ tmp ファイルで実物を使う。
 */
function conferencesSeedTmpDir(): string
{
    /** @var mixed $dir */
    $dir = test()->seedDir;
    assert(is_string($dir));

    return $dir;
}

beforeEach(function () {
    test()->seedDir = sys_get_temp_dir().'/cfp-conf-seed-test-'.bin2hex(random_bytes(4));
    mkdir(conferencesSeedTmpDir());
});

afterEach(function () {
    $dir = conferencesSeedTmpDir();
    if (is_dir($dir)) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

/**
 * @param  array<int, array<string, mixed>>  $conferences
 */
function writeConferencesSeedFile(array $conferences): string
{
    $path = conferencesSeedTmpDir().'/conferences.json';
    file_put_contents($path, json_encode(['conferences' => $conferences], JSON_UNESCAPED_UNICODE));

    return $path;
}

/**
 * categorySlugs 解決用の CategoryRepository モックを生成。
 * `slug → categoryId` マップから findBySlug を返す。
 *
 * @param  array<string, string>  $slugToId
 */
function bindCategoryRepoStub(array $slugToId): void
{
    $repo = Mockery::mock(CategoryRepository::class);
    foreach ($slugToId as $slug => $id) {
        $repo->shouldReceive('findBySlug')
            ->with($slug)
            ->andReturn(new Category(
                categoryId: $id,
                name: ucfirst($slug),
                slug: $slug,
                displayOrder: 100,
                axis: null,
                createdAt: '2026-01-01T00:00:00+09:00',
                updatedAt: '2026-01-01T00:00:00+09:00',
            ));
    }
    // 未知 slug は null
    $repo->shouldReceive('findBySlug')->andReturn(null);
    app()->instance(CategoryRepository::class, $repo);
}

it('JSON の各行を Conference として save する (categorySlugs を UUID に解決)', function () {
    // Given: Published 1 件 + Draft 1 件、CategoryRepository が slug を解決するモック
    $path = writeConferencesSeedFile([
        [
            'conferenceId' => '00000000-0000-4000-8000-000000000001',
            'name' => 'PHP Conference Japan 2026',
            'officialUrl' => 'https://phpcon.example.com/2026',
            'cfpUrl' => 'https://phpcon.example.com/2026/cfp',
            'eventStartDate' => '2026-07-20',
            'eventEndDate' => '2026-07-20',
            'venue' => '東京',
            'format' => 'offline',
            'cfpEndDate' => '2026-05-20',
            'categorySlugs' => ['php'],
            'status' => 'published',
        ],
        [
            'conferenceId' => '00000000-0000-4000-8000-000000000002',
            'name' => 'Draft 仮登録',
            'officialUrl' => 'https://draft.example.com',
            'categorySlugs' => [],
            'status' => 'draft',
        ],
    ]);

    bindCategoryRepoStub(['php' => 'cat-php-uuid']);

    /** @var array<int, Conference> $saved */
    $saved = [];
    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldReceive('save')
        ->twice()
        ->with(Mockery::on(function (Conference $c) use (&$saved): bool {
            $saved[] = $c;

            return true;
        }));
    app()->instance(ConferenceRepository::class, $repo);

    // When
    $exitCode = Artisan::call('conferences:seed', ['--source' => $path]);

    // Then
    expect($exitCode)->toBe(0);
    expect($saved)->toHaveCount(2);
    expect($saved[0]->name)->toBe('PHP Conference Japan 2026');
    expect($saved[0]->status)->toBe(ConferenceStatus::Published);
    expect($saved[0]->categories)->toBe(['cat-php-uuid']);
    expect($saved[0]->format)->toBe(ConferenceFormat::Offline);
    expect($saved[1]->name)->toBe('Draft 仮登録');
    expect($saved[1]->status)->toBe(ConferenceStatus::Draft);
    expect($saved[1]->cfpUrl)->toBeNull();
    expect($saved[1]->categories)->toBe([]);
});

it('--dry-run 指定時は save を呼ばず投入予定のみ表示する', function () {
    // Given
    $path = writeConferencesSeedFile([
        [
            'conferenceId' => '00000000-0000-4000-8000-000000000010',
            'name' => 'Dry-run カンファ',
            'officialUrl' => 'https://x.example.com',
            'categorySlugs' => [],
            'status' => 'draft',
        ],
    ]);
    bindCategoryRepoStub([]);
    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldNotReceive('save');
    app()->instance(ConferenceRepository::class, $repo);

    // When
    $exitCode = Artisan::call('conferences:seed', [
        '--source' => $path,
        '--dry-run' => true,
    ]);

    // Then
    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('would seed');
    expect($output)->toContain('Dry-run カンファ');
    expect($output)->toContain('1 conferences would seed');
});

it('存在しないファイルを --source に指定すると FAILURE', function () {
    // Given
    bindCategoryRepoStub([]);
    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldNotReceive('save');
    app()->instance(ConferenceRepository::class, $repo);

    // When
    $exitCode = Artisan::call('conferences:seed', [
        '--source' => conferencesSeedTmpDir().'/no-such-file.json',
    ]);

    // Then
    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('Seed file not found');
});

it('top-level conferences キー欠落で FAILURE', function () {
    // Given
    $path = conferencesSeedTmpDir().'/conferences.json';
    file_put_contents($path, json_encode(['items' => []]));
    bindCategoryRepoStub([]);
    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldNotReceive('save');
    app()->instance(ConferenceRepository::class, $repo);

    // When
    $exitCode = Artisan::call('conferences:seed', ['--source' => $path]);

    // Then
    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('top-level "conferences" array');
});

it('必須フィールド (name) 欠落で FAILURE', function () {
    // Given: name 欠落
    $path = writeConferencesSeedFile([
        [
            'conferenceId' => '00000000-0000-4000-8000-000000000020',
            // name 欠落
            'officialUrl' => 'https://x.example.com',
            'categorySlugs' => [],
            'status' => 'draft',
        ],
    ]);
    bindCategoryRepoStub([]);
    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldNotReceive('save');
    app()->instance(ConferenceRepository::class, $repo);

    // When
    $exitCode = Artisan::call('conferences:seed', ['--source' => $path]);

    // Then
    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('Missing or empty required field: name');
});

it('status=published で Published 必須項目 (cfpEndDate 等) が欠落していると FAILURE', function () {
    // Given: status=published だが cfpEndDate と venue が欠落
    $path = writeConferencesSeedFile([
        [
            'conferenceId' => '00000000-0000-4000-8000-000000000030',
            'name' => 'Bad Published',
            'officialUrl' => 'https://x.example.com',
            'cfpUrl' => 'https://x.example.com/cfp',
            'eventStartDate' => '2026-09-01',
            'eventEndDate' => '2026-09-01',
            // venue / format / cfpEndDate / categorySlugs 欠落
            'categorySlugs' => [],
            'status' => 'published',
        ],
    ]);
    bindCategoryRepoStub([]);
    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldNotReceive('save');
    app()->instance(ConferenceRepository::class, $repo);

    // When
    $exitCode = Artisan::call('conferences:seed', ['--source' => $path]);

    // Then: Published 必須項目欠落エラー
    $exitOutput = Artisan::output();
    expect($exitCode)->toBe(1);
    expect($exitOutput)->toContain('Published');
    expect($exitOutput)->toContain('venue');
});

it('未知の category slug を含むと FAILURE', function () {
    // Given: 知らない slug を指定
    $path = writeConferencesSeedFile([
        [
            'conferenceId' => '00000000-0000-4000-8000-000000000040',
            'name' => '不明スラッグ',
            'officialUrl' => 'https://x.example.com',
            'categorySlugs' => ['unknown-slug'],
            'status' => 'draft',
        ],
    ]);
    bindCategoryRepoStub([]); // 何も解決できないモック
    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldNotReceive('save');
    app()->instance(ConferenceRepository::class, $repo);

    // When
    $exitCode = Artisan::call('conferences:seed', ['--source' => $path]);

    // Then
    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('Unknown category slug: unknown-slug');
});

it('既定パスの data/seeds/conferences.json を読み込める (存在する想定)', function () {
    // Given: 既定パス (data/seeds/conferences.json) が存在し、リポジトリで投入される想定。
    // 件数は seed JSON 側の追加で増減するため「>= 1 件」のみ確認。
    // categorySlugs の解決は data/seeds/categories.json の全 slug を網羅する形でモック
    // (実種データで未知 slug が出ても落ちないように)。
    $defaultPath = base_path('../../data/seeds/conferences.json');
    if (! is_readable($defaultPath)) {
        test()->markTestSkipped('default seed file not present yet');
    }

    $categoriesJson = file_get_contents(base_path('../../data/seeds/categories.json'));
    /** @var array{categories: array<int, array{slug: string}>} $categoriesData */
    $categoriesData = json_decode($categoriesJson === false ? '{}' : $categoriesJson, true);
    $slugMap = [];
    foreach ($categoriesData['categories'] as $cat) {
        $slugMap[$cat['slug']] = 'cat-'.$cat['slug'];
    }
    bindCategoryRepoStub($slugMap);

    $repo = Mockery::mock(ConferenceRepository::class);
    $repo->shouldReceive('save')->atLeast()->once();
    app()->instance(ConferenceRepository::class, $repo);

    // When
    $exitCode = Artisan::call('conferences:seed');

    // Then
    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toMatch('/\d+ conferences seeded/');
});
