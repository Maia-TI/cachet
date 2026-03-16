<?php

namespace App\Console\Commands;

class MonitorJuFinanceiro extends BaseMonitorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:ju-financeiro';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor JU Financeiro availability and create incident if down';

    public function getUrl(): string
    {
        return 'https://api.financeiro.janelaunica.com.br/up';
    }

    public function getComponentId(): int
    {
        return 2;
    }

    public function getMonitorName(): string
    {
        return 'JU Financeiro';
    }

    public function getPublicName(): string
    {
        return 'JU Financeiro';
    }
}
