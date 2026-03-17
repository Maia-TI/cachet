<?php

namespace App\Console\Commands;

use App\Contracts\MonitorInterface;
use Cachet\Actions\Incident\CreateIncident;
use Cachet\Data\Requests\Incident\CreateIncidentRequestData;
use Cachet\Enums\ComponentStatusEnum;
use Cachet\Enums\IncidentStatusEnum;
use Cachet\Models\Component;
use Cachet\Models\Incident;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

abstract class BaseMonitorCommand extends Command implements MonitorInterface
{
    /**
     * Timeout for the HTTP request in seconds.
     */
    protected int $timeout = 5;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $monitorName = $this->getMonitorName();
        $url = $this->getUrl();
        
        Log::info("Running monitor: {$monitorName} [{$url}]");

        try {
            $this->ensureComponentExists();
        } catch (\Exception $e) {
            Log::warning("Could not ensure component exists for {$monitorName}: {$e->getMessage()}");
        }

        try {
            $response = $this->performRequest($url);

            if ($response->failed()) {
                $status = $response->status();
                $publicMessage = "{$this->getPublicName()} está indisponível (HTTP {$status}). ";
                Log::error("[Monitor Failed] {$monitorName}: HTTP {$status}");
                
                try {
                    $this->handleFailure("{$this->getPublicName()} is down (HTTP {$status})", $publicMessage);
                } catch (\Exception $e) {
                    Log::error("Failed to record failure in DB for {$monitorName}: {$e->getMessage()}");
                }
            } else {
                $status = $response->status();
                $publicMessage = "{$this->getPublicName()} " . ($this->getPublicName() === 'Janela Única' ? 'online.' : 'voltou a operar normalmente.');
                Log::info("[Monitor Success] {$monitorName}: HTTP {$status}");
                
                try {
                    $this->handleSuccess("{$this->getPublicName()} is UP (HTTP {$status})", $publicMessage);
                } catch (\Exception $e) {
                    Log::error("Failed to record success in DB for {$monitorName}: {$e->getMessage()}");
                }
            }
        } catch (\Exception $e) {
            $publicMessage = "{$this->getPublicName()} está inacessível (timeout/erro de conexão). ";
            Log::error("[Monitor Error] {$monitorName}: {$e->getMessage()}");
            
            try {
                $this->handleFailure("{$this->getPublicName()} is unreachable (Timeout/Error: {$e->getMessage()})", $publicMessage);
            } catch (\Exception $e) {
                Log::error("Failed to record reachability error in DB for {$monitorName}: {$e->getMessage()}");
            }
        }

