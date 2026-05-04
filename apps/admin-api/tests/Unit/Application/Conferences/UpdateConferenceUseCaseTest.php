<?php

use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceRepository;
use Illuminate\Support\Carbon;

/**
 * UpdateConferenceUseCase の単体テスト。
 *
 * 責務:
 * - Repository->findById() で既存 Conference を取得 (なければ ConferenceNotFoundException)
 * - 入力 array (キー有無で部分更新) を反映した新しい Conference を構築
 * - updatedAt を現在時刻で更新 (createdAt は維持)
 * - Repository->save() で永続化
 * - 更新後 Conference を返す
 */
beforeEach(function () {
    // Given (共通): 時刻を固定し、updatedAt の検証を決定的にする
    Carbon::setTestNow('2026-05-04T10:00:00+09:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeExistingConference(): Conference
{
    return new Conference(
        conferenceId: '550e8400-e29b-41d4-a716-446655440000',
        name: '元の名前',
        trackName: '一般 CfP',
        officialUrl: 'https://original.example.com',
        cfpUrl: 'https://original.example.com/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: '2026-05-01',
        cfpEndDate: '2026-07-15',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: '元の説明',
        themeColor: '#000000',
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );
}

it('入力 array で指定したフィールドのみ更新し、未指定フィールドは元の値を維持する', function () {
    // Given: 既存 Conference が Repository から返り、save も呼ばれる
    $existing = makeExistingConference();
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->with($existing->conferenceId)->andReturn($existing);
    $repository->shouldReceive('save')->once();

    // When: name と description のみ更新する
    $useCase = new UpdateConferenceUseCase($repository);
    $updated = $useCase->execute($existing->conferenceId, [
        'name' => '新しい名前',
        'description' => '新しい説明',
    ]);

    // Then: 指定 2 フィールドが更新され、未指定フィールドは元の値が維持される
    expect($updated->name)->toBe('新しい名前');
    expect($updated->description)->toBe('新しい説明');
    expect($updated->trackName)->toBe('一般 CfP');
    expect($updated->officialUrl)->toBe('https://original.example.com');
    expect($updated->categories)->toBe(['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02']);
    expect($updated->themeColor)->toBe('#000000');
});

it('updatedAt は現在時刻で更新され、createdAt と conferenceId は維持される', function () {
    // Given: 既存 Conference
    $existing = makeExistingConference();
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->andReturn($existing);
    $repository->shouldReceive('save')->once();

    // When: 任意のフィールドを更新する
    $useCase = new UpdateConferenceUseCase($repository);
    $updated = $useCase->execute($existing->conferenceId, ['name' => '改名']);

    // Then: ID と createdAt は維持、updatedAt は現在時刻
    expect($updated->conferenceId)->toBe($existing->conferenceId);
    expect($updated->createdAt)->toBe($existing->createdAt);
    expect($updated->updatedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('format に ConferenceFormat enum を渡せる', function () {
    // Given: 既存 Conference (元 format は Offline)
    $existing = makeExistingConference();
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->andReturn($existing);
    $repository->shouldReceive('save')->once();

    // When: format を Hybrid に変更する
    $useCase = new UpdateConferenceUseCase($repository);
    $updated = $useCase->execute($existing->conferenceId, [
        'format' => ConferenceFormat::Hybrid,
    ]);

    // Then: format が Hybrid に更新される
    expect($updated->format)->toBe(ConferenceFormat::Hybrid);
});

it('Repository->save() に更新後 Conference が渡される', function () {
    // Given: 既存 Conference + save に渡された Conference を補足するモック
    $existing = makeExistingConference();
    $captured = null;
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->andReturn($existing);
    $repository->shouldReceive('save')
        ->once()
        ->with(Mockery::on(function (Conference $c) use (&$captured) {
            $captured = $c;

            return true;
        }));

    // When: name を更新する
    $useCase = new UpdateConferenceUseCase($repository);
    $useCase->execute($existing->conferenceId, ['name' => '変更後']);

    // Then: save に渡された Conference は更新後の値を持つ
    /** @var Conference $captured 静的解析向けの型 narrow (mock の closure 内で代入される) */
    expect($captured)->toBeInstanceOf(Conference::class);
    expect($captured->name)->toBe('変更後');
    expect($captured->updatedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('存在しない conferenceId で ConferenceNotFoundException を投げる', function () {
    // Given: Repository->findById() が null を返す (該当無し)
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->andReturn(null);
    $repository->shouldNotReceive('save');

    // When / Then: UseCase 実行で ConferenceNotFoundException が投げられる
    $useCase = new UpdateConferenceUseCase($repository);
    expect(fn () => $useCase->execute('missing-id', ['name' => 'X']))
        ->toThrow(ConferenceNotFoundException::class);
});
