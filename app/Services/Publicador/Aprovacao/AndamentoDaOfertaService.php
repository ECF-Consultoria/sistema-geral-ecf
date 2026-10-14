<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoOferta;
use App\Models\User;
use App\Support\Publicador\RegraViolada;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A ÚNICA PORTA DE TRANSIÇÃO DE ANDAMENTO DA OFERTA DO PORTÃO.
 *
 * ⚠️⚠️ **NENHUM OUTRO LUGAR ESCREVE `andamento`.** Não é preferência de organização: `aprovado` é
 * o portão que libera a publicação, e o usuário decidiu em 14/10/2026 que **aprovar JÁ PUBLICA**,
 * sem segundo clique e **sem desfazer**. Escrita de estado espalhada é, aqui, elevação de
 * privilégio (T-186-11): um `update(['andamento' => 'aprovado'])` em qualquer controller publicaria
 * um anúncio que ninguém conferiu, e não haveria como voltar.
 *
 * O invariante tem gate: um `grep` por escrita em `andamento` fora deste serviço, **com isca** — a
 * parte medível neste plano está em `AndamentoDaOfertaServiceTest`, e o gate amplo é do plano
 * **186-09**.
 *
 * ═══ AS DUAS RECUSAS, E POR QUE ELAS SÃO SEPARADAS ═════════════════════════════════════════════
 *
 * - **`REGRA_TRANSICAO_ILEGAL`** — o par (origem, destino) não está em `PubAprovacaoOferta::
 *   TRANSICOES`, que é **lista fechada**: o default é recusa. Nada é gravado.
 * - **`REGRA_SEM_MLB`** — AND-04: chegar a `publicado` exige `ml_item_id` **confirmado pelo ML**.
 *   Sem ele, a tela diria "Publicado e confirmado no Mercado Livre · MLB…" sem anúncio nenhum
 *   (T-186-12).
 *
 * Os dois códigos são DISTINTOS de propósito: a tela precisa dizer coisas diferentes para "esse
 * caminho não existe" e "o Mercado Livre ainda não confirmou". ⚠️ `RegraViolada` vem de
 * `App\Support\Publicador` e é **USADA, nunca alterada** (TERR-03 proíbe mexer naquele namespace);
 * o CÓDIGO da regra mora em constante **deste serviço**, não no Support.
 *
 * ═══ ⚠️ UMA TRANSAÇÃO SÓ, E A RAZÃO É "NÃO HÁ DESFAZER" ════════════════════════════════════════
 *
 * O andamento, os três campos de `aprovado_*`, o registro no histórico e o congelamento da
 * composição acontecem dentro de **uma `DB::transaction`**. Falhar no meio deixaria uma oferta
 * **aprovada sem prova do que foi aprovado** — e como aprovar publica, a publicação sairia sem
 * rastro da versão conferida (T-186-13, repúdio).
 *
 * ═══ OS PONTOS DE EXTENSÃO NOMEADOS (para ninguém achar que ficou de fora) ═════════════════════
 *
 * - **`registrar()`** — grava o evento em `pub_aprovacao_eventos`. A tabela e o conteúdo são do
 *   plano **186-05**; aqui o método existe, é chamado DENTRO da transação e é um no-op
 *   documentado. Ligar o histórico é trocar o corpo dele, sem tocar em `mover()`.
 * - **`aoAprovar()`** — o gancho da **Fase 193** (o que acontece no instante da aprovação, já que
 *   aprovar publica). Chamado DENTRO da mesma transação, e também no-op aqui.
 *
 * ⚠️ **Nenhum "confirmar publicação", "desfazer", "segundo passo" ou "cancelar aprovação"** — nem
 * método, nem estado, nem aviso. A decisão do usuário de 14/10 recusou o segundo clique, e o
 * rótulo "Aprovar para publicação" fica inalterado.
 *
 * ⚠️ **A `versao` não é incrementada por este serviço.** Quem incrementa (e com isso REABRE a
 * aprovação) é a rotina das 8 mudanças relevantes, do plano **186-08** — inclusive o caso em que
 * mudança de foto reabre TODAS as ofertas do conjunto. Aqui a `versao` é só LIDA, para carimbar
 * `versao_aprovada`. ⚠️ Em particular, `falha → publicando` ("Tentar de novo") **não** mexe na
 * versão: incrementar ali reabriria uma aprovação em que ninguém mexeu.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class AndamentoDaOfertaService
{
    /** O par (origem, destino) não está na lista fechada de `PubAprovacaoOferta::TRANSICOES`. */
    public const REGRA_TRANSICAO_ILEGAL = 'AND-02-TRANSICAO-ILEGAL';

    /** AND-04 — `publicado` sem `ml_item_id` confirmado pelo Mercado Livre. */
    public const REGRA_SEM_MLB = 'AND-04-SEM-MLB-CONFIRMADO';

    /** DADO-14 — consulta de fila sem recorte de conta seria leitura cruzando empresas. */
    public const REGRA_FILA_SEM_CONTA = 'DADO-14-FILA-SEM-CONTA';

    /** O prefixo de módulo do log (convenção do projeto). */
    private const TAG = '[Publicador Aprovação]';

    /**
     * Move a oferta de andamento, gravando a transição, **e devolve a oferta nova**.
     *
     * Quem chama não precisa de `fresh()`: o retorno já vem recarregado do banco, porque a
     * verdade do estado é o banco e não o objeto em memória.
     *
     * @param  PubAprovacaoOferta  $oferta  a oferta a mover
     * @param  string  $destino  um dos 8 andamentos de `PubAprovacaoOferta::ANDAMENTOS`
     * @param  User|null  $quem  quem está movendo (obrigatório na prática para `aprovado`)
     * @param  string|null  $motivo  o motivo; em `falha` é **o erro REAL do ML** (PUB-05)
     *
     * @throws RegraViolada transição fora da lista fechada, ou `publicado` sem MLB confirmado
     */
    public function mover(PubAprovacaoOferta $oferta, string $destino, ?User $quem = null, ?string $motivo = null): PubAprovacaoOferta
    {
        $origem = $oferta->andamento;

        // ⚠️ RECUSA 1 — lista fechada, default = recusa. Nada é gravado: a recusa é um no-op
        // completo (nem `updated_at` se move), senão "recusei" e "mudei a linha" se confundiriam.
        if (! PubAprovacaoOferta::transicaoPermitida($origem, $destino)) {
            throw new RegraViolada(
                self::REGRA_TRANSICAO_ILEGAL,
                sprintf('A oferta não pode ir de "%s" para "%s".', (string) $origem, $destino),
                ['oferta_id' => $oferta->id, 'de' => $origem, 'para' => $destino]
            );
        }

        // ⚠️ RECUSA 2 — AND-04. `podeSerPublicado()` é a pergunta; a recusa é aqui.
        if ($destino === PubAprovacaoOferta::PUBLICADO && ! $oferta->podeSerPublicado()) {
            throw new RegraViolada(
                self::REGRA_SEM_MLB,
                'A oferta só fica publicada depois de o Mercado Livre confirmar o anúncio (MLB).',
                ['oferta_id' => $oferta->id, 'de' => $origem]
            );
        }

        $mudancas = ['andamento' => $destino];

        // FILA-13 — a base do tempo de espera ("há X", âmbar acima de 24 h). Carimbada na PRIMEIRA
        // entrada na fila e nunca reescrita: reescrever zeraria a espera a cada ida e volta de
        // ajuste, e a oferta mais antiga pareceria a mais nova.
        if ($destino === PubAprovacaoOferta::AGUARDANDO_APROVACAO && $oferta->entrou_na_fila_em === null) {
            $mudancas['entrou_na_fila_em'] = now();
        }

        // ⚠️ BLOQ-05 — os TRÊS campos juntos, na mesma transação. `versao_aprovada` guarda a
        // versão VIGENTE no instante do clique: é ela que permite saber, depois, que a aprovação
        // foi dada sobre outra coisa (`aprovacaoReaberta()`).
        if ($destino === PubAprovacaoOferta::APROVADO) {
            $mudancas['aprovado_por'] = $quem?->id;
            $mudancas['aprovado_em'] = now();
            $mudancas['versao_aprovada'] = (int) $oferta->versao;
        }

        // PUB-05 — o erro REAL do ML, não um "algo deu errado".
        if ($destino === PubAprovacaoOferta::FALHA) {
            $mudancas['erro_publicacao'] = $motivo;
        }

        // "Tentar de novo": o erro da tentativa que já passou não fica na tela da nova.
        // ⚠️ E a `versao` NÃO entra em `$mudancas` — ver o docblock da classe.
        if ($destino === PubAprovacaoOferta::PUBLICANDO) {
            $mudancas['erro_publicacao'] = null;
        }

        DB::transaction(function () use ($oferta, $mudancas, $origem, $destino, $quem, $motivo) {
            $oferta->update($mudancas);

            $this->registrar($oferta, (string) $origem, $destino, $quem, $motivo);

            if ($destino === PubAprovacaoOferta::APROVADO) {
                $this->aoAprovar($oferta, $quem);
            }
        });

        // Rastreio com id + NOME da entidade (convenção do projeto): sem o nome, o log de
        // produção obriga a abrir o banco para saber de que produto se fala.
        Log::info(self::TAG . ' andamento movido', [
            'oferta_id' => $oferta->id,
            'produto' => $oferta->produto?->sku . ' (' . $oferta->produto?->nome . ')',
            'alvo_id' => $oferta->alvo_id,
            'de' => $origem,
            'para' => $destino,
            'quem_id' => $quem?->id,
            'versao' => $oferta->versao,
        ]);

        return $oferta->refresh();
    }

    /**
     * A base de consulta da fila de UMA conta (DADO-14).
     *
     * ⚠️ **Recusa a consulta sem recorte.** Devolver tudo quando as duas âncoras vêm nulas seria
     * leitura cruzando empresas (T-186-14) — o tipo de vazamento que o schema abençoaria, porque
     * as duas colunas são anuláveis por construção. Quem não sabe de que conta está falando não
     * pode receber a fila.
     *
     * @throws RegraViolada quando nenhuma âncora de conta é informada
     */
    public function filaDaConta(?int $companyId = null, ?int $mlbEmpresaId = null): Builder
    {
        if ($companyId === null && $mlbEmpresaId === null) {
            throw new RegraViolada(
                self::REGRA_FILA_SEM_CONTA,
                'A fila de aprovação só é consultada com a conta informada.',
                []
            );
        }

        $consulta = PubAprovacaoOferta::query();

        if ($companyId !== null) {
            $consulta->where('company_id', $companyId);
        }

        if ($mlbEmpresaId !== null) {
            $consulta->where('mlb_empresa_id', $mlbEmpresaId);
        }

        return $consulta;
    }

    /**
     * A ordem da fila (AND-08): por `PubAprovacaoOferta::ORDEM` e, **dentro de cada degrau, a
     * maior espera primeiro**.
     *
     * ⚠️ A espera só desempata DENTRO do degrau — ela nunca atravessa degraus. Uma oferta em
     * `preparando` desde o mês passado continua por último, porque não há nada a fazer nela.
     *
     * ⚠️ `entrou_na_fila_em` nulo vai para o FIM do próprio degrau: espera desconhecida não vira
     * espera infinita (o contrário colocaria oferta que acabou de nascer na frente de todas).
     * Empate completo desempata por `id`, para a ordem ser estável entre duas chamadas.
     *
     * @param  Collection<int, PubAprovacaoOferta>  $ofertas
     * @return Collection<int, PubAprovacaoOferta>
     */
    public function ordenarFila(Collection $ofertas): Collection
    {
        return $ofertas->sort(function (PubAprovacaoOferta $a, PubAprovacaoOferta $b) {
            $porDegrau = $a->ordem() <=> $b->ordem();

            if ($porDegrau !== 0) {
                return $porDegrau;
            }

            $esperaA = $a->entrou_na_fila_em?->getTimestamp();
            $esperaB = $b->entrou_na_fila_em?->getTimestamp();

            if ($esperaA === null && $esperaB === null) {
                return $a->id <=> $b->id;
            }

            if ($esperaA === null) {
                return 1;
            }

            if ($esperaB === null) {
                return -1;
            }

            // Mais antigo primeiro = maior espera primeiro.
            return $esperaA <=> $esperaB;
        })->values();
    }

    /**
     * Os **9** recortes da faixa de andamento (AND-09): **Todas** + as **7** etapas internas +
     * **"Publicado hoje"**.
     *
     * ⚠️⚠️ O ÚLTIMO É RECORTE **DO DIA**, não o estado inteiro. Trocar o rótulo pelo do estado
     * (`'Publicado'`) faria a faixa **prometer o histórico completo e entregar o do dia** — e
     * contar o estado inteiro faria o contrário. O corte é por `updated_at`, que na oferta
     * publicada é o instante da transição para `publicado`: a última escrita que este serviço faz
     * naquela linha. ⚠️ Não há coluna `publicado_em` de propósito — ela seria uma segunda verdade
     * ao lado da transição, livre para divergir dela.
     *
     * ⚠️ `todas` é "sem filtro", não um estado, e por isso não está em `ANDAMENTOS` nem em
     * `ORDEM`. Ela é MAIOR que a soma dos oito recortes sempre que houver publicada de outro dia —
     * e essa diferença é o que prova que o último recorte não é o estado inteiro.
     *
     * @param  Builder  $base  já recortada por conta (ver `filaDaConta`)
     * @return list<array{chave: string, rotulo: string, total: int}>
     */
    public function faixaDeAndamento(Builder $base): array
    {
        $faixa = [[
            'chave' => 'todas',
            'rotulo' => 'Todas',
            'total' => (clone $base)->count(),
        ]];

        foreach (PubAprovacaoOferta::ANDAMENTOS as $chave => $rotulo) {
            if ($chave === PubAprovacaoOferta::PUBLICADO) {
                continue;
            }

            $faixa[] = [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'total' => (clone $base)->where('andamento', $chave)->count(),
            ];
        }

        $faixa[] = [
            'chave' => PubAprovacaoOferta::PUBLICADO,
            'rotulo' => 'Publicado hoje',
            'total' => (clone $base)
                ->where('andamento', PubAprovacaoOferta::PUBLICADO)
                ->whereDate('updated_at', now()->toDateString())
                ->count(),
        ];

        return $faixa;
    }

    /**
     * PONTO DE EXTENSÃO — o registro da transição no histórico (`pub_aprovacao_eventos`).
     *
     * ⚠️ **É LIGADO PELO PLANO 186-05**, que cria a tabela e define o conteúdo do evento. Aqui o
     * método existe, é chamado **dentro da transação** de `mover()` e é um no-op deliberado: assim
     * o 186-05 troca só o corpo, sem tocar na máquina de estado nem na transação.
     *
     * Deixar o gancho vazio e DOCUMENTADO é melhor que não tê-lo: sem ele, o 186-05 teria de
     * reabrir `mover()` e a transação única viraria duas.
     */
    protected function registrar(PubAprovacaoOferta $oferta, string $origem, string $destino, ?User $quem, ?string $motivo): void
    {
        // Vazio de propósito — ver o docblock. O histórico chega no plano 186-05.
    }

    /**
     * PONTO DE EXTENSÃO — o que acontece no instante da aprovação.
     *
     * ⚠️ **É LIGADO PELA FASE 193.** Como **aprovar JÁ PUBLICA** (decisão do usuário de
     * 14/10/2026), é daqui que a publicação sai — e é por isso que o gancho fica **dentro da mesma
     * transação** que gravou `aprovado_por`/`aprovado_em`/`versao_aprovada`: publicar sem a prova
     * do que foi aprovado é o que não pode acontecer, porque não há desfazer.
     *
     * ⚠️ Este gancho **não** é um "segundo passo" nem uma confirmação: não existe estado
     * intermediário entre `aprovado` e `publicando`, e o rótulo do botão segue "Aprovar para
     * publicação".
     */
    protected function aoAprovar(PubAprovacaoOferta $oferta, ?User $quem): void
    {
        // Vazio de propósito — ver o docblock. A publicação é ligada na Fase 193.
    }
}
