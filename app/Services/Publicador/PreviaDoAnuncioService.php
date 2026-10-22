<?php

namespace App\Services\Publicador;

use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Support\Publicador\AlavancasLiberadas;
use App\Support\Publicador\Payload\ItemPlano;
use App\Support\Publicador\Payload\MontadorDePlano;
use App\Support\Publicador\Payload\PayloadBuilderUserProducts;
use App\Support\Publicador\PrecoDaPromocao;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\SchemaClassificado;
use App\Support\Publicador\Schema\ValorAtributo;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Collection;

/**
 * A prévia do anúncio como o comprador vai ver no Mercado Livre — a etapa 5 ("Prévia") do editor,
 * pedida pelo usuário em 20/10/2026 para quem publica conferir tudo antes de publicar e para o
 * agente revisor ler o mesmo retrato.
 *
 * Sai do MESMO plano que a conferência e a publicação mandam ao ML (`PayloadBuilderUserProducts`,
 * RN-91: o que se vê é o que se envia): um anúncio por tipo × cor ativa, com o `family_name`, o
 * preço, as fotos na ordem final (a capa do Premium já trocada, D6), a ficha, a garantia e o envio.
 * Só uma coisa muda: aqui toda foto entra, mesmo a que ainda não subiu ao ML — a tela mostra a
 * cópia guardada e avisa.
 *
 * O que NÃO vem do plano, e por quê:
 *  - a promoção: o preço da Central de Promoções sai de `PrecoDaPromocao`, a MESMA conta do
 *    gatilho pós-publicação;
 *  - as parcelas do Premium: o ML não as informa antes de publicar; a quantidade vem da faixa
 *    de preço em `publicador.previa.parcelas_premium` e a tela a chama de estimativa.
 *
 * Devolve números e textos prontos, sem HTML (o agente lê isto direto).
 */
final class PreviaDoAnuncioService
{
    public const NOME_TIPO = ['gold_special' => 'Clássico', 'gold_pro' => 'Premium'];

    private const CONDICAO = ['new' => 'Novo', 'used' => 'Usado', 'refurbished' => 'Recondicionado'];

    /** Onde a ficha aparece no ML: as características visíveis e os eixos. O resto (SKU, embalagem, oculto) não aparece. */
    private const SECOES_VISIVEIS = [AtributoClassificado::SECAO_PRINCIPAIS, AtributoClassificado::SECAO_FICHA, AtributoClassificado::SECAO_EIXO];

    public function __construct(
        private RascunhoRepository $repo,
        private DadosEfetivosService $efetivos,
        private EditorRascunhoService $editor,
    ) {}

    /**
     * @return array{
     *     pronta: bool, motivo: ?string, revisao: int,
     *     categoria: ?array{id: string, caminho: list<string>},
     *     descricao: ?string,
     *     promocao_automatica: bool, dias_da_promocao: int,
     *     tipos: list<array{listing_type_id: string, nome: string}>,
     *     eixos: list<array{chave: string, nome: string}>,
     *     variantes: list<array{chave: string, rotulo: string, valores: list<array{eixo: string, valor: string}>}>,
     *     anuncios: list<array<string, mixed>>
     * }
     */
    public function montar(PubRascunho $r): array
    {
        $r = $r->fresh(['produto']) ?? $r;
        $snapshot = $this->repo->snapshot($r)->comEfetivosDe($this->efetivos->daProduto($r->produto));
        $eixos = Eixo::ordenar($snapshot->eixos);

        $base = [
            'pronta' => false,
            'motivo' => null,
            'revisao' => (int) $r->revisao,
            'categoria' => null,
            // O texto que vai ao ML depois do item criado (sem HTML e sem emoji, RN-72).
            'descricao' => MontadorDePlano::descricao($r->descricao),
            'promocao_automatica' => AlavancasLiberadas::libera($r->produto->contaOuNula()),
            'dias_da_promocao' => PrecoDaPromocao::DIAS,
            'tipos' => array_map(fn ($a) => ['listing_type_id' => $a->listingTypeId, 'nome' => self::NOME_TIPO[$a->listingTypeId] ?? $a->listingTypeId], $snapshot->alvosAtivos()),
            'eixos' => array_map(fn (Eixo $e) => ['chave' => $e->chave, 'nome' => $e->nome], $eixos),
            'variantes' => array_map(fn (Variante $v) => [
                'chave' => $v->chave, 'rotulo' => $v->rotulo($eixos), 'valores' => $this->valoresDaVariante($v, $eixos),
            ], $snapshot->variantesAtivas()),
            'anuncios' => [],
        ];

        [$schema, $erroSchema] = $this->editor->schemaDe($r, $snapshot);
        if ($schema === null) {
            return [...$base, 'motivo' => $erroSchema ?? 'Escolha a categoria na etapa Produto para ver a prévia.'];
        }
        $base['categoria'] = ['id' => $schema->categoriaId, 'caminho' => array_values($schema->caminho)];

        /** @var Collection<string, PubImagem> $imagens */
        $imagens = $r->imagens()->get()->keyBy(fn (PubImagem $i) => (string) $i->id);
        // Toda foto conta como enviada: o plano sai com a ordem final, inclusive a que ainda não subiu.
        $todas = $imagens->keys()->mapWithKeys(fn ($id) => [(string) $id => (string) $id])->all();

        try {
            $itens = PayloadBuilderUserProducts::montar($snapshot, $schema, $todas);
        } catch (RegraViolada $e) {
            return [...$base, 'motivo' => $e->getMessage()];
        }

        if ($itens === []) {
            return [...$base, 'motivo' => $snapshot->alvosAtivos() === []
                ? 'Nenhum tipo de anúncio ligado: ligue o Clássico ou o Premium em Condições de venda.'
                : 'Nenhuma variação ativa: ative ao menos uma na etapa Detalhes.'];
        }

        $variantes = collect($snapshot->variantesAtivas())->keyBy(fn (Variante $v) => $v->chave);
        $envioQueVale = $this->envioQueVaiValer($r);

        return [
            ...$base,
            'pronta' => true,
            'anuncios' => array_map(fn (ItemPlano $i) => $this->anuncio($r, $i, $variantes->get($i->varianteChave), $eixos, $schema, $snapshot, $imagens, $envioQueVale), $itens),
        ];
    }

