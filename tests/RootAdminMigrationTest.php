<?php

namespace DreamFactory\Core\Compliance\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Root administrator designation migration. Plain unit tests against an
 * in-memory SQLite database: no booted application needed.
 */
class RootAdminMigrationTest extends TestCase
{
    const MIGRATION = '2026_11_18_000000_designate_root_admin_when_none_set.php';

    /** @var Capsule */
    private $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();

        $manager = $this->capsule->getDatabaseManager();
        $container->instance('db', $manager);
        $container->bind('db.schema', function () use ($manager) {
            return $manager->connection()->getSchemaBuilder();
        });
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $this->schema()->create('user', function (Blueprint $t) {
            $t->increments('id');
            $t->string('email');
            $t->boolean('is_sys_admin')->default(0);
            $t->boolean('is_active')->default(1);
            $t->boolean('is_root_admin')->default(0);
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    private function schema()
    {
        return $this->capsule->getConnection()->getSchemaBuilder();
    }

    private function migration(): Migration
    {
        return require dirname(__DIR__) . '/database/migrations/' . self::MIGRATION;
    }

    private function addUser(int $id, bool $admin, bool $active = true, bool $root = false): void
    {
        $this->capsule->table('user')->insert([
            'id'            => $id,
            'email'         => "user{$id}@example.com",
            'is_sys_admin'  => $admin,
            'is_active'     => $active,
            'is_root_admin' => $root,
        ]);
    }

    private function rootIds(): array
    {
        return $this->capsule->table('user')
            ->where('is_root_admin', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int)$id)
            ->all();
    }

    public function testMigrationRunsAfterOtherPackageMigrations()
    {
        $files = array_map('basename', glob(dirname(__DIR__) . '/database/migrations/*.php'));
        sort($files);

        $this->assertSame(self::MIGRATION, end($files));
    }

    public function testLowestIdActiveAdminBecomesRoot()
    {
        $this->addUser(1, true, false); // inactive admin
        $this->addUser(2, false);       // regular user
        $this->addUser(5, true);
        $this->addUser(3, true);

        $this->migration()->up();

        $this->assertSame([3], $this->rootIds());
    }

    public function testExistingRootIsKept()
    {
        $this->addUser(1, true);
        $this->addUser(2, true, true, true);

        $this->migration()->up();

        $this->assertSame([2], $this->rootIds());
    }

    public function testExistingInactiveRootIsKept()
    {
        $this->addUser(1, true);
        $this->addUser(2, true, false, true);

        $this->migration()->up();

        $this->assertSame([2], $this->rootIds());
    }

    public function testNothingChangesWithoutAdmins()
    {
        $this->addUser(1, false);
        $this->addUser(2, false);

        $this->migration()->up();

        $this->assertSame([], $this->rootIds());
    }

    public function testNothingChangesWithOnlyInactiveAdmins()
    {
        $this->addUser(1, true, false);
        $this->addUser(2, false);

        $this->migration()->up();

        $this->assertSame([], $this->rootIds());
    }

    public function testNothingChangesWithEmptyUserTable()
    {
        $this->migration()->up();

        $this->assertSame(0, $this->capsule->table('user')->count());
    }

    public function testRunningTwiceDesignatesOneRoot()
    {
        $this->addUser(4, true);
        $this->addUser(7, true);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame([4], $this->rootIds());
    }

    public function testOnlyRootFlagChanges()
    {
        $this->addUser(2, true);
        $this->addUser(3, false);
        $before = $this->capsule->table('user')->orderBy('id')->get()->map(fn ($r) => (array)$r)->all();

        $this->migration()->up();

        $after = $this->capsule->table('user')->orderBy('id')->get()->map(fn ($r) => (array)$r)->all();
        $before[0]['is_root_admin'] = 1;
        $this->assertEquals($before, $after);
    }

    public function testDownLeavesDesignationInPlace()
    {
        $this->addUser(1, true);

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $this->assertSame([1], $this->rootIds());
    }

    public function testSkipsWhenRootColumnIsMissing()
    {
        $this->schema()->drop('user');
        $this->schema()->create('user', function (Blueprint $t) {
            $t->increments('id');
            $t->boolean('is_sys_admin')->default(0);
            $t->boolean('is_active')->default(1);
        });
        $this->capsule->table('user')->insert(['id' => 1, 'is_sys_admin' => true, 'is_active' => true]);

        $this->migration()->up();

        $this->assertFalse($this->schema()->hasColumn('user', 'is_root_admin'));
    }
}
