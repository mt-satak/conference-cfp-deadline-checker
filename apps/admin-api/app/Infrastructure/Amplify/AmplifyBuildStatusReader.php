<?php

namespace App\Infrastructure\Amplify;

use App\Domain\Build\BuildJobStatus;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildStatus;
use App\Domain\Build\BuildStatusReader;
use Aws\Amplify\AmplifyClient;
use DateTimeInterface;

/**
 * BuildStatusReader の AWS Amplify SDK 実装。
 *
 * Amplify ListJobs API を呼び、Job 情報を Domain 層の BuildStatus に変換する。
 * appId / branchName が未設定の場合は BuildServiceNotConfiguredException を投げる
 * (HTTP 層で 503 に整形)。
 */
class AmplifyBuildStatusReader implements BuildStatusReader
{
    public function __construct(
        private readonly AmplifyClient $client,
        private readonly ?string $appId,
        private readonly string $branchName,
    ) {}

    /**
     * @return BuildStatus[] 新しい順
     *
     * @throws BuildServiceNotConfiguredException
     */
    public function listRecent(int $limit): array
    {
        if ($this->appId === null || $this->appId === '') {
            throw BuildServiceNotConfiguredException::appIdMissing();
        }

        $result = $this->client->listJobs([
            'appId' => $this->appId,
            'branchName' => $this->branchName,
            'maxResults' => $limit,
        ]);

        /** @var array<int, array<string, mixed>> $summaries */
        $summaries = $result['jobSummaries'] ?? [];

        $statuses = [];
        foreach ($summaries as $summary) {
            $statuses[] = $this->toBuildStatus($summary);
        }

        return $statuses;
    }

    /**
     * Amplify SDK の Job summary 配列から Domain BuildStatus に変換する。
     *
     * @param  array<string, mixed>  $summary
     */
    private function toBuildStatus(array $summary): BuildStatus
    {
        return new BuildStatus(
            jobId: $this->stringify($summary, 'jobId'),
            status: $this->parseStatus($summary['status'] ?? ''),
            startedAt: $this->stringifyDate($summary['startTime'] ?? null),
            commitId: $this->nullableString($summary, 'commitId'),
            commitMessage: $this->nullableString($summary, 'commitMessage'),
            endedAt: $this->nullableDate($summary['endTime'] ?? null),
            // triggerSource は Amplify SDK の Job summary に直接フィールドが無い。
            // jobReason などからの推定は後続 PR で行う想定。現状は null で運用。
            triggerSource: null,
        );
    }

    /**
     * Amplify SDK の status 値を BuildJobStatus に変換する。
     * 想定外の値が来た場合は Pending 扱いで防御 (フロント側に表示は出るが落ちない)。
     */
    private function parseStatus(mixed $value): BuildJobStatus
    {
        if (! is_string($value)) {
            return BuildJobStatus::Pending;
        }

        return BuildJobStatus::tryFrom($value) ?? BuildJobStatus::Pending;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function stringify(array $item, string $key): string
    {
        $value = $item[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function nullableString(array $item, string $key): ?string
    {
        if (! array_key_exists($key, $item) || $item[$key] === null) {
            return null;
        }
        $value = $item[$key];

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * AWS SDK の DateTimeResult (DateTimeInterface 実装) を ISO 8601 文字列に変換する。
     * 必須フィールド (startedAt) 用、null が来た場合は空文字列。
     */
    private function stringifyDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (is_string($value)) {
            return $value;
        }

        return '';
    }

    /**
     * 任意フィールド用の date 変換。null / 不正型は null を返す。
     */
    private function nullableDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
