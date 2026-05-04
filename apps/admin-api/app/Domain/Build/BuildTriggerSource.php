<?php

namespace App\Domain\Build;

/**
 * ビルドが起動された経路。
 *
 * OpenAPI 仕様 (data/openapi.yaml の BuildStatus.triggerSource) の enum に対応。
 * Amplify ジョブのメタ情報から推定する (Amplify 側に直接フィールドはないため、
 * commitMessage や jobReason 等から判定する想定)。
 */
enum BuildTriggerSource: string
{
    /** 管理画面の「再ビルド」ボタンから手動 */
    case AdminManual = 'admin-manual';
    /** 管理画面でカンファレンス / カテゴリ保存時に自動 */
    case AdminSave = 'admin-save';
    /** EventBridge 日次 (architecture.md §10) */
    case Scheduled = 'scheduled';
    /** リポジトリ push 由来 (Amplify Git 連携時) */
    case RepositoryPush = 'repository-push';
}
