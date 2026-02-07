# Architecture - システムアーキテクチャ

## システム全体構成

```
┌──────────────────────────────────────────────────────────┐
│                     Docker Compose                        │
│                                                          │
│  ┌─────────┐    ┌──────────┐    ┌─────────┐             │
│  │  Nginx   │───→│ PHP-FPM  │───→│  MySQL  │             │
│  │  :80     │    │ (app)    │    │  :3306  │             │
│  │          │    │  :9000   │    │         │             │
│  └────┬─────┘    └────┬─────┘    └─────────┘             │
│       │               │                                   │
│       │               │         ┌───────────┐            │
│       │               └────────→│ Memcached │            │
│       │                         │  :11211   │            │
│       │                         └───────────┘            │
│       │                                                   │
│       └──→ /home/public/image/ (静的画像ファイル)         │
│                                                          │
└──────────────────────────────────────────────────────────┘
```

## リクエストフロー

### 1. 通常のページリクエスト（例: GET /）

```
Client
  ↓ HTTP Request
Nginx (:80)
  ↓ FastCGI (TCP :9000)
PHP-FPM
  ↓ SQL Query
MySQL (:3306)
  ↓ Result
PHP-FPM
  ↓ HTML Response
Nginx
  ↓ HTTP Response
Client
```

1. クライアントがNginx（ポート80）にHTTPリクエストを送信
2. Nginxは`php.conf`の設定に従い、FastCGIプロトコルでPHP-FPMにリクエストを転送
3. PHP-FPMが`index.php`を実行し、Slim 4フレームワークがルーティング
4. 必要に応じてMySQLにクエリを発行、Memcachedからセッションを取得
5. HTMLを生成してNginx経由でクライアントに返却

### 2. 画像リクエスト（最適化後: GET /image/123.jpg）

```
Client
  ↓ GET /image/123.jpg
Nginx (:80)
  ↓ try_files でファイルを探索
/home/public/image/123.jpg が存在？
  ├─ YES → Nginx が直接ファイルを返却（PHPを経由しない）
  └─ NO  → @app にフォールバック
              ↓ FastCGI
           PHP-FPM → MySQL → 画像バイナリ返却
```

**最適化前**は、すべての画像リクエストがPHP → MySQLを経由していました。10,000枚以上の画像を毎回DBから読み出すのは非常に遅く、タイムアウトの原因でした。

## 各コンポーネントの役割

### Nginx

**役割**: リバースプロキシ + 静的ファイル配信

```nginx
# /etc/nginx/conf.d/php.conf

# 静的画像ファイルの直接配信
location /image/ {
    root /public;
    expires 1d;
    add_header Cache-Control "public, immutable";
    try_files $uri @app;   # ファイルがあればNginxが返す、なければPHPへ
}

# PHP-FPMへのプロキシ
location @app {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/html/index.php;
    fastcgi_pass app:9000;
}
```

**ポイント**:
- `try_files $uri @app` により、ファイルシステムに画像が存在すればNginxが直接返却
- 存在しない場合のみPHP-FPMにフォールバック（移行中の互換性確保）
- `expires 1d` でブラウザキャッシュを有効化

### PHP-FPM (Slim 4)

**役割**: アプリケーションロジック

- **ルーティング**: Slim 4がURL→ハンドラのマッピングを管理
- **テンプレート**: PHPRendererでHTML生成
- **セッション**: Memcachedバックエンドのセッション管理
- **画像処理**: アップロード時にファイルシステムに保存

### MySQL

**役割**: データの永続化

**テーブル構成**:

| テーブル | 役割 | 主要カラム |
|---------|------|-----------|
| `users` | ユーザー情報 | id, account_name, passhash, authority, del_flg |
| `posts` | 投稿データ | id, user_id, mime, imgdata, body, created_at |
| `comments` | コメント | id, post_id, user_id, comment, created_at |

**追加インデックス**（PR #5で追加）:

```sql
CREATE INDEX idx_comments_post_id ON comments(post_id);
CREATE INDEX idx_comments_user_id ON comments(user_id);
CREATE INDEX idx_posts_created_at ON posts(created_at DESC);
CREATE INDEX idx_posts_user_id ON posts(user_id);
```

### Memcached

**役割**: セッション管理

PHPの`session.save_handler`を`memcached`に設定し、セッションデータをメモリに格納。ファイルベースのセッションより高速です。

```php
ini_set('session.save_handler', 'memcached');
ini_set('session.save_path', '127.0.0.1:11211');
```

## Docker Compose設定

```yaml
services:
  app:      # PHP-FPM（アプリケーション）
  nginx:    # リバースプロキシ
  mysql:    # データベース
  memcached: # セッションストア
```

各サービスは同一のDockerネットワーク内に配置され、サービス名（`app`, `mysql`, `memcached`）でDNS解決できます。

## ボトルネックの発生箇所

初期状態で特に問題が大きかった箇所：

```
1. MySQL → PHP: 画像BLOBの転送（数MB/リクエスト）     ← PR#4で解決
2. PHP内: N+1クエリ（数十〜数百回のSQLクエリ/リクエスト） ← PR#5で解決
3. PHP → Shell: digest関数のプロセス生成（6プロセス/認証） ← PR#6で解決
```
