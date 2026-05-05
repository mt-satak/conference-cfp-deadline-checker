<?php

namespace App\Application\Conferences;

use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceStatus;

/**
 * CreateConferenceUseCase の入力 DTO。
 *
 * Domain Entity (Conference) との違い:
 * - conferenceId / createdAt / updatedAt は持たない (UseCase が生成・付与する)
 * - すべて呼び出し元 (HTTP Controller の FormRequest 等) から受け取る生データ
 *
 * バリデーション (URL 形式 / 日付整合性 / categories の参照整合性 等) は
 * 上位の HTTP 層 (FormRequest) で行い、本 DTO は型受け渡しのみに専念する。
 *
 * status による必須/任意の差分は Conference Entity と一致 (Issue #41)。
 * status は default Published で、Phase 0.5 導入前の既存呼出と後方互換。
 */
final readonly class CreateConferenceInput
{
    /**
     * @param  string[]  $categories  categories.categoryId の配列 (UUID v4)。Draft では空配列可。
     */
    public function __construct(
        public string $name,
        public ?string $trackName,
        public string $officialUrl,
        public ?string $cfpUrl,
        public ?string $eventStartDate,
        public ?string $eventEndDate,
        public ?string $venue,
        public ?ConferenceFormat $format,
        public ?string $cfpStartDate,
        public ?string $cfpEndDate,
        public array $categories,
        public ?string $description,
        public ?string $themeColor,
        public ConferenceStatus $status = ConferenceStatus::Published,
    ) {}
}
