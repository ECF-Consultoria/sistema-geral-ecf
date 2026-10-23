<?php

namespace App\Support\Publicador;

use App\Models\PubJardinagem;

/**
 * A árvore de Jardinagem do MAG (Turma 8, aula de 27/11/2025) como regra pura: visitas e vendas de um
 * mês → um dos quatro ramos. Sem banco e sem Mercado Livre, para as fronteiras serem testadas sozinhas.
 *
 * - vendas = 0 e visitas < corte (100)  → `sem_tracao`        (excluir e criar nova oferta)
 * - vendas = 0 e visitas ≥ corte        → `visita_sem_venda`  (criativo + elasticidade de preço)
 * - vendas > 0 e conversão < corte (2%) → `conversao_baixa`   (criativo + elasticidade de preço)
 * - vendas > 0 e conversão ≥ corte      → `conversao_boa`     (curva A → Full → escalar)
 *
 * Fronteiras: a árvore diz "< 100" e "> 100"; exatamente 100 visitas cai no ramo de cima (o anúncio JÁ
 * é encontrado). A conversão é decidida com DUAS casas (a mesma que a tela mostra): 1,995% vira 2,00% e
 * conta como boa — a tela nunca mostra "2,00%" com o selo de baixa. Vendeu sem nenhuma visita contada (a
 * visita do ML chega atrasada, ou a venda veio de fora da busca): conversão nula, e o ramo é o bom —
 * o anúncio vendeu.
 *
 * Os cortes vêm de `publicador.jardinagem` ({@see daConfig()}); o MAG dá os 2% como exemplo ("2,00% Ex").
 */
final class ReguaDaJardinagem
{
    public function __construct(
        public readonly int $corteVisitas = 100,
        public readonly float $corteConversao = 2.0,
    ) {}

    public static function daConfig(): self
    {
        return new self(
            max(1, (int) config('publicador.jardinagem.visitas_corte', 100)),
            max(0.01, (float) config('publicador.jardinagem.conversao_corte', 2.0)),
        );
    }

    /** Vendas ÷ visitas × 100, com duas casas; nula sem visita. */
    public function conversao(int $visitas, int $vendas): ?float
    {
        return $visitas > 0 ? round($vendas / $visitas * 100, 2) : null;
    }

    public function diagnosticar(int $visitas, int $vendas): string
    {
        $visitas = max(0, $visitas);
        $vendas = max(0, $vendas);

        if ($vendas === 0) {
            return $visitas < $this->corteVisitas ? PubJardinagem::SEM_TRACAO : PubJardinagem::VISITA_SEM_VENDA;
        }

        $conversao = $this->conversao($visitas, $vendas);

        return $conversao !== null && $conversao < $this->corteConversao
            ? PubJardinagem::CONVERSAO_BAIXA
            : PubJardinagem::CONVERSAO_BOA;
    }

    /**
     * Os textos de cada ramo para a tela, já com os cortes em vigor.
     *
     * @return array<string, array{rotulo: string, regra: string, acao: string}>
     */
    public function descricoes(): array
    {
        $v = number_format($this->corteVisitas, 0, ',', '.');
        $c = self::porcentagem($this->corteConversao);

        return [
            PubJardinagem::SEM_TRACAO => [
                'rotulo' => 'Sem tração',
                'regra' => "Menos de {$v} visitas e nenhuma venda",
                'acao' => 'Excluir e criar nova oferta',
            ],
            PubJardinagem::VISITA_SEM_VENDA => [
                'rotulo' => 'Visita sem venda',
                'regra' => "{$v} visitas ou mais e nenhuma venda",
                'acao' => 'Reavaliar o criativo e testar o preço',
            ],
            PubJardinagem::CONVERSAO_BAIXA => [
                'rotulo' => 'Conversão baixa',
                'regra' => "Vende, com conversão abaixo de {$c}",
                'acao' => 'Reavaliar o criativo e testar o preço',
            ],
            PubJardinagem::CONVERSAO_BOA => [
                'rotulo' => 'Conversão boa',
                'regra' => "Vende, com conversão de {$c} ou mais",
                'acao' => 'Curva A → Full → escalar',
            ],
        ];
    }

    /** 2.0 → "2%", 2.5 → "2,5%", 1.75 → "1,75%". */
    public static function porcentagem(float $valor): string
    {
        $texto = rtrim(rtrim(number_format($valor, 2, ',', '.'), '0'), ',');

        return $texto.'%';
    }
}
