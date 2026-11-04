<?php

namespace App\Services\Trilha;

use App\Jobs\Trilha\TentarPreparoDeNovoJob;
use App\Models\Company;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * O [Tentar de novo] da "Preparação automática" da trilha (retorno do 1º teste, R4, 04/11/2026): quando o preparo pela
 * IA parou num item (a linha diz "O agente parou: título, descrição"), a equipe pede que ele refaça — SÓ as etapas que
 * pararam — sem sair da trilha.
 *
 * ### Quem refaz é o PRÓPRIO preparo
 * O Job ({@see TentarPreparoDeNovoJob}, na fila da IA) chama `PreparoIaDoRascunhoService::avaliarIa($pub)` como está:
 * com os mesmos fatos (o mesmo hash), `etapasAFazer` devolve só as etapas em `erro`/`desistiu` (e a `rodando`/`adiada`
 * que venceu — Job que morreu), mais o Modelo junto de um título refeito; as que deram certo não gastam IA de novo. A
 * cota diária da loja, a trava da onda, o "incompleto" e o resto das regras do preparo continuam valendo — nada aqui
 * chama a IA nem escreve no rascunho. Se os fatos mudaram depois do erro, o preparo refaz as três (é a regra dele; o
 * save do cadastro faria o mesmo).
 *
 * ### Quando dá para pedir (a régua é a da linha)
 * O item tem de ter `parada.tentar` ({@see SituacaoDasOfertas}: o texto do agente parou em alguma etapa, nada do texto
 * está andando agora e não há pedido na fila). Fora disso, 409 sem mudar nada:
 * - já pedido e ainda na fila ({@see MSG_JA_PEDIDO});
 * - nada parado no texto do agente — inclusive só as imagens paradas, que o preparo não refaz ({@see MSG_NADA}).
 * Dois cliques ao mesmo tempo: a trava curta e a marca relida dentro dela — um Job só.
 *
 * ### A marca "pedido"
 * `Cache` `trilha:preparo:de-novo:{pub}` por até {@see VALE_MIN} minutos: enquanto o Job não roda, a linha diz "Na fila
 * do agente" (sem alerta) em vez de "O agente parou" ({@see pedidosEmLote()}, lido em lote pela situação das ofertas).
 * O Job apaga a marca quando termina (dê no que der) e no `failed()`; depois, a linha segue a régua de sempre — o
 * preparo marca as etapas `rodando` ("O agente está escrevendo…") e, de novo em erro, a causa nova aparece.
 */
class PreparoNaTrilha
{
    /** A marca "pedido, esperando a fila da IA" — depois disto, o pedido conta como perdido e a linha volta a "parou". */
    public const VALE_MIN = 30;

    public const EVENTO = 'trilha_preparo_de_novo';

    public const MSG_PEDIDO = 'Pedido feito: o agente vai tentar de novo em instantes.';

    public const MSG_JA_PEDIDO = 'Já pedido: o agente vai tentar de novo em instantes.';

    public const MSG_NADA = 'Nada para tentar de novo: a preparação deste item não parou no texto.';

    /** O rótulo de cada etapa do texto (o histórico). */
    private const ROTULO = ['titulo' => 'título', 'modelo' => 'modelo', 'descricao' => 'descrição', 'imagens' => 'imagens'];

    public function __construct(private PreparoIaDoRascunhoService $preparo) {}

