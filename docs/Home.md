# Iscogram Wiki

Iscogram（private-isu）は、ISUCONの練習用Webアプリケーションです。SNS風の画像投稿サービスを題材に、Webアプリケーションのパフォーマンスチューニングを学ぶことができます。

## 目次

### 基本情報
- [Home](Home.md) - このページ
- [Architecture](Architecture.md) - システム構成・アーキテクチャ
- [Database-Schema](Database-Schema.md) - データベーススキーマ

### セットアップ
- [Getting-Started](Getting-Started.md) - 環境構築・起動方法

### 開発ガイド
- [API-Endpoints](API-Endpoints.md) - APIエンドポイント一覧
- [Benchmarker](Benchmarker.md) - ベンチマーカーの使い方

### チューニング
- [Performance-Tuning](Performance-Tuning.md) - パフォーマンス改善戦略
- [Score-Log](Score-Log.md) - スコア推移記録

---

## プロジェクト概要

### Iscogramとは

ユーザーが画像を投稿し、他のユーザーがコメントできるシンプルなSNSアプリケーションです。

**主な機能:**
- ユーザー登録・ログイン
- 画像のアップロード・投稿
- タイムライン表示
- 投稿へのコメント
- ユーザープロフィール表示
- 管理者によるユーザーBAN機能

### 対応言語

5つの言語による参考実装が提供されています：

| 言語 | フレームワーク | ディレクトリ |
|------|----------------|--------------|
| Ruby | Sinatra | `ruby/` |
| Go | chi | `golang/` |
| PHP | Slim 4 | `php/` |
| Python | Flask | `python/` |
| Node.js | Hono | `node/` |

### ディレクトリ構成

```
webapp/
├── compose.yml          # Docker Compose設定
├── sql/                 # 初期データ
├── etc/nginx/conf.d/    # Nginx設定
├── public/              # 静的ファイル
├── ruby/                # Ruby実装（デフォルト）
├── golang/              # Go実装
├── php/                 # PHP実装
├── python/              # Python実装
├── node/                # Node.js実装
└── docs/                # ドキュメント
```

---

## クイックスタート

### 1. Docker Composeで起動

```bash
cd webapp
docker compose up --build
```

### 2. ブラウザでアクセス

http://localhost

**テストアカウント:**
- アカウント名: `mary`
- パスワード: `marymary`

---

## 関連リンク

- [GitHub リポジトリ](https://github.com/catatsuy/private-isu)
- [ISUCON公式サイト](https://isucon.net)
- [達人が教えるWebパフォーマンスチューニング](https://gihyo.jp/book/2022/978-4-297-12846-3)
