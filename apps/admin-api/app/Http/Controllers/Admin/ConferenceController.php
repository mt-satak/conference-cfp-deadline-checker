<?php

namespace App\Http\Controllers\Admin;

use App\Application\Categories\ListCategoriesUseCase;
use App\Application\Conferences\CreateConferenceInput;
use App\Application\Conferences\CreateConferenceUseCase;
use App\Application\Conferences\DeleteConferenceUseCase;
use App\Application\Conferences\Extraction\ConferenceDraft;
use App\Application\Conferences\Extraction\ExtractConferenceDraftUseCase;
use App\Application\Conferences\Extraction\HtmlFetchFailedException;
use App\Application\Conferences\Extraction\LlmExtractionFailedException;
use App\Application\Conferences\GetConferenceUseCase;
use App\Application\Conferences\ListConferencesUseCase;
use App\Application\Conferences\UpdateConferenceUseCase;
use App\Domain\Categories\CategoryRepository;
use App\Domain\Conferences\Conference;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceNotFoundException;
use App\Domain\Conferences\ConferenceSortKey;
use App\Domain\Conferences\ConferenceStatus;
use App\Domain\Conferences\SortOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conferences\StoreConferenceRequest;
use App\Http\Requests\Conferences\UpdateConferenceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 管理画面のカンファレンス管理 UI (Blade SSR)。
 *
 * /admin/api/conferences と機能は重複するが、こちらは UI 用 (HTML を返す / リダイレクトする)。
 * UseCase / FormRequest / 入力 DTO は API 側と完全に共有して二重実装を避ける。
 *
 * ConferenceNotFoundException は API 側では Renderer が 404 JSON に整形するが、
 * UI 側では HTML エラー画面として 404 abort する (Laravel デフォルトの 404 view)。
 *
 * バリデーション違反時は FormRequest が自動で 302 リダイレクト + errors / old() を
 * session に flash する (Accept: application/json でない通常 POST)。
 */
class ConferenceController extends Controller
{
    /**
     * GET /admin/conferences — 一覧画面。
     *
     * クエリパラメータ:
     * - ?status=draft|published|archived|active: status フィルタ (Issue #165 で archived/active 追加)
     *   - draft / published / archived: 各 status 単独
     *   - active (= デフォルト): Draft + Published (Archived 除外、運用ノイズ削減)
     *   - 未指定 / 不正値: active と同じ挙動
     * - ?sort=cfpEndDate|eventStartDate|cfpStartDate|name|createdAt: ソートキー (Issue #47 Phase A)
     * - ?order=asc|desc: 並び順 (Issue #47 Phase A)
     */
    public function index(Request $request, ListConferencesUseCase $useCase): View
    {
        $statusParam = $request->query('status');
        $effectiveStatus = is_string($statusParam) ? $statusParam : 'active';
        $statusFilters = self::resolveStatusFilters($effectiveStatus);

        $sortParam = $request->query('sort');
        $sortKey = is_string($sortParam) ? ConferenceSortKey::tryFrom($sortParam) : null;

        $orderParam = $request->query('order');
        $order = is_string($orderParam)
            ? (SortOrder::tryFrom($orderParam) ?? SortOrder::Asc)
            : SortOrder::Asc;

        $conferences = $useCase->execute($statusFilters, $sortKey, $order);

        return view('admin.conferences.index', [
            'conferences' => $conferences,
            // Blade 側でフィルタタブのアクティブ判定に使う (string 値)。
            // 未指定 = active 扱い (= Issue #165 で Archived を default で隠す方針)。
            'statusFilter' => self::canonicalizeStatusParam($effectiveStatus),
            // Blade 側で列ヘッダの「現在のソート」表示と次にクリックした時の order 反転に使う
            'sortKey' => ($sortKey ?? ConferenceSortKey::CfpEndDate)->value,
            'sortOrder' => $order->value,
        ]);
    }

    /**
     * URL の ?status 文字列を ListConferencesUseCase に渡す配列に解決する。
     *
     * "active" は仮想 status として「Draft + Published」を意味する (Issue #165)。
     * 未知値は active と同じ扱いにして fail-soft (= ノイズが見えないことを優先)。
     *
     * @return ConferenceStatus[]
     */
    private static function resolveStatusFilters(string $statusParam): array
    {
        return match ($statusParam) {
            'draft' => [ConferenceStatus::Draft],
            'published' => [ConferenceStatus::Published],
            'archived' => [ConferenceStatus::Archived],
            // 'active' / 未指定 / 不正値: Active 扱いで Archived 除外
            default => [ConferenceStatus::Draft, ConferenceStatus::Published],
        };
    }

