<?php

namespace App\Mcp\Shopee;

use App\Mcp\ErroDaFerramenta;
use App\Models\Company;
use App\Models\ShopeeToken;
use App\Services\Shopee\ShopeeService;
use RuntimeException;
use Throwable;

/**
 * Chamada à API da Shopee em nome do MCP, por cima do {@see ShopeeService}:
 * mesma assinatura, mesmo token e mesma renovação do sync diário.
 *
 * O que fica aqui, e não no serviço:
 *
 *  - **Qual app chamar.** A ECF tem dois apps na Shopee — ERP (pedido,
 *    produto, pagamento…) e ADS (anúncios) — e é o TIPO do app que decide o
 *    que a Shopee libera (seção "API PERMISSION" de cada endpoint; não existe
 *    liberação manual). Módulo `ads` vai no app ADS e o resto no ERP. Se a
 *    Shopee recusar por permissão, tenta UMA vez o outro app da mesma loja:
 *    módulos novos (vídeo, afiliados, live) não têm dono certo.
 *  - **Erro legível.** O serviço lança RuntimeException com o corpo cru da
 *    Shopee; aqui vira frase. O `message` da Shopee vai junto quando o erro é
 *    de parâmetro — é ele que diz ao Claude qual campo corrigir.
 */
class ChamadaShopee
{
    public const ROTULO = ['erp' => 'ERP', 'ads' => 'ADS'];

    /**
     * Chama um endpoint de loja. O app é escolhido pelo módulo do caminho.
     *
     * @param  array<string, mixed>  $parametros
     * @return array{app:string, resposta:array<mixed>}
     *
     * @throws ErroDaFerramenta
     */
    public function chamar(Company $loja, string $caminho, array $parametros = [], string $metodo = 'GET'): array
    {
        $natural = str_starts_with($caminho, '/api/v2/ads/') ? 'ads' : 'erp';
        $outro   = $natural === 'ads' ? 'erp' : 'ads';
        $tokens  = $this->appsConectados($loja);

        if ($tokens === []) {
            throw new ErroDaFerramenta("A loja {$loja->name} não tem a Shopee conectada. A conexão é feita em /shopee-oauth.");
        }

        $negado = null;
        foreach ([$natural, $outro] as $app) {
            if (! in_array($app, $tokens, true)) {
                continue;
            }

            try {
                return ['app' => $app, 'resposta' => $this->executar($loja, $app, $caminho, $parametros, $metodo)];
            } catch (PermissaoNegada $e) {
                $negado = $e->getMessage();
            }
        }

        if (! in_array($natural, $tokens, true)) {
            throw new ErroDaFerramenta($this->semApp($loja, $natural));
        }

        throw new ErroDaFerramenta(
            "A Shopee não libera {$caminho} para os apps da ECF (resposta: {$negado}). Esse dado não está disponível para nós pela API."
        );
    }

    /**
     * Igual a {@see chamar()}, mas devolve o erro em vez de lançar — para as
     * respostas compostas, em que uma parte recusada não derruba as outras.
     *
     * @param  array<string, mixed>  $parametros
     * @return array{ok:bool, app?:string, resposta?:array<mixed>, erro?:string}
     */
    public function tentar(Company $loja, string $caminho, array $parametros = [], string $metodo = 'GET'): array
    {
        try {
            return ['ok' => true] + $this->chamar($loja, $caminho, $parametros, $metodo);
        } catch (ErroDaFerramenta $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        }
    }

    /**
     * Roda um método do próprio ShopeeService (ex.: `fetchAdsMetrics`) com o
     * mesmo tratamento de erro — para reaproveitar a conta que o sync já faz.
     *
     * @template T
     *
     * @param  callable(ShopeeService): T  $acao
     * @return T
     *
     * @throws ErroDaFerramenta
     */
    public function comServico(Company $loja, string $app, callable $acao): mixed
    {
        if (! in_array($app, $this->appsConectados($loja), true)) {
            throw new ErroDaFerramenta($this->semApp($loja, $app));
        }

        try {
            return $this->traduzindo($loja, $app, '', fn () => $acao(new ShopeeService($app)));
        } catch (PermissaoNegada $e) {
            throw new ErroDaFerramenta('A Shopee não libera este dado para o app '.self::ROTULO[$app].' da ECF (resposta: '.$e->getMessage().').');
        }
    }

