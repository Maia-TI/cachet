<?php

namespace App\Console\Commands;

class MonitorJuSuporte extends BaseMonitorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:ju-suporte';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor JU Suporte availability and create incident if down';

    public function getUrl(): string
    {
        return 'https://suporte.janelaunica.com.br/home';
    }

    public function getComponentId(): int
    {
        return 6;
    }

    public function getMonitorName(): string
    {
        return 'JU Suporte';
    }

    public function getPublicName(): string
    {
        return 'JU Suporte';
    }
}
