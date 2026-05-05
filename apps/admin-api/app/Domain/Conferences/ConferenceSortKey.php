<?php

namespace App\Domain\Conferences;

/**
 * カンファレンス一覧のソート対象キー (Issue #47 Phase A)。
 *
 * OpenAPI 仕様 (data/openapi.yaml の listConferences の ?sort) と一致する 5 値を持つ enum。
 * 文字列値はそのままクエリパラメータの値として使う。
 *
 * デフォルトは CfpEndDate (= 締切が近い順、本アプリの主要ユースケース)。
 *
 * Draft 行 (cfpEndDate 等が null になりうる) のソート時は、対応値が null のものを
 * 並びの末尾に集める仕様で UseCase 層が実装する。
 */
enum ConferenceSortKey: string
{
    case CfpEndDate = 'cfpEndDate';
    case EventStartDate = 'eventStartDate';
    case CfpStartDate = 'cfpStartDate';
    case Name = 'name';
    case CreatedAt = 'createdAt';
}
