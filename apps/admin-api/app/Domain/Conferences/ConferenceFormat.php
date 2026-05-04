<?php

namespace App\Domain\Conferences;

/**
 * カンファレンスの開催形態。
 *
 * OpenAPI 仕様 (data/openapi.yaml の Conference.format) と一致する 3 値を持つ enum。
 * 文字列値はそのまま DynamoDB に格納する文字列でもある (data/schema.md 参照)。
 */
enum ConferenceFormat: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Hybrid = 'hybrid';
}
