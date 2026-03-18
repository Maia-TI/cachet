<?php

/*
 * This file is part of Cachet.
 *
 * (c) Alt Three Services Limited
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Console\Commands\MonitorJanelaUnica;
use App\Console\Commands\MonitorJuFinanceiro;
use App\Console\Commands\MonitorDeepFace;
use App\Console\Commands\MonitorJuConecta;
use App\Console\Commands\MonitorJanelaUnicaLegado;
use App\Console\Commands\MonitorJuSuporte;
use Illuminate\Support\Facades\Schedule;

Schedule::command(MonitorJanelaUnica::class)->everyThirtySeconds();
Schedule::command(MonitorJuFinanceiro::class)->everyThirtySeconds();
Schedule::command(MonitorDeepFace::class)->everyThirtySeconds();
Schedule::command(MonitorJuConecta::class)->everyThirtySeconds();
Schedule::command(MonitorJanelaUnicaLegado::class)->everyThirtySeconds();
Schedule::command(MonitorJuSuporte::class)->everyThirtySeconds();
