# Performance Tuning Guide - ISUCONパフォーマンスチューニングガイド

ISUCONにおけるパフォーマンスチューニングの考え方と、一般的な改善手法をまとめます。

## チューニングの基本原則

### 1. 推測するな、計測せよ (Measure, Don't Guess)

パフォーマンスの問題は直感と異なる場所に存在することが多いです。必ずベンチマーク結果やログを基に、ボトルネックを特定してから改善に着手してください。

```
ベンチマーク実行
    ↓
エラーメッセージ・スコアを確認
    ↓
ボトルネック箇所を特定
    ↓
仮説を立てる
    ↓
改善を実装
    ↓
再度ベンチマーク実行（効果検証）
    ↓
次のボトルネックへ（繰り返し）
```

### 2. 最大のボトルネックから対処する

100ms かかる処理を50ms に改善するより、10秒かかる処理を100ms にする方が効果は大きいです。

### 3. 変更は最小限に

ISUCONでは時間が限られています。既存コードへの理解を深め、最小限の変更で最大の効果を得ることが重要です。

## ボトルネックの分類と対処法

### レイヤー別の影響度

```
影響度: 大 ←―――――――――――――――――――→ 小

[アーキテクチャ] > [データベース] > [アプリケーション] > [OS/ミドルウェア設定]
    構造変更          クエリ最適化      コード最適化         カーネルパラメータ
    キャッシュ導入    インデックス追加  アルゴリズム改善     ワーカー数調整
```

## データベースの最適化

### インデックスの追加

最も効果が大きく、リスクが低い最適化の一つです。

**いつインデックスを追加すべきか**:
- `WHERE`句で頻繁に使用されるカラム
- `ORDER BY`で使用されるカラム
- `JOIN`の結合条件に使用されるカラム
- `GROUP BY`で使用されるカラム

```sql
-- 効果的なインデックスの例
CREATE INDEX idx_comments_post_id ON comments(post_id);  -- WHERE post_id = ? の高速化
CREATE INDEX idx_posts_created_at ON posts(created_at DESC);  -- ORDER BY created_at DESC の高速化
```

**複合インデックス**:
```sql
-- created_atでソートしつつuser_idでフィルタする場合
CREATE INDEX idx_posts_user_created ON posts(user_id, created_at DESC);
```

複合インデックスでは「左端から順に」使用されます。`(user_id, created_at)`のインデックスは `WHERE user_id = ?` と `WHERE user_id = ? ORDER BY created_at` には効きますが、`WHERE created_at > ?` 単独には効きません。

### N+1クエリの解消

N+1問題は最も一般的なパフォーマンス問題です。

| パターン | 対処法 |
|---------|--------|
| ループ内でSELECT | IN句でバッチ取得 |
| 関連テーブルの個別取得 | JOINで結合 |
| 件数のカウント | GROUP BY + COUNTで一括 |
| 上位N件の取得 | ウィンドウ関数（ROW_NUMBER） |

### 不要なデータの取得を避ける

```sql
-- 悪い例: 全カラム・全行を取得
SELECT * FROM posts ORDER BY created_at DESC;

-- 良い例: 必要なカラムのみ、LIMITで件数制限
SELECT id, user_id, body, mime, created_at
FROM posts
ORDER BY created_at DESC
LIMIT 20;
```

### EXPLAINの活用

クエリの実行計画を確認して、インデックスが使われているか確認できます。

```sql
EXPLAIN SELECT * FROM comments WHERE post_id = 1;
```

| 注目すべきフィールド | 理想的な値 | 問題のある値 |
|---|---|---|
| type | const, eq_ref, ref | ALL（フルスキャン） |
| key | インデックス名 | NULL（インデックス未使用） |
| rows | 少数 | テーブルの全行数 |
| Extra | Using index | Using filesort, Using temporary |

## アプリケーションの最適化

### 外部プロセス呼び出しの回避

```php
// 遅い: 毎回プロセスを生成
return trim(`printf "%s" {$src} | openssl dgst -sha512 | sed 's/^.*= //'`);

// 速い: PHPの組み込み関数を使用
return hash('sha512', $src);
```

**一般的な置き換え**:

| シェルコマンド | PHP関数 |
|---|---|
| `openssl dgst` | `hash()` |
| `convert` (ImageMagick) | `imagick` 拡張 |
| `curl` | `file_get_contents()` or Guzzle |
| `jq` | `json_decode()` |

### PHPでのメモリ管理

大量データを扱う場合、メモリ使用量を意識する必要があります。

```php
// 悪い例: 全結果をメモリに展開
$results = $ps->fetchAll(PDO::FETCH_ASSOC);  // 10,000行を一度に

// 良い例: バッチ処理
for ($offset = 0; $offset < $total; $offset += 100) {
    $ps->execute([$offset, $offset + 100]);
    while ($row = $ps->fetch(PDO::FETCH_ASSOC)) {
        // 1行ずつ処理
    }
    $ps->closeCursor();  // メモリ解放
}
```

## Nginx/ミドルウェアの最適化

### 静的ファイルの直接配信

Nginxは静的ファイルの配信に特化しており、アプリケーションサーバーよりも桁違いに高速です。

```nginx
# 画像をNginxから直接配信
location /image/ {
    root /public;
    expires 1d;
    add_header Cache-Control "public, immutable";
    try_files $uri @app;  # なければアプリにフォールバック
}
```

### キャッシュヘッダの設定

```nginx
# ブラウザキャッシュ
expires 1d;

# CDN/プロキシキャッシュ
add_header Cache-Control "public, immutable";
```

## 改善の優先度チェックリスト

ISUCONで限られた時間内に最大の効果を得るための優先度：

### 高優先度（まず最初に確認）

- [ ] 画像やバイナリデータがDBに格納されていないか
- [ ] N+1クエリが発生していないか
- [ ] 必要なインデックスが張られているか
- [ ] 不要なカラムやLIMITなしの全件取得がないか

### 中優先度

- [ ] 外部プロセス呼び出しがないか
- [ ] Nginxで静的ファイルを直接配信しているか
- [ ] キャッシュが活用できないか
- [ ] 重複するクエリがないか

### 低優先度（時間があれば）

- [ ] PHP-FPMのワーカー数は適切か
- [ ] MySQLのバッファサイズは適切か
- [ ] カーネルパラメータの調整
- [ ] HTTP/2の有効化

## 参考資料

- [ISUCON公式サイト](https://isucon.net)
- [MySQL :: MySQL 8.4 Reference Manual :: 10 Optimization](https://dev.mysql.com/doc/refman/8.4/en/optimization.html)
- [Nginx Documentation](https://nginx.org/en/docs/)
- [PHP: Performance Considerations](https://www.php.net/manual/en/internals2.ze1.zendapi.php)
