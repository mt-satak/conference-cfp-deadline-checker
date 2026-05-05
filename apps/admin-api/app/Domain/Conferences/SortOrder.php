<?php

namespace App\Domain\Conferences;

/**
 * ソート順 (昇順 / 降順) の enum。
 *
 * OpenAPI 仕様 (data/openapi.yaml の listConferences の ?order) と一致する 2 値を持つ。
 * Conferences コンテキスト固有ではなく汎用に使えるが、現状利用箇所が一覧 UseCase
 * 1 つのため Conferences ネームスペースに置く (将来 Categories 等で使うなら共通化検討)。
 */
enum SortOrder: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
