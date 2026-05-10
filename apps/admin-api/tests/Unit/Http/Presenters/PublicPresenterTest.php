<?php

declare(strict_types=1);

use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceStatus;
use App\Http\Presenters\PublicCategoryPresenter;
use App\Http\Presenters\PublicConferencePresenter;

/**
 * 公開フロント (cfp-checker.dev) 向け Presenter のテスト (Issue #178 #4)。
 *
 * 設計意図:
 * - admin Presenter (ConferencePresenter / CategoryPresenter) と projection を分離し、
 *   将来 Conference / Category Entity に admin 専用フィールド (auditLog 等) を
 *   追加した際に公開側へ漏洩しない契約を確立する
 * - 各 Public Presenter は `PUBLIC_FIELDS` 定数で「公開してよいキー集合」を単一の真実として保持
 * - 漏洩検知テスト: 出力キー ⊆ PUBLIC_FIELDS を強制 (= ホワイトリスト方式)
 *
 * Issue 連絡先: #178 #4
 */
function makePublicPresenterFullConference(): Conference
{
    // Given: 全フィールド埋まった Conference (= projection 検証時に欠損キーが無いように)
    return new Conference(
        conferenceId: '11111111-1111-1111-1111-111111111111',
        name: 'PHPerKaigi 2026',
        trackName: 'Track A',
        officialUrl: 'https://phperkaigi.jp/2026/',
        cfpUrl: 'https://phperkaigi.jp/2026/cfp',
        eventStartDate: '2026-03-26',
        eventEndDate: '2026-03-28',
        venue: '練馬区立練馬区民・産業プラザ Coconeri',
        format: ConferenceFormat::Hybrid,
        cfpStartDate: '2025-12-01',
        cfpEndDate: '2026-01-15',
        categories: ['c1c1c1c1-2222-3333-4444-555555555555'],
        description: 'PHP の祭典',
        themeColor: '#FF6600',
        createdAt: '2025-11-01T10:00:00+09:00',
        updatedAt: '2025-12-01T10:00:00+09:00',
        status: ConferenceStatus::Published,
    );
}

it('PublicConferencePresenter::toArray は PUBLIC_FIELDS に列挙したキー集合と完全一致する', function () {
    // Given: フル Conference
    $conf = makePublicPresenterFullConference();

    // When: 公開 Presenter で配列化
    $array = PublicConferencePresenter::toArray($conf);
    $keys = array_keys($array);

    // Then: 出力キーは PUBLIC_FIELDS と完全一致 (順序問わず)
    sort($keys);
    $expected = PublicConferencePresenter::PUBLIC_FIELDS;
    sort($expected);
    expect($keys)->toBe($expected);
});

it('PublicConferencePresenter::toArray の出力キーは PUBLIC_FIELDS の部分集合である (= leak 検知)', function () {
    // Given: フル Conference
    $conf = makePublicPresenterFullConference();

    // When: 出力キーが PUBLIC_FIELDS にホワイトリストされていないものを抽出
    $array = PublicConferencePresenter::toArray($conf);
    $unknown = array_diff(array_keys($array), PublicConferencePresenter::PUBLIC_FIELDS);

    // Then: 未承認キーは 0 (= 公開漏洩なし)
    expect($unknown)->toBe([]);
});

it('PublicConferencePresenter::toArray は admin Presenter と同じ既存フィールドを返す (= 互換性維持)', function () {
    // Given: フル Conference
    $conf = makePublicPresenterFullConference();

    // When: 公開 Presenter で配列化
    $array = PublicConferencePresenter::toArray($conf);

    // Then: 公開フロント (apps/public-site/src/lib/conferencesApi.ts ApiConference) が
    //       読み取る各フィールドが期待値で取れる
    expect($array['conferenceId'])->toBe('11111111-1111-1111-1111-111111111111');
    expect($array['name'])->toBe('PHPerKaigi 2026');
    expect($array['officialUrl'])->toBe('https://phperkaigi.jp/2026/');
    expect($array['format'])->toBe('hybrid');
    expect($array['status'])->toBe('published');
    expect($array['categories'])->toBe(['c1c1c1c1-2222-3333-4444-555555555555']);
});

it('PublicConferencePresenter::toList は配列要素を toArray した list を返す', function () {
    // Given: 2 件の Conference
    $conferences = [
        makePublicPresenterFullConference(),
        makePublicPresenterFullConference(),
    ];

    // When: toList で一括変換
    $list = PublicConferencePresenter::toList($conferences);

    // Then: list shape (= 連番 int キー) で 2 件
    expect($list)->toHaveCount(2);
    expect($list[0]['conferenceId'] ?? null)->toBe('11111111-1111-1111-1111-111111111111');
});

it('PublicConferencePresenter::toList は空配列に対して空配列を返す', function () {
    expect(PublicConferencePresenter::toList([]))->toBe([]);
});

it('PublicCategoryPresenter::toArray の出力キーは PUBLIC_FIELDS の部分集合である (= leak 検知 / axis あり)', function () {
    // Given: axis ありの Category (フィールド最大集合)
    $cat = new Category(
        categoryId: '22222222-2222-2222-2222-222222222222',
        name: 'PHP',
        slug: 'php',
        displayOrder: 100,
        axis: CategoryAxis::A,
        createdAt: '2025-11-01T10:00:00+09:00',
        updatedAt: '2025-12-01T10:00:00+09:00',
    );

    // When: 出力キーがホワイトリスト外のものを抽出
    $array = PublicCategoryPresenter::toArray($cat);
    $unknown = array_diff(array_keys($array), PublicCategoryPresenter::PUBLIC_FIELDS);

    // Then: 未承認キーは 0
    expect($unknown)->toBe([]);
    expect($array['axis'] ?? null)->toBe('A');
});

it('PublicCategoryPresenter::toArray は axis null のとき axis キーを含めない', function () {
    // Given: axis null の Category
    $cat = new Category(
        categoryId: '33333333-3333-3333-3333-333333333333',
        name: 'Backend',
        slug: 'backend',
        displayOrder: 200,
        axis: null,
        createdAt: '2025-11-01T10:00:00+09:00',
        updatedAt: '2025-12-01T10:00:00+09:00',
    );

    // When: 出力配列のキー集合
    $array = PublicCategoryPresenter::toArray($cat);

    // Then: axis キーは含まれず、それ以外の必須キーは全て揃う
    expect($array)->not->toHaveKey('axis');
    expect($array['categoryId'])->toBe('33333333-3333-3333-3333-333333333333');
    expect($array['slug'])->toBe('backend');
    expect($array['name'])->toBe('Backend');
    expect($array['displayOrder'])->toBe(200);
});

it('PublicCategoryPresenter::toList は配列要素を toArray した list を返す', function () {
    // Given: 2 件の Category
    $categories = [
        new Category('id-1', 'PHP', 'php', 100, CategoryAxis::A, '2025-11-01T10:00:00+09:00', '2025-11-01T10:00:00+09:00'),
        new Category('id-2', 'Backend', 'backend', 200, null, '2025-11-01T10:00:00+09:00', '2025-11-01T10:00:00+09:00'),
    ];

    // When: toList で一括変換
    $list = PublicCategoryPresenter::toList($categories);

    // Then: list shape で 2 件
    expect($list)->toHaveCount(2);
    expect($list[0]['slug'] ?? null)->toBe('php');
    expect($list[1]['slug'] ?? null)->toBe('backend');
});

it('PublicCategoryPresenter::toList は空配列に対して空配列を返す', function () {
    expect(PublicCategoryPresenter::toList([]))->toBe([]);
});
