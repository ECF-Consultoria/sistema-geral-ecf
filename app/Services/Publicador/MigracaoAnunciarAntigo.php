<?php

namespace App\Services\Publicador;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaPublicacao;
use App\Models\PubImagem;
use App\Models\PubPublicacao;
use App\Models\PubProduto;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Leva os rascunhos do Anunciar antigo (`estrutura_publicacoes`) para o
 * Publicador (`16` §4.3). A tabela antiga NÃO é alterada: fica só para leitura.
 *
 * - Atributos → `migrated`, a revisar (o formulário antigo era fixo e mandava
 *   número sem unidade). Embalagem → `SELLER_PACKAGE_*`.
 * - Título e preço → só viram "digitados" se forem DIFERENTES do planejado
 *   (aba Anúncios) e do anunciado (Precificação). O Anunciar antigo congelava
 *   esses valores no primeiro autosave (`16` §1.6): igual ao herdado = não foi
 *   a pessoa, então volta a herdar.
 * - Fotos → só o id do ML, sem arquivo (`caminho` nulo): não dá para
 *   revalidar nem reenviar; se o ML devolver `picture_not_found` (H-22), a
 *   pessoa sobe de novo.
 * - MLB já publicado → publicação "desconhecida" com o item CREATED, sem
 *   payload (nunca foi guardado). O tipo que falta continua publicável.
 *
 * Idempotente: oferta que já tem rascunho novo é pulada.
 */
class MigracaoAnunciarAntigo
{
    /** O formulário antigo tinha esta lista fixa; o id é o de "Garantia do vendedor" no `sale_terms` (H-09, medido em 01/10). */
    private const GARANTIAS = ['30 dias' => [30, 'dias'], '90 dias' => [90, 'dias'], '6 meses' => [6, 'meses'], '1 ano' => [1, 'anos']];
    private const GARANTIA_DO_VENDEDOR = '2230280';

    private const EMBALAGEM = [
        'peso_g' => ['SELLER_PACKAGE_WEIGHT', 'g'], 'altura_cm' => ['SELLER_PACKAGE_HEIGHT', 'cm'],
        'largura_cm' => ['SELLER_PACKAGE_WIDTH', 'cm'], 'comprimento_cm' => ['SELLER_PACKAGE_LENGTH', 'cm'],
    ];

    public function __construct(
        private RascunhoRepository $repo,
        private EstruturaPrecificacaoService $precificacao,
    ) {}

    /**
     * O que a migração faria com UMA linha, sem gravar nada.
     *
     * @return array{oferta_id: int, sku: string, acao: string, status?: string, atributos?: int, fotos?: int, titulos?: array, precos?: array, publicados?: array}
     */
    public function planejar(EstruturaPublicacao $antiga): array
    {
        $oferta = $antiga->oferta;
        if (PubRascunho::whereRelation('produto', 'oferta_id', $oferta->id)->exists()) {
            return ['oferta_id' => $oferta->id, 'sku' => $oferta->sku, 'acao' => 'pular (já migrada)'];
        }

        $d = (array) $antiga->dados;
        $herdado = $this->herdado($antiga);
        $titulos = $precos = [];
        foreach (['classico', 'premium'] as $tipo) {
            $titulos[$tipo] = self::seDiferente($d['tipos'][$tipo]['titulo'] ?? null, $herdado['titulos'][$tipo]);
            $precos[$tipo] = self::precoSeDiferente($d['tipos'][$tipo]['preco'] ?? null, $herdado['precos'][$tipo]);
        }

        return [
            'oferta_id' => $oferta->id,
            'sku' => $oferta->sku,
            'acao' => 'migrar',
            'status' => self::status($antiga),
            'atributos' => count((array) ($d['atributos'] ?? [])),
            'fotos' => count((array) ($d['fotos'] ?? [])),
            'titulos' => $titulos,
            'precos' => $precos,
            'publicados' => array_filter(['classico' => $antiga->ml_item_classico, 'premium' => $antiga->ml_item_premium]),
        ];
    }

