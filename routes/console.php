<?php

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of Closure based console commands
| Each Closure is bound to a command instance allowing for a simple
| approach to interacting with the application's IO methods.
|
*/

// Scheduled tasks are registered exclusively in app/Console/Kernel.php.
// Do NOT register backup:cleanup here — a second registration overrode the
// config-driven retention hours (audit C4).
