import type { APIRoute } from 'astro';
import { loadConferences } from '../data/loadConferences';
import { extractCategorySlugs } from '../lib/categories';
import { filterOpenConferences } from '../lib/openConferences';

/**
 * sitemap.xml の動的生成 (Issue #130 #4)。
 *
 * SSG: ビルド時に dist/sitemap.xml として静的に出力される。
 * トップページ + 各カテゴリ別ページを含める。
 *
 * URL 設計:
 * - https://cfp-checker.dev/                    (priority 1.0)
 * - https://cfp-checker.dev/categories/<slug>/  (priority 0.8)
 *
 * lastmod はビルド日付 (= 日次 rebuild 想定なのでカテゴリ別の細かい更新時刻は出さない)。
 * changefreq=daily は CfP 締切までの残り日数表示が日次で変わるため。
 *
 * `/categories/<slug>/` の slug 一覧は index.astro と同じロジック
 * (extractCategorySlugs(filterOpenConferences(...))) で算出する。
 */
export const GET: APIRoute = async () => {
    const today = new Date();
    const allConferences = await loadConferences();
    const openConferences = filterOpenConferences(allConferences, today);
    const slugs = extractCategorySlugs(openConferences);

    const baseUrl = 'https://cfp-checker.dev';
    const lastmod = today.toISOString().split('T')[0]; // YYYY-MM-DD

    const urls = [
        { loc: `${baseUrl}/`, priority: '1.0' },
        ...slugs.map((slug) => ({
            loc: `${baseUrl}/categories/${slug}/`,
            priority: '0.8',
        })),
    ];

    const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls
    .map(
        (url) => `    <url>
        <loc>${url.loc}</loc>
        <lastmod>${lastmod}</lastmod>
        <changefreq>daily</changefreq>
        <priority>${url.priority}</priority>
    </url>`,
    )
    .join('\n')}
</urlset>
`;

    return new Response(xml, {
        headers: {
            'Content-Type': 'application/xml; charset=utf-8',
        },
    });
};
