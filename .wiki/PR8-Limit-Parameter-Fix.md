# PR #8: LIMITパラメータのバインドバグ修正

- **ブランチ**: `fix/limit-parameter-binding`
- **コミット**: `6d06c9d`
- **PR**: [#8](https://github.com/liofval/Iscogram/pull/8)

## 背景

PR #5（N+1クエリ解消）で導入した`LIMIT ?`のプレースホルダバインドが、MySQLの構文エラーを引き起こしていました。

```
Fatal error: Uncaught PDOException: SQLSTATE[42000]: Syntax error or access violation:
1064 You have an error in your SQL syntax; ... near ''20'' at line 6
```

## 原因

### PDOのパラメータバインド

PDOの`execute()`メソッドに配列でパラメータを渡すと、**全ての値が文字列（`PDO::PARAM_STR`）としてバインド**されます。

```php
$ps = $db->prepare('SELECT * FROM posts LIMIT ?');
$ps->execute([20]);
// 実際に生成されるSQL: SELECT * FROM posts LIMIT '20'
//                                              ^^^^^ 文字列！
```

MySQLの`LIMIT`句は**整数リテラルのみ**を受け付けるため、文字列`'20'`は構文エラーになります。

### 正しいバインド方法

PDOで整数としてバインドするには`bindValue()`を使う方法もあります：

```php
$ps = $db->prepare('SELECT * FROM posts LIMIT :limit');
$ps->bindValue(':limit', 20, PDO::PARAM_INT);
$ps->execute();
```

ただし今回は`POSTS_PER_PAGE`がアプリケーション定数（ユーザー入力ではない）のため、SQL文字列に直接埋め込む方がシンプルです。

## 修正内容

```php
// 変更前（エラー）
$ps = $db->prepare('... LIMIT ?');
$ps->execute([POSTS_PER_PAGE]);

// 変更後（正常）
$ps = $db->prepare('... LIMIT ' . POSTS_PER_PAGE);
$ps->execute();
```

対象の3エンドポイント：
- `GET /` - トップページ
- `GET /posts` - 追加投稿読み込み
- `GET /@{account_name}` - ユーザープロフィール

## セキュリティ上の考慮

`POSTS_PER_PAGE`は`const POSTS_PER_PAGE = 20;`で定義されたアプリケーション定数であり、ユーザー入力ではないため、SQLインジェクションのリスクはありません。ユーザー入力をSQL文字列に直接埋め込むことは避けるべきですが、定数の埋め込みは安全です。

## 効果

```
変更前: pass=false score=0     success=14     fail=3    ← ページ表示エラー
変更後: pass=true  score=29742 success=28625  fail=0    ← 完全にパス
```

このバグ修正により、PR #5とPR #6の最適化効果が正しく発揮され、スコアが0から**29,742**に大幅改善しました。

## 学んだこと

1. **PDOのexecute()は全て文字列バインド**: 整数が必要な箇所（LIMIT, OFFSET等）では`bindValue()`で型指定するか、定数ならSQL文字列に直接埋め込む
2. **変更後のテスト必須**: パフォーマンス最適化でも機能が壊れないことの確認が重要
3. **エラーログの確認**: ベンチマーカーの出力だけでなく、PHPのエラーログ（`curl`での直接確認）も重要な情報源
