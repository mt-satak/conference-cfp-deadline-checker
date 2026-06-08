<?php

declare(strict_types=1);

use App\Application\CfpSources\CreateCfpSourceUseCase;
use App\Application\CfpSources\DeleteCfpSourceUseCase;
use App\Application\CfpSources\GetCfpSourceUseCase;
use App\Application\CfpSources\ListCfpSourcesUseCase;
use App\Application\CfpSources\UpdateCfpSourceUseCase;
use App\Domain\CfpSources\CfpSource;
use App\Domain\CfpSources\CfpSourceConflictException;
use App\Domain\CfpSources\CfpSourceNotFoundException;
use App\Http\Middleware\VerifyOrigin;

/**
 * Admin CfP ソース管理 UI の Feature テスト (Issue #200 PR-1)。
 *
 * 一覧 / 作成 / 編集 / 削除 / バリデーション / コンフリクト / 404 を網羅。
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

function uiCfpSource(string $id, string $name, string $url, bool $enabled = true): CfpSource
{
    return new CfpSource(
        sourceId: $id,
        name: $name,
        url: $url,
        enabled: $enabled,
        createdAt: '2026-05-15T09:00:00+09:00',
        updatedAt: '2026-05-15T09:00:00+09:00',
    );
}

// ── Index ──

it('GET /admin/cfp-sources は 200 で一覧を表示する', function () {
    // Given
    $useCase = Mockery::mock(ListCfpSourcesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([
        uiCfpSource('s-1', 'fortee', 'https://fortee.jp/events', true),
        uiCfpSource('s-2', 'connpass', 'https://connpass.com/explore?keyword=tech', false),
    ]);
    app()->instance(ListCfpSourcesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/cfp-sources');

    // Then
    $response->assertStatus(200);
    $response->assertSee('fortee', false);
    $response->assertSee('connpass', false);
    $response->assertSee('https://fortee.jp/events', false);
    $response->assertSee('有効', false);
    $response->assertSee('無効', false);
    $response->assertSee('2 件', false);
});

it('GET /admin/cfp-sources は 0 件で empty state を表示する', function () {
    // Given
    $useCase = Mockery::mock(ListCfpSourcesUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn([]);
    app()->instance(ListCfpSourcesUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/cfp-sources');

    // Then
    $response->assertStatus(200);
    $response->assertSee('登録された CfP ソースがありません', false);
});

// ── Create ──

it('GET /admin/cfp-sources/create は 200 でフォームを表示する', function () {
    $response = $this->get('/admin/cfp-sources/create');

    $response->assertStatus(200);
    $response->assertSee('CfP ソース新規作成', false);
    $response->assertSee('name="name"', false);
    $response->assertSee('name="url"', false);
});

it('POST /admin/cfp-sources は UseCase を呼んで index にリダイレクト + flash', function () {
    // Given
    $useCase = Mockery::mock(CreateCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andReturn(uiCfpSource('new-id', 'fortee', 'https://fortee.jp/events'));
    app()->instance(CreateCfpSourceUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/cfp-sources', [
        'name' => 'fortee',
        'url' => 'https://fortee.jp/events',
        'enabled' => '1',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/cfp-sources');
    $response->assertSessionHas('status');
    expect(session('status'))->toContain('fortee');
});

it('POST /admin/cfp-sources は url 重複時に form に戻して errors flash', function () {
    // Given
    $useCase = Mockery::mock(CreateCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(CfpSourceConflictException::withUrl('https://fortee.jp/events'));
    app()->instance(CreateCfpSourceUseCase::class, $useCase);

    // When
    $response = $this->from('/admin/cfp-sources/create')->post('/admin/cfp-sources', [
        'name' => 'duplicate',
        'url' => 'https://fortee.jp/events',
        'enabled' => '1',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/cfp-sources/create');
    $response->assertSessionHasErrors(['conflict']);
});

it('POST /admin/cfp-sources は url 形式不正 (http://) でバリデーションエラー', function () {
    // When
    $response = $this->from('/admin/cfp-sources/create')->post('/admin/cfp-sources', [
        'name' => 'invalid',
        'url' => 'http://insecure.example.com',  // https:// 必須
        'enabled' => '1',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['url']);
});

// ── Edit / Update ──

it('GET /admin/cfp-sources/{id}/edit は 200 で既存値を埋めたフォームを返す', function () {
    // Given
    $source = uiCfpSource('s-1', 'fortee', 'https://fortee.jp/events', true);
    $useCase = Mockery::mock(GetCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')->once()->with('s-1')->andReturn($source);
    app()->instance(GetCfpSourceUseCase::class, $useCase);

    // When
    $response = $this->get('/admin/cfp-sources/s-1/edit');

    // Then
    $response->assertStatus(200);
    $response->assertSee('CfP ソース編集', false);
    $response->assertSee('fortee', false);
    $response->assertSee('https://fortee.jp/events', false);
});

it('GET /admin/cfp-sources/{id}/edit は該当無しで 404', function () {
    // Given
    $useCase = Mockery::mock(GetCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')->once()->andThrow(CfpSourceNotFoundException::withId('missing'));
    app()->instance(GetCfpSourceUseCase::class, $useCase);

    // When/Then
    $this->get('/admin/cfp-sources/missing/edit')->assertStatus(404);
});

it('PUT /admin/cfp-sources/{id} は UseCase を呼んで index にリダイレクト + flash', function () {
    // Given
    $updated = uiCfpSource('s-1', 'new name', 'https://fortee.jp/events', true);
    $useCase = Mockery::mock(UpdateCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')->once()->andReturn($updated);
    app()->instance(UpdateCfpSourceUseCase::class, $useCase);

    // When
    $response = $this->put('/admin/cfp-sources/s-1', [
        'name' => 'new name',
        'url' => 'https://fortee.jp/events',
        'enabled' => '1',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/cfp-sources');
    $response->assertSessionHas('status');
});

it('PUT /admin/cfp-sources/{id} は checkbox 未送信を enabled=false 補完して UseCase に渡す', function () {
    // Given: enabled 未送信 (checkbox 外し相当)
    $captured = null;
    $useCase = Mockery::mock(UpdateCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with('s-1', Mockery::on(function (array $fields) use (&$captured) {
            $captured = $fields;

            return true;
        }))
        ->andReturn(uiCfpSource('s-1', 'fortee', 'https://fortee.jp/events', false));
    app()->instance(UpdateCfpSourceUseCase::class, $useCase);

    // When: enabled キーを送信しない
    $this->put('/admin/cfp-sources/s-1', [
        'name' => 'fortee',
        'url' => 'https://fortee.jp/events',
    ]);

    // Then: Controller が補完して enabled=false で渡している
    /** @var array<string, mixed> $captured */
    expect($captured['enabled'])->toBeFalse();
});

