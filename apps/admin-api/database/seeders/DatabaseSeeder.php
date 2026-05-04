<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * 本アプリは Eloquent / SQL DB を使わないため、Seeder の実体は持たない。
 * 将来 DynamoDB Local 向けのシード処理が必要になった場合は、
 * artisan コマンドや packages/db-tools 配下のスクリプトで対応する想定。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // intentionally empty
    }
}
