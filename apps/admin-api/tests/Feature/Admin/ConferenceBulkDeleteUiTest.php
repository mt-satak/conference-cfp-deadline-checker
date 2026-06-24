<?php

use App\Application\Conferences\BulkDelete\BulkDeleteConferencesResult;
use App\Application\Conferences\BulkDelete\BulkDeleteConferencesUseCase;
use App\Http\Middleware\VerifyOrigin;

/**
 * POST /admin/conferences/bulk-delete の Feature テスト (Issue #219)。
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

it('チェックした ID を UseCase に渡し、件数入りフラッシュで index にリダイレクト', function () {
    // Given
    $captured = null;
    $useCase = Mockery::mock(BulkDeleteConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(Mockery::on(function (array $ids) use (&$captured): bool {
            $captured = $ids;

            return true;
        }))
        ->andReturn(new BulkDeleteConferencesResult(requestedCount: 2, deletedCount: 2));
    app()->instance(BulkDeleteConferencesUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/bulk-delete', [
        'ids' => ['id-1', 'id-2'],
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences');
    $response->assertSessionHas('status');
    expect(session('status'))->toContain('2 件');
    expect($captured)->toBe(['id-1', 'id-2']);
});

it('削除件数 < 要求件数なら「既に削除済み」を添えてフラッシュ', function () {
    // Given: 3 件要求のうち 2 件のみ削除成功
    $useCase = Mockery::mock(BulkDeleteConferencesUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andReturn(new BulkDeleteConferencesResult(requestedCount: 3, deletedCount: 2));
    app()->instance(BulkDeleteConferencesUseCase::class, $useCase);

    // When
    $response = $this->post('/admin/conferences/bulk-delete', [
        'ids' => ['id-1', 'id-2', 'id-3'],
    ]);

    // Then
    $response->assertStatus(302);
    expect(session('status'))->toContain('2 件');
    expect(session('status'))->toContain('1 件は既に削除済み');
});

it('ids が空ならバリデーションエラーで戻す (UseCase は呼ばれない)', function () {
    // Given
    $useCase = Mockery::mock(BulkDeleteConferencesUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(BulkDeleteConferencesUseCase::class, $useCase);

    // When: ids 未送信
    $response = $this->from('/admin/conferences')->post('/admin/conferences/bulk-delete', []);

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences');
    $response->assertSessionHasErrors('ids');
});

it('ids に文字列以外が混ざってもバリデーションで弾く', function () {
    // Given
    $useCase = Mockery::mock(BulkDeleteConferencesUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(BulkDeleteConferencesUseCase::class, $useCase);

    // When: ネストした配列を要素に混ぜる (= string ルール違反)
    $response = $this->from('/admin/conferences')->post('/admin/conferences/bulk-delete', [
        'ids' => [['nested' => 'x']],
    ]);

    // Then
    $response->assertStatus(302);
    $response->assertSessionHasErrors('ids.0');
});
