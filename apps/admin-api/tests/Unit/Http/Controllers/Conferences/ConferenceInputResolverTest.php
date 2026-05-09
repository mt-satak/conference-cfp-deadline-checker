<?php

declare(strict_types=1);

use App\Application\Conferences\CreateConferenceInput;
use App\Domain\Conferences\ConferenceFormat;
use App\Domain\Conferences\ConferenceStatus;
use App\Http\Controllers\Conferences\ConferenceInputResolver;

/**
 * ConferenceInputResolver の単体テスト (Issue #178 #1)。
 *
 * Api / Admin の ConferenceController に重複していた以下のロジックを
 * 静的ヘルパに抽出した:
 *  - resolveStatusFilters: URL ?status を ListConferencesUseCase 用配列に解決
 *  - resolveCreateStatus:  POST data の status (string) を enum に解決
 *  - buildCreateInput:     POST data + status から CreateConferenceInput を組み立て
 *  - castUpdateFields:     PUT data の format / status を string → enum にキャスト
 */
describe('resolveStatusFilters (URL ?status → ConferenceStatus[]|null)', function () {
    it('?status=draft で [Draft]', function () {
        expect(ConferenceInputResolver::resolveStatusFilters('draft', null))
            ->toBe([ConferenceStatus::Draft]);
    });

    it('?status=published で [Published]', function () {
        expect(ConferenceInputResolver::resolveStatusFilters('published', null))
            ->toBe([ConferenceStatus::Published]);
    });

    it('?status=archived で [Archived]', function () {
        expect(ConferenceInputResolver::resolveStatusFilters('archived', null))
            ->toBe([ConferenceStatus::Archived]);
    });

    it('?status=active で [Draft, Published] (= Active 仮想 status)', function () {
        expect(ConferenceInputResolver::resolveStatusFilters('active', null))
            ->toBe([ConferenceStatus::Draft, ConferenceStatus::Published]);
    });

    it('?status=unknown は default を返す (= API は null、Admin は active 配列)', function () {
        // API パターン
        expect(ConferenceInputResolver::resolveStatusFilters('unknown-value', null))
            ->toBeNull();
        // Admin パターン
        expect(ConferenceInputResolver::resolveStatusFilters(
            'unknown-value',
            [ConferenceStatus::Draft, ConferenceStatus::Published],
        ))->toBe([ConferenceStatus::Draft, ConferenceStatus::Published]);
    });

    it('?status 未指定 (null) は default を返す', function () {
        expect(ConferenceInputResolver::resolveStatusFilters(null, null))->toBeNull();
        expect(ConferenceInputResolver::resolveStatusFilters(
            null,
            [ConferenceStatus::Draft, ConferenceStatus::Published],
        ))->toBe([ConferenceStatus::Draft, ConferenceStatus::Published]);
    });

    it('?status=配列 (= 不正型) も default を返す (= fail-soft)', function () {
        expect(ConferenceInputResolver::resolveStatusFilters(['draft'], null))->toBeNull();
    });
});

describe('resolveCreateStatus (POST $validated["status"] → ConferenceStatus)', function () {
    it('status=draft → Draft', function () {
        expect(ConferenceInputResolver::resolveCreateStatus(['status' => 'draft']))
            ->toBe(ConferenceStatus::Draft);
    });

    it('status=published → Published', function () {
        expect(ConferenceInputResolver::resolveCreateStatus(['status' => 'published']))
            ->toBe(ConferenceStatus::Published);
    });

    it('status 未指定は Published (= 後方互換)', function () {
        expect(ConferenceInputResolver::resolveCreateStatus([]))
            ->toBe(ConferenceStatus::Published);
    });

    it('status 不正値は Published (= fail-soft)', function () {
        expect(ConferenceInputResolver::resolveCreateStatus(['status' => 'unknown']))
            ->toBe(ConferenceStatus::Published);
    });
});