    /**
     * Um anúncio do plano, como o comprador vê.
     *
     * @param  list<Eixo>  $eixos
     * @param  Collection<string, PubImagem>  $imagens
     * @param  ?array{modo: string, aviso: string}  $envioQueVale  o envio que o ML vai aplicar no lugar do plano ({@see envioQueVaiValer})
     */
    private function anuncio(PubRascunho $r, ItemPlano $i, ?Variante $v, array $eixos, SchemaClassificado $schema, RascunhoSnapshot $s, Collection $imagens, ?array $envioQueVale = null): array
    {
        $p = $i->payload;
        $titulo = isset($p['family_name']) ? trim((string) $p['family_name']) : null;
        $valores = $v ? $this->valoresDaVariante($v, $eixos) : [];
        $preco = isset($p['price']) ? (float) $p['price'] : null;
        $promocao = PrecoDaPromocao::calcular($preco, $v?->dados['portal'][$i->listingTypeId] ?? null);
        $premium = $i->listingTypeId === 'gold_pro';

        return [
            'indice' => $i->indice,
            'listing_type_id' => $i->listingTypeId,
            'tipo' => self::NOME_TIPO[$i->listingTypeId] ?? $i->listingTypeId,
            'variante' => ['chave' => $i->varianteChave, 'rotulo' => $i->rotulo, 'valores' => $valores],
            'titulo' => $titulo !== '' ? $titulo : null,
            // No modelo User Products o ML acrescenta o valor da variação ao fim do título
            // (medido na publicação real de 10/10/2026, learnings publicador-ml §21).
            'titulo_no_ml' => $titulo ? trim($titulo.' '.implode(' ', array_column($valores, 'valor'))) : null,
            'preco' => $preco,
            'promocao' => $promocao['calculavel']
                ? ['preco' => $promocao['preco'], 'percentual' => $promocao['percentual'], 'ajustada_ao_minimo' => $promocao['ajustada_ao_minimo']]
                : null,
            'sem_promocao' => $promocao['calculavel'] ? null : PrecoDaPromocao::MOTIVOS[$promocao['motivo']] ?? null,
            // Só o Premium parcela sem juros; a quantidade é estimativa pela faixa de preço.
            'parcelas' => $premium ? [
                'sem_promocao' => self::parcelasPremium($preco),
                'com_promocao' => $promocao['calculavel'] ? self::parcelasPremium($promocao['preco']) : null,
            ] : null,
            'estoque' => isset($p['available_quantity']) ? (int) $p['available_quantity'] : null,
            'condicao' => self::CONDICAO[$s->condicao] ?? 'Novo',
            'fotos' => array_values(array_filter(array_map(
                fn (array $foto) => $this->foto($r, $imagens->get((string) $foto['id'])),
                (array) ($p['pictures'] ?? []),
            ))),
            'caracteristicas' => $this->caracteristicas((array) ($p['attributes'] ?? []), $schema, $s, $v),
            'garantia' => $this->garantia($s->garantia, $schema),
            // N1 (23/10): fora do Mercado Envios o ML publica "a combinar" e tira o frete grátis sem erro (§25):
            // a prévia mostra o que vai valer, não o que o plano pede.
            'envio' => [
                'modo' => $envioQueVale['modo'] ?? (string) ($p['shipping']['mode'] ?? 'me2'),
                'frete_gratis' => $envioQueVale === null && (bool) ($p['shipping']['free_shipping'] ?? false),
                'retirada' => (bool) ($p['shipping']['local_pick_up'] ?? false),
                'aviso' => $envioQueVale['aviso'] ?? null,
            ],
        ];
    }

