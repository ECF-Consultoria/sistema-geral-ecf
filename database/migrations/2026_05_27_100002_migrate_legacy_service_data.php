<?php

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 14 / Plan 14-02 — Migration 2: data migration legacy → contratos_servico.
 *
 * Itera todas as empresas existentes e cria os `contratos_servico` derivados
 * dos campos legacy:
 *   (1) Para cada item em `companies.service_type` (JSON array): 1 contrato
 *       com servico_id mapeado via D-01 e valor_contratado=0 (classificação).
 *   (2) Se `companies.additional_service` preenchido: 1 contrato adicional
 *       com servico_id resolvido via normalização Title Case (D-02) e
 *       valor_contratado=additional_service_price.
 *   (3) Empresas com ambos vazios: NENHUM contrato (estado neutro — D-04 item 3).
 *
 * Datas (D-04):
 *   - data_contratacao = contract_start ?? company.created_at
 *   - data_vencimento  = contract_end (nullable)
 *
 * Idempotência (RESEARCH §1 + Pitfall 3):
 *   - Catálogo: firstOrCreate por nome
 *   - Contratos: guard explícito `where(company_id, servico_id, valor_contratado)->exists()`
 *     antes de criar. Re-run NÃO duplica.
 *
 * Atomicidade: TODO o trabalho dentro de DB::transaction — se falhar no meio,
 * nenhum contrato fica criado (`php artisan migrate` falha loud).
 *
 * Performance (Pitfall 2): chunk(100) para evitar OOM com muitas empresas.
 *
 * Sistema entra em COEXISTÊNCIA após esta migration:
 *   - Campos legacy de `companies` PERMANECEM populados
 *   - `contratos_servico` PASSA a ser populada
 *   - Runtime AINDA lê dos legacy (refator dos consumers só no Plan 14-03)
 *   - Drop das colunas legacy só no Plan 14-06
 *
 * Per D-01, D-02, D-04, D-06.
 */
