# ステータス集約サブAPI プラン

## 目的
各モジュールが出力する `*_status.json` を一括収集し、管理メニュー（`console/menu.html`）で横断表示できるようにする。

## 対象
- 収集先: `src/app` 配下（再帰）
- 収集形式: `*_status.json`
- API: `src/app/API/status_overview.php`
- 表示先: `src/app/console/menu.html`

## API仕様（初版）
- メソッド: `GET` のみ
- レスポンス:
  - `status`: `ok` / `error`
  - `generated_at`: API生成時刻
  - `summary`: `total / ok / warning / error / other`
  - `modules[]`:
    - `module`: モジュール名（`*_status.json` の接頭辞）
    - `status`: 各モジュール状態
    - `message`: 補助メッセージ
    - `updated_at`: ステータスファイル更新時刻
    - `path`: 相対パス
    - `raw`: 元JSON

## 管理メニュー表示仕様（初版）
- 画面上部にサマリー（件数）
- テーブルにモジュール一覧
- 「再読込」ボタンで手動更新
- API失敗時はテーブル内にエラー表示

## 今後の拡張ポイント
1. ソート切替（状態順 / 更新日時順）
2. モジュール名フィルタ
3. `raw` の展開表示（詳細モーダル）
4. `task_master.json` の gate 状態との突合表示
