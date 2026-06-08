<?php

declare(strict_types=1);

use App\Domain\CfpSources\CfpSource;

/**
 * CfpSource Entity の単体テスト (Issue #200 PR-1)。
 *
 * Domain 層の純粋な readonly 値オブジェクト。Categories と同パターン。
 *
 * 識別子: sourceId (UUID v4)
 * 主属性: name (表示名) / url (巡回対象 URL) / enabled (有効/無効トグル)
 * メタ: createdAt / updatedAt (ISO 8601)
 */
it('CfpSource は全プロパティを名前付き引数で受け取って公開する', function () {
    // When: 全フィールドを指定して構築
    $source = new CfpSource(
        sourceId: '11111111-2222-3333-4444-555555555555',
        name: 'fortee イベント一覧',
        url: 'https://fortee.jp/events',
        enabled: true,
        createdAt: '2026-05-15T09:00:00+09:00',
        updatedAt: '2026-05-15T09:00:00+09:00',
    );

    // Then: 各プロパティが指定値で公開される
    expect($source->sourceId)->toBe('11111111-2222-3333-4444-555555555555');
    expect($source->name)->toBe('fortee イベント一覧');
    expect($source->url)->toBe('https://fortee.jp/events');
    expect($source->enabled)->toBeTrue();
    expect($source->createdAt)->toBe('2026-05-15T09:00:00+09:00');
    expect($source->updatedAt)->toBe('2026-05-15T09:00:00+09:00');
});

it('CfpSource は enabled=false (無効化された source) も保持できる', function () {
    // Given/When: enabled=false で構築
    $source = new CfpSource(
        sourceId: 's-1',
        name: '一時無効化',
        url: 'https://example.com/feed',
        enabled: false,
        createdAt: '2026-05-15T09:00:00+09:00',
        updatedAt: '2026-05-15T09:00:00+09:00',
    );

    // Then
    expect($source->enabled)->toBeFalse();
});
