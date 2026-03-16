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

class MonitorJanelaUnicaLegado extends Command
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

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = 'https://programa.janelaunica.com.br/loginAdmin/';
        $timeout = 5;
        $iterations = 6;
        $secondsBetween = 10;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                $response = Http::timeout($timeout)->get($url);

                if ($response->failed()) {
                    $publicMessage = "Janela Única Legado está indisponível (HTTP {$response->status()}). ";
                    $this->handleFailure("Janela Única Legado is down (HTTP {$response->status()})", $publicMessage);
                } else {
                    $publicMessage = "Janela Única Legado está operando normalmente.";
                    $this->handleSuccess("Janela Única Legado is UP (HTTP {$response->status()})", $publicMessage);
                }
            } catch (\Exception $e) {
                $publicMessage = "Janela Única Legado está inacessível (timeout/erro de conexão). ";
                $this->handleFailure("Janela Única Legado is unreachable (Timeout/Error: {$e->getMessage()})", $publicMessage);
            }

            if ($i < $iterations - 1) {
                sleep($secondsBetween);
            }
        }
    }

    private function handleFailure(string $consoleMessage, string $publicMessage)
    {
        $this->error($consoleMessage);

        $incidentName = 'Incidente: Janela Única Legado';

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
            componentId: 5,
            componentStatus: ComponentStatusEnum::major_outage,
        );

        app(CreateIncident::class)->handle($data);

        // Update component status
        Component::find(5)?->update([
            'status' => ComponentStatusEnum::major_outage,
        ]);

        $this->info("Incident created and component status updated.");
    }

    private function handleSuccess(string $consoleMessage, string $publicMessage)
    {
        $incidentName = 'Incidente: Janela Única Legado';

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

            // Update component status back to operational
            Component::find(5)?->update([
                'status' => ComponentStatusEnum::operational,
            ]);

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
