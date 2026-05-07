import { defineConfig } from 'vitest/config';

/**
 * Vitest 設定 (Issue #86 / Phase 1)。
 *
 * - 環境: jsdom (将来 Astro component test で DOM 操作する場合に備える)。当面は純粋ロジック中心
 * - coverage は CI では計測しない (Issue #80 / D 案運用に倣う): pnpm test は --no-coverage 既定
 *   ローカル開発時のみ pnpm test:coverage で計測する
 * - 閾値は Phase 2 以降に層構造が見えてから設定する
 */
export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: false,
    include: ['src/**/*.test.{ts,tsx}', 'tests/**/*.test.{ts,tsx}'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html', 'json-summary'],
      include: ['src/**/*.ts'],
      exclude: ['src/**/*.test.ts', 'src/pages/**'],
    },
  },
});
