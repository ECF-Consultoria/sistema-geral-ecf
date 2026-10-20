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
    public function __construct(
        private ProgramasPublicadorService $programas,
        private AndamentoDaOfertaService $andamento,
        private ElegibilidadeDoAgente $elegibilidade,
        private ExecucoesDoAgente $execucoes,
    ) {}

    /**
     * @return string o que aconteceu: encaminhada, aguardando_texto, bloqueada, nao_viva
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
        if ($this->preparo()->textoEmAndamento($r)) {
            $execucao->update(['etapa' => 'gerando_texto', 'heartbeat_em' => now()]);

            return 'aguardando_texto';
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
     * @return array{encaminhada: int, aguardando_texto: int, bloqueada: int, nao_viva: int, erro: int}
     */
    public function encaminharProntas(?string $contaChave = null, int $limite = 50): array
    {
        $resumo = ['encaminhada' => 0, 'aguardando_texto' => 0, 'bloqueada' => 0, 'nao_viva' => 0, 'erro' => 0];
        PubAgenteExecucao::query()
            ->where('estado', PubAgenteExecucao::PREPARANDO)
            ->when($contaChave !== null, fn ($q) => $q->where('conta_chave', $contaChave))
            ->orderBy('id')->limit($limite)->get()
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
