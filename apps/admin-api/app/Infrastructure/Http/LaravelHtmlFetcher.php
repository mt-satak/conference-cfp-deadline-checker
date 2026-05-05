<?php

namespace App\Infrastructure\Http;

use App\Application\Conferences\Extraction\HtmlFetcher;
use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Laravel の Http facade を使った HtmlFetcher 実装 (Issue #40 Phase 3 PR-2)。
 *
 * セキュリティ防御層 (PR 議論で確定):
 * 1. HTTPS 強制 (= 平文 / mixed content の経路を塞ぐ)
 * 2. SSRF 防御: DNS 解決して IP を確認、プライベートレンジ /
 *    loopback / link-local / metadata endpoint を拒否
 *    (= admin が誤って http://localhost や 169.254.169.254 を入れても
 *    内部リソースを叩かない)
 * 3. リソース上限: 5MB / Content-Type / リダイレクト 5 回 / timeout 10s
 * 4. HTML サニタイズ: script / style / iframe / svg / form / noscript /
 *    HTML コメント を DOMDocument 経由で除去
 *    (= プロンプトインジェクション攻撃面の縮小)
 * 5. 30000 文字での切り詰め (= LLM トークン消費上限と一致)
 *
 * <main> > <article> > <body> の優先順で本文を抽出 (= ナビ / フッタを排除)。
 */
class LaravelHtmlFetcher implements HtmlFetcher
{
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024; // 5MB

    private const MAX_HTML_CHARS = 30000;

    private const HTTP_TIMEOUT_SECONDS = 10;

    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly HostValidator $hostValidator,
    ) {}

    public function fetch(string $url): string
    {
        $this->assertHttps($url);
        $host = $this->extractHost($url);
        $this->hostValidator->validate($host);

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->withOptions([
                    'allow_redirects' => ['max' => self::MAX_REDIRECTS, 'protocols' => ['https']],
                    // SSRF 二重防御: リダイレクト先のホストも検証する on_redirect コールバック
                    // Guzzle の signature: function (Request, Response, Uri): void
                    'on_redirect' => function ($request, $response, UriInterface $uri): void {
                        $this->hostValidator->validate($uri->getHost());
                    },
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            throw HtmlFetchFailedException::networkError($url, $e->getMessage());
        } catch (Throwable $e) {
            throw HtmlFetchFailedException::networkError($url, $e->getMessage());
        }

        if ($response->failed()) {
            throw HtmlFetchFailedException::statusError($url, $response->status());
        }

        $contentType = (string) $response->header('Content-Type');
        if (! $this->isHtmlContentType($contentType)) {
            throw HtmlFetchFailedException::networkError(
                $url,
                "Content-Type is not text/html: {$contentType}",
            );
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw HtmlFetchFailedException::tooLarge($url, strlen($body), self::MAX_RESPONSE_BYTES);
        }

        $sanitized = $this->sanitizeAndExtract($body);

        if (mb_strlen($sanitized) > self::MAX_HTML_CHARS) {
            $sanitized = mb_substr($sanitized, 0, self::MAX_HTML_CHARS);
        }

        return $sanitized;
    }

    private function assertHttps(string $url): void
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['scheme']) || ! isset($parsed['host'])) {
            throw HtmlFetchFailedException::networkError($url, 'invalid URL');
        }
        if ($parsed['scheme'] !== 'https') {
            throw HtmlFetchFailedException::notHttps($url);
        }
    }

    private function extractHost(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'])) {
            throw HtmlFetchFailedException::networkError($url, 'cannot extract host');
        }
        $host = $parsed['host'];
        if ($host === '') {
            throw HtmlFetchFailedException::networkError($url, 'cannot extract host');
        }

        // [::1] のような IPv6 は parse_url で角括弧が外れている (host = "::1")
        return $host;
    }

    private function isHtmlContentType(string $contentType): bool
    {
        // text/html / application/xhtml+xml / 何らかのパラメータ付きを許容
        return str_starts_with($contentType, 'text/html')
            || str_starts_with($contentType, 'application/xhtml');
    }

    /**
     * HTML 本文をサニタイズし、<main>/<article>/<body> の優先順で本文を抽出する。
     */
    private function sanitizeAndExtract(string $html): string
    {
        $dom = new DOMDocument;
        // 文字エンコーディング推定対策 (= UTF-8 と仮定して明示的に prefix を付与)
        $prefix = '<?xml encoding="UTF-8">';
        // 不正 HTML への寛容性。warning は内部 IO バッファで吸収。
        $previousLevel = libxml_use_internal_errors(true);
        $dom->loadHTML($prefix.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLevel);

        $this->removeDangerousTags($dom);
        $this->removeComments($dom);

        // 抽出優先順: <main> > <article> > <body>
        $extracted = $this->findFirstByTag($dom, 'main')
            ?? $this->findFirstByTag($dom, 'article')
            ?? $this->findFirstByTag($dom, 'body');

        if ($extracted === null) {
            // 抽出対象が無ければ全 HTML を返す (= 解析失敗時のフォールバック)
            return $dom->saveHTML() ?: '';
        }

        return $dom->saveHTML($extracted) ?: '';
    }

    private function removeDangerousTags(DOMDocument $dom): void
    {
        $dangerous = ['script', 'style', 'iframe', 'svg', 'form', 'noscript', 'object', 'embed'];
        foreach ($dangerous as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            // getElementsByTagName は live なので逆順で removeChild する
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node !== null && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    private function removeComments(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $comments = $xpath->query('//comment()');
        if ($comments === false) {
            return;
        }
        foreach ($comments as $comment) {
            // DOMNodeList の iterator は DOMNode|DOMNameSpaceNode のため narrow
            if ($comment instanceof DOMNode && $comment->parentNode !== null) {
                $comment->parentNode->removeChild($comment);
            }
        }
    }

    private function findFirstByTag(DOMDocument $dom, string $tag): ?DOMElement
    {
        $nodes = $dom->getElementsByTagName($tag);
        $first = $nodes->item(0);

        return $first instanceof DOMElement ? $first : null;
    }
}
