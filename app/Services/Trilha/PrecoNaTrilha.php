<?php

namespace App\Services\Trilha;

use App\Models\Company;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Portal\Estrutura\RegistroEstrutura;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\NaFilaDePublicacao;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * O preço ajustável da revisão (4ª entrega, fatia 4c, desenho §19.11, D45/D46/D47): a equipe troca o preço do Clássico
 * e/ou do Premium do anúncio que o robô preparou — em todas as cores ou numa só — sem abrir o Publicador. Grava SÓ no
 * rascunho (`pub_variante_precos`), pelo caminho do editor ({@see EditorRascunhoService::salvarVariantes()}, D45); a
 * Precificação nunca é escrita (o preço dela é lido na hora, e `null` = voltar a herdar dele).
 *
 * ### A guarda, nesta ordem (§19.4)
 * O controller já conferiu as duas chaves (404) e a escrita no Portal da empresa (403). Aqui:
 * 1. o item do ENDEREÇO pela régua única ({@see TrilhaDaEmpresa::itens()}, como na tela) — o de outra loja ou o que não
 *    existe é 404, ANTES de olhar o corpo;
 * 2. 409 SEM `travado` (D47, padrão F6): na fila de publicação ({@see NaFilaDePublicacao}), publicando agora,
 *    publicado — depois, fora da revisão; e só então a `revisao` do corpo contra a do rascunho (aba velha:
 *    `{revisao_atual, message}`). O estado vem antes da revisão porque diz mais: recarregar não destrava a fila;
 * 3. 422 (`errors["precos.classico"]`, `errors["precos.premium"]`, `errors.cor`): valor inválido, tipo desligado, cor
 *    que não está no anúncio e o Premium IGUAL ao Clássico (D46, {@see exigirPremiumDiferente()}).
 *
 * ### O que grava
 * - Por cor, o mapa INTEIRO de preços daquela variante (o `salvarVariantes` troca o `precos` todo): o que estava
 *   GRAVADO (nunca o efetivo — gravar o herdado congelaria o preço da Precificação no rascunho, o defeito do Anunciar
 *   antigo) com o tipo pedido trocado. Só as cores que mudam; pedido que não muda nada não grava (a `revisao` não sobe e
 *   a conferência não perde a validade à toa).
 * - Sobe a `revisao` do rascunho (o `tocar` do editor): a conferência anterior deixa de valer (R4 do desenho).
 * - `activity_log` (canal `portal`, evento `trilha_preco_ajustado`, ator da equipe — o `salvarVariantes` não aceita
 *   ator), um registro por TIPO que mudou: a cor pedida (nula = todas), o efetivo de cada cor antes e o que ficou.
 *
 * Abaixo do mínimo da Precificação é AVISO na resposta (`avisos`), não bloqueia: a equipe decide.
 *
 * Zero IA, zero ML, nenhum Job por aqui. Custo: a régua da linha, a Precificação uma vez (o efetivo antes) e a prévia
 * nova ({@see PreviaNaTrilha::montar()}, que relê a régua e a Precificação).
 *
 * ### Corrida conhecida
 * A conferência da `revisao` e do estado é feita ANTES do `salvarVariantes` (que trava o rascunho só dentro da
 * transação dele). Não há transação de fora de propósito: o `salvarVariantes` propaga o estoque aos kits DEPOIS da
 * transação dele, e aninhá-lo numa trava de fora juntaria duas travas de rascunho (T-175-40). Na janela de milissegundos,
 * vale o que o editor do Publicador já faz: a última escrita vence; e se a fila pegar o item no meio, a `revisao` nova o
 * tira como `precisa_revisar` (R5).
 */
class PrecoNaTrilha
{
    public const MSG_REVISAO = 'Este anúncio mudou depois que você abriu. Recarregue para ver o que mudou.';

    public const MSG_NA_FILA = 'Este anúncio já está na fila de publicação: o preço não muda mais por aqui.';

    public const MSG_PUBLICANDO = 'Este anúncio está sendo publicado agora: o preço não muda mais por aqui.';

    public const MSG_PUBLICADO = 'Este anúncio já foi publicado: o preço não muda mais por aqui.';

    public const MSG_FORA_DA_REVISAO = 'Este anúncio não está na revisão: o preço só muda aqui quando ele estiver pronto para revisar.';

    public const MSG_PREMIUM_IGUAL = 'O Premium não pode ter o mesmo preço do Clássico.';

