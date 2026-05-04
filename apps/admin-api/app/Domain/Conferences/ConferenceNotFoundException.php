<?php

namespace App\Domain\Conferences;

use Exception;

/**
 * 指定された conferenceId のカンファレンスが見つからなかったときに投げる Domain 例外。
 *
 * - Application 層 (UseCase) で findById の戻りが null だった場合などに使う
 * - Symfony / Laravel の HTTP 例外には依存しないため Domain 層に置く
 * - HTTP レイヤでの整形 (404 + NOT_FOUND) は AdminApiExceptionRenderer が
 *   後続コミットで本例外をマッピングする
 */
class ConferenceNotFoundException extends Exception
{
    public static function withId(string $conferenceId): self
    {
        return new self("Conference not found: {$conferenceId}");
    }
}