    /**
     * Parcelas sem juros do Premium para um preço: a primeira faixa de `publicador.previa.parcelas_premium`
     * que o cobre. Nulo = sem preço.
     *
     * @return ?array{vezes: int, valor: float}
     */
    public static function parcelasPremium(?float $preco): ?array
    {
        if ($preco === null || $preco <= 0) {
            return null;
        }
        foreach ((array) config('publicador.previa.parcelas_premium', []) as $faixa) {
            $ate = $faixa['ate'] ?? null;
            $vezes = (int) ($faixa['vezes'] ?? 0);
            if ($vezes >= 1 && ($ate === null || $preco <= (float) $ate)) {
                return ['vezes' => $vezes, 'valor' => round($preco / $vezes, 2)];
            }
        }

        return null;
    }

    /**
     * @param  list<Eixo>  $eixos
     * @return list<array{eixo: string, valor: string}>
     */
    private function valoresDaVariante(Variante $v, array $eixos): array
    {
        $saida = [];
        foreach ($eixos as $e) {
            if (isset($v->valores[$e->chave])) {
                $saida[] = ['eixo' => $e->nome, 'valor' => $v->valores[$e->chave]->valueName];
            }
        }

        return $saida;
    }

    /** A foto como a tela desenha: a do ML quando já subiu; senão a cópia guardada aqui (D26). */
    private function foto(PubRascunho $r, ?PubImagem $i): ?array
    {
        if ($i === null) {
            return null;
        }
        $url = $i->ml_url ?: ($i->caminho !== null ? route('mlb.anuncios.publicador.fotos.arquivo', ['produto' => $r->produto_id, 'imagem' => $i->id]) : null);
        if ($url === null) {
            return null;
        }

        return [
            'id' => (string) $i->id, 'url' => $url, 'largura' => $i->largura, 'altura' => $i->altura,
            'no_ml' => $i->upload_status === 'uploaded' && $i->ml_picture_id !== null,
        ];
    }

    /**
     * A ficha como o ML mostra em "Características do produto": só o que é visível (principais, demais
     * grupos e o valor da variação), na ordem dos grupos do schema. "Não se aplica" não aparece.
     *
     * @param  list<array<string, mixed>>  $atributos  os do payload (id + value_id/value_name; eixo customizado = name + value_name)
     * @return list<array{grupo: string, itens: list<array{nome: string, valor: string}>}>
     */
    private function caracteristicas(array $atributos, SchemaClassificado $schema, RascunhoSnapshot $s, ?Variante $v): array
    {
        $porId = [];
        $livres = [];
        foreach ($atributos as $a) {
            if (! isset($a['id'])) {
                // Eixo customizado (`{name, value_name}`): o ML mostra com o nome que a equipe deu.
                if (trim((string) ($a['value_name'] ?? '')) !== '') {
                    $livres[] = ['nome' => (string) ($a['name'] ?? 'Variação'), 'valor' => (string) $a['value_name']];
                }

                continue;
            }
            $def = $schema->atributo((string) $a['id']);
            if ($def === null || ! in_array($def->secao, self::SECOES_VISIVEIS, true) || ($a['value_id'] ?? null) === ValorAtributo::NAO_SE_APLICA) {
                continue;
            }
            $valor = $this->valorParaTela($def, $a, $s, $v);
            if ($valor !== null) {
                $porId[$def->id] = ['nome' => $def->nome, 'valor' => $valor];
            }
        }

        $grupos = [];
        $usados = [];
        foreach ($schema->grupos as $g) {
            if (($g['id'] ?? null) === 'AVANCADO') {
                continue;
            }
            $itens = [];
            foreach ((array) ($g['atributos'] ?? []) as $id) {
                if (isset($porId[$id])) {
                    $itens[] = $porId[$id];
                    $usados[$id] = true;
                }
            }
            if ($itens !== []) {
                $grupos[] = ['grupo' => (string) ($g['label'] ?? 'Características'), 'itens' => $itens];
            }
        }

        // Os eixos (a cor, por exemplo) e o que ficou fora de grupo entram nas principais, como no ML.
        $resto = [...array_values(array_diff_key($porId, $usados)), ...$livres];
        if ($resto !== []) {
            if ($grupos === []) {
                $grupos[] = ['grupo' => 'Características principais', 'itens' => $resto];
            } else {
                $grupos[0]['itens'] = [...$grupos[0]['itens'], ...$resto];
            }
        }

        return $grupos;
    }

