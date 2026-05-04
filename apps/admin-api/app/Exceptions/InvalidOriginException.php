<?php

namespace App\Exceptions;

use Exception;

/**
 * VerifyOrigin ミドルウェアが Origin / Referer ヘッダ不一致を検出したときに投げる例外。
 *
 * AdminApiExceptionRenderer が捕捉して 403 + INVALID_ORIGIN に整形する。
 * 個別の例外クラスにすることで「単なる 403」と区別し、エラーコードの取り違えを防ぐ。
 */
class InvalidOriginException extends Exception
{
}
