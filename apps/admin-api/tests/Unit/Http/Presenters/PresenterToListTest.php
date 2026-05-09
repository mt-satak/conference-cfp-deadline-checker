<?php

declare(strict_types=1);

use App\Domain\Build\BuildJobStatus;
use App\Domain\Build\BuildStatus;
use App\Domain\Categories\Category;
use App\Domain\Categories\CategoryAxis;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceStatus;
use App\Http\Presenters\BuildStatusPresenter;
use App\Http\Presenters\CategoryPresenter;
use App\Http\Presenters\ConferencePresenter;

/**
 * Presenter::toList() 静的ヘルパのテスト (Issue #178 #3)。
 *
 * 各 Controller index() で `array_map(static fn => Presenter::toArray($x), $items)` の
 * パターンが計 5 箇所重複していた。`Presenter::toList(array): array` に集約して
 * Controller を 1 行に簡潔化する。
 *
 * 各 Presenter::toArray() の挙動は既に Feature テストで暗黙的に検証されているため、
 * 本テストは toList() 固有 (= 配列入力 → 配列出力 + reindex 等) のみを軽く確認する。
 */
function makePresenterTestConference(string $id): Conference
{
    return new Conference(
        conferenceId: $id,
        name: 'Conf '.$id,
        trackName: null,
        officialUrl: 'https://x.example.com/'.$id,
        cfpUrl: null,
        eventStartDate: null,
        eventEndDate: null,
        venue: null,
        format: ConferenceFormat::Offline,
        cfpStartDate: null,
        cfpEndDate: null,
        categories: [],
        description: null,
        themeColor: null,
        createdAt: '2026-04-01T10:00:00+09:00',
        updatedAt: '2026-04-01T10:00:00+09:00',
        status: ConferenceStatus::Published,
    );
}

it('ConferencePresenter::toList は配列に入った各要素を toArray した結果を返す', function () {
    $conferences = [
        makePresenterTestConference('a'),
        makePresenterTestConference('b'),
        makePresenterTestConference('c'),
    ];

    $list = ConferencePresenter::toList($conferences);

    expect($list)->toHaveCount(3);
    expect($list[0]['conferenceId'] ?? null)->toBe('a');
    expect($list[1]['conferenceId'] ?? null)->toBe('b');
    expect($list[2]['conferenceId'] ?? null)->toBe('c');
});

it('ConferencePresenter::toList は空配列に対して空配列を返す', function () {
    expect(ConferencePresenter::toList([]))->toBe([]);
});

it('CategoryPresenter::toList は配列に入った各要素を toArray した結果を返す', function () {
    $categories = [
        new Category(
            categoryId: '11111111-2222-3333-4444-555555555555',
            name: 'PHP',
            slug: 'php',
            displayOrder: 100,
            axis: CategoryAxis::A,
            createdAt: '2026-04-01T10:00:00+09:00',
            updatedAt: '2026-04-01T10:00:00+09:00',
        ),
        new Category(
            categoryId: '22222222-3333-4444-5555-666666666666',
            name: 'Backend',
            slug: 'backend',
            displayOrder: 200,
            axis: null,
            createdAt: '2026-04-01T10:00:00+09:00',
            updatedAt: '2026-04-01T10:00:00+09:00',
        ),
    ];

    $list = CategoryPresenter::toList($categories);

    expect($list)->toHaveCount(2);
    expect($list[0]['slug'] ?? null)->toBe('php');
    expect($list[1]['slug'] ?? null)->toBe('backend');
});

it('CategoryPresenter::toList は空配列に対して空配列を返す', function () {
    expect(CategoryPresenter::toList([]))->toBe([]);
});

it('BuildStatusPresenter::toList は配列に入った各要素を toArray した結果を返す', function () {
    $statuses = [
        new BuildStatus(
            jobId: 'job-1',
            status: BuildJobStatus::Succeed,
            startedAt: '2026-05-01T10:00:00+09:00',
            commitId: null,
            commitMessage: null,
            endedAt: '2026-05-01T10:02:00+09:00',
            triggerSource: null,
        ),
        new BuildStatus(
            jobId: 'job-2',
            status: BuildJobStatus::Running,
            startedAt: '2026-05-02T10:00:00+09:00',
            commitId: null,
            commitMessage: null,
            endedAt: null,
            triggerSource: null,
        ),
    ];

    $list = BuildStatusPresenter::toList($statuses);

    expect($list)->toHaveCount(2);
    expect($list[0]['jobId'] ?? null)->toBe('job-1');
    expect($list[1]['jobId'] ?? null)->toBe('job-2');
});

it('BuildStatusPresenter::toList は空配列に対して空配列を返す', function () {
    expect(BuildStatusPresenter::toList([]))->toBe([]);
});
