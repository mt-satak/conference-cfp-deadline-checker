<?php

namespace App\Http\Presenters;

use App\Domain\Conferences\Conference;

/**
 * Domain Entity (Conference) を OpenAPI Conference スキーマに従う array に変換する Presenter。
 *
 * Domain 層を JSON シリアライズ責務で汚さないため、HTTP 層に置く。
 * 整合先: data/openapi.yaml の components.schemas.Conference。
 *
 * NOTE: ttl 属性は OpenAPI 上は readOnly で定義されているが、Domain Entity が
 * 持たない (DynamoDB 内部用) ため出力に含めない。
 */
class ConferencePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Conference $conference): array
    {
        return [
            'conferenceId' => $conference->conferenceId,
            'name' => $conference->name,
            'trackName' => $conference->trackName,
            'officialUrl' => $conference->officialUrl,
            'cfpUrl' => $conference->cfpUrl,
            'eventStartDate' => $conference->eventStartDate,
            'eventEndDate' => $conference->eventEndDate,
            'venue' => $conference->venue,
            'format' => $conference->format?->value,
            'cfpStartDate' => $conference->cfpStartDate,
            'cfpEndDate' => $conference->cfpEndDate,
            'categories' => $conference->categories,
            'description' => $conference->description,
            'themeColor' => $conference->themeColor,
            'createdAt' => $conference->createdAt,
            'updatedAt' => $conference->updatedAt,
            'status' => $conference->status->value,
        ];
    }
}
