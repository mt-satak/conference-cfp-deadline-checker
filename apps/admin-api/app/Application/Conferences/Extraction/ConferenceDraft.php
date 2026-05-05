<?php

namespace App\Application\Conferences\Extraction;

use App\Domain\Conferences\ConferenceFormat;

/**
 * LLM URL 抽出の結果として渡される未保存・未検証 DTO (Issue #40 Phase 3)。
 *
 * Conference Entity との違い:
 * - conferenceId / createdAt / updatedAt / status を持たない
 *   (UseCase が保存時に補完。LLM 抽出結果は status=Draft で保存する想定)
 * - sourceUrl 以外の全フィールドが null 許容
 *   (LLM が一部しか抽出できない場合の中間状態を表現)
 * - categorySlugs は string[] (= LLM の "推測"。確定 categoryId UUID ではなく、
 *   HTTP 層 / Admin UI 側で CategoryRepository::findBySlug() で UUID 解決する)
 *
 * sourceUrl は「抽出元の URL」であり、抽出結果の officialUrl とは別概念
 * (LLM が公式トップページの URL を officialUrl として抽出することがある)。
 */
final readonly class ConferenceDraft
{
    /**
     * @param  string[]  $categorySlugs
     */
    public function __construct(
        public string $sourceUrl,
        public ?string $name = null,
        public ?string $trackName = null,
        public ?string $officialUrl = null,
        public ?string $cfpUrl = null,
        public ?string $eventStartDate = null,
        public ?string $eventEndDate = null,
        public ?string $venue = null,
        public ?ConferenceFormat $format = null,
        public ?string $cfpStartDate = null,
        public ?string $cfpEndDate = null,
        public array $categorySlugs = [],
        public ?string $description = null,
        public ?string $themeColor = null,
    ) {}
}
