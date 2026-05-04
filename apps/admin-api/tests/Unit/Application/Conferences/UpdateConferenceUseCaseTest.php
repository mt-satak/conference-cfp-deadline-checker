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
 *
 * 部分更新セマンティクス: 入力 array に含まれていないキーは元の値を維持する。
 */

beforeEach(function () {
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
    $existing = makeExistingConference();

    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->with($existing->conferenceId)->andReturn($existing);
    $repository->shouldReceive('save')->once();

    $useCase = new UpdateConferenceUseCase($repository);
    $updated = $useCase->execute($existing->conferenceId, [
        'name' => '新しい名前',
        'description' => '新しい説明',
    ]);

    expect($updated->name)->toBe('新しい名前');
    expect($updated->description)->toBe('新しい説明');
    // 未指定フィールドは元の値
    expect($updated->trackName)->toBe('一般 CfP');
    expect($updated->officialUrl)->toBe('https://original.example.com');
    expect($updated->categories)->toBe(['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02']);
    expect($updated->themeColor)->toBe('#000000');
});

it('updatedAt は現在時刻で更新され、createdAt と conferenceId は維持される', function () {
    $existing = makeExistingConference();

    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->andReturn($existing);
    $repository->shouldReceive('save')->once();

    $useCase = new UpdateConferenceUseCase($repository);
    $updated = $useCase->execute($existing->conferenceId, ['name' => '改名']);

    expect($updated->conferenceId)->toBe($existing->conferenceId);
    expect($updated->createdAt)->toBe($existing->createdAt);
    expect($updated->updatedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('format に ConferenceFormat enum を渡せる', function () {
    $existing = makeExistingConference();

    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->andReturn($existing);
    $repository->shouldReceive('save')->once();

    $useCase = new UpdateConferenceUseCase($repository);
    $updated = $useCase->execute($existing->conferenceId, [
        'format' => ConferenceFormat::Hybrid,
    ]);

    expect($updated->format)->toBe(ConferenceFormat::Hybrid);
});

it('Repository->save() に更新後 Conference が渡される', function () {
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

    $useCase = new UpdateConferenceUseCase($repository);
    $useCase->execute($existing->conferenceId, ['name' => '変更後']);

    expect($captured->name)->toBe('変更後');
    expect($captured->updatedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('存在しない conferenceId で ConferenceNotFoundException を投げる', function () {
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('findById')->once()->andReturn(null);
    $repository->shouldNotReceive('save');

    $useCase = new UpdateConferenceUseCase($repository);

    expect(fn () => $useCase->execute('missing-id', ['name' => 'X']))
        ->toThrow(ConferenceNotFoundException::class);
});
