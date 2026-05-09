<?php

namespace App\Domain\Conferences;

/**
 * カンファレンスの Entity (ドメイン層、Aggregate Root)。
 *
 * `conferenceId` を識別子に持つ Entity であり、Conferences Aggregate の Root。
 * Eloquent / DynamoDB SDK には依存しない純粋な構造体で、Repository (interface) を
 * 介して永続化層と境界を切ることで、テスト時には Repository をモックしやすくなる。
 *
 * 設計判断:
 * - 日付 / 日時は string (ISO 8601) で保持する。
 *   DynamoDB 側もこの形式の文字列で格納するため、無駄な変換を挟まない。
 *   ドメイン層で日付演算が必要になった時点で DateTimeImmutable 化を検討する。
 * - readonly class により全プロパティはコンストラクタ後変更不可 (PHP 8.2+)。
 *   状態遷移は新しい Conference インスタンスを返す形で表現する。
 *
 * 各プロパティの仕様は data/openapi.yaml の Conference スキーマ
 * および data/schema.md の conferences テーブル定義を参照。
 */
final readonly class Conference
{
    /**
     * @param  string[]  $categories  categories.categoryId の配列 (UUID v4)。Draft では空配列可。
     *
     * status による必須/任意の差分:
     * - 必須 (両状態): conferenceId, name, officialUrl, createdAt, updatedAt, status
     * - 任意 (Draft では null 可): cfpUrl, eventStartDate, eventEndDate, venue, format, cfpEndDate
     * - 任意 (元から両状態 null 可): trackName, cfpStartDate, description, themeColor
     *
     * Published バリデーションは HTTP 層 (FormRequest) で実施。Domain Entity 側は
     * 「Draft 中の中間状態」と「Published の確定状態」の両方を表現できる柔軟な型に留める。
     *
     * status はデフォルト Published で既存呼出との後方互換を取る (Issue #41 PR-1 / PR-2)。
     */
    public function __construct(
        public string $conferenceId,
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
        public string $createdAt,
        public string $updatedAt,
        public ConferenceStatus $status = ConferenceStatus::Published,
    ) {}

    /**
     * 開催日を過ぎたか判定する純粋関数 (Issue #165)。
     *
     * 比較基準:
     * - eventEndDate が非 null ならそれを基準にする
     * - eventEndDate が null なら eventStartDate を基準 (= 1 日開催想定)
     * - 両方 null なら判定不能 → false (= Draft の不完全データを誤って archive しない)
     *
     * 当日中 (`基準日 === today`) は false を返す (= 終了日翌日からアーカイブ対象)。
     * これは「終了直後にすぐ消える」ことを避け、運用者が当日中は一覧で確認できる UX のため。
     *
     * 文字列比較で OK な理由: ISO 8601 の YYYY-MM-DD は辞書順 = 時系列順なので。
     */
    public function isPastEvent(string $today): bool
    {
        $referenceDate = $this->eventEndDate ?? $this->eventStartDate;
        if ($referenceDate === null) {
            return false;
        }

        return $referenceDate < $today;
    }

    /**
     * status と updatedAt のみ差し替えた新しい Conference を返す (Issue #165)。
     *
     * readonly class なので元インスタンスは不変。Application 層でアーカイブ処理時に使う。
     * 他フィールド全てを保持しつつ、ステータス遷移と更新時刻だけを表現する。
     */
    public function withStatus(ConferenceStatus $status, string $updatedAt): self
    {
        return new self(
            conferenceId: $this->conferenceId,
            name: $this->name,
            trackName: $this->trackName,
            officialUrl: $this->officialUrl,
            cfpUrl: $this->cfpUrl,
            eventStartDate: $this->eventStartDate,
            eventEndDate: $this->eventEndDate,
            venue: $this->venue,
            format: $this->format,
            cfpStartDate: $this->cfpStartDate,
            cfpEndDate: $this->cfpEndDate,
            categories: $this->categories,
            description: $this->description,
            themeColor: $this->themeColor,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt,
            status: $status,
        );
    }
}
