<?php

namespace App\Domain\Categories;

/**
 * カテゴリの軸ラベル。
 *
 * OpenAPI 仕様 (data/openapi.yaml の Category.axis) の enum 4 値。
 * 表示には使わず運用補助 (displayOrder の番号帯分割の根拠等) として保持する。
 */
enum CategoryAxis: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
}
