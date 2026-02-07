# Private-ISU パフォーマンス改善 Wiki

## 概要

このWikiは、[private-isu](https://github.com/catatsuy/private-isu)（ISUCON練習用Webアプリケーション）のパフォーマンス改善過程を教育目的で詳細に記録したものです。

private-isuは「Iscogram」という画像投稿SNSで、Instagram風のWebアプリケーションです。投稿・コメント・ユーザー管理といった基本的なSNS機能を持ちますが、意図的にパフォーマンスのボトルネックが埋め込まれています。

## 目次

### アーキテクチャ
- [Architecture](./Architecture.md) - システム構成とリクエストフローの解説

### 最適化の記録（PR別）
- [PR #1: PHP実装への切り替え](./PR1-PHP-Implementation.md)
- [PR #4: 画像ファイルシステム移行](./PR4-Image-Filesystem-Migration.md)
- [PR #5: N+1クエリ問題の解消](./PR5-N-Plus-1-Query-Fix.md)
- [PR #6: digest関数の最適化](./PR6-Digest-Function-Optimization.md)
- [PR #8: LIMITパラメータバグ修正](./PR8-Limit-Parameter-Fix.md)

### リファレンス
- [Technical Glossary](./Technical-Glossary.md) - 技術用語集
- [Performance Tuning Guide](./Performance-Tuning-Guide.md) - ISUCONにおけるパフォーマンスチューニングの考え方

## スコア推移

```
初期状態:          score=0      success=172    fail=37  ← 画像タイムアウト多発
PR#4 画像移行後:   score=0      success=206    fail=64  ← 画像タイムアウト解消
PR#5 N+1解消後:    score=0      success=422    fail=26  ← クエリ最適化効果
PR#6+#8 最終:      score=29,742 success=28,625 fail=0   ← digest最適化+バグ修正
```

## 使用技術スタック

| レイヤー | 技術 | 役割 |
|---------|------|------|
| リバースプロキシ | Nginx | 静的ファイル配信、リクエストルーティング |
| アプリケーション | PHP 8.3 (PHP-FPM) + Slim 4 | ビジネスロジック |
| データベース | MySQL 8.4 | データ永続化 |
| セッション | Memcached 1.6 | セッション管理 |
| コンテナ | Docker Compose | 環境構築 |

## 改善のアプローチ

ISUCONにおけるパフォーマンス改善は、以下の手順で進めました：

1. **ボトルネックの特定** - ベンチマーク結果のエラーメッセージから問題箇所を推測
2. **仮説の構築** - なぜそこがボトルネックになっているか分析
3. **改善の実装** - 最小限の変更で最大の効果を得る修正
4. **効果の検証** - ベンチマーク再実行でスコアとエラーの変化を確認
5. **次のボトルネック特定** - 新しいエラーメッセージから次の改善点を見つける

この「特定 → 仮説 → 実装 → 検証」のサイクルを繰り返すことで、段階的にパフォーマンスを改善しています。