    /**
     * Blade のタブハイライト判定用に ?status の値を正規化する。
     * 未知値や未指定は 'active' に丸める (= UseCase 側の挙動と一致させる)。
     */
    private static function canonicalizeStatusParam(string $statusParam): string
    {
        return match ($statusParam) {
            'draft', 'published', 'archived' => $statusParam,
            default => 'active',
        };
    }

    /**
     * GET /admin/conferences/create — 新規作成フォーム。
     * categories / formats を選択肢として渡す。
     */
    public function create(ListCategoriesUseCase $listCategoriesUseCase): View
    {
        return view('admin.conferences.create', [
            'categories' => $listCategoriesUseCase->execute(),
            'formats' => ConferenceFormat::cases(),
        ]);
    }

    /**
     * POST /admin/conferences — 新規作成サブミット。
     */
    public function store(StoreConferenceRequest $request, CreateConferenceUseCase $useCase): RedirectResponse
    {
        $v = $request->validated();

        // status 省略時は Published (= 後方互換)。Phase 0.5 (Issue #41) で nullable 受付化。
        $status = isset($v['status'])
            ? (ConferenceStatus::tryFrom($v['status']) ?? ConferenceStatus::Published)
            : ConferenceStatus::Published;

        $formatRaw = $v['format'] ?? null;
        $input = new CreateConferenceInput(
            name: $v['name'],
            trackName: $v['trackName'] ?? null,
            officialUrl: $v['officialUrl'],
            cfpUrl: $v['cfpUrl'] ?? null,
            eventStartDate: $v['eventStartDate'] ?? null,
            eventEndDate: $v['eventEndDate'] ?? null,
            venue: $v['venue'] ?? null,
            format: $formatRaw !== null ? ConferenceFormat::from($formatRaw) : null,
            cfpStartDate: $v['cfpStartDate'] ?? null,
            cfpEndDate: $v['cfpEndDate'] ?? null,
            categories: $v['categories'] ?? [],
            description: $v['description'] ?? null,
            themeColor: $v['themeColor'] ?? null,
            status: $status,
        );

        $conference = $useCase->execute($input);

        return redirect()
            ->route('admin.conferences.index')
            ->with('status', "「{$conference->name}」を作成しました");
    }

    /**
     * GET /admin/conferences/{id}/edit — 編集フォーム。
     */
    public function edit(
        string $id,
        GetConferenceUseCase $getUseCase,
        ListCategoriesUseCase $listCategoriesUseCase,
    ): View {
        try {
            $conference = $getUseCase->execute($id);
        } catch (ConferenceNotFoundException) {
            abort(404);
        }

        return view('admin.conferences.edit', [
            'conference' => $conference,
            'categories' => $listCategoriesUseCase->execute(),
            'formats' => ConferenceFormat::cases(),
        ]);
    }

