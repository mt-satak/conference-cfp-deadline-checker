<?php

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceStatus;

/**
 * Conference value object および ConferenceFormat enum のユニットテスト。
 *
 * Conference はドメイン層の readonly Entity (Aggregate Root)。
 * Eloquent / DynamoDB SDK には依存せず、Repository を介して永続化層と
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
    // When: 全フィールドを指定して Conference を構築する
    $conference = makeConference();

    // Then: 各プロパティが指定値で公開されている
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
    // When: optional フィールドを null にして Conference を構築する
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

    // Then: optional フィールドは null として保持される
    expect($conference->trackName)->toBeNull();
    expect($conference->cfpStartDate)->toBeNull();
    expect($conference->description)->toBeNull();
    expect($conference->themeColor)->toBeNull();
});

// NOTE: readonly プロパティの再代入禁止は PHP 言語機能であり、
// `final readonly class Conference` の宣言自体で保証される。
// テストで PHP の挙動を二重確認する価値が低いため、当該テストは置かない。

it('ConferenceFormat enum は online / offline / hybrid の 3 ケースを持つ', function () {
    // When / Then: 3 ケース全てが期待値の文字列で公開され、件数も 3 である
    expect(ConferenceFormat::Online->value)->toBe('online');
    expect(ConferenceFormat::Offline->value)->toBe('offline');
    expect(ConferenceFormat::Hybrid->value)->toBe('hybrid');
    expect(ConferenceFormat::cases())->toHaveCount(3);
});

it('ConferenceFormat::from() で文字列から enum を取得できる', function () {
    // When / Then: 各文字列値が対応する enum ケースに変換される
    expect(ConferenceFormat::from('online'))->toBe(ConferenceFormat::Online);
    expect(ConferenceFormat::from('offline'))->toBe(ConferenceFormat::Offline);
    expect(ConferenceFormat::from('hybrid'))->toBe(ConferenceFormat::Hybrid);
});

it('ConferenceFormat::tryFrom() は不正値で null を返す', function () {
    // When / Then: 列挙にない値は null を返す (例外を投げない)
    expect(ConferenceFormat::tryFrom('unknown'))->toBeNull();
});

it('ConferenceStatus enum は draft / published の 2 ケースを持つ', function () {
    // When / Then: 2 ケース全てが期待値の文字列で公開され、件数も 2 である
    expect(ConferenceStatus::Draft->value)->toBe('draft');
    expect(ConferenceStatus::Published->value)->toBe('published');
    expect(ConferenceStatus::cases())->toHaveCount(2);
});

it('ConferenceStatus::from() で文字列から enum を取得できる', function () {
    // When / Then: 各文字列値が対応する enum ケースに変換される
    expect(ConferenceStatus::from('draft'))->toBe(ConferenceStatus::Draft);
    expect(ConferenceStatus::from('published'))->toBe(ConferenceStatus::Published);
});

it('ConferenceStatus::tryFrom() は不正値で null を返す', function () {
    // When / Then: 列挙にない値は null を返す (例外を投げない)
    expect(ConferenceStatus::tryFrom('archived'))->toBeNull();
});

it('Conference は status を指定して構築でき、プロパティとして公開する', function () {
    // When: status を指定して Conference を構築する
    $conference = new Conference(
        conferenceId: '770e8400-e29b-41d4-a716-446655440002',
        name: 'Draft カンファ',
        trackName: null,
        officialUrl: 'https://draft.example.com',
        cfpUrl: 'https://draft.example.com/cfp',
        eventStartDate: '2026-12-01',
        eventEndDate: '2026-12-01',
        venue: 'TBD',
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: '2026-10-01',
        categories: ['1d4f2a83-6b48-4f1c-9c8a-7e2b3d4f5a02'],
        description: null,
        themeColor: null,
        createdAt: '2026-04-15T10:30:00+09:00',
        updatedAt: '2026-04-15T10:30:00+09:00',
        status: ConferenceStatus::Draft,
    );

    // Then: status が指定値で公開されている
    expect($conference->status)->toBe(ConferenceStatus::Draft);
});

it('Conference は status を省略すると published がデフォルトで適用される', function () {
    // Given/When: status 引数なしで構築 (= 既存 16 callers との後方互換性確認)
    $conference = makeConference();

    // Then: status は Published (= 既存挙動)
    expect($conference->status)->toBe(ConferenceStatus::Published);
});
