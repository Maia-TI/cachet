<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Cachet\Actions\Incident\CreateIncident;
use Cachet\Data\Requests\Incident\CreateIncidentRequestData;
use Cachet\Enums\IncidentStatusEnum;
use Cachet\Enums\ComponentStatusEnum;
use Cachet\Models\Incident;
use Cachet\Models\Component;

class MonitorJanelaUnica extends Command
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

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = 'https://janelaunica.com.br/up';
        $timeout = 5;

        try {
            $response = Http::timeout($timeout)->get($url);

            if ($response->failed()) {
                $this->handleFailure("Janela Única is down (HTTP {$response->status()})");
                return;
            }

            $this->handleSuccess("Janela Única is UP (HTTP {$response->status()})");
        } catch (\Exception $e) {
            $this->handleFailure("Janela Única is unreachable (Timeout/Error: {$e->getMessage()})");
        }
    }

    private function handleFailure(string $message)
    {
        $this->error($message);

        $incidentName = 'Janela Única Outage';

        // Check for existing unresolved incident with the same name
        $existingIncident = Incident::query()
            ->where('name', $incidentName)
            ->unresolved() // Requires Incident model to have unresolved scope
            ->exists();

        if ($existingIncident) {
            $this->info("An unresolved incident already exists. Skipping creation.");
            return;
        }

        $this->info("Creating new incident...");

        $data = new CreateIncidentRequestData(
            name: $incidentName,
            status: IncidentStatusEnum::investigating,
            message: $message,
            visible: true,
            stickied: false,
            notifications: true, // Notify subscribers
            occurredAt: now()->toDateTimeString(),
            componentId: 1,
            componentStatus: ComponentStatusEnum::major_outage,
        );

        app(CreateIncident::class)->handle($data);

        // Update component status
        Component::find(1)?->update([
            'status' => ComponentStatusEnum::major_outage,
        ]);

        $this->info("Incident created and component status updated.");
    }

    private function handleSuccess(string $message)
    {
        $incidentName = 'Janela Única Outage';

        // Check for existing unresolved incident
        $incident = Incident::query()
            ->where('name', $incidentName)
            ->unresolved()
            ->first();

        if ($incident) {
            $incident->update([
                'status' => IncidentStatusEnum::fixed,
                'message' => $incident->message . "\n\n**Resolved:** " . $message,
            ]);

            // Update component status back to operational
            Component::find(1)?->update([
                'status' => ComponentStatusEnum::operational,
            ]);

            $this->info("Incident resolved and component status updated to Operational.");
        } else {
            $this->info($message);
        }
    }
}