    /** O texto do valor: o nome da lista (por `value_id`) ou o digitado; nulo = nada a mostrar. */
    private function valorParaTela(AtributoClassificado $def, array $a, RascunhoSnapshot $s, ?Variante $v): ?string
    {
        $id = trim((string) ($a['value_id'] ?? ''));
        if ($id !== '') {
            foreach ($def->valores as $opcao) {
                if ((string) ($opcao['id'] ?? '') === $id) {
                    return (string) $opcao['name'];
                }
            }
            // Fora da lista do schema (eixo, valor antigo): o nome que o rascunho guardou.
            if ($v !== null && isset($v->valores[$def->id])) {
                return $v->valores[$def->id]->valueName;
            }
            $guardado = trim((string) ($v?->dados['atributos'][$def->id]['value_name'] ?? $s->atributos[$def->id]['value_name'] ?? ''));

            return $guardado !== '' ? $guardado : null;
        }
        $texto = trim((string) ($a['value_name'] ?? ''));

        return $texto !== '' ? $texto : null;
    }

    /** "Garantia do vendedor: 90 dias" — o nome do tipo vem do schema; "sem garantia" não leva tempo. */
    private function garantia(?array $g, SchemaClassificado $schema): ?string
    {
        if (empty($g['tipo'])) {
            return null;
        }
        $nome = null;
        foreach ((array) ($schema->garantia['tipos'] ?? []) as $tipo) {
            if ((string) ($tipo['id'] ?? '') === (string) $g['tipo']) {
                $nome = (string) $tipo['name'];
            }
        }
        $nome ??= 'Garantia';
        if (empty($g['tempo'])) {
            return $nome;
        }

        return $nome.': '.((int) $g['tempo']).' '.($g['unidade'] ?? 'dias');
    }

    /**
     * N1 (demo de 23/10/2026): o envio que o Mercado Livre vai aplicar quando ele difere do plano — nulo quando vale
     * o plano.
     *
     * Só lê o que a última conferência GRAVOU (nenhuma chamada ao ML, nenhum cálculo de frete aqui): o aviso
     * `shipping.lost_me2*` do validate (o 4057 da Poltrona Opala, §25: o anúncio sai "a combinar" e o frete grátis
     * some sem erro) ou o `respostas_ml.frete` com `motivo` `sem_mercado_envios`/`fora_do_me2` que a conferência
     * de frete grava (`ConferenciaService::freteContraAPrecificacao`, com o `destino` do `EnvioDoRascunhoService`).
     * Destino `me1` = a conta tem a transportadora do vendedor; o resto (`combinar`, `fora`) = "a combinar".
     *
     * @return ?array{modo: string, aviso: null}
     */
    private function envioQueVaiValer(PubRascunho $r): ?array
    {
        /** @var ?\App\Models\PubValidacao $v */
        $v = $r->validacoes()->latest('id')->first();
        if ($v === null) {
            return null;
        }

        $frete = (array) ($v->respostas_ml['frete'] ?? []);
        $perdeMe2 = in_array($frete['motivo'] ?? null, ['sem_mercado_envios', 'fora_do_me2'], true);
        foreach ((array) $v->issues as $issue) {
            if (str_starts_with((string) ($issue['ml_causa']['code'] ?? ''), 'shipping.lost_me2')) {
                $perdeMe2 = true;
            }
        }
        if (! $perdeMe2) {
            return null;
        }

        // Sem aviso (22/10/2026). O usuário, sobre "a entrega fica a combinar e o frete grátis não vale": "outro aviso
        // nada a ver — frete grátis é para o CLIENTE; ainda assim a gente paga o frete se for acima de 79". Frete grátis
        // é o cliente não pagar a entrega, e isso continua valendo fora do Mercado Envios: quem paga é o vendedor. A
        // prévia só mostra a entrega como o Mercado Livre vai exibi-la.
        if (($frete['destino'] ?? null) === 'me1') {
            return ['modo' => 'me1', 'aviso' => null];
        }

        return ['modo' => 'not_specified', 'aviso' => null];
    }
}
