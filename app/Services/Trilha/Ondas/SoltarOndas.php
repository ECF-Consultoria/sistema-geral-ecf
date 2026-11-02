<?php

namespace App\Services\Trilha\Ondas;

use App\Models\Company;
use App\Models\PubAgenteConfiguracao;
use App\Models\PubProduto;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Services\Publicador\CombosDoPlanejamentoService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\ProgramasPublicadorService;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Portal\AtorDoPortal;
use App\Support\Trilha\TrilhaDeTrabalho;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A data da onda solta o robô (Trilha de produtos, 2ª entrega, 02/11/2026). Uma passada, por empresa da trilha de
 * trabalho:
 *
 * 1. **Soltar** — a onda cujo dia chegou (São Paulo, comparado em PHP) ganha `liberada_em`, uma vez só (linha
 *    travada), com o rastro `trilha_onda_solta`.
 * 2. **Pedir o robô** — os itens das ondas que já começaram (Simples, Kit, Combit e o Combo, cujo pub avulso de
 *    cor fora do kit também precisa do texto), ainda sem `pedido_em`, até `trilha.ondas.lote` produtos por passada:
 *    `PreparoIaAgenda::aoSalvar()`, o MESMO caminho de um save no Portal (Sincronizar + preparo 20 s depois, com a
 *    marca do debounce), mas com a IA restrita aos itens pedidos (`soItens`): o que já andou com uma etapa em erro
 *    não é refeito de carona. A trava da onda deixa passar o que já começou. O resto vai na passada seguinte. Com o
 *    preparo pela IA desligado nada é pedido nem marcado — o `aoSalvar` não agendaria Job nenhum.
 * 3. **Repescar** — o item pedido em outro dia que ainda não tem NADA do texto (teto do dia, worker parado, Job
 *    perdido) é pedido de novo UMA vez, dentro de `trilha.ondas.repescagem_dias` dias contados da entrada dele na onda
 *    (`TrilhaOndaItem::comecoEfetivo`). O que começou e falhou não volta sozinho: aparece como "O agente parou" e
 *    espera uma pessoa — não há laço pago. A régua é o texto (`sem_texto`), não o "andou": com as imagens
 *    automáticas ligadas, o item que bateu no teto do texto ganha a memória das imagens e "andou" sem texto. Com o
 *    agente da Aprovação ligado na conta, a repescagem vale uma vez POR DIA na janela: a recusa dele (limite do dia,
 *    teto da fila) não custa IA, e a varredura dele pula a empresa da trilha.
 * 4. **Combos** — o Combo de onda que começou e ainda não andou pede o Kit N (`CombosDoPlanejamentoService::agendar`),
 *    no máximo uma vez por hora por produto e SEM janela de dias: ele nasce quando a Simples fica pronta, que pode
 *    ser muito depois do começo da onda (e o Job do Kit N não chama IA nenhuma).
 * 5. **Limpar** — a linha `c-` cujo combo deixou de existir (todas as cores apagadas), em qualquer onda: sem isso,
 *    um Combo N aceito depois herdaria a onda antiga.
 *
 * Os tetos de sempre valem: 60 textos e 10 kits de imagem por empresa por dia — "começa na data" não é "fica pronto
 * na data". Nenhuma fila nova, nenhum worker novo.
 */
class SoltarOndas
{
    public function __construct(
        private PreparoIaAgenda $agenda,
        private CombosDoPlanejamentoService $combos,
    ) {}

    /**
     * Uma passada por todas as empresas da trilha de trabalho (ou só a pedida, se estiver ligada).
     *
     * @return list<array> o resumo de cada empresa ({@see daEmpresa()})
     */
    public function rodar(?int $soEmpresa = null, bool $aplicar = true): array
    {
        $saida = [];
        foreach (TrilhaDeTrabalho::empresas() as $id) {
            if ($soEmpresa !== null && $id !== $soEmpresa) {
                continue;
            }
            $empresa = Company::query()->find($id);
            if ($empresa === null) {
                continue;
            }
            try {
                $saida[] = $this->daEmpresa($empresa, $aplicar);
            } catch (\Throwable $e) {
                Log::error("[Trilha] Soltura das ondas da empresa {$empresa->id} ({$empresa->name}) falhou: ".$e->getMessage());
                $saida[] = ['empresa' => (int) $empresa->id, 'erro' => $e->getMessage()];
            }
        }

        return $saida;
    }

