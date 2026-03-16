<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\Http;

class MonitorDeepFace extends BaseMonitorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:deepface';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor DeepFace availability and create incident if down';

    public function getUrl(): string
    {
        return 'https://deepface.maiatecnologia.com.br/health';
    }

    public function getComponentId(): int
    {
        return 3;
    }

    public function getMonitorName(): string
    {
        return 'DeepFace';
    }

    public function getPublicName(): string
    {
        return 'DeepFace';
    }

    /**
     * Perform the HTTP request without SSL verification for this specific endpoint.
     */
    protected function performRequest(string $url)
    {
        return Http::timeout($this->timeout)->withoutVerifying()->get($url);
    }
}
