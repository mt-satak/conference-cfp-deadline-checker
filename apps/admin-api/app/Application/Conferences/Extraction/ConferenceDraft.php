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

    /**
     * Publish 必須 6 項目 (cfpUrl / eventStartDate / eventEndDate / venue / format /
     * cfpEndDate) のいずれかが null かを返す (Issue #224)。
     *
     * 公式リンク条件付き follow の判定に使う。trackName / cfpStartDate / description /
     * themeColor / categorySlugs は Publish 必須ではないため判定対象外。
     * (= ConferenceController::missingPublishedFields と同じ項目集合)
     */
    public function isMissingPublishableField(): bool
    {
        return $this->cfpUrl === null
            || $this->eventStartDate === null
            || $this->eventEndDate === null
            || $this->venue === null
            || $this->format === null
            || $this->cfpEndDate === null;
    }

    /**
     * 自分の null フィールドを $other の値で補完した新しい Draft を返す (Issue #224)。
     *
     * 公式リンク follow で得た 2 ページ目の結果 ($other) を、1 ページ目 ($this) の
     * 非 null 値を優先しつつマージする。sourceUrl は $this を維持 (= 主たる抽出元)。
     * categorySlugs は配列のため「$this が空配列なら $other を採用」とする。
     */
    public function mergeFillingNullsFrom(self $other): self
    {
        return new self(
            sourceUrl: $this->sourceUrl,
            name: $this->name ?? $other->name,
            trackName: $this->trackName ?? $other->trackName,
            officialUrl: $this->officialUrl ?? $other->officialUrl,
            cfpUrl: $this->cfpUrl ?? $other->cfpUrl,
            eventStartDate: $this->eventStartDate ?? $other->eventStartDate,
            eventEndDate: $this->eventEndDate ?? $other->eventEndDate,
            venue: $this->venue ?? $other->venue,
            format: $this->format ?? $other->format,
            cfpStartDate: $this->cfpStartDate ?? $other->cfpStartDate,
            cfpEndDate: $this->cfpEndDate ?? $other->cfpEndDate,
            categorySlugs: $this->categorySlugs === [] ? $other->categorySlugs : $this->categorySlugs,
            description: $this->description ?? $other->description,
            themeColor: $this->themeColor ?? $other->themeColor,
        );
    }
}
