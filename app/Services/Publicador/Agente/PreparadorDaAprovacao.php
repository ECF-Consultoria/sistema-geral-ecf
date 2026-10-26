<?php

namespace App\Services\Publicador\Agente;

use App\Models\PubAgenteConfiguracao;
use App\Models\PubAgenteExecucao;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Models\PubAprovacaoOferta;
use App\Models\PubImagemAtribuicao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Services\Publicador\ProgramasPublicadorService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Leva o rascunho que o agente preparou para a fila de Aprovação que já existe (AG-03, 20/10/2026).
 *
 * É o primeiro código de produção que cria `PubAprovacaoOferta` — a 194 (AUTO-01/02) deve reaproveitar este
 * caminho, não abrir outro. Não aprova nada: a oferta nasce `preparando` e passa a `aguardando_aprovacao`
 * pela porta única (`AndamentoDaOfertaService::mover`, ator de sistema). A aprovação continua sendo da pessoa.
 *
 * Idempotente: uma oferta por alvo (unique `alvo_id`), um conjunto por (rascunho, grupo), uma linha de revisão
 * por (conjunto, foto) — sempre `firstOrCreate`, NUNCA `updateOrCreate`: o parecer de quem já revisou uma foto
 * (estado, quem, quando, motivo) não é apagado por uma reexecução. Oferta que já saiu de `preparando` não é
 * tocada (nem fotos, nem andamento): mudança depois disso passa pelos serviços da revisão, que sobem a versão.
 *
 * Âncoras (decisão 17 / G4): as DUAS, pelo mesmo `ProgramasPublicadorService::resolver` que a fila usa. Conta
 * `empresa-N` sem Company não tem fila alcançável (a rota da Aprovação é por Company): bloqueia com o motivo.
 */
class PreparadorDaAprovacao
{
    // ⚠️⚠️ **`MAX_TENTATIVAS` SAIU DAQUI EM 26/10/2026, e o motivo é "uma fonte só".**
    //
    // O máximo de tentativas passou a ser opção da loja (`tentativas_max`), e o padrão vive em
    // `PubAgenteConfiguracao::TENTATIVAS_PADRAO` — que é o valor que `null` na coluna resolve.
    // Deixar a constante aqui com o mesmo `2` criaria **duas fontes do mesmo número**, e duas
    // cópias divergem: alguem mudaria o padrão num lugar e o outro seguiria valendo em silêncio.
    // ⚠️ Medido antes de remover: **ninguém fora desta classe a usava** (os outros
    // `MAX_TENTATIVAS` do projeto são de `PortalCodigoAcesso`, outra classe).

    public function __construct(
        private ProgramasPublicadorService $programas,
        private AndamentoDaOfertaService $andamento,
        private ElegibilidadeDoAgente $elegibilidade,
        private ExecucoesDoAgente $execucoes,
        private PoliticaDoAgente $politica,
    ) {}

