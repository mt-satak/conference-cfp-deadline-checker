import { App, Stack } from 'aws-cdk-lib';
import { Annotations, Match, Template } from 'aws-cdk-lib/assertions';
import { describe, it, expect } from 'vitest';
import { DataTables, type DataTablesProps } from './data-tables';

/**
 * DataTables Construct の単体テスト (Issue #28: dev/prod flag)。
 *
 * CDK の Template assertion API で CloudFormation 出力を検証する。
 * 実 AWS デプロイは行わない。
 *
 * env オプションの効果を検証:
 *   - 未指定 (= 本番モード): RETAIN + deletionProtection: true (現状維持)
 *   - 'dev': DELETE + deletionProtection: false + 警告 Annotation
 */

function synthTemplate(props?: DataTablesProps): {
    template: Template;
    stack: Stack;
} {
    const app = new App();
    const stack = new Stack(app, 'TestStack');
    new DataTables(stack, 'TablesUnderTest', props);

    return { template: Template.fromStack(stack), stack };
}

describe('DataTables (Issue #28: env flag)', () => {
    describe('env 未指定 (= 本番モード、現状の動作維持)', () => {
        it('Conferences テーブルが RETAIN + deletionProtection: true で作成される', () => {
            // Given/When
            const { template } = synthTemplate();

            // Then: 2 テーブル分の Resource が DeletionPolicy: Retain で作られている
            template.hasResource('AWS::DynamoDB::Table', {
                DeletionPolicy: 'Retain',
                UpdateReplacePolicy: 'Retain',
                Properties: Match.objectLike({
                    TableName: 'cfp-conferences',
                    DeletionProtectionEnabled: true,
                }),
            });
        });

        it('Categories テーブルも RETAIN + deletionProtection: true', () => {
            // Given/When
            const { template } = synthTemplate();

            // Then
            template.hasResource('AWS::DynamoDB::Table', {
                DeletionPolicy: 'Retain',
                UpdateReplacePolicy: 'Retain',
                Properties: Match.objectLike({
                    TableName: 'cfp-categories',
                    DeletionProtectionEnabled: true,
                }),
            });
        });

        it('警告 Annotation は出ない', () => {
            // Given/When
            const { stack } = synthTemplate();

            // Then: warning が 0 件
            const warnings = Annotations.fromStack(stack).findWarning(
                '*',
                Match.anyValue(),
            );
            expect(warnings.length).toBe(0);
        });
    });

    describe("env='dev' (= 初回セットアップ用)", () => {
        it('Conferences テーブルが DELETE + deletionProtection: false で作成される', () => {
            // Given/When
            const { template } = synthTemplate({ env: 'dev' });

            // Then
            template.hasResource('AWS::DynamoDB::Table', {
                DeletionPolicy: 'Delete',
                UpdateReplacePolicy: 'Delete',
                Properties: Match.objectLike({
                    TableName: 'cfp-conferences',
                    DeletionProtectionEnabled: false,
                }),
            });
        });

        it('Categories テーブルも DELETE + deletionProtection: false', () => {
            // Given/When
            const { template } = synthTemplate({ env: 'dev' });

            // Then
            template.hasResource('AWS::DynamoDB::Table', {
                DeletionPolicy: 'Delete',
                UpdateReplacePolicy: 'Delete',
                Properties: Match.objectLike({
                    TableName: 'cfp-categories',
                    DeletionProtectionEnabled: false,
                }),
            });
        });

        it('警告 Annotation が出る (本番運用時に dev フラグを残してしまう事故防止)', () => {
            // Given/When
            const { stack } = synthTemplate({ env: 'dev' });

            // Then: warning が 1 件以上、メッセージに dev / DESTROY を含む
            const warnings = Annotations.fromStack(stack).findWarning(
                '*',
                Match.anyValue(),
            );
            expect(warnings.length).toBeGreaterThan(0);
            const messages = warnings.map((w) => String(w.entry.data));
            expect(
                messages.some(
                    (m) => m.includes('dev') && m.toLowerCase().includes('destroy'),
                ),
            ).toBe(true);
        });
    });

    describe("env='production' (= 明示的な本番指定、未指定と同等)", () => {
        it('RETAIN + deletionProtection: true で本番モードと同等の動作になる', () => {
            // Given/When
            const { template } = synthTemplate({ env: 'production' });

            // Then
            template.hasResource('AWS::DynamoDB::Table', {
                DeletionPolicy: 'Retain',
                Properties: Match.objectLike({
                    TableName: 'cfp-conferences',
                    DeletionProtectionEnabled: true,
                }),
            });
        });
    });
});
