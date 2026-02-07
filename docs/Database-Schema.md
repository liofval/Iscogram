# Database Schema - データベーススキーマ

## 概要

| テーブル名 | 用途 | 初期データ件数 |
|------------|------|----------------|
| users | ユーザー情報 | 1,000件 |
| posts | 投稿・画像データ | 10,000件 |
| comments | コメント | 100,000件 |

---

## テーブル定義

### users テーブル

```sql
CREATE TABLE users (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `account_name` varchar(64) NOT NULL UNIQUE,
  `passhash` varchar(128) NOT NULL,
  `authority` tinyint(1) NOT NULL DEFAULT 0,
  `del_flg` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4;
```

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int | ユーザーID（主キー） |
| account_name | varchar(64) | アカウント名（ユニーク） |
| passhash | varchar(128) | パスワードハッシュ（SHA-512） |
| authority | tinyint(1) | 権限（0: 一般, 1: 管理者） |
| del_flg | tinyint(1) | 削除フラグ（0: 有効, 1: BAN済み） |
| created_at | timestamp | 作成日時 |

---

### posts テーブル

```sql
CREATE TABLE posts (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` int NOT NULL,
  `mime` varchar(64) NOT NULL,
  `imgdata` mediumblob NOT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4;
```

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int | 投稿ID（主キー） |
| user_id | int | 投稿者のユーザーID |
| mime | varchar(64) | 画像のMIMEタイプ |
| imgdata | mediumblob | 画像バイナリデータ |
| body | text | 投稿本文 |
| created_at | timestamp | 投稿日時 |

**対応MIMEタイプ:** `image/jpeg`, `image/png`, `image/gif`

---

### comments テーブル

```sql
CREATE TABLE comments (
  `id` int NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `post_id` int NOT NULL,
  `user_id` int NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4;
```

| カラム | 型 | 説明 |
|--------|-----|------|
| id | int | コメントID（主キー） |
| post_id | int | 対象の投稿ID |
| user_id | int | コメント投稿者のユーザーID |
| comment | text | コメント本文 |
| created_at | timestamp | コメント日時 |

---

## ER図

```
┌─────────────────┐
│     users       │
├─────────────────┤
│ id (PK)         │◄─────────────────┐
│ account_name    │                  │
│ passhash        │                  │
│ authority       │                  │
│ del_flg         │                  │
│ created_at      │                  │
└─────────────────┘                  │
        ▲                            │
        │ user_id                    │ user_id
        │                            │
┌─────────────────┐          ┌─────────────────┐
│     posts       │          │    comments     │
├─────────────────┤          ├─────────────────┤
│ id (PK)         │◄─────────│ id (PK)         │
│ user_id (FK)    │  post_id │ post_id (FK)    │
│ mime            │          │ user_id (FK)    │
│ imgdata         │          │ comment         │
│ body            │          │ created_at      │
│ created_at      │          └─────────────────┘
└─────────────────┘
```

---

## 初期化処理 (db_initialize)

`GET /initialize` で実行される初期化処理：

```sql
DELETE FROM users WHERE id > 1000;
DELETE FROM posts WHERE id > 10000;
DELETE FROM comments WHERE id > 100000;
UPDATE users SET del_flg = 0;
UPDATE users SET del_flg = 1 WHERE id % 50 = 0;
```

---

## 推奨インデックス

初期状態ではインデックスがありません。以下の追加を推奨：

```sql
-- コメント取得の高速化
CREATE INDEX idx_comments_post_id ON comments(post_id);

-- 投稿一覧の高速化
CREATE INDEX idx_posts_created_at ON posts(created_at DESC);
CREATE INDEX idx_posts_user_id ON posts(user_id);
```

---

## テストユーザー

| account_name | password | 備考 |
|--------------|----------|------|
| mary | marymary | 一般ユーザー |
