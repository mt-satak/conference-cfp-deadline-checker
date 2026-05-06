import { describe, it, expect } from 'vitest';
import { buildResponse } from './index.mjs';

/**
 * Lambda@Edge basic-auth handler の純粋関数 buildResponse 単体テスト。
 *
 * 背景 (Issue #75):
 * CloudFront OAC + Lambda Function URL + POST + body の組み合わせで、
 * viewer の Basic 認証 Authorization と CloudFront SigV4 Authorization が
 * 衝突して署名検証 mismatch (403)。
 *
 * 認証成功後に Authorization を削除して return することで、CloudFront →
 * Function URL の SigV4 を CloudFront 側がクリーンに上書きできるようにする。
 */

function buildEvent(authValue) {
  return {
    Records: [
      {
        cf: {
          request: {
            method: 'POST',
            uri: '/admin/conferences/extract-from-url',
            headers: {
              authorization: authValue
                ? [{ key: 'Authorization', value: authValue }]
                : undefined,
              'content-type': [
                { key: 'Content-Type', value: 'application/x-www-form-urlencoded' },
              ],
            },
          },
        },
      },
    ],
  };
}

describe('buildResponse', () => {
  const expectedAuth = `Basic ${Buffer.from('admin:secret123').toString('base64')}`;

  it('Authorization が一致しない場合 401 を返す', () => {
    // Given: 不正な Authorization
    const event = buildEvent('Basic ZmFrZTpmYWtl'); // fake:fake
    const request = event.Records[0].cf.request;

    // When
    const result = buildResponse(request, 'Basic ZmFrZTpmYWtl', expectedAuth);

    // Then
    expect(result.status).toBe('401');
    expect(result.headers['www-authenticate']).toBeDefined();
  });

  it('Authorization 未指定の場合 401 を返す', () => {
    // Given
    const event = buildEvent(undefined);
    const request = event.Records[0].cf.request;

    // When
    const result = buildResponse(request, undefined, expectedAuth);

    // Then
    expect(result.status).toBe('401');
  });

  it('Authorization が一致した場合、authorization ヘッダを削除した request を返す (Issue #75)', () => {
    // Given: 正しい Authorization
    const event = buildEvent(expectedAuth);
    const request = event.Records[0].cf.request;

    // When
    const result = buildResponse(request, expectedAuth, expectedAuth);

    // Then: request 自体が返り、authorization ヘッダは消える (= CloudFront が
    // SigV4 をクリーンに上書きできる)。content-type 等の他ヘッダは残る。
    expect(result.method).toBe('POST');
    expect(result.uri).toBe('/admin/conferences/extract-from-url');
    expect(result.headers.authorization).toBeUndefined();
    expect(result.headers['content-type']).toEqual([
      { key: 'Content-Type', value: 'application/x-www-form-urlencoded' },
    ]);
  });
});
