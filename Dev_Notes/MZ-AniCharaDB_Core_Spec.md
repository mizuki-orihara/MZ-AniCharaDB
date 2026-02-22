# MZ-AniCharaDB core/watchdog・ゲート設計メモ
作成日: 2026-02-22

---

## 基本設計方針

### core.phpの役割

- cronから定期的に呼ばれる監視係
- モジュールを上から順に呼び出し、終了コードだけ見て次へ進むかどうか判断
- ゲートの中身・処理内容には関知しない
- `flock()` で自身の多重起動を防ぐ

```
cron（定期）
  ↓
core.php
  ↓ exit(0) → 次のモジュールへ
  ↓ exit(1) → 停止・ログ記録
import_Norm.php
normalizer.php
dispatcher.php
archiver.php
indexer_add.php
indexer_rebuild.php
（statistics系）
```

### 終了コード規約（全モジュール共通）

| exit コード | 意味 |
|------------|------|
| exit(0) | 正常終了（処理完了・ゲート閉で何もしなかった・対象ファイルなし、すべて含む） |
| exit(1) | 異常終了（処理中にエラーが発生した場合） |

### ゲートチェックの共通パターン

```php
require_once __DIR__ . '/api/config.php';

if (!is_gate_open('ゲート名')) {
    exit(0); // ゲート閉 → 何もせず正常終了
}

// ===== 処理本体 =====
```

`is_gate_open()` は `config.php` に共通関数として定義する。

---

## ゲートの概念

ゲートは「自分が動いていいか」ではなく、**「下流が上流に汲み取りに来ていいか」** の許可フラグ。

```
receive = true
  → normalizer.phpが import_Norm の成果物を汲み取りに行っていいか

normalize = true
  → dispatcher.phpが normalizer の成果物を汲み取りに行っていいか
```

最上流の `import_Norm` / `import_bulk` は汲み取り先の上流が存在しないが、
慣例として自身のゲートを持つ（外部からの受付可否制御にも使える）。

### 親子ゲート（statistics系）

```
build_statistics = true   // 親ゲート
  かつ
  take_tag_consolidation = true  // 子ゲート
  → 両方 true で初めて動ける

build_statistics = false の場合
  → 子ゲートが true でも動けない
```

---

## 運用モード例

ゲートの開閉の組み合わせが「運用モードの設定」になる。

### 通常運用

```json
"gates": {
  "receive":        true,
  "normalize":      true,
  "dispatch":       true,
  "archive":        true,
  "merge_return":   true,
  "index_add":      true,
  "index_rebuild":  false
}
```

### ベリファイ貯め置きモード（dispatchを止める）

```json
"gates": {
  "receive":        true,
  "normalize":      true,
  "dispatch":       false,   // ← 閉じる
  "archive":        true,
  "merge_return":   false,
  "index_add":      false,
  "index_rebuild":  false
}
```

この状態でcore.phpが動くと：

```
import_Norm.php     → 受信処理 or 対象なし → exit(0)
normalizer.php      → normalize=true → 処理実行 → exit(0)
dispatcher.php      → dispatch=false → 何もせず → exit(0)
archiver.php        → archive=true → 処理実行 → exit(0)
indexer_add.php     → index_add=false → 何もせず → exit(0)
indexer_rebuild.php → index_rebuild=false → 何もせず → exit(0)
```

dispatchより下流は動かないが、normalizer までは正常に処理が進む。
一貫性を保ったまま意図的に貯め置きできる。

### 夜間インデックス再構築モード

```json
"gates": {
  "receive":        false,
  "normalize":      false,
  "dispatch":       false,
  "archive":        false,
  "merge_return":   false,
  "index_add":      false,
  "index_rebuild":  true    // ← これだけ開く
}
```

```
import_Norm.php     → receive=false → exit(0)
normalizer.php      → normalize=false → exit(0)
dispatcher.php      → dispatch=false → exit(0)
archiver.php        → archive=false → exit(0)
indexer_add.php     → index_add=false → exit(0)
indexer_rebuild.php → index_rebuild=true → 処理実行 → exit(0)
```

全モジュールが同じ作法で書かれているため、
どのゲートの組み合わせでも破綻しない。

---

## TODO・再考タスク

### モジュール名とゲート名の対応整理（要確認）

現状のゲート名と想定モジュールの対応が一部ずれている。
実装前に確定させること。

| 現状のゲート名 | 対応モジュール（想定） | 問題・メモ |
|--------------|-------------------|----------|
| `receive` | import_Norm.php | OK |
| `receive_bulk` | import_bulk.php | ゲートリストに未追加 |
| `varid_verify` | normalizer.php | モジュール名変更に伴い `normalize` に改名要 |
| `dispatch` | dispatcher.php | OK |
| `archive` | archiver.php | OK |
| `merge_return` | merger（未実装） | typo: `marge_return` → `merge_return` に修正要 |
| `index_add` | indexer_add.php | OK |
| `index_rebuild` | indexer_rebuild.php | OK |
| `build_statistics` | statistics系の親ゲート | OK |

### 修正アクション
- [ ] `varid_verify` → `normalize` にリネーム（task_master.json・normalizer.php両方）
- [ ] `marge_return` → `merge_return` にtypo修正
- [ ] `receive_bulk` をゲートリストに追加
- [ ] `is_gate_open()` を config.php に実装
- [ ] core.php のモジュールリストと順序を確定