    /**
     * `POST /trilha/{company}/ofertas/{item}/preparo`. Ordem: 404 item → 409 já pedido → 409 nada parado no texto → grava
     * a marca, o histórico e enfileira o Job na fila da IA.
     *
     * @return array{preparo: array{situacao: string, mensagem: string, etapas: list<string>}}
     *
     * @throws HttpResponseException 409
     */
    public function pedir(Company $empresa, string $chave, User $quem): array
    {
        $item = TrilhaDaEmpresa::itens($empresa)[$chave] ?? null;
        abort_if($item === null, 404);
        $pubId = $item['pub_produto_id'] !== null ? (int) $item['pub_produto_id'] : null;
        if ($pubId !== null && self::pedido($pubId) !== null) {
            self::conflito(self::MSG_JA_PEDIDO);
        }
        $parada = $item['parada'] ?? null;
        if ($pubId === null || ! is_array($parada) || ! ($parada['tentar'] ?? false)) {
            self::conflito(self::MSG_NADA);
        }

        $trava = Cache::lock(self::chave($pubId).':pedido', 10);
        if (! $trava->get()) {
            self::conflito(self::MSG_JA_PEDIDO);
        }
        try {
            if (self::pedido($pubId) !== null) {
                self::conflito(self::MSG_JA_PEDIDO);
            }
            $etapas = array_values(array_filter((array) $parada['etapas'], fn ($e) => $e !== 'imagens'));
            Cache::put(self::chave($pubId), [
                'pedido_em' => now()->toIso8601String(),
                'por'       => (int) $quem->id,
                'item'      => $chave,
                'etapas'    => $etapas,
                'motivo'    => (string) ($parada['motivo'] ?? ''),
            ], now()->addMinutes(self::VALE_MIN));

            $r = PubRascunho::query()->where('produto_id', $pubId)->first();
            if ($r !== null) {
                RegistroEstrutura::registrar(AtorDoPortal::daEquipe($quem), $empresa, $r, self::EVENTO,
                    "Preparação pedida de novo em \"{$item['nome']}\": ".implode(', ', array_map(fn ($e) => self::ROTULO[$e] ?? $e, $etapas)), [
                        'item'           => $chave,
                        'pub_produto_id' => $pubId,
                        'rascunho_id'    => (int) $r->id,
                        'etapas'         => $etapas,
                        'motivo'         => (string) ($parada['motivo'] ?? ''),
                    ]);
            }
            TentarPreparoDeNovoJob::dispatch((int) $empresa->id, $chave, $pubId);
        } finally {
            $trava->release();
        }

        return ['preparo' => ['situacao' => 'fila', 'mensagem' => self::MSG_PEDIDO, 'etapas' => $etapas]];
    }

    /**
     * O que o {@see TentarPreparoDeNovoJob} faz: o `avaliarIa` do preparo para o anúncio do item, e a marca sai. Devolve o
     * que o preparo disse (log e testes): `gerando (titulo, modelo)`, `em_dia`, `limite`, `incompleto`…, ou `sumiu`.
     */
    public function executar(int $empresaId, string $chave, int $pubId): string
    {
        try {
            $pub = PubProduto::query()->find($pubId);
            if ($pub === null) {
                return 'sumiu';
            }
            $resultado = $this->preparo->avaliarIa($pub);
            Log::info("[Trilha] Tentar de novo: item {$chave} (anúncio {$pubId}) da empresa {$empresaId}: {$resultado}.");

            return $resultado;
        } finally {
            self::esquecer($pubId);
        }
    }

    /** O pedido na fila deste anúncio (nulo: nenhum). */
    public static function pedido(int $pubId): ?array
    {
        $p = Cache::get(self::chave($pubId));

        return is_array($p) ? $p : null;
    }

    /** O Job terminou (ou quebrou): a linha volta à régua de sempre. */
    public static function esquecer(int $pubId): void
    {
        Cache::forget(self::chave($pubId));
    }

    /**
     * Os anúncios com o pedido na fila, de uma vez (um `Cache::many`) — o que a situação das ofertas lê para dizer "Na
     * fila do agente" no lugar de "O agente parou".
     *
     * @param  list<int>  $pubIds
     * @return array<int, true>
     */
    public static function pedidosEmLote(array $pubIds): array
    {
        if ($pubIds === []) {
            return [];
        }
        $chaves = [];
        foreach ($pubIds as $id) {
            $chaves[self::chave((int) $id)] = (int) $id;
        }
        $saida = [];
        foreach (Cache::many(array_keys($chaves)) as $chave => $valor) {
            if (isset($chaves[$chave]) && is_array($valor)) {
                $saida[$chaves[$chave]] = true;
            }
        }

        return $saida;
    }

    public static function chave(int $pubId): string
    {
        return "trilha:preparo:de-novo:{$pubId}";
    }

    private static function conflito(string $mensagem): never
    {
        throw new HttpResponseException(response()->json(['message' => $mensagem], 409));
    }
}
