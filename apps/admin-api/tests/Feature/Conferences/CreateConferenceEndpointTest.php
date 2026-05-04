<?php

use App\Application\Conferences\CreateConferenceInput;
use App\Application\Conferences\CreateConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Http\Middleware\VerifyOrigin;

beforeEach(function () {
    // Given (共通): 本テストは create endpoint のロジック検証が責務。
    // Origin ヘッダ検証は AdminApiVerifyOriginTest 側で個別検証済みなので
    // ここではミドルウェアを bypass する。
    test()->withoutMiddleware(VerifyOrigin::class);
});

/**
 * POST /admin/api/conferences (operationId: createConference) の Feature テスト。
 *
 * OpenAPI 仕様 (data/openapi.yaml):
 *   - 201 Created: {"data": <Conference>}
 *   - 422 VALIDATION_FAILED: バリデーション違反
 *
 * バリデーションは StoreConferenceRequest (FormRequest) で実施する。
 * 整合性ルール (cfpStartDate <= cfpEndDate <= eventStartDate <= eventEndDate /
 * URL https / categories minItems 1 / 等) を OpenAPI 仕様に整合させる。
 *
 * NOTE: categories の参照整合性 (categoryId が categories テーブルに存在
 * すること) は Categories Repository が無いため本コミット時点では検証しない。
 */
function validCreatePayload(): array
{
    return [
        'name' => 'PHPカンファレンス2026',
        'trackName' => '一般 CfP',
        'officialUrl' => 'https://phpcon.example.com/2026',
        'cfpUrl' => 'https://phpcon.example.com/2026/cfp',
        'eventStartDate' => '2026-09-19',
        'eventEndDate' => '2026-09-20',
        'venue' => '東京',
        'format' => 'offline',
        'cfpStartDate' => '2026-05-01',
        'cfpEndDate' => '2026-07-15',
        'categories' => ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        'description' => '国内最大規模のPHPカンファレンス。',
        'themeColor' => '#777BB4',
    ];
}

function createdConferenceFor(string $id, array $payload): Conference
{
    return new Conference(
        conferenceId: $id,
        name: $payload['name'],
        trackName: $payload['trackName'] ?? null,
        officialUrl: $payload['officialUrl'],
        cfpUrl: $payload['cfpUrl'],
        eventStartDate: $payload['eventStartDate'],
        eventEndDate: $payload['eventEndDate'],
        venue: $payload['venue'],
        format: ConferenceFormat::from($payload['format']),
        cfpStartDate: $payload['cfpStartDate'] ?? null,
        cfpEndDate: $payload['cfpEndDate'],
        categories: $payload['categories'],
        description: $payload['description'] ?? null,
        themeColor: $payload['themeColor'] ?? null,
        createdAt: '2026-05-04T10:00:00+09:00',
        updatedAt: '2026-05-04T10:00:00+09:00',
    );
}

it('正常な入力で 201 と data に作成された Conference が返る', function () {
    // Given: UseCase が作成済み Conference を返すようコンテナで差し替える
    $payload = validCreatePayload();
    $createdId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    $useCase = Mockery::mock(CreateConferenceUseCase::class);
    $useCase->shouldReceive('execute')
        ->once()
        ->with(Mockery::type(CreateConferenceInput::class))
        ->andReturn(createdConferenceFor($createdId, $payload));
    app()->instance(CreateConferenceUseCase::class, $useCase);

    // When: 正常な入力で POST する
    $response = $this->postJson('/admin/api/conferences', $payload);

    // Then: 201 + data に作成された Conference (id 含む) が返る
    $response->assertStatus(201);
    $response->assertJsonPath('data.conferenceId', $createdId);
    $response->assertJsonPath('data.name', $payload['name']);
    $response->assertJsonPath('data.format', 'offline');
});

it('必須フィールド欠落で 422 + VALIDATION_FAILED', function () {
    // Given: name など必須フィールドを欠落させた入力 + UseCase は呼ばれないモック
    $payload = validCreatePayload();
    unset($payload['name'], $payload['officialUrl']);
    $useCase = Mockery::mock(CreateConferenceUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(CreateConferenceUseCase::class, $useCase);

    // When: POST する
    $response = $this->postJson('/admin/api/conferences', $payload);

    // Then: 422 + VALIDATION_FAILED で details に欠落フィールドが含まれる
    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $fields = collect($response->json('error.details'))->pluck('field')->all();
    expect($fields)->toContain('name');
    expect($fields)->toContain('officialUrl');
});

it('officialUrl が https でないと 422', function () {
    // Given: officialUrl を http (非 https) にした入力
    $payload = validCreatePayload();
    $payload['officialUrl'] = 'http://insecure.example.com';
    $useCase = Mockery::mock(CreateConferenceUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(CreateConferenceUseCase::class, $useCase);

    // When: POST する
    $response = $this->postJson('/admin/api/conferences', $payload);

    // Then: 422 で officialUrl が details に含まれる
    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $fields = collect($response->json('error.details'))->pluck('field')->all();
    expect($fields)->toContain('officialUrl');
});

it('format が enum 列挙外だと 422', function () {
    // Given: format を不正値にした入力
    $payload = validCreatePayload();
    $payload['format'] = 'unknown';
    $useCase = Mockery::mock(CreateConferenceUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(CreateConferenceUseCase::class, $useCase);

    // When: POST する
    $response = $this->postJson('/admin/api/conferences', $payload);

    // Then: 422 で format が details に含まれる
    $response->assertStatus(422);
    $fields = collect($response->json('error.details'))->pluck('field')->all();
    expect($fields)->toContain('format');
});

it('cfpEndDate > eventStartDate (整合性違反) は 422', function () {
    // Given: cfpEndDate (2026-08-01) が eventStartDate (2026-07-15) より後
    $payload = validCreatePayload();
    $payload['cfpEndDate'] = '2026-08-01';
    $payload['eventStartDate'] = '2026-07-15';
    $payload['eventEndDate'] = '2026-07-16';
    $useCase = Mockery::mock(CreateConferenceUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(CreateConferenceUseCase::class, $useCase);

    // When: POST する
    $response = $this->postJson('/admin/api/conferences', $payload);

    // Then: 422 で cfpEndDate が details に含まれる (date order 違反)
    $response->assertStatus(422);
    $fields = collect($response->json('error.details'))->pluck('field')->all();
    expect($fields)->toContain('cfpEndDate');
});

it('categories が空配列だと 422 (minItems: 1)', function () {
    // Given: categories を空配列にした入力
    $payload = validCreatePayload();
    $payload['categories'] = [];
    $useCase = Mockery::mock(CreateConferenceUseCase::class);
    $useCase->shouldNotReceive('execute');
    app()->instance(CreateConferenceUseCase::class, $useCase);

    // When: POST する
    $response = $this->postJson('/admin/api/conferences', $payload);

    // Then: 422 で categories が details に含まれる
    $response->assertStatus(422);
    $fields = collect($response->json('error.details'))->pluck('field')->all();
    expect($fields)->toContain('categories');
});
