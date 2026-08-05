<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quick task 260805-ohs — limpa de `companies.notes` as linhas que o webhook do
 * HubSpot escrevia automaticamente.
 *
 * `companies.notes` e campo de TEXTO LIVRE do time (Comercial/NovaEmpresa.jsx e
 * Companies/Index.jsx). O webhook anexava ali duas linhas geradas:
 *   - "Contato (HubSpot): {nome}"   (Phase 35 D-04 — o nome ja vive em nome_contato)
 *   - "Serviço (HubSpot): {nome}"   (warning servico_nao_encontrado)
 * As duas escritas foram removidas do controller nesta mesma quick task; esta
 * migration apaga o passivo que ficou no banco.
 *
 * Medicao em producao antes da limpeza: 176 empresas, 11 com `notes` preenchido,
 * 10 contendo APENAS a linha legada e 1 (#328 "Vitrine do Couro - Principal")
 * com texto humano real ("3 CNPJS ATIVOS"). Por isso a limpeza e por LINHA e nao
 * por registro: texto digitado por humano nunca pode ser perdido, mesmo se um dia
 * aparecer misturado com a linha legada.
 *
 * Duas decisoes deliberadas:
 *  1. Usa DB::table() e NAO Eloquent. `Company` tem LogsActivity com 'notes' em
 *     logOnly() — limpar via model criaria entradas em `activity_log` como se um
 *     humano tivesse editado cada empresa, poluindo a auditoria.
 *  2. NAO toca em `updated_at`. Mesmo motivo: isto e faxina de dado derivado, nao
 *     edicao de conteudo; a empresa nao foi "atualizada" do ponto de vista do time.
 *
 * Idempotente: rodar duas vezes nao causa dano (na segunda passada nao ha linha
 * legada para remover e nenhum UPDATE e emitido).
 */
return new class extends Migration
{
    /**
     * Prefixos gerados pelo webhook. A variante sem acento entra por seguranca —
     * o controller sempre gravou "Serviço", mas um banco com collation/encoding
     * diferente pode ter guardado a forma sem acento.
     */
    private const PREFIXOS_LEGADOS = [
        'Contato (HubSpot):',
        'Serviço (HubSpot):',
        'Servico (HubSpot):',
    ];

    public function up(): void
    {
        DB::table('companies')
            ->select('id', 'notes')
            ->whereNotNull('notes')
            ->where('notes', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($empresas) {
                foreach ($empresas as $empresa) {
                    $limpo = $this->removerLinhasLegadas((string) $empresa->notes);

                    // Sem mudanca → nao emite UPDATE (mantem updated_at intacto
                    // e torna a migration idempotente de graca).
                    if ($limpo === (string) $empresa->notes) {
                        continue;
                    }

                    // Sobrou nada → null, nunca string vazia (coerente com o
                    // resto do schema, onde "sem observacao" e NULL).
                    DB::table('companies')
                        ->where('id', $empresa->id)
                        ->update(['notes' => $limpo === '' ? null : $limpo]);
                }
            });
    }

    public function down(): void
    {
        // No-op intencional: o conteudo removido era dado DERIVADO (nome do
        // contato e nome do servico, ambos ainda disponiveis em colunas
        // estruturadas e no hubspot_snapshot). Nao ha o que restaurar, e
        // recriar as linhas seria justamente reintroduzir a poluicao.
    }

    /**
     * Quebra em linhas, descarta as que (apos trim) comecam com um dos prefixos
     * legados, rejunta com "\n" e faz trim do resultado.
     */
    private function removerLinhasLegadas(string $notes): string
    {
        $linhas = preg_split('/\R/u', $notes) ?: [];

        $mantidas = array_filter($linhas, function ($linha) {
            $linhaTrim = trim($linha);

            foreach (self::PREFIXOS_LEGADOS as $prefixo) {
                if (str_starts_with($linhaTrim, $prefixo)) {
                    return false;
                }
            }

            return true;
        });

        return trim(implode("\n", $mantidas));
    }
};
