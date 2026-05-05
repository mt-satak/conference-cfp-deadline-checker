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
