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
        categories: ['ruby'],
    },
    {
        // ローカル UI 確認用: today ステータス (= 本日締切バッジ赤色) を再現するためのデータ。
        // cfpEndDate は本日 (2026-05-07) に固定。日付が変わると urgent → closed と
        // ステータスがずれるので、本日締切バッジを確認したいときは値を更新する。
        id: '550e8400-e29b-41d4-a716-446655440006',
        name: 'Today Conf 2026 (テスト用)',
        officialUrl: 'https://example.com/today-conf-2026/',
        eventStartDate: '2026-08-01',
        eventEndDate: '2026-08-02',
        venue: 'テスト会場',
        format: 'hybrid',
        cfpEndDate: '2026-05-07',
        categories: ['web'],
    },
];
