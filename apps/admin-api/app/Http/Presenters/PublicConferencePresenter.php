<?php

namespace App\Http\Presenters;

use App\Domain\Conferences\Conference;

/**
 * 公開フロント (cfp-checker.dev) 向け Conference Presenter (Issue #178 #4)。
 *
 * admin Presenter (ConferencePresenter) と projection を分離する目的:
 * - 将来 Conference Entity に admin 専用フィールド (auditLog / internalNotes 等) を
 *   追加した際に、公開エンドポイント `/api/public/conferences` へ自動的に漏洩しない
 * - 「公開してよいフィールド」を PUBLIC_FIELDS で明示し、新規フィールド追加時に
 *   リスト更新と漏洩検知テスト (PublicPresenterTest) の両方が必要となる安全装置にする
 *
 * NOTE: 現時点では admin Presenter と shape は同じ。重複に見えるが、
 * 上記のとおり「分離されている契約」自体が将来の漏洩を防ぐ安全装置。
 */
class PublicConferencePresenter
{
    /**
     * 公開フロントへ出力してよいフィールドのホワイトリスト (Issue #178 #4)。
     *
     * 出力キー集合がこのリストの部分集合であることを PublicPresenterTest が検証する。
     * 新規フィールドを公開する際は、ここに追加 + テスト更新の両方が必要。
     *
     * @var list<string>
     */
    public const PUBLIC_FIELDS = [
        'conferenceId',
        'name',
        'trackName',
        'officialUrl',
        'cfpUrl',
        'eventStartDate',
        'eventEndDate',
        'venue',
        'format',
        'cfpStartDate',
        'cfpEndDate',
        'categories',
        'description',
        'themeColor',
        'createdAt',
        'updatedAt',
        'status',
    ];

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

    /**
     * Conference[] を toArray した配列のリストに一括変換する。
     *
     * @param  Conference[]  $conferences
     * @return list<array<string, mixed>>
     */
    public static function toList(array $conferences): array
    {
        return array_values(array_map(
            static fn (Conference $c): array => self::toArray($c),
            $conferences,
        ));
    }
}
