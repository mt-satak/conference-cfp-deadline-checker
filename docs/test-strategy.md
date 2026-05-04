# テスト戦略

`Conference CfP Deadline Checker` のテスト方針・カバレッジルール・運用ガイド。
新規 contributor がテストを書く際の判断基準と、カバレッジゲートで落ちた時の
対処法をまとめる。

> 本ドキュメントは Issue #12 (PR #14) で導入したカバレッジゲートの設計判断を
> 後から参照できるよう Issue #15 で起票・整備したもの。

---

## 1. 全体方針

### バックエンド (admin-api)

- フレームワーク: **Pest 4** (PHPUnit 上)
- 配置: `apps/admin-api/tests/`
  - `Feature/` HTTP リクエスト経由でアプリ全体を駆動するテスト (主力)
  - `Unit/` 単一クラスを直接呼ぶテスト (Feature では構造的に踏めない部分のみ)
- 粒度の使い分けは「§3 Feature と Unit の使い分け」参照

### フロントエンド (apps/frontend, 未着手)

- フレームワーク: **Vitest** + React Testing Library 想定 (Astro SSG)
- 詳細は apps/frontend 着手時に本ドキュメントへ追記する (現時点ではプレースホルダ)
- 同じ「層別閾値」「padding テスト禁止」の原則を適用予定

### カバレッジは数値より「品質担保レベル」

- 全コード一律 X% ではなく、層 (namespace) ごとに「その層で本当に必要な担保」を設定する
- 数値を満たすために padding テストを書くのは禁止 (§4 参照)
- 数値が下がったら原因を見て「テスト不足」「ツール都合」「SUT に死分岐」のどれかを判断する

---

## 2. 層別 C1 (Branch Coverage) 閾値

判定スクリプト: `apps/admin-api/scripts/check-coverage.php`
入力: `storage/coverage/clover.xml` (PHPUnit Clover)
ゲート: `git push` 時に `.githooks/pre-push` から自動実行

### 閾値表 (admin-api)

| 層 (namespace プレフィクス) | 閾値 | 根拠 |
|---|---|---|
| `App\Domain\*` | **100%** | Entity / VO / Domain Exception。ビジネスロジックの中核。フレームワーク依存ゼロで testable なため untested 分岐を許す理由がない |
| `App\Application\*` | **100%** | UseCase。ビジネスフロー本体。Repository を Mockery で差し替えるだけで全分岐到達可能 |
| `App\Http\Presenters\*` | **100%** | Domain Entity → JSON 配列変換のみ。防御コード不要、入力経路も限定的 |
| `App\Http\Requests\*` | **100%** | バリデーションルール定義。Feature の各エンドポイントテストで自然に網羅される |
| `App\Http\Controllers\*` | **85%** | UseCase 呼出と Presenter 呼出の薄いオーケストレーション。Feature テスト主体で 100% を狙うとモック嵐になり過剰 |
| `App\Http\Middleware\*` | **85%** | フレームワーク hook (handle メソッドの Closure 等) 由来の追加分岐があり得るため Controllers と同水準 |
| `App\Exceptions\*` | **75%** ⚠️ | xdebug の compound branch カウント特有の構造的限界。詳細は §5 |
| `App\Infrastructure\*` | **75%** | AWS SDK の例外パス (`DynamoDbException` の各 errorCode 分岐等) のモック網羅コストが高く、実トラフィックでしか発生しない経路がある |
| `App\Providers\*` | (計測対象外) | DI 配線。Lambda コンテナ起動時のみ走り、HTTP テストでは検査困難 |

### 設計原則

- **「ロジック層」(Domain / Application) は 100% 必須**: ビジネスルールが集中するため、未カバー分岐は実質的なリグレッション余地
- **「データ整形層」(Presenters / Requests) も 100%**: 入出力 shape 変換は入力経路が少なく 100% 容易
- **「薄い橋渡し層」(Controllers / Middleware) は 85%**: テストを増やすと SUT 構造をなぞるだけになりやすい
- **「外部 SDK 境界 / 例外フォーマット層」(Infrastructure / Exceptions) は 75%**: モック / xdebug ツーリングの構造的限界

---

## 3. Feature と Unit の使い分け

### 原則: Feature を主力、Unit は補完

- **Feature テスト**は実 HTTP リクエストでアプリ全体を駆動し、振る舞いの契約 (status / レスポンス shape / DB 状態) を検証する
- **Unit テスト**は Feature では構造的に踏めない分岐 / 組合せに限定する

### Unit を書く判断基準 (3 つすべて満たす場合のみ)

1. **Feature では構造的に踏めない**
   - 例: `$request->is('admin/api/*')` の FALSE 分岐 — 非 admin/api パスの例外は Laravel ルート段階で別経路を通り Renderer に到達しない
   - 例: 空メッセージ HttpException — HTTP リクエストから自然に構築する経路が存在しない
