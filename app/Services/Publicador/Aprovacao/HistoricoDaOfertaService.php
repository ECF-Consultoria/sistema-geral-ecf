<?php

namespace App\Services\Publicador\Aprovacao;

use App\Models\PubAprovacaoEvento;
use App\Models\PubAprovacaoOferta;
use App\Models\User;
use App\Support\Publicador\RegraViolada;
use Illuminate\Support\Facades\Log;

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 * A ÚNICA PORTA DE ESCRITA DO HISTÓRICO DA OFERTA DO PORTÃO (`pub_aprovacao_eventos`).
 *
 * ⚠️⚠️ **ESTE SERVIÇO É O QUE TORNA "AUDITORIA DEPOIS" VERDADE.** O usuário decidiu em 14/10/2026
 * que **aprovar JÁ PUBLICA**, sem segundo clique e **sem desfazer**, e que a exceção de
 * prejuízo/margem é aplicada **direto** por quem revisa (BLOQ-09). As duas decisões trocam
 * "barreira ANTES" por "auditoria DEPOIS" — e auditoria depois só existe se o registro existir:
 * quem fez, quando, o quê, em que versão. **Sem este serviço, "auditoria depois" é uma frase.**
 *
 * ═══ ⚠️ A TABELA É **APPEND-ONLY**, E ESTE SERVIÇO SÓ INSERE (MIG-09 / T-186-18) ═══════════════
 *
 * Não há método de apagar, editar, ocultar ou limpar — **nem aqui nem em lugar nenhum**. Nenhuma
 * rotina de retenção toca `pub_aprovacao_eventos`. ⚠️ Não confundir com a retenção de 48 h dos
 * criativos (`LimparReferenciasCriativos`), que é de arquivo de imagem e é território de outro
 * dev.
 *
 * ═══ COL-05 — AUTOR E DATA **REAIS** ═══════════════════════════════════════════════════════════
 *
 * `ocorrido_em` vem de `now()` **do servidor**; nada de data vinda do cliente. O autor é a pessoa
 * ou o ator **Sistema** (`autor_id = NULL` + `ator_sistema = true`) — **nunca** um usuário
 * inventado, e **nunca** nome vazio (`PubAprovacaoEvento::autorExibido()`).
 *
 * ═══ COL-06 — A DATA SAI **FORMATADA** DO SERVIDOR ═════════════════════════════════════════════
 *
 * `linhaDoTempo()` devolve só **escalares**: nenhum objeto de data atravessa para o cliente.
 * `new Date()` no navegador já produziu `Invalid Date` e deslocamento de fuso de data-only em BRT
 * neste projeto — por isso a formatação é do servidor, e há teste que recusa qualquer
 * `DateTimeInterface` no retorno.
 *
 * ═══ ⚠️ A LIGAÇÃO COM O `AndamentoDaOfertaService` (plano 186-04) ══════════════════════════════
 *
 * Aquele serviço é a **única porta de transição de andamento** e deixou o ponto de extensão
 * `registrar()` **nomeado para este plano**. ➡️ **A ligação acontece LÁ, no corpo daquele método,
 * e em mais lugar nenhum**: o 186-05 trocou só o corpo do gancho, sem tocar em `mover()` nem na
 * transação única. É por isso que o evento da transição é gravado **dentro** da mesma
 * `DB::transaction` que gravou `aprovado_por`/`aprovado_em`/`versao_aprovada` — aprovar sem a
 * prova do que foi aprovado é o que não pode acontecer, porque não há desfazer (T-186-13).
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 */
class HistoricoDaOfertaService
{
    /** O `tipo` não é um dos 8 de `PubAprovacaoEvento::TIPOS` (lista fechada, default = recusa). */
    public const REGRA_TIPO_DESCONHECIDO = 'DADO-10-TIPO-DE-EVENTO-DESCONHECIDO';

    /** O texto do evento é obrigatório: linha de histórico sem frase não registra nada. */
    public const REGRA_TEXTO_VAZIO = 'DADO-10-EVENTO-SEM-TEXTO';

    /** O prefixo de módulo do log (convenção do projeto). */
    private const TAG = '[Publicador Aprovação]';

