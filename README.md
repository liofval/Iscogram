# Private-ISU パフォーマンス改善

PR TIMES 学生インターン課題として、[private-isu](https://github.com/catatsuy/private-isu) のパフォーマンス改善に取り組みました。

## スコア

| 状態 | スコア | 成功 | 失敗 | 備考 |
|------|--------|------|------|------|
| 初期状態（PHP実装） | 0 | 172 | 37 | タイムアウト多発 |
| 最終スコア | - | - | - | （計測後に更新） |

## 使用言語

**PHP** （PR TIMES社内で利用されている言語のため）

- フレームワーク: Slim 4
- 実行環境: PHP-FPM 8.3

## 環境構成

- Nginx（リバースプロキシ）
- PHP-FPM 8.3
- MySQL 8.4
- Memcached 1.6

## 改善内容

### 実施済み

1. **PHP実装への切り替え**
   - Docker Composeの設定を変更
   - Nginx設定をPHP用に切り替え

### 今後の改善予定

1. **データベースインデックスの追加**
   - `comments.post_id` へのインデックス
   - `posts.created_at` へのインデックス

2. **N+1クエリの解消**
   - JOINを使用した一括取得

3. **画像配信の最適化**
   - DBのBLOBからファイルシステムへ移行
   - Nginxからの直接配信

## ディレクトリ構成

```
webapp/
├── php/                 # PHP実装
│   ├── public/          # 公開ディレクトリ（静的ファイル）
│   └── index.php        # アプリケーションエントリーポイント
├── sql/                 # SQLスキーマ
├── etc/                 # 設定ファイル
│   └── nginx/conf.d/    # Nginx設定
├── docs/                # ドキュメント
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
docker run --network host -i \
  -v $(pwd)/../benchmarker/userdata:/opt/userdata \
  private-isu-benchmarker \
  /bin/benchmarker -t http://host.docker.internal -u /opt/userdata
```

## 総括

（最終的な改善結果と学んだことを記述予定）

---

## 参考

- [catatsuy/private-isu](https://github.com/catatsuy/private-isu)
- [ISUCON公式サイト](https://isucon.net)
