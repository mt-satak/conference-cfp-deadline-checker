<?php

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;

/**
 * Conference value object および ConferenceFormat enum のユニットテスト。
 *
 * Conference はドメイン層の readonly value object で、Eloquent や DynamoDB SDK
 * に依存しない純粋な PHP 構造体。Repository (interface) を介して永続化層と
 * やり取りする際の境界型として使う。
 */

function makeConference(): Conference
{
    return new Conference(
        conferenceId: '550e8400-e29b-41d4-a716-446655440000',
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
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );
}

it('Conference は全プロパティを名前付き引数で受け取って公開する', function () {
    $conference = makeConference();

    expect($conference->conferenceId)->toBe('550e8400-e29b-41d4-a716-446655440000');
    expect($conference->name)->toBe('PHPカンファレンス2026');
    expect($conference->trackName)->toBe('一般 CfP');
    expect($conference->officialUrl)->toBe('https://phpcon.example.com/2026');
    expect($conference->cfpUrl)->toBe('https://phpcon.example.com/2026/cfp');
    expect($conference->eventStartDate)->toBe('2026-09-19');
    expect($conference->eventEndDate)->toBe('2026-09-20');
    expect($conference->venue)->toBe('東京');
    expect($conference->format)->toBe(ConferenceFormat::Offline);
    expect($conference->cfpStartDate)->toBe('2026-05-01');
    expect($conference->cfpEndDate)->toBe('2026-07-15');
    expect($conference->categories)->toBe(['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02']);
    expect($conference->description)->toBe('国内最大規模のPHPカンファレンス。');
    expect($conference->themeColor)->toBe('#777BB4');
    expect($conference->createdAt)->toBe('2026-04-15T10:30:00+09:00');
    expect($conference->updatedAt)->toBe('2026-04-15T10:30:00+09:00');
});

it('Conference は trackName / cfpStartDate / description / themeColor を null で受け付ける', function () {
    // OpenAPI 仕様で optional として定義されているフィールドは null が許容される
    $conference = new Conference(
        conferenceId: '660e8400-e29b-41d4-a716-446655440001',
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
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
    );

    expect($conference->trackName)->toBeNull();
    expect($conference->cfpStartDate)->toBeNull();
    expect($conference->description)->toBeNull();
    expect($conference->themeColor)->toBeNull();
});

it('Conference は readonly でプロパティの再代入を許さない', function () {
    $conference = makeConference();

    // readonly class は PHP の Error 例外を投げる (TypeError ではない)
    expect(fn () => $conference->name = 'Other')->toThrow(\Error::class);
});

it('ConferenceFormat enum は online / offline / hybrid の 3 ケースを持つ', function () {
    expect(ConferenceFormat::Online->value)->toBe('online');
    expect(ConferenceFormat::Offline->value)->toBe('offline');
    expect(ConferenceFormat::Hybrid->value)->toBe('hybrid');
    expect(ConferenceFormat::cases())->toHaveCount(3);
});

it('ConferenceFormat::from() で文字列から enum を取得できる', function () {
    expect(ConferenceFormat::from('online'))->toBe(ConferenceFormat::Online);
    expect(ConferenceFormat::from('offline'))->toBe(ConferenceFormat::Offline);
    expect(ConferenceFormat::from('hybrid'))->toBe(ConferenceFormat::Hybrid);
});

it('ConferenceFormat::tryFrom() は不正値で null を返す', function () {
    expect(ConferenceFormat::tryFrom('unknown'))->toBeNull();
});
