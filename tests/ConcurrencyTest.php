<?php

use Hasyirin\Actor\Events\ActionRecorded;
use Hasyirin\Actor\Facades\Actor;
use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\ExampleActor;
use Workbench\App\Models\ExampleResource;

beforeEach(function () {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions.');
    }

    if (DB::getDriverName() === 'sqlite') {
        // Forked processes cannot share an in-memory database.
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'actor-race-database');
        config()->set('database.connections.race', ['driver' => 'sqlite', 'database' => $this->databaseFile, 'prefix' => '']);
        config()->set('database.default', 'race');
        $this->createFixtureTables();
        $this->runPackageMigration();
    }

    $this->alice = ExampleActor::create(['name' => 'alice']);
    $this->bob = ExampleActor::create(['name' => 'bob']);
    $this->bot = ExampleResource::create(['name' => 'bot']);
    $this->resource = ExampleResource::create(['name' => 'a post']);
});

afterEach(function () {
    if (isset($this->databaseFile)) {
        unlink($this->databaseFile);
    }
});

/**
 * @param  list<Closure>  $callbacks
 * @return list<string>
 */
function runAtOnce(array $callbacks): array
{
    $connection = DB::getDefaultConnection();
    DB::disconnect($connection);
    $start = microtime(true) + 0.05;
    $children = [];

    foreach ($callbacks as $callback) {
        $resultFile = tempnam(sys_get_temp_dir(), 'actor-race');
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::purge($connection);

            while (microtime(true) < $start) {
            }

            try {
                $callback();
                file_put_contents($resultFile, 'ok');
            } catch (Throwable $e) {
                file_put_contents($resultFile, $e::class.': '.$e->getMessage());
            }

            // Skip PHPUnit's shutdown in the child, so only the parent cleans up.
            posix_kill(getmypid(), SIGKILL);
        }

        $children[$pid] = $resultFile;
    }

    $errors = [];

    foreach ($children as $pid => $resultFile) {
        pcntl_waitpid($pid, $status);
        $result = file_get_contents($resultFile);
        unlink($resultFile);

        if ($result !== 'ok') {
            $errors[] = $result === '' ? "Process $pid died with status $status." : $result;
        }
    }

    return $errors;
}

it('reports a process that dies as an error', function () {
    expect(runAtOnce([fn () => posix_kill(getmypid(), SIGKILL)]))->toHaveCount(1);
});

it('saves one actor, never a mix of two, when two re-acts race', function () {
    expect($this->bot->getKey())->toBe($this->alice->getKey());
    $valid = [[$this->bob->getMorphClass(), $this->bob->getKey()], [$this->bot->getMorphClass(), $this->bot->getKey()]];
    $errors = [];
    $mixed = [];

    foreach (range(1, 30) as $round) {
        Actor::act($this->resource, 'approved', $this->alice);

        array_push($errors, ...runAtOnce([
            fn () => Actor::act($this->resource, 'approved', $this->bob),
            fn () => Actor::act($this->resource, 'approved', $this->bot),
        ]));

        $action = Actor::findAction($this->resource, 'approved');

        if (! in_array([$action->actor_type, $action->actor_id], $valid)) {
            $mixed[] = "$action->actor_type#$action->actor_id";
        }
    }

    expect($errors)->toBe([])
        ->and($mixed)->toBe([]);
});

it('saves one row without errors when two first acts race', function () {
    $errors = [];
    $counts = [];

    foreach (range(1, 30) as $round) {
        Action::query()->delete();

        array_push($errors, ...runAtOnce([
            fn () => Actor::act($this->resource, 'approved', $this->bob),
            fn () => Actor::act($this->resource, 'approved', $this->bot),
        ]));

        $counts[] = Action::query()->count();
    }

    expect($errors)->toBe([])
        ->and(array_unique($counts))->toBe([1]);
});

