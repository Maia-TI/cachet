<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Cachet\Actions\Incident\CreateIncident;
use Cachet\Data\Requests\Incident\CreateIncidentRequestData;
use Cachet\Enums\IncidentStatusEnum;
use Cachet\Enums\ComponentStatusEnum;
use Cachet\Models\Incident;
use Cachet\Models\Component;

class MonitorJuFinanceiro extends Command
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

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = 'https://api.financeiro.janelaunica.com.br/api/ziggy';
        $timeout = 5;

        try {
            $response = Http::timeout($timeout)->get($url);

            if ($response->failed()) {
                $publicMessage = "JU Financeiro está indisponível (HTTP {$response->status()}). Investigação em andamento.";
                $this->handleFailure("JU Financeiro is down (HTTP {$response->status()})", $publicMessage);
                return;
            }

            $publicMessage = "JU Financeiro voltou a operar normalmente.";
            $this->handleSuccess("JU Financeiro is UP (HTTP {$response->status()})", $publicMessage);
        } catch (\Exception $e) {
            $publicMessage = "JU Financeiro está inacessível (timeout/erro de conexão). Investigação em andamento.";
            $this->handleFailure("JU Financeiro is unreachable (Timeout/Error: {$e->getMessage()})", $publicMessage);
        }
    }

    private function handleFailure(string $consoleMessage, string $publicMessage)
    {
        $this->error($consoleMessage);

        $incidentName = 'Incidente: JU Financeiro';

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
            notifications: true, // Notify subscribers
            occurredAt: now()->toDateTimeString(),
            componentId: 2,
            componentStatus: ComponentStatusEnum::major_outage,
        );

        app(CreateIncident::class)->handle($data);

        // Update component status
        Component::find(2)?->update([
            'status' => ComponentStatusEnum::major_outage,
        ]);

        $this->info("Incident created and component status updated.");
    }

    private function handleSuccess(string $consoleMessage, string $publicMessage)
    {
        $incidentName = 'Incidente: JU Financeiro';

        // Check for existing unresolved incident
        $incident = Incident::query()
            ->where('name', $incidentName)
            ->unresolved()
            ->first();

        if ($incident) {
            $downtimeDuration = $this->formatDowntime($incident->created_at);

            $incident->update([
                'status' => IncidentStatusEnum::fixed,
                'message' => $incident->message . "\n\n**Resolvido.** {$publicMessage} Tempo de indisponibilidade: **{$downtimeDuration}**.",
            ]);

            $component = Component::where('name', 'like', '%Financeiro%')->first();
            $componentId = $component ? $component->id : 2;

            // Update component status back to operational
            if ($componentId) {
                Component::find($componentId)?->update([
                    'status' => ComponentStatusEnum::operational,
                ]);
            }

            $this->info("Incident resolved and component status updated to Operational. Downtime: {$downtimeDuration}");
        } else {
            $this->info($consoleMessage);
        }
    }

    private function formatDowntime(Carbon $since): string
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
