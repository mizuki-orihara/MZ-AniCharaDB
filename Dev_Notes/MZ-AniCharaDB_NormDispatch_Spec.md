# MZ-AniCharaDB 処理モジュール仕様ノート
## normalizer.php / dispatcher.php
作成日: 2026-02-22

---

## パイプライン上の位置づけ

```
01a_import_norm/~temp_[SID]/   ← import_Norm.phpが受信・一時保存
        ↓
  normalizer.php  （ステージ2）
        ↓
02_valid/Confirmed/             ← 正規化済み・dispatcher参照先
02_valid/error/                 ← 正規化失敗
        ↓
  dispatcher.php  （ステージ3）
        ↓
mainDB/registercache/           ← 新規カード（indexer待ち）
03b_merge/                      ← 競合カード（人手マージ待ち）
破棄待ちリスト                   ← 完全重複カード（archiver処理待ち）
```

---

## normalizer.php

### 役割

`01a_import_norm/~temp_[SID]/` に格納されたJSONファイルを1枚ずつ読み取り、正規化・検証のうえ `02_valid/Confirmed/` または `02_valid/error/` に出力する。

### 処理順序

#### 1. スキーマチェック

- `header.schema` キーの存在確認
- 値が既知のスキーマバージョン文字列と一致するか確認
- 不一致・キー欠損の場合は `02_valid/error/` へ移動して処理中断

既知スキーマバージョン（随時追記）:
```
MiZu_Character_Profile_v1.2602.01
MiZu_Character_Profile_v1.2508.02
```

#### 2. JSON正規化（複製転記方式）

破損JSONを直接修復するのではなく、**既知フィールドを定義順に読み取って新しいJSONオブジェクトに転記する**方式をとる。これにより以下が自然に排除される：

- 余分な記号・クォート（例: `2017,"` → `2017`）
- カンマ抜け等の構文エラー
- キー末尾の余分な記号（例: `character_designer_film_` → `character_designer_film`）
- 値の先頭・末尾の余分なスペース・タブ（トリム）
- タブ・スペース混在のインデント

**転記対象フィールド（定義順）:**

```
header.*
name
work
profile.name_reading
profile.height_cm.*
profile.weight_kg.*
profile.birthday.*
profile.blood_type.*
profile.custom_fields[].label
profile.custom_fields[].value
profile.custom_fields[].is_official
product.*           （キーは保持。値のみトリム）
tags.affiliations[]
tags.character_tags[]
rating.*
personality_parameters.軸N.labels[]
personality_parameters.軸N.values[]
personality_parameters.軸N.anchor
extended.*.*        （ブロック名・パラメーター名は保持。値のみトリム）
assets[]
comments.intro[]
comments.evaluation[].content
comments.evaluation[].author_uuid
```

**normalizer が処理しないもの（名寄せフェーズへ）:**

- キーの日英混在（`"アニメ制作"` と `"studio"` 等）
- スペルミス（`voise` → `voice` 等）
- 表記ゆれ（`"担当声優(正)"` と `"chara_voice_2nd"` が同概念かどうか等）
- 値が同一なのにキーが分かれているケースの統合判断

これらは名寄せマスター整備フェーズで人手が判断する。

#### 3. content_hash の生成・付与

- ハッシュ対象: 転記済みJSONの **headerブロックより下の本文部分**（`name` 以下）
- ハッシュアルゴリズム: SHA-256（予定）
- 書き込み先: `header.content_hash`

ハッシュはdispatcher.phpでの重複・競合判定に使用する。

#### 4. 出力

- 正規化成功: `02_valid/Confirmed/{元ファイル名}.json` に保存
- 正規化失敗（スキーマ不一致・必須キー欠損等）: `02_valid/error/{元ファイル名}.json` に移動し、エラー理由をファイル名またはサイドカーファイルに記録（詳細は未定）

### 汚れデータ入力例

