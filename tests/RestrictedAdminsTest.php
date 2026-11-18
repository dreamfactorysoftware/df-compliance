<?php

namespace DreamFactory\Core\Testing;

use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdminRole;
use DreamFactory\Core\Compliance\Http\Middleware\HandleRestrictedAdmin;
use DreamFactory\Core\Compliance\Components\RestrictedAdmin;
use DreamFactory\Core\Compliance\Enums\VerbsMask;
use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Models\Role;
use DreamFactory\Core\Models\RoleServiceAccess;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Utility\JWTUtilities;
use DreamFactory\Core\Models\App;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use DreamFactory\Core\Exceptions\ForbiddenException;
use \Mockery as m;
use ServiceManager;

class RestrictedAdminsTest extends TestCase
{
    private $rootAdminData = [
        'name' => 'John Doe',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'jdoe@dreamfactory.com',
        'password' => 'test1234',
        'security_question' => 'Make of your first car?',
        'security_answer' => 'mazda',
        'is_active' => true,
        'is_sys_admin' => true,
        'is_root_admin' => true
    ];

    private $raAdminData = [
        'name' => 'John_Doe',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'jdoeRA@dreamfactory.com',
        'password' => 'test1234',
        'security_question' => 'Make of your first car?',
        'security_answer' => 'mazda',
        'is_sys_admin' => true,
        'is_active' => true,
    ];

    private $adminRequstData = [
        'email' => 'test@dreamfactory.com',
        'password' => 'test1234',
        'security_question' => 'Your favourite band?',
        'security_answer' => 'The Pretty Reckless',
        'is_active' => true,
        'is_restricted_admin' => true,
        'access_by_tabs' => ['users']
    ];

    private $nonRootAdminData = [
        'name' => 'John Doe',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'jdoeNonRoot@dreamfactory.com',
        'password' => 'test1234',
        'security_question' => 'Make of your first car?',
        'security_answer' => 'mazda',
        'is_active' => true
    ];

    public function tearDown(): void
    {
        AdminUser::setConsoleCommand(false);
        Session::flush();
        \DreamFactory\Core\Models\User::resetAdminExists();
        \DreamFactory\Core\Models\User::whereIn('email', ['task-admin@dreamfactory.com', 'two-step@dreamfactory.com', 'plain-user@dreamfactory.com'])->delete();
        AdminUser::whereEmail('jdoe@dreamfactory.com')->delete();
        AdminUser::whereEmail('jdoeRA@dreamfactory.com')->delete();
        AdminUser::whereEmail('jdoeNonRoot@dreamfactory.com')->delete();
        Role::whereName('jdoeRA@dreamfactory.com\'s role')->delete();
        parent::tearDown();
    }

    public function testRestrictedAdminCreation()
    {
        $role = $this->createRARole();
        $roleId = $role->id;
        $this->assertTrue(1 === Role::whereName($this->raAdminData['email'] . '\'s role')->count());
        $this->assertTrue(7 === RoleServiceAccess::whereRoleId($roleId)->count());
    }