    /**
     * PUT /admin/conferences/{id} — 更新サブミット (部分更新)。
     */
    public function update(
        string $id,
        UpdateConferenceRequest $request,
        UpdateConferenceUseCase $useCase,
    ): RedirectResponse {
        $validated = $request->validated();

        // ── HTML form のチェックボックス全外し対応 (Issue #121) ──
        // HTML 仕様で未チェックの checkbox は POST されないため、ユーザーが
        // categories のチェックを全て外して submit すると `categories` キー自体が
        // request body に存在しない。UpdateConferenceUseCase は「キー不在 = 既存値
        // 維持」の partial update セマンティクスのため、補完しないと既存カテゴリが
        // 残ってしまう (= ユーザー意図と乖離)。
        //
        // 本 Controller は HTML form 用 (= 全フィールド一括 submit が前提) なので、
        // categories 未送信 = ユーザーが意図的に空にしたと解釈し、空配列で上書きする。
        // API 経由 (Api\ConferenceController) では `'sometimes'` の partial update
        // セマンティクスを保つため、この補完は入れない。
        if (! $request->has('categories')) {
            $validated['categories'] = [];
        }

        // ConferenceController (API) と同じ「format / status 変換 + typed shape」パターン。
        // Phase 0.5 (Issue #41) で cfpUrl 等を nullable 受付化、status 受付追加。
        /** @var array{
         *     status?: ConferenceStatus,
         *     name?: string,
         *     trackName?: string|null,
         *     officialUrl?: string,
         *     cfpUrl?: string|null,
         *     eventStartDate?: string|null,
         *     eventEndDate?: string|null,
         *     venue?: string|null,
         *     format?: ConferenceFormat|null,
         *     cfpStartDate?: string|null,
         *     cfpEndDate?: string|null,
         *     categories?: array<int, string>,
         *     description?: string|null,
         *     themeColor?: string|null,
         * } $fields
         */
        $fields = $validated;
        if (array_key_exists('format', $validated)) {
            $fields['format'] = $validated['format'] !== null ? ConferenceFormat::from($validated['format']) : null;
        }
        if (isset($validated['status'])) {
            $fields['status'] = ConferenceStatus::from($validated['status']);
        }

        try {
            $conference = $useCase->execute($id, $fields);
        } catch (ConferenceNotFoundException) {
            abort(404);
        }

        return redirect()
            ->route('admin.conferences.index')
            ->with('status', "「{$conference->name}」を更新しました");
    }

    /**
     * DELETE /admin/conferences/{id} — 削除実行。
     */
    public function destroy(string $id, DeleteConferenceUseCase $useCase): RedirectResponse
    {
        try {
            $useCase->execute($id);
        } catch (ConferenceNotFoundException) {
            abort(404);
        }

        return redirect()
            ->route('admin.conferences.index')
            ->with('status', 'カンファレンスを削除しました');
    }

    /**
     * POST /admin/conferences/{id}/publish — Draft → Published 昇格専用 (Phase 0.5 / Issue #41)。
     *
     * 一覧画面の Draft 行から 1 クリックで公開するためのショートカット。
     * 既存エンティティの Published 必須項目が揃っているかを Repository データで検証し、
     * 欠落時は edit 画面に戻して error フラッシュを出す (= 編集してから再公開を促す)。
     */
    public function publish(
        string $id,
        GetConferenceUseCase $getUseCase,
        UpdateConferenceUseCase $updateUseCase,
    ): RedirectResponse {
        try {
            $existing = $getUseCase->execute($id);
        } catch (ConferenceNotFoundException) {
            abort(404);
        }

        $missing = $this->missingPublishedFields($existing);
        if ($missing !== []) {
            return redirect()
                ->route('admin.conferences.edit', $id)
                ->with('error', '公開に必要な項目が不足しています: '.implode(', ', $missing));
        }

        $updateUseCase->execute($id, ['status' => ConferenceStatus::Published]);

        return redirect()
            ->route('admin.conferences.index')
            ->with('status', "「{$existing->name}」を公開しました");
    }

    /**
     * Published 状態が要求する非 null 項目で、現状 null になっているフィールド名を返す。
     *
     * @return string[]
     */
    private function missingPublishedFields(Conference $c): array
    {
        $missing = [];
        if ($c->cfpUrl === null) {
            $missing[] = 'cfpUrl';
        }
        if ($c->eventStartDate === null) {
            $missing[] = 'eventStartDate';
        }
        if ($c->eventEndDate === null) {
            $missing[] = 'eventEndDate';
        }
        if ($c->venue === null) {
            $missing[] = 'venue';
        }
        if ($c->format === null) {
            $missing[] = 'format';
        }
        if ($c->cfpEndDate === null) {
            $missing[] = 'cfpEndDate';
        }
        // Issue #121: categories は publish の必須項目から除外。
        // カテゴリ未確定でも公開できる運用に揃える (Conference Domain VO は
        // categories: string[] で 0 件を許容済み)。

        return $missing;
    }

