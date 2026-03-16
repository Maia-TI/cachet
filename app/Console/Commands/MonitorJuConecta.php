<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Http;

class MonitorJuConecta extends BaseMonitorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:ju-conecta';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor JU Conecta availability and create incident if down';

    public function getUrl(): string
    {
        return 'https://conecta.janelaunica.com.br/api/estatisticas';
    }

    public function getComponentId(): int
    {
        return 4;
    }

    public function getMonitorName(): string
    {
        return 'JU Conecta';
    }

    public function getPublicName(): string
    {
        return 'JU Conecta';
    }

    /**
     * Perform the HTTP request without SSL verification for this specific endpoint.
     */
    protected function performRequest(string $url)
    {
        return Http::timeout($this->timeout)->withoutVerifying()->get($url);
    }
}