以下は実際に発生しうる汚れデータの例。normalizer正規化フェーズで機械的に処理できるものと、名寄せフェーズに送るものを示す。

```json
"product": {
  "アニメ制作": "PINE JAM",
  "year":       2017,"                    // ← 余分な" → トリムで除去
  "担当声優(正)":   "Lynn",               // ← キー表記ゆれ → 名寄せフェーズへ
  "chara_voise_2nd": " M・A・O（ラジオ企画にて）",  // ← 値先頭スペース→トリム、スペルミス→名寄せ
  "原作":       "鴨志田一"                // ← カンマ抜け → 転記時に自動補完
  "character_designer_film_": "田中将賀"  // ← キー末尾_ → 除去
  "character_designer_novel": "田中将賀"  // ← 値同一キー分離 → 名寄せフェーズへ
}
```

| 問題 | 処理 |
|------|------|
| 値末尾の余分な `"` | 転記時に自動除去 |
| 値先頭・末尾のスペース | トリムで除去 |
| カンマ抜け | 転記方式のため自動補完 |
| キー末尾の余分な `_` | 転記時に除去 |
| キーの日英混在 | 名寄せフェーズへ |
| スペルミス | 名寄せフェーズへ |
| 表記ゆれ・意味の同一性判断 | 名寄せフェーズへ |

---

## dispatcher.php

### 役割

`02_valid/Confirmed/` の正規化済みカードを1枚ずつ読み取り、mainDBとの照合結果に応じて振り分ける。

### 判定フロー

```
02_valid/Confirmed/ のカードを1枚ずつ処理
        ↓
  作品名 + キャラ名 + 枝番 で mainDB/index.json を検索
        ↓
  ┌──────────────────────────────────────────────┐
  │                                              │
  │  該当なし                                    │
  │  → 新規カードとして mainDB/registercache/ へ  │
  │                                              │
  │  該当あり + content_hash 完全一致             │
  │  → 内容同一の重複。破棄待ちリストに記録        │
  │                                              │
  │  該当あり + content_hash 不一致              │
  │  → 内容に差異あり。03b_merge/ へ             │
  │                                              │
  └──────────────────────────────────────────────┘
```

### 各振り分け先の詳細

**mainDB/registercache/**
- 新規カードのみが集まる場所
- indexer_add.phpがここを参照してmainDB本体への登録・インデックス追記を行う
- 競合判断は不要。機械的な追記処理のみ

**破棄待ちリスト**
- 完全重複カードを記録するリスト（形式未定・JSONを想定）
- archiver.phpが「リスト記載あり かつ 24時間経過」の条件で削除処理
- 即削除せず一定時間保持することで、誤判定時の復元を可能にする

**03b_merge/**
- 同一作品・同一キャラ・同一枝番で内容に差異があるカードの待機場所
- 比較リストに既存カードのパスと新着カードのパスを記録
- 人手による目視チェックで各キーの採用値を選択し新カードを生成
- マージ完了後のフロー:
  1. 旧カードをmainDBから削除・インデックスから除去
  2. 生成した新カードを `02_valid/Confirmed/` に戻してdispatcherに再処理させる
  3. 再処理では「該当なし」として `registercache/` 経由でADD登録される

### mainDBとの照合方法

`index.json` の構造（暫定）:

```json
{
  "作品名_キャラ名_枝番": "content_hash値",
  ...
}
```

照合順序:
1. `作品名_キャラ名_枝番` をキーにindex.jsonを検索
2. 該当あり → `content_hash` を比較
3. 結果に応じて上記フローで振り分け

---

## 未定事項

- エラーファイルへのエラー理由の記録方式（サイドカーファイル vs ファイル名付記）
- 破棄待ちリストのファイル形式・保存場所
- 03b_mergeの比較リストのファイル形式
- content_hashのアルゴリズム確定（SHA-256で進める予定）
- index.jsonの詳細構造（indexer実装時に確定）
