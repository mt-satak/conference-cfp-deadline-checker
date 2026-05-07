// カテゴリ絞り込みモーダルの open / close ハンドラ。
//
// 公開フロントの CSP は script-src 'self' で inline script を禁止しているため、
// Astro の <script> でなく public/ 配下の純 JS として配信する (Astro/vite の
// bundle 最適化を完全に bypass)。CategoryFilterModal.astro から
// <script src="/scripts/category-filter-modal.js" type="module"></script> で読み込む。
// public/ 配下は Astro build で dist/ にそのままコピーされるため、CSP 'self' で通る。
//
// type="module" の defer 性質により DOM 構築完了「後」に実行される = DOM は既に
// 存在するので DOMContentLoaded 待ちは不要。
//
// 担当する操作:
//   #category-filter-open ボタン:  showModal()
//   #category-filter-close ボタン: close()
//   backdrop クリック:             close() (UX 慣習)
//   Esc キー:                      <dialog> ネイティブで close されるため独自処理不要

const modal = document.getElementById('category-filter-modal');
const openBtn = document.getElementById('category-filter-open');
const closeBtn = document.getElementById('category-filter-close');

if (modal && openBtn && closeBtn) {
    openBtn.addEventListener('click', () => modal.showModal());
    closeBtn.addEventListener('click', () => modal.close());

    // dialog 自身の click event の target が dialog 自身のときのみ
    // (= dialog 内コンテンツのクリックは無視) close する。
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.close();
        }
    });
}