    public const MSG_TIPO_DESLIGADO = 'Este tipo de anúncio está desligado.';

    public const MSG_SEM_TIPO = 'Diga o preço do Clássico ou do Premium.';

    public const MSG_COR = 'Esta cor não está no anúncio.';

    public const MSG_VALOR = 'Informe um preço maior que zero, com até 2 casas decimais.';

    public const MSG_RECARREGUE = 'Recarregue a página e tente de novo.';

    /** O teto do editor do Publicador (`variantes.*.precos.*` em `MlbPublicadorController`). */
    private const MAXIMO = 9999999;

    /** A chave do tipo no contrato → `listing_type_id`. */
    private const LT = ['classico' => 'gold_special', 'premium' => 'gold_pro'];

    public function __construct(
        private PreviaNaTrilha $previa,
        private RascunhoRepository $repo,
        private DadosEfetivosService $efetivos,
        private EditorRascunhoService $editor,
    ) {}

    /**
     * O `PUT …/ofertas/{item}/preco`. Corpo: `{revisao, precos: {classico?: ?número, premium?: ?número}, cor: ?string}` —
     * tipo ausente = não mexe; `null` = voltar ao da Precificação; `cor` nula = todas as cores ativas, ou a chave da
     * variante (`variantes[].chave` da prévia). Devolve a prévia nova (§19.5, com o `preco` ampliado) e `avisos`.
     *
     * @param  array<string, mixed>  $corpo
     * @return array<string, mixed>
     *
     * @throws HttpResponseException 409 (estado ou revisão)
     * @throws ValidationException 422
     */
    public function ajustar(Company $empresa, string $chave, array $corpo, User $quem): array
    {
        $item = TrilhaDaEmpresa::itens($empresa, comoNaTela: true)[$chave] ?? null;
        abort_if($item === null, 404);

        $r = PreviaNaTrilha::rascunho($item);
        self::exigirQueDaParaMexer($item, $r);
        /** @var PubRascunho $r */
        self::exigirRevisao($r, $corpo);

        $pedido = self::validar($corpo);
        $cru = $this->repo->snapshot($r);
        $efetivo = $cru->comEfetivosDe($this->efetivos->daProduto($r->produto));
        $ligados = array_map(fn ($a) => $a->listingTypeId, $efetivo->alvosAtivos());
        $ativas = [];
        foreach ($efetivo->variantesAtivas() as $v) {
            /** @var Variante $v */
            $ativas[$v->chave] = $v;
        }
        $erros = [];
        foreach (array_keys($pedido['precos']) as $tipo) {
            if (! in_array(self::LT[$tipo], $ligados, true)) {
                $erros["precos.{$tipo}"] = self::MSG_TIPO_DESLIGADO;
            }
        }
        $cor = $pedido['cor'];
        if ($cor !== null && ! isset($ativas[$cor])) {
            $erros['cor'] = self::MSG_COR;
        }
        if ($ativas === []) {
            $erros['cor'] = 'Nenhuma cor ativa no anúncio.';
        }
        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        $alvos = $cor !== null ? [$cor] : array_keys($ativas);
        $crus = [];
        foreach ($cru->variantes as $v) {
            $crus[$v->chave] = $v;
        }
        // O mapa GRAVADO de cada cor, com o tipo pedido trocado (por `listing_type_id`).
        $novos = [];
        foreach ($alvos as $k) {
            $precos = (array) ($crus[$k]->dados['precos'] ?? []);
            foreach ($pedido['precos'] as $tipo => $valor) {
                $precos[self::LT[$tipo]] = $valor;
            }
            $novos[$k] = $precos;
        }
        self::exigirPremiumDiferente($ligados, $ativas, $novos);
        $avisos = self::avisosDoMinimo($pedido['precos'], $ativas, $alvos);

        // O que muda de fato, comparado com o GRAVADO: por tipo, cor → o efetivo de antes (para o histórico).
        $mudam = [];
        $de = [];
        foreach ($novos as $k => $precos) {
            foreach (array_keys($pedido['precos']) as $tipo) {
                $lt = self::LT[$tipo];
                if (! self::igual($precos[$lt] ?? null, $crus[$k]->dados['precos'][$lt] ?? null)) {
                    $mudam[$k] = ['precos' => $precos];
                    $antes = $ativas[$k]->dados['precos'][$lt] ?? null;
                    $de[$tipo][$k] = $antes !== null ? (float) $antes : null;
                }
            }
        }
        if ($mudam !== []) {
            $this->editor->salvarVariantes($r, $mudam);
            $this->registrar($empresa, $quem, $chave, $item, $r->fresh() ?? $r, $pedido, $de);
        }

        return [...$this->previa->montar($empresa, $chave), 'avisos' => $avisos];
    }

