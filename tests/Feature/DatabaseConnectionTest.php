<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\System\Services\HostCapabilityInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_the_default_connection_answers_a_query(): void
    {
        $this->assertSame(1, DB::selectOne('select 1 as one')->one);
    }

    public function test_the_users_table_exists_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasColumns('users', ['id', 'name', 'email', 'password', 'role']));
    }

    public function test_the_queue_and_cache_tables_exist_for_shared_hosting(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('sessions'));
    }

    public function test_the_host_inspector_can_reach_the_database(): void
    {
        $report = app(HostCapabilityInspector::class)->inspect();

        $this->assertSame('READY', $report->asMap()['database'] ?? null);
    }
}
