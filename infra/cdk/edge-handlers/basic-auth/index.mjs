/**
 * Lambda@Edge ハンドラー: 管理画面 (/admin/*) の Basic 認証
 *
 * CloudFront の Viewer Request トリガーで実行される。
 * 認証情報は AWS Secrets Manager に保存し、コードには埋め込まない。
 * Secrets Manager の値は Lambda インスタンスのメモリにキャッシュし、
 * リクエストごとの API コール（コスト・レイテンシ）を抑える。
 */

import { Buffer } from 'node:buffer';
import {
  GetSecretValueCommand,
  SecretsManagerClient,
} from '@aws-sdk/client-secrets-manager';

// Secrets Manager のシークレット ID（CDK 側で同名で作成する）
const SECRET_ID = 'cfp/admin-basic-auth';

// シークレットの保管リージョン。Lambda@Edge は us-east-1 に登録されるため、
// シークレットも us-east-1 に置きクロスリージョン呼び出しを避ける。
const SECRET_REGION = 'us-east-1';

// メモリキャッシュの TTL。ローテーション後の反映までの最大遅延に相当する。
const CACHE_TTL_MS = 5 * 60 * 1000;

const client = new SecretsManagerClient({ region: SECRET_REGION });

// モジュールスコープにキャッシュを保持。同じ Lambda インスタンスが再利用される間は
// Secrets Manager を呼ばない。コールドスタート時のみ取得する。
let cachedAuthHeader = null;
let cacheTime = 0;

/**
 * Secrets Manager から認証情報を取得し、Basic 認証の期待値ヘッダ文字列を組み立てる。
 * キャッシュが有効ならそれを返す。
 */
async function getExpectedAuthHeader() {
  if (cachedAuthHeader && Date.now() - cacheTime < CACHE_TTL_MS) {
    return cachedAuthHeader;
  }
  const response = await client.send(
    new GetSecretValueCommand({ SecretId: SECRET_ID }),
  );
  if (!response.SecretString) {
    throw new Error('Secret value is empty');
  }

  // CDK 側で `{"username":"admin","password":"..."}` 形式で生成される想定
  const { username, password } = JSON.parse(response.SecretString);

  // RFC 7617 に基づく Basic 認証ヘッダ値
  cachedAuthHeader = `Basic ${Buffer.from(`${username}:${password}`).toString('base64')}`;
  cacheTime = Date.now();
  return cachedAuthHeader;
}

/**
 * Authorization ヘッダの検証結果から CloudFront viewer-request の応答を組み立てる。
 *
 * 認証成功時: 元の request をそのまま返す。ただし `authorization` ヘッダは削除する。
 *   理由 (Issue #75):
 *   CloudFront OAC + Lambda Function URL の SigV4 署名は、CloudFront が SigV4 を
 *   `Authorization` ヘッダに書き込む形で動作する。viewer (ブラウザ) の Basic 認証の
 *   `Authorization: Basic ...` ヘッダが残ったまま origin へ転送されると、POST + body
 *   ありリクエストで CloudFront 側の SigV4 計算と Function URL 側の検証で署名 mismatch
 *   が発生し 403 になる (GET は body なしで寛容なため動作)。Lambda@Edge で認証成功
 *   時点で削除しておくことで CloudFront が SigV4 をクリーンに上書きできる。
 *
 * 認証失敗時: 401 を返し、ブラウザに Basic 認証ダイアログを表示させる。
 *
 * @param {object} request CloudFront viewer-request の request オブジェクト
 * @param {string | undefined} providedAuth viewer から送られた Authorization ヘッダ値
 * @param {string} expectedAuth 期待する Basic 認証ヘッダ文字列
 */
export function buildResponse(request, providedAuth, expectedAuth) {
  if (providedAuth !== expectedAuth) {
    return {
      status: '401',
      statusDescription: 'Unauthorized',
      headers: {
        // WWW-Authenticate ヘッダを返すことでブラウザがログインダイアログを表示する
        'www-authenticate': [
          {
            key: 'WWW-Authenticate',
            value: 'Basic realm="Admin Area", charset="UTF-8"',
          },
        ],
      },
      body: 'Unauthorized',
    };
  }

  // OAC SigV4 衝突を避けるため認証成功後は Authorization を削除する (Issue #75)
  delete request.headers.authorization;
  return request;
}

/**
 * Lambda@Edge エントリポイント
 * - 認証成功時: Authorization を削除した request をオリジンへ転送
 * - 認証失敗時: 401 を返す
 */
export const handler = async (event) => {
  const request = event.Records[0].cf.request;
  const expectedAuth = await getExpectedAuthHeader();
  // CloudFront Lambda@Edge ではヘッダが [{key, value}] 配列形式で渡される
  const providedAuth = request.headers.authorization?.[0]?.value;
  return buildResponse(request, providedAuth, expectedAuth);
};
