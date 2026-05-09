// クライアント側で「ページ表示時点」の残日数を再計算して DeadlineBadge を
// 上書きする (Issue #134)。
//
// 公開フロントは Astro SSG でビルド時刻を基準に「あと N 日」を計算するため、
// 再ビルドが走らない限り日付がずれる。本スクリプトが走ると常に正確な値に
// なるため、deploy.yml の schedule cron (PR #135) と組み合わせて
// 「24h 古い表示」と「0:00-0:10 のずれ」の両方を解消する。
//
// CSP 対応:
//   公開フロントの CSP は script-src 'self' で inline script を禁止。Astro の
//   <script> ブロックは小さなコードを inline 化する最適化が働くため、
//   public/ 配下に純 JS を置いて <script is:inline src="..."> で読み込む。
//   詳細は Issue #124 の経緯参照。
//
// ロジックは src/lib/deadlineLabel.ts と src/lib/dates.ts のコピー。
// TS をバンドルして public/ に出すフローを組むのは Astro/vite の責務外で
// 複雑度に見合わないため、純 JS でロジックを再実装する。
// 本ファイルと TS 側の挙動は ConferenceCard が両方使う関係で必ず一致させる
// 必要があるため、TS 側を変更したら本ファイルも合わせて更新すること。

/** 締切が直近とみなす上限 (日数)。これ以下は urgent ステータスになる */
const URGENT_THRESHOLD_DAYS = 7;

/** Status ごとの Tailwind class (DeadlineBadge.astro と一致させる) */
const CLASS_BY_STATUS = {
    open: 'bg-emerald-600 text-white ring-1 ring-inset ring-emerald-700',
    urgent: 'bg-orange-500 text-white ring-1 ring-inset ring-orange-600',
    today: 'bg-red-600 text-white ring-1 ring-inset ring-red-700',
    closed: 'bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-200',
    unknown: 'bg-white text-gray-600 ring-1 ring-inset ring-gray-300',
};

/** バッジ container の固定 class (status と独立) */
const BASE_CLASS =
    'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 font-semibold min-h-[60px] min-w-[120px]';

/**
 * CfP 締切日と基準日の JST 日付差を整数日数で返す (dates.ts と同等、Issue #174)。
 * 旧版は UTC ベースで計算していたため JST 0:00-08:59 で 1 日ズレていた問題を修正。
 * @param {string} deadline YYYY-MM-DD (JST のカレンダー日付として解釈)
 * @param {Date} today
 * @returns {number}
 */
function daysUntilDeadline(deadline, today) {
    const [dy, dm, dd] = deadline.split('-').map(Number);
    const deadlineUtc = Date.UTC(dy, dm - 1, dd);

    const todayJst = jstDateComponents(today);
    const todayUtc = Date.UTC(
        todayJst.year,
        todayJst.month - 1,
        todayJst.day,
    );

    const msPerDay = 24 * 60 * 60 * 1000;
    return Math.round((deadlineUtc - todayUtc) / msPerDay);
}

/**
 * Date オブジェクトを JST タイムゾーンの year/month/day に分解する (Issue #174)。
 * Intl.DateTimeFormat 経由で runtime の system timezone に依存せず JST 日付を返す。
 * @param {Date} date
 * @returns {{year: number, month: number, day: number}}
 */
function jstDateComponents(date) {
    const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Tokyo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    const parts = formatter.formatToParts(date);
    const lookup = (type) => {
        const part = parts.find((p) => p.type === type);
        return Number(part ? part.value : '0');
    };
    return {
        year: lookup('year'),
        month: lookup('month'),
        day: lookup('day'),
    };
}

/**
 * deadlineLabel.ts と同等のロジック。
 * @param {string|null} deadline
 * @param {Date} today
 * @returns {{text: string, status: 'open'|'urgent'|'today'|'closed'|'unknown'}}
 */
