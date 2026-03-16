<?php

namespace App\Console\Commands;

class MonitorJanelaUnicaLegado extends BaseMonitorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:janela-unica-legado';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor Janela Unica Legado availability and create incident if down';

    public function getUrl(): string
    {
        return 'https://programa.janelaunica.com.br/loginAdmin/';
    }

    public function getComponentId(): int
    {
        return 5;
    }

    public function getMonitorName(): string
    {
        return 'Janela Única Legado';
    }

    public function getPublicName(): string
    {
        return 'Janela Única Legado';
    }
}
