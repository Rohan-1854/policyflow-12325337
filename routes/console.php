<?php

use Illuminate\Support\Facades\Schedule;

// Commands live in app/Console/Commands and are auto-discovered.
// Run `php artisan schedule:work` locally to enable periodic reconciliation.
Schedule::command('policyflow:reconcile')->everyFiveMinutes()->withoutOverlapping();
