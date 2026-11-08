---
title: "Queue workers on Citadel"
description: "How a Laravel app runs queued jobs on Citadel today: the scheduler-driven short worker, the timeout rule, the deploy restart, and the supervised worker we are asking for."
---

# Queue workers on Citadel

Laravel apps on Citadel dispatch jobs to a queue, but the platform runs no queue worker: one container per app, Apache in front, and nothing that keeps a long-lived `php artisan queue:work` alive. A queue with no worker is a promise with no keeper. This page covers what works today (a short worker driven by the scheduler), how a release restarts workers, what has not been tested yet, and the request text for the supervised worker we want from the Citadel team. Finish [Citadel setup](citadel-setup.md) first if the app is not wired up yet.

## What works today

Laravel's own guidance is that a permanent `queue:work` needs a process monitor to keep it running ([queues](https://laravel.com/docs/13.x/queues#supervisor-configuration)), and Citadel has none. So the worker rides the one thing every app already runs: the scheduler's single cron entry, which runs as the app user each minute ([scheduling](https://laravel.com/docs/13.x/scheduling#running-the-scheduler)). An app that already schedules tasks has this entry; the worker reuses it, so nothing new runs on the server.

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

The schedule then starts a short worker every minute. In `routes/console.php` (Laravel 11+, where the scheduling docs put the task schedule):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(10);
```

Each piece is documented:

- `--stop-when-empty` processes all jobs and then exits gracefully, the option Laravel documents for a container that should shut down once the queue is empty ([queues](https://laravel.com/docs/13.x/queues#processing-all-queued-jobs-and-then-exiting)).
- `--max-time=50` caps a run at 50 seconds, so a run started before a release cannot outlive the code it booted with ([queues](https://laravel.com/docs/13.x/queues#processing-jobs-for-a-given-number-of-seconds)).
- `everyMinute()` runs the task on each scheduler tick ([scheduling](https://laravel.com/docs/13.x/scheduling#schedule-frequency-options)).
- `withoutOverlapping(10)` skips the tick while the previous run is still going. The lock lives in the cache and expires after 24 hours by default; the explicit 10-minute expiry bounds a lock that a crashed run leaves behind ([scheduling](https://laravel.com/docs/13.x/scheduling#preventing-task-overlaps)).

Because every run boots fresh and exits, there is no stale code and no memory growth between runs. The trade is latency: a job waits for the next minute tick, and a busy queue drains one burst per minute.

> [!WARNING]
> Keep every job's timeout below its connection's `retry_after`, at least several seconds shorter. If a job is still processing past `retry_after`, the connection releases it back onto the queue and another run picks it up, so the job runs twice. The worker's `--timeout` is 60 seconds by default, the shipped `retry_after` is 90, and a job may name its own timeout with the `Timeout` attribute ([worker timeouts](https://laravel.com/docs/13.x/queues#worker-timeouts), [job expiration](https://laravel.com/docs/13.x/queues#job-expiration)). Job timeouts need the PCNTL extension in the image ([max job attempts and timeout](https://laravel.com/docs/13.x/queues#max-job-attempts-and-timeout)). Give jobs a finite number of tries: `--tries=0` retries forever, and a permanently failing job would then spin the queue every minute.

With the `database` driver the jobs live in a table that Laravel's default migration creates (`0001_01_01_000002_create_jobs_table`); an app without it generates one with `php artisan make:queue-table` ([queues](https://laravel.com/docs/13.x/queues)).

## Deploys

Workers do not notice new code until they restart, so a release restarts them ([queue workers and deployment](https://laravel.com/docs/13.x/queues#queue-workers-and-deployment)). Laravel documents two commands, and the current release has both:

- `php artisan queue:restart` tells every worker to exit gracefully after its current job, so no job in flight is lost. The restart signal is stored in the cache, so the app needs a working cache driver.
- `php artisan reload` terminates the long-running services (queue workers, Reverb, Octane) in one command; outside Laravel Cloud you configure the process monitor that restarts them yourself ([deployment](https://laravel.com/docs/13.x/deployment#reloading-services)).

For the short worker above, the restart is mostly belt-and-braces: a run started before the release ends within its 50-second cap regardless, and the command makes sure it ends now instead of finishing the rest of the window on old code. For a supervised worker the restart is the whole mechanism, because the process never exits on its own.

Squire's `scripts/release.sh` does this for you in its Laravel block, after `laravel migrate` and `laravel optimize` and before the Oracle check and the smoke test: the code is checked out and optimized, the smoke test has not run yet, and a worker restarted here comes up on the new code before the release declares success. The step runs `php artisan reload` where Laravel has it, and otherwise `queue:restart` and `schedule:interrupt`, each only when that command exists. It is report only (a failed restart is a warning), and `SQUIRE_ARTISAN_RESTART=0` turns it off. See [The release script](release-script.md) for the step order.

## Not yet tested on Citadel

The short worker is the stack's plan, not a measured fact: the combination of `--stop-when-empty`, `--max-time` and `withoutOverlapping` puts three documented features together, and it has not been run on Citadel yet. How fast a queue actually drains, how the cron entry behaves on the platform, and what the logs look like are all unmeasured, and the Citadel test is tracked separately; this page will say what it found when it lands.

## The supervised worker we are asking for

The text below is ready to send to the Citadel team. It asks for the long-lived worker Laravel expects, with the restart hook that makes deploys safe.

```text
Request: a supervised queue worker per app

We would like a supported way to run one long-lived process per app, kept alive by a process monitor that restarts it when it exits.

What it runs:

    php artisan queue:work --sleep=3 --tries=3 --max-time=3600

One worker per app, more where an app needs the throughput.

Why: Laravel's queue worker is a long-lived process that a process monitor must keep running, and it does not pick up new code until it is restarted. Today our apps run a short worker from the scheduler every minute, which works but is a stopgap: a job waits up to a minute for the next tick, a run that fills its minute overlaps the next one (the overlap guard then skips ticks and adds more delay), and the framework reboots every tick.

Restart on deploy: the Quick Update post-script (where scripts/release.sh already runs) would call php artisan reload, which terminates the worker after its current job finishes; the monitor then starts it again on the new code. The stop is graceful: on SIGTERM the worker finishes the job it is processing before it exits.

Timeouts: each job's timeout stays below the connection's retry_after (at least several seconds shorter), so a frozen job is killed before the queue retries it and runs twice. The image needs the PCNTL extension for job timeouts.

Logs: worker output where the app's other logs are, viewable in the dashboard, so a dead worker is visible instead of silent.

Cache: the restart signal is stored in the application cache, so the app's cache store must survive the restart (the file store does, inside one container).
```

## Next

Read [The release script](release-script.md) for the step order a restart fits into, or [Citadel setup](citadel-setup.md) for the dashboard steps that make the cron entry exist.
