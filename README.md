# Private-ISU パフォーマンス改善

PR TIMES 学生インターン課題として、[private-isu](https://github.com/catatsuy/private-isu) のパフォーマンス改善に取り組みました。

## スコア推移

| # | 改善内容 | スコア | 成功 | 失敗 | PR |
|---|----------|--------|------|------|----|
| 0 | 初期状態（PHP実装） | 0 | 172 | 37 | [#1](https://github.com/liofval/Iscogram/pull/1) |
| 1 | 画像ファイルシステム移行 | 0 | 206 | 64 | [#4](https://github.com/liofval/Iscogram/pull/4) |
| 2 | N+1クエリ解消 + クエリ最適化 | 0 | 422 | 26 | [#5](https://github.com/liofval/Iscogram/pull/5) |
| 3 | digest関数のPHP化 + LIMITバグ修正 | **29,742** | **28,625** | **0** | [#6](https://github.com/liofval/Iscogram/pull/6), [#8](https://github.com/liofval/Iscogram/pull/8) |

## 使用言語

**PHP** （PR TIMES社内で利用されている言語のため）

- フレームワーク: Slim 4
- 実行環境: PHP-FPM 8.3

## 環境構成

- Nginx（リバースプロキシ + 静的ファイル配信）
- PHP-FPM 8.3
- MySQL 8.4
- Memcached 1.6

## 改善内容

### PR #1: PHP実装への切り替え

- Docker Composeの設定をRubyからPHPに変更
- Nginx設定をPHP-FPM用に切り替え

### PR #4: 画像配信の最適化

- DBのBLOB → ファイルシステムへ画像データを移行
- Nginxから直接配信（PHPをバイパス）し、レスポンス高速化
- バッチ処理（100件単位）で画像エクスポート（メモリ枯渇対策）
- DBフォールバック機構で移行中の互換性を確保

### PR #5: N+1クエリ問題の解消とクエリ最適化

- `make_posts`関数のN+1クエリをIN句によるバッチ処理に置き換え
  - コメント数: `GROUP BY` で一括取得
  - コメント: `ROW_NUMBER()` ウィンドウ関数で一括取得
  - ユーザー: `IN` 句で一括取得
- データベースインデックス追加（comments.post_id, posts.created_at等）
- GET / と GET /posts で全件取得をJOIN+LIMITに最適化
- ユーザープロフィールページのCOUNTクエリをサブクエリに効率化

### PR #6: digest関数のPHP組み込みhash()への置き換え

- シェルコマンド呼び出し（printf | openssl | sed）をPHPの`hash('sha512')`に置き換え
- 認証処理のたびに発生していたプロセス生成コストを削減

### PR #8: LIMITパラメータのバインドバグ修正

- PDOの`execute()`がLIMIT値を文字列としてバインドしMySQLエラーが発生していた問題を修正
- 定数`POSTS_PER_PAGE`をSQL文字列に直接埋め込む方式に変更

## ディレクトリ構成

```
webapp/
├── .wiki/               # 詳細ドキュメント（技術解説・最適化記録）
├── php/                 # PHP実装
│   ├── views/           # テンプレート
│   └── index.php        # アプリケーションエントリーポイント
├── public/              # 公開ディレクトリ
│   └── image/           # 画像ファイル（エクスポート先）
├── sql/                 # SQLスキーマ
├── etc/                 # 設定ファイル
│   └── nginx/conf.d/    # Nginx設定
└── compose.yml          # Docker Compose設定
```

## 起動方法

```bash
cd webapp
docker compose up -d
```

ブラウザで http://localhost にアクセス

## ベンチマーク実行

```bash
# 1. 画像を事前エクスポート（初回のみ）
curl http://localhost/export-images

# 2. ベンチマーク実行
docker run --network host -i \
  -v $(pwd)/../benchmarker/userdata:/opt/userdata \
  private-isu-benchmarker \
  /bin/benchmarker -t http://host.docker.internal -u /opt/userdata
```

## 総括

初期スコア0から**29,742**まで改善しました。主な改善ポイント：

1. **画像配信の最適化**: DBのBLOBからファイルシステム+Nginx直接配信に変更し、画像タイムアウトを解消
2. **N+1クエリの解消**: ループ内の個別クエリをIN句+バッチ処理に変更し、クエリ数を121回→4回に削減
3. **インデックスの追加**: WHERE/ORDER BY句で使用するカラムにインデックスを張りフルスキャンを回避
4. **クエリの効率化**: 全件取得→JOIN+LIMITで必要分のみ取得
5. **外部プロセス呼び出しの排除**: シェルコマンドをPHP組み込み関数に置き換え

パフォーマンスチューニングでは「推測するな、計測せよ」の原則に従い、ベンチマーク結果のエラーメッセージからボトルネックを特定し、仮説→実装→検証のサイクルを繰り返しました。

## 詳細ドキュメント

技術的な解説や最適化の詳細は [.wiki/](.wiki/) ディレクトリを参照してください。

---

## 参考

- [catatsuy/private-isu](https://github.com/catatsuy/private-isu)
- [ISUCON公式サイト](https://isucon.net)
