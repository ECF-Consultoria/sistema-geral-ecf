<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Phase 111 Plan 111-01 (HUB-API-02) — Comando de diagnóstico da Properties
 * API do HubSpot. Antes de depender de qualquer custom property (hs_mrr etc.)
 * este comando confirma os nomes internos reais da conta ECF — o label
 * visível na UI do HubSpot NEM SEMPRE bate com o nome interno (`name`).
 *
 * GET /crm/v3/properties/{objectType} para cada objeto pedido em --objects
 * (CSV; default deals,companies,contacts,line_items). Imprime, por objeto,
 * uma tabela com nome interno / label / type / fieldType.
 *
 * Resiliência (T-111-02): cada objeto é isolado num try/catch — falha de
 * status (403/404/500) OU exceção de rede (timeout/DNS/TLS via
 * ConnectionException) é reportada com $this->warn() e o comando segue para
 * o próximo objeto. NUNCA propaga a resposta HTTP como exceção (evitaria
 * capturá-la aqui) e sempre retorna exit code 0 (self::SUCCESS), mesmo com falhas
 * parciais — é uma ferramenta de diagnóstico, não deve travar scripts.
 *
 * Segurança (T-111-01): jamais imprime/loga o access token nem a string
 * 'Bearer'. Mensagens de erro contêm só o nome do objeto + status HTTP ou
 * o nome da classe da exceção — nunca a mensagem crua da exceção, que pode
 * conter a URL completa da requisição (incluindo headers/credenciais em
 * alguns clientes HTTP).
 */
class HubspotInspectProperties extends Command
{
    private const BASE = 'https://api.hubapi.com';

    protected $signature = 'hubspot:inspect-properties {--objects=deals,companies,contacts,line_items : CSV dos objetos CRM a inspecionar}';

    protected $description = 'Lista nome interno/label/type/fieldType das propriedades HubSpot via Properties API (nunca loga o token)';

    public function handle(): int
    {
        $objetos = array_filter(array_map('trim', explode(',', (string) $this->option('objects'))));

        if (empty($objetos)) {
            $this->warn('Nenhum objeto informado em --objects.');
            return self::SUCCESS;
        }

        $token = config('services.hubspot.access_token');

        foreach ($objetos as $objeto) {
            $this->info("== {$objeto} ==");

            try {
                $res = Http::withToken($token)->get(self::BASE . "/crm/v3/properties/{$objeto}");

                if (!$res->ok()) {
                    $this->warn("Falha ao consultar propriedades de {$objeto}: HTTP {$res->status()}");
                    continue;
                }

                $results = $res->json('results') ?? [];

                if (empty($results)) {
                    $this->line('  (nenhuma propriedade retornada)');
                    continue;
                }

                $linhas = array_map(static fn (array $prop): array => [
                    $prop['name'] ?? '',
                    $prop['label'] ?? '',
                    $prop['type'] ?? '',
                    $prop['fieldType'] ?? '',
                ], $results);

                $this->table(['name (interno)', 'label', 'type', 'fieldType'], $linhas);
            } catch (\Throwable $e) {
                // Nunca logar $e->getMessage() cru (T-111-01) — pode conter a
                // URL da requisição. Só o nome do objeto + classe da exceção.
                $this->warn("Falha ao consultar propriedades de {$objeto}: " . get_class($e));
                continue;
            }
        }

        return self::SUCCESS;
    }
}
