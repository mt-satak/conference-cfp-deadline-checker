<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHubApp;

use App\Domain\Build\BuildServiceNotConfiguredException;
use App\Domain\Build\BuildTriggerer;
use App\Domain\GitHubApp\GitHubAppCredentials;
use Illuminate\Support\Carbon;

/**
 * BuildTriggerer の GitHub Actions (workflow_dispatch) 実装 (Phase 5.3)。
 *
 * 流れ:
 *  1. Secrets Manager から渡された 3 値 (appId / installationId / privateKey) で
 *     GitHubAppCredentials を組み立てる
 *  2. GitHubAppClient.getInstallationToken() で installation access token を取得
 *  3. GitHubAppClient.dispatchWorkflow() で deploy.yml を main 上で起動
 *
 * Secrets Manager 値が config から null/空文字で渡された場合は
 * BuildServiceNotConfiguredException を欠けている項目別に投げる。HTTP 層で
 * 503 SERVICE_UNAVAILABLE に整形される (OpenAPI 仕様の Build endpoint 503 ケース)。
 */
class GitHubActionsBuildTriggerer implements BuildTriggerer
{
    public function __construct(
        private readonly GitHubAppClient $client,
        private readonly ?string $appId,
        private readonly ?string $installationId,
        private readonly ?string $privateKey,
        private readonly string $owner,
        private readonly string $repo,
        private readonly string $workflowFileName,
        private readonly string $ref,
    ) {}

    /**
     * @throws BuildServiceNotConfiguredException
     */
    public function trigger(): string
    {
        $credentials = $this->resolveCredentials();
        $token = $this->client->getInstallationToken($credentials);
        $this->client->dispatchWorkflow(
            $token,
            $this->owner,
            $this->repo,
            $this->workflowFileName,
            $this->ref,
        );

        return Carbon::now('Asia/Tokyo')->toIso8601String();
    }

    /**
     * 設定 3 値の空チェックを Domain 例外に変換する。
     *
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
}
