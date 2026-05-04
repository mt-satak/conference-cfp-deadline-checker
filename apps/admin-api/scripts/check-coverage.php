<?php

/**
 * Clover XML をパースして「層 (namespace) 別の C1 (Branch Coverage) 閾値」を
 * すべてのクラスが満たしているか判定するスクリプト。
 *
 * 設計判断:
 *   全コード一律の閾値 (例: 90%) ではなく層別閾値とする。理由は
 *   docs/test-strategy.md に詳述するが要点:
 *     - Domain / Application は 100% を要求する (ロジックが集中する層)
 *     - 薄い変換層 (Exceptions/Controllers) は xdebug の branch coverage が
 *       compound boolean 条件で過剰カウントするため一律 90%+ は非現実的
 *     - Infrastructure (DynamoDB) は AWS SDK 例外パスのモック網羅コストが高く 75%
 *
 * 使い方:
 *   php scripts/check-coverage.php <clover-xml のパス>
 *
 * 終了コード:
 *   0  = すべてのファイルが層閾値を満たす
 *   1  = いずれかのファイルが層閾値未満
 *   2  = ファイル読込失敗 / フォーマット解析失敗 / 引数不正
 */
if ($argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/check-coverage.php <clover-xml-file>\n");
    exit(2);
}

$file = $argv[1];

if (! is_readable($file)) {
    fwrite(STDERR, "Coverage report not readable: {$file}\n");
    fwrite(STDERR, "Run `make test-coverage` first.\n");
    exit(2);
}

/**
 * 層別 C1 閾値テーブル。
 *
 * キー: クラス完全修飾名のプレフィクス (前方一致、長い順に評価)
 * 値:   閾値 (%)。null は計測対象外 (DI 配線等)
 *
 * 順序が重要: 配列先頭から順に最初にマッチしたエントリを採用する。
 * より具体的なプレフィクスを上に置くこと。
 */
$thresholds = [
    'App\\Providers\\' => null,   // DI wiring。Lambdaコンテナ起動時しか走らない
    'App\\Domain\\' => 100.0,  // Entity / VO / Domain Exception
    'App\\Application\\' => 100.0,  // UseCase
    'App\\Http\\Presenters\\' => 100.0,  // データ整形のみ
    'App\\Http\\Requests\\' => 100.0,  // バリデーションルール定義
    'App\\Http\\Controllers\\' => 85.0,   // 薄いオーケストレーション
    'App\\Http\\Middleware\\' => 85.0,   // フレームワーク hook 分岐含む
    'App\\Exceptions\\' => 75.0,   // match true + compound instanceof で xdebug が micro-branch を細かく分割するため一律 80%+ は padding テストでしか到達不能。実測上限 79.59% に余裕を取って 75。詳細 docs/test-strategy.md 参照
    'App\\Infrastructure\\' => 75.0,   // AWS SDK 例外パスのモック網羅コストが高い
];

$xml = @simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse Clover XML: {$file}\n");
    exit(2);
}

/**
 * クラス FQN から適用すべき閾値を引く。マッチしなければ "未分類" として 0 (= 必ず PASS)。
 */
$resolveThreshold = function (string $fqn) use ($thresholds): array {
    foreach ($thresholds as $prefix => $threshold) {
        if (str_starts_with($fqn, $prefix)) {
            return [$prefix, $threshold];
        }
    }

    return ['(unmatched)', 0.0];
};

$rows = [];
$failures = 0;
$skipped = 0;

foreach ($xml->project->package as $package) {
    foreach ($package->file as $file) {
        foreach ($file->class as $class) {
            $fqn = (string) $class['name'];
            [$layer, $threshold] = $resolveThreshold($fqn);

            $m = $class->metrics;
            $covered = (int) $m['coveredconditionals'];
            $total = (int) $m['conditionals'];

            // 分岐 0 のクラス (定数のみ等) は 100% 扱いで常に PASS
            $pct = $total > 0 ? ($covered / $total * 100) : 100.0;

            if ($threshold === null) {
                $rows[] = [$fqn, $layer, $pct, $covered, $total, 'SKIP', '⚪'];
                $skipped++;

                continue;
            }

            $ok = $pct >= $threshold;
            if (! $ok) {
                $failures++;
            }
            $rows[] = [
                $fqn,
                $layer,
                $pct,
                $covered,
                $total,
                $ok ? 'PASS' : 'FAIL',
                $ok ? '✅' : '❌',
                $threshold,
            ];
        }
    }
}

// 結果テーブル出力 (層 → FQN の順でソート)
usort($rows, function ($a, $b) {
    $cmpLayer = strcmp($a[1], $b[1]);

    return $cmpLayer !== 0 ? $cmpLayer : strcmp($a[0], $b[0]);
});

printf("\n%-65s %6s  %10s  %5s  %s\n", 'Class', 'C1', 'covered', 'thr', 'status');
printf("%s\n", str_repeat('-', 110));
foreach ($rows as $row) {
    [$fqn, $layer, $pct, $covered, $total, $status, $icon] = $row;
    $thr = $row[7] ?? null;
    printf("%s %-63s %6.2f%%  %4d/%-5d  %5s  %s\n",
        $icon,
        $fqn,
        $pct,
        $covered,
        $total,
        $thr === null ? '-' : sprintf('%4.0f%%', $thr),
        $status,
    );
}

echo "\n";
if ($failures > 0) {
    fwrite(STDERR, "❌ {$failures} class(es) below their layer threshold. See storage/coverage/html/index.html for details.\n");
    exit(1);
}

printf("✅ All %d measured class(es) meet their layer thresholds (%d skipped).\n",
    count($rows) - $skipped, $skipped);
exit(0);
