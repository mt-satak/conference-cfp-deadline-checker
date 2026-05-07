<?php

declare(strict_types=1);

use App\Domain\GitHubApp\GitHubAppCredentials;
use App\Domain\GitHubApp\InstallationToken;
use App\Infrastructure\GitHubApp\FirebaseGitHubAppClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Http;

/**
 * FirebaseGitHubAppClient の単体テスト (Phase 5.3 / Issue #110)。
 *
 * テスト戦略:
 *  - JWT 生成: テスト内で生成した RSA key pair で署名して payload を検証
 *  - GitHub API 呼出: Http::fake() で /app/installations/{id}/access_tokens と
 *    /repos/{o}/{r}/actions/workflows/{f}/dispatches と /actions/workflows/{f}/runs を mock
 *
 * Http facade は Laravel の internal なので Pest の TestCase 経由で初期化が要る。
 * tests/Pest.php で Unit ディレクトリ全体が Tests\TestCase (= Laravel boot 済) に
 * bind されているため、ここでは個別 uses() は書かない。
 */

/** RSA key pair 生成 (テスト用、PHP openssl). */
function makeRsaKeyPair(): array
{
    $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $resource = openssl_pkey_new($config);
    if ($resource === false) {
        throw new RuntimeException('openssl_pkey_new failed');
    }
    openssl_pkey_export($resource, $privateKey);
    $details = openssl_pkey_get_details($resource);
    $publicKey = $details['key'];

    return ['private' => $privateKey, 'public' => $publicKey];
}

describe('FirebaseGitHubAppClient::getInstallationToken', function () {
    it('private key で JWT 署名 → installation token API を叩いて InstallationToken を返す', function () {
        // Given: テスト用 RSA key pair
        $keys = makeRsaKeyPair();
        $credentials = new GitHubAppCredentials(
            appId: '123456',
            installationId: '789012',
            privateKey: $keys['private'],
        );

        // GitHub API の応答を mock (token + expires_at)
        Http::fake([
            'https://api.github.com/app/installations/789012/access_tokens' => Http::response([
                'token' => 'ghs_test_xxxxxxxx',
                'expires_at' => '2026-05-07T13:00:00Z',
                'permissions' => ['actions' => 'write'],
                'repository_selection' => 'selected',
            ], 201),
        ]);

        $client = new FirebaseGitHubAppClient;

        // When
        $token = $client->getInstallationToken($credentials);

        // Then: token / 失効時刻が期待通り
        expect($token)->toBeInstanceOf(InstallationToken::class);
        expect($token->token)->toBe('ghs_test_xxxxxxxx');
        expect($token->expiresAt->format(DateTimeInterface::ATOM))->toBe('2026-05-07T13:00:00+00:00');

        // Authorization ヘッダで送られた JWT が public key で復号でき、
        // iss = appId であることを検証する
        Http::assertSent(function ($request) use ($keys) {
            $authHeader = $request->header('Authorization')[0] ?? '';
            if (! preg_match('/^Bearer (.+)$/', $authHeader, $m)) {
                return false;
            }
            $jwt = $m[1];
            $decoded = JWT::decode($jwt, new Key($keys['public'], 'RS256'));

            return $decoded->iss === '123456';
        });
    });

    it('GitHub API が 401 を返すと RuntimeException', function () {
        // Given
        $keys = makeRsaKeyPair();
        $credentials = new GitHubAppCredentials('123456', '789012', $keys['private']);
        Http::fake([
            'https://api.github.com/app/installations/789012/access_tokens' => Http::response(
                ['message' => 'Bad credentials'],
                401,
            ),
        ]);

        $client = new FirebaseGitHubAppClient;

        // When / Then
        expect(fn () => $client->getInstallationToken($credentials))
            ->toThrow(RuntimeException::class);
    });
});

describe('FirebaseGitHubAppClient::dispatchWorkflow', function () {
    it('workflow_dispatch API に ref を POST する', function () {
        // Given
        $token = new InstallationToken(
            token: 'ghs_test',
            expiresAt: new DateTimeImmutable('2026-05-07T13:00:00+09:00'),
        );
        Http::fake([
            'https://api.github.com/repos/mt-satak/conference-cfp-deadline-checker/actions/workflows/deploy.yml/dispatches' => Http::response('', 204),
        ]);

        $client = new FirebaseGitHubAppClient;

        // When
        $client->dispatchWorkflow(
            $token,
            'mt-satak',
            'conference-cfp-deadline-checker',
            'deploy.yml',
            'main',
        );

        // Then: Authorization ヘッダ + body.ref を検証
        Http::assertSent(function ($request) {
            $body = $request->data();
            $auth = $request->header('Authorization')[0] ?? '';

            return $body['ref'] === 'main' && $auth === 'Bearer ghs_test';
        });
    });

    it('204 以外を返すと RuntimeException', function () {
        // Given
        $token = new InstallationToken('ghs_x', new DateTimeImmutable('+1 hour'));
        Http::fake([
            'https://api.github.com/repos/o/r/actions/workflows/deploy.yml/dispatches' => Http::response(
                ['message' => 'Workflow not found'],
                404,
            ),
        ]);

        $client = new FirebaseGitHubAppClient;

        // When / Then
        expect(fn () => $client->dispatchWorkflow($token, 'o', 'r', 'deploy.yml', 'main'))
            ->toThrow(RuntimeException::class);
    });
});

describe('FirebaseGitHubAppClient::listWorkflowRuns', function () {
    it('workflow runs API を叩いて workflow_runs 配列を返す', function () {
        // Given
        $token = new InstallationToken('ghs_x', new DateTimeImmutable('+1 hour'));
        Http::fake([
            'https://api.github.com/repos/mt-satak/conference-cfp-deadline-checker/actions/workflows/deploy.yml/runs*' => Http::response([
                'total_count' => 2,
                'workflow_runs' => [
                    ['id' => 1, 'status' => 'completed', 'conclusion' => 'success'],
                    ['id' => 2, 'status' => 'in_progress', 'conclusion' => null],
                ],
            ], 200),
        ]);

        $client = new FirebaseGitHubAppClient;

        // When
        $runs = $client->listWorkflowRuns(
            $token,
            'mt-satak',
            'conference-cfp-deadline-checker',
            'deploy.yml',
            10,
        );

        // Then
        expect($runs)->toHaveCount(2);
        expect($runs[0]['id'])->toBe(1);
        expect($runs[1]['status'])->toBe('in_progress');
    });

    it('per_page クエリパラメータが limit と一致する', function () {
        // Given
        $token = new InstallationToken('ghs_x', new DateTimeImmutable('+1 hour'));
        Http::fake([
            'https://api.github.com/*' => Http::response([
                'workflow_runs' => [],
            ], 200),
        ]);

        $client = new FirebaseGitHubAppClient;

        // When
        $client->listWorkflowRuns($token, 'o', 'r', 'deploy.yml', 5);

        // Then
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'per_page=5');
        });
    });

    it('200 以外を返すと RuntimeException', function () {
        // Given
        $token = new InstallationToken('ghs_x', new DateTimeImmutable('+1 hour'));
        Http::fake([
            'https://api.github.com/*' => Http::response('', 500),
        ]);

        $client = new FirebaseGitHubAppClient;

        // When / Then
        expect(fn () => $client->listWorkflowRuns($token, 'o', 'r', 'deploy.yml', 10))
            ->toThrow(RuntimeException::class);
    });
});
