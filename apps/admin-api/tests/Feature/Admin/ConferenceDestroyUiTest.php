<?php

use App\Application\Conferences\DeleteConferenceUseCase;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Http\Middleware\VerifyOrigin;

/**
 * DELETE /admin/conferences/{id} の Feature テスト。
 */
beforeEach(function () {
    test()->withoutVite();
    test()->withoutMiddleware(VerifyOrigin::class);
});

it('DELETE /admin/conferences/{id} は成功時に index にリダイレクト + フラッシュ', function () {
    // Given
    $useCase = Mockery::mock(DeleteConferenceUseCase::class);
    $useCase->shouldReceive('execute')->once()->with('id-1');
    app()->instance(DeleteConferenceUseCase::class, $useCase);

    // When
    $response = $this->delete('/admin/conferences/id-1');

    // Then
    $response->assertStatus(302);
    $response->assertRedirect('/admin/conferences');
    $response->assertSessionHas('status');
});

it('DELETE /admin/conferences/{id} は該当無しなら 404', function () {
    // Given
    $useCase = Mockery::mock(DeleteConferenceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(ConferenceNotFoundException::withId('missing'));
    app()->instance(DeleteConferenceUseCase::class, $useCase);

    // When
    $response = $this->delete('/admin/conferences/missing');

    // Then
    $response->assertStatus(404);
});
