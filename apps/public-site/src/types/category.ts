/**
 * 公開フロントで扱う Category (カテゴリ) 型 (Issue #95 / Phase 4.4)。
 *
 * admin-api の `App\Domain\Categories\Category` をベースに、公開サイトで
 * 描画 / URL 生成に必要なフィールドだけに絞ったプロジェクション。
 *
 *  - id: UUID v4 (Conference.categories で参照される識別子)
 *  - slug: URL 用 short string (= /categories/{slug}/ のセグメント)
 *  - name: 人間可読の名前 (チップ表示等で使う)
 */
export interface Category {
    readonly id: string;
    readonly slug: string;
    readonly name: string;
}
