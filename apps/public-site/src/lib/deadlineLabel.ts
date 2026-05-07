import { daysUntilDeadline } from './dates';

/**
 * CfP 締切ラベルの表示状態。
 *
 * - open: まだ余裕あり (8 日以上前)
 * - urgent: 締切直前 (1〜7 日前) もしくは当日
 * - closed: 締切終了
 * - unknown: 締切日未入力 (admin が未設定)
 *
 * UI 側でこの enum をベースに色 / バッジを切り替える。
 */
export type DeadlineLabelStatus = 'open' | 'urgent' | 'closed' | 'unknown';

export interface DeadlineLabel {
    /** 表示用テキスト (例: "あと 5 日") */
    readonly text: string;
    /** UI で色分けする際の手がかり */
    readonly status: DeadlineLabelStatus;
}

/** 締切が直近とみなす上限 (日数)。これ以下は urgent ステータスになる */
const URGENT_THRESHOLD_DAYS = 7;

/**
 * CfP 締切日 (YYYY-MM-DD or null) からラベル文字列とステータスを生成する。
 *
 * @param deadline CfP 締切日。null は未入力扱い (= 「締切未定」)
 * @param today 基準時刻 (テスト容易性のため明示的に渡す)
 */
export function deadlineLabel(deadline: string | null, today: Date): DeadlineLabel {
    if (deadline === null) {
        return { text: '締切未定', status: 'unknown' };
    }

    const days = daysUntilDeadline(deadline, today);

    if (days < 0) {
        return { text: '締切終了', status: 'closed' };
    }

    if (days === 0) {
        return { text: '本日締切', status: 'urgent' };
    }

    const status: DeadlineLabelStatus = days <= URGENT_THRESHOLD_DAYS ? 'urgent' : 'open';
    return { text: `あと ${days} 日`, status };
}
