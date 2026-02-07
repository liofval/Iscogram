# Performance Tuning - パフォーマンス改善戦略

## 概要

private-isuは意図的にパフォーマンス問題を含んでいます。

**主な問題点:**
1. N+1クエリ問題
2. 画像のDB格納
3. インデックス不足
4. 非効率なセッション管理

---

## 改善の優先順位

| 優先度 | 改善項目 | 期待効果 |
|--------|----------|----------|
| 1 | 画像のファイルシステム移行 | 大 |
| 2 | N+1クエリの解消 | 大 |
| 3 | データベースインデックス追加 | 中〜大 |
| 4 | Nginxでの静的ファイル配信 | 中 |
| 5 | コネクションプーリング | 中 |
| 6 | キャッシュ導入 | 中 |

---

## 1. 画像配信の最適化

### 問題点

画像が`posts.imgdata`にBLOBとして格納されている。

### 解決策

画像をファイルシステムに移行し、Nginxから直接配信。

**手順:**

1. 既存画像をファイルに書き出す
2. 投稿処理を修正（画像をファイルに保存）
3. Nginx設定を追加

```nginx
location /image/ {
    root /public;
    expires 1d;
}
```

---

## 2. N+1クエリの解消

### 問題点

タイムライン表示で投稿ごとに複数クエリが発行される。

### 解決策

JOINを使用して一括取得。

```sql
SELECT p.*, u.account_name
FROM posts p
JOIN users u ON p.user_id = u.id
WHERE u.del_flg = 0
ORDER BY p.created_at DESC
LIMIT 20;
```

---

## 3. インデックス追加

```sql
-- コメント取得の高速化
CREATE INDEX idx_comments_post_id ON comments(post_id);

-- 投稿一覧の高速化
CREATE INDEX idx_posts_created_at ON posts(created_at DESC);
CREATE INDEX idx_posts_user_id ON posts(user_id);
```

---

## 4. Nginxの最適化

### 静的ファイルの直接配信

```nginx
location ~ ^/(css|js|img|favicon\.ico) {
    root /public;
    expires 1d;
}
```

### gzip圧縮

```nginx
gzip on;
gzip_types text/plain text/css application/json application/javascript;
```

---

## 5. コネクションプーリング

### Go

```go
db.SetMaxOpenConns(25)
db.SetMaxIdleConns(25)
db.SetConnMaxLifetime(5 * time.Minute)
```

### PHP

PDOの永続接続を使用：

```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_PERSISTENT => true
]);
```

---

## 6. キャッシュ戦略

### ユーザー情報のキャッシュ

```php
$user = $memcached->get("user:$user_id");
if (!$user) {
    $user = $db->query("SELECT * FROM users WHERE id = ?", $user_id);
    $memcached->set("user:$user_id", $user, 300);
}
```

---

## プロファイリング

### MySQLスロークエリログ

```sql
SET GLOBAL slow_query_log = 1;
SET GLOBAL long_query_time = 0.1;
```

### アプリケーションログ

```bash
docker compose logs app
```

---

## 参考スコア例

| 状態 | スコア目安 |
|------|-----------|
| 初期状態 | ~1,500 |
| インデックス追加 | ~3,000 |
| N+1解消 | ~8,000 |
| 画像ファイル化 | ~15,000 |
| 総合最適化 | ~30,000+ |
