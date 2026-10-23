# AI usage disclosure

> **Before submitting:** complete the two sections marked _TODO (candidate)_ with what you
> actually did. Everything else describes how this repository was produced and is accurate
> as written; edit it if your own process differed.

## 1. Tools used

- **Claude (Anthropic)**, used through the Claude app with a code workspace. It produced the
  first full implementation from the assignment brief: Laravel skeleton, migrations, pricing,
  state machine, services, mock insurer, tests, README and example scripts.
- Laravel and PHPUnit documentation for checking framework behaviour.

## 2. Concrete ways AI helped

1. **Design of the failure handling.** Classifying insurer responses into *definitely not
   processed* (503/429, retry), *declined* (400/422, stop) and *unknown* (timeout, 500/502/504,
   malformed 2xx, reconcile first), and turning that into the
   `NEEDS_RECONCILIATION -> status lookup` path instead of treating a timeout as a failure.
2. **Concurrency details.** Using the unique index as the real duplicate guard and catching the
   violation *outside* the rolled-back transaction (needed on PostgreSQL); not holding a row
   lock during the insurer call; recording and applying a callback in one transaction so a
   redelivery cannot slip through between the two.
3. **Test scaffolding.** A scripted fake gateway (`tests/Fakes/ScriptedInsurerGateway.php`) that
   counts insurer calls, which is what lets the tests prove "no second issue call" rather than
   only checking the final status.

## 3. Incorrect or incomplete suggestions encountered

- **Aggregate on an ordered relation.** The first version computed the next attempt number with
  `$this->attempts()->max('sequence')`, where `attempts()` has an `orderBy`. That runs on SQLite
  but produces `SELECT max(...) ... ORDER BY sequence`, which PostgreSQL rejects and MySQL can
  reject under `ONLY_FULL_GROUP_BY`. Fixed with `reorder()` (`PolicyRequest::nextAttemptSequence`,
  `IssuanceService::isCurrentIssueAttempt`). The SQLite test suite would not have caught this,
  which is why the README asks for a MySQL test run.
- **Self-calling HTTP mock.** The initial plan was for the app to call its own mock insurer over
  HTTP. With `php artisan serve` (one worker) a request that calls the same server waits for
  itself and hangs. The default became an in-process gateway that goes through the same response
  mapper; the HTTP driver is kept for multi-worker servers.
- **Floats for money.** Multiplying `1.80 * 1.25` style values as floats was avoided; multipliers
  are integer hundredths and rounding happens once, half-up, on integers.
- **Tests not executed by the AI.** The AI's sandbox could not download Composer packages, so it
  could not run the Laravel feature tests. It ran the framework-free unit tests (pricing, state
  machine, response mapping: 43 passing) and syntax-checked every file. The feature tests were
  run locally afterwards (see section 4).

## 4. How the output was verified

_TODO (candidate): replace this list with what you actually ran and saw, for example:_

- [ ] `composer install` and `php artisan migrate` on MySQL 8 completed without errors
- [ ] `php artisan test` (SQLite): all tests passing - paste the summary line, e.g. `Tests: NN passed`
- [ ] `php artisan test` against a MySQL `policyflow_test` database: all passing
- [ ] `bash examples/demo.sh` / `examples\demo.ps1`: outputs match the README
- [ ] Manually checked the brief's example: `POST /api/policy-requests` returns premium `225.00`
- [ ] Read through `IssuanceService`, `IntakeService` and `ProviderCallbackService` and can
      explain each phase (lock/decide, call, record) without notes
- [ ] Changed one mock scenario by hand (e.g. `MOCK_INSURER_TEMPORARY_FAILURES=2`) and saw the
      expected behaviour

## 5. Independent judgment

_TODO (candidate): one or two decisions you made or changed yourself, e.g. something you
simplified, a default you disagreed with, or a test you added after reading the code._
