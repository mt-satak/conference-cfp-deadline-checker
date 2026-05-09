<?php

namespace App\Application\Conferences;

use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceRepository;
use App\Domain\Conferences\ConferenceSortKey;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\SortOrder;

/**
 * カンファレンス全件取得 UseCase。
 *
 * 責務:
 * - Repository から全件を取得して呼び出し元に返す
 * - status 指定があれば該当ステータスのみに絞り込む (Phase 0.5 / Issue #165 で配列対応)
 * - sortKey / order 指定でサーバ側ソート (Issue #47 Phase A、件数 50〜200 想定で
 *   in-memory usort で十分)
 *
 * デフォルトは cfpEndDate 昇順 = 「締切が近い順」(本アプリの主要ユースケース)。
 *
 * Null 値の扱い:
 *   ソート対象キー (cfpEndDate / eventStartDate / cfpStartDate / etc.) が null の
 *   Conference は、昇順 / 降順どちらの場合でも **末尾に集める**。
 *   Draft 中で「未確定の値はいつも視界の端」であるべき UX 一貫性のため。
 *
 * statusFilters の意味 (Issue #165):
 *   - null: 全件 (= フィルタ無し、Archived も含む)
 *   - 配列: 配列内のいずれかの status と一致する Conference のみ (OR 結合)
 *   - 空配列 []: 0 件 (= 「どの status とも一致しない」と解釈)
 *   "Active" タブ ([Draft, Published]) で Archived を除外する用途を想定。
 */
class ListConferencesUseCase
{
    public function __construct(
        private readonly ConferenceRepository $repository,
    ) {}

    /**
     * @param  ConferenceStatus[]|null  $statusFilters  null=全件、配列=指定 status の OR 結合
     * @return Conference[]
     */
    public function execute(
        ?array $statusFilters = null,
        ?ConferenceSortKey $sortKey = null,
        SortOrder $order = SortOrder::Asc,
    ): array {
        $all = $this->repository->findAll();

        if ($statusFilters !== null) {
            $all = array_values(array_filter(
                $all,
                static fn (Conference $c): bool => in_array($c->status, $statusFilters, true),
            ));
        }

        $effectiveKey = $sortKey ?? ConferenceSortKey::CfpEndDate;
        usort($all, static fn (Conference $a, Conference $b): int => self::compareNullable(
            self::sortValue($a, $effectiveKey),
            self::sortValue($b, $effectiveKey),
            $order,
        ));

        return $all;
    }

    /**
     * ConferenceSortKey に対応する Conference のフィールド値を返す。
     * null 許容 (Draft の cfpEndDate/eventStartDate/cfpStartDate)。
     */
    private static function sortValue(Conference $c, ConferenceSortKey $key): ?string
    {
        return match ($key) {
            ConferenceSortKey::CfpEndDate => $c->cfpEndDate,
            ConferenceSortKey::EventStartDate => $c->eventStartDate,
            ConferenceSortKey::CfpStartDate => $c->cfpStartDate,
            ConferenceSortKey::Name => $c->name,
            ConferenceSortKey::CreatedAt => $c->createdAt,
        };
    }

    /**
     * null 含みの 2 値を SortOrder 付きで比較する usort 用 comparator。
     *
     * ルール: null は常に末尾 (= 昇順 / 降順どちらでも「未確定」が後ろ)。
     *
     * @internal 元々 usort のクロージャ内インラインだったが、PHP の usort は
     *           実装 (Timsort) によって比較ペアの方向が決定的でなく、
     *           特定の null/非null ペア分岐を確実にカバーできないため、
     *           xdebug C1 (Branch Coverage) 100% を満たす目的で外出ししている。
     *           public は static helper としてテスト容易性のため (= ドメイン API ではない)。
     */
    public static function compareNullable(?string $av, ?string $bv, SortOrder $order): int
    {
        if ($av === null && $bv === null) {
            return 0;
        }
        if ($av === null) {
            return 1;
        }
        if ($bv === null) {
            return -1;
        }

        $cmp = $av <=> $bv;

        return $order === SortOrder::Desc ? -$cmp : $cmp;
    }
}
