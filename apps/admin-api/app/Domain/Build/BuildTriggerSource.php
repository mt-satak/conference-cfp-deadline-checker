<?php

namespace App\Domain\Build;

/**
 * ビルドが起動された経路。
 *
 * OpenAPI 仕様 (data/openapi.yaml の BuildStatus.triggerSource) の enum に対応。
 * Phase 5.3 以降は GitHub Actions の workflow event 名から判定する
 * (workflow_dispatch / push / schedule)。
 */
enum BuildTriggerSource: string
{
    /** 管理画面の「再ビルド」ボタンから手動 (= GitHub event: workflow_dispatch) */
    case AdminManual = 'admin-manual';
    /** 管理画面でカンファレンス / カテゴリ保存時に自動 */
    case AdminSave = 'admin-save';
    /** EventBridge 日次 (architecture.md §10、GitHub event: schedule) */
    case Scheduled = 'scheduled';
    /** リポジトリ push 由来 (GitHub event: push) */
    case RepositoryPush = 'repository-push';
}
