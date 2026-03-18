<?php

namespace App\Jobs;

use App\Contracts\MonitorInterface;
use Cachet\Actions\Incident\CreateIncident;
use Cachet\Data\Requests\Incident\CreateIncidentRequestData;
use Cachet\Enums\ComponentStatusEnum;
use Cachet\Enums\IncidentStatusEnum;
use Cachet\Models\Component;
use Cachet\Models\Incident;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitorHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /**
     * The number of times the job may be attempted.
     * With 3 tries and 30s schedule, this covers a ~1-minute window of confirmed failure.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $monitorName,
        protected string $url,
        protected int $componentId,
        protected string $publicName,
        protected int $timeout = 5
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("[Queue Attempt {$this->attempts()}] Running health check for: {$this->monitorName} [{$this->url}]");

        try {
            $this->ensureComponentExists();
            
            $response = Http::timeout($this->timeout)->get($this->url);

            if ($response->failed()) {
                $status = $response->status();
                Log::warning("[Monitor Attempt Failed] {$this->monitorName}: HTTP {$status}");
                
                if ($this->attempts() < $this->tries) {
                    $this->release($this->backoff);
                    return;
                }

                $publicMessage = "{$this->publicName} está indisponível (HTTP {$status}). ";
                $this->handleFailure("{$this->publicName} is down (HTTP {$status})", $publicMessage);
            } else {
                $status = $response->status();
                $publicMessage = "{$this->publicName} " . ($this->publicName === 'Janela Única' ? 'online.' : 'voltou a operar normalmente.');
                Log::info("[Monitor Attempt Success] {$this->monitorName}: HTTP {$status}");
                
                $this->handleSuccess("{$this->publicName} is UP (HTTP {$status})", $publicMessage);
            }
        } catch (\Exception $e) {
            Log::warning("[Monitor Attempt Error] {$this->monitorName}: {$e->getMessage()}");
            
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);
                return;
            }

            $publicMessage = "{$this->publicName} está inacessível (timeout/erro de conexão). ";
            $this->handleFailure("{$this->publicName} is unreachable (Timeout/Error: {$e->getMessage()})", $publicMessage);
        }
    }

    /**
     * Ensure the component exists in the database.
     */
    protected function ensureComponentExists(): void
    {
        $component = Component::find($this->componentId);

        if (!$component) {
            Log::info("Creating component in queue: {$this->monitorName} (ID: {$this->componentId})");
            
            Component::query()->forceCreate([
                'id' => $this->componentId,
                'name' => $this->monitorName,
                'status' => ComponentStatusEnum::operational,
                'enabled' => true,
                'description' => 'Monitorado automaticamente',
            ]);
        }
    }

    /**
     * Handle service failure.
     */
    protected function handleFailure(string $consoleMessage, string $publicMessage): void
    {
        Cache::lock("monitor_lock_{$this->componentId}", 10)->get(function () use ($consoleMessage, $publicMessage) {
            Log::error("[Queue Failure Confirmed] {$this->monitorName}: {$consoleMessage}");

            $incidentName = 'Incidente: ' . $this->monitorName;

            $existingIncident = Incident::query()
                ->where('name', '=', $incidentName)
                ->unresolved()
                ->first();

            Component::find($this->componentId)?->update([
                'status' => ComponentStatusEnum::major_outage,
            ]);

            if ($existingIncident) {
                return;
            }

            $recentIncident = Incident::query()
                ->where('name', '=', $incidentName)
                ->where('status', '=', IncidentStatusEnum::fixed->value)
                ->where('updated_at', '>', now()->subMinutes(2))
                ->orderBy('updated_at', 'desc')
                ->first();

            if ($recentIncident) {
                $recentIncident->update([
                    'status' => IncidentStatusEnum::investigating,
                    'message' => $recentIncident->message . "\n\n**Reaberto por instabilidade recorrente.**\n\n{$publicMessage}",
                ]);
                return;
            }

            $data = new CreateIncidentRequestData(
                name: $incidentName,
                status: IncidentStatusEnum::investigating,
                message: $publicMessage,
                visible: true,
                stickied: false,
                notifications: true,
                occurredAt: now()->toDateTimeString(),
                componentId: $this->componentId,
                componentStatus: ComponentStatusEnum::major_outage,
            );

            app(CreateIncident::class)->handle($data);
        });
    }

    /**
     * Handle service success.
     */
    protected function handleSuccess(string $consoleMessage, string $publicMessage): void
    {
        Cache::lock("monitor_lock_{$this->componentId}", 10)->get(function () use ($consoleMessage, $publicMessage) {
            Log::info("[Queue Success] {$this->monitorName}: {$consoleMessage}");
            $incidentName = 'Incidente: ' . $this->monitorName;

            $incident = Incident::query()
                ->where('name', '=', $incidentName)
                ->unresolved()
                ->first();

            if (!$incident && $this->monitorName === 'Janela Única') {
                $incident = Incident::query()
                    ->where('name', '=', 'Janela Única Outage')
                    ->unresolved()
                    ->first();
            }

            // Always ensure status is operational on success
            Component::find($this->componentId)?->update([
                'status' => ComponentStatusEnum::operational,
            ]);

            if ($incident) {
                $downtimeDuration = $this->formatDowntime($incident->created_at);

                $incident->update([
                    'status' => IncidentStatusEnum::fixed,
                    'message' => $incident->message . "\n\n**Resolvido.** {$publicMessage} Tempo de indisponibilidade: **{$downtimeDuration}**.",
                ]);
            }
        });
    }

    /**
     * Format the downtime duration.
     */
    protected function formatDowntime(Carbon $since): string
    {
        $diff = $since->diff(Carbon::now());
        $parts = [];
        if ($diff->d > 0) $parts[] = $diff->d . ' ' . ($diff->d === 1 ? 'dia' : 'dias');
        if ($diff->h > 0) $parts[] = $diff->h . ' ' . ($diff->h === 1 ? 'hora' : 'horas');
        if ($diff->i > 0) $parts[] = $diff->i . ' ' . ($diff->i === 1 ? 'minuto' : 'minutos');
        if (empty($parts)) $parts[] = $diff->s . ' ' . ($diff->s === 1 ? 'segundo' : 'segundos');

        return implode(', ', $parts);
    }
}