    /**
     * Grava uma linha no histórico da oferta. **A única porta de escrita.**
     *
     * @param  PubAprovacaoOferta  $oferta  a oferta a que o fato pertence
     * @param  User|null  $quem  quem fez; **`null` = Sistema** (ator próprio, COL-05)
     * @param  string  $tipo  um dos 8 de `PubAprovacaoEvento::TIPOS`
     * @param  string  $texto  a frase REAL do que aconteceu, já com os valores dentro
     * @param  int|null  $versao  a versão da oferta no instante do fato
     *
     * @throws RegraViolada tipo fora dos 8, ou texto vazio
     */
    public function registrar(PubAprovacaoOferta $oferta, ?User $quem, string $tipo, string $texto, ?int $versao = null): PubAprovacaoEvento
    {
        // ⚠️ RECUSA 1 — lista fechada. `tipo` livre faria o filtro por tipo mentir, e um evento de
        // exceção poderia entrar como `ok`. Nada é gravado: a recusa é um no-op completo.
        if (! PubAprovacaoEvento::tipoValido($tipo)) {
            throw new RegraViolada(
                self::REGRA_TIPO_DESCONHECIDO,
                sprintf('"%s" não é um tipo de evento do histórico.', $tipo),
                ['oferta_id' => $oferta->id, 'tipo' => $tipo]
            );
        }

        // ⚠️ RECUSA 2 — frase vazia. Linha de histórico sem texto é pior que nenhuma: ela ocupa a
        // linha do tempo e não diz nada, e quem auditar vai achar que o registro está completo.
        if (trim($texto) === '') {
            throw new RegraViolada(
                self::REGRA_TEXTO_VAZIO,
                'O histórico não registra evento sem descrição.',
                ['oferta_id' => $oferta->id, 'tipo' => $tipo]
            );
        }

        $evento = PubAprovacaoEvento::create([
            'oferta_id' => $oferta->id,
            'tipo' => $tipo,
            'texto' => trim($texto),
            // ⚠️ COL-05 — as duas colunas juntas. `autor_id` nulo sozinho é ambíguo (o `SET NULL`
            // de usuário apagado também deixa nulo); `ator_sistema` é quem desambigua.
            'autor_id' => $quem?->id,
            'ator_sistema' => $quem === null,
            'versao' => $versao,
            // ⚠️ COL-05 — a data é do SERVIDOR. Nunca do cliente.
            'ocorrido_em' => now(),
        ]);

        // Rastreio com id + NOME da entidade (convenção do projeto): sem o nome, o log de produção
        // obriga a abrir o banco para saber de que produto se fala.
        Log::info(self::TAG . ' evento registrado', [
            'evento_id' => $evento->id,
            'oferta_id' => $oferta->id,
            'produto' => $oferta->produto?->sku . ' (' . $oferta->produto?->nome . ')',
            'tipo' => $tipo,
            'autor_id' => $quem?->id,
            'ator_sistema' => $quem === null,
            'versao' => $versao,
        ]);

        return $evento;
    }

    /**
     * A linha do tempo de uma oferta, **em ordem do fato** e **pronta para a tela**.
     *
     * ⚠️ Ordena por `ocorrido_em` (o FATO), não por `id` (a linha) — um backfill separaria os dois
     * e a tela contaria a história na ordem errada. Empate completo desempata por `id`, para a
     * ordem ser estável entre duas chamadas.
     *
     * ⚠️⚠️ **COL-06 — só ESCALARES no retorno.** Nenhum objeto de data sai daqui: a formatação é
     * do servidor, e há teste que recusa qualquer `DateTimeInterface` em qualquer campo.
     *
     * @return list<array{tipo: string, icone: string, rotulo: string, cor: string, texto: string, autor: string, ator_sistema: bool, versao: int|null, ocorrido_formatado: string}>
     */
    public function linhaDoTempo(PubAprovacaoOferta $oferta): array
    {
        $eventos = PubAprovacaoEvento::query()
            ->with('autor')
            ->where('oferta_id', $oferta->id)
            ->orderBy('ocorrido_em')
            ->orderBy('id')
            ->get();

        return $eventos->map(function (PubAprovacaoEvento $e) {
            $entrada = PubAprovacaoEvento::entradaDe($e->tipo) ?? ['icone' => '•', 'rotulo' => '—', 'cor' => '#52525B'];

            return [
                'tipo' => (string) $e->tipo,
                'icone' => $entrada['icone'],
                'rotulo' => $entrada['rotulo'],
                'cor' => $entrada['cor'],
                'texto' => (string) $e->texto,
                // ⚠️ Nunca vazio (COL-05).
                'autor' => $e->autorExibido(),
                'ator_sistema' => (bool) $e->ator_sistema,
                'versao' => $e->versao === null ? null : (int) $e->versao,
                // ⚠️ COL-06 — formatada aqui, nunca no cliente.
                'ocorrido_formatado' => $e->ocorridoFormatado(),
            ];
        })->values()->all();
    }
}
