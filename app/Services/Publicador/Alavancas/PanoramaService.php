<?php

namespace App\Services\Publicador\Alavancas;

use Illuminate\Support\Facades\Log;

/**
 * D-02 — panorama da conta: cada fonte falha sozinha (AL166-03); nunca 500 para a página.
 *
 * Uma fonte que não responde vira `['erro' => 'Não deu para ler agora.']` e as outras seguem.
 * O panorama em si não guarda cache: cada leitor guarda o seu SUCESSO (erro nunca fica guardado).
 */
class PanoramaService
{
    public const ERRO = 'Não deu para ler agora.';

    /** Convite que já passou do fim não é mais convite. */
    private const STATUS_ENCERRADOS = ['finished', 'closed', 'cancelled', 'deleted'];

    public function __construct(
        private LeitorContaAlavancas $conta,
        private PromocoesLeitura $promocoes,
        private CuponsLeitura $cupons,
        private PublicidadeLeitura $publicidade,
    ) {}

    /**
     * @return array{conta: array, convites: array, cupons: array, publicidade: array, atacado: array}
     */
    public function montar(ContaAlavanca $c, bool $atualizar = false): array
    {
        // Cada fonte no seu try/catch: o que falha vira a mensagem curta e as outras seguem.
        try {
            $l = $this->conta->ler($c, $atualizar);
            $conta = ['nickname' => $l['nickname'], 'reputacao' => $l['reputacao'], 'business' => $l['business'],
                'experiencia' => $l['experiencia'], 'site' => $l['site']];
            // O atacado depende da tag `business` da conta: se a conta falhar, ele também.
            $atacado = ['business' => (bool) $l['business']];
        } catch (\Throwable $e) {
            $conta = $atacado = $this->falhou($c, 'conta', $e);
        }

        try {
            $convites = $this->convitesAbertos($c, $atualizar);
        } catch (\Throwable $e) {
            $convites = $this->falhou($c, 'convites', $e);
        }

        try {
            $cupons = $this->cuponsAtivos($c, $atualizar);
        } catch (\Throwable $e) {
            $cupons = $this->falhou($c, 'cupons', $e);
        }

        try {
            $publicidade = $this->publicidade->resumo($c, $atualizar);
        } catch (\Throwable $e) {
            $publicidade = $this->falhou($c, 'publicidade', $e);
        }

        return compact('conta', 'convites', 'cupons', 'publicidade', 'atacado');
    }

    /** Falha de uma fonte: aviso no log e a mensagem curta para a tela. */
    private function falhou(ContaAlavanca $c, string $fonte, \Throwable $e): array
    {
        Log::warning("[Alavancas] panorama {$fonte} conta {$c->chaveConta()}: ".$e->getMessage());

        return ['erro' => self::ERRO];
    }

    /** @return array{itens: list<array>, truncado: bool} */
    private function convitesAbertos(ContaAlavanca $c, bool $atualizar): array
    {
        $lista = $this->promocoes->convites($c, $atualizar);
        $itens = [];
        foreach ($lista['itens'] as $convite) {
            if (! in_array($convite['tipo'], TiposDePromocao::CONVITES_DO_ML, true)) {
                continue;
            }
            $dias = $convite['dias_para_vencer'] ?? null;
            if ($dias !== null ? $dias < 0 : in_array($convite['status'], self::STATUS_ENCERRADOS, true)) {
                continue;
            }
            $itens[] = [...$convite, 'alertas' => AlertasAlavancas::doConvite($convite)];
        }

        return ['itens' => $itens, 'truncado' => (bool) ($lista['truncado'] ?? false)];
    }

    /** @return array{itens: list<array>, truncado: bool} */
    private function cuponsAtivos(ContaAlavanca $c, bool $atualizar): array
    {
        $lista = $this->cupons->cupons($c, $atualizar);

        return [
            'itens' => array_values(array_filter($lista['itens'], fn (array $i) => ($i['status'] ?? null) === 'started')),
            'truncado' => $lista['truncado'],
        ];
    }
}
