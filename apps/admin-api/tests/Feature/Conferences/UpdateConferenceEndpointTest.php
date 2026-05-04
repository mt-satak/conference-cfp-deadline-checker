<?php

use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Http\Middleware\VerifyOrigin;

/**
 * PUT /admin/api/conferences/{id} (operationId: updateConference) の Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml):
 *   - 200 OK: {"data": <Conference>}
 *   - 404 NOT_FOUND: 該当無し
 *   - 422 VALIDATION_FAILED: バリデーション違反
 *   - 部分更新セマンティクス (入力 array に含まれるキーのみ更新)
 *
 * NOTE: 整合性ルール (cfpEndDate <= eventStartDate 等) の cross-field 検証は
 * 部分更新では実装難度が高いため本コミットでは見送り、各フィールド単位の
 * shape 検証のみ。後続で UseCase 側に既存データ取得後の cross-field 検証を
 * 追加する余地を残す。
 */
beforeEach(function () {
    // Given (共通): VerifyOrigin は別テスト責務なのでバイパス
    test()->withoutMiddleware(VerifyOrigin::class);
});

function updatedSampleConference(string $id, array $patch): Conference
{
    return new Conference(
        conferenceId: $id,
        name: $patch['name'] ?? '元の名前',
        trackName: $patch['trackName'] ?? null,
        officialUrl: $patch['officialUrl'] ?? 'https://original.example.com',
        cfpUrl: $patch['cfpUrl'] ?? 'https://original.example.com/cfp',
        eventStartDate: $patch['eventStartDate'] ?? '2026-09-19',
        eventEndDate: $patch['eventEndDate'] ?? '2026-09-20',
        venue: $patch['venue'] ?? '東京',
        format: isset($patch['format']) ? ConferenceFormat::from($patch['format']) : ConferenceFormat::Offline,
        cfpStartDate: $patch['cfpStartDate'] ?? null,
        cfpEndDate: $patch['cfpEndDate'] ?? '2026-07-15',
        categories: $patch['categories'] ?? ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: $patch['description'] ?? null,
        themeColor: $patch['themeColor'] ?? null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );
}

it('PUT /admin/api/conferences/{id} の部分更新で 200 と更新後 Conference が返る', function () {
    // Given: UseCase が name のみ更新した Conference を返すモック
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $patch = ['name' => '新しい名前'];
    $useCase = Mockery::mock(UpdateConferenceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with($id, Mockery::on(fn ($fields) => isset($fields['name']) && $fields['name'] === '新しい名前'))
        ->andReturn(updatedSampleConference($id, $patch));
    app()->instance(UpdateConferenceUseCase::class, $useCase);

    // When: name のみ含む PUT を送る
    $response = $this->putJson("/admin/api/conferences/{$id}", $patch);

    // Then: 200 + 更新後の name が返る
    $response->assertStatus(200);
    $response->assertJsonPath('data.conferenceId', $id);
    $response->assertJsonPath('data.name', '新しい名前');
});

it('PUT /admin/api/conferences/{id} は該当無しなら 404 + NOT_FOUND', function () {
    // Given: UseCase が ConferenceNotFoundException を投げる
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(UpdateConferenceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->andThrow(ConferenceNotFoundException::withId($id));
    app()->instance(UpdateConferenceUseCase::class, $useCase);

    // When: PUT する
    $response = $this->putJson("/admin/api/conferences/{$id}", ['name' => 'X']);

    // Then: 404 + NOT_FOUND に整形される
    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'NOT_FOUND');
});

it('PUT で officialUrl が https でないと 422', function () {
    // Given: officialUrl を http (非 https) にした入力 + UseCase は呼ばれない
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(UpdateConferenceUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(UpdateConferenceUseCase::class, $useCase);

    // When: PUT する
    $response = $this->putJson("/admin/api/conferences/{$id}", [
        'officialUrl' => 'http://insecure.example.com',
    ]);

    // Then: 422 で officialUrl が details に含まれる
    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $fields = collect($response->json('error.details'))->pluck('field')->all();
    expect($fields)->toContain('officialUrl');
});

it('PUT で format が enum 列挙外だと 422', function () {
    // Given: format を不正値にした入力
    $id = '550e8400-e29b-41d4-a716-446655440000';
    $useCase = Mockery::mock(UpdateConferenceUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(UpdateConferenceUseCase::class, $useCase);

    // When: PUT する
    $response = $this->putJson("/admin/api/conferences/{$id}", ['format' => 'unknown']);

    // Then: 422 で format が details に含まれる
    $response->assertStatus(422);
    $fields = collect($response->json('error.details'))->pluck('field')->all();
    expect($fields)->toContain('format');
});