it('PUT /admin/cfp-sources/{id} は 404 ハンドリング', function () {
    // Given
    $useCase = Mockery::mock(UpdateCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')->once()->andThrow(CfpSourceNotFoundException::withId('missing'));
    app()->instance(UpdateCfpSourceUseCase::class, $useCase);

    // When/Then
    $this->put('/admin/cfp-sources/missing', [
        'name' => 'x',
        'url' => 'https://x.example.com/',
    ])->assertStatus(404);
});

it('PUT /admin/cfp-sources/{id} は url 重複時に form に戻して errors flash', function () {
    // Given
    $useCase = Mockery::mock(UpdateCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')->once()->andThrow(CfpSourceConflictException::withUrl('https://other.example.com'));
    app()->instance(UpdateCfpSourceUseCase::class, $useCase);

    // When
    $response = $this->from('/admin/cfp-sources/s-1/edit')->put('/admin/cfp-sources/s-1', [
        'name' => 'x',
        'url' => 'https://other.example.com',
        'enabled' => '1',
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/cfp-sources/s-1/edit');
    $response->assertSessionHasErrors(['conflict']);
});

// ── Delete ──

it('DELETE /admin/cfp-sources/{id} は UseCase を呼んで index にリダイレクト + flash', function () {
    // Given
    $useCase = Mockery::mock(DeleteCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')->once()->with('s-1');
    app()->instance(DeleteCfpSourceUseCase::class, $useCase);

    // When
    $response = $this->delete('/admin/cfp-sources/s-1');

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/cfp-sources');
    $response->assertSessionHas('status');
    expect(session('status'))->toContain('削除しました');
});

it('DELETE /admin/cfp-sources/{id} は 404 ハンドリング', function () {
    // Given
    $useCase = Mockery::mock(DeleteCfpSourceUseCase::class);
    $useCase->shouldReceive('execute')->once()->andThrow(CfpSourceNotFoundException::withId('missing'));
    app()->instance(DeleteCfpSourceUseCase::class, $useCase);

    // When/Then
    $this->delete('/admin/cfp-sources/missing')->assertStatus(404);
});
