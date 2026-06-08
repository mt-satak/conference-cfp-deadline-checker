<?php

declare(strict_types=1);

namespace App\Application\CfpSources;

/**
 * CfP ソース新規登録の入力 DTO (Issue #200 PR-1)。
 *
 * 入力源は admin Blade フォーム。バリデーション (URL 形式 / name 長さ等) は
 * HTTP 層 (FormRequest) で実施済の前提。
 */
final readonly class CreateCfpSourceInput
{
    public function __construct(
        public string $name,
        public string $url,
        public bool $enabled,
    ) {}
}