    /**
     * 409 SEM `travado` pelo ESTADO do anúncio (D47): na fila de publicação, publicando ou publicado — e, fora disso, o
     * item que não está na revisão (o robô ainda prepara, no ar por outro caminho, sem rascunho).
     *
     * @throws HttpResponseException
     */
    private static function exigirQueDaParaMexer(array $item, ?PubRascunho $r): void
    {
        $motivo = match (true) {
            $r !== null && NaFilaDePublicacao::emUso((int) $r->produto_id)                                        => self::MSG_NA_FILA,
            $r !== null && $r->status === PubRascunho::PUBLISHING                                                 => self::MSG_PUBLICANDO,
            $r !== null && in_array($r->status, [PubRascunho::PUBLISHED, PubRascunho::PARTIALLY_PUBLISHED], true) => self::MSG_PUBLICADO,
            $r === null || $item['etapa'] !== 'revisao'                                                           => self::MSG_FORA_DA_REVISAO,
            default                                                                                               => null,
        };
        if ($motivo !== null) {
            throw new HttpResponseException(response()->json(['message' => $motivo], 409));
        }
    }

    /**
     * A `revisao` que a tela tinha: sem ela (ou fora da forma) é 422 — sem a revisão não há como saber se a aba é velha;
     * diferente da atual, 409 `{revisao_atual, message}` SEM `travado` (a caixa mantém o digitado e oferece [Recarregar]).
     *
     * @throws HttpResponseException|ValidationException
     */
    private static function exigirRevisao(PubRascunho $r, array $corpo): void
    {
        $revisao = $corpo['revisao'] ?? null;
        if (! is_int($revisao) && ! (is_string($revisao) && preg_match('/^[0-9]{1,9}$/', $revisao))) {
            throw ValidationException::withMessages(['revisao' => self::MSG_RECARREGUE]);
        }
        if ((int) $revisao !== (int) $r->revisao) {
            throw new HttpResponseException(response()->json(['revisao_atual' => (int) $r->revisao, 'message' => self::MSG_REVISAO], 409));
        }
    }

    /**
     * A forma do corpo: `precos` com ao menos um dos dois tipos (número > 0, até 2 casas, ou `null`) e `cor` nula ou texto.
     *
     * @return array{precos: array<string, ?float>, cor: ?string}
     *
     * @throws ValidationException
     */
    private static function validar(array $corpo): array
    {
        $valor = ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:'.self::MAXIMO];
        $mensagens = [];
        foreach (array_keys(self::LT) as $tipo) {
            foreach (['numeric', 'gt', 'decimal', 'max'] as $regra) {
                $mensagens["precos.{$tipo}.{$regra}"] = self::MSG_VALOR;
            }
        }
        Validator::make($corpo, [
            'precos'          => ['required', 'array'],
            'precos.classico' => $valor,
            'precos.premium'  => $valor,
            'cor'             => ['nullable', 'string', 'max:255'],
        ], [
            ...$mensagens,
            'precos.required' => self::MSG_SEM_TIPO,
            'precos.array'    => self::MSG_SEM_TIPO,
            'cor.string'      => self::MSG_COR,
            'cor.max'         => self::MSG_COR,
        ])->validate();

        $precos = [];
        foreach (array_keys(self::LT) as $tipo) {
            if (array_key_exists($tipo, $corpo['precos'])) {
                $precos[$tipo] = $corpo['precos'][$tipo] === null ? null : round((float) $corpo['precos'][$tipo], 2);
            }
        }
        if ($precos === []) {
            throw ValidationException::withMessages(['precos' => self::MSG_SEM_TIPO]);
        }

        return ['precos' => $precos, 'cor' => isset($corpo['cor']) ? (string) $corpo['cor'] : null];
    }

