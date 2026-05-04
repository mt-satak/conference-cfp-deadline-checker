<?php

use App\Domain\Build\BuildJobStatus;
use App\Domain\Build\BuildStatus;
use App\Domain\Build\BuildTriggerSource;

/**
 * BuildStatus Value Object と関連 enum の単体テスト。
 */
it('全フィールドを指定して BuildStatus を構築できる', function () {
    // Given/When
    $status = new BuildStatus(
        jobId: 'job-1',
        status: BuildJobStatus::Succeed,
        startedAt: '2026-05-04T10:00:00+09:00',
        commitId: 'abc123',
        commitMessage: 'fix typo',
        endedAt: '2026-05-04T10:02:00+09:00',
        triggerSource: BuildTriggerSource::AdminManual,
    );

    // Then
    expect($status->jobId)->toBe('job-1');
    expect($status->status)->toBe(BuildJobStatus::Succeed);
    expect($status->startedAt)->toBe('2026-05-04T10:00:00+09:00');
    expect($status->commitId)->toBe('abc123');
    expect($status->commitMessage)->toBe('fix typo');
    expect($status->endedAt)->toBe('2026-05-04T10:02:00+09:00');
    expect($status->triggerSource)->toBe(BuildTriggerSource::AdminManual);
});

it('optional フィールドを null で構築できる', function () {
    // Given/When
    $status = new BuildStatus(
        jobId: 'job-2',
        status: BuildJobStatus::Running,
        startedAt: '2026-05-04T10:00:00+09:00',
        commitId: null,
        commitMessage: null,
        endedAt: null,
        triggerSource: null,
    );

    // Then
    expect($status->commitId)->toBeNull();
    expect($status->commitMessage)->toBeNull();
    expect($status->endedAt)->toBeNull();
    expect($status->triggerSource)->toBeNull();
});

it('BuildJobStatus enum は OpenAPI 仕様の 7 値を持つ', function () {
    $values = array_column(BuildJobStatus::cases(), 'value');
    expect($values)->toBe([
        'PENDING', 'PROVISIONING', 'RUNNING', 'FAILED',
        'SUCCEED', 'CANCELLING', 'CANCELLED',
    ]);
});

it('BuildTriggerSource enum は OpenAPI 仕様の 4 値を持つ', function () {
    $values = array_column(BuildTriggerSource::cases(), 'value');
    expect($values)->toBe([
        'admin-manual', 'admin-save', 'scheduled', 'repository-push',
    ]);
});
