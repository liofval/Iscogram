# Benchmarker - ベンチマーカー使用ガイド

## 概要

ベンチマーカーはGo言語で実装された負荷テストツールです。アプリケーションに対して実際のユーザー操作をシミュレートし、パフォーマンスを数値化します。

---

## 実行方法

### Docker経由（推奨）

```bash
# イメージのビルド
docker build -t private-isu-benchmarker https://github.com/catatsuy/private-isu.git#:benchmarker

# 実行
docker run --network host -i private-isu-benchmarker \
  /bin/benchmarker -t http://host.docker.internal -u /opt/userdata
```

### ローカル実行

```bash
cd benchmarker
make
./bin/benchmarker -t "http://localhost" -u ./userdata
```

---

## オプション

| オプション | デフォルト | 説明 |
|------------|-----------|------|
| `-t, --target` | (必須) | ターゲットURL |
| `-u, --userdata` | (必須) | ユーザーデータディレクトリ |
| `--benchmark-timeout` | 60s | 負荷走行時間 |
| `-d, --debug` | false | デバッグモード |

---

## 出力フォーマット

```json
{
  "pass": true,
  "score": 1710,
  "success": 1434,
  "fail": 0,
  "messages": []
}
```

| フィールド | 説明 |
|------------|------|
| pass | 合格判定 |
| score | スコア |
| success | 成功リクエスト数 |
| fail | 失敗リクエスト数 |
| messages | エラーメッセージ |

---

## スコア計算

```
スコア = (GET成功 × 1)
       + (POST成功 × 2)
       + (画像投稿成功 × 5)
       - (エラー × 10)
       - (通信エラー × 20)
       - (遅延POST × 100)
```

---

## 合格条件

- `/initialize` が10秒以内に完了
- 致命的なエラーがない
- 必要なDOM要素がレスポンスに含まれている

---

## シナリオ

### 1. 初期化フェーズ

```
GET /initialize → 10秒以内に完了必須
```

### 2. 負荷走行フェーズ（60秒間）

**閲覧系:**
- トップページ閲覧
- 投稿詳細閲覧
- ユーザープロフィール閲覧
- 画像取得

**操作系:**
- ログイン
- 新規登録
- 投稿
- コメント

---

## スコア目安

| 状態 | スコア目安 |
|------|-----------|
| 初期状態 | ~1,500 |
| インデックス追加 | ~3,000 |
| N+1解消 | ~8,000 |
| 画像ファイル化 | ~15,000 |
| 総合最適化 | ~30,000+ |
