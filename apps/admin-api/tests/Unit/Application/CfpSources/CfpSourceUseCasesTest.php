<?php

declare(strict_types=1);

use App\Application\CfpSources\CreateCfpSourceInput;
use App\Application\CfpSources\CreateCfpSourceUseCase;
use App\Application\CfpSources\DeleteCfpSourceUseCase;
use App\Application\CfpSources\GetCfpSourceUseCase;
use App\Application\CfpSources\ListCfpSourcesUseCase;
use App\Application\CfpSources\UpdateCfpSourceUseCase;
use App\Domain\CfpSources\CfpSource;
use App\Domain\CfpSources\CfpSourceConflictException;
use App\Domain\CfpSources\CfpSourceNotFoundException;
use App\Domain\CfpSources\CfpSourceRepository;

/**
 * CfP ソース系 UseCase 単体テスト (Issue #200 PR-1)。
 *
 * 5 UseCase (List / Get / Create / Update / Delete) を 1 ファイルで網羅する。
 * Categories と同パターン。
 */
function makeSource(string $id, string $name, string $url, bool $enabled = true, string $createdAt = '2026-05-15T09:00:00+09:00'): CfpSource
{
    return new CfpSource(
        sourceId: $id,
        name: $name,
        url: $url,
        enabled: $enabled,
        createdAt: $createdAt,
        updatedAt: $createdAt,
    );
}

// ── ListCfpSourcesUseCase ──

it('List は Repository の全件を createdAt 昇順 (= 追加順) で返す', function () {
    // Given: createdAt 順が崩れた 2 件
    $newer = makeSource('s-2', 'B', 'https://b.example.com/', true, '2026-05-15T11:00:00+09:00');
    $older = makeSource('s-1', 'A', 'https://a.example.com/', true, '2026-05-15T09:00:00+09:00');
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findAll')->once()->andReturn([$newer, $older]);

    // When
    $result = (new ListCfpSourcesUseCase($repo))->execute();

    // Then: createdAt 昇順
    expect($result)->toHaveCount(2);
    expect($result[0]->sourceId)->toBe('s-1');
    expect($result[1]->sourceId)->toBe('s-2');
});

it('List は 0 件で空配列を返す', function () {
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findAll')->once()->andReturn([]);

    expect((new ListCfpSourcesUseCase($repo))->execute())->toBe([]);
});

// ── GetCfpSourceUseCase ──

it('Get は findById を呼んで CfpSource を返す', function () {
    // Given
    $source = makeSource('s-1', 'fortee', 'https://fortee.jp/events');
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findById')->once()->with('s-1')->andReturn($source);

    // When
    $result = (new GetCfpSourceUseCase($repo))->execute('s-1');

    // Then
    expect($result->sourceId)->toBe('s-1');
});

it('Get は該当無しなら CfpSourceNotFoundException', function () {
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findById')->once()->andReturn(null);

    (new GetCfpSourceUseCase($repo))->execute('missing');
})->throws(CfpSourceNotFoundException::class);

// ── CreateCfpSourceUseCase ──

it('Create は url 重複なしなら新規 UUID + 現在時刻で save する', function () {
    // Given
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findByUrl')->once()->with('https://fortee.jp/events')->andReturn(null);

    $saved = null;
    $repo->shouldReceive('save')
        ->once()
        ->with(Mockery::on(function (CfpSource $s) use (&$saved) {
            $saved = $s;

            return true;
        }));

    // When
    $result = (new CreateCfpSourceUseCase($repo))->execute(
        new CreateCfpSourceInput(name: 'fortee', url: 'https://fortee.jp/events', enabled: true),
    );

    // Then
    /** @var CfpSource $saved */
    expect($saved->sourceId)->not->toBe('');
    expect($saved->name)->toBe('fortee');
    expect($saved->url)->toBe('https://fortee.jp/events');
    expect($saved->enabled)->toBeTrue();
    expect($saved->createdAt)->toBe($saved->updatedAt);  // 新規時は同値
    expect($result->sourceId)->toBe($saved->sourceId);
});

