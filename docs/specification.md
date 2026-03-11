# Linkcard プラグイン仕様書

## 概要

外部リンクからOGP（Open Graph Protocol）情報を非同期で取得し、リンクカードとして表示するPukiWikiプラグイン。

## 基本情報

| 項目 | 内容 |
|------|------|
| プラグイン名 | `linkcard` |
| プラグイン種別 | ブロック型 + アクション型 |
| バージョン | 1.0.0 |
| 動作環境 | PukiWiki 1.5.4 / PHP 8.2+ |

## 書き方の構文

### ブロック型

```
#linkcard(URL)
```

- URLは `http://` または `https://` で始まる外部リンク

### 表示例

```
#linkcard(https://example.com/article)
```

→ リンクカード（タイトル、説明文、OGP画像、URL）が表示される

## アーキテクチャ

### 非同期取得フロー

```
1. PHP（ブロック呼び出し）→ プレースホルダHTML出力（スケルトンUI）
2. JS（DOMContentLoaded後）→ アクション型APIにPOSTリクエスト
3. PHP（アクション型）→ OGP取得 or キャッシュ返却 → JSONレスポンス
4. JS → レスポンスを受け取りリンクカードDOMを更新
```

### アクション型API

- **エンドポイント**: `?plugin=linkcard`（POST、bodyに `url=<encoded_url>`）
- **レスポンス**: JSON形式
  ```json
  {
    "status": "ok",
    "title": "ページタイトル",
    "description": "ページ説明文",
    "image": "画像のキャッシュパス or 外部URL",
    "url": "元のURL",
    "site_name": "サイト名"
  }
  ```
- **エラー時**:
  ```json
  {
    "status": "error",
    "message": "エラー内容"
  }
  ```

## キャッシュ

| 項目 | 内容 |
|------|------|
| キャッシュ先 | `CACHE_DIR . 'linkcard/'` |
| キャッシュ形式 | JSON（OGP情報）+ 画像ファイル（WebP） |
| キャッシュキー | `md5(URL)` |
| 有効期間 | 7日間 |
| ファイル名 | `{md5}.json`（メタデータ）, `{md5}.webp`（画像） |
| 画像処理 | 適切なサイズにリサイズ → WebP変換 |

### キャッシュ構造

```
CACHE_DIR/linkcard/
├── {md5_hash}.json    # OGPメタデータ: title, description, image, url, site_name, cached_at
└── {md5_hash}.webp    # OGP画像（リサイズ・WebP変換済み）
```

## フロントエンド

- **ローディング**: スケルトンUI（アニメーション付き）
- **エラー時**: シンプルなリンクにフォールバック
- **ダークモード**: `PKWK_SKIN_DARK_THEME` に対応

## 設定ページ

なし（設定はPHPの定数で管理）

## データの永続化

サーバーサイドファイルキャッシュのみ（`CACHE_DIR . 'linkcard/'`）

## 注意事項

- 同一ページに複数の `&linkcard` がある場合、一括でfetchリクエストを送る
- cURL で外部リンクにアクセスする（User-Agent設定）
- 画像はサーバー側にキャッシュし、外部URLには直接リンクしない
- XSS対策として全出力値を `htmlsc()` でエスケープ
