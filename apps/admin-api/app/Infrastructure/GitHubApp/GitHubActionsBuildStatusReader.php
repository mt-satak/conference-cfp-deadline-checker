<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHubApp;

use App\Domain\Build\BuildJobStatus;
use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildStatus;
use App\Domain\Build\BuildStatusReader;
use App\Domain\Build\BuildTriggerSource;
use App\Domain\GitHubApp\GitHubAppCredentials;

/**
 * BuildStatusReader の GitHub Actions (workflow runs) 実装 (Phase 5.3)。
 *
 * 流れ:
 *  1. credentials を組み立て (3 値の空チェックを Domain 例外に変換)
 *  2. GitHubAppClient.getInstallationToken() で installation access token を取得
 *  3. GitHubAppClient.listWorkflowRuns() で生応答を受け取り、Domain BuildStatus に変換
 *
 * GitHub Actions API のレスポンス JSON を Domain enum にマップする方針:
 *  - status / conclusion → BuildJobStatus
 *  - event → BuildTriggerSource
 *  - id → jobId (string 化), created_at → startedAt, updated_at → endedAt (completed のみ)
 *  - head_sha → commitId, head_commit.message → commitMessage
 */
class GitHubActionsBuildStatusReader implements BuildStatusReader
{
    public function __construct(
        private readonly GitHubAppClient $client,
        private readonly ?string $appId,
        private readonly ?string $installationId,
        private readonly ?string $privateKey,
        private readonly string $owner,
        private readonly string $repo,
        private readonly string $workflowFileName,
    ) {}

    /**
     * @return BuildStatus[] 新しい順
     *
     * @throws BuildServiceNotConfiguredException
     */
    public function listRecent(int $limit): array
    {
        $credentials = $this->resolveCredentials();
        $token = $this->client->getInstallationToken($credentials);

        $runs = $this->client->listWorkflowRuns(
            $token,
            $this->owner,
            $this->repo,
            $this->workflowFileName,
            $limit,
        );

        return array_map(fn (array $run): BuildStatus => $this->toBuildStatus($run), $runs);
    }

    /**
     * @throws BuildServiceNotConfiguredException
     */
    private function resolveCredentials(): GitHubAppCredentials
    {
        if ($this->appId === null || $this->appId === '') {
            throw BuildServiceNotConfiguredException::appIdMissing();
        }
        if ($this->installationId === null || $this->installationId === '') {
            throw BuildServiceNotConfiguredException::installationIdMissing();
        }
        if ($this->privateKey === null || $this->privateKey === '') {
            throw BuildServiceNotConfiguredException::privateKeyMissing();
        }

        return new GitHubAppCredentials($this->appId, $this->installationId, $this->privateKey);
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function toBuildStatus(array $run): BuildStatus
    {
        $status = $this->parseStatus(
            is_string($run['status'] ?? null) ? $run['status'] : '',
            is_string($run['conclusion'] ?? null) ? $run['conclusion'] : null,
        );

        return new BuildStatus(
            jobId: $this->stringify($run, 'id'),
            status: $status,
            startedAt: $this->stringify($run, 'created_at'),
            commitId: $this->nullableString($run, 'head_sha'),
            commitMessage: $this->extractCommitMessage($run),
            // GitHub Actions の updated_at は run の最終変更時刻。completed 以外は
            // まだ進行中なので endedAt として返さず null にする。
            endedAt: $status === BuildJobStatus::Running || $status === BuildJobStatus::Pending
                ? null
                : $this->nullableString($run, 'updated_at'),
            triggerSource: $this->parseTriggerSource(
                is_string($run['event'] ?? null) ? $run['event'] : '',
            ),
        );
    }

    /**
     * GitHub Actions の (status, conclusion) を BuildJobStatus enum にマップする。
     *
     * BuildJobStatus enum を流用する都合上、未知の conclusion (skipped / neutral
     * / action_required 等) は Failed 扱いに寄せて運用画面で気付けるようにする。
     */
    private function parseStatus(string $status, ?string $conclusion): BuildJobStatus
    {
        if ($status === 'queued') {
            return BuildJobStatus::Pending;
        }
        if ($status === 'in_progress') {
            return BuildJobStatus::Running;
        }
        if ($status === 'completed') {
            return match ($conclusion) {
                'success' => BuildJobStatus::Succeed,
                'cancelled' => BuildJobStatus::Cancelled,
                default => BuildJobStatus::Failed,
            };
        }

        return BuildJobStatus::Pending;
    }

    /**
     * GitHub Actions の event 名を BuildTriggerSource enum にマップする。
     * 未知の event は null を返す (architecture.md §10 に未定義のため)。
     */
    private function parseTriggerSource(string $event): ?BuildTriggerSource
    {
        return match ($event) {
            'workflow_dispatch' => BuildTriggerSource::AdminManual,
            'push' => BuildTriggerSource::RepositoryPush,
            'schedule' => BuildTriggerSource::Scheduled,
            default => null,
        };
    }

    /**
     * head_commit.message から commit message を取り出す。
     *
     * @param  array<string, mixed>  $run
     */
    private function extractCommitMessage(array $run): ?string
    {
        $headCommit = $run['head_commit'] ?? null;
        if (! is_array($headCommit)) {
            return null;
        }
        $message = $headCommit['message'] ?? null;

        return is_string($message) ? $message : null;
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
}
