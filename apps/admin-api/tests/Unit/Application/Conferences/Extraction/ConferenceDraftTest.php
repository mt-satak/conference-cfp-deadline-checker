<?php

use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Domain\Conferences\ConferenceFormat;

/**
 * ConferenceDraft DTO のユニットテスト (Issue #40 Phase 3 PR-1)。
 *
 * ConferenceDraft は LLM URL 抽出の結果として渡される未保存・未検証 DTO。
 * Conference Entity ではないため:
 * - conferenceId / createdAt / updatedAt / status を持たない (UseCase が保存時に補完)
 * - sourceUrl 以外の全フィールドが null 許容 (LLM が一部しか抽出できない場合がある想定)
 * - categorySlugs は string[] (= 検証前の "推測" であり、確定 categoryId UUID ではない)
 */
it('ConferenceDraft は sourceUrl のみ必須で他フィールドはデフォルト null', function () {
    // When: 必須項目のみ指定して構築
    $draft = new ConferenceDraft(sourceUrl: 'https://phpcon.example.com/2026');

    // Then
    expect($draft->sourceUrl)->toBe('https://phpcon.example.com/2026');
    expect($draft->name)->toBeNull();
    expect($draft->trackName)->toBeNull();
    expect($draft->officialUrl)->toBeNull();
    expect($draft->cfpUrl)->toBeNull();
    expect($draft->eventStartDate)->toBeNull();
    expect($draft->eventEndDate)->toBeNull();
    expect($draft->venue)->toBeNull();
    expect($draft->format)->toBeNull();
    expect($draft->cfpStartDate)->toBeNull();
    expect($draft->cfpEndDate)->toBeNull();
    expect($draft->categorySlugs)->toBe([]);
    expect($draft->description)->toBeNull();
    expect($draft->themeColor)->toBeNull();
});

it('ConferenceDraft は全フィールドを指定して構築できる', function () {
    // When: 全フィールド指定
    $draft = new ConferenceDraft(
        sourceUrl: 'https://phpcon.example.com/2026',
        name: 'PHP Conference 2026',
        trackName: '一般 CfP',
        officialUrl: 'https://phpcon.example.com/2026',
        cfpUrl: 'https://fortee.jp/phpcon-2026/cfp',
        eventStartDate: '2026-07-20',
        eventEndDate: '2026-07-20',
        venue: '東京',
        format: ConferenceFormat::Offline,
        cfpStartDate: '2026-04-01',
        cfpEndDate: '2026-05-20',
        categorySlugs: ['php', 'backend'],
        description: '国内最大規模の PHP カンファレンス。',
        themeColor: '#777BB4',
    );

    // Then: 各フィールドが指定値で公開されている
    expect($draft->name)->toBe('PHP Conference 2026');
    expect($draft->format)->toBe(ConferenceFormat::Offline);
    expect($draft->categorySlugs)->toBe(['php', 'backend']);
    expect($draft->themeColor)->toBe('#777BB4');
});

// ── Issue #224: 公式リンク条件付き follow 用ロジック ──

