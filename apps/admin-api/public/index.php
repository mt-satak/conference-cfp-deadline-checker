<?php
/**
 * 暫定プレースホルダー
 *
 * 本実装（Laravel + Bref）は後続のコミットで apps/admin-api 配下に
 * 構築する。このファイルは CDK の Lambda 関数定義 (Code.fromAsset) が
 * 参照するための最低限のエントリポイントとして配置している。
 */

header('Content-Type: text/plain; charset=utf-8');
http_response_code(503);
echo "admin-api not yet implemented\n";
