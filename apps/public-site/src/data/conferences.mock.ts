import type { Conference } from '../types/conference';

/**
 * Phase 2 用の mock データ (Issue #86)。
 *
 * Phase 4 で admin-api の公開 API に置換する。それまではこのデータでトップページ
 * のデザイン / レイアウトを開発する。
 *
 * 締切の状態 (open / urgent / closed) を網羅するように 5 件構成。
 */
export const mockConferences: readonly Conference[] = [
    {
        id: '550e8400-e29b-41d4-a716-446655440001',
        name: 'PHP Conference Japan 2026',
        officialUrl: 'https://phpcon.php.gr.jp/2026/',
        eventStartDate: '2026-07-20',
        eventEndDate: '2026-07-20',
        venue: '大田区産業プラザ PiO',
        format: 'offline',
        cfpEndDate: '2026-05-15',
        description: '日本 PHP ユーザ会が主催する PHP に関する国内最大級のカンファレンス。',
        categories: ['php', 'web'],
    },
    {
        id: '550e8400-e29b-41d4-a716-446655440002',
        name: 'GopherCon Japan 2026',
        officialUrl: 'https://2026.gophercon.com/',
        eventStartDate: '2026-09-15',
        eventEndDate: '2026-09-16',
        venue: '東京ミッドタウン',
        format: 'hybrid',
        cfpEndDate: '2026-06-30',
        description: 'Go 言語コミュニティの祭典。最先端の事例セッションと懇親イベント。',
        categories: ['go', 'backend'],
    },
    {
        id: '550e8400-e29b-41d4-a716-446655440003',
        name: 'PyCon JP 2026',
        officialUrl: 'https://2026.pycon.jp/',
        eventStartDate: '2026-10-01',
        eventEndDate: '2026-10-03',
        venue: '東京国際フォーラム',
        format: 'offline',
        cfpEndDate: '2026-05-10',
        description: 'Python 開発者向けの国内最大級の年次カンファレンス。',
        categories: ['python', 'data', 'web'],
    },
    {
        id: '550e8400-e29b-41d4-a716-446655440004',
        name: 'JSConf JP 2026',
        officialUrl: 'https://jsconf.jp/2026/',
        eventStartDate: '2026-11-22',
        eventEndDate: '2026-11-23',
        venue: 'オンライン',
        format: 'online',
        cfpEndDate: '2026-04-30',
        description: 'JavaScript / TypeScript / Web プラットフォーム関連の祭典。締切は終了済み。',
        categories: ['javascript', 'typescript', 'web'],
    },
    {
        id: '550e8400-e29b-41d4-a716-446655440005',
        name: 'RubyKaigi 2026',
        officialUrl: 'https://rubykaigi.org/2026/',
        eventStartDate: '2026-05-14',
        eventEndDate: '2026-05-16',
        venue: '松山市総合コミュニティセンター',
        format: 'offline',
        cfpEndDate: null,
        description: '日本最大の Ruby 国際カンファレンス。CfP 締切は未定 (公式発表待ち)。',
        categories: ['ruby'],
    },
];