describe('ConferenceDraft::isMissingPublishableField', function () {
    it('Publish 必須 6 項目がすべて埋まっていれば false', function () {
        // Given: cfpUrl / eventStartDate / eventEndDate / venue / format / cfpEndDate 全充足
        $draft = new ConferenceDraft(
            sourceUrl: 'https://x.example.com/',
            name: 'Conf',
            cfpUrl: 'https://x.example.com/cfp',
            eventStartDate: '2026-09-19',
            eventEndDate: '2026-09-20',
            venue: '東京',
            format: ConferenceFormat::Offline,
            cfpEndDate: '2026-07-15',
        );

        // When/Then
        expect($draft->isMissingPublishableField())->toBeFalse();
    });

    it('venue が null なら true', function () {
        $draft = new ConferenceDraft(
            sourceUrl: 'https://x.example.com/',
            name: 'Conf',
            cfpUrl: 'https://x.example.com/cfp',
            eventStartDate: '2026-09-19',
            eventEndDate: '2026-09-20',
            venue: null,
            format: ConferenceFormat::Offline,
            cfpEndDate: '2026-07-15',
        );

        expect($draft->isMissingPublishableField())->toBeTrue();
    });

    it('format が null でも true (= 必須 6 項目のいずれか欠落で true)', function () {
        $draft = new ConferenceDraft(
            sourceUrl: 'https://x.example.com/',
            name: 'Conf',
            cfpUrl: 'https://x.example.com/cfp',
            eventStartDate: '2026-09-19',
            eventEndDate: '2026-09-20',
            venue: '東京',
            format: null,
            cfpEndDate: '2026-07-15',
        );

        expect($draft->isMissingPublishableField())->toBeTrue();
    });

    it('description / themeColor / trackName / cfpStartDate / categorySlugs は判定対象外', function () {
        // Given: 必須 6 項目は埋まり、任意項目だけ null/空
        $draft = new ConferenceDraft(
            sourceUrl: 'https://x.example.com/',
            name: 'Conf',
            cfpUrl: 'https://x.example.com/cfp',
            eventStartDate: '2026-09-19',
            eventEndDate: '2026-09-20',
            venue: '東京',
            format: ConferenceFormat::Offline,
            cfpStartDate: null,
            cfpEndDate: '2026-07-15',
            categorySlugs: [],
            description: null,
            themeColor: null,
            trackName: null,
        );

        // When/Then: 任意項目の null は欠損ではない
        expect($draft->isMissingPublishableField())->toBeFalse();
    });
});

describe('ConferenceDraft::mergeFillingNullsFrom', function () {
    it('自分の null フィールドを other の値で補完する', function () {
        // Given: 1 ページ目は name + cfpEndDate のみ、2 ページ目に venue / 開催日 / format
        $primary = new ConferenceDraft(
            sourceUrl: 'https://fortee.jp/conf',
            name: 'Conf 2026',
            officialUrl: 'https://conf.example.com/',
            cfpEndDate: '2026-07-15',
        );
        $fallback = new ConferenceDraft(
            sourceUrl: 'https://conf.example.com/',
            name: 'Conf 2026 (公式)',
            venue: '東京',
            eventStartDate: '2026-09-19',
            eventEndDate: '2026-09-20',
            format: ConferenceFormat::Hybrid,
        );

        // When
        $merged = $primary->mergeFillingNullsFrom($fallback);

        // Then: primary の非 null は維持、null は fallback で補完
        expect($merged->sourceUrl)->toBe('https://fortee.jp/conf');  // primary 維持
        expect($merged->name)->toBe('Conf 2026');                    // primary 維持 (非 null)
        expect($merged->cfpEndDate)->toBe('2026-07-15');             // primary 維持
        expect($merged->venue)->toBe('東京');                         // fallback 補完
        expect($merged->eventStartDate)->toBe('2026-09-19');         // fallback 補完
        expect($merged->eventEndDate)->toBe('2026-09-20');           // fallback 補完
        expect($merged->format)->toBe(ConferenceFormat::Hybrid);     // fallback 補完
    });

    it('primary が非 null のフィールドは other で上書きしない', function () {
        $primary = new ConferenceDraft(sourceUrl: 'https://a/', venue: '東京');
        $fallback = new ConferenceDraft(sourceUrl: 'https://b/', venue: '大阪');

        $merged = $primary->mergeFillingNullsFrom($fallback);

        expect($merged->venue)->toBe('東京');
    });

    it('categorySlugs は primary が空のとき other を採用する', function () {
        $primary = new ConferenceDraft(sourceUrl: 'https://a/', categorySlugs: []);
        $fallback = new ConferenceDraft(sourceUrl: 'https://b/', categorySlugs: ['php', 'web']);

        $merged = $primary->mergeFillingNullsFrom($fallback);

        expect($merged->categorySlugs)->toBe(['php', 'web']);
    });

    it('categorySlugs は primary が非空なら維持する', function () {
        $primary = new ConferenceDraft(sourceUrl: 'https://a/', categorySlugs: ['go']);
        $fallback = new ConferenceDraft(sourceUrl: 'https://b/', categorySlugs: ['php', 'web']);

        $merged = $primary->mergeFillingNullsFrom($fallback);

        expect($merged->categorySlugs)->toBe(['go']);
    });
});