    /**
     * D46: em cada cor que o pedido toca, o preço EFETIVO do Premium não pode empatar (meio centavo) com o do Clássico
     * DEPOIS de aplicar o pedido — o tipo que o pedido não mexe vale como está (gravado ou herdado da Precificação), e o
     * que volta à Precificação (`null`) vale o dela. Só com os dois tipos ligados. As cores que o pedido não toca não
     * contam: o empate que já existia numa delas não pode impedir de consertar a outra (o `premium_igual` da prévia avisa).
     *
     * @param  list<string>  $ligados  listing_type_id dos tipos ligados
     * @param  array<string, Variante>  $ativas  as variantes ativas com os efetivos
     * @param  array<string, array<string, ?float>>  $novos  cor → o mapa gravado depois do pedido
     *
     * @throws ValidationException
     */
    private static function exigirPremiumDiferente(array $ligados, array $ativas, array $novos): void
    {
        if (! in_array('gold_special', $ligados, true) || ! in_array('gold_pro', $ligados, true)) {
            return;
        }
        foreach ($novos as $k => $precos) {
            if (PreviaNaTrilha::mesmoPreco(self::efetivoDepois($ativas[$k], $precos, 'gold_special'), self::efetivoDepois($ativas[$k], $precos, 'gold_pro'))) {
                throw ValidationException::withMessages(['precos.premium' => self::MSG_PREMIUM_IGUAL]);
            }
        }
    }

    /** O preço efetivo de uma cor num tipo depois do pedido: o gravado, senão o da Precificação daquela cor. */
    private static function efetivoDepois(Variante $ativa, array $gravado, string $lt): ?float
    {
        if (($gravado[$lt] ?? null) !== null) {
            return (float) $gravado[$lt];
        }
        $daPrecificacao = $ativa->dados['portal'][$lt]['anunciado'] ?? null;

        return $daPrecificacao !== null ? (float) $daPrecificacao : null;
    }

    /**
     * Os avisos de preço abaixo do mínimo da Precificação (o da linha que dá o preço daquela cor): um por tipo, com o
     * MAIOR mínimo que o valor pedido não alcança. `null` (voltar à Precificação) não avisa.
     *
     * @param  array<string, ?float>  $precos  o pedido, pela chave do contrato
     * @param  array<string, Variante>  $ativas
     * @param  list<string>  $alvos
     * @return list<string>
     */
    private static function avisosDoMinimo(array $precos, array $ativas, array $alvos): array
    {
        $avisos = [];
        foreach ($precos as $tipo => $valor) {
            if ($valor === null) {
                continue;
            }
            $maior = null;
            foreach ($alvos as $k) {
                $minimo = $ativas[$k]->dados['portal'][self::LT[$tipo]]['minimo'] ?? null;
                if ($minimo !== null && (float) $minimo - $valor >= PreviaNaTrilha::DIFERENCA) {
                    $maior = max($maior ?? 0.0, (float) $minimo);
                }
            }
            if ($maior !== null) {
                $avisos[] = 'Abaixo do mínimo da Precificação no '.PreviaNaTrilha::NOME_DO_TIPO[$tipo].' ('.self::reais($maior).').';
            }
        }

        return $avisos;
    }

    /**
     * O histórico: um registro por tipo que mudou, com o ator da equipe. `de` = cor → o preço EFETIVO antes (o que iria ao
     * anúncio, gravado ou herdado); `para` = o pedido (`null` = voltou ao da Precificação); `cor` = a do pedido (nula =
     * todas as cores ativas).
     *
     * @param  array<string, array<string, ?float>>  $de  tipo → cor → efetivo antes (só as cores que mudaram)
     */
    private function registrar(Company $empresa, User $quem, string $chave, array $item, PubRascunho $r, array $pedido, array $de): void
    {
        foreach ($de as $tipo => $antes) {
            $valor = $pedido['precos'][$tipo];
            $para = $valor === null ? 'o da Precificação' : self::reais($valor);
            RegistroEstrutura::registrar(AtorDoPortal::daEquipe($quem), $empresa, $r, 'trilha_preco_ajustado',
                'Preço do '.PreviaNaTrilha::NOME_DO_TIPO[$tipo]." de \"{$item['nome']}\" ajustado na revisão: {$para}"
                    .($pedido['cor'] === null ? ' (todas as cores)' : ''), [
                    'item'           => $chave,
                    'pub_produto_id' => (int) $r->produto_id,
                    'rascunho_id'    => (int) $r->id,
                    'revisao'        => (int) $r->revisao,
                    'tipo'           => $tipo,
                    'cor'            => $pedido['cor'],
                    'de'             => $antes,
                    'para'           => $valor,
                ]);
        }
    }

    private static function igual(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }

        return abs((float) $a - (float) $b) < PreviaNaTrilha::DIFERENCA;
    }

    /** `1234.5` → `R$ 1.234,50` (espaço comum, não o U+00A0 do `toLocaleString`). */
    private static function reais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
