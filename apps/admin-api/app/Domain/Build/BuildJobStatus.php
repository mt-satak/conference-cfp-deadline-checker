<?php

namespace App\Domain\Build;

/**
 * AWS Amplify ビルドジョブのステータス。
 *
 * OpenAPI 仕様 (data/openapi.yaml の BuildStatus.status) の enum に対応。
 * Amplify SDK が返す Job.status の値をそのまま転記する設計。
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
