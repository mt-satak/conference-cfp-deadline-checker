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
    // When / Then: 各文字列値が対応する enum ケースに変換される
    expect(ConferenceStatus::from('draft'))->toBe(ConferenceStatus::Draft);
    expect(ConferenceStatus::from('published'))->toBe(ConferenceStatus::Published);
});

it('ConferenceStatus::tryFrom() は不正値で null を返す', function () {
    // When / Then: 列挙にない値は null を返す (例外を投げない)。
    // 廃止した 'archived' (Issue #221) も未知値として null になる。
    expect(ConferenceStatus::tryFrom('unknown-status'))->toBeNull();
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

// NOTE: Conference::withStatus は archive 専用メソッドだったため Issue #221 で削除
// (= 過去イベントはステータス遷移ではなくハード削除に変更)。対応するテストも撤去。

/**
 * Issue #188: AutoCrawl の差分検知を Conference.pendingChanges に集約。
 *
 * - Published Conference に「人間レビュー待ちの保留差分」を保持できるようにする。
 * - actual フィールド (cfpUrl 等) は AutoCrawl が直接書き換えない。書き換える対象は
 *   pendingChanges のみで、Apply UseCase で初めて actual に反映される。
 * - 後方互換のためデフォルト null。既存 16+ caller に影響しない。
 */
describe('Conference::pendingChanges (Issue #188)', function () {
    it('pendingChanges を省略するとデフォルト null になる (= 既存 caller への後方互換)', function () {
        // Given/When: pendingChanges 未指定で構築
        $conference = makeConference();

        // Then: null
        expect($conference->pendingChanges)->toBeNull();
    });

    it('pendingChanges は field => {old, new} の連想配列で受け取って公開する', function () {
        // Given: AutoCrawl が生成する shape の保留差分
        $pending = [
            'cfpEndDate' => ['old' => '2026-07-15', 'new' => '2026-08-01'],
            'venue' => ['old' => '東京', 'new' => '東京 (千代田区)'],
        ];

        // When: pendingChanges を指定して構築
        $conference = new Conference(
            conferenceId: 'pc-1',
            name: 'Pending Changes Conf',
            trackName: null,
            officialUrl: 'https://x.example.com',
            cfpUrl: null,
            eventStartDate: null,
            eventEndDate: null,
            venue: '東京',
            format: null,
            cfpStartDate: null,
            cfpEndDate: '2026-07-15',
            categories: [],
            description: null,
            themeColor: null,
            createdAt: '2026-04-01T10:00:00+09:00',
            updatedAt: '2026-04-01T10:00:00+09:00',
            status: ConferenceStatus::Published,
            pendingChanges: $pending,
        );

        // Then: そのまま公開される
        expect($conference->pendingChanges)->toBe($pending);
    });

    it('pendingChanges は空配列も許容する (= 0 件保留状態を null と区別したい場合)', function () {
        // Given/When: 空配列で構築
        $conference = new Conference(
            conferenceId: 'pc-2',
            name: 'Empty Pending',
            trackName: null,
            officialUrl: 'https://x.example.com',
            cfpUrl: null,
            eventStartDate: null,
            eventEndDate: null,
            venue: null,
            format: null,
            cfpStartDate: null,
            cfpEndDate: null,
            categories: [],
            description: null,
            themeColor: null,
            createdAt: '2026-04-01T10:00:00+09:00',
            updatedAt: '2026-04-01T10:00:00+09:00',
            pendingChanges: [],
        );

        // Then: 空配列がそのまま保持される (null とは区別)
        expect($conference->pendingChanges)->toBe([]);
        expect($conference->pendingChanges)->not->toBeNull();
    });
});

/**
 * Issue #200 PR-2: 自動 CfP 発見 (PR-3 で実装) で投入された Draft を識別する
 * メタデータ。Conference に discoveryMetadata 持たせ、admin 一覧で「🆕 自動発見」
 * バッジ表示する。
 *
 * - discoveryMetadata: ?array{discoveredAt: string, sourceId: string} (default null)
 *   null = 既存・手動作成 / 値あり = PR-3 の DiscoverConferencesUseCase が投入
 * - isRecentlyDiscovered(today, withinDays = 14): 直近 N 日以内かを判定する純粋関数
 * - PublicConferencePresenter には含めない (= PUBLIC_FIELDS ホワイトリストで構造的に保護)
 */
describe('Conference::discoveryMetadata (Issue #200 PR-2)', function () {
    it('discoveryMetadata を省略するとデフォルト null (= 既存 caller への後方互換)', function () {
        // Given/When
        $conference = makeConference();

        // Then
        expect($conference->discoveryMetadata)->toBeNull();
    });

    it('discoveryMetadata は {discoveredAt, sourceId} の連想配列で受け取って公開する', function () {
        // Given
        $meta = [
            'discoveredAt' => '2026-05-15T08:00:00+09:00',
            'sourceId' => 'source-fortee',
        ];

        // When
        $conference = new Conference(
            conferenceId: 'dm-1',
            name: 'Discovered Conf',
            trackName: null,
            officialUrl: 'https://x.example.com',
            cfpUrl: null,
            eventStartDate: null,
            eventEndDate: null,
            venue: null,
            format: null,
            cfpStartDate: null,
            cfpEndDate: null,
            categories: [],
            description: null,
            themeColor: null,
            createdAt: '2026-05-15T08:00:00+09:00',
            updatedAt: '2026-05-15T08:00:00+09:00',
            status: ConferenceStatus::Draft,
            discoveryMetadata: $meta,
        );

        // Then
        expect($conference->discoveryMetadata)->toBe($meta);
    });
});

describe('Conference::isRecentlyDiscovered (Issue #200 PR-2)', function () {
    /**
     * @param  array{discoveredAt: string, sourceId: string}|null  $meta
     */
    function makeConferenceForDiscovery(?array $meta): Conference
    {
        return new Conference(
            conferenceId: 'd-1',
            name: 'Test',
            trackName: null,
            officialUrl: 'https://x.example.com',
            cfpUrl: null,
            eventStartDate: null,
            eventEndDate: null,
            venue: null,
            format: null,
            cfpStartDate: null,
            cfpEndDate: null,
            categories: [],
            description: null,
            themeColor: null,
            createdAt: '2026-05-15T08:00:00+09:00',
            updatedAt: '2026-05-15T08:00:00+09:00',
            status: ConferenceStatus::Draft,
            discoveryMetadata: $meta,
        );
    }

    it('discoveryMetadata 無し (= 手動作成) は常に false', function () {
        // Given/When/Then: 14 日以内であろうとも null なら自動発見ではない
        expect(makeConferenceForDiscovery(null)->isRecentlyDiscovered('2026-05-15'))->toBeFalse();
    });

    it('discoveredAt が今日と同じ日付なら true', function () {
        // Given
        $conf = makeConferenceForDiscovery([
            'discoveredAt' => '2026-05-15T08:00:00+09:00',
            'sourceId' => 'source-1',
        ]);

        // When/Then
        expect($conf->isRecentlyDiscovered('2026-05-15'))->toBeTrue();
    });

    it('discoveredAt が 14 日以内なら true (= 直近 14 日)', function () {
        // Given: 14 日前 (= 上限の境界)
        $conf = makeConferenceForDiscovery([
            'discoveredAt' => '2026-05-01T08:00:00+09:00',
            'sourceId' => 'source-1',
        ]);

        // When/Then: 5/15 から見て 5/1 はちょうど 14 日前 = true
        expect($conf->isRecentlyDiscovered('2026-05-15'))->toBeTrue();
    });

    it('discoveredAt が 15 日以上前なら false', function () {
        // Given: 15 日前 (= 範囲外)
        $conf = makeConferenceForDiscovery([
            'discoveredAt' => '2026-04-30T08:00:00+09:00',
            'sourceId' => 'source-1',
        ]);

        // When/Then
        expect($conf->isRecentlyDiscovered('2026-05-15'))->toBeFalse();
    });

    it('discoveryMetadata に discoveredAt キーが無い (= 想定外データ) は false', function () {
        // Given: 壊れた meta
        $conf = makeConferenceForDiscovery([
            'discoveredAt' => '',  // 空文字列 (= データ欠落想定)
            'sourceId' => 'source-1',
        ]);

        // When/Then: 防御的に false
        expect($conf->isRecentlyDiscovered('2026-05-15'))->toBeFalse();
    });

    it('discoveryMetadata 配列に discoveredAt キー自体が無い (= ?? のデフォルト経路) は false', function () {
        // Given: discoveredAt キー欠落 (= ?? '' で空文字列フォールバック)
        // @phpstan-ignore-next-line - intentional shape violation to exercise the ?? default branch
        $conf = makeConferenceForDiscovery(['sourceId' => 'source-1']);

        // When/Then
        expect($conf->isRecentlyDiscovered('2026-05-15'))->toBeFalse();
    });
});
