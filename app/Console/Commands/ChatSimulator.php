<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Agent\AgentRuntime;
use App\Support\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agentes:chat {negocio : Slug del negocio} {--nuevo : Empezar una conversación desde cero}')]
#[Description('Conversa con el agente desde la terminal, sin WhatsApp (usa el LLM real)')]
class ChatSimulator extends Command
{
    private const SIMULATOR_WA_ID = '570000000000';

    public function handle(AgentRuntime $runtime, TenantContext $tenants): int
    {
        $tenant = Tenant::where('slug', $this->argument('negocio'))->first();

        if (! $tenant) {
            $this->error('No existe ese negocio.');

            return self::FAILURE;
        }

        return $tenants->run($tenant, function () use ($tenant, $runtime) {
            $contact = Contact::firstOrCreate(['wa_id' => self::SIMULATOR_WA_ID], ['name' => null]);

            if ($this->option('nuevo')) {
                $contact->conversations()->delete();
                $contact->update(['lead_data' => null, 'stage' => 'nuevo', 'name' => null, 'opted_out' => false]);
            }

            $conversation = $contact->conversations()->where('status', '!=', Conversation::STATUS_CLOSED)->latest('id')->first()
                ?? Conversation::create(['contact_id' => $contact->id, 'agent_id' => $tenant->agent?->id]);

            $this->info("Chat con el agente de {$tenant->name}. Escribe \"salir\" para terminar.");

            while (true) {
                $text = trim((string) $this->ask('Tú'));

                if ($text === '' || in_array(strtolower($text), ['salir', 'exit'], true)) {
                    return self::SUCCESS;
                }

                $conversation->update(['status' => Conversation::STATUS_BOT, 'window_expires_at' => now()->addDay()]);
                $conversation->messages()->create([
                    'direction' => Message::IN, 'author' => 'contact', 'content' => $text, 'status' => 'received',
                ]);

                $reply = $runtime->handle($conversation->fresh());

                if ($reply->message) {
                    $reply->message->update(['status' => 'sent']);
                    $this->line('<fg=green>Agente:</> '.$reply->message->content);
                    $this->line(sprintf(
                        '<fg=gray>  [%s · %s · %s tokens · $%s USD]</>',
                        $reply->outcome,
                        $reply->message->source,
                        $reply->message->input_tokens + $reply->message->output_tokens,
                        number_format($reply->message->cost_usd, 5),
                    ));
                }

                if ($reply->outcome === 'handed_off') {
                    $this->warn('  La conversación pasó a un humano. En el simulador se reactiva el bot al siguiente mensaje.');
                }
            }
        });
    }
}
