<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHubApp;

use App\Domain\GitHubApp\GitHubAppCredentials;
use App\Domain\GitHubApp\InstallationToken;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GitHubAppClient の Firebase JWT + Laravel HTTP クライアント実装 (Phase 5.3)。
 *
 * 認証フロー:
 *  1. GitHubAppCredentials の private key (PEM) で RS256 JWT を署名
 *     - payload: iss=appId, iat=now-60s, exp=now+10min
 *     - 60s 戻すのは GitHub と admin-api 間のクロックスキュー対策 (公式推奨)
 *  2. POST /app/installations/{id}/access_tokens に JWT を Bearer で渡す
 *  3. 受け取った installation token + expires_at を InstallationToken VO に詰める
 *
 * dispatchWorkflow / listWorkflowRuns は installation token を Bearer で送るだけ。
 *
 * Http::fake() で Unit test できるように Laravel Http facade を直接使う。
 * Guzzle 等の DI 注入は不要 (Http facade がテスト用置換ポイントを提供する)。
 */
class FirebaseGitHubAppClient implements GitHubAppClient
{
    /** GitHub API ベース URL (公式 docs に合わせる、エンタープライズ未対応) */
    private const API_BASE = 'https://api.github.com';

    /** GitHub 推奨の Accept ヘッダ (REST API v3) */
    private const ACCEPT_HEADER = 'application/vnd.github+json';

    /** JWT 有効期間 (秒) — GitHub 上限 10 分 */
    private const JWT_LIFETIME_SECONDS = 600;

    /** クロックスキュー対策の遡及秒数 — GitHub 公式推奨 */
    private const JWT_BACKDATED_SECONDS = 60;

    public function getInstallationToken(GitHubAppCredentials $credentials): InstallationToken
    {
        $jwt = $this->createJwt($credentials);

        $response = Http::withHeaders([
            'Accept' => self::ACCEPT_HEADER,
            'Authorization' => "Bearer {$jwt}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post(self::API_BASE."/app/installations/{$credentials->installationId}/access_tokens");

        $this->ensureSuccess($response, 'getInstallationToken');

        $body = $response->json();
        if (! is_array($body) || ! is_string($body['token'] ?? null) || ! is_string($body['expires_at'] ?? null)) {
            throw new RuntimeException('GitHub installation token response is malformed');
        }

        return new InstallationToken(
            token: $body['token'],
            expiresAt: new DateTimeImmutable($body['expires_at']),
        );
    }

    public function dispatchWorkflow(
        InstallationToken $token,
        string $owner,
        string $repo,
        string $workflowFileName,
        string $ref,
    ): void {
        $url = self::API_BASE."/repos/{$owner}/{$repo}/actions/workflows/{$workflowFileName}/dispatches";

        $response = Http::withHeaders([
            'Accept' => self::ACCEPT_HEADER,
            'Authorization' => "Bearer {$token->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->post($url, ['ref' => $ref]);

        $this->ensureSuccess($response, 'dispatchWorkflow');
    }

    public function listWorkflowRuns(
        InstallationToken $token,
        string $owner,
        string $repo,
        string $workflowFileName,
        int $limit,
    ): array {
        $url = self::API_BASE."/repos/{$owner}/{$repo}/actions/workflows/{$workflowFileName}/runs";

        $response = Http::withHeaders([
            'Accept' => self::ACCEPT_HEADER,
            'Authorization' => "Bearer {$token->token}",
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get($url, ['per_page' => $limit]);

        $this->ensureSuccess($response, 'listWorkflowRuns');

        $body = $response->json();
        if (! is_array($body)) {
            return [];
        }
        $runs = $body['workflow_runs'] ?? [];
        if (! is_array($runs)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $runs */
        return array_values($runs);
    }

    /**
     * GitHub App の private key で RS256 JWT を署名する。
     */
    private function createJwt(GitHubAppCredentials $credentials): string
    {
        $now = time();
        $payload = [
            'iat' => $now - self::JWT_BACKDATED_SECONDS,
            'exp' => $now + self::JWT_LIFETIME_SECONDS,
            'iss' => $credentials->appId,
        ];

        return JWT::encode($payload, $credentials->privateKey, 'RS256');
    }

    /**
     * 2xx 以外を RuntimeException 化する。GitHub 応答の status / body を含めて
     * 後続のオペレータが原因を特定しやすいメッセージにする。
     */
    private function ensureSuccess(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'GitHub API call failed in %s: status=%d body=%s',
            $context,
            $response->status(),
            $response->body(),
        ));
    }
}
