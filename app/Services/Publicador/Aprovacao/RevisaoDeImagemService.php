<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoImagemAlerta;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagem;
use App\Models\User;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A REVISÃO DAS FOTOS — aprovar · reprovar · substituir · recolocar · reordenar · definir a
 * principal · levar para regeneração e trazer de volta.
 *
 * ═══ ⚠️⚠️ IMG-06 — AS **6** AÇÕES QUE MUDAM O CONJUNTO FINAL REABREM **TODAS** AS OFERTAS ═══════
 *
 * reprovar · substituir · regenerar · adicionar/recolocar · reordenar · trocar a principal.
 * ⚠️ **A propagação NÃO mora aqui:** ela mora em
 * {@see AprovacaoDaOfertaService::registrarMudanca()}, num lugar só. Duplicá-la deixaria as duas
 * cópias livres para divergir, e a que divergisse deixaria uma oferta **aprovada com a foto
 * trocada** — e aprovar já publica, sem desfazer.
 *
 * ⚠️ **Aprovar foto NÃO reabre nada** (`approveImg`, `Aprovacao.dc.html:1150`): aprovar é
 * progresso na direção da aprovação, não mudança do conjunto. Ele marca `tocado` no conjunto e em
 * todas as ofertas dele, e registra no histórico.
 *
 * ═══ ⚠️ O QUE MANTÉM A GALERIA CONSISTENTE (IMG-07), E POR QUE É **POR CONSTRUÇÃO** ═════════════
 *
 * Depois de **toda** mudança de composição, {@see self::reposicionar()} reescreve as `posicao`
 * como `0..n-1` na ordem corrente e carimba `principal_imagem_id` com a imagem da **posição 0**.
 * ➡️ *"A principal é sempre a primeira da ordem"* deixa de ser uma regra a lembrar e passa a ser
 * **invariante da escrita**: não existe caminho que deixe a capa fora do topo. ⚠️ `posicao` NULA
 * **é** "fora da publicação" (DADO-05), e `fora = true` diz o mesmo explicitamente — as duas são
 * conferidas juntas por `PubAprovacaoImagem::noConjuntoFinal()`, nunca uma só.
 *
 * ═══ ⚠️ IMG-09 — EM `regenerating` A IMAGEM NÃO ACEITA AÇÃO ════════════════════════════════════
 *
 * **As quatro**: aprovar, reprovar, substituir e regenerar de novo. Aprovar uma imagem que vai ser
 * substituída em segundos **aprovaria a foto errada** — e ela iria ao ar.
 *
 * ═══ ⚠️⚠️ A REGENERAÇÃO EM SI É DA **FASE 192** ════════════════════════════════════════════════
 *
 * Este serviço só leva a imagem a `regenerating` ({@see self::marcarRegenerando()}) e a traz de
 * volta ({@see self::concluirRegeneracao()}). ⚠️ **A chamada ao Creative Engine é da Fase 192**
 * (IA-01: composição do que já existe, nunca serviço novo), e este plano **não toca**
 * `app/Services/Creative/`, `PublicadorCriativoAprovacaoService.php` nem a retenção de 48 h.
 *
 * ═══ ⚠️ NÃO EXISTE `label` DE IMAGEM NESTE BANCO — e o rótulo é DERIVADO ════════════════════════
 *
 * Medido: `pub_imagens` tem `caminho`, `sha256`, `mime`, dimensões e o eixo de **upload** — e
 * **nenhuma** coluna de nome amigável (`database/migrations/2026_10_01_200000_…:173-189`). O
 * `label` do protótipo é dado de fixture dele. ➡️ O rótulo que vai ao histórico é
 * {@see self::rotuloDaImagem()}: a **posição** (que é o que a pessoa vê na tela) mais o nome do
 * arquivo quando existe. ⚠️ Inventar uma coluna de rótulo seria migration, e este plano não cria
 * migration.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class RevisaoDeImagemService
{
    /** IMG-10 — reprovar **exige motivo**. Sem motivo, recusa e **nada** é gravado. */
    public const REGRA_SEM_MOTIVO = 'IMG-10-REPROVAR-SEM-MOTIVO';

    /** IMG-09 — a imagem está em `regenerating` e não aceita ação. */
    public const REGRA_REGENERANDO = 'IMG-09-IMAGEM-REGENERANDO';

    /** IMG-03 — a original oferecida não é do mesmo rascunho da imagem substituída. */
    public const REGRA_ORIGINAL_DE_OUTRO_RASCUNHO = 'IMG-03-ORIGINAL-DE-OUTRO-RASCUNHO';

    /** IMG-07 — a ordem recebida não é exatamente o conjunto final corrente. */
    public const REGRA_ORDEM_INCOMPLETA = 'IMG-07-ORDEM-INCOMPLETA';

    private const TAG = '[Publicador Aprovação]';

    public function __construct(
        private AprovacaoDaOfertaService $aprovacao,
        private HistoricoDaOfertaService $historico,
    ) {}

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // IMG-04 / IMG-05 — APROVAR (não reabre nada)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **IMG-04** — aprova a foto: grava **quem** e **quando**, marca o conjunto como **tocado em
     * todas as ofertas dele** e registra no histórico.
     *
     * ⚠️ O registro vai para **TODAS** as ofertas do conjunto, e isso é **divergência declarada**:
     * o protótipo registra só na primeira (`approveImg`, `:1150`, `setOffers(set)[0]`). Com duas
     * ofertas compartilhando a foto, a linha do tempo do Premium **não explicaria** por que a foto
     * dele ficou aprovada — e o compartilhamento é justamente o que mais confunde quem revisa.
     *
     * @throws RegraViolada quando a imagem está em `regenerating` (IMG-09)
     */
    public function aprovar(PubAprovacaoImagem $imagem, User $quem): PubAprovacaoImagem
    {
        $this->exigirQueAceiteAcao($imagem, 'aprovar');

        $conjunto = $imagem->conjunto;

        DB::transaction(function () use ($imagem, $quem, $conjunto) {
            $imagem->update([
                'estado' => PubAprovacaoImagem::APROVADA,
                'quem_id' => $quem->id,
                'quando' => now(),
            ]);

            $this->marcarTocado($conjunto);

            $this->registrarNoConjunto(
                $conjunto,
                $quem,
                PubAprovacaoEvento::APROVACAO,
                sprintf('Aprovou a foto “%s”', $this->rotuloDaImagem($imagem))
            );
        });

        return $imagem->refresh();
    }

    /**
     * **IMG-05** — "Aprovar N sem alerta": aprova **só** as `pending` **sem** verificação
     * automática acima de `ok`, e registra *"Aprovou N fotos sem alerta do conjunto
     * compartilhado"*.
     *
     * ⚠️ Imagem `pending` **COM** alerta **não** é aprovada: ela continua para conferência
     * individual. É o ponto inteiro do botão — ele existe para não obrigar ninguém a clicar em
     * trinta fotos limpas, **sem** passar as suspeitas de bonde.
     * ⚠️ `regenerating` também fica de fora (nem é `pending`).
     *
     * @return int quantas foram aprovadas
     */
    public function aprovarSemAlerta(PubAprovacaoConjunto $conjunto, User $quem): int
    {
        $candidatas = $conjunto->imagens()
            ->with('alertas')
            ->where('estado', PubAprovacaoImagem::PENDENTE)
            ->get()
            ->filter(fn (PubAprovacaoImagem $i) => ! $i->alertas
                ->contains(fn (PubAprovacaoImagemAlerta $a) => $a->severidade !== PubAprovacaoImagemAlerta::OK))
            ->values();

        if ($candidatas->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($candidatas, $conjunto, $quem) {
            foreach ($candidatas as $imagem) {
                $imagem->update([
                    'estado' => PubAprovacaoImagem::APROVADA,
                    'quem_id' => $quem->id,
                    'quando' => now(),
                ]);
            }

            $this->marcarTocado($conjunto);

            $this->registrarNoConjunto(
                $conjunto,
                $quem,
                PubAprovacaoEvento::APROVACAO,
                sprintf('Aprovou %d fotos sem alerta do conjunto compartilhado', $candidatas->count())
            );
        });

        Log::info(self::TAG.' fotos sem alerta aprovadas', [
            'conjunto_id' => $conjunto->id,
            'quantas' => $candidatas->count(),
            'quem_id' => $quem->id,
        ]);

        return $candidatas->count();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // IMG-01 / IMG-10 — REPROVAR (sai da galeria NA HORA e reabre o conjunto)
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **IMG-01 + IMG-10** — reprova a foto: ela sai da galeria final **na hora** e vai para "Fora
     * da publicação"; **se era a principal, a próxima vira principal**; e a aprovação de **todas**
     * as ofertas do conjunto é **reaberta**.
     *
     * ⚠️ **O motivo é obrigatório** (IMG-10), com `trim()` antes de conferir: "só espaço" não é
     * motivo. Sem ele **nada** é gravado — a recusa é no-op completo. Foto reprovada sem motivo
     * registrado é foto que ninguém sabe por que saiu, e o pedido de regeneração depende disso.
     *
     * @throws RegraViolada motivo vazio (IMG-10) ou imagem em `regenerating` (IMG-09)
     */
    public function reprovar(PubAprovacaoImagem $imagem, User $quem, string $motivo): PubAprovacaoImagem
    {
        $this->exigirQueAceiteAcao($imagem, 'reprovar');

        if (trim($motivo) === '') {
            throw new RegraViolada(
                self::REGRA_SEM_MOTIVO,
                'Escreva por que esta foto está sendo reprovada.',
                ['aprovacao_imagem_id' => $imagem->id]
            );
        }

        $conjunto = $imagem->conjunto;
        $rotulo = $this->rotuloDaImagem($imagem);

        DB::transaction(function () use ($imagem, $quem, $motivo, $conjunto, $rotulo) {
            // ⚠️ As TRÊS marcas de "fora da publicação" juntas: estado, `posicao` nula e `fora`.
            // Deixar só o estado faria a foto continuar ocupando lugar na ordem.
            $imagem->update([
                'estado' => PubAprovacaoImagem::REPROVADA,
                'posicao' => null,
                'fora' => true,
                'motivo' => trim($motivo),
                'quem_id' => $quem->id,
                'quando' => now(),
            ]);

            // ⚠️ E a próxima vira principal **por construção** (ver o docblock da classe).
            $this->reposicionar($conjunto);

            $this->registrarNoConjunto(
                $conjunto,
                $quem,
                PubAprovacaoEvento::REPROVACAO,
                sprintf('Reprovou a foto “%s”: %s', $rotulo, trim($motivo))
            );

            $this->reabrirConjunto($conjunto, AprovacaoDaOfertaService::FOTO_REPROVADA, $quem, $rotulo);
        });

        return $imagem->refresh();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // IMG-03 / IMG-08 — SUBSTITUIR E RECOLOCAR
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **IMG-03** — substitui a foto por uma **original**, que entra **NA MESMA POSIÇÃO**.
     *
     * ⚠️ **Se a original já estava aprovada, a aprovação é MANTIDA**; senão, ela entra `pending` e
     * precisa ser aprovada (IMG-02). Zerar a aprovação de quem já foi conferida obrigaria a pessoa
     * a reconferir a MESMA foto que ela mesma aprovou cinco minutos antes.
     *
     * @throws RegraViolada original de outro rascunho, ou imagem em `regenerating` (IMG-09)
     */
    public function substituirPorOriginal(PubAprovacaoImagem $imagem, PubImagem $original, User $quem): PubAprovacaoImagem
    {
        $this->exigirQueAceiteAcao($imagem, 'substituir');

        $conjunto = $imagem->conjunto;

        if ((int) $original->rascunho_id !== (int) $conjunto->rascunho_id) {
            throw new RegraViolada(
                self::REGRA_ORIGINAL_DE_OUTRO_RASCUNHO,
                'Esta imagem original é de outro anúncio.',
                ['aprovacao_imagem_id' => $imagem->id, 'imagem_id' => $original->id]
            );
        }

        $posicao = $imagem->posicao;
        $rotulo = $this->rotuloDaImagem($imagem);

        $entrante = DB::transaction(function () use ($imagem, $original, $quem, $conjunto, $posicao, $rotulo) {
            // A linha de revisão da original neste conjunto — pode já existir (ela pode ter sido
            // reprovada ou aprovada antes). O unique é (`conjunto_id`, `imagem_id`).
            $entrante = PubAprovacaoImagem::query()
                ->where('conjunto_id', $conjunto->id)
                ->where('imagem_id', $original->id)
                ->first();

            // ⚠️ `APROVADA` é PRESERVADA; qualquer outro estado entra como `pending` (IMG-02).
            $estado = $entrante !== null && $entrante->estado === PubAprovacaoImagem::APROVADA
                ? PubAprovacaoImagem::APROVADA
                : PubAprovacaoImagem::PENDENTE;

            $dados = [
                'estado' => $estado,
                'posicao' => $posicao,
                'fora' => false,
                'tipo' => PubAprovacaoImagem::TIPO_ORIGINAL,
            ];

            if ($entrante === null) {
                $entrante = PubAprovacaoImagem::create(array_merge($dados, [
                    'conjunto_id' => $conjunto->id,
                    'imagem_id' => $original->id,
                ]));
            } else {
                $entrante->update($dados);
            }

            // A substituída sai da publicação, com o estado PRÓPRIO (`replaced`, não `rejected`:
            // ela não foi reprovada por ninguém).
            $imagem->update([
                'estado' => PubAprovacaoImagem::SUBSTITUIDA,
                'posicao' => null,
                'fora' => true,
                'quem_id' => $quem->id,
                'quando' => now(),
            ]);

            $this->reposicionar($conjunto);

            $this->registrarNoConjunto(
                $conjunto,
                $quem,
                PubAprovacaoEvento::ALTERACAO,
                sprintf('Substituiu “%s” pela original “%s”', $rotulo, $this->rotuloDaImagem($entrante->refresh()))
            );

            $this->reabrirConjunto($conjunto, AprovacaoDaOfertaService::FOTO_SUBSTITUIDA, $quem, $rotulo);

            return $entrante;
        });

        return $entrante->refresh();
    }

    /**
     * **IMG-08** — recoloca uma original **reprovada ou substituída**: ela volta para `pending`,
     * sai de "Fora da publicação" e entra **no fim** da galeria (o `addOrig` do protótipo,
     * `:1156`, concatena no fim).
     *
     * ⚠️ Ela volta `pending` **mesmo se já tinha sido aprovada antes**, e isso é deliberado: a
     * foto saiu da publicação por alguma razão, e quem a traz de volta está tomando uma decisão
     * nova. É o que o protótipo faz (`['rejected','replaced'].includes(st) ⇒ pending`).
     */
    public function recolocarOriginal(PubAprovacaoImagem $imagem, User $quem): PubAprovacaoImagem
    {
        $conjunto = $imagem->conjunto;
        $rotulo = $this->rotuloDaImagem($imagem);

        DB::transaction(function () use ($imagem, $quem, $conjunto, $rotulo) {
            $fim = $this->idsNaGaleria($conjunto);

            $imagem->update([
                'estado' => PubAprovacaoImagem::PENDENTE,
                'fora' => false,
                'posicao' => count($fim),
                'quem_id' => $quem->id,
                'quando' => now(),
            ]);

            $this->reposicionar($conjunto);

            $this->registrarNoConjunto(
                $conjunto,
                $quem,
                PubAprovacaoEvento::ALTERACAO,
                sprintf('Recolocou a foto “%s” na publicação · aguardando revisão', $rotulo)
            );

            $this->reabrirConjunto($conjunto, AprovacaoDaOfertaService::FOTO_ADICIONADA, $quem, $rotulo);
        });

        return $imagem->refresh();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // IMG-07 — ORDEM E PRINCIPAL
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * **IMG-07** — reordena a galeria final.
     *
     * ⚠️ A ordem recebida tem de ser **exatamente** o conjunto final corrente: id a mais mandaria
     * foto de fora para dentro sem decisão, e id a menos deixaria foto sem posição — e `posicao`
     * nula **é** "fora da publicação", então o silêncio aqui **apagaria** uma foto da galeria.
     *
     * @param  list<int>  $ordem  ids de `pub_aprovacao_imagens`, na ordem nova
     *
     * @throws RegraViolada quando a ordem não é exatamente o conjunto final corrente
     */
    public function reordenar(PubAprovacaoConjunto $conjunto, array $ordem, User $quem): PubAprovacaoConjunto
    {
        $atual = $this->idsNaGaleria($conjunto);

        $recebida = array_map('intval', array_values($ordem));
        $a = $recebida;
        $b = $atual;
        sort($a);
        sort($b);

        if ($a !== $b) {
            throw new RegraViolada(
                self::REGRA_ORDEM_INCOMPLETA,
                'A ordem enviada não corresponde às fotos que estão na publicação.',
                ['conjunto_id' => $conjunto->id, 'recebida' => $recebida, 'atual' => $atual]
            );
        }

        DB::transaction(function () use ($conjunto, $recebida, $quem) {
            $this->reposicionar($conjunto, $recebida);

            $this->registrarNoConjunto(
                $conjunto,
                $quem,
                PubAprovacaoEvento::ALTERACAO,
                'Alterou a ordem da galeria'
            );

            $this->reabrirConjunto($conjunto, AprovacaoDaOfertaService::FOTOS_REORDENADAS, $quem);
        });

        return $conjunto->refresh();
    }

    /**
     * **IMG-07** — define a foto principal: ela vai para a **posição 0** e as outras escorregam.
     *
     * ⚠️ *"A principal é sempre a primeira da ordem"* é invariante da escrita, não regra a
     * lembrar: {@see self::reposicionar()} carimba `principal_imagem_id` com a imagem da posição 0
     * depois de **toda** mudança.
     */
    public function definirPrincipal(PubAprovacaoImagem $imagem, User $quem): PubAprovacaoConjunto
    {
        $conjunto = $imagem->conjunto;
        $rotulo = $this->rotuloDaImagem($imagem);

        DB::transaction(function () use ($conjunto, $imagem, $quem, $rotulo) {
            $ordem = array_values(array_filter($this->idsNaGaleria($conjunto), fn (int $id) => $id !== (int) $imagem->id));
            array_unshift($ordem, (int) $imagem->id);

            $this->reposicionar($conjunto, $ordem);

            $this->registrarNoConjunto(
                $conjunto,
                $quem,
                PubAprovacaoEvento::ALTERACAO,
                sprintf('Definiu “%s” como foto principal', $rotulo)
            );

            $this->reabrirConjunto($conjunto, AprovacaoDaOfertaService::PRINCIPAL_ALTERADA, $quem, $rotulo);
        });

        return $conjunto->refresh();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════
    // A REGENERAÇÃO — só a ida e a volta; o Creative Engine é da FASE 192
    // ═════════════════════════════════════════════════════════════════════════════════════════

    /**
     * Leva a imagem a `regenerating` e registra o pedido.
     *
     * ⚠️ **A geração em si é da Fase 192** (IA-01). Daqui não sai chamada ao Creative Engine, nem
     * a `app/Services/Creative/`, nem ao Mercado Livre.
     * ⚠️ `regenerating` **não** é mudança do conjunto final: a foto antiga continua no lugar até a
     * nova chegar, então **não reabre** aqui — quem reabre é {@see self::concluirRegeneracao()}.
     *
     * @throws RegraViolada imagem já em `regenerating` (IMG-09)
     */
    public function marcarRegenerando(PubAprovacaoImagem $imagem, User $quem, string $nota = ''): PubAprovacaoImagem
    {
        $this->exigirQueAceiteAcao($imagem, 'regenerar');

        $conjunto = $imagem->conjunto;
        $rotulo = $this->rotuloDaImagem($imagem);

        DB::transaction(function () use ($imagem, $quem, $conjunto, $rotulo, $nota) {
            $imagem->update([
                'estado' => PubAprovacaoImagem::REGENERANDO,
                'quem_id' => $quem->id,
                'quando' => now(),
            ]);

            $this->registrarNoConjunto(
                $conjunto,
                $quem,
                PubAprovacaoEvento::ALTERACAO,
                sprintf('Pediu nova geração com IA de “%s”%s', $rotulo, trim($nota) === '' ? '' : ': '.trim($nota))
            );
        });

        return $imagem->refresh();
    }

    /**
     * A nova versão chegou: ela entra `pending` **na mesma posição** (IMG-02), a antiga vira
     * `replaced` e vai para "Fora da publicação", e a aprovação do conjunto é **reaberta**.
     *
     * ⚠️ Quem chama isto é a **Fase 192**, com a imagem que o Creative Engine produziu. Aqui a
     * `PubImagem` já existe — este serviço **não cria arquivo e não chama IA**.
     */
    public function concluirRegeneracao(PubAprovacaoImagem $antiga, PubImagem $nova, User $quem): PubAprovacaoImagem
    {
        $conjunto = $antiga->conjunto;
        $posicao = $antiga->posicao;
        $rotulo = $this->rotuloDaImagem($antiga);

        $entrante = DB::transaction(function () use ($antiga, $nova, $quem, $conjunto, $posicao, $rotulo) {
            $entrante = PubAprovacaoImagem::create([
                'conjunto_id' => $conjunto->id,
                'imagem_id' => $nova->id,
                'tipo' => PubAprovacaoImagem::TIPO_IA,
                // ⚠️ IMG-02 — imagem regenerada entra `pending`: ela nunca foi conferida.
                'estado' => PubAprovacaoImagem::PENDENTE,
                'posicao' => $posicao,
                'fora' => false,
                'nova' => true,
            ]);

            $antiga->update([
                'estado' => PubAprovacaoImagem::SUBSTITUIDA,
                'posicao' => null,
                'fora' => true,
            ]);

            $this->reposicionar($conjunto);

            $this->registrarNoConjunto(
                $conjunto,
                null,
                PubAprovacaoEvento::SISTEMA,
                sprintf('Nova versão gerada no lugar de “%s” · aguardando revisão', $rotulo)
            );

            $this->reabrirConjunto($conjunto, AprovacaoDaOfertaService::FOTO_REGENERADA, $quem, $rotulo);

            return $entrante;
        });

        return $entrante->refresh();
    }

    // ═════════════════════════════════════════════════════════════════════════════════════════

    /** ⚠️ IMG-09 — as quatro ações recusadas em `regenerating`, numa guarda só. */
    private function exigirQueAceiteAcao(PubAprovacaoImagem $imagem, string $acao): void
    {
        if ($imagem->aceitaAcao()) {
            return;
        }

        throw new RegraViolada(
            self::REGRA_REGENERANDO,
            'Esta foto está sendo refeita pela IA. Espere a nova versão para '.$acao.'.',
            ['aprovacao_imagem_id' => $imagem->id, 'acao' => $acao]
        );
    }

    /**
     * ⚠️ **A REABERTURA NÃO É FEITA AQUI** — ela é DELEGADA a
     * {@see AprovacaoDaOfertaService::registrarMudanca()}, que é o único lugar que incrementa a
     * `versao` e devolve a oferta `aprovado` para `aguardando_aprovacao`.
     *
     * Este método só escolhe **uma** oferta do conjunto para entrar; a propagação para as **duas**
     * é de lá (escopo `conjunto`). ⚠️ Conjunto sem oferta nenhuma (rascunho que ainda não gerou as
     * ofertas) é **no-op silencioso de propósito**: não há aprovação a reabrir.
     */
    private function reabrirConjunto(PubAprovacaoConjunto $conjunto, string $mudanca, ?User $quem, string $detalhe = ''): void
    {
        $alguma = PubAprovacaoOferta::query()
            ->where('rascunho_id', $conjunto->rascunho_id)
            ->orderBy('id')
            ->first();

        if ($alguma === null) {
            return;
        }

        $this->aprovacao->registrarMudanca($alguma, $mudanca, $quem, $detalhe);
    }

    /**
     * ⚠️ IMG-04 — o `touched` do protótipo, no conjunto **e em todas as ofertas dele**.
     *
     * Ele alimenta a escolha do "PRÓXIMO A REVISAR" e o anel de progresso da fila: sem marcar as
     * duas ofertas, o Premium pareceria "ainda não analisado" depois de alguém aprovar as fotos
     * compartilhadas.
     */
    private function marcarTocado(PubAprovacaoConjunto $conjunto): void
    {
        $conjunto->update(['tocado' => true]);

        PubAprovacaoOferta::query()
            ->where('rascunho_id', $conjunto->rascunho_id)
            ->update(['tocado' => true]);
    }

    /** Registra o MESMO fato na linha do tempo de **todas** as ofertas do conjunto. */
    private function registrarNoConjunto(PubAprovacaoConjunto $conjunto, ?User $quem, string $tipo, string $texto): void
    {
        $ofertas = PubAprovacaoOferta::query()
            ->where('rascunho_id', $conjunto->rascunho_id)
            ->orderBy('id')
            ->get();

        foreach ($ofertas as $oferta) {
            $this->historico->registrar($oferta, $quem, $tipo, $texto, (int) $oferta->versao);
        }
    }

    /**
     * Reescreve as `posicao` como `0..n-1` e carimba a **principal** com a imagem da posição 0.
     *
     * @param  list<int>|null  $ordem  a ordem nova; `null` = mantém a corrente e só recompacta
     */
    private function reposicionar(PubAprovacaoConjunto $conjunto, ?array $ordem = null): void
    {
        $ids = $ordem ?? $this->idsNaGaleria($conjunto);

        foreach (array_values($ids) as $i => $id) {
            PubAprovacaoImagem::query()->where('id', $id)->update(['posicao' => $i]);
        }

        // ⚠️ A capa é SEMPRE a da posição 0. Galeria vazia ⇒ **`null`**, nunca a última capa
        // conhecida: capa apontando para foto que saiu da publicação é exatamente o tipo de
        // ponteiro velho que faria o anúncio subir com a foto errada.
        $primeira = $ids === [] ? null : PubAprovacaoImagem::query()->find(array_values($ids)[0]);

        $conjunto->update(['principal_imagem_id' => $primeira?->imagem_id]);
    }

    /**
     * Os ids de `pub_aprovacao_imagens` que estão **na galeria final**, na ordem corrente.
     *
     * ⚠️ O filtro é `noConjuntoFinal()` — estado vivo **E** `posicao` preenchida **E** `fora`
     * falso, as três. Conferir só o estado deixaria foto `fora = true` ocupando posição.
     *
     * @return list<int>
     */
    private function idsNaGaleria(PubAprovacaoConjunto $conjunto): array
    {
        /** @var Collection<int, PubAprovacaoImagem> $imagens */
        $imagens = $conjunto->imagens()->orderBy('posicao')->orderBy('id')->get();

        return $imagens
            ->filter(fn (PubAprovacaoImagem $i) => $i->noConjuntoFinal())
            ->map(fn (PubAprovacaoImagem $i) => (int) $i->id)
            ->values()
            ->all();
    }

    /**
     * O rótulo legível de uma foto. ⚠️ **Derivado** — não existe coluna de nome neste banco (ver
     * o docblock da classe).
     */
    private function rotuloDaImagem(PubAprovacaoImagem $imagem): string
    {
        $arquivo = trim((string) $imagem->imagem?->caminho);
        $arquivo = $arquivo === '' ? '' : ' · '.basename($arquivo);

        return $imagem->posicao === null || $imagem->fora
            ? 'Fora da publicação'.$arquivo
            : 'Imagem #'.((int) $imagem->posicao + 1).$arquivo;
    }
}
