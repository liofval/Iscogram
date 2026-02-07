# PR #5: N+1クエリ問題の解消とクエリ最適化

- **ブランチ**: `feature/fix-n-plus-1-queries`
- **コミット**: `95e5fad`
- **PR**: [#5](https://github.com/liofval/Iscogram/pull/5)

## 背景

画像タイムアウトを解消した後、ベンチマーク結果に新たなタイムアウトが現れました：

```
"リクエストがタイムアウトしました (GET /)"
"リクエストがタイムアウトしました (GET /posts)"
"リクエストがタイムアウトしました (GET /@{username})"
```

これらのエンドポイントに共通するのは、`make_posts`関数を呼び出していることです。この関数に深刻なN+1クエリ問題がありました。

## N+1クエリ問題とは

N+1クエリ問題は、1回のメインクエリの結果に対して、各行ごとに追加のクエリ（N回）を発行してしまうパフォーマンスアンチパターンです。

### 具体例：make_posts関数の場合

```php
// メインクエリ: 投稿一覧を取得（1回）
$results = 'SELECT * FROM posts ORDER BY created_at DESC';

// N+1のループ
foreach ($results as $post) {
    // クエリ1: コメント数を取得（N回）
    $post['comment_count'] = 'SELECT COUNT(*) FROM comments WHERE post_id = ?';

    // クエリ2: コメントを取得（N回）
    $comments = 'SELECT * FROM comments WHERE post_id = ? LIMIT 3';

    foreach ($comments as $comment) {
        // クエリ3: コメントユーザーを取得（N×M回）← ネストしたN+1
        $comment['user'] = 'SELECT * FROM users WHERE id = ?';
    }

    // クエリ4: 投稿者を取得（N回）
    $post['user'] = 'SELECT * FROM users WHERE id = ?';
}
```

### クエリ数の計算

20件の投稿を表示する場合（各投稿に3件のコメント）：

| クエリの種類 | 回数 | 計算 |
|---|---|---|
| 投稿一覧 | 1回 | 1 |
| コメント数 | 20回 | N |
| コメント取得 | 20回 | N |
| コメントユーザー | 60回 | N × M (20 × 3) |
| 投稿者 | 20回 | N |
| **合計** | **121回** | 1 + 4N + NM |

1ページ表示するだけで**121回**のSQLクエリが発行されています。さらに、初期実装では全投稿（10,000件以上）をフェッチしてからPHPでフィルタリングしていたため、実際にはさらに多くのクエリが発行されていました。

## 解決方針

3つの最適化を実施しました：

1. **N+1クエリのバッチ化**: 個別クエリ → IN句で一括取得
2. **インデックスの追加**: フルテーブルスキャン → インデックス検索
3. **クエリの効率化**: 全件取得 → JOIN + LIMITで必要分のみ取得

## 実装の詳細

### 1. make_posts関数のバッチ処理化

#### 変更前（N+1問題）

```php
public function make_posts(array $results, $options = []) {
    $posts = [];
    foreach ($results as $post) {
        // N+1: 各投稿のコメント数を個別に取得
        $post['comment_count'] = $this->fetch_first(
            'SELECT COUNT(*) AS count FROM comments WHERE post_id = ?',
            $post['id']
        )['count'];

        // N+1: 各投稿のコメントを個別に取得
        $ps = $this->db()->prepare('SELECT * FROM comments WHERE post_id = ? ORDER BY created_at DESC LIMIT 3');
        $ps->execute([$post['id']]);
        $comments = $ps->fetchAll(PDO::FETCH_ASSOC);

        // ネストしたN+1: 各コメントのユーザーを個別に取得
        foreach ($comments as &$comment) {
            $comment['user'] = $this->fetch_first('SELECT * FROM users WHERE id = ?', $comment['user_id']);
        }

        // N+1: 各投稿の投稿者を個別に取得
        $post['user'] = $this->fetch_first('SELECT * FROM users WHERE id = ?', $post['user_id']);

        if ($post['user']['del_flg'] == 0) {
            $posts[] = $post;
        }
        if (count($posts) >= POSTS_PER_PAGE) break;
    }
    return $posts;
}
```

#### 変更後（バッチ処理）

```php
public function make_posts(array $results, $options = []) {
    if (empty($results)) return [];

    // 投稿IDとユーザーIDを収集
    $post_ids = array_column($results, 'id');
    $post_user_ids = array_unique(array_column($results, 'user_id'));

    // 1. 投稿者を一括取得（1クエリ）
    $users_by_id = $this->fetch_users_by_ids($post_user_ids);

    // del_flg=0のユーザーの投稿のみフィルタ
    $filtered_results = [];
    foreach ($results as $post) {
        $user = $users_by_id[$post['user_id']] ?? null;
        if ($user && $user['del_flg'] == 0) {
            $filtered_results[] = $post;
            if (count($filtered_results) >= POSTS_PER_PAGE) break;
        }
    }

    $post_ids = array_column($filtered_results, 'id');

    // 2. コメント数を一括取得（1クエリ）
    $comment_counts = $this->fetch_comment_counts($post_ids);

    // 3. コメントを一括取得（1クエリ）
    $comments_by_post = $this->fetch_comments_for_posts($post_ids, $all_comments);

    // 4. コメントユーザーを一括取得（1クエリ）
    $comment_user_ids = /* 全コメントからuser_idを収集 */;
    $comment_users_by_id = $this->fetch_users_by_ids($comment_user_ids);

    // 5. データを組み立て（クエリなし、メモリ内処理のみ）
    foreach ($filtered_results as $post) {
        $post['comment_count'] = $comment_counts[$post['id']] ?? 0;
        $comments = $comments_by_post[$post['id']] ?? [];
        foreach ($comments as &$comment) {
            $comment['user'] = $comment_users_by_id[$comment['user_id']] ?? null;
        }
        // ...
    }
}
```

#### クエリ数の比較

| 処理 | 変更前 | 変更後 |
|------|--------|--------|
| 投稿者取得 | N回 | **1回** |
| コメント数 | N回 | **1回** |
| コメント取得 | N回 | **1回** |
| コメントユーザー | N×M回 | **1回** |
| **合計** | 121回 | **4回** |

### 2. ヘルパーメソッドの解説

#### fetch_users_by_ids - ユーザー一括取得

```php
private function fetch_users_by_ids(array $user_ids) {
    $placeholder = implode(',', array_fill(0, count($user_ids), '?'));
    $ps = $this->db()->prepare(
        "SELECT * FROM users WHERE id IN ({$placeholder})"
    );
    $ps->execute(array_values($user_ids));

    // IDをキーとした連想配列に変換（O(1)ルックアップ）
    $users_by_id = [];
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $users_by_id[$user['id']] = $user;
    }
    return $users_by_id;
}
```

**ポイント**:
- `IN (?,...,?)` で複数IDを1クエリで取得
- 結果をIDキーの連想配列に変換することで、後続の処理でO(1)のルックアップが可能

#### fetch_comment_counts - コメント数一括取得

```php
private function fetch_comment_counts(array $post_ids) {
    $placeholder = implode(',', array_fill(0, count($post_ids), '?'));
    $ps = $this->db()->prepare(
        "SELECT post_id, COUNT(*) AS count FROM comments
         WHERE post_id IN ({$placeholder}) GROUP BY post_id"
    );
    $ps->execute(array_values($post_ids));
    // ...
}
```

**ポイント**:
- `GROUP BY post_id` で投稿ごとのコメント数を1クエリで取得
- 個別のCOUNTクエリN回が、1回のGROUP BYクエリに集約

#### fetch_comments_for_posts - コメント一括取得

```php
private function fetch_comments_for_posts(array $post_ids, bool $all_comments) {
    if ($all_comments) {
        // 全コメント取得
        $sql = "SELECT * FROM comments WHERE post_id IN ({$placeholder})
                ORDER BY post_id, created_at DESC";
    } else {
        // 各投稿につき最新3件をウィンドウ関数で取得
        $sql = "
            SELECT * FROM (
                SELECT *, ROW_NUMBER() OVER (
                    PARTITION BY post_id ORDER BY created_at DESC
                ) AS rn
                FROM comments
                WHERE post_id IN ({$placeholder})
            ) ranked
            WHERE rn <= 3
            ORDER BY post_id, created_at DESC
        ";
    }
    // ...
}
```

**ポイント**:
- `ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY created_at DESC)` は、投稿ごとにコメントを最新順で番号付け
- `WHERE rn <= 3` で各投稿から最新3件のみを取得
- これにより、個別の `SELECT ... LIMIT 3` を N回実行する代わりに、1回のクエリで全投稿のコメントを取得

### 3. ウィンドウ関数の解説

`ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY created_at DESC)` を分解すると：

```sql
ROW_NUMBER()    -- 連番を振る関数
OVER (          -- ウィンドウ（範囲）の指定
    PARTITION BY post_id        -- post_idごとにグループ化
    ORDER BY created_at DESC    -- 各グループ内で新しい順にソート
)
```

具体例：

| post_id | comment | created_at | rn |
|---------|---------|------------|----|
| 1 | コメントC | 2026-01-03 | 1 |
| 1 | コメントB | 2026-01-02 | 2 |
| 1 | コメントA | 2026-01-01 | 3 |
| 1 | コメント0 | 2025-12-31 | 4 ← WHERE rn <= 3 で除外 |
| 2 | コメントZ | 2026-01-05 | 1 ← post_id=2で番号リセット |
| 2 | コメントY | 2026-01-04 | 2 |

### 4. データベースインデックスの追加

```php
public function create_indexes() {
    $indexes = [
        ['comments', 'idx_comments_post_id', 'post_id'],
        ['posts', 'idx_posts_created_at', 'created_at DESC'],
        ['posts', 'idx_posts_user_id', 'user_id'],
        ['comments', 'idx_comments_user_id', 'user_id'],
    ];

    foreach ($indexes as [$table, $index_name, $columns]) {
        try {
            $db->exec("CREATE INDEX {$index_name} ON {$table} ({$columns})");
        } catch (PDOException $e) {
            // 既に存在する場合は無視
        }
    }
}
```

#### インデックスの効果

| インデックス | 対応するクエリ | 効果 |
|---|---|---|
| `idx_comments_post_id` | `WHERE post_id IN (...)` | コメント検索がフルスキャン → インデックスルックアップに |
| `idx_posts_created_at` | `ORDER BY created_at DESC` | ソートがファイルソート → インデックスソートに |
| `idx_posts_user_id` | `WHERE user_id = ?` | ユーザーの投稿検索が高速化 |
| `idx_comments_user_id` | `WHERE user_id = ?` | ユーザーのコメント検索が高速化 |

**インデックスなしの場合**: MySQLは全行を読み込んで条件に合う行を探す（フルテーブルスキャン）。comments テーブルに100,000行あれば、毎回100,000行をスキャン。

**インデックスありの場合**: B+Tree構造で目的の行を直接参照。100,000行でも数回のディスクアクセスで完了。

### 5. GET / と GET /posts のクエリ最適化

#### 変更前

```php
// 全投稿を取得（10,000件以上）
$ps = $db->prepare('SELECT id, user_id, body, mime, created_at FROM posts ORDER BY created_at DESC');
$ps->execute();
$results = $ps->fetchAll(PDO::FETCH_ASSOC);  // 全件メモリに展開
$posts = $this->get('helper')->make_posts($results);  // PHPでフィルタリング
```

**問題**: 20件しか表示しないのに10,000件以上をDBから取得し、PHPメモリに展開している。

#### 変更後

```php
// JOINで有効ユーザーの投稿のみ取得、LIMITで20件に制限
$ps = $db->prepare('
    SELECT p.id, p.user_id, p.body, p.mime, p.created_at
    FROM posts p
    JOIN users u ON p.user_id = u.id
    WHERE u.del_flg = 0
    ORDER BY p.created_at DESC
    LIMIT ?
');
$ps->execute([POSTS_PER_PAGE]);  // 20件のみ取得
```

**改善点**:
- `JOIN users` で削除済みユーザーの投稿を事前にフィルタ（PHP側でのフィルタが不要に）
- `LIMIT 20` で必要な件数のみ取得（転送データ量を削減）

### 6. ユーザープロフィールページの最適化

#### 変更前

```php
// 全投稿IDを取得
$ps = $db->prepare('SELECT id FROM posts WHERE user_id = ?');
$ps->execute([$user['id']]);
$post_ids = array_column($ps->fetchAll(PDO::FETCH_ASSOC), 'id');
$post_count = count($post_ids);

// post_idsを展開してIN句で検索
$placeholder = implode(',', array_fill(0, count($post_ids), '?'));
$commented_count = $this->get('helper')->fetch_first(
    "SELECT COUNT(*) AS count FROM comments WHERE post_id IN ({$placeholder})",
    ...$post_ids
)['count'];
```

**問題**: 投稿が多いユーザーの場合、巨大なIN句が生成される。

#### 変更後

```php
// COUNTを直接SQLで計算
$post_count = $this->get('helper')->fetch_first(
    'SELECT COUNT(*) AS count FROM posts WHERE user_id = ?',
    $user['id']
)['count'];

// サブクエリで効率化
$commented_count = $this->get('helper')->fetch_first('
    SELECT COUNT(*) AS count FROM comments
    WHERE post_id IN (SELECT id FROM posts WHERE user_id = ?)
', $user['id'])['count'];
```

**改善点**:
- PHPで`count()`する代わりにSQLの`COUNT(*)`を使用
- IN句にIDリストを展開する代わりにサブクエリを使用（MySQLの最適化器がセミジョインに変換）

## 効果

```
変更前: score=0  success=206  fail=64  ← N+1でタイムアウト多発
変更後: score=0  success=422  fail=26  ← クエリ最適化でタイムアウト大幅減少
```

成功リクエスト数が約2倍に増加し、失敗数が半分以下に減少しました。

## 学んだこと

1. **N+1クエリは致命的**: ループ内でのDB問い合わせは、データ量に比例してクエリ数が爆発する
2. **IN句によるバッチ処理が効果的**: 個別クエリをIN句で集約することで、クエリ数を大幅に削減できる
3. **ウィンドウ関数は強力**: `ROW_NUMBER() OVER (PARTITION BY ...)`で、グループごとの上位N件を1クエリで取得できる
4. **インデックスは基本**: WHERE句やORDER BY句で使用するカラムにはインデックスを張る
5. **必要なデータだけ取得**: `LIMIT`やJOINで事前にフィルタリングし、不要なデータ転送を避ける