    /**
     * @return string o que aconteceu: encaminhada, aguardando_texto, recuperando, pausada, bloqueada, nao_viva
     */
    public function encaminhar(PubAgenteExecucao $execucao): string
    {
        $execucao->refresh();
        if ($execucao->estado !== PubAgenteExecucao::PREPARANDO) {
            return 'nao_viva';
        }

        $pub = $execucao->produto_id !== null ? PubProduto::find($execucao->produto_id) : null;
        $cfg = PubAgenteConfiguracao::query()->where('conta_chave', $execucao->conta_chave)->first();
        $r = $pub !== null ? PubRascunho::where('produto_id', $pub->id)->first() : null;
        if ($pub === null || $r === null) {
            return $this->bloquear($execucao, 'produto_removido', 'O produto ou o rascunho deixou de existir.');
        }

        // Os fatos podem ter mudado durante a IA: a régua é a de agora.
        $eleg = $this->elegibilidade->avaliar($pub, $cfg);
        if (! $eleg['elegivel']) {
            $execucao->update(['bloqueios' => $eleg['pendencias']]);

            return $this->bloquear($execucao, null, implode(' ', array_column($eleg['pendencias'], 'mensagem')));
        }
        // AG-06: loja pausada/suspensa não anda — a execução fica viva e o "Retomar" continua de onde parou.
        if ($this->politica->recusaParaSeguir($execucao) !== null) {
            return 'pausada';
        }

        $texto = $this->preparo()->situacaoDoTexto($r);
        if ($texto === 'rodando') {
            $execucao->update(['etapa' => 'gerando_texto', 'heartbeat_em' => now()]);

            return 'aguardando_texto';
        }
        if ($texto !== 'completo') {
            return $this->recuperarTexto($execucao, $pub, $texto);
        }

        $conta = $this->programas->resolver($execucao->conta_chave);
        if ($conta === null || $conta['company'] === null) {
            return $this->bloquear($execucao, 'conta_sem_fila', 'Esta conta não tem Company ligada: a fila de Aprovação dela não é alcançável.');
        }
        $alvo = $r->alvos()->where('ativo', true)->where('listing_type_id', $cfg->modalidade)->first();
        if ($alvo === null) {
            return $this->bloquear($execucao, 'alvos_fora_do_piloto', 'A modalidade configurada não está ativa no rascunho.');
        }

        $oferta = $this->ofertaDoAlvo($alvo->id, $r, $pub, $conta);
        if ($oferta->andamento === PubAprovacaoOferta::PREPARANDO) {
            DB::transaction(function () use ($oferta, $r) {
                $this->projetarFotos($r);
                $oferta->update(['preparo_frase' => 'encaminhando para a revisão']);
            });
            $this->andamento->mover($oferta, PubAprovacaoOferta::AGUARDANDO_APROVACAO, null);
        }

        $execucao->update(['rascunho_id' => $r->id, 'etapa' => 'encaminhada', 'bloqueios' => null]);
        $this->execucoes->encerrar($execucao, PubAgenteExecucao::AGUARDANDO_APROVACAO);
        Log::info("[Publicador Agente] Produto {$pub->id} ({$execucao->conta_chave}) encaminhado à Aprovação — oferta {$oferta->id}.");

        return 'encaminhada';
    }

    /**
     * As execuções em `preparando` (de uma conta ou de todas) que já podem ir à Aprovação. Para o comando
     * agendado: uma falha numa execução não segura as outras.
     *
     * A mais esquecida primeiro (`heartbeat_em`): 50 execuções paradas no topo não escondem as de trás.
     *
     * @return array{encaminhada: int, aguardando_texto: int, recuperando: int, pausada: int, bloqueada: int, nao_viva: int, erro: int}
     */
    public function encaminharProntas(?string $contaChave = null, int $limite = 50): array
    {
        $resumo = ['encaminhada' => 0, 'aguardando_texto' => 0, 'recuperando' => 0, 'pausada' => 0, 'bloqueada' => 0, 'nao_viva' => 0, 'erro' => 0];
        PubAgenteExecucao::query()
            ->where('estado', PubAgenteExecucao::PREPARANDO)
            ->when($contaChave !== null, fn ($q) => $q->where('conta_chave', $contaChave))
            ->orderBy('heartbeat_em')->orderBy('id')->limit($limite)->get()
            ->each(function (PubAgenteExecucao $e) use (&$resumo) {
                try {
                    $resumo[$this->encaminhar($e)]++;
                } catch (\Throwable $ex) {
                    $resumo['erro']++;
                    Log::error("[Publicador Agente] Encaminhar a execução {$e->id} ({$e->conta_chave}) falhou: ".$ex->getMessage());
                }
            });

        return $resumo;
    }

