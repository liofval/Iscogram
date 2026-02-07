# API Endpoints - エンドポイント一覧

## エンドポイント一覧

| メソッド | パス | 認証 | 説明 |
|----------|------|------|------|
| GET | `/` | 不要 | トップページ（タイムライン） |
| GET | `/initialize` | 不要 | データベース初期化 |
| GET | `/login` | 不要 | ログインページ |
| POST | `/login` | 不要 | ログイン処理 |
| GET | `/register` | 不要 | 登録ページ |
| POST | `/register` | 不要 | ユーザー登録処理 |
| GET | `/logout` | 要 | ログアウト |
| GET | `/posts` | 不要 | 投稿一覧（AJAX用） |
| GET | `/posts/:id` | 不要 | 投稿詳細 |
| POST | `/` | 要 | 新規投稿 |
| GET | `/image/:id.:ext` | 不要 | 画像取得 |
| POST | `/comment` | 要 | コメント投稿 |
| GET | `/admin/banned` | 要(管理者) | BAN管理ページ |
| POST | `/admin/banned` | 要(管理者) | ユーザーBAN処理 |
| GET | `/@:accountName` | 不要 | ユーザープロフィール |

---

## 詳細仕様

### GET /initialize

ベンチマーク前にデータベースをリセット。

**レスポンス:** `200 OK`

**制約:** 10秒以内に完了必須

---

### GET /

トップページ。最新20件の投稿を表示。

**表示内容:**
- 投稿の画像、本文、投稿者名
- 各投稿の最新3件のコメント
- コメント総数

---

### POST /login

**リクエスト:**
```
Content-Type: application/x-www-form-urlencoded

account_name=<アカウント名>&password=<パスワード>
```

**レスポンス:**
- 成功: `302` → `/`
- 失敗: `302` → `/login`

---

### POST /register

**リクエスト:**
```
Content-Type: application/x-www-form-urlencoded

account_name=<アカウント名>&password=<パスワード>
```

**バリデーション:**
- account_name: 3文字以上、英数字とアンダースコア
- password: 6文字以上、英数字とアンダースコア

---

### POST / (新規投稿)

**リクエスト:**
```
Content-Type: multipart/form-data

file=<画像ファイル>
body=<投稿本文>
csrf_token=<CSRFトークン>
```

**制約:**
- ファイルサイズ: 10MB以下
- 対応形式: JPEG, PNG, GIF

---

### GET /image/:id.:ext

投稿の画像を取得。

**パスパラメータ:**
- id: 投稿ID
- ext: jpg, png, gif

**レスポンス:** 画像バイナリ

---

### POST /comment

**リクエスト:**
```
Content-Type: application/x-www-form-urlencoded

post_id=<投稿ID>
comment=<コメント本文>
csrf_token=<CSRFトークン>
```

---

## 静的ファイル

| パス | ファイル |
|------|----------|
| `/css/style.css` | スタイルシート |
| `/js/main.js` | JavaScript |
| `/js/timeago.min.js` | 時刻表示ライブラリ |
| `/favicon.ico` | ファビコン |
