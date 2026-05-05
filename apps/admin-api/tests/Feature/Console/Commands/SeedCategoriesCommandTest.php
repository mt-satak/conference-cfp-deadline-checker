<?php

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Categories\CategoryRepository;
use Illuminate\Support\Facades\Artisan;

/**
 * categories:seed コマンドの Feature テスト。
 *
 * 検証範囲:
 * - JSON シードを読み込んで CategoryRepository::save() を期待回数呼ぶ
 * - axis の有無を含めて Category Entity に正しく変換される
 * - --dry-run / --source オプションが期待通りに作用する
 * - 不正入力 (壊れた JSON、必須フィールド欠落、ファイル無し) で FAILURE
 *
 * Repository は Mockery で差し替え、IO のみ tmp ファイルで実物を使う。
 */
/**
 * テスト用 tmp ディレクトリのパスを返す (test() の動的プロパティ経由だと
 * PHPStan が mixed と推論するため、明示的に string にして返すヘルパ)。
 */
function seedTmpDir(): string
{
    /** @var mixed $dir */
    $dir = test()->seedDir;
    assert(is_string($dir));

    return $dir;
}

beforeEach(function () {
    test()->seedDir = sys_get_temp_dir().'/cfp-seed-test-'.bin2hex(random_bytes(4));
    mkdir(seedTmpDir());
});

afterEach(function () {
    // tmp ファイルを掃除 (テスト毎にユニークなディレクトリにしてあるので rm -rf 相当で OK)
    $dir = seedTmpDir();
    if (is_dir($dir)) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

/**
 * テスト用 seed JSON を tmp に書き出してパスを返す。
 *
 * @param  array<int, array<string, mixed>>  $categories
 */
function writeSeedFile(array $categories): string
{
    $path = seedTmpDir().'/categories.json';
    file_put_contents($path, json_encode(['categories' => $categories], JSON_UNESCAPED_UNICODE));

    return $path;
}

it('JSON の各行を Category として save する', function () {
    // Given: 2 件 (axis あり / 無し) のシード
    $path = writeSeedFile([
        [
            'categoryId' => 'id-1',
            'name' => 'PHP',
            'slug' => 'php',
            'displayOrder' => 100,
            'axis' => 'A',
        ],
        [
            'categoryId' => 'id-2',
            'name' => 'Domain Driven Design',
            'slug' => 'ddd',
            'displayOrder' => 410,
            // axis 無し
        ],
    ]);

    $repository = Mockery::mock(CategoryRepository::class);
    $saved = [];
    $repository->shouldReceive('save')
        ->twice()
        ->with(Mockery::on(function (Category $category) use (&$saved): bool {
            $saved[] = $category;

            return true;
        }));
    app()->instance(CategoryRepository::class, $repository);

    // When
    $exitCode = Artisan::call('categories:seed', ['--source' => $path]);

    // Then
    expect($exitCode)->toBe(0);
    expect($saved)->toHaveCount(2);
    expect($saved[0]->categoryId)->toBe('id-1');
    expect($saved[0]->name)->toBe('PHP');
    expect($saved[0]->slug)->toBe('php');
    expect($saved[0]->displayOrder)->toBe(100);
    expect($saved[0]->axis)->toBe(CategoryAxis::A);
    expect($saved[1]->axis)->toBeNull();
    expect($saved[1]->displayOrder)->toBe(410);
    expect(Artisan::output())->toContain('2 categories seeded');
});

it('--dry-run 指定時は save を呼ばず投入予定のみ表示する', function () {
    // Given
    $path = writeSeedFile([
        [
            'categoryId' => 'id-1',
            'name' => 'Go',
            'slug' => 'go',
            'displayOrder' => 110,
            'axis' => 'A',
        ],
    ]);

    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldNotReceive('save');
    app()->instance(CategoryRepository::class, $repository);

    // When
    $exitCode = Artisan::call('categories:seed', [
        '--source' => $path,
        '--dry-run' => true,
    ]);

    // Then
    expect($exitCode)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('would seed');
    expect($output)->toContain('Go');
    expect($output)->toContain('1 categories would seed');
});

it('存在しないファイルを --source に指定すると FAILURE で終わる', function () {
    // Given: file_exists しない path
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldNotReceive('save');
    app()->instance(CategoryRepository::class, $repository);

    // When
    $exitCode = Artisan::call('categories:seed', [
        '--source' => seedTmpDir().'/does-not-exist.json',
    ]);

    // Then
    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('Seed file not found');
});

it('categories キーが無い JSON は FAILURE で終わる', function () {
    // Given: top-level に categories が無い
    $path = seedTmpDir().'/categories.json';
    file_put_contents($path, json_encode(['items' => []]));

    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldNotReceive('save');
    app()->instance(CategoryRepository::class, $repository);

    // When
    $exitCode = Artisan::call('categories:seed', ['--source' => $path]);

    // Then
    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('top-level "categories" array');
});

it('必須フィールド (name) が空文字だと FAILURE で終わる', function () {
    // Given: name が空
    $path = writeSeedFile([
        [
            'categoryId' => 'id-1',
            'name' => '',
            'slug' => 'php',
            'displayOrder' => 100,
        ],
    ]);

    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldNotReceive('save');
    app()->instance(CategoryRepository::class, $repository);

    // When
    $exitCode = Artisan::call('categories:seed', ['--source' => $path]);

    // Then
    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('Missing or empty required field: name');
});

it('displayOrder が int でないと FAILURE で終わる', function () {
    // Given: displayOrder が文字列
    $path = writeSeedFile([
        [
            'categoryId' => 'id-1',
            'name' => 'PHP',
            'slug' => 'php',
            'displayOrder' => '100',
        ],
    ]);

    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldNotReceive('save');
    app()->instance(CategoryRepository::class, $repository);

    // When
    $exitCode = Artisan::call('categories:seed', ['--source' => $path]);

    // Then
    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('displayOrder must be an integer');
});

it('未知の axis 値は無視され null として登録される', function () {
    // Given: axis に enum 外の値
    $path = writeSeedFile([
        [
            'categoryId' => 'id-1',
            'name' => 'PHP',
            'slug' => 'php',
            'displayOrder' => 100,
            'axis' => 'Z', // CategoryAxis::tryFrom() は null を返す
        ],
    ]);

    $repository = Mockery::mock(CategoryRepository::class);
    /** @var array<int, Category> $saved */
    $saved = [];
    $repository->shouldReceive('save')
        ->once()
        ->with(Mockery::on(function (Category $category) use (&$saved): bool {
            $saved[] = $category;

            return true;
        }));
    app()->instance(CategoryRepository::class, $repository);

    // When
    $exitCode = Artisan::call('categories:seed', ['--source' => $path]);

    // Then
    expect($exitCode)->toBe(0);
    expect($saved)->toHaveCount(1);
    expect($saved[0]->axis)->toBeNull();
});

it('既定パスで data/seeds/categories.json を読み込めて 34 件投入する', function () {
    // Given: --source 未指定 → リポジトリ同梱の data/seeds/categories.json (34 件) を読む想定
    $repository = Mockery::mock(CategoryRepository::class);
    $repository->shouldReceive('save')->times(34);
    app()->instance(CategoryRepository::class, $repository);

    // When
    $exitCode = Artisan::call('categories:seed');

    // Then
    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('34 categories seeded');
});
