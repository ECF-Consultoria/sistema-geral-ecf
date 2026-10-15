<?php

namespace App\Services\Publicador;

use App\Jobs\Publicador\ExcluirAtivoDoAcervoJob;
use App\Models\AcervoImagem;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Exclusão segura de uma imagem do acervo da conta (v28.0 Fase 199, EXC-01..05).
 *
 * REGRA (decisão do usuário, 15/10/2026): a exclusão é BLOQUEADA se a imagem está em algum anúncio
 * que ainda não foi publicado (rascunho, validado, publicando, publicado em parte, falhou) — o
 * arquivo ainda vai subir ao Mercado Livre. Se ela só está em anúncios JÁ PUBLICADOS, a exclusão é
 * LIBERADA: o ML guardou a cópia dele e o anúncio não quebra.
 *
 * Banco e storage não têm transação comum, então: marca `excluindo` → job apaga o objeto (e a
 * miniatura) → `excluida`; falha repete e, esgotada, vira `falhou`. As linhas de `pub_imagens` dos
 * anúncios publicados NUNCA são apagadas (o histórico de aprovação da v27 aponta para elas em
 * cascata); o ativo fica como registro do que existiu.
 */
class AcervoExclusaoService
{
    /** Teto de imagens por exclusão em lote. */
    public const MAXIMO_LOTE = 100;

    /** Status de rascunho em que a imagem ainda pode subir ao ML — uso que bloqueia. */
    private const LIVRE_SO_EM = [PubRascunho::PUBLISHED];

    /**
     * Onde a imagem está em uso e se isso impede a exclusão.
     *
     * @return array{pode: bool, bloqueios: list<array{produto_id: ?int, produto: ?string, sku: ?string, status: string}>, publicados: int}
     */
    public function analisar(AcervoImagem $ativo): array
    {
        $usos = PubImagem::query()->where('acervo_imagem_id', $ativo->id)->with('rascunho.produto')->get();

        $bloqueios = [];
        $publicados = 0;
        foreach ($usos as $uso) {
            $r = $uso->rascunho;
            if ($r !== null && in_array($r->status, self::LIVRE_SO_EM, true)) {
                $publicados++;

                continue;
            }
            $bloqueios[] = [
                'produto_id' => $r?->produto_id,
                'produto' => $r?->produto?->nomeExibido() ?? $r?->produto?->nome,
                'sku' => $r?->produto?->sku,
                'status' => (string) ($r?->status ?? 'sem rascunho'),
            ];
        }

        return ['pode' => $bloqueios === [], 'bloqueios' => $bloqueios, 'publicados' => $publicados];
    }

    /**
     * Pede a exclusão: confere de novo os usos, marca `excluindo` e despacha o job.
     *
     * @return array{ok: bool, analise: array}
     */
    public function pedir(AcervoImagem $ativo): array
    {
        $resultado = Cache::lock(self::trava($ativo), 10)->block(5, function () use ($ativo) {
            $ativo->refresh();
            $analise = $this->analisar($ativo);
            if (! $analise['pode'] || $ativo->status !== AcervoImagem::DISPONIVEL) {
                return ['ok' => false, 'analise' => $analise];
            }

            $ativo->update(['status' => AcervoImagem::EXCLUINDO, 'exclusao_pedida_em' => now(), 'exclusao_erro' => null, 'exclusao_tentativas' => 0]);

            return ['ok' => true, 'analise' => $analise];
        });

        // Despacho FORA da trava: o job pega a mesma trava (e, em fila síncrona, rodaria aqui dentro).
        if ($resultado['ok']) {
            ExcluirAtivoDoAcervoJob::dispatch($ativo->id);
        }

        return $resultado;
    }

    /**
     * O trabalho do job, idempotente: só age em ativo `excluindo`; se um anúncio não publicado passou
     * a usar a imagem enquanto ela esperava, desiste e devolve ao acervo.
     */
    public function executar(int $ativoId): void
    {
        Cache::lock('acervo-ativo-'.$ativoId, 120)->block(10, function () use ($ativoId) {
            $ativo = AcervoImagem::find($ativoId);
            if ($ativo === null || $ativo->status !== AcervoImagem::EXCLUINDO) {
                return;
            }

            if (! $this->analisar($ativo)['pode']) {
                $ativo->update(['status' => AcervoImagem::DISPONIVEL, 'exclusao_erro' => 'Voltou ao acervo: um anúncio não publicado passou a usar esta imagem.']);
                Log::info("[Acervo] Ativo {$ativo->id}: exclusão cancelada, imagem entrou em anúncio não publicado");

                return;
            }

            $ativo->increment('exclusao_tentativas');
            ArquivosDeImagem::apagar($ativo->caminho);
            if ($ativo->miniatura_caminho !== null) {
                ArquivosDeImagem::apagar($ativo->miniatura_caminho);
            }

            $ativo->update(['status' => AcervoImagem::EXCLUIDA, 'miniatura_caminho' => null, 'exclusao_erro' => null]);
            Log::info("[Acervo] Ativo {$ativo->id} ({$ativo->dono_chave}) excluído do storage");
        });
    }

    public static function trava(AcervoImagem $ativo): string
    {
        return 'acervo-ativo-'.$ativo->id;
    }
}