    /**
     * POST /admin/conferences/extract-from-url — 公式 URL から ConferenceDraft を
     * LLM 抽出して Create 画面に prefill する (Issue #40 Phase 3 PR-3)。
     *
     * 流れ:
     * 1. URL バリデーション (HTTPS のみ、URL 形式)
     * 2. ExtractConferenceDraftUseCase が HtmlFetcher → LLM で抽出
     * 3. ConferenceDraft → form 入力 array に変換 (categorySlugs → categoryIds 解決)
     * 4. create にリダイレクト + withInput で old() 復元される
     *
     * 例外処理:
     * - HtmlFetchFailedException / LlmExtractionFailedException は error フラッシュで
     *   create 画面に戻す (UI で修正・再試行 or 手動入力にフォールバック)
     *
     * Rate Limit: routes/admin-ui.php の throttle middleware で 1 admin あたり 1 時間 50 件
     * (= cost runaway 防止、project memory project_no_api_keys_policy.md の運用ガード)
     */
    public function extractFromUrl(
        Request $request,
        ExtractConferenceDraftUseCase $useCase,
        CategoryRepository $categoryRepository,
    ): RedirectResponse {
        $validator = Validator::make(
            $request->all(),
            [
                'url' => ['required', 'string', 'url', 'starts_with:https://', 'max:2000'],
            ],
            [
                'url.starts_with' => 'URL は https:// で始める必要があります',
                'url.url' => 'URL の形式が不正です',
            ],
        );
        if ($validator->fails()) {
            return redirect()
                ->route('admin.conferences.create')
                ->withErrors($validator)
                ->withInput();
        }

        /** @var array{url: string} $validated */
        $validated = $validator->validated();
        $url = $validated['url'];

        try {
            $draft = $useCase->execute($url);
        } catch (HtmlFetchFailedException $e) {
            Log::warning('extract-from-url ui: html fetch failed', [
                'channel' => 'llm.extraction.ui',
                'source_url' => $url,
                'exception_type' => $e::class,
            ]);

            return redirect()
                ->route('admin.conferences.create')
                ->with('error', "URL からの取り込みに失敗しました: {$e->getMessage()}");
        } catch (LlmExtractionFailedException $e) {
            Log::warning('extract-from-url ui: llm extraction failed', [
                'channel' => 'llm.extraction.ui',
                'source_url' => $url,
                'exception_type' => $e::class,
            ]);

            return redirect()
                ->route('admin.conferences.create')
                ->with('error', "URL からの取り込み (LLM 抽出) に失敗しました: {$e->getMessage()}");
        }

        $formInput = $this->draftToFormInput($draft, $categoryRepository);

        // 観測ログ: どのフィールドが LLM で埋まり、どれが null かを記録
        // (= 後で精度評価する材料に使う、Phase 3 PR-4)
        $filledFields = [];
        $nullFields = [];
        foreach ($formInput as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                $nullFields[] = $key;
            } else {
                $filledFields[] = $key;
            }
        }
        $categories = $formInput['categories'];
        Log::info('extract-from-url ui: succeeded', [
            'channel' => 'llm.extraction.ui',
            'source_url' => $url,
            'filled_fields' => $filledFields,
            'null_fields' => $nullFields,
            'category_count' => is_array($categories) ? count($categories) : 0,
        ]);

        return redirect()
            ->route('admin.conferences.create')
            ->withInput($formInput)
            ->with('status', "{$url} から情報を取り込みました。内容を確認・修正して保存してください。");
    }

    /**
     * ConferenceDraft → create フォーム用の入力 array に変換する。
     * categorySlugs は CategoryRepository::findBySlug() で UUID 配列に解決する。
     * 解決できない slug は除外 (= LLM 推測値の defensive 扱い)。
     *
     * @return array<string, mixed>
     */
    private function draftToFormInput(ConferenceDraft $draft, CategoryRepository $repo): array
    {
        $categoryIds = [];
        foreach ($draft->categorySlugs as $slug) {
            $category = $repo->findBySlug($slug);
            if ($category !== null) {
                $categoryIds[] = $category->categoryId;
            }
        }

        return [
            'name' => $draft->name,
            'trackName' => $draft->trackName,
            'officialUrl' => $draft->officialUrl,
            'cfpUrl' => $draft->cfpUrl,
            'eventStartDate' => $draft->eventStartDate,
            'eventEndDate' => $draft->eventEndDate,
            'venue' => $draft->venue,
            'format' => $draft->format?->value,
            'cfpStartDate' => $draft->cfpStartDate,
            'cfpEndDate' => $draft->cfpEndDate,
            'categories' => $categoryIds,
            'description' => $draft->description,
            'themeColor' => $draft->themeColor,
        ];
    }
}
