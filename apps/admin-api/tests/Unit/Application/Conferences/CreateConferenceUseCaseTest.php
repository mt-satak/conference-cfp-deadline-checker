<?php

use App\Application\Conferences\CreateConferenceInput;
use App\Application\Conferences\CreateConferenceUseCase;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * CreateConferenceUseCase の単体テスト。
 *
 * 責務:
 * - 入力 DTO から conferenceId (UUID v4) と createdAt / updatedAt を補完して
 *   Conference Entity を構築する
 * - Repository->save() で永続化する
 * - 構築した Conference を返す
 *
 * テスト容易性のため Carbon::setTestNow() と Str::createUuidsUsing() で
 * 時間と UUID を固定する。
 */

beforeEach(function () {
    Carbon::setTestNow('2026-05-04T10:00:00+09:00');
    // Str::createUuidsUsing は UuidInterface を返すクロージャを期待する。
    Str::createUuidsUsing(fn () => Uuid::fromString('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'));
});

afterEach(function () {
    Carbon::setTestNow();
    Str::createUuidsNormally();
});

function makeCreateInput(): CreateConferenceInput
{
    return new CreateConferenceInput(
        name: 'PHPカンファレンス2026',
        trackName: '一般 CfP',
        officialUrl: 'https://phpcon.example.com/2026',
        cfpUrl: 'https://phpcon.example.com/2026/cfp',
        eventStartDate: '2026-09-19',
        eventEndDate: '2026-09-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: '2026-05-01',
        cfpEndDate: '2026-07-15',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: '国内最大規模のPHPカンファレンス。',
        themeColor: '#777BB4',
    );
}

it('入力 DTO から UUID と現在時刻を補完して Conference を返す', function () {
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('save')->once()->with(Mockery::type(Conference::class));

    $useCase = new CreateConferenceUseCase($repository);
    $created = $useCase->execute(makeCreateInput());

    expect($created->conferenceId)->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
    expect($created->name)->toBe('PHPカンファレンス2026');
    expect($created->createdAt)->toBe('2026-05-04T10:00:00+09:00');
    expect($created->updatedAt)->toBe('2026-05-04T10:00:00+09:00');
});

it('Repository->save() に組み立てた Conference を渡す', function () {
    $captured = null;

    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('save')
        ->once()
        ->with(Mockery::on(function (Conference $c) use (&$captured) {
            $captured = $c;
            return true;
        }));

    $useCase = new CreateConferenceUseCase($repository);
    $useCase->execute(makeCreateInput());

    expect($captured)->toBeInstanceOf(Conference::class);
    expect($captured->conferenceId)->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
    expect($captured->categories)->toBe(['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02']);
});

it('optional 入力 (trackName / cfpStartDate / description / themeColor) は null のまま Conference に渡る', function () {
    $repository = Mockery::mock(ConferenceRepository::class);
    $repository->shouldReceive('save')->once();

    $input = new CreateConferenceInput(
        name: '小規模カンファレンス',
        trackName: null,
        officialUrl: 'https://small.example.com',
        cfpUrl: 'https://small.example.com/cfp',
        eventStartDate: '2026-10-01',
        eventEndDate: '2026-10-01',
        venue: 'オンライン',
        format: ConferenceFormat::Online,
        cfpStartDate: null,
        cfpEndDate: '2026-08-01',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: null,
        themeColor: null,
    );

    $useCase = new CreateConferenceUseCase($repository);
    $created = $useCase->execute($input);

    expect($created->trackName)->toBeNull();
    expect($created->cfpStartDate)->toBeNull();
    expect($created->description)->toBeNull();
    expect($created->themeColor)->toBeNull();
});
