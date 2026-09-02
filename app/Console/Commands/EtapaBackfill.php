<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\FluxoEntrada\EtapaTransicaoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * etapa:backfill — migra as ~500 empresas já cadastradas para a máquina de
 * estados da Fase 137 (ETAPA-02), em EXATAMENTE dois baldes (D-04, travado):
 *
 *  1. Empresa que satisfaz o cálculo atual de "em operação"
 *     (`analistaPerformance()` OU `estrategistaPerformance()` não vazio,
 *     idêntico a `CompanyController::index()` linha `em_operacao`) recebe
 *     `etapa = Company::ETAPA_EM_OPERACAO`.
 *  2. Todo o resto fica com `etapa` `NULL` — nasce assim (D-03), este
 *     comando nunca escreve nada para o balde 2.
 *
 * Nunca dedução de etapa intermediária (2..8) a partir de `ContratoAssinatura`
 * ou `Onboarding.status` (D-05): carimbar etapa 7 numa empresa que hoje
 * aparece em "Empresas" seria exatamente como a tela a perde quando a Fase
 * 142 passar a ler `etapa`; etapa intermediária afirma um histórico que não
 * aconteceu; e a Fase 143 mede SLA por tempo em etapa, então etapa deduzida
 * vira duração fictícia no painel de gargalo.
 *
 * A colisão "distribuir já é em operação" é ACEITA para o legado (D-06):
 * empresa já distribuída com onboarding em rascunho/andamento recebe etapa 9
 * e não volta para 6/7/8. Quem entra depois da máquina existir percorre
 * 1→9 pelo `EtapaTransicaoService::transicionar()`.
 *
 * Seguro por construção:
 *  - **Dry-run é o padrão.** Sem `--apply` o comando só lista o que faria.
 *  - **Sem `--limite` e sem `--servico`.** Os dois baldes de D-04 não
 *    admitem execução parcial — um filtro parcial só criaria a chance de
 *    deixar metade da base carimbada.
 *  - **A escrita mora no serviço, não aqui.** `EtapaTransicaoService::
 *    carimbarBackfill()` é o único lugar que grava `companies.etapa` em
 *    massa — este comando só decide QUAIS ids (balde 1).
 *
 * ⚠️ O STDOUT desta execução NÃO é prova. A disciplina de
 * `.planning/learnings/desempenho-bonificacao.md` §4/§10.1 (já custou caro
 * confiar em consolidação "bem-sucedida" na tela sem ter gravado o esperado)
 * exige reconsulta direta ao banco (D-08) — ver
 * `137-BACKFILL-CONTAGENS.md`, que registra essa reconsulta.
 */
class EtapaBackfill extends Command
{
    protected $signature = 'etapa:backfill
        {--apply : Grava de verdade. Sem esta flag o comando só mostra o que faria}';

    protected $description = 'Migra empresas legadas para companies.etapa em dois baldes (dry-run por padrão)';

    public function handle(EtapaTransicaoService $servico): int
    {
        $apply = (bool) $this->option('apply');

        [$total, $comEtapa9, $comEtapaNull] = $this->contar();

        $this->info(sprintf(
            'Antes: total=%d, com etapa em_operacao=%d, com etapa NULL=%d.',
            $total,
            $comEtapa9,
            $comEtapaNull
        ));

        // Balde 1 — reusa EXATAMENTE as relações do model (Pitfall 5,
        // 137-RESEARCH.md): o papel de analista na pivot `company_users` é
        // `consultor`, NUNCA o slug do cargo. Reescrever esta query à mão
        // filtrando pelo slug do cargo devolveria zero linhas silenciosamente.
        //
        // ⚠️ WR-02 (`137-REVIEW.md`) / T-137-33 (plano 137-10): `companies.name`
        // NÃO tem `unique()` no schema. A versão antiga deste `pluck()` usava
        // `name` como segundo argumento — CHAVE do array retornado — e duas
        // empresas homônimas no balde 1 colapsavam numa só: a primeira
        // desaparecia em silêncio do lote (a segunda sobrescrevia a primeira
        // na Collection). Localmente invisível (só 1 de 180 empresas caía no
        // balde 1), mas produção tem ~500 e nada no schema impede colisão de
        // nome lá. Chaveado só por `id` — que É único — a decisão de quem é
        // carimbado não depende mais do nome.
        $idsBalde1 = Company::query()
            ->where(function ($q) {
                $q->whereHas('analistaPerformance')
                    ->orWhereHas('estrategistaPerformance');
            })
            ->pluck('id');

        if (! $apply) {
            // Amostra do dry-run é buscada SEPARADAMENTE, só para
            // apresentação — não decide mais quem é carimbado. `id`/`name`
            // juntos aqui não colidem entre si porque a tabela exibe uma
            // linha por `id`, não por `name`.
            $amostraIds = $idsBalde1->take(20);
            $amostra = Company::query()
                ->whereIn('id', $amostraIds)
                ->get(['id', 'name']);

            $this->table(
                ['id', 'name'],
                $amostra->map(fn ($empresa) => [$empresa->id, $empresa->name])->all()
            );

            $this->warn(sprintf(
                'MODO DRY-RUN — nada foi gravado. %d empresa(s) cairiam no balde 1 (em_operacao)%s. '
                . 'Rode de novo com --apply para gravar.',
                $idsBalde1->count(),
                $idsBalde1->count() > 20 ? ' — amostra acima limitada às 20 primeiras' : ''
            ));

            return self::SUCCESS;
        }

        // Balde 2 é implícito: todo o resto já nasce NULL (D-03). Não existe
        // UPDATE para ele — se algum dia aparecer um terceiro balde neste
        // arquivo, é violação de D-05.
        try {
            $carimbadas = $servico->carimbarBackfill($idsBalde1->values()->all());
        } catch (\Throwable $e) {
            Log::error("[EtapaBackfill] falha ao carimbar lote de {$idsBalde1->count()} empresa(s): {$e->getMessage()}");

            $this->error("Falha ao carimbar o backfill: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Carimbadas {$carimbadas} empresa(s) com etapa=" . Company::ETAPA_EM_OPERACAO . '.');

        [$totalDepois, $comEtapa9Depois, $comEtapaNullDepois] = $this->contar();

        $this->info(sprintf(
            'Depois: total=%d, com etapa em_operacao=%d, com etapa NULL=%d.',
            $totalDepois,
            $comEtapa9Depois,
            $comEtapaNullDepois
        ));

        $this->line('Este stdout NÃO é prova (D-08) — confira por reconsulta direta ao banco.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int, 2: int} total, com etapa em_operacao, com etapa NULL
     */
    private function contar(): array
    {
        $total = Company::query()->count();
        $comEtapa9 = Company::query()->where('etapa', Company::ETAPA_EM_OPERACAO)->count();
        $comEtapaNull = Company::query()->whereNull('etapa')->count();

        return [$total, $comEtapa9, $comEtapaNull];
    }
}
