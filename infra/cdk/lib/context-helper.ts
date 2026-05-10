import type { App } from 'aws-cdk-lib';

/**
 * CDK context から文字列値を型安全に取得するヘルパ (Issue #178 #6)。
 *
 * `app.node.tryGetContext(key)` は any 型を返すため、呼び出し側で
 * `as string | undefined` キャストするのが慣例だが、これは「実際には
 * 数値や object が入り得る」事実を握り潰す unsafe cast である。
 *
 * このヘルパは typeof check で文字列であることを実際に確認し、
 * - 文字列: そのまま返す
 * - 非文字列 / 未設定 + default 無し: undefined
 * - 非文字列 / 未設定 + default あり: default
 *
 * を保証する。bin/cfp-deadline-checker.ts の context 取得が 1 行で書ける。
 */
export function getContextString(app: App, key: string): string | undefined;
export function getContextString(app: App, key: string, defaultValue: string): string;
export function getContextString(
    app: App,
    key: string,
    defaultValue?: string,
): string | undefined {
    const raw = app.node.tryGetContext(key);
    if (typeof raw === 'string') {
        return raw;
    }
    return defaultValue;
}