describe('buildCreateInput (validated array + status → CreateConferenceInput)', function () {
    it('全フィールドを CreateConferenceInput に束ねて返す', function () {
        $input = ConferenceInputResolver::buildCreateInput([
            'name' => 'PHP Conference 2026',
            'trackName' => 'Track A',
            'officialUrl' => 'https://phpcon.example.com/',
            'cfpUrl' => 'https://phpcon.example.com/cfp',
            'eventStartDate' => '2026-09-19',
            'eventEndDate' => '2026-09-20',
            'venue' => '東京',
            'format' => 'offline',
            'cfpStartDate' => '2026-05-01',
            'cfpEndDate' => '2026-07-15',
            'categories' => ['cat-1'],
            'description' => 'desc',
            'themeColor' => '#FF0000',
        ], ConferenceStatus::Published);

        expect($input)->toBeInstanceOf(CreateConferenceInput::class);
        expect($input->name)->toBe('PHP Conference 2026');
        expect($input->trackName)->toBe('Track A');
        expect($input->format)->toBe(ConferenceFormat::Offline);
        expect($input->categories)->toBe(['cat-1']);
        expect($input->status)->toBe(ConferenceStatus::Published);
    });

    it('optional フィールド未指定時は null / 空配列で埋める', function () {
        $input = ConferenceInputResolver::buildCreateInput([
            'name' => 'Minimal Draft',
            'officialUrl' => 'https://x.example.com/',
        ], ConferenceStatus::Draft);

        expect($input->trackName)->toBeNull();
        expect($input->cfpUrl)->toBeNull();
        expect($input->format)->toBeNull();
        expect($input->categories)->toBe([]);
        expect($input->status)->toBe(ConferenceStatus::Draft);
    });

    it('format=null は ConferenceFormat null として保持 (= "未指定" を表現)', function () {
        $input = ConferenceInputResolver::buildCreateInput([
            'name' => 'X',
            'officialUrl' => 'https://x.example.com/',
            'format' => null,
        ], ConferenceStatus::Draft);

        expect($input->format)->toBeNull();
    });
});

describe('castUpdateFields (PUT $validated string → enum cast)', function () {
    it('format string を enum に cast する', function () {
        $fields = ConferenceInputResolver::castUpdateFields(['format' => 'online']);
        expect($fields)->toHaveKey('format');
        expect($fields['format'] ?? null)->toBe(ConferenceFormat::Online);
    });

    it('format=null は null のまま維持する', function () {
        $fields = ConferenceInputResolver::castUpdateFields(['format' => null]);
        expect(array_key_exists('format', $fields))->toBeTrue();
        // PHPStan の typed shape (= optional) を narrowing するため key 存在 + 値で別々に assert
        $format = array_key_exists('format', $fields) ? $fields['format'] : 'sentinel';
        expect($format)->toBeNull();
    });

    it('format キー不在の時は cast 対象に含まない', function () {
        $fields = ConferenceInputResolver::castUpdateFields(['name' => 'X']);
        expect(array_key_exists('format', $fields))->toBeFalse();
    });

    it('status string を enum に cast する', function () {
        $fields = ConferenceInputResolver::castUpdateFields(['status' => 'archived']);
        expect($fields)->toHaveKey('status');
        expect($fields['status'] ?? null)->toBe(ConferenceStatus::Archived);
    });

    it('status キー不在の時は cast 対象に含まない', function () {
        $fields = ConferenceInputResolver::castUpdateFields(['name' => 'X']);
        expect(array_key_exists('status', $fields))->toBeFalse();
    });

    it('format / status 以外の field は素通しする', function () {
        $fields = ConferenceInputResolver::castUpdateFields([
            'name' => 'X',
            'cfpEndDate' => '2026-08-01',
            'categories' => ['cat-1', 'cat-2'],
        ]);
        expect($fields['name'] ?? null)->toBe('X');
        expect($fields['cfpEndDate'] ?? null)->toBe('2026-08-01');
        expect($fields['categories'] ?? null)->toBe(['cat-1', 'cat-2']);
    });
});