    /** A oferta do alvo: a que já existe ou uma nova `preparando`, com as duas âncoras da fila. */
    private function ofertaDoAlvo(int $alvoId, PubRascunho $r, PubProduto $pub, array $conta): PubAprovacaoOferta
    {
        $existente = PubAprovacaoOferta::query()->where('alvo_id', $alvoId)->first();
        if ($existente !== null) {
            return $existente;
        }

        try {
            return PubAprovacaoOferta::create([
                'alvo_id' => $alvoId,
                'rascunho_id' => $r->id,
                'produto_id' => $pub->id,
                'company_id' => $conta['company']->id,
                'mlb_empresa_id' => $conta['mlb_empresa']?->id,
                'origem' => PubAprovacaoOferta::ORIGEM_AUTO,
                'preparo_frase' => 'organizando as fotos para a revisão',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Outro processo criou a oferta deste alvo ao mesmo tempo: é a mesma oferta.
            return PubAprovacaoOferta::query()->where('alvo_id', $alvoId)->firstOrFail();
        }
    }

    /**
     * Um conjunto por grupo das atribuições de foto do rascunho e uma linha `pending` por foto original, na
     * ordem do editor. Linha que já existe fica como está (parecer humano preservado). Principal = posição 0.
     */
    private function projetarFotos(PubRascunho $r): void
    {
        $atribuicoes = PubImagemAtribuicao::query()
            ->whereIn('imagem_id', $r->imagens()->select('id'))
            ->orderBy('grupo_hash')->orderBy('posicao')->orderBy('imagem_id')
            ->get()->groupBy('grupo_hash');

        foreach ($atribuicoes as $grupoHash => $doGrupo) {
            $conjunto = PubAprovacaoConjunto::firstOrCreate(
                ['rascunho_id' => $r->id, 'grupo_hash' => $grupoHash],
                ['grupo_chave' => $doGrupo->first()->grupo_chave],
            );
            foreach ($doGrupo->values() as $i => $a) {
                PubAprovacaoImagem::firstOrCreate(
                    ['conjunto_id' => $conjunto->id, 'imagem_id' => $a->imagem_id],
                    ['tipo' => PubAprovacaoImagem::TIPO_ORIGINAL, 'estado' => PubAprovacaoImagem::PENDENTE, 'posicao' => $i],
                );
            }
            if ($conjunto->principal_imagem_id === null) {
                $conjunto->update(['principal_imagem_id' => $doGrupo->first()->imagem_id]);
            }
        }
    }

    /**
     * A cadeia de texto morreu (Job perdido, erro da IA, desistiu de esperar) ou nunca começou para estes fatos:
     * pede o preparo de novo — `avaliarIa` refaz só as etapas que não deram certo — até o máximo
     * da LOJA (`tentativas_max`, 26/10/2026; `null` resolve para `PubAgenteConfiguracao::TENTATIVAS_PADRAO`).
     * Depois disso bloqueia com o motivo: a pessoa escreve o texto no editor e pede "Preparar agora".
     */
    private function recuperarTexto(PubAgenteExecucao $execucao, PubProduto $pub, string $texto): string
    {
        // ⚠️⚠️ **O MÁXIMO PASSOU A SER POR LOJA (26/10/2026).** Era a constante
        // `MAX_TENTATIVAS` desta classe, que **foi removida** para não duplicar o número: o
        // padrão passou a viver **só** em `PubAgenteConfiguracao::TENTATIVAS_PADRAO`, que é o que
        // `null` na coluna resolve. Logo nenhuma loja existente muda de comportamento.
        //
        // ⚠️ A configuração é lida pelo `configuracao_id` **da execução**, não pela conta: a
        // execução guarda com que configuração foi admitida, e mudar o limite no meio de um preparo
        // em curso não deve reescrever a regra que já valia para ele. Sem a linha (configuração
        // apagada, `nullOnDelete`), cai no padrão — nunca em zero por omissão, que faria a
        // recuperação morrer na primeira falha.
        $maximo = ($execucao->configuracao_id === null
            ? null
            : PubAgenteConfiguracao::query()->whereKey($execucao->configuracao_id)->first()
        )?->tentativasEfetivas() ?? PubAgenteConfiguracao::TENTATIVAS_PADRAO;

        if ($execucao->tentativas >= $maximo) {
            return $this->bloquear($execucao, 'texto_nao_concluido',
                'A IA não concluiu o texto depois de '.$maximo.' tentativa(s). Escreva no editor e peça "Preparar agora".');
        }

        $situacao = $this->preparo()->avaliarIa($pub);
        $execucao->update(['tentativas' => $execucao->tentativas + 1, 'etapa' => 'recuperando_texto', 'heartbeat_em' => now()]);
        Log::info("[Publicador Agente] Execução {$execucao->id} ({$execucao->conta_chave}): texto {$texto}, preparo pedido de novo — {$situacao}.");
        if (in_array($situacao, ['limite', 'desligado'], true)) {
            return $this->bloquear($execucao, 'ia_indisponivel', $situacao === 'limite'
                ? 'Limite diário de gerações de texto da empresa atingido.'
                : 'O preparo pela IA está desligado no sistema.');
        }

        return 'recuperando';
    }

    private function bloquear(PubAgenteExecucao $execucao, ?string $codigo, string $motivo): string
    {
        if ($codigo !== null) {
            $execucao->update(['bloqueios' => [['codigo' => $codigo, 'mensagem' => $motivo, 'origem' => 'agente', 'url_correcao' => null]]]);
        }
        $this->execucoes->encerrar($execucao, PubAgenteExecucao::BLOQUEADA, $motivo);

        return 'bloqueada';
    }

    private function preparo(): PreparoIaDoRascunhoService
    {
        return app(PreparoIaDoRascunhoService::class);
    }
}
