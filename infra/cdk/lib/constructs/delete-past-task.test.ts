import { App, Stack } from 'aws-cdk-lib';
import { Match, Template } from 'aws-cdk-lib/assertions';
import { AttributeType, Table } from 'aws-cdk-lib/aws-dynamodb';
import { Architecture, Code, LayerVersion } from 'aws-cdk-lib/aws-lambda';
import { describe, it } from 'vitest';
import { DeletePastTask } from './delete-past-task';

/**
 * DeletePastTask Construct の単体テスト (Issue #221 PR-1)。
 *
 * 開催日を過ぎた Conference を全ステータス対象でハード削除する Lambda console と
 * EventBridge schedule (毎週 月曜 JST 08:00) を CDK で生成する。
 *
 * 重要な検証点:
 * - Schedule cron が JST 月 08:00 = UTC 日 23:00 に正しくマッピングされている
 * - EventBridge target payload で artisan command が `conferences:delete-past --apply`
 * - Lambda は admin-api と同じ Bref + PHP layer を共有する
 * - DynamoDB conferences テーブルに対し read+write IAM
 */
function synthTemplate(): Template {
    const app = new App();
    const stack = new Stack(app, 'TestStack');

    const conferences = new Table(stack, 'TestConferences', {
        partitionKey: { name: 'conferenceId', type: AttributeType.STRING },
    });

    // PROVIDED_AL2023 は inline code 非対応のため Code.fromAsset を使う (中身は見られない)。
    const adminApiCode = Code.fromAsset(__dirname);
    const phpLayer = LayerVersion.fromLayerVersionArn(
        stack,
        'TestPhpLayer',
        'arn:aws:lambda:ap-northeast-1:123456789012:layer:php:1',
    );

    new DeletePastTask(stack, 'DeletePastTaskUnderTest', {
        adminApiCode,
        phpLayer,
        appKey: 'dummy-app-key',
        appUrl: 'https://admin.example.com',
        conferences,
        architecture: Architecture.X86_64,
    });

    return Template.fromStack(stack);
}

describe('DeletePastTask Construct', () => {
    it('Lambda は Bref console handler (= artisan) で作成される', () => {
        const template = synthTemplate();

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

    it('Lambda timeout は 5 分 (= 300 秒)', () => {
        const template = synthTemplate();

        // LLM 抽出無し、純 DynamoDB 操作 (Scan + DeleteItem) のため 5 分で十分
        template.hasResourceProperties('AWS::Lambda::Function', {
            Handler: 'artisan',
            Timeout: 300,
        });
    });

    it('EventBridge schedule は毎週 月曜 JST 08:00 (= UTC 日曜 23:00) で起動する', () => {
        const template = synthTemplate();

        // cron(0 23 ? * SUN *) = 毎週 UTC 日曜 23:00 = JST 月曜 08:00
        template.hasResourceProperties('AWS::Events::Rule', {
            ScheduleExpression: 'cron(0 23 ? * SUN *)',
        });
    });

    it('EventBridge target payload は conferences:delete-past --apply (= 実削除)', () => {
        const template = synthTemplate();

        template.hasResourceProperties('AWS::Events::Rule', {
            Targets: Match.arrayWith([
                Match.objectLike({
                    Input: Match.serializedJson({
                        cli: 'conferences:delete-past --apply',
                    }),
                }),
            ]),
        });
    });

    it('Lambda は conferences テーブルに read+write IAM を持つ', () => {
        const template = synthTemplate();

        // DeleteItem を含む write 系アクションが IAM Policy に付与される
        template.hasResourceProperties('AWS::IAM::Policy', {
            PolicyDocument: Match.objectLike({
                Statement: Match.arrayWith([
                    Match.objectLike({
                        Action: Match.arrayWith(['dynamodb:DeleteItem']),
                    }),
                ]),
            }),
        });
    });
});
