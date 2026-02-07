# PR #4: 画像ファイルシステム移行

- **ブランチ**: `feature/image-filesystem`
- **コミット**: `3ea343b`, `5fbab0b`, `5355971`
- **PR**: [#4](https://github.com/liofval/Iscogram/pull/4)

## 背景

初期実装では、画像データがMySQLの`posts`テーブルに`LONGBLOB`型で格納されていました。画像リクエストのたびに、PHPがMySQLから数MBのバイナリデータを取得し、HTTPレスポンスとして返却する構成です。

### なぜこれが問題なのか

1. **データ転送量**: 画像1枚あたり数百KB〜数MBのデータがMySQL → PHP間で転送される
2. **PHPメモリ消費**: 画像データをPHPのメモリに読み込む必要がある
3. **MySQL負荷**: BLOBの読み出しはディスクI/Oを伴い、他のクエリのパフォーマンスにも影響
4. **同時接続**: ベンチマーカーは並行してリクエストを送るため、上記の問題が増幅

初期ベンチマークで`fail=37`のうち大半が画像タイムアウトでした。

## 解決方針

**画像をファイルシステムに保存し、Nginxから直接配信する**

```
変更前: Client → Nginx → PHP-FPM → MySQL（BLOB読み出し）→ PHP-FPM → Nginx → Client
変更後: Client → Nginx → ファイルシステム → Client（PHPを経由しない）
```

## 実装の詳細

### 1. 画像保存先の定義

```php
const IMAGE_DIR = '/home/public/image';

function get_image_ext($mime) {
    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        default => ''
    };
}

function get_image_path($id, $mime) {
    $ext = get_image_ext($mime);
    return IMAGE_DIR . "/{$id}.{$ext}";
}
```

画像ファイル名は`{post_id}.{拡張子}`形式で、ユーザー入力を使用しないことでパストラバーサル攻撃を防いでいます。

### 2. 新規投稿時の画像保存

```php
// 変更前: DBにBLOBとして保存
$ps->execute([$me['id'], $mime, file_get_contents($_FILES['file']['tmp_name']), $params['body']]);

// 変更後: DBにはimgdata空で保存、ファイルシステムに画像を保存
$ps->execute([$me['id'], $mime, '', $params['body']]);  // imgdataは空
$image_path = get_image_path($pid, $mime);
move_uploaded_file($_FILES['file']['tmp_name'], $image_path);
```

### 3. 既存画像のエクスポート（バッチ処理）

初期データの10,000枚の画像をDBからファイルに書き出す必要があります。一括で全データを読み込むとPHPのメモリ制限（128MB）を超えるため、**100件ずつのバッチ処理**を実装しました。

```php
public function export_images_to_filesystem() {
    $db = $this->db();
    $batch_size = 100;

    for ($offset = 0; $offset < 10000; $offset += $batch_size) {
        $ps = $db->prepare('SELECT id, mime, imgdata FROM posts WHERE id > ? AND id <= ? ORDER BY id');
        $ps->execute([$offset, $offset + $batch_size]);

        while ($row = $ps->fetch(PDO::FETCH_ASSOC)) {
            $path = get_image_path($row['id'], $row['mime']);
            if (!file_exists($path)) {
                file_put_contents($path, $row['imgdata']);
            }
        }
        $ps->closeCursor();  // カーソルを明示的にクローズしてメモリ解放
    }
}
```

#### バッチ処理のポイント

| 方式 | メモリ使用量 | 問題 |
|------|-------------|------|
| 全件一括取得 | 数GB | PHPメモリ制限超過（Fatal Error） |
| 1件ずつ取得 | 最小 | クエリ数が10,000回で遅い |
| **100件バッチ** | **適度** | **メモリと速度のバランスが良い** |

### 4. 初期化処理の最適化

ベンチマーカーは`/initialize`エンドポイントを呼び出してデータをリセットします。初期化時に全画像を再エクスポートすると時間がかかるため、新規投稿（id > 10000）の画像ファイルのみを削除する方式にしました。

```php
public function db_initialize() {
    // ... データのリセット処理 ...

    // 新規投稿（id > 10000）の画像ファイルのみ削除
    $this->cleanup_new_images();  // 全画像エクスポートではなく、差分のみ処理
}

public function cleanup_new_images() {
    $files = glob(IMAGE_DIR . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            $basename = basename($file);
            if (preg_match('/^(\d+)\./', $basename, $matches)) {
                $id = (int)$matches[1];
                if ($id > 10000) {
                    unlink($file);
                }
            }
        }
    }
}
```

**考え方**: 初期データ（id 1〜10000）は事前にエクスポート済みで変わらないため、ベンチマーク中に追加された投稿（id > 10000）のファイルだけ削除すれば十分です。

### 5. Nginx設定

```nginx
location /image/ {
    root /public;
    expires 1d;
    add_header Cache-Control "public, immutable";
    try_files $uri @app;
}
```

- `root /public` : `/public/image/` ディレクトリからファイルを探す
- `expires 1d` : ブラウザキャッシュの有効期限を1日に設定
- `Cache-Control "public, immutable"` : CDNやプロキシキャッシュも有効化
- `try_files $uri @app` : ファイルが存在すればNginxが返し、なければPHPにフォールバック

### 6. PHPフォールバック

ファイルシステムに画像がない場合（マイグレーション中の互換性確保）、PHPがDBから画像を取得して返します：

```php
$app->get('/image/{id}.{ext}', function (...) {
    // ファイルシステムを優先してチェック（高速）
    $image_path = IMAGE_DIR . "/{$args['id']}.{$ext}";
    if (file_exists($image_path)) {
        $response->getBody()->write(file_get_contents($image_path));
        return $response->withHeader('Content-Type', $mime);
    }

    // ファイルがなければDBから取得（フォールバック）
    $post = $this->get('helper')->fetch_first('SELECT mime, imgdata FROM posts WHERE id = ?', $args['id']);
    // ...
});
```

## 遭遇したエラーと対処

### メモリ枯渇エラー

```
Fatal error: Allowed memory size of 134217728 bytes exhausted
```

**原因**: 全画像を一括でSELECTしようとして、PHPのメモリ制限（128MB）を超過。

**対処**: バッチ処理（100件単位）に変更し、各バッチ後にカーソルをクローズしてメモリを解放。

## 効果

```
変更前: score=0  success=172  fail=37  ← 画像タイムアウト多発
変更後: score=0  success=206  fail=64  ← 画像タイムアウト解消
```

successが増加し、画像関連のタイムアウトが解消されました。failが増えているのは、画像配信が高速化されたことでベンチマーカーがより多くのリクエストを送るようになり、他のボトルネック（N+1クエリ等）が顕在化したためです。

## 学んだこと

1. **バイナリデータはDBに格納しない**: 画像・動画などのバイナリデータはファイルシステムやオブジェクトストレージに保存し、DBにはメタデータ（パス、MIME型）のみ格納するのがベストプラクティス
2. **Nginxの直接配信は強力**: 静的ファイルはNginxから直接返すことで、PHP-FPMのプロセスを消費せず、桁違いのスループットが得られる
3. **バッチ処理でメモリ管理**: 大量データの処理では、一括ではなくバッチ処理でメモリ使用量をコントロールする
4. **フォールバック機構の重要性**: 移行中でも既存機能が動作し続けるよう、フォールバック機構を設ける
