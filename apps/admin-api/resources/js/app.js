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

// カンファレンス一覧の一括削除 (Issue #219)。
// CSP `script-src 'self'` 配下のため inline JS は使えず、本バンドルで配線する。
//   - 全選択 checkbox (data-bulk-select-all) で行 checkbox を一括 ON/OFF
//   - 選択件数に応じて削除ボタン (data-bulk-delete-submit) の活性 + 件数表示を更新
//   - 送信時に件数入りの確認ダイアログを出す (件数が動的なため data-confirm-message
//     ではなく専用 listener で処理する)
function setUpBulkDelete() {
    const form = document.querySelector('[data-bulk-delete-form]');
    if (!(form instanceof HTMLFormElement)) {
        return; // 一覧が空 (= テーブル非表示) のページでは何もしない
    }

    const selectAll = document.querySelector('[data-bulk-select-all]');
    const submitButton = form.querySelector('[data-bulk-delete-submit]');
    const countLabel = form.querySelector('[data-bulk-delete-count]');
    const rowCheckboxes = () =>
        Array.from(document.querySelectorAll('[data-bulk-row-checkbox]')).filter(
            (el) => el instanceof HTMLInputElement,
        );

    const checkedCount = () => rowCheckboxes().filter((el) => el.checked).length;

    const refresh = () => {
        const count = checkedCount();
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = count === 0;
        }
        if (countLabel instanceof HTMLElement) {
            countLabel.textContent = `${count} 件選択中`;
        }
        // 全選択 checkbox の状態を同期 (= 全部 ON なら checked、一部なら indeterminate)
        if (selectAll instanceof HTMLInputElement) {
            const all = rowCheckboxes();
            selectAll.checked = all.length > 0 && count === all.length;
            selectAll.indeterminate = count > 0 && count < all.length;
        }
    };

    if (selectAll instanceof HTMLInputElement) {
        selectAll.addEventListener('change', () => {
            for (const checkbox of rowCheckboxes()) {
                checkbox.checked = selectAll.checked;
            }
            refresh();
        });
    }

    for (const checkbox of rowCheckboxes()) {
        checkbox.addEventListener('change', refresh);
    }

    form.addEventListener('submit', (event) => {
        const count = checkedCount();
        if (count === 0) {
            // 念のため (ボタンは disabled だが) 0 件送信を防ぐ
            event.preventDefault();
            return;
        }
        if (!window.confirm(`選択した ${count} 件のカンファレンスを削除します。よろしいですか？`)) {
            event.preventDefault();
        }
    });

    refresh(); // 初期状態 (= 0 件選択、ボタン無効) を反映
}

document.addEventListener('DOMContentLoaded', setUpBulkDelete);
