<?php

namespace App\Services\Publicador\Aprovacao\Fila;

use App\Models\PubAprovacaoOferta;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\Aprovacao\ComposicaoFinanceiraService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A LEITURA DA FILA "AGUARDANDO APROVAÇÃO" — O ÚNICO PONTO POR ONDE A TELA LÊ A FILA.
 *
 * ⚠️⚠️ **ESTE ARQUIVO NÃO TEM LÓGICA PRÓPRIA, E ISSO É O VALOR DELE.** Os cinco métodos públicos
 * são uma linha cada, delegando aos serviços que a **Fase 186** já entregou. O recorte por conta,
 * a ordem da fila, os 9 recortes da faixa e o resolvedor da margem mínima **já existem** — e
 * reescrever qualquer um deles aqui criaria uma **segunda verdade**, livre para divergir da
 * primeira sem nenhum teste de render acusar.
 *
 * O invariante tem gate: `FilaDeAprovacaoRotaTest::test_servico_da_fila_nao_tem_logica_propria_187_02`
 * lê este arquivo **sem os comentários** e exige zero `where('company_id'`, zero `->sort(`, zero
 * `whereDate(` e **zero literal dos 8 estados** — com isca nas duas direções. Ler o código sem
 * comentários não é zelo: palavra no docblock que explica *"não uso isso"* casa com o gate que
 * conta aquela palavra, e o executor seguinte "conserta" o código para o gate passar.
 *
 * ═══ POR QUE ELE MORA EM `Aprovacao/Fila/` E NÃO EM `Aprovacao/` ═══════════════════════════════
 *
 * Os **10** arquivos de `app/Services/Publicador/Aprovacao/` são da Fase 186 e, para esta fase,
 * **SOMENTE LEITURA**. Pôr o serviço da fila num subdiretório NOVO deixa o gate de território
 * medir um diretório que é **100% desta fase** — `Aprovacao/` **menos** `Fila/` tem de sair com
 * `numstat` ZERO.
 *
 * ═══ ⚠️ O CONTADOR DA ABA LÊ `PubAprovacaoOferta::LIVE` — NUNCA UM ARRAY À MÃO ═════════════════
 *
 * Escrever os três estados aqui criaria uma cópia da mesma verdade, livre para divergir no dia em
 * que um **4º** estado entrar em `LIVE`. Lendo a constante, o 4º estado entra sozinho — e é por
 * isso que o gate proíbe literal de estado neste arquivo.
 *
 * ⚠️ E o contador **vem do servidor** (ABA-05). Somar no cliente exigiria mandar a fila inteira
 * para uma tela que não a mostra, e o número passaria a depender **do que a página carregou**, não
 * do que existe. Há gate de ausência provando que nenhum `.jsx` soma andamento.
 *
 * ⚠️⚠️ **A FILA NASCE VAZIA, E ISSO É HONESTO.** Medido em 15/10/2026: **nada** cria
 * `PubAprovacaoOferta` hoje — quem passa a criar é a **Fase 194**. Até lá o contador é **0**, e
 * **0 real não é enfeite**: a aba existe com número honesto, sem dado de exemplo, sem fixture como
 * fonte e sem esconder a aba quando for zero (decisão do usuário).
 *
 * ⚠️ **NUNCA um `||` aceitando as chaves do protótipo** (`awaiting`/`ecf_review`/`changes`): as
 * chaves de dado são pt-BR, a tradução vive em `VocabularioDoPortao` e em `andamentoDoPortao.jsx`
 * (`DO_PROTOTIPO`), com bijeção, e aceitar as duas formas foi achado como **defeito grave** na
 * verificação da Fase 186.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class FilaDeAprovacaoService
{
    public function __construct(
        private AndamentoDaOfertaService $andamento,
        private ComposicaoFinanceiraService $financeira,
    ) {}

    /**
     * A base de consulta da fila de UMA conta.
     *
     * ⚠️ **Propaga a `RegraViolada`** de `filaDaConta()` com as duas âncoras nulas (a trava
     * T-186-14): quem não sabe de que conta fala **não recebe a fila**. Engolir a exceção aqui
     * devolveria a fila de todas as empresas — vazamento que o schema abençoaria, porque as duas
     * colunas de conta são anuláveis por construção.
     *
     * @throws \App\Support\Publicador\RegraViolada quando nenhuma âncora de conta é informada
     */
    public function paraAConta(?int $companyId = null, ?int $mlbEmpresaId = null): Builder
    {
        return $this->andamento->filaDaConta($companyId, $mlbEmpresaId);
    }

    /**
     * O contador da aba (ABA-05 / AND-05): quantas ofertas da conta estão **ao vivo**.
     *
     * ⚠️ `PubAprovacaoOferta::LIVE` é **LIDO**, nunca reescrito — ver o docblock da classe.
     */
    public function contadorDaAba(?int $companyId = null, ?int $mlbEmpresaId = null): int
    {
        return $this->paraAConta($companyId, $mlbEmpresaId)
            ->whereIn('andamento', PubAprovacaoOferta::LIVE)
            ->count();
    }

    /**
     * Os **9** recortes da faixa de andamento (AND-09), calculados sobre os DEMAIS filtros.
     *
     * @param  Builder  $base  já recortada por conta (ver `paraAConta`)
     * @return list<array{chave: string, rotulo: string, total: int}>
     */
    public function faixa(Builder $base): array
    {
        return $this->andamento->faixaDeAndamento($base);
    }

    /**
     * A ordem da fila (AND-08): a literal de `ORDEM`, com maior espera primeiro no degrau.
     *
     * @param  Collection<int, PubAprovacaoOferta>  $ofertas
     * @return Collection<int, PubAprovacaoOferta>
     */
    public function ordenar(Collection $ofertas): Collection
    {
        return $this->andamento->ordenarFila($ofertas);
    }

    /**
     * A margem mínima da linha "ⓘ Margem mínima" (FIN-09), **com a origem**.
     *
     * ⚠️ O número **nunca** é literal na tela nem aqui: o parâmetro da empresa manda quando é
     * maior que zero, a config manda no resto, e **o zero não desliga**. A `origem` viaja junto
     * porque cada número diz de onde vem.
     *
     * @return array{valor: float, origem: string}
     */
    public function margemMinima(?int $companyId): array
    {
        return $this->financeira->margemMinimaComOrigem($companyId);
    }
}
