<?php

namespace App\Console\Commands;

class MonitorJanelaUnica extends BaseMonitorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:janela-unica';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor Janela Unica availability and create incident if down';

    public function getUrl(): string
    {
        return 'https://janelaunica.com.br/up';
    }

    public function getComponentId(): int
    {
        return 1;
    }

    public function getMonitorName(): string
    {
        return 'Janela Única';
    }

    public function getPublicName(): string
    {
        return 'Janela Única';
    }
}
