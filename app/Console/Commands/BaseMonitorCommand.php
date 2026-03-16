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

abstract class BaseMonitorCommand extends Command implements MonitorInterface
{
    /**
     * Timeout for the HTTP request in seconds.
     */
    protected int $timeout = 5;

    /**
     * Number of iterations to check the service.
     */
    protected int $iterations = 6;

    /**
     * Seconds to wait between iterations.
     */
    protected int $secondsBetween = 10;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $url = $this->getUrl();

        for ($i = 0; $i < $this->iterations; $i++) {
            try {
                $response = $this->performRequest($url);

                if ($response->failed()) {
                    $publicMessage = "{$this->getPublicName()} está indisponível (HTTP {$response->status()}). ";
                    $this->handleFailure("{$this->getPublicName()} is down (HTTP {$response->status()})", $publicMessage);
                } else {
                    $publicMessage = "{$this->getPublicName()} " . ($this->getPublicName() === 'Janela Única' ? 'online.' : 'voltou a operar normalmente.');
                    $this->handleSuccess("{$this->getPublicName()} is UP (HTTP {$response->status()})", $publicMessage);
                }
            } catch (\Exception $e) {
                $publicMessage = "{$this->getPublicName()} está inacessível (timeout/erro de conexão). ";
                $this->handleFailure("{$this->getPublicName()} is unreachable (Timeout/Error: {$e->getMessage()})", $publicMessage);
            }

            if ($i < $this->iterations - 1) {
                sleep($this->secondsBetween);
            }
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
        $this->error($consoleMessage);

        $incidentName = 'Incidente: ' . $this->getMonitorName();

        // Check for existing unresolved incident with the same name
        $existingIncident = Incident::query()
            ->where('name', $incidentName)
            ->unresolved()
            ->exists();

        if ($existingIncident) {
            $this->info("An unresolved incident already exists. Skipping creation.");
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

        // Update component status
        Component::find($this->getComponentId())?->update([
            'status' => ComponentStatusEnum::major_outage,
        ]);

        $this->info("Incident created and component status updated.");
    }

    /**
     * Handle service success by resolving existing incidents and updating component status.
     */
    protected function handleSuccess(string $consoleMessage, string $publicMessage): void
    {
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
