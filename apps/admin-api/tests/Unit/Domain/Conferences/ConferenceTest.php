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

it('ConferenceStatus::from() で文字列から enum を取得できる', function () {
    // When / Then: 各文字列値が対応する enum ケースに変換される (Issue #165 で archived 追加)
    expect(ConferenceStatus::from('draft'))->toBe(ConferenceStatus::Draft);
    expect(ConferenceStatus::from('published'))->toBe(ConferenceStatus::Published);
    expect(ConferenceStatus::from('archived'))->toBe(ConferenceStatus::Archived);
});

it('ConferenceStatus::tryFrom() は不正値で null を返す', function () {
    // When / Then: 列挙にない値は null を返す (例外を投げない)。
    // 'archived' は Issue #165 で正式な enum 値として追加されたため、
    // 不正値として 'unknown-status' で検証する。
    expect(ConferenceStatus::tryFrom('unknown-status'))->toBeNull();
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

/**
 * Issue #165 Phase 2 で追加するドメインメソッド。
 *
 * isPastEvent: 「開催日を過ぎたか」を判定する純粋関数。
 *   - eventEndDate が非 null ならそれを基準にする
 *   - eventEndDate が null なら eventStartDate を基準にする (= 1 日開催想定)
 *   - 両方 null なら判定不能 → false (= Draft の不完全データを誤って archive しない)
 *   - 当日中 (eventEndDate === today) は false (= 終了日翌日からアーカイブ)
 *
 * withStatus: status と updatedAt のみを差し替えた新規 Conference を返す。
 *   readonly class なので新規インスタンス生成。Application 層で archive 時に使う。
 */
function makeConferenceForIsPast(?string $eventStartDate, ?string $eventEndDate): Conference
{
    return new Conference(
        conferenceId: 'id-1',
        name: 'Test conference',
        trackName: null,
        officialUrl: 'https://x.example.com',
        cfpUrl: null,
        eventStartDate: $eventStartDate,
        eventEndDate: $eventEndDate,
        venue: null,
        format: null,
        cfpStartDate: null,
        cfpEndDate: null,
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2026-04-01T10:00:00+09:00',
        updatedAt: '2026-04-01T10:00:00+09:00',
    );
}

describe('Conference::isPastEvent (Issue #165)', function () {
    it('eventEndDate < today なら true (= 終了日翌日からアーカイブ対象)', function () {
        // Given: 5/8 終了 → 5/9 時点で過去
        $conf = makeConferenceForIsPast('2026-05-07', '2026-05-08');

        // When/Then
        expect($conf->isPastEvent('2026-05-09'))->toBeTrue();
    });

    it('eventEndDate > today なら false (= 未来開催)', function () {
        // Given: 5/15 終了予定 → 5/9 時点では未来
        $conf = makeConferenceForIsPast('2026-05-14', '2026-05-15');

        // When/Then
        expect($conf->isPastEvent('2026-05-09'))->toBeFalse();
    });

    it('eventEndDate === today なら false (= 当日中はまだアーカイブしない)', function () {
        // Given: 5/9 終了 = today
        $conf = makeConferenceForIsPast('2026-05-09', '2026-05-09');

        // When/Then
        expect($conf->isPastEvent('2026-05-09'))->toBeFalse();
    });

    it('eventEndDate=null かつ eventStartDate < today なら true (= 1 日開催 fallback)', function () {
        // Given: eventEndDate 無しで eventStartDate のみ
        $conf = makeConferenceForIsPast('2026-05-08', null);

        // When/Then
        expect($conf->isPastEvent('2026-05-09'))->toBeTrue();
    });

    it('eventEndDate=null かつ eventStartDate > today なら false', function () {
        // Given
        $conf = makeConferenceForIsPast('2026-05-15', null);

        // When/Then
        expect($conf->isPastEvent('2026-05-09'))->toBeFalse();
    });

    it('eventEndDate / eventStartDate 両方 null なら false (= 判定不能で Draft の不完全データを温存)', function () {
        // Given: 不完全 Draft (両方 null)
        $conf = makeConferenceForIsPast(null, null);

        // When/Then
        expect($conf->isPastEvent('2026-05-09'))->toBeFalse();
    });
});

describe('Conference::withStatus (Issue #165)', function () {
    it('status と updatedAt を差し替えた新インスタンスを返す', function () {
        // Given: Draft の Conference
        $original = makeConferenceForIsPast('2026-05-07', '2026-05-08');
        // 元の updatedAt を確認用に取っておく
        expect($original->updatedAt)->toBe('2026-04-01T10:00:00+09:00');

        // When: Archived に切り替え + 新しい updatedAt
        $archived = $original->withStatus(
            ConferenceStatus::Archived,
            '2026-05-09T06:00:00+09:00',
        );

        // Then
        expect($archived)->not->toBe($original); // 新規インスタンス
        expect($archived->status)->toBe(ConferenceStatus::Archived);
        expect($archived->updatedAt)->toBe('2026-05-09T06:00:00+09:00');
        // 他フィールドは保持
        expect($archived->conferenceId)->toBe($original->conferenceId);
        expect($archived->name)->toBe($original->name);
        expect($archived->createdAt)->toBe($original->createdAt);
        expect($archived->eventStartDate)->toBe($original->eventStartDate);
        expect($archived->eventEndDate)->toBe($original->eventEndDate);
    });

    it('元の Conference は不変 (= readonly class の保証確認)', function () {
        // Given
        $original = makeConferenceForIsPast('2026-05-07', '2026-05-08');

        // When: 新インスタンスを作る
        $original->withStatus(
            ConferenceStatus::Archived,
            '2026-05-09T06:00:00+09:00',
        );

        // Then: 元は変わらない
        expect($original->status)->toBe(ConferenceStatus::Published);
        expect($original->updatedAt)->toBe('2026-04-01T10:00:00+09:00');
    });
});