function deadlineLabel(deadline, today) {
    if (deadline === null || deadline === '') {
        return { text: '締切未定', status: 'unknown' };
    }
    const days = daysUntilDeadline(deadline, today);
    if (days < 0) {
        return { text: '締切終了', status: 'closed' };
    }
    if (days === 0) {
        return { text: '本日締切', status: 'today' };
    }
    const status = days <= URGENT_THRESHOLD_DAYS ? 'urgent' : 'open';
    return { text: `あと ${days} 日`, status };
}

/**
 * 1 つの badge 要素を最新の status / text に書き換える。
 * @param {HTMLElement} badge
 * @param {Date} today
 */
function recalcBadge(badge, today) {
    const cfpEndDate = badge.getAttribute('data-cfp-end-date') || null;
    const { text, status } = deadlineLabel(cfpEndDate, today);

    badge.className = `${BASE_CLASS} ${CLASS_BY_STATUS[status]}`;
    badge.setAttribute('aria-label', `CfP 締切: ${text}`);

    // DeadlineBadge.astro の出力構造と完全一致させる。
    //   - 「あと N 日」: 数字部分を text-4xl で大きく目立たせる
    //   - 単一テキスト (本日締切 / 締切終了 / 締切未定): text-2xl で表示
    const countdownMatch = text.match(/^あと\s*(\d+)\s*日$/);
    if (countdownMatch) {
        const days = countdownMatch[1];
        badge.innerHTML =
            '<span class="inline-flex items-baseline gap-2">' +
            '<span class="text-sm">あと</span>' +
            `<span class="text-4xl font-extrabold leading-none tracking-tight">${days}</span>` +
            '<span class="text-sm">日</span>' +
            '</span>';
    } else {
        badge.innerHTML = `<span class="text-2xl font-black tracking-tight leading-none">${text}</span>`;
    }
}

/**
 * カード全体 (= ConferenceCard) を非表示にし、件数表示も連動して減らす。
 * 「締切終了 (closed)」になったカンファレンスはユーザーに見せても価値が
 * 低い (= 応募できない) ため一覧から消す。SSG 側の filterOpenConferences
 * と同じ方針 (open / urgent / today のみ表示)。
 * @param {HTMLElement} badge
 */
function hideClosedCard(badge) {
    // ConferenceCard.astro 側の構造: <li><article>...<div class="mb-4">badge</div>...</article></li>
    // 最も近い <li> を遡って非表示にする。
    const li = badge.closest('li');
    if (li instanceof HTMLElement) {
        li.style.display = 'none';
    }
}

/**
 * ヘッダの「N 件」表示を実際に表示中のカード数に合わせて更新する。
 * 該当要素が無いページ (例: カテゴリ別ページで他のロジック) でも安全に no-op。
 */
function updateVisibleCount() {
    const countEl = document.querySelector('[data-conference-count]');
    if (!(countEl instanceof HTMLElement)) return;
    const visible = document.querySelectorAll(
        'li:has([data-deadline-badge])',
    ).length;
    // 上記 :has は visible/hidden を含むので display: none を除外して再カウント
    let actuallyVisible = 0;
    document.querySelectorAll('li').forEach((li) => {
        if (
            li.querySelector('[data-deadline-badge]') &&
            li.style.display !== 'none'
        ) {
            actuallyVisible += 1;
        }
    });
    countEl.textContent = `${actuallyVisible} 件`;
}

// ───────────────────────────────────────────
// メイン処理
// Astro の type="module" な script は defer 相当で DOM 構築完了後に実行される
// ため DOMContentLoaded 待ちは不要 (Modal 経験 / Issue #124 と同じ)。

const today = new Date();
const badges = document.querySelectorAll('[data-deadline-badge]');
badges.forEach((badge) => {
    if (!(badge instanceof HTMLElement)) return;
    recalcBadge(badge, today);
    // 「締切終了」になったものはカードごと非表示
    const newStatus = badge.className.includes('bg-gray-100')
        ? 'closed'
        : null;
    if (newStatus === 'closed') {
        hideClosedCard(badge);
    }
});
updateVisibleCount();
