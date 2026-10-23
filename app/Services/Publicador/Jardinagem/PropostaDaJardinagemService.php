<?php

namespace App\Services\Publicador\Jardinagem;

use App\Models\PubJardinagem;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\PalavrasChaveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * O título da NOVA OFERTA do anúncio sem tração (23/10/2026). A árvore do MAG manda "excluir e criar
 * uma nova oferta"; a nova oferta testa os termos mais buscados DESTA semana (a lista do ML muda toda
 * semana), sem sair da metodologia do título (as mesmas regras e o mesmo prompt do preparo).
 *
 * - **Termos novos**: os da lista da semana que têm a ver com o produto (a pista `relacionado`) e trazem
 *   alguma palavra que o título de agora não tem (`PalavrasChaveService::trazPalavraNova`), na ordem do ML.
 *   Vão para a IA como prioridade — ela ainda descarta o que não descreve o produto (filtro de coerência).
 * - **Nunca repete**: o título de agora, os de meses anteriores e as propostas anteriores do produto, os
 *   dos outros anúncios do mesmo produto e os do rascunho.
 *
 * Só grava em `pub_jardinagens.proposta`. Nada vai ao rascunho nem ao Mercado Livre: o rascunho de um
 * produto publicado é intocável pela IA, e encerrar/criar anúncio ainda é a equipe quem faz.
 */
class PropostaDaJardinagemService
{
    public function __construct(
        private PalavrasChaveService $palavras,
    ) {}

    /** Gera e grava a proposta. Erro conhecido vira `estado: erro` com a mensagem para a tela. */
    public function gerar(PubJardinagem $j, ?float $prazo = null): PubJardinagem
    {
        $r = PubRascunho::with('produto')->find($j->rascunho_id);
        if ($r === null || $r->produto === null) {
            return $this->terminar($j, ['estado' => 'erro', 'erro' => 'O produto deste anúncio não está mais no Publicador.']);
        }

        $tituloAtual = trim((string) ($j->titulo ?? ''));
        $termosNovos = $this->termosNovos($r, $tituloAtual);
        $evitar = $this->titulosJaUsados($j, $r);

        try {
            $titulos = $this->palavras->gerarTitulosDaJardinagem($r, $evitar, $termosNovos, $prazo);
        } catch (\Throwable $e) {
            Log::warning("[Publicador Jardinagem] título novo de {$j->ml_item_id} (linha {$j->id}) falhou: {$e->getMessage()}");

            return $this->terminar($j, ['estado' => 'erro', 'termos_novos' => $termosNovos,
                'erro' => 'A IA não gerou o título agora. Tente de novo em alguns minutos.']);
        }

        $tipo = $j->listing_type_id === 'gold_pro' ? 'gold_pro' : 'gold_special';
        $outro = $tipo === 'gold_pro' ? 'gold_special' : 'gold_pro';
        $novo = trim((string) ($titulos[$tipo] ?: $titulos[$outro]));
        if ($novo === '') {
            return $this->terminar($j, ['estado' => 'erro', 'termos_novos' => $termosNovos,
                'erro' => 'A IA só achou títulos iguais aos que este produto já usou. Tente de novo ou escreva à mão.']);
        }

        Log::info("[Publicador Jardinagem] título novo de {$j->ml_item_id} (linha {$j->id}): \"{$novo}\" (".count($termosNovos).' termo(s) novo(s)).');

        return $this->terminar($j, ['estado' => 'pronta', 'titulo_novo' => $novo, 'termos_novos' => $termosNovos, 'erro' => null]);
    }

    /** O Job morreu (tempo, worker): a tela sai do "gerando" com o motivo. */
    public function falhou(int $id, string $mensagem): void
    {
        $j = PubJardinagem::find($id);
        if ($j !== null && ($j->proposta['estado'] ?? null) === 'gerando') {
            $this->terminar($j, ['estado' => 'erro', 'erro' => $mensagem]);
        }
    }

    /**
     * Os termos da semana que trazem palavra nova ao título, relacionados ao produto primeiro.
     *
     * @return list<string>
     */
    public function termosNovos(PubRascunho $r, string $tituloAtual): array
    {
        try {
            $lista = $this->palavras->termos($r)['termos'] ?? [];
        } catch (\Throwable $e) {
            // Sem a lista do ML a IA ainda trabalha (com o nome e os fatos); só não há prioridade.
            Log::warning("[Publicador Jardinagem] termos da semana indisponíveis para o rascunho {$r->id}: {$e->getMessage()}");

            return [];
        }

        $doTitulo = PalavrasChaveService::palavrasDoTitulo($tituloAtual);
        $novos = array_values(array_filter($lista, fn ($t) => ! empty($t['relacionado'])
            && PalavrasChaveService::trazPalavraNova((string) ($t['termo'] ?? ''), $doTitulo)));
        usort($novos, fn ($a, $b) => [($a['origem'] ?? '') === 'acima' ? 1 : 0, (int) ($a['posicao'] ?? 0)]
            <=> [($b['origem'] ?? '') === 'acima' ? 1 : 0, (int) ($b['posicao'] ?? 0)]);

        $limite = max(1, (int) config('publicador.jardinagem.termos_priorizados', 8));

        return array_slice(array_values(array_unique(array_map(fn ($t) => (string) $t['termo'], $novos))), 0, $limite);
    }

    /** @return list<string> todo título que este produto já usou ou já recebeu como proposta */
    private function titulosJaUsados(PubJardinagem $j, PubRascunho $r): array
    {
        $titulos = [(string) $j->titulo];

        $doProduto = PubJardinagem::query()
            ->where(fn ($q) => $q->where('rascunho_id', $r->id)->when($j->produto_id !== null, fn ($q) => $q->orWhere('produto_id', $j->produto_id)))
            ->get(['titulo', 'proposta']);
        foreach ($doProduto as $outra) {
            $titulos[] = (string) $outra->titulo;
            $titulos[] = (string) ($outra->proposta['titulo_novo'] ?? '');
        }

        foreach ($r->alvos()->pluck('titulo') as $t) {
            $titulos[] = (string) $t;
        }

        $publicados = PubPublicacaoItem::query()
            ->whereIn('publicacao_id', $r->publicacoes()->pluck('id'))
            ->where('status', PubPublicacaoItem::CREATED)
            ->get(['payload']);
        foreach ($publicados as $item) {
            $titulos[] = (string) ($item->payload['family_name'] ?? $item->payload['title'] ?? '');
        }

        return array_values(array_unique(array_filter(array_map('trim', $titulos))));
    }

    private function terminar(PubJardinagem $j, array $campos): PubJardinagem
    {
        return DB::transaction(function () use ($j, $campos) {
            $travada = PubJardinagem::query()->whereKey($j->id)->lockForUpdate()->firstOrFail();
            $travada->update(['proposta' => [
                'titulo_atual' => $travada->titulo,
                'titulo_novo' => null,
                'termos_novos' => [],
                ...(array) ($travada->proposta ?? []),
                ...$campos,
                'em' => now()->toIso8601String(),
            ]]);

            return $travada;
        });
    }
}