it('saves one row without errors when six processes act on the same action', function (string $case) {
    $actors = array_map(fn (int $number) => ExampleActor::create(['name' => "actor $number"]), range(1, 6));
    $errors = [];

    foreach (range(1, 10) as $round) {
        $resource = $case === 'first act' ? ExampleResource::create(['name' => "post $round"]) : $this->resource;

        array_push($errors, ...runAtOnce(array_map(
            fn (Model $actor) => fn () => Actor::act($resource, 'approved', $actor),
            $actors,
        )));

        expect(Action::query()->ofResource($resource)->count())->toBe(1);
    }

    expect($errors)->toBe([]);
})->with(['first act', 're-act']);

it('dispatches ActionRecorded once per act when six processes act on the same action', function () {
    $actors = array_map(fn (int $number) => ExampleActor::create(['name' => "actor $number"]), range(1, 6));

    $errors = runAtOnce(array_map(fn (Model $actor) => function () use ($actor) {
        $recorded = 0;
        Event::listen(ActionRecorded::class, function () use (&$recorded) {
            $recorded++;
        });

        foreach (range(1, 20) as $act) {
            Actor::act($this->resource, 'approved', $actor);
        }

        if ($recorded !== 20) {
            throw new RuntimeException("Recorded $recorded events for 20 acts.");
        }
    }, $actors));

    expect($errors)->toBe([]);
});

it('saves every row without errors when four processes act on different resources', function () {
    $errors = [];

    foreach (range(1, 10) as $round) {
        $resources = array_map(fn (int $number) => ExampleResource::create(['name' => "post $round-$number"]), range(1, 4));

        array_push($errors, ...runAtOnce(array_map(
            fn (ExampleResource $resource) => fn () => Actor::act($resource, 'approved', $this->alice),
            $resources,
        )));
    }

    expect($errors)->toBe([])
        ->and(Action::query()->count())->toBe(40);
});

it('act() returns its row while another process keeps deleting it', function () {
    $until = microtime(true) + 0.5;

    $errors = runAtOnce([
        function () use ($until) {
            while (microtime(true) < $until) {
                Actor::act($this->resource, 'approved', $this->bob);
            }
        },
        function () use ($until) {
            while (microtime(true) < $until) {
                try {
                    Action::query()->delete();
                } catch (QueryException) {
                    // Only errors from act() count here.
                }
            }
        },
    ]);

    expect($errors)->toBe([]);
});

it('does not deadlock when first acts on two new resources run in callers’ transactions', function () {
    $actInTransaction = fn (ExampleResource $resource, Model $actor) => DB::transaction(function () use ($resource, $actor) {
        Actor::act($resource, 'approved', $actor);
        usleep(2000);
    });
    $errors = [];

    foreach (range(1, 30) as $round) {
        $first = ExampleResource::create(['name' => "first $round"]);
        $second = ExampleResource::create(['name' => "second $round"]);

        array_push($errors, ...runAtOnce([
            fn () => $actInTransaction($first, $this->bob),
            fn () => $actInTransaction($second, $this->bot),
        ]));
    }

    expect($errors)->toBe([]);
});

it('act() in a transaction with retries succeeds when another connection saves the same action first', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite blocks a second writer while a transaction holds a read lock.');
    }

    config()->set('database.connections.other', config('database.connections.'.DB::getDefaultConnection()));
    $attempts = 0;

    $action = DB::transaction(function () use (&$attempts) {
        ExampleResource::query()->findOrFail($this->resource->getKey());

        if (++$attempts === 1) {
            DB::connection('other')->table('actions')->insert([
                'resource_type' => $this->resource->getMorphClass(),
                'resource_id' => $this->resource->getKey(),
                'name' => 'approved',
                'actor_type' => $this->bob->getMorphClass(),
                'actor_id' => $this->bob->getKey(),
                'acted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Actor::act($this->resource, 'approved', $this->alice);
    }, 3);

    expect($action->actor_id)->toBe($this->alice->getKey())
        ->and(Action::query()->sole()->actor_id)->toBe($this->alice->getKey());
});