2. **その分岐固有の振る舞いを検証する**
   - 「同じ assertion を別起動点で繰り返すだけ」のテストは padding (§4)
3. **Domain / Application 層のロジック組合せ網羅**
   - ロジック層は Mockery で Repository を差し替えれば直接 UseCase を呼べる
   - Feature 経由だと組合せ爆発 (eg. UpdateConferenceUseCase の部分更新の各キー有無パターン)

### 反例 (書かない)

- ❌ `ConferenceNotFoundException → 404 NOT_FOUND` を Feature で検証済みなのに Unit で `__invoke()` を直接呼んで同じ assertion を繰り返す
  - 理由: Renderer の振る舞いが壊れた時、Unit が落ちる時には対応する Feature も落ちる ⇒ 検出力ゼロの重複
  - 経緯: PR #14 のレビューで commit 0452e7c が「padding 例」として整理対象になった

---

## 4. padding テスト禁止

「カバレッジ閾値を満たすために、検出力のないテストを書く」のは禁止。

### padding の典型パターン

1. **assertion 重複** Feature と完全に同じ status/error.code を Unit で別起動点から再アサート
2. **死分岐テスト** SUT 内に「実コードベースで一切到達しない match arm」をテストする (eg. `HttpException(409)` を投げるコードが無いのに 409 → CONFLICT を Unit でアサート)
3. **構造模写** SUT の match arm 順序 / 内部メソッド名 をそのまま再宣言するだけのテスト

### padding を発見したら

