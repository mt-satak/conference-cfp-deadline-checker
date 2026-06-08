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
     * @param  array<string, array{old: mixed, new: mixed}>|null  $pendingChanges
     *                                                                             Issue #188: AutoCrawl が検知した「人間レビュー待ちの保留差分」。
     *                                                                             key は変更フィールド名 (cfpUrl / cfpEndDate 等)、value は {old, new}。
     *                                                                             null = 保留差分なし、空配列 = 「過去あった保留が解消された」状態を null と区別したい場合。
     *                                                                             Public Presenter (#178 #4 PUBLIC_FIELDS) には含めない (= 公開漏洩防止)。
     * @param  array{discoveredAt: string, sourceId: string}|null  $discoveryMetadata
     *                                                                                 Issue #200 PR-2: 週次自動 CfP 発見 (PR-3 で実装) が投入した Draft の出自情報。
     *                                                                                 null = 既存・手動作成 / 値あり = DiscoverConferencesUseCase が投入。
     *                                                                                 admin 一覧で「🆕 自動発見」バッジ表示の判定に使う (isRecentlyDiscovered)。
     *                                                                                 Public Presenter には含めない (= PUBLIC_FIELDS ホワイトリストで構造的に保護)。
     *
     * status による必須/任意の差分:
     * - 必須 (両状態): conferenceId, name, officialUrl, createdAt, updatedAt, status
     * - 任意 (Draft では null 可): cfpUrl, eventStartDate, eventEndDate, venue, format, cfpEndDate
     * - 任意 (元から両状態 null 可): trackName, cfpStartDate, description, themeColor, pendingChanges, discoveryMetadata
     *
     * Published バリデーションは HTTP 層 (FormRequest) で実施。Domain Entity 側は
     * 「Draft 中の中間状態」と「Published の確定状態」の両方を表現できる柔軟な型に留める。
     *
     * status はデフォルト Published で既存呼出との後方互換を取る (Issue #41 PR-1 / PR-2)。
     * pendingChanges / discoveryMetadata もデフォルト null で既存 caller への後方互換を取る。
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
        public ?array $pendingChanges = null,
        public ?array $discoveryMetadata = null,
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
            pendingChanges: $this->pendingChanges,
            discoveryMetadata: $this->discoveryMetadata,
        );
    }

    /**
     * Issue #200 PR-2: 「直近 $withinDays 日以内に自動発見された Draft か」を判定。
     *
     * - discoveryMetadata 無し (= 手動作成) は常に false
     * - discoveryMetadata 内の discoveredAt が ISO 8601 文字列で、その YYYY-MM-DD 部分が
     *   $today から $withinDays 日以内なら true
     * - 不正データ (空 / 形式不正) は防御的に false
     *
     * 文字列比較で済むのは ISO 8601 YYYY-MM-DD が辞書順 = 時系列順だから (= isPastEvent と同方針)。
     * 14 日は admin が「新しい」と感じる範囲の標準値。バッジ表示の閾値として一覧画面 (Blade) から呼ぶ。
     */
    public function isRecentlyDiscovered(string $today, int $withinDays = 14): bool
    {
        if ($this->discoveryMetadata === null) {
            return false;
        }
        $discoveredAt = $this->discoveryMetadata['discoveredAt'] ?? '';
        if ($discoveredAt === '') {
            return false;
        }

        // ISO 8601 の先頭 10 文字 = YYYY-MM-DD
        $discoveredDate = substr($discoveredAt, 0, 10);
        if (strlen($discoveredDate) !== 10) {
            return false;
        }

        // 14 日前 を today から計算した境界 (inclusive)
        $timestamp = strtotime($today.' -'.$withinDays.' days');
        if ($timestamp === false) {
            return false;
        }
        $boundary = date('Y-m-d', $timestamp);

        return $discoveredDate >= $boundary && $discoveredDate <= $today;
    }
}
