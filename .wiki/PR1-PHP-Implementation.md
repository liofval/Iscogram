# PR #1: PHP実装への切り替え

- **ブランチ**: `feature/php-implementation`
- **コミット**: `3f6941c`
- **PR**: [#1](https://github.com/liofval/Iscogram/pull/1)

## 背景

private-isuはRuby, Go, PHP, Python, Node.jsの複数言語で実装が用意されていますが、デフォルトはRuby実装が選択されています。PR TIMESのインターン課題では**PHPの使用が必須**のため、最初の作業としてPHP実装への切り替えを行いました。

## 変更内容

### 変更ファイル

| ファイル | 変更内容 |
|---------|---------|
| `compose.yml` | appサービスのビルドコンテキストを`ruby/`→`php/`に変更 |
| `etc/nginx/conf.d/default.conf` | `default.conf.org`にリネーム（無効化） |
| `etc/nginx/conf.d/php.conf.org` | `php.conf`にリネーム（有効化） |

### compose.ymlの変更

```yaml
# 変更前
app:
  build: ruby/

# 変更後
app:
  build: php/
```

### Nginx設定の切り替え

Rubyの場合はUnicornへのプロキシ（`default.conf`）、PHPの場合はFastCGIプロキシ（`php.conf`）が必要です。ファイルのリネームで設定を切り替えました。

```nginx
# php.conf（有効化）
location / {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/html/index.php;
    fastcgi_pass app:9000;
}
```

## PHP実装の技術構成

| 項目 | 内容 |
|------|------|
| 言語 | PHP 8.3 |
| 実行環境 | PHP-FPM（FastCGI Process Manager） |
| フレームワーク | Slim 4（マイクロフレームワーク） |
| テンプレート | PHP Renderer（PHPファイルでHTML生成） |
| DI | PHP-DI（依存性注入コンテナ） |

### PHP-FPMとは

**PHP-FPM**（FastCGI Process Manager）は、PHPをWebサーバーと連携させるためのプロセスマネージャです。

- WebサーバーからFastCGIプロトコルでリクエストを受け取る
- 複数のワーカープロセスをプールとして管理
- リクエストを空いているワーカーに割り当て並行処理
- mod_phpのようにWebサーバーに組み込むのではなく、独立したプロセスとして動作

### Slim 4フレームワーク

Slim 4は軽量なPHPマイクロフレームワークで、ルーティングとミドルウェアに特化しています：

```php
$app->get('/', function (Request $request, Response $response) {
    // トップページの処理
});

$app->post('/comment', function (Request $request, Response $response) {
    // コメント投稿の処理
});
```

ISUCONではフレームワークの処理オーバーヘッドを最小限に抑えることが重要であり、Slim 4のような軽量フレームワークは適切な選択です。

## ベンチマーク結果

```json
{"pass":true,"score":0,"success":172,"fail":37}
```

初期状態ではスコアは0でした。fail=37の主な原因は画像リクエストのタイムアウトです。
