# Getting Started - 環境構築ガイド

## 前提条件

- Docker
- Docker Compose

---

## クイックスタート

### 1. 初期データの準備

```bash
# プロジェクトルートで実行
make init
```

これにより `sql/dump.sql.bz2` がダウンロードされます。

### 2. Docker Composeで起動

```bash
cd webapp
docker compose up --build
```

初回起動時はMySQLの初期化に数分かかります。

### 3. アクセス確認

http://localhost にアクセス

**テストアカウント:**
- アカウント名: `mary`
- パスワード: `marymary`

---

## 言語の切り替え

デフォルトはRuby実装です。

### PHP実装に切り替え

1. `compose.yml` を編集：

```yaml
app:
  build:
    context: php/  # ruby/ から変更
```

2. Nginx設定を切り替え：

```bash
cd etc/nginx/conf.d
mv default.conf default.conf.org
mv php.conf.org php.conf
```

3. 再起動：

```bash
docker compose down
docker compose up --build
```

### Go実装に切り替え

```yaml
app:
  build:
    context: golang/
```

### Python実装に切り替え

```yaml
app:
  build:
    context: python/
```

### Node.js実装に切り替え

```yaml
app:
  build:
    context: node/
```

---

## ポート競合の解決

### ポート80が使用中

```yaml
services:
  nginx:
    ports:
      - "8080:80"  # 80から8080に変更
```

### ポート3306が使用中

```yaml
services:
  mysql:
    ports:
      - "13306:3306"  # 3306から13306に変更
```

または、ローカルのMySQLを停止：

```bash
# macOS
brew services stop mysql

# Ubuntu
sudo systemctl stop mysql
```

---

## 停止・再起動

```bash
# 停止
docker compose down

# 再起動（コード変更を反映）
docker compose up --build

# ボリュームも削除（DBリセット）
docker compose down -v
```

---

## データベース接続

```bash
mysql -h 127.0.0.1 -P 3306 -u root -proot isuconp
```

---

## トラブルシューティング

### 画像が表示されない

初期データがインポートされているか確認：

```bash
ls -la sql/dump.sql.bz2
```

### MySQLに接続できない

```bash
docker compose logs mysql
```

### アプリが起動しない

```bash
docker compose logs app
```