    /**
     * Várias consultas ao MESMO endpoint, até 8 por vez — cada chamada à
     * Shopee leva ~2 s. Só app ERP (pedido, produto): sem a segunda tentativa
     * no outro app.
     *
     * @param  array<int|string, array<string, mixed>>  $consultas chave => parâmetros
     * @return array<int|string, array<mixed>> conteúdo útil de cada uma, com a chave dela
     *
     * @throws ErroDaFerramenta
     */
    public function chamarEmParalelo(Company $loja, string $caminho, array $consultas): array
    {
        if ($consultas === []) {
            return [];
        }

        if (! in_array('erp', $this->appsConectados($loja), true)) {
            throw new ErroDaFerramenta($this->semApp($loja, 'erp'));
        }

        try {
            $jsons = $this->traduzindo($loja, 'erp', $caminho, fn () => (new ShopeeService('erp'))->getEmParalelo($loja, $caminho, $consultas));
        } catch (PermissaoNegada $e) {
            throw new ErroDaFerramenta("A Shopee não libera {$caminho} para o app ERP da ECF (resposta: {$e->getMessage()}).");
        }

        return array_map(fn (array $json) => $this->conteudo($json), $jsons);
    }

    /**
     * Apps com token utilizável (o revogado não volta sem o vendedor autorizar
     * de novo; o vencido o serviço renova sozinho).
     *
     * @return array<int, string>
     */
    public function appsConectados(Company $loja): array
    {
        return ShopeeToken::where('company_id', $loja->id)
            ->where('status', '!=', 'revoked')
            ->pluck('app')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $parametros
     * @return array<mixed>
     */
    private function executar(Company $loja, string $app, string $caminho, array $parametros, string $metodo): array
    {
        $servico = new ShopeeService($app);

        return $this->conteudo($this->traduzindo($loja, $app, $caminho, fn () => $metodo === 'POST'
            ? $servico->postCompleto($loja, $caminho, $parametros)
            : $servico->getCompleto($loja, $caminho, $parametros)));
    }

    /**
     * O conteúdo útil da resposta: a chave `response` (padrão da v2) ou, para
     * os endpoints que respondem na raiz (`get_shop_info`), o JSON sem o
     * envelope (`error`, `message`, `request_id`).
     *
     * @param  array<string, mixed>  $json
     * @return array<mixed>
     */
    private function conteudo(array $json): array
    {
        if (array_key_exists('response', $json)) {
            return (array) $json['response'];
        }

        return array_diff_key($json, array_flip(['error', 'message', 'request_id', 'warning']));
    }

    private function semApp(Company $loja, string $app): string
    {
        return 'Este dado vem do app '.self::ROTULO[$app].' da Shopee, e a loja '.$loja->name
            .' não tem esse app conectado. A conexão é feita em /shopee-oauth.';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $chamada
     * @return T
     *
     * @throws PermissaoNegada quando o tipo do app não pode chamar o endpoint
     * @throws ErroDaFerramenta
     */
    private function traduzindo(Company $loja, string $app, string $caminho, callable $chamada): mixed
    {
        try {
            return $chamada();
        } catch (ErroDaFerramenta|PermissaoNegada $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // `ShopeeService` lança "sem token válido" quando a renovação falhou.
            if (str_contains($e->getMessage(), 'sem token válido')) {
                throw new ErroDaFerramenta($this->acessoVencido($loja, $app));
            }

            [$codigo, $mensagem] = $this->erroDaShopee($e->getMessage());

            if ($codigo === 'error_permission_denied' || str_contains(strtolower($mensagem), 'no permission')) {
                throw new PermissaoNegada($mensagem !== '' ? $mensagem : $codigo);
            }

            if (in_array($codigo, ['error_auth', 'invalid_access_token', 'invalid_acceess_token', 'error_invalid_token'], true)) {
                throw new ErroDaFerramenta($this->acessoVencido($loja, $app));
            }

            if ($codigo === '') {
                report($e);

                throw new ErroDaFerramenta('A API da Shopee não respondeu como esperado agora. Tente de novo em instantes.');
            }

            $onde = $caminho !== '' ? " em {$caminho}" : '';

            throw new ErroDaFerramenta(trim("A Shopee recusou a consulta{$onde}: {$codigo} — {$mensagem}", ' —'));
        } catch (Throwable $e) {
            report($e);

            throw new ErroDaFerramenta('A API da Shopee não respondeu agora. Tente de novo em instantes.');
        }
    }

    private function acessoVencido(Company $loja, string $app): string
    {
        return 'O acesso da loja '.$loja->name.' ao app '.self::ROTULO[$app]
            .' da Shopee venceu ou foi revogado pelo vendedor. É preciso reconectar em /shopee-oauth.';
    }

    /**
     * Código e mensagem do corpo que o ShopeeService põe na exceção
     * ("[Shopee] Erro em /api/v2/… empresa 1: {"error":"…","message":"…"}").
     *
     * @return array{0:string, 1:string}
     */
    private function erroDaShopee(string $mensagemDaExcecao): array
    {
        $inicio = strpos($mensagemDaExcecao, '{');
        $corpo  = $inicio === false ? null : json_decode(substr($mensagemDaExcecao, $inicio), true);

        if (! is_array($corpo)) {
            return ['', ''];
        }

        return [(string) ($corpo['error'] ?? ''), (string) ($corpo['message'] ?? $corpo['msg'] ?? '')];
    }
}
