import { App } from 'aws-cdk-lib';
import { describe, it, expect } from 'vitest';
import { getContextString } from './context-helper';

/**
 * getContextString ヘルパの単体テスト (Issue #178 #6)。
 *
 * bin/cfp-deadline-checker.ts で `app.node.tryGetContext(key) as string | undefined`
 * パターンが 11 箇所重複していた。`as` キャストは unsafe (型が嘘をつく余地がある) なので、
 * helper で「string か undefined」を保証して呼び出し側を簡潔化する。
 */

describe('getContextString', () => {
    it('context が文字列で渡されている時はその文字列を返す', () => {
        // Given: --context key=value 相当の context が設定された App
        const app = new App({ context: { myKey: 'hello' } });

        // When: 同じキーで取得
        const result = getContextString(app, 'myKey');

        // Then: 設定値がそのまま返る
        expect(result).toBe('hello');
    });

    it('context が未設定でデフォルトも無い時は undefined を返す', () => {
        // Given: context 何も渡さない App
        const app = new App();

        // When: 存在しないキーで取得
        const result = getContextString(app, 'missingKey');

        // Then: undefined (= 「指定なし」を呼び出し側が判定可能)
        expect(result).toBeUndefined();
    });

    it('context が未設定でデフォルトを渡した時はデフォルト値を返す', () => {
        // Given: context 何も渡さない App
        const app = new App();

        // When: デフォルト付きで取得
        const result = getContextString(app, 'missingKey', 'fallback');

        // Then: デフォルト値
        expect(result).toBe('fallback');
    });

    it('context が文字列で渡されている時はデフォルトより context が優先される', () => {
        // Given: context が設定された App
        const app = new App({ context: { myKey: 'fromContext' } });

        // When: デフォルト付きで取得
        const result = getContextString(app, 'myKey', 'fromDefault');

        // Then: context 値が勝つ (= 上書き不可だと意味が無い)
        expect(result).toBe('fromContext');
    });

    it('context に文字列以外の型 (例: number) が入っている時はデフォルト扱いになる', () => {
        // Given: 数値が context に入った App (= 想定外データ)
        // Why: tryGetContext() は any を返すので、--context 経由以外で
        //      非文字列が入る可能性がゼロではない。helper が安全側で防御する。
        const app = new App({ context: { weirdKey: 42 } });

        // When: デフォルト付きで取得
        const result = getContextString(app, 'weirdKey', 'safe-default');

        // Then: 非文字列は無視 → デフォルトに fallback
        expect(result).toBe('safe-default');
    });

    it('context に文字列以外の型 (例: number) が入っていてデフォルトも無い時は undefined を返す', () => {
        // Given: 数値が context に入った App
        const app = new App({ context: { weirdKey: 42 } });

        // When: デフォルト無しで取得
        const result = getContextString(app, 'weirdKey');

        // Then: undefined (= 呼び出し側に「想定外データだった」と伝わる)
        expect(result).toBeUndefined();
    });
});
