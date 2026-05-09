import { App, SecretValue, Stack } from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import { AttributeType, Table } from 'aws-cdk-lib/aws-dynamodb';
import { Architecture, Code, LayerVersion } from 'aws-cdk-lib/aws-lambda';
import { describe, it } from 'vitest';
import { ArchivePastTask } from './archive-past-task';

/**
 * ArchivePastTask Construct の単体テスト (Issue #165 Phase 3)。
 *
 * 開催日を過ぎた Published Conference を Archived 状態に遷移させる Lambda console と
 * EventBridge schedule (毎朝 JST 6:00) を CDK で生成する。
 *
 * Lambda の中身は Phase 2 で実装した artisan command (`conferences:archive-past`) を
 * Bref console モードで実行する。
 *
 * 重要な検証点:
 * - Schedule cron が JST 6:00 = UTC 21:00 に正しくマッピングされている
 * - EventBridge target payload で artisan command 名が `conferences:archive-past`
 * - Lambda は admin-api と同じ Bref + PHP layer を共有する
 * - DynamoDB conferences テーブルに対し read+write IAM
 */
function synthTemplate(): Template {
    const app = new App();
    const stack = new Stack(app, 'TestStack');

    // ダミー DynamoDB テーブル
    const conferences = new Table(stack, 'TestConferences', {
        partitionKey: { name: 'conferenceId', type: AttributeType.STRING },
    });

    // PROVIDED_AL2023 ランタイムは inline code 非対応のため Code.fromAsset を使う。
    // CDK は asset 解決に既存ディレクトリを要求するだけで、テスト時は中身を見ないので
    // 任意の存在ディレクトリで OK (ここでは CDK の test ディレクトリ自体を使う)。
    // Layer も同様に inline 不可なので fromLayerVersionArn で fake ARN を渡す。
    const adminApiCode = Code.fromAsset(__dirname);
    const phpLayer = LayerVersion.fromLayerVersionArn(
        stack,
        'TestPhpLayer',
        'arn:aws:lambda:ap-northeast-1:123456789012:layer:php:1',
    );

    new ArchivePastTask(stack, 'ArchivePastTaskUnderTest', {
        adminApiCode,
        phpLayer,
        appKey: SecretValue.unsafePlainText('dummy-app-key'),
        appUrl: 'https://admin.example.com',
        conferences,
        architecture: Architecture.X86_64,
    });

    return Template.fromStack(stack);
}

describe('ArchivePastTask Construct', () => {
    it('Lambda は Bref console handler (= artisan) で作成される', () => {
        // Given/When
        const template = synthTemplate();

        // Then
        template.hasResourceProperties('AWS::Lambda::Function', {
            Handler: 'artisan',
            Runtime: 'provided.al2023',
            Environment: {
                Variables: Match.objectLike({
                    BREF_RUNTIME: 'console',
                    BREF_LOOP_MAX: '1',
                }),
            },
        });
    });

    it('Lambda timeout は archive-past 処理に十分な 5 分 (= 300 秒) に設定される', () => {
        // Given/When
        const template = synthTemplate();

        // Then: AutoCrawl の 15 分より短く設定 (LLM 抽出無し、純 DynamoDB 操作のため)
        template.hasResourceProperties('AWS::Lambda::Function', {
            Handler: 'artisan',
            Timeout: 300,
        });
    });

    it('EventBridge schedule は毎朝 JST 6:00 (= UTC 21:00) で起動する', () => {
        // Given/When
        const template = synthTemplate();

        // Then: cron(0 21 * * ? *) = 毎日 UTC 21:00 = JST 翌日 06:00
        template.hasResourceProperties('AWS::Events::Rule', {
            ScheduleExpression: 'cron(0 21 * * ? *)',
        });
    });

    it('EventBridge target payload で artisan command 名が conferences:archive-past', () => {
        // Given/When
        const template = synthTemplate();

        // Then
        template.hasResourceProperties('AWS::Events::Rule', {
            Targets: Match.arrayWith([
                Match.objectLike({
                    Input: Match.serializedJson({
                        cli: 'conferences:archive-past',
                    }),
                }),
            ]),
        });
    });

    it('Lambda は conferences テーブルに read+write IAM を持つ', () => {
        // Given/When
        const template = synthTemplate();

        // Then: DynamoDB の write 系アクション (PutItem 等) が IAM Policy に含まれる
        template.hasResourceProperties('AWS::IAM::Policy', {
            PolicyDocument: Match.objectLike({
                Statement: Match.arrayWith([
                    Match.objectLike({
                        Action: Match.arrayWith(['dynamodb:PutItem']),
                    }),
                ]),
            }),
        });
    });
});
