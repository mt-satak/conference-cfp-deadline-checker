<?php

/**
 * coverage.txt をパースして C1 (Branch Coverage) が指定閾値以上か判定するスクリプト。
 *
 * 想定入力 (PHPUnit / Pest の text coverage report):
 *   ...
 *   Code Coverage Report Summary:
 *     Classes:    XX.XX% (X/X)
 *     Methods:    XX.XX% (X/X)
 *     Paths:      XX.XX% (X/X)
 *     Branches:   XX.XX% (X/X)   ← C1 はこれ
 *     Lines:      XX.XX% (X/X)
 *
 * 使い方:
 *   php scripts/check-coverage.php <coverage.txt のパス> <閾値 %>
 *
 * 終了コード:
 *   0  = 閾値以上
 *   1  = 閾値未満
 *   2  = ファイル読込失敗 / フォーマット解析失敗 / 引数不正
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php scripts/check-coverage.php <coverage-text-file> <threshold-percent>\n");
    exit(2);
}

$file = $argv[1];
$threshold = (float) $argv[2];

if (! is_readable($file)) {
    fwrite(STDERR, "Coverage report not readable: {$file}\n");
    fwrite(STDERR, "Run `make test-coverage` first.\n");
    exit(2);
}

$contents = file_get_contents($file);
if ($contents === false) {
    fwrite(STDERR, "Failed to read coverage report: {$file}\n");
    exit(2);
}

// ANSI カラーコードを除去 (PHPUnit の text レポートに含まれる場合がある)
$plain = preg_replace('/\x1b\[[0-9;]*m/', '', $contents);

// "Branches:   XX.XX%" のような行を探す。最初の Summary ブロックの値を採用する
// (個別クラス毎の Branches 行も後続にあるが、Summary が最初に出現する想定)。
if (! preg_match('/Branches:\s+(\d+(?:\.\d+)?)%\s+\((\d+)\/(\d+)\)/', $plain, $m)) {
    fwrite(STDERR, "Could not find 'Branches: NN%' line in coverage report.\n");
    fwrite(STDERR, "Make sure phpunit.xml has <coverage pathCoverage=\"true\">.\n");
    exit(2);
}

$actual = (float) $m[1];
$covered = (int) $m[2];
$total = (int) $m[3];

$ok = $actual >= $threshold;
$icon = $ok ? '✅' : '❌';
$status = $ok ? 'PASS' : 'FAIL';

printf("%s C1 (Branch Coverage) %s: %.2f%% (%d/%d)  threshold=%.2f%%\n",
    $icon, $status, $actual, $covered, $total, $threshold);

if (! $ok) {
    fwrite(STDERR, "\nC1 coverage is below the {$threshold}% threshold.\n");
    fwrite(STDERR, "See storage/coverage/html/index.html for details.\n");
    exit(1);
}

exit(0);