return new class extends Migration
{
    /**
     * Mapeamento D-01 (verbatim do CONTEXT.md) — slug legacy → nome canônico no catálogo.
     *
     * @var array<string, string>
     */
    private array $mapaLegacy = [
        'publicacao'  => 'Publicação',
        'polos'       => 'Polos',
        'assessoria'  => 'Assessoria',
        'incubadora'  => 'Incubadora',
        'publicidade' => 'Publicidade',
        'gestao'      => 'Gestão',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            // Cache nome → id para evitar N queries de resolução (RESEARCH §1).
            // Recalculado a cada chunk seria desnecessário — o catálogo é estático
            // dentro de uma migração (Migration 1 já populou tudo).
            $servicosByNome = Servico::pluck('id', 'nome');

            Company::chunk(100, function ($companies) use ($servicosByNome) {
                foreach ($companies as $company) {
                    $this->migrarContratosLegacy($company, $servicosByNome);
                }
            });
        });
    }

    public function down(): void
    {
        // Down não reverte dados criados — contratos_servico permanecem.
        // Para reverter completamente, restaurar backup do banco.
        // Migration de schema (drop) faz própria reversão via recriação de
        // colunas no Plan 14-06 (migration _100003).
    }

    /**
     * Cria contratos_servico para uma empresa, derivando dos campos legacy.
     *
     * Regra cumulativa (D-04):
     *   - 1 contrato por slug em service_type (valor_contratado=0)
     *   - 1 contrato adicional se additional_service preenchido
     *
     * @param  Company                     $company        Empresa fonte dos dados legacy.
     * @param  Collection<string, int>     $servicosByNome Mapeamento nome → id (cache).
     */
    private function migrarContratosLegacy(Company $company, Collection $servicosByNome): void
    {
        // Phase 14 (Frente B / Plan 14-06): esta migration foi escrita assumindo que
        // Company.php tinha os casts 'service_type' => 'array' e 'contract_start' /
        // 'contract_end' => 'date'. O Plan 14-06 removeu esses casts (preparando o drop
        // das colunas), então qualquer execução de `migrate:fresh` em dev/CI/novo VPS
        // chamaria $company->contract_start->toDateString() em string crua e quebraria
        // com "Call to a member function toDateString() on string". E foreach((array)
        // $company->service_type) sobre a string JSON crua produziria um array de UM
        // elemento (a JSON inteira), nunca casando com o mapaLegacy.
        //
        // Solução: ler os 3 campos via DB::table cru (independente de cast) e parsear
        // explicitamente. Em produção esta migration JÁ rodou antes do drop —
        // este fix garante apenas a paridade em fresh installs.

        // ─── Leitura raw dos 3 campos legacy (sem depender de cast Eloquent) ──
        // Em fresh installs com colunas ainda presentes (esta migration roda ANTES da
        // 100003 que dropa as colunas), as 3 colunas existem e value() retorna o conteúdo
        // bruto (string JSON para service_type, string 'Y-m-d H:i:s' para datas).
        $rawServiceType = DB::table('companies')->where('id', $company->id)->value('service_type');
        $rawStart       = DB::table('companies')->where('id', $company->id)->value('contract_start');
        $rawEnd         = DB::table('companies')->where('id', $company->id)->value('contract_end');

        // service_type: decodifica JSON explicitamente. Defensivo contra (a) string
        // JSON normal, (b) já-array (caso casts voltem no futuro), (c) null/vazio.
        $slugs = is_string($rawServiceType) && $rawServiceType !== ''
            ? (json_decode($rawServiceType, true) ?: [])
            : (is_array($rawServiceType) ? $rawServiceType : []);

        // Datas: parse via Carbon explícito (Pitfall 8 — normaliza para 'Y-m-d').
        $dataContratacao = $rawStart
            ? \Carbon\Carbon::parse($rawStart)->toDateString()
            : $company->created_at->toDateString();

        $dataVencimento = $rawEnd
            ? \Carbon\Carbon::parse($rawEnd)->toDateString()
            : null;

        // ─── (1) Contratos derivados de service_type (JSON array decodificada acima) ──
        foreach ($slugs as $slug) {
            $nome = $this->mapaLegacy[$slug] ?? null;

            // Slug desconhecido (ex: dado sujo histórico) → ignora.
            if (! $nome || ! isset($servicosByNome[$nome])) {
                continue;
            }

            $servicoId = (int) $servicosByNome[$nome];

            // Guard de idempotência: combinação exata (company + servico + valor=0).
            // Pitfall 3: empresa com service_type=['polos'] já migrada não duplica
            // contratos no segundo run.
            $jaExiste = ContratoServico::where('company_id', $company->id)
                ->where('servico_id', $servicoId)
                ->where('valor_contratado', 0)
                ->exists();

            if ($jaExiste) {
                continue;
            }

            ContratoServico::create([
                'company_id'       => $company->id,
                'servico_id'       => $servicoId,
                'valor_contratado' => 0,
                'data_contratacao' => $dataContratacao,
                'data_vencimento'  => $dataVencimento,
                'ativo'            => true,
            ]);
        }

        // ─── (2) Contrato adicional derivado de additional_service ──────────
        // Phase 14 (Frente B / Plan 14-06): leitura raw via DB::table para preservar
        // independência de casts Eloquent (consistência com leitura de service_type
        // acima). Em fresh installs com Company.php sem casts, $company->additional_service
        // ainda funcionaria (string nativa), mas usar a mesma estratégia raw evita
        // surpresas se futuros refactors removerem $fillable ou adicionarem accessors.
        $rawAdditionalService      = DB::table('companies')->where('id', $company->id)->value('additional_service');
        $rawAdditionalServicePrice = DB::table('companies')->where('id', $company->id)->value('additional_service_price');

        $additionalRaw = (string) ($rawAdditionalService ?? '');

        if (trim($additionalRaw) === '') {
            // Sem additional_service → nada a fazer aqui (D-04 itens 3 e 4).
            return;
        }

        // D-02: normalização agressiva (trim + Title Case UTF-8) garante dedupe:
        // "consultoria", "Consultoria", "  CONSULTORIA  " viram todos "Consultoria".
        $nomeAdicional = mb_convert_case(trim($additionalRaw), MB_CASE_TITLE, 'UTF-8');

        // Pitfall 4 (decimal:2 retorna string em SQLite): (float) explícito
        // antes de operações aritméticas e comparações.
        $valorAdicional = (float) ($rawAdditionalServicePrice ?? 0);

        // find-or-create no catálogo para o nome adicional. valor_padrao do
        // catálogo recebe o valor real (heurística — usuário pode ajustar via UI).
        $servicoAdicional = Servico::firstOrCreate(
            ['nome' => $nomeAdicional],
            [
                'valor_padrao'  => $valorAdicional,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
            ],
        );

        // Atualiza cache local para futuros lookups dentro da mesma transaction.
        $servicosByNome->put($nomeAdicional, $servicoAdicional->id);

        // Guard de idempotência por (company + servico + valor_contratado exato).
        $jaExisteAdicional = ContratoServico::where('company_id', $company->id)
            ->where('servico_id', $servicoAdicional->id)
            ->where('valor_contratado', $valorAdicional)
            ->exists();

        if ($jaExisteAdicional) {
            return;
        }

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servicoAdicional->id,
            'valor_contratado' => $valorAdicional,
            'data_contratacao' => $dataContratacao,
            'data_vencimento'  => $dataVencimento,
            'ativo'            => true,
        ]);
    }
};