    public function testOnlyRootAdminCanModifyRARole()
    {
        $role = $this->createRARole();
        $this->assertTrue(1 === Role::whereName($this->raAdminData['email'] . '\'s role')->count());
        $this->assertTrue(7 === RoleServiceAccess::whereRoleId($role->id)->count());

        // Modify RA role
        $restrictedAdmin = AdminUser::whereEmail($this->raAdminData['email'])->first();
        $apiKey = App::find(1)->api_key;
        Session::setUserInfoWithJWT($restrictedAdmin);
        $token = JWTUtilities::makeJWTByUser($restrictedAdmin->id, $restrictedAdmin->email);
        $rq = Request::create("http://localhost/api/v2/system/role/" . $role->id, "PUT", ['id' => $role->id,], [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('PUT', 'api/{version}/{service}/{resource?}', []))
                ->where(['resource' => '.*'])
                ->bind($rq);
        });
        $nextCalled = false;
        $middleware = new HandleRestrictedAdminRole();
        $response = $middleware->handle($rq, function () use (&$nextCalled) {
            $nextCalled = true;
        });
        $this->assertFalse($nextCalled);
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('You do not have permission to modify restricted admin roles. Please contact your root administrator.',
            json_decode($response->getContent(), true)['error']['message']);
    }

    public function testNonRootAdminsCannotManageAdmins()
    {
        AdminUser::create($this->nonRootAdminData);
        $nonRootAdmin = AdminUser::whereEmail($this->nonRootAdminData['email'])->first();
        $nonRootAdmin->is_sys_admin = true;
        $nonRootAdmin->save();
        Session::setUserInfoWithJWT($nonRootAdmin);
        $response = $this->get('/api/v2/system/admin');
        $response->assertStatus(403);
        $this->assertEquals('Only root admin can manage admins.', $response->json('error.message'));

        $response = $this->get('/api/v2/system/admin/profile');
        $response->assertStatus(200);

        $role = $this->createRARole();
        Session::setUserInfoWithJWT($nonRootAdmin);
        $response = $this->delete('/api/v2/system/role/' . $role->id);
        $response->assertStatus(403);
        $this->assertEquals('You do not have permission to modify restricted admin roles. Please contact your root administrator.', $response->json('error.message'));

        Session::setUserInfoWithJWT($nonRootAdmin);
        $response = $this->json('PATCH', '/api/v2/system/role', ['resource' => [['id' => $role->id, 'description' => 'changed']]]);
        $response->assertStatus(403);
        $this->assertEquals('You do not have permission to modify restricted admin roles. Please contact your root administrator.', $response->json('error.message'));

        Session::setUserInfoWithJWT($nonRootAdmin);
        $response = $this->json('PATCH', '/api/v2/system/role', [['id' => $role->id, 'description' => 'changed']]);
        $response->assertStatus(403);
        $this->assertEquals('You do not have permission to modify restricted admin roles. Please contact your root administrator.', $response->json('error.message'));
    }

    /**
     * The root-admin authorization must gate the downstream handler.
     * A non-root admin POSTing to system/admin must be rejected BEFORE $next
     * runs, so the handler never commits an admin row.
     */
    public function testNonRootAdminRejectedBeforeHandlerRuns()
    {
        AdminUser::create($this->nonRootAdminData);
        $nonRootAdmin = AdminUser::whereEmail($this->nonRootAdminData['email'])->first();
        $nonRootAdmin->is_sys_admin = true;
        $nonRootAdmin->save();
        Session::setUserInfoWithJWT($nonRootAdmin);
        $token = JWTUtilities::makeJWTByUser($nonRootAdmin->id, $nonRootAdmin->email);
        $apiKey = App::find(1)->api_key;

        $rq = Request::create("http://localhost/api/v2/system/admin", "POST", $this->adminRequstData, [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('POST', 'api/{version}/{service}/{resource?}', []))->bind($rq);
        });

        $nextCalled = false;
        $middleware = new HandleRestrictedAdmin();
        $response = $middleware->handle($rq, function () use (&$nextCalled) {
            $nextCalled = true;
            return m::mock('Illuminate\Http\Response');
        });

        // The handler ($next) must never run: the write is prevented, not undone.
        $this->assertFalse($nextCalled, 'Downstream handler ran before authorization; write would commit.');
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals('Only root admin can manage admins.', json_decode($response->getContent(), true)['error']['message']);
    }

    /**
     * Admin paths are matched in canonical form.
     */
    public function testAdminPathsAreMatchedInCanonicalForm()
    {
        $nonRootAdmin = $this->makeAdmin($this->nonRootAdminData);
        Session::setUserInfoWithJWT($nonRootAdmin);

        $urls = [
            'http://session.localhost/api/v2/system/admin',
            'http://password.localhost/api/v2/system/admin',
            'http://localhost/api/v2/SYSTEM/admin',
            'http://localhost/api/v2/system/%61dmin',
        ];
        foreach ($urls as $url) {
            $rq = Request::create($url, 'POST', $this->adminRequstData);
            $nextCalled = false;
            $response = (new HandleRestrictedAdmin())->handle($rq, function () use (&$nextCalled) {
                $nextCalled = true;
                return m::mock('Illuminate\Http\Response');
            });
            $this->assertFalse($nextCalled, "Handler ran for $url");
            $this->assertEquals(403, $response->getStatusCode(), "Not rejected: $url");
        }
    }

    /**
     * The model guard holds on every route, because it does not look at the
     * route. Only a root admin may create, change or delete an admin.
     */
    public function testModelGuardAllowsOnlyRootToManageAdmins()
    {
        $root = $this->makeAdmin($this->rootAdminData, true);
        $nonRoot = $this->makeAdmin($this->nonRootAdminData);
        $other = $this->makeAdmin($this->raAdminData);

        $denied = function (callable $change) {
            try {
                $change();
            } catch (ForbiddenException $e) {
                return true;
            }
            return false;
        };
        $newAdmin = function () {
            $user = new \DreamFactory\Core\Models\User();
            $user->is_sys_admin = true;
            return $user;
        };

        Session::setUserInfoWithJWT($nonRoot);
        $this->assertTrue($denied(function () use ($newAdmin) {
            AdminUser::authorizeChange($newAdmin());
        }), 'non-root created an admin');
        $this->assertTrue($denied(function () use ($other) {
            $other->first_name = 'changed';
            AdminUser::authorizeChange($other);
        }), 'non-root changed another admin');
        $this->assertTrue($denied(function () use ($other) {
            AdminUser::authorizeChange($other->fresh(), true);
        }), 'non-root deleted another admin');
        $this->assertTrue($denied(function () use ($nonRoot) {
            $self = $nonRoot->fresh();
            $self->is_root_admin = true;
            AdminUser::authorizeChange($self);
        }), 'non-root made itself root');
        $this->assertFalse($denied(function () use ($nonRoot) {
            $self = $nonRoot->fresh();
            $self->first_name = 'mine';
            AdminUser::authorizeChange($self);
        }), 'non-root could not edit its own record');

        Session::setUserInfoWithJWT($root);
        $this->assertFalse($denied(function () use ($newAdmin) {
            AdminUser::authorizeChange($newAdmin());
        }), 'root could not create an admin');
        $this->assertFalse($denied(function () use ($other) {
            AdminUser::authorizeChange($other->fresh(), true);
        }), 'root could not delete an admin');
    }

    /**
     * With no session user, only sign-in fields may change on an admin
     * outside the password and sign-in endpoints, and nothing may create an
     * admin or change the admin flags.
     */
    public function testModelGuardWithoutSessionUser()
    {
        $this->makeAdmin($this->rootAdminData, true);
        $other = $this->makeAdmin($this->raAdminData);
        Session::flush();

        $this->assertTrue($this->refused(function () {
            $user = new \DreamFactory\Core\Models\User();
            $user->is_sys_admin = true;
            AdminUser::authorizeChange($user);
        }), 'admin created without a session user');
        $this->assertTrue($this->refused(function () use ($other) {
            $self = $other->fresh();
            $self->is_sys_admin = false;
            AdminUser::authorizeChange($self);
        }), 'admin flag changed without a session user');
        $this->assertTrue($this->refused(function () use ($other) {
            $self = $other->fresh();
            $self->first_name = 'changed';
            AdminUser::authorizeChange($self);
        }), 'admin profile changed without a session user');
        $this->assertTrue($this->refused(function () use ($other) {
            AdminUser::authorizeChange($other->fresh(), true);
        }), 'admin deleted without a session user');
        $this->assertFalse($this->refused(function () use ($other) {
            $self = $other->fresh();
            $self->last_login_date = date('Y-m-d H:i:s');
            $self->confirm_code = null;
            AdminUser::authorizeChange($self);
        }), 'sign-in fields refused without a session user');
        $this->assertTrue($this->refused(function () use ($other) {
            $self = $other->fresh();
            $self->password = 'another-password-1234';
            AdminUser::authorizeChange($self);
        }), 'admin password changed without a session user outside the password endpoints');
    }

    /**
     * Password and sign-in fields of an admin may change on the password and
     * sign-in endpoints, whoever is signed in. Other endpoints and artisan
     * commands, including scheduled tasks, may not change them.
     */
    public function testPasswordFieldsChangeOnPasswordAndSignInEndpoints()
    {
        $this->makeAdmin($this->rootAdminData, true);
        $nonRoot = $this->makeAdmin($this->nonRootAdminData);
        $other = $this->makeAdmin($this->raAdminData);
        $resetByCode = function () use ($other) {
            $self = $other->fresh();
            $self->confirm_code = null;
            $self->password = 'another-password-1234';
            AdminUser::authorizeChange($self);
        };
        $originalRequest = app('request');

        try {
            AdminUser::setConsoleCommand(null);
            Session::flush();
            foreach (['user/password', 'system/admin/password', 'user/session', 'system/admin/session'] as $path) {
                app()->instance('request', Request::create('http://localhost/api/v2/' . $path, 'POST'));
                $this->assertTrue(AdminUser::isCredentialRequest(), $path);
                $this->assertFalse($this->refused($resetByCode), 'password refused on ' . $path);
            }

            Session::setUserInfoWithJWT($nonRoot);
            app()->instance('request', Request::create('http://localhost/api/v2/user/password', 'POST'));
            $this->assertFalse($this->refused($resetByCode), 'password reset refused while another admin is signed in');
            $this->assertTrue($this->refused(function () use ($other) {
                $self = $other->fresh();
                $self->first_name = 'changed';
                AdminUser::authorizeChange($self);
            }), 'non-root changed another admin profile on the password endpoint');

            Session::flush();
            foreach (['system/admin/' . $other->id, 'system/user', 'user/password/extra', 'user/profile', 'system/script'] as $path) {
                app()->instance('request', Request::create('http://localhost/api/v2/' . $path, 'PATCH'));
                $this->assertFalse(AdminUser::isCredentialRequest(), $path);
                $this->assertTrue($this->refused($resetByCode), 'password changed on ' . $path);
            }

            app()->instance('request', Request::create('http://localhost/api/v2/user/password', 'POST'));
            foreach (['df:scheduled-request', 'queue:work', 'schedule:run'] as $command) {
                AdminUser::setConsoleCommand($command);
                $this->assertFalse(AdminUser::isCredentialRequest(), $command);
                $this->assertTrue($this->refused($resetByCode), 'password changed by ' . $command);
            }
        } finally {
            app()->instance('request', $originalRequest);
        }
    }

    /**
     * Restricted admin roles, their service access and lookups, and an
     * admin's role assignments and lookups are changed only by a root admin.
     * Records of ordinary users and roles are not affected.
     */
    public function testAdminRolesAndRelatedRecordsNeedRoot()
    {
        $root = $this->makeAdmin($this->rootAdminData, true);
        $nonRoot = $this->makeAdmin($this->nonRootAdminData);
        $other = $this->makeAdmin($this->raAdminData);
        $plain = \DreamFactory\Core\Models\User::create(['name' => 'Plain User', 'email' => 'plain-user@dreamfactory.com']);
        $appId = App::query()->value('id');
        $serviceId = \DreamFactory\Core\Models\Service::query()->value('id');
        AdminUser::setConsoleCommand('queue:work');

        \DB::beginTransaction();
        try {
            Session::setUserInfoWithJWT($root);
            $role = Role::create(['name' => 'admin-role-' . uniqid(), 'is_active' => 1]);
            $assignment = \DreamFactory\Core\Models\UserAppRole::create(['user_id' => $other->id, 'app_id' => $appId, 'role_id' => $role->id]);
            $access = RoleServiceAccess::create(['role_id' => $role->id, 'service_id' => $serviceId, 'component' => '*', 'verb_mask' => 1, 'requestor_mask' => 1]);
            $plainRole = Role::create(['name' => 'plain-role-' . uniqid(), 'is_active' => 1]);

            $changes = [
                'role'            => function () use ($role) {
                    $mine = $role->fresh();
                    $mine->description = 'changed';
                    $mine->save();
                },
                'role delete'     => function () use ($role) {
                    $role->fresh()->delete();
                },
                'service access'  => function () use ($role, $serviceId) {
                    RoleServiceAccess::create(['role_id' => $role->id, 'service_id' => $serviceId, 'component' => 'admin/*', 'verb_mask' => 31, 'requestor_mask' => 3]);
                },
                'access update'   => function () use ($access) {
                    $mine = $access->fresh();
                    $mine->verb_mask = 31;
                    $mine->save();
                },
                'access move'     => function () use ($access, $plainRole) {
                    $mine = $access->fresh();
                    $mine->role_id = $plainRole->id;
                    $mine->save();
                },
                'role lookup'     => function () use ($role) {
                    \DreamFactory\Core\Models\RoleLookup::create(['role_id' => $role->id, 'name' => 'admin_role_value', 'value' => 'x']);
                },
                'assignment'      => function () use ($assignment, $plainRole) {
                    $mine = $assignment->fresh();
                    $mine->role_id = $plainRole->id;
                    $mine->save();
                },
                'assignment delete' => function () use ($assignment) {
                    $assignment->fresh()->delete();
                },
                'new assignment'  => function () use ($nonRoot, $appId, $role) {
                    \DreamFactory\Core\Models\UserAppRole::create(['user_id' => $nonRoot->id, 'app_id' => $appId, 'role_id' => $role->id]);
                },
                'user lookup'     => function () use ($other) {
                    \DreamFactory\Core\Models\UserLookup::create(['user_id' => $other->id, 'name' => 'admin_value', 'value' => 'x']);
                },
            ];

            foreach ([$nonRoot, null] as $caller) {
                $caller ? Session::setUserInfoWithJWT($caller) : Session::flush();
                foreach ($changes as $name => $change) {
                    $this->assertTrue($this->refused($change), ($caller ? 'non-root' : 'no session user') . ' changed ' . $name);
                }
            }
            $this->assertNull($role->fresh()->description);
            $this->assertEquals(1, $access->fresh()->verb_mask);
            $this->assertEquals($role->id, $assignment->fresh()->role_id);

            Session::setUserInfoWithJWT($nonRoot);
            $this->assertFalse($this->refused(function () use ($plainRole, $plain, $appId, $serviceId) {
                $plainRole->description = 'changed';
                $plainRole->save();
                RoleServiceAccess::create(['role_id' => $plainRole->id, 'service_id' => $serviceId, 'component' => '*', 'verb_mask' => 1, 'requestor_mask' => 1]);
                \DreamFactory\Core\Models\UserAppRole::create(['user_id' => $plain->id, 'app_id' => $appId, 'role_id' => $plainRole->id]);
                \DreamFactory\Core\Models\UserLookup::create(['user_id' => $plain->id, 'name' => 'plain_value', 'value' => 'x']);
            }), 'non-root could not change ordinary roles and users');

            Session::setUserInfoWithJWT($root);
            foreach ($changes as $name => $change) {
                if ('role delete' !== $name && 'assignment delete' !== $name) {
                    $this->assertFalse($this->refused($change), 'root could not change ' . $name);
                }
            }

            // Sign-in services apply their configured roles to the user signing in.
            Session::flush();
            \DreamFactory\Core\Models\UserAppRole::whereUserId($other->id)->delete();
            $this->assertFalse($this->refused(function () use ($other, $plainRole) {
                \DreamFactory\Core\Models\User::applyDefaultUserAppRole($other, $plainRole->id);
            }), 'sign-in role assignment refused');
            $this->assertTrue(\DreamFactory\Core\Models\UserAppRole::whereUserId($other->id)->exists());
        } finally {
            \DB::rollBack();
        }
    }

    /**
     * Sign-in fields may change on another admin even while a different,
     * non-root user is signed in (signing in again with another account).
     */
    public function testModelGuardAllowsSignInFieldsForAnyCaller()
    {
        $this->makeAdmin($this->rootAdminData, true);
        $nonRoot = $this->makeAdmin($this->nonRootAdminData);
        $other = $this->makeAdmin($this->raAdminData);
        Session::setUserInfoWithJWT($nonRoot);

        $this->assertFalse($this->refused(function () use ($other) {
            $self = $other->fresh();
            $self->last_login_date = date('Y-m-d H:i:s');
            AdminUser::authorizeChange($self);
        }), 'sign-in fields refused');
        $this->assertTrue($this->refused(function () use ($other) {
            $self = $other->fresh();
            $self->password = 'another-password-1234';
            AdminUser::authorizeChange($self);
        }), 'non-root changed another admin password');
    }

    /**
     * The first admin of a new install is saved twice: created (and marked
     * root because no admin exists yet), then given admin rights. Both saves
     * run with the model check active.
     */
    public function testFirstAdminCanBeCreatedWithModelCheckActive()
    {
        // Start from an install with no admins; the transaction restores the
        // database afterwards.
        \DB::beginTransaction();
        try {
            \DreamFactory\Core\Models\User::query()->update(['is_sys_admin' => 0, 'is_root_admin' => 0]);
            Session::flush();
            \DreamFactory\Core\Models\User::resetAdminExists();
            AdminUser::setConsoleCommand('queue:work');

            $user = \DreamFactory\Core\Models\User::create([
                'name'       => 'First Admin',
                'first_name' => 'First',
                'last_name'  => 'Admin',
                'email'      => 'first-admin@dreamfactory.com',
                'is_active'  => 1,
            ]);
            $user->password = 'first-admin-password-1234';
            $user->is_sys_admin = 1;
            $user->save();

            $saved = AdminUser::whereEmail('first-admin@dreamfactory.com')->first();
            $this->assertTrue((bool)$saved->is_sys_admin, 'first admin has no admin rights');
            $this->assertTrue((bool)$saved->is_root_admin, 'first admin is not root');
        } finally {
            \DB::rollBack();
        }
    }

    /**
     * Scheduled tasks and other console work are checked like web requests.
     * Operator commands and the test runner are not.
     */
    public function testConsoleCommandsOtherThanOperatorCommandsAreChecked()
    {
        $this->makeAdmin($this->rootAdminData, true);
        $nonRoot = $this->makeAdmin($this->nonRootAdminData);
        $other = $this->makeAdmin($this->raAdminData);

        AdminUser::setConsoleCommand(null);
        $this->assertTrue(AdminUser::isOperatorConsole());
        AdminUser::setConsoleCommand('df:root_admin');
        $this->assertTrue(AdminUser::isOperatorConsole());
        AdminUser::setConsoleCommand('df:scheduled-request');
        $this->assertFalse(AdminUser::isOperatorConsole());

        Session::setUserInfoWithJWT($nonRoot);
        $this->assertTrue($this->refused(function () use ($other) {
            $other->fresh()->delete();
        }), 'scheduled task deleted an admin as non-root');
        $this->assertTrue(AdminUser::adminExistsById($other->id));

        Session::flush();
        $this->assertTrue($this->refused(function () {
            $user = new \DreamFactory\Core\Models\User(['name' => 'Task Admin', 'email' => 'task-admin@dreamfactory.com']);
            $user->is_sys_admin = true;
            $user->save();
        }), 'scheduled task created an admin without a session user');
        $this->assertFalse(\DreamFactory\Core\Models\User::whereEmail('task-admin@dreamfactory.com')->exists());
    }

    /**
     * An admin create that is refused at the second save leaves no user
     * behind.
     */
    public function testRefusedAdminCreateLeavesNoUser()
    {
        $this->makeAdmin($this->rootAdminData, true);
        $nonRoot = $this->makeAdmin($this->nonRootAdminData);
        AdminUser::setConsoleCommand('df:scheduled-request');
        Session::setUserInfoWithJWT($nonRoot);

        $this->assertTrue($this->refused(function () {
            $user = \DreamFactory\Core\Models\User::create(['name' => 'Two Step', 'email' => 'two-step@dreamfactory.com']);
            $user->is_sys_admin = 1;
            $user->save();
        }), 'non-root created an admin');
        $this->assertFalse(\DreamFactory\Core\Models\User::whereEmail('two-step@dreamfactory.com')->exists());

        $this->assertFalse($this->refused(function () {
            \DreamFactory\Core\Models\User::create(['name' => 'Plain User', 'email' => 'plain-user@dreamfactory.com']);
        }), 'non-root could not create an ordinary user');
        $this->assertTrue(\DreamFactory\Core\Models\User::whereEmail('plain-user@dreamfactory.com')->exists());
    }

    /**
     * Only a root admin may save a scheduled task that runs as another
     * admin. Tasks that run as the caller or as an ordinary user are not
     * affected.
     */
    public function testScheduledTaskRunningAsAnotherAdminNeedsRoot()
    {
        $taskClass = AdminUser::SCHEDULER_TASK_MODEL;
        if (!class_exists($taskClass)) {
            $this->markTestSkipped('df-scheduler is not installed.');
        }

        $root = $this->makeAdmin($this->rootAdminData, true);
        $nonRoot = $this->makeAdmin($this->nonRootAdminData);
        $other = $this->makeAdmin($this->raAdminData);
        $plain = \DreamFactory\Core\Models\User::create(['name' => 'Plain User', 'email' => 'plain-user@dreamfactory.com']);
        AdminUser::setConsoleCommand('queue:work');

        \DB::beginTransaction();
        try {
            $task = function ($userId) use ($taskClass) {
                return new $taskClass([
                    'name'       => 'run-as-' . $userId . '-' . uniqid(),
                    'service_id' => \DreamFactory\Core\Models\Service::query()->value('id'),
                    'app_id'     => App::query()->value('id'),
                    'user_id'    => $userId,
                    'component'  => 'admin',
                    'verb_mask'  => 1,
                    'frequency'  => 5,
                ]);
            };

            Session::setUserInfoWithJWT($nonRoot);
            foreach ([$root->id, $other->id] as $userId) {
                $this->assertTrue($this->refused(function () use ($task, $userId) {
                    $task($userId)->save();
                }), 'non-root saved a task that runs as admin ' . $userId);
            }
            foreach ([$nonRoot->id, $plain->id, null] as $userId) {
                $this->assertFalse($this->refused(function () use ($task, $userId) {
                    $task($userId)->save();
                }), 'non-root could not save a task that runs as ' . var_export($userId, true));
            }

            Session::setUserInfoWithJWT($root);
            $saved = $task($root->id);
            $this->assertFalse($this->refused(function () use ($saved) {
                $saved->save();
            }), 'root could not save a task that runs as root');

            Session::setUserInfoWithJWT($nonRoot);
            $this->assertTrue($this->refused(function () use ($saved) {
                $saved->fresh()->update(['component' => 'admin/1']);
            }), 'non-root changed a task that runs as root');
            $this->assertTrue($this->refused(function () use ($saved, $root) {
                $mine = $saved->fresh();
                $mine->user_id = $root->id;
                $mine->name = 'renamed';
                $mine->save();
            }), 'non-root renamed a task that runs as root');

            Session::flush();
            $this->assertTrue($this->refused(function () use ($task, $root) {
                $task($root->id)->save();
            }), 'task that runs as root saved without a session user');
        } finally {
            \DB::rollBack();
        }
    }

    private function refused(callable $change)
    {
        try {
            $change();
        } catch (ForbiddenException $e) {
            return true;
        }

        return false;
    }

    private function makeAdmin(array $data, $root = false)
    {
        AdminUser::create($data);
        $admin = AdminUser::whereEmail($data['email'])->first();
        $admin->is_sys_admin = true;
        $admin->is_root_admin = $root;
        $admin->save();

        return $admin;
    }

    public function testConfigTabGrantsServerSettingAccess()
    {
        $role = $this->createRARole(['config']);
        $access = RoleServiceAccess::whereRoleId($role->id)
            ->whereServiceId(ServiceManager::getServiceIdByName('system'))
            ->whereComponent('server_setting/*')
            ->first();
        $this->assertNotNull($access);
        $this->assertEquals(VerbsMask::getFullAccessMask(), $access->verb_mask);
    }

    public function testRoleWithoutServerSettingRowKeepsConfigTab()
    {
        // a restricted admin created before server_setting/* joined the Config tab
        $role = $this->createRARole(['config']);
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->delete();
        $this->assertContains('config', RestrictedAdmin::getAccessibleTabsByRoleId($role->id));
    }

    public function testResaveGrantsServerSettingToExistingConfigAdmin()
    {
        $role = $this->createRARole(['config']);
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->delete();
        // a root admin narrowed one row of the tab; a re-save must not add the wide one back
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('cors/*')->update(['requestor_mask' => 1]);

        // root admin saves the admin again with the same tabs
        $root = AdminUser::whereEmail($this->rootAdminData['email'])->first();
        Session::setUserInfo(['id' => $root->id, 'email' => $root->email, 'is_sys_admin' => true]);
        (new RestrictedAdmin($this->raAdminData['email'], ['config']))->updateRestrictedAdminRole();

        $this->assertSame(1, RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->count());
        $this->assertSame(1, RoleServiceAccess::whereRoleId($role->id)->whereComponent('cors/*')->count());
    }

    public function testResaveKeepsNarrowedServerSettingRow()
    {
        $role = $this->createRARole(['config']);
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->update(['verb_mask' => 1]);

        $root = AdminUser::whereEmail($this->rootAdminData['email'])->first();
        Session::setUserInfo(['id' => $root->id, 'email' => $root->email, 'is_sys_admin' => true]);
        (new RestrictedAdmin($this->raAdminData['email'], ['config']))->updateRestrictedAdminRole();

        $rows = RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int)$rows[0]->verb_mask);
    }

    public function testRemovingConfigTabRevokesServerSettingAccess()
    {
        $role = $this->createRARole(['config', 'users']);
        $this->assertSame(1, RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->count());

        // root admin saves the admin again without the Config tab
        $root = AdminUser::whereEmail($this->rootAdminData['email'])->first();
        Session::setUserInfo(['id' => $root->id, 'email' => $root->email, 'is_sys_admin' => true]);
        (new RestrictedAdmin($this->raAdminData['email'], ['users']))->updateRestrictedAdminRole();

        $this->assertSame(0, RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->count());
        $tabs = RestrictedAdmin::getAccessibleTabsByRoleId($role->id);
        $this->assertNotContains('config', $tabs);
        $this->assertContains('users', $tabs);
    }

    public function testTabsWithoutConfigNeverGrantServerSetting()
    {
        $role = $this->createRARole(['apps', 'users', 'services', 'scripts', 'limits', 'scheduler']);
        $this->assertSame(0, RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->count());
    }

    public function testUpgradeIncludesServerSettingForConfigRestrictedAdmin()
    {
        $role = $this->createRARole(['config', 'users']);
        $saved = $this->serverSettingRows($role->id);
        $this->assertCount(1, $saved);
        // a restricted admin created before server_setting/* joined the Config tab
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->delete();
        // a root admin narrowed one row of the tab; the upgrade must keep it as it is
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('cors/*')->update(['requestor_mask' => 1]);
        $before = $this->roleAccessRows($role->id);

        $this->runServerSettingMigration();

        $rows = $this->serverSettingRows($role->id);
        $this->assertCount(1, $rows);
        // same access the admin save grants
        $this->assertSame(
            array_diff_key($saved[0], ['id' => 0]),
            array_diff_key($rows[0], ['id' => 0])
        );
        $this->assertSame((int)ServiceManager::getServiceIdByName('system'), (int)$rows[0]['service_id']);
        $this->assertSame(VerbsMask::getFullAccessMask(), (int)$rows[0]['verb_mask']);
        $this->assertSame($before, array_values(array_filter($this->roleAccessRows($role->id), function ($row) {
            return $row['component'] !== 'server_setting/*';
        })));
        $tabs = RestrictedAdmin::getAccessibleTabsByRoleId($role->id);
        $this->assertContains('config', $tabs);
        $this->assertContains('users', $tabs);
        $this->assertSame(1, Role::whereName($this->raAdminData['email'] . '\'s role')->count());
    }

    public function testUpgradeMigrationRunsAgainWithoutChanges()
    {
        $role = $this->createRARole(['config']);
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->delete();

        $this->runServerSettingMigration();
        $once = $this->roleAccessRows($role->id);
        $this->runServerSettingMigration();

        $this->assertSame($once, $this->roleAccessRows($role->id));
        $this->assertCount(1, $this->serverSettingRows($role->id));
    }

    public function testUpgradeKeepsNarrowedServerSettingRow()
    {
        $role = $this->createRARole(['config']);
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->update(['verb_mask' => 1]);
        $before = $this->roleAccessRows($role->id);

        $this->runServerSettingMigration();

        $this->assertSame($before, $this->roleAccessRows($role->id));
    }

    public function testUpgradeLeavesRestrictedAdminsWithoutConfigTab()
    {
        $role = $this->createRARole(['users', 'scripts']);
        $before = $this->roleAccessRows($role->id);

        $this->runServerSettingMigration();

        $this->assertSame($before, $this->roleAccessRows($role->id));
        $this->assertCount(0, $this->serverSettingRows($role->id));
    }

    public function testUpgradeSkipsRoleNotNamedForTheAdmin()
    {
        $role = $this->createRARole(['config']);
        RoleServiceAccess::whereRoleId($role->id)->whereComponent('server_setting/*')->delete();
        Role::whereId($role->id)->update(['name' => 'renamed admin role']);
        $before = $this->roleAccessRows($role->id);

        try {
            $this->runServerSettingMigration();

            $this->assertSame($before, $this->roleAccessRows($role->id));
            $this->assertSame(0, Role::whereName($this->raAdminData['email'] . '\'s role')->count());
        } finally {
            Role::whereName('renamed admin role')->delete();
        }
    }

    private function runServerSettingMigration()
    {
        // the migration runs from `php artisan migrate`, without a session user
        Session::flush();
        AdminUser::setConsoleCommand('migrate');
        $migration = require dirname(__DIR__) .
            '/database/migrations/2026_11_18_000100_include_server_settings_for_config_restricted_admins.php';
        $migration->up();
        AdminUser::setConsoleCommand(false);
    }

    private function roleAccessRows($roleId)
    {
        return RoleServiceAccess::whereRoleId($roleId)->orderBy('id')
            ->get(['id', 'service_id', 'component', 'verb_mask', 'requestor_mask', 'filters', 'filter_op'])
            ->toArray();
    }

    private function serverSettingRows($roleId)
    {
        return array_values(array_filter($this->roleAccessRows($roleId), function ($row) {
            return $row['component'] === 'server_setting/*';
        }));
    }

    public function createRARole(array $tabs = ['users'])
    {
        // Create a RA role
        $rootAdmin = AdminUser::create($this->rootAdminData);
        $rootAdmin->is_root_admin = true;
        $rootAdmin->is_sys_admin = true;
        $rootAdmin->save();
        $restrictedAdmin = AdminUser::create($this->raAdminData);
        $restrictedAdmin->is_sys_admin = true;
        $restrictedAdmin->save();
        Session::setUserInfoWithJWT($rootAdmin);
        $token = JWTUtilities::makeJWTByUser($rootAdmin->id, $rootAdmin->email);
        $apiKey = App::find(1)->api_key;
        $rq = Request::create("http://localhost/api/v2/system/admin", "POST", ['access_by_tabs' => $tabs] + $this->adminRequstData, [], [], [], []);
        $rq->headers->set('HTTP_X_DREAMFACTORY_SESSION_TOKEN', $token);
        $rq->headers->set('X-Dreamfactory-API-Key', $apiKey);
        $rq->setRouteResolver(function () use ($rq) {
            return (new Route('POST', 'api/{version}/{service}/{resource?}', []))->bind($rq);
        });
        $response = m::mock('Illuminate\Http\Response')->shouldReceive('getOriginalContent')->once()->andReturn(['id' => $restrictedAdmin->id])->getMock();
        $response->shouldReceive('isSuccessful')->once()->andReturn(true);
        $middleware = new HandleRestrictedAdmin();
        $middleware->handle($rq, function () use ($response) {
            return $response;
        });
        $role = Role::whereName($this->raAdminData['email'] . '\'s role')->first();
        return $role;
    }
}