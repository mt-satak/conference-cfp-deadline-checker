/**
 * 公開フロントで扱う Conference (カンファレンス) 型 (Issue #86 / Phase 2)。
 *
 * admin-api の `App\Domain\Conferences\Conference` をベースに、公開サイトで
 * 描画に必要なフィールドだけに絞ったプロジェクション。
 *
 * Phase 2 では mock データで使い、Phase 4 で admin-api の公開 read-only API から
 * fetch して同じ shape にマップする想定。
 */
export type ConferenceFormat = 'online' | 'offline' | 'hybrid';

export interface Conference {
    /** UUID v4。admin-api の conferenceId と同じ識別子 */
    readonly id: string;
    /** カンファレンス名 (例: "PHP Conference Japan 2026") */
    readonly name: string;
    /** 公式サイト URL (https:// 必須) */
    readonly officialUrl: string;
    /** 開催開始日 YYYY-MM-DD。未定なら null */
    readonly eventStartDate: string | null;
    /** 開催終了日 YYYY-MM-DD。未定 / 単日開催なら null */
    readonly eventEndDate: string | null;
    /** 会場 (オフライン or hybrid 時) */
    readonly venue: string | null;
    /** 開催形式 */
    readonly format: ConferenceFormat | null;
    /** CfP 終了日 YYYY-MM-DD。null は未入力扱い */
    readonly cfpEndDate: string | null;
    /** カンファレンス説明 (オーガナイザの一言など) */
    readonly description: string | null;
    /** カテゴリ (将来 admin-api の categories と紐付ける) */
    readonly categories: readonly string[];
}