    /**
     * Uma passada numa empresa. Sem `$aplicar`, só diz o que faria (nada é gravado, nenhum Job sai).
     *
     * @return array{empresa: int, soltas: list<string>, pedidos: list<string>, repescados: list<string>, combos: list<string>, orfaos: list<string>, produtos: int}
     */
    public function daEmpresa(Company $empresa, bool $aplicar = true): array
    {
        $resumo = ['empresa' => (int) $empresa->id, 'soltas' => [], 'pedidos' => [], 'repescados' => [], 'combos' => [], 'orfaos' => [], 'produtos' => 0];
        if (! TrilhaDeTrabalho::ligada($empresa)) {
            return $resumo;
        }
        $hoje = TrilhaOnda::hoje();

        // ─── 1. Soltar ───
        foreach (TrilhaOnda::query()->where('company_id', $empresa->id)->whereNull('liberada_em')->orderBy('id')->get() as $onda) {
            if (! $onda->comecou($hoje)) {
                continue;
            }
            $resumo['soltas'][] = $onda->nome;
            if ($aplicar) {
                $this->soltar($empresa, (int) $onda->id);
            }
        }

        // ─── 2 e 3. Pedir o robô e repescar, no mesmo lote ───
        $itens = TrilhaDaEmpresa::itens($empresa);
        $lote = max(1, (int) config('trilha.ondas.lote', 30));
        $janelaDesde = Carbon::parse($hoje, TrilhaOnda::FUSO)->subDays(max(0, (int) config('trilha.ondas.repescagem_dias', 7)))->startOfDay();
        $todas = TrilhaOndaItem::query()->where('company_id', $empresa->id)->with('onda')->orderBy('id')->get();

        // O `aoSalvar` não agenda nada com o preparo desligado: marcar `pedido_em` mentiria e gastaria a repescagem.
        $preparoLigado = (bool) config('publicador.preparo_ia.ativo', true);
        $comAgente = $preparoLigado && self::agenteLigado($empresa);
        $semPreparo = 0;

        $produtos = [];
        $soItens = [];
        $pedidas = [];
        $repescadas = [];
        $soMarcar = [];
        $orfas = [];
        $combos = [];
        foreach ($todas as $l) {
            $chave = $l->chave();
            $item = $itens[$chave] ?? null;
            if ($item === null) {
                // O combo que deixou de existir (todas as cores apagadas), em QUALQUER onda: sem isso um Combo N
                // aceito de novo herdaria a onda antiga. Quem apaga pelo Eloquent já limpa na hora
                // (`TrilhaComboExcluidoObserver`); aqui é a rede de segurança.
                if (str_starts_with($chave, 'c-')) {
                    $orfas[] = $l;
                }

                continue;
            }
            $onda = $l->onda;
            if ($onda === null || (int) $onda->company_id !== (int) $empresa->id || ! $onda->comecou($hoje) || $item['sem_produto']) {
                continue;
            }
            $naJanela = ($l->comecoEfetivo() ?? $janelaDesde)->gte($janelaDesde);
            if (str_starts_with($chave, 'c-') && ! $item['andou']) {
                $combos[(int) $l->produto_id] = true;
            }
            if (! $preparoLigado) {
                $semPreparo++;

                continue;
            }

            if ($l->pedido_em === null) {
                // O que já tem texto (alguém preparou à mão antes da data) não precisa do robô: só fica marcado.
                if ($item['andou'] && ! $item['sem_texto']) {
                    $soMarcar[] = $l;

                    continue;
                }
                $repescar = false;
            } else {
                // Repescagem: UMA vez (com o agente ligado, uma por dia), de outro dia, dentro da janela (contada da
                // entrada do item na onda), e só para quem ainda não tem NADA do texto — o que começou e falhou não
                // volta sozinho.
                // A repescagem diária do agente não vale para o Combo: o pedido dele não passa pelo agente (o Kit N nasce
                // da Simples pronta) — repescá-lo todo dia só sincronizaria o produto à toa.
                $umaVezSo = ! $comAgente || str_starts_with($chave, 'c-');
                if (($l->repescado_em !== null && $umaVezSo) || ! $item['sem_texto'] || ! $naJanela
                    || $l->pedido_em->copy()->setTimezone(TrilhaOnda::FUSO)->toDateString() >= $hoje) {
                    continue;
                }
                $repescar = true;
            }
            if ($item['produtos'] === []) {
                if (! $repescar) {
                    $soMarcar[] = $l;
                }

                continue;
            }
            $novos = array_diff($item['produtos'], array_keys($produtos));
            if ($produtos !== [] && count($produtos) + count($novos) > $lote) {
                continue;
            }
            // O preparo é por PRODUTO, mas a IA roda só para os itens pedidos (`soItens`): um kit, um combo ou uma
            // Simples de outro item do mesmo produto, que já andou com uma etapa em erro, não é refeito de carona.
            foreach ($item['produtos'] as $p) {
                $produtos[$p] = true;
                $soItens[$p][] = $chave;
            }
            if ($repescar) {
                $repescadas[] = $l;
            } else {
                $pedidas[] = $l;
            }
            $resumo[$repescar ? 'repescados' : 'pedidos'][] = $chave;
        }
        $resumo['produtos'] = count($produtos);
        if ($semPreparo > 0) {
            Log::info("[Trilha] Ondas da empresa {$empresa->id} ({$empresa->name}): o preparo pela IA está desligado (publicador.preparo_ia.ativo); {$semPreparo} item(ns) de onda começada esperam o robô.");
        }

        if ($aplicar) {
            if ($produtos !== []) {
                $this->agenda->aoSalvar((int) $empresa->id, array_keys($produtos), array_map(fn (array $c) => array_values(array_unique($c)), $soItens));
                Log::info("[Trilha] Ondas da empresa {$empresa->id} ({$empresa->name}): robô pedido para ".(count($pedidas) + count($repescadas)).' item(ns), '.count($produtos).' produto(s).');
            }
            $ids = fn (array $linhas) => array_map(fn (TrilhaOndaItem $l) => (int) $l->id, $linhas);
            if ($pedidas !== [] || $soMarcar !== []) {
                TrilhaOndaItem::query()->whereIn('id', [...$ids($pedidas), ...$ids($soMarcar)])->update(['pedido_em' => now()]);
            }
            if ($repescadas !== []) {
                TrilhaOndaItem::query()->whereIn('id', $ids($repescadas))->update(['pedido_em' => now(), 'repescado_em' => now()]);
            }
        }

        // ─── 4. Combos: o Kit N nasce quando a Simples fica pronta ───
        // No máximo uma vez por hora por produto: um Kit N que nunca nasce (cor que não entra no kit, regra do
        // Publicador) não pode virar um Job a cada 15 minutos. Sem janela de dias: a Simples pode ficar pronta muito
        // depois do começo da onda (e o Job não chama IA nenhuma).
        foreach (array_keys($combos) as $produtoId) {
            $grupo = PubProduto::query()->where('company_id', $empresa->id)->where('estrutura_produto_id', $produtoId)
                ->whereNull('produto_base_id')->orderBy('id')->value('id');
            if ($grupo === null) {
                continue;
            }
            $resumo['combos'][] = "s-{$produtoId}";
            if ($aplicar && Cache::add("trilha:ondas:combo-agendado:{$grupo}", 1, now()->addHour())) {
                $this->combos->agendar((int) $grupo);
            }
        }

        // ─── 5. Limpar as linhas de combo órfãs ───
        foreach ($orfas as $l) {
            $resumo['orfaos'][] = $l->chave();
            if ($aplicar) {
                $l->delete();
            }
        }

        return $resumo;
    }

