<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHubApp;

use App\Domain\GitHubApp\GitHubAppCredentials;
use App\Domain\GitHubApp\InstallationToken;
use RuntimeException;

/**
 * GitHub App API との通信境界 (Phase 5.3 / Issue #110)。
 *
 * Infrastructure 層内で BuildTriggerer / BuildStatusReader 実装が共通利用する
 * 低レベル client。Domain 層からは直接参照させず、Build aggregate の
 * trigger / status read 経路だけが抽象として見える設計。
 *
 * 実装は FirebaseGitHubAppClient (firebase/php-jwt + Laravel HTTP クライアント)。
 * テストでは Mockery で interface を mock する。
 */
interface GitHubAppClient
{
    /**
     * GitHub App の private key で JWT 署名 → installation access token を取得する。
     *
     * @throws RuntimeException GitHub API 応答が想定外 / 通信エラー
     */
    public function getInstallationToken(GitHubAppCredentials $credentials): InstallationToken;

    /**
     * 指定 workflow を ref 上で起動する (workflow_dispatch)。
     *
     * @throws RuntimeException GitHub API 応答が想定外 / 通信エラー
     */
    public function dispatchWorkflow(
        InstallationToken $token,
        string $owner,
        string $repo,
        string $workflowFileName,
        string $ref,
    ): void;

    /**
     * 直近の workflow runs を取得する (新しい順)。
     *
     * GitHub API のレスポンス JSON を生の連想配列のまま返す。
     * BuildStatus への変換は呼び出し側 (GitHubActionsBuildStatusReader) で行う。
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException GitHub API 応答が想定外 / 通信エラー
     */
    public function listWorkflowRuns(
        InstallationToken $token,
        string $owner,
        string $repo,
        string $workflowFileName,
        int $limit,
    ): array;
}
