<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Support\Publicador\ConferenciaDeFrete;
use Illuminate\Support\Facades\Cache;

/**
 * Para onde vai o envio de um rascunho na conta dele (21/10/2026): Mercado Envios, a transportadora do vendedor
 * (ME1) ou "Combine a entrega".
 *
 * Por que existe: o "Calcular" do Publicador descontava o frete que o `shipping_options/free` devolve, e esse
 * endpoint responde até para o pacote que NÃO cabe no Mercado Envios da conta — um número teórico, que o Mercado
 * Livre não cobra. A Poltrona Opala da #459 (Correios, soma dos lados 245 cm, máx. 200) saiu "Combine a entrega",
 * e a tela mostrava R$ 147,85 de frete do Mercado Livre. Medido em 4 contas com ME1 (481 anúncios ativos): nenhum
 * anúncio em Mercado Envios passa do limite da modalidade da conta; os que passam estão em ME1 ou "a combinar".
 *
 * A régua é a do Portal ({@see LogisticaProduto}, limites por modalidade no config); o pacote é o do RASCUNHO
 * (`SELLER_PACKAGE_*`), que é o que vai ao anúncio. A conta (modalidade e se tem ME1) vem do cache do Portal
 * ({@see ModalidadeDeEnvio}); sem ele, uma leitura de `shipping_preferences` — só GET — que fica guardada.
 * Sem saber a modalidade da conta, não se julga nada (`destino` nulo): quem chama segue como antes.
 */
class EnvioDoRascunhoService
{
    public const ME2 = 'me2';

    public function __construct(private ClienteMlPublicador $cliente) {}

    /**
     * @param  array<string, array>  $atributos  os atributos efetivos do rascunho (os do pacote entre eles)
     * @param  ?string  $sellerId  o vendedor, quando quem chama já o tem (a conferência); senão o do rascunho ou o do token
     * @return array{modalidade: ?string, me1: ?bool, destino: ?string, estouros: list<array>}
     *               destino: `me2`, `me1`, `combinar`, `fora` (fora do ME2 sem saber se a conta tem ME1) ou null
     */
    public function avaliar(PubRascunho $r, array $atributos, ?string $sellerId = null): array
    {
        ['modalidade' => $modalidade, 'me1' => $me1] = $this->daConta($r, $sellerId);
        $saida = ['modalidade' => $modalidade, 'me1' => $me1, 'destino' => null, 'estouros' => []];

        $pacote = ConferenciaDeFrete::pacote($atributos);
        if ($pacote === null || $modalidade === null) {
            return $saida;
        }

        $av = LogisticaProduto::avaliar($pacote, null, $modalidade);
        if ($av['logistica'] !== LogisticaProduto::ME1) {
            return ['destino' => self::ME2] + $saida;
        }

        return ['destino' => ModalidadeDeEnvio::destinoFora($me1), 'estouros' => LogisticaProduto::estouros($av, $modalidade)] + $saida;
    }

    /** Fora do Mercado Envios, com a conta lida: o Mercado Livre não cobra frete nenhum. */
    public static function fora(?string $destino): bool
    {
        return $destino !== null && $destino !== self::ME2;
    }

    /**
     * A modalidade e o ME1 da conta: o cache do Portal ou, sem ele, uma leitura (guardada quando a conta é uma
     * empresa da carteira; conta de Polos lê a cada ação explícita — é rara no Publicador).
     *
     * @return array{modalidade: ?string, me1: ?bool}
     */
    private function daConta(PubRascunho $r, ?string $sellerId): array
    {
        $empresa = $r->produto?->company;
        if ($empresa instanceof Company) {
            $guardado = Cache::get(ModalidadeDeEnvio::chave((int) $empresa->id));
            if (is_array($guardado) && array_key_exists('me1', $guardado)) {
                return ['modalidade' => ModalidadeDeEnvio::doGuardado($guardado), 'me1' => ModalidadeDeEnvio::me1DoGuardado($guardado)];
            }
        }

        if ($r->produto === null) {
            return ['modalidade' => null, 'me1' => null];
        }
        $seller = (string) ($sellerId ?? $r->step_state['conta']['sellerId'] ?? $r->conta()->mlToken?->ml_user_id ?? '');
        if ($seller === '') {
            return ['modalidade' => null, 'me1' => null];
        }

        $resp = $this->cliente->daConta($r->conta(), 'GET', "/users/{$seller}/shipping_preferences");
        if (! $resp->ok() || ! is_array($resp->corpo)) {
            return ['modalidade' => null, 'me1' => null];
        }

        $modalidade = ModalidadeDeEnvio::daPreferencia($resp->corpo);
        $me1 = ModalidadeDeEnvio::temMe1($resp->corpo);
        if ($empresa instanceof Company) {
            ModalidadeDeEnvio::guardar((int) $empresa->id, $modalidade, null, $me1);
        }

        return ['modalidade' => $modalidade, 'me1' => $me1];
    }
}