    public function aplicar(EstruturaPublicacao $antiga): ?PubRascunho
    {
        $plano = $this->planejar($antiga);
        if ($plano['acao'] !== 'migrar') {
            return null;
        }

        return DB::transaction(function () use ($antiga, $plano) {
            $oferta = $antiga->oferta;
            $d = (array) $antiga->dados;
            $tipos = EstruturaPublicacao::LISTING_TYPES;

            $alvos = array_map(fn ($tipo) => new Alvo($tipos[$tipo], $plano['titulos'][$tipo], $antiga->mlItem($tipo) === null), ['classico', 'premium']);
            $r = $this->repo->criar(PubProduto::daOferta($oferta), $alvos, ['origem' => 'migracao_anunciar_antigo', 'estrutura_publicacao_id' => $antiga->id]);

            $r->update([
                'status' => $plano['status'],
                'categoria_id' => $d['categoria_id'] ?? null,
                'condicao' => $d['condicao'] ?? 'new',
                'descricao' => $d['descricao'] ?? null,
                'envio' => ['modo' => $d['envio']['modo'] ?? 'me2', 'frete_gratis' => (bool) ($d['envio']['frete_gratis'] ?? false), 'retirada' => false],
                'garantia' => self::garantia($d['garantia'] ?? null),
            ]);

            $atributos = [];
            foreach ((array) ($d['atributos'] ?? []) as $id => $v) {
                $atributos[$id] = ['value_id' => $v['value_id'] ?? null, 'value_name' => $v['value_name'] ?? null, 'origem' => 'migrated', 'revisar' => true];
            }
            foreach (self::EMBALAGEM as $campo => [$id, $unidade]) {
                $valor = $d['embalagem'][$campo] ?? null;
                if (is_numeric($valor) && $valor > 0) {
                    $atributos[$id] = ['value_name' => rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.')." {$unidade}", 'origem' => 'migrated', 'revisar' => false];
                }
            }
            $this->repo->gravarAtributos($r, $atributos);

            $publicada = $antiga->ml_item_classico !== null || $antiga->ml_item_premium !== null;
            $this->repo->gravarVariacao($r, [], [new Variante(ChaveCanonica::UNICA, [], dados: array_filter([
                'estoque' => $d['estoque'] ?? null,
                'precos' => array_filter([$tipos['classico'] => $plano['precos']['classico'], $tipos['premium'] => $plano['precos']['premium']], fn ($p) => $p !== null),
                'atributos' => ['SELLER_SKU' => ['value_name' => $oferta->sku]],
            ], fn ($x) => $x !== null && $x !== []), publicada: $publicada)]);

            foreach ((array) ($d['fotos'] ?? []) as $i => $f) {
                $img = $r->imagens()->create(['ml_picture_id' => $f['id'], 'ml_url' => $f['url'] ?? null, 'upload_status' => PubImagem::ENVIADA]);
                $img->atribuicoes()->create(['grupo_chave' => ResolvedorGruposImagem::GERAL, 'grupo_hash' => hash('sha256', ResolvedorGruposImagem::GERAL), 'posicao' => $i]);
            }

            if ($publicada) {
                $pub = $r->publicacoes()->create([
                    'revisao' => 1, 'modelo_publicacao' => 'desconhecido', 'status' => $plano['status'] === PubRascunho::PUBLISHED ? PubPublicacao::PUBLISHED : PubPublicacao::PARTIALLY_PUBLISHED,
                    'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => $antiga->publicado_em, 'ator' => ['origem' => 'migracao_anunciar_antigo'],
                ]);
                $indice = 0;
                foreach (['classico', 'premium'] as $tipo) {
                    if ($mlb = $antiga->mlItem($tipo)) {
                        $pub->itens()->create(['indice' => $indice++, 'listing_type_id' => $tipos[$tipo], 'variante_chave' => ChaveCanonica::UNICA,
                            'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => $mlb, 'descricao_status' => 'NONE']);
                    }
                }
            }

            return $r->fresh();
        });
    }

    /** Título planejado (aba Anúncios) e preço anunciado (Precificação) — a mesma regra do Anunciar antigo. */
    private function herdado(EstruturaPublicacao $antiga): array
    {
        $oferta = $antiga->oferta;
        $preco = $this->precificacao->pagina($oferta->company, [$oferta->id])['por_oferta'][$oferta->id] ?? [];

        $titulos = $precos = [];
        foreach (['classico', 'premium'] as $tipo) {
            $planejado = EstruturaAnuncio::where('oferta_id', $oferta->id)->where('tipo', $tipo)
                ->orderByRaw('codigo_mlb IS NULL DESC')->orderBy('id')->value('titulo');
            $titulos[$tipo] = $planejado;
            $precos[$tipo] = $preco[$tipo]['anunciado'] ?? null;
        }

        return ['titulos' => $titulos, 'precos' => $precos];
    }

    private static function status(EstruturaPublicacao $antiga): string
    {
        $publicados = (int) ($antiga->ml_item_classico !== null) + (int) ($antiga->ml_item_premium !== null);

        return match ($publicados) {
            2 => PubRascunho::PUBLISHED,
            1 => PubRascunho::PARTIALLY_PUBLISHED,
            default => PubRascunho::DRAFT,
        };
    }

    private static function garantia(?string $antiga): ?array
    {
        if ($antiga === null || ! isset(self::GARANTIAS[$antiga])) {
            return null;
        }
        [$tempo, $unidade] = self::GARANTIAS[$antiga];

        return ['tipo' => self::GARANTIA_DO_VENDEDOR, 'tempo' => $tempo, 'unidade' => $unidade];
    }

    private static function seDiferente(?string $gravado, ?string $herdado): ?string
    {
        $gravado = trim((string) $gravado);

        return $gravado === '' || $gravado === trim((string) $herdado) ? null : $gravado;
    }

    private static function precoSeDiferente(mixed $gravado, mixed $herdado): ?float
    {
        if (! is_numeric($gravado)) {
            return null;
        }

        return is_numeric($herdado) && round((float) $gravado, 2) === round((float) $herdado, 2) ? null : round((float) $gravado, 2);
    }
}