    /**
     * O agente da Aprovação está ligado na conta da empresa? (A mesma configuração que o `EntradaDoPreparo` lê.) Erro
     * ao ler conta como desligado: vale a regra de sempre, uma repescagem só.
     */
    private static function agenteLigado(Company $empresa): bool
    {
        try {
            $chave = app(ProgramasPublicadorService::class)->resolver('company-'.$empresa->id)['chave'] ?? null;

            return $chave !== null && PubAgenteConfiguracao::query()->where('conta_chave', $chave)->where('habilitado', true)->exists();
        } catch (\Throwable $e) {
            Log::warning("[Trilha] Ondas da empresa {$empresa->id}: não deu para ler o agente da Aprovação da conta; repescagem de sempre. ".$e->getMessage());

            return false;
        }
    }

    /** Grava `liberada_em` uma vez só (a linha da onda travada) e deixa o rastro. */
    private function soltar(Company $empresa, int $ondaId): void
    {
        DB::transaction(function () use ($empresa, $ondaId) {
            $onda = TrilhaOnda::query()->whereKey($ondaId)->lockForUpdate()->first();
            if ($onda === null || $onda->liberada_em !== null) {
                return;
            }
            $onda->update(['liberada_em' => now()]);
            RegistroEstrutura::registrar(self::ator(), $empresa, $onda, 'trilha_onda_solta',
                "Onda {$onda->nome} começou ({$onda->dia()}): o robô começa a preparar as ofertas dela",
                ['via' => 'trilha', 'onda_id' => (int) $onda->id]);
        });
    }

    /** A data da onda, sem humano por trás do clique. */
    public static function ator(): AtorDoPortal
    {
        return AtorDoPortal::sistema('trilha', 'Trilha (data da onda)');
    }
}