        Log::info("Monitor finished: {$monitorName}");
    }

    /**
     * Ensure the component exists in the database.
     */
    protected function ensureComponentExists(): void
    {
        $component = Component::find($this->getComponentId());

        if (!$component) {
            $this->info("Creating component: {$this->getMonitorName()} (ID: {$this->getComponentId()})");
            
            Component::query()->forceCreate([
                'id' => $this->getComponentId(),
                'name' => $this->getMonitorName(),
                'status' => ComponentStatusEnum::operational,
                'enabled' => true,
                'description' => 'Monitorado automaticamente',
            ]);
        }
    }

    /**
     * Perform the HTTP request. Overridable for special cases (like SSL issues).
     */
    protected function performRequest(string $url)
    {
        return Http::timeout($this->timeout)->get($url);
    }

    /**
     * Handle service failure by creating an incident and updating component status.
     */
    protected function handleFailure(string $consoleMessage, string $publicMessage): void
    {
        Cache::lock("monitor_lock_{$this->getComponentId()}", 10)->get(function () use ($consoleMessage, $publicMessage) {
            Log::error("[Monitor Failure] {$this->getMonitorName()}: {$consoleMessage}");
            $this->error($consoleMessage);

            $incidentName = 'Incidente: ' . $this->getMonitorName();

            // Check for existing unresolved incident with the same name
            $existingIncident = Incident::query()
                ->where('name', $incidentName)
                ->unresolved()
                ->first();

            // Always ensure component status reflects the failure
            Component::find($this->getComponentId())?->update([
                'status' => ComponentStatusEnum::major_outage,
            ]);

            if ($existingIncident) {
                $this->info("An unresolved incident already exists. Skipping creation.");
                return;
            }

            // Check for a recently resolved incident (within 2 minutes) to "merge" the instability
            $recentIncident = Incident::query()
                ->where('name', $incidentName)
                ->where('status', IncidentStatusEnum::fixed)
                ->where('updated_at', '>', now()->subMinutes(2))
                ->orderBy('updated_at', 'desc')
                ->first();

            if ($recentIncident) {
                $this->info("A recently resolved incident exists. Reopening it instead of creating a new one.");
                $recentIncident->update([
                    'status' => IncidentStatusEnum::investigating,
                    'message' => $recentIncident->message . "\n\n**Reaberto por instabilidade recorrente.**\n\n{$publicMessage}",
                ]);
                return;
            }

            $this->info("Creating new incident...");

            $data = new CreateIncidentRequestData(
                name: $incidentName,
                status: IncidentStatusEnum::investigating,
                message: $publicMessage,
                visible: true,
                stickied: false,
                notifications: true,
                occurredAt: now()->toDateTimeString(),
                componentId: $this->getComponentId(),
                componentStatus: ComponentStatusEnum::major_outage,
            );

            app(CreateIncident::class)->handle($data);

            $this->info("Incident created and component status updated.");
        });
    }

    /**
     * Handle service success by resolving existing incidents and updating component status.
     */
    protected function handleSuccess(string $consoleMessage, string $publicMessage): void
    {
        Cache::lock("monitor_lock_{$this->getComponentId()}", 10)->get(function () use ($consoleMessage, $publicMessage) {
            Log::info("[Monitor Success] {$this->getMonitorName()}: {$consoleMessage}");
            $incidentName = 'Incidente: ' . $this->getMonitorName();

            // Check for existing unresolved incident
            $incident = Incident::query()
                ->where('name', $incidentName)
                ->unresolved()
                ->first();

            // Compatibility with old naming patterns if needed (like in MonitorJanelaUnica)
            if (!$incident && $this->getMonitorName() === 'Janela Única') {
                $incident = Incident::query()
                    ->where('name', 'Janela Única Outage')
                    ->unresolved()
                    ->first();
            }

            if ($incident) {
                $downtimeDuration = $this->formatDowntime($incident->created_at);

                $incident->update([
                    'status' => IncidentStatusEnum::fixed,
                    'message' => $incident->message . "\n\n**Resolvido.** {$publicMessage} Tempo de indisponibilidade: **{$downtimeDuration}**.",
                ]);

                // Update component status back to operational
                Component::find($this->getComponentId())?->update([
                    'status' => ComponentStatusEnum::operational,
                ]);

                $this->info("Incident resolved and component status updated to Operational. Downtime: {$downtimeDuration}");
            } else {
                $this->info($consoleMessage);
            }
        });
    }

    /**
     * Format the downtime duration from a starting date.
     */
    protected function formatDowntime(Carbon $since): string
    {
        $diff = $since->diff(Carbon::now());

        $parts = [];
        if ($diff->d > 0) {
            $parts[] = $diff->d . ' ' . ($diff->d === 1 ? 'dia' : 'dias');
        }
        if ($diff->h > 0) {
            $parts[] = $diff->h . ' ' . ($diff->h === 1 ? 'hora' : 'horas');
        }
        if ($diff->i > 0) {
            $parts[] = $diff->i . ' ' . ($diff->i === 1 ? 'minuto' : 'minutos');
        }
        if (empty($parts)) {
            $parts[] = $diff->s . ' ' . ($diff->s === 1 ? 'segundo' : 'segundos');
        }

        return implode(', ', $parts);
    }
}
