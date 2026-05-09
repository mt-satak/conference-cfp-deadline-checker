// admin Blade で利用する form 確認ダイアログ (Issue #177 #2)。
// CSP `script-src 'self'` 配下では inline `onsubmit="..."` が動作しないため、
// `<form data-confirm-message="...">` を data 属性として書き、外部 JS の listener から
// confirm() を呼ぶ方式に統一する。
//
// 対象 form:
//   - 削除確認 (conferences / categories)
//   - 公開操作 (conferences publish)
//   - 再ビルド trigger
//
// data-confirm-message 属性が無い form は通常通り submit (= no-op)。
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    const message = form.dataset.confirmMessage;
    if (typeof message === 'string' && message !== '' && !window.confirm(message)) {
        event.preventDefault();
    }
});
