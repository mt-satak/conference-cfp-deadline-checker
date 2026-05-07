<?php

namespace App\Domain\Build;

/**
 * ビルドジョブのステータス。
 *
 * OpenAPI 仕様 (data/openapi.yaml の BuildStatus.status) の enum に対応。
 * 元々は AWS Amplify SDK の Job.status 値をそのまま転記したが、Phase 5.3 で
 * GitHub Actions 経路に移行した後も既存 enum 値を流用している
 * (GitHubActionsBuildStatusReader が GitHub の status/conclusion をこの enum に
 * マッピングする)。Provisioning / Cancelling は GitHub Actions の状態には対応
 * しないが、enum 削除は Blade view も含めた影響範囲が広いため別 Issue で扱う。
 */
enum BuildJobStatus: string
{
    case Pending = 'PENDING';
    case Provisioning = 'PROVISIONING';
    case Running = 'RUNNING';
    case Failed = 'FAILED';
    case Succeed = 'SUCCEED';
    case Cancelling = 'CANCELLING';
    case Cancelled = 'CANCELLED';
}
