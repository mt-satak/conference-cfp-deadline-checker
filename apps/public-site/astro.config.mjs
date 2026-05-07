import { defineConfig } from 'astro/config';

import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
//
// 型チェックは tsconfig.json の exclude で本ファイルを対象外としている。
// 理由: `@tailwindcss/vite` (内部 vite 8 系) と Astro 6 (内部 vite 7 系) で Plugin 型が
// 衝突するため。runtime API は互換で実害なし。Astro が起動時に config を検証するため
// 型チェック非対象でも実行時 fail で誤設定は検出可能。
export default defineConfig({
  vite: {
    plugins: [tailwindcss()]
  }
});