it('Create は同 url の source が既存なら CfpSourceConflictException', function () {
    // Given: 既存 source が同 URL
    $existing = makeSource('existing', 'fortee', 'https://fortee.jp/events');
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findByUrl')->once()->andReturn($existing);
    $repo->shouldNotReceive('save');

    (new CreateCfpSourceUseCase($repo))->execute(
        new CreateCfpSourceInput(name: 'duplicate', url: 'https://fortee.jp/events', enabled: true),
    );
})->throws(CfpSourceConflictException::class);

// ── UpdateCfpSourceUseCase ──

it('Update は部分更新で指定キーのみ書き換え (= name 変更時に url/enabled は維持)', function () {
    // Given
    $existing = makeSource('s-1', 'old name', 'https://fortee.jp/events', true);
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findById')->once()->with('s-1')->andReturn($existing);

    $saved = null;
    $repo->shouldReceive('save')
        ->once()
        ->with(Mockery::on(function (CfpSource $s) use (&$saved) {
            $saved = $s;

            return true;
        }));

    // When: name のみ更新
    (new UpdateCfpSourceUseCase($repo))->execute('s-1', ['name' => 'new name']);

    // Then
    /** @var CfpSource $saved */
    expect($saved->name)->toBe('new name');
    expect($saved->url)->toBe('https://fortee.jp/events');  // 維持
    expect($saved->enabled)->toBeTrue();  // 維持
    expect($saved->updatedAt)->not->toBe($existing->updatedAt);  // 更新
});

it('Update は enabled トグルを反映する', function () {
    // Given
    $existing = makeSource('s-1', 'fortee', 'https://fortee.jp/events', true);
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findById')->once()->andReturn($existing);

    $saved = null;
    $repo->shouldReceive('save')
        ->once()
        ->with(Mockery::on(function (CfpSource $s) use (&$saved) {
            $saved = $s;

            return true;
        }));

    // When: enabled=false (= 一時無効化)
    (new UpdateCfpSourceUseCase($repo))->execute('s-1', ['enabled' => false]);

    // Then
    /** @var CfpSource $saved */
    expect($saved->enabled)->toBeFalse();
});

it('Update は該当無しなら CfpSourceNotFoundException', function () {
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findById')->once()->andReturn(null);
    $repo->shouldNotReceive('save');

    (new UpdateCfpSourceUseCase($repo))->execute('missing', ['name' => 'x']);
})->throws(CfpSourceNotFoundException::class);

it('Update で url 変更時は他 source との重複チェックする (= 自身は除外)', function () {
    // Given: 自分 (s-1) + 同じ URL の他 source (s-2) が既に居る
    $existing = makeSource('s-1', 'fortee', 'https://fortee.jp/events', true);
    $conflict = makeSource('s-2', 'connpass', 'https://other.example.com/');
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findById')->once()->andReturn($existing);
    $repo->shouldReceive('findByUrl')->once()->with('https://other.example.com/')->andReturn($conflict);
    $repo->shouldNotReceive('save');

    // When/Then: 他 source の URL に変えようとすると conflict
    (new UpdateCfpSourceUseCase($repo))->execute('s-1', ['url' => 'https://other.example.com/']);
})->throws(CfpSourceConflictException::class);

it('Update で url を自身と同じ値に維持する場合は重複チェックを skip して save 成功', function () {
    // Given: 同じ URL で他フィールドのみ変更
    $existing = makeSource('s-1', 'fortee', 'https://fortee.jp/events', true);
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('findById')->once()->andReturn($existing);
    // 同値なので findByUrl は呼ばれない
    $repo->shouldNotReceive('findByUrl');
    $repo->shouldReceive('save')->once();

    // When/Then: 例外なし
    (new UpdateCfpSourceUseCase($repo))->execute('s-1', [
        'url' => 'https://fortee.jp/events',
        'name' => 'rename',
    ]);

    expect(true)->toBeTrue();
});

// ── DeleteCfpSourceUseCase ──

it('Delete は deleteById 成功で void return', function () {
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('deleteById')->once()->with('s-1')->andReturn(true);

    (new DeleteCfpSourceUseCase($repo))->execute('s-1');
    expect(true)->toBeTrue();
});

it('Delete は deleteById が false なら CfpSourceNotFoundException', function () {
    $repo = Mockery::mock(CfpSourceRepository::class);
    $repo->shouldReceive('deleteById')->once()->andReturn(false);

    (new DeleteCfpSourceUseCase($repo))->execute('missing');
})->throws(CfpSourceNotFoundException::class);
