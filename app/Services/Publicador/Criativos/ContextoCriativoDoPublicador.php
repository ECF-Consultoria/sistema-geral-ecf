<?php

namespace App\Services\Publicador\Criativos;

use App\Models\PubProdutoFatoCriativo;
use App\Models\PubRascunho;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Variacao\Eixo;

/**
 * Leitura do `pub_rascunho` do Publicador para o Creative Engine (Fase 165,
 * D-02/D-14). O `CreativeContextBuilder` continua sendo a ÚNICA fronteira
 * que monta o DTO `CreativeContext` (CTX-02) — este adaptador só devolve o
 * array de dados que o builder usa para preencher o DTO no ramo novo; nunca
 * instancia o DTO nem conhece o Creative Engine.
 *
 * Nada é gravado de volta no rascunho: `RascunhoSnapshot::comEfetivos()`
 * nunca volta ao `RascunhoRepository` (regra do preço congelado, `16` §1.6).
 */
class ContextoCriativoDoPublicador
{
    public function __construct(
        private RascunhoRepository $repo,
        private DadosEfetivosService $efetivos,
    ) {}

    /**
     * @return array{produto: string, categoria_id: ?string, descricao: ?string, atributos: array<string, string>, variacoes: array{quantidade: int, combinacoes: list<list<string>>}, loja: ?string, fatos_humanos: array{beneficios: array<int, string>, medidas: array<int, string>}}
     */
    public function montar(PubRascunho $r, string $grupo): array
    {
        $snapshot = $this->repo->snapshot($r);

        if ($r->produto !== null) {
            $e = $this->efetivos->daProduto($r->produto);
            $snapshot = $snapshot->comEfetivos($e['titulos'], $e['precos']);
        }

        $produto = '';
        foreach ($snapshot->alvosAtivos() as $alvo) {
            if (trim((string) $alvo->titulo) !== '') {
                $produto = trim((string) $alvo->titulo);

                break;
            }
        }
        if ($produto === '') {
            $produto = $r->produto?->nomeExibido() ?? '';
        }

        $categoriaId = $r->categoria_id !== null && trim((string) $r->categoria_id) !== '' ? (string) $r->categoria_id : null;

        $atributos = array_merge(
            self::atributosVerificados($snapshot->atributos),
            self::atributosDoGrupo($snapshot, $grupo),
        );

        $variantes = array_values(array_filter($snapshot->variantesAtivas(), fn ($v) => $v->valores !== []));

        return [
            'produto'      => $produto,
            'categoria_id' => $categoriaId,
            'descricao'    => $snapshot->descricao,
            'atributos'    => $atributos,
            'variacoes'    => [
                'quantidade'  => count($variantes),
                'combinacoes' => array_map(
                    fn ($v) => array_values(array_filter(array_map(
                        fn ($valor) => trim((string) $valor->valueName),
                        array_values($v->valores),
                    ), fn ($nome) => $nome !== '')),
                    $variantes,
                ),
            ],
            'loja'          => $r->produto?->contaOuNula()?->nomeContaMl(),
            'fatos_humanos' => self::fatosHumanos($r->produto_id),
        ];
    }

    /**
     * Fase 169 (TXT-01/TXT-02): fatos confirmados pelo OPERADOR para este
     * produto — ponto forte (benefício) ou medida, separados do cadastro
     * automático do ML. Sem linha nenhuma na tabela, devolve as duas listas
     * vazias (regressão zero: produto sem confirmação humana se comporta
     * exatamente como antes deste plano).
     *
     * @return array{beneficios: array<int, string>, medidas: array<int, string>}
     */
    public static function fatosHumanos(int $produtoId): array
    {
        $fatos = PubProdutoFatoCriativo::where('pub_produto_id', $produtoId)
            ->orderBy('id')
            ->get();

        return [
            'beneficios' => $fatos->where('tipo', PubProdutoFatoCriativo::TIPO_BENEFICIO)
                ->map(fn ($f) => trim((string) $f->texto))
                ->values()
                ->all(),
            'medidas' => $fatos->where('tipo', PubProdutoFatoCriativo::TIPO_MEDIDA)
                ->map(fn ($f) => trim((string) $f->texto))
                ->values()
                ->all(),
        ];
    }

    /**
     * TRUTH-01 do ramo do Publicador: só entra atributo com `value_name` não
     * vazio (trim) — a mesma regra do ramo antigo do payload. Atributo só
     * com `value_id` (ex.: "não se aplica", sem rótulo resolvido) cai fora.
     *
     * @param  array<string, array>  $atributos
     * @return array<string, string>
     */
    public static function atributosVerificados(array $atributos): array
    {
        $verificados = [];
        foreach ($atributos as $id => $valor) {
            $nome = $valor['value_name'] ?? null;
            if ($nome !== null && trim((string) $nome) !== '') {
                $verificados[(string) $id] = trim((string) $nome);
            }
        }

        return $verificados;
    }

    /**
     * Os valores do eixo da variação do grupo pedido (D-14) — ex.: pedido no
     * grupo `COLOR=id:52028`, devolve `['COLOR' => 'Azul']`. A galeria geral
     * (`GENERAL`) nunca injeta nada.
     *
     * @return array<string, string>
     */
    public static function atributosDoGrupo(RascunhoSnapshot $s, string $grupo): array
    {
        if ($grupo === ResolvedorGruposImagem::GERAL) {
            return [];
        }

        $eixos = Eixo::ordenar($s->eixos);
        $definem = array_values(array_filter($eixos, fn (Eixo $e) => $e->definesPicture));
        // Nenhum eixo define a foto: com "fotos por variante" ligado, o grupo é a
        // combinação INTEIRA (todos os eixos); senão não há grupo de variação.
        $eixosDoGrupo = $definem !== [] ? $definem : ($s->fotosPorVariante ? $eixos : []);

        foreach ($s->variantesAtivas() as $v) {
            if (ResolvedorGruposImagem::chaveDoGrupo($v, $eixos, $s->fotosPorVariante) !== $grupo) {
                continue;
            }

            $valores = [];
            foreach ($eixosDoGrupo as $e) {
                // O eixo `~custom` não é id de atributo do ML (D-14, RESEARCH §2).
                if ($e->ehCustomizado() || ! isset($v->valores[$e->chave])) {
                    continue;
                }
                $valores[$e->chave] = trim((string) $v->valores[$e->chave]->valueName);
            }

            return $valores;
        }

        return [];
    }
}