1. **第一選択: SUT 側を YAGNI で削る**
   - 死分岐 (どのテストが当たっても production trigger が無い) は SUT から削除
   - 例 (PR #14): `codeForHttpStatus` の 409/422/429/503 arm を削除、`is_string($fields['format'])` 削除、`pickFormat` ヘルパ削除
2. **次の選択: 閾値側で受け止める**
   - SUT を削れない (将来必要になる予定がある等) かつ、Feature で意味のある assertion に置き換え不可なら、層別閾値を実測可能ラインまで下げる
   - 「実測上限 + バッファ」で設定する。ピッタリ実測値にすると 1 branch 滑った時に gate が割れる
3. **最終手段: `// @codeCoverageIgnore`**
   - 意図的に防御コードを残す場合のみ。乱用しない

---

## 5. なぜ Exceptions 層を 75% にしたか (PR #14 の経緯)

`App\Exceptions\AdminApiExceptionRenderer` の C1 を実測したところ **79.59% (39/49) が padding なしの上限**だった。経緯と判断プロセスを記録する。

### 5.1 xdebug の compound branch カウント

xdebug の C1 計測は PHP の `match (true)` を以下のように分割カウントする:

```php
return match (true) {
    $e instanceof ValidationException => $this->renderValidation($e),     // ← 4 micro-branches
    $e instanceof InvalidOriginException => $this->renderInvalidOrigin(), // ← 4 micro-branches
    // ...
};
```

各 arm は xdebug 内部で:
1. `instanceof` 評価が TRUE
2. `instanceof` 評価が FALSE
3. arm body へ進入
4. arm body を skip

の 4 micro-branches としてカウントされる。

さらに compound 条件 (PR #14 で `isCsrfMismatch()` ヘルパに切り出す前は match arm に直接書いていた) は `&&` の short-circuit でさらに細分化される:

```php
($e instanceof HttpException && $e->getStatusCode() === 419) => ...
//  論理経路は 3 つ (instanceof FALSE / TRUE+419 / TRUE+非419) だが
//  xdebug は 8 micro-branches としてカウント
```

### 5.2 Feature だけでは届かない理由

Feature テストが各 arm の TRUE/FALSE を踏むには、その例外型を投げる HTTP リクエストを発行する必要がある。しかし:

- 「非 admin/api パス」分岐 ⇒ Laravel の routing 段階で別経路を通り Renderer 自体が呼ばれない
- 「空メッセージ HttpException」⇒ HTTP リクエストから自然に発生する経路がない
- 各 arm の compound short-circuit ⇒ 同一 instanceof 型で複数ステータス踏むテストが必要、Feature では冗長

これらを Unit で埋めると、§4 で禁止した padding パターンに該当するケースが増える。

### 5.3 トレードオフと結論

| 案 | 評価 |
|---|---|
| Unit 補強で 90% を目指す | padding テストが必須化、検出力ゼロの assertion 重複が大量発生。却下 |
| match (true) を if/return チェーンに書き換え | xdebug の counting は同等、根本解決にならない。却下 |
| 閾値を実測上限 (79.59%) 付近に設定 | 1 branch 滑った時 gate 割れ → padding 圧力。却下 |
| **閾値を 75% に設定** (実測上限から余裕 4.59pt) | 採用。後続 PR で多少 branch が下がっても padding 強制せず吸収可能 |

「padding を増やす」より「ツール都合の構造的限界を閾値で受け止める」方が筋が通ると判断した。

### 5.4 Renderer に残した本物の Unit テスト (2 ケース)

`apps/admin-api/tests/Unit/Exceptions/AdminApiExceptionRendererTest.php`:

1. **非 admin/api パス → null** Feature では構造的に踏めない FALSE 分岐
2. **HttpException(403, "") → message="HTTP 403"** 空メッセージのフォールバック ternary。HTTP 経由では構築できない

両方とも「Feature で代替不可」かつ「固有の振る舞い検証」を満たす。

---

## 6. テストの書き方ルール

### 共通

- **テスト名は日本語**で、「主語 + 条件 + 期待」の形にする
  - 良い例: `it('admin/api 配下で ValidationException が 422 + VALIDATION_FAILED を返す')`
  - 悪い例: `it('test validation error')`
- **Given-When-Then (GWT) コメント**を本文に付ける
  ```php
  it('XXXX', function () {
      // Given: 前提条件 (DI モック設定 / Route 動的登録 / fixture 投入 等)
      // When: 実行する操作 (HTTP リクエスト / メソッド呼出)
      // Then: 期待する結果 (status / response shape / DB 状態 / 例外)
  });
  ```
- assertion はできる限り「振る舞いの契約」に対して行う (status code / error.code / DB の永続化結果)。実装詳細 (内部メソッドが呼ばれた回数等) は最小限に

### Feature テスト

- 配置: `tests/Feature/`
- HTTP 経由 (`$this->getJson()` / `postJson()` 等) で駆動
- Mockery で UseCase を差し替えてエッジケースを作るのは OK (eg. `ConferenceNotFoundException` を投げさせる)
- Route::middleware('web') で動的にルート登録するパターンは「フレームワーク振る舞いを検証したいが、本番ルートを汚さない」場合に使う

### Unit テスト

- 配置: `tests/Unit/`
- §3 の 3 条件を満たす場合のみ書く
- ファイル冒頭に「なぜこのファイルが Unit として必要か」を doc コメントで明記
- Feature で同じ assertion を踏んでいる場合は重複理由 (xdebug branch tooling 補完 等) を明記しないと padding と判別不能

---

## 7. ゲート運用

### ローカル開発時

```sh
make api-test            # 高速 (xdebug 無し、~5s)
make api-test-coverage   # カバレッジ計測 (xdebug 必須、~25s)
make api-coverage-check  # 層別閾値判定
```

### push 時の自動ゲート (`.githooks/pre-push`)

1. `git push` で発火
2. push 対象 ref の差分に `apps/admin-api/`、`packages/`、`docker/` のいずれかの変更があるか判定
3. 変更があれば `make api-test-coverage` + `make api-coverage-check` を実行
4. いずれかの class が層別閾値未達なら exit 1 で push 中止
5. 変更なし (= フロントエンドのみの変更等) なら ✅ skip

フックの自動セットアップは `pnpm install` の `postinstall` で `git config core.hooksPath .githooks` が走る。手動有効化も可能。

### 緊急時バイパス

```sh
SKIP_COVERAGE_CHECK=1 git push
```

CI 導入後は CI 側でも同等のゲートを掛けるため、バイパス push は CI で弾かれる想定。日常的には使わない。

---

## 8. ゲートで落ちた時の対処手順

`make api-coverage-check` が ❌ FAIL を出した時:

1. **どの class が落ちたか確認**
   ```
   ❌ App\Foo\Bar  73.50%  threshold=85%
   ```
2. **HTML レポートで未カバー branch を特定**
   ```sh
   open apps/admin-api/storage/coverage/html/index.html
   ```
   `_branch.html` で `class="danger"` の行を探す
3. **未カバーの理由を分類**
   - **(a) テスト不足** ⇒ 不足分の Feature/Unit を追加。「§3 の 3 条件」を満たすか自問
   - **(b) SUT に死分岐** ⇒ 該当コードに到達する production trigger が無いなら **削除**
   - **(c) ツール都合** ⇒ xdebug の compound branch カウント等。代替策が無ければ **層別閾値の見直し** を提案 (PR で議論)
4. **「90% 未達だから Unit を増やす」を反射でやらない**: §4 の padding パターンに該当しないか必ず確認

---

## 9. 関連 Issue / PR

| 番号 | 内容 |
|---|---|
| Issue #12 | テストカバレッジ計測導入 (Pest + Vitest) と C1 90% ルール |
| PR #14 | Issue #12 実装。レビューで層別閾値方針に変更 |
| Issue #15 | 本ドキュメントの整備 |

---

## 10. TODO (本ドキュメントの将来追記)

- [ ] フロントエンド (Vitest + RTL) の閾値・運用 — `apps/frontend` 着手時
- [ ] CI 環境 (GitHub Actions 等) でのゲート — CI 導入時
- [ ] PHPStan (Issue #13) との関係 — PHPStan 導入後に「型ゲート + カバレッジゲート」の関係を整理
