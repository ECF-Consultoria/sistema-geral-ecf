<?php

namespace App\Services\Publicador;

use App\Models\EstruturaOferta;
use App\Models\PubEixo;
use App\Models\PubEixoValor;
use App\Models\PubImagemAtribuicao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubVariante;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Schema\CategorySchema;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\ValorEixo;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Support\Facades\DB;

/**
 * Banco ⇄ `RascunhoSnapshot`. As regras ficam no núcleo puro
 * (`App\Support\Publicador`); aqui só se lê e se grava o resultado delas.
 *
 * Lê e grava SÓ o que a pessoa digitou. Título planejado e preço da
 * Precificação entram por `RascunhoSnapshot::comEfetivos()`, que nunca volta
 * para cá — é o que impede o congelamento de preço do Anunciar antigo.
 *
 * Nada que tenha dado se perde em silêncio: valor de eixo ou eixo que sai da
 * tela e ainda tem variante órfã apontando fica com `removido = true`; variante
 * publicada nunca é apagada (`05` §4).
 */
class RascunhoRepository
{
    /**
     * O rascunho nasce com a variante única (RN-40) e os alvos que a régua manda publicar.
     *
     * @param  list<Alvo>  $alvos
     */
    public function criar(EstruturaOferta $oferta, array $alvos, ?array $ator = null): PubRascunho
    {
        return DB::transaction(function () use ($oferta, $alvos, $ator) {
            // 'produto_id': ponte até o motor receber o produto (160-02); oferta_id segue gravado pelos leitores por coluna.
            $r = PubRascunho::create(['produto_id' => PubProduto::daOferta($oferta)->id, 'oferta_id' => $oferta->id, 'status' => PubRascunho::DRAFT, 'ator' => $ator,
                'envio' => ['modo' => 'me2', 'frete_gratis' => false, 'retirada' => false]]);
            $this->gravarAlvos($r, $alvos);
            $this->gravarVariacao($r, [], [new Variante(ChaveCanonica::UNICA, [])]);

            return $r->fresh();
        });
    }

    public function snapshot(PubRascunho $r): RascunhoSnapshot
    {
        $r->load(['alvos', 'atributos', 'eixos.valores', 'variantes.valoresDosEixos', 'variantes.atributos', 'variantes.precos', 'imagens.atribuicoes']);

        // Todo valor de eixo, inclusive os removidos: as órfãs ainda apontam para eles.
        $valores = [];
        foreach ($r->eixos as $e) {
            foreach ($e->valores as $v) {
                $valores[$v->id] = [self::chaveDoEixo($e), new ValorEixo($v->value_id, $v->value_name)];
            }
        }

        $eixos = $r->eixos->where('removido', false)->values()->map(fn (PubEixo $e) => new Eixo(
            self::chaveDoEixo($e), $e->nome, $e->posicao, $e->defines_picture,
            $e->valores->where('removido', false)->values()->map(fn (PubEixoValor $v) => new ValorEixo($v->value_id, $v->value_name))->all(),
        ))->all();

        $tipoDoAlvo = $r->alvos->pluck('listing_type_id', 'id')->all();

        $variantes = $r->variantes->map(function (PubVariante $v) use ($valores, $tipoDoAlvo) {
            $doEixo = [];
            foreach ($v->valoresDosEixos as $ev) {
                [$chaveEixo, $valor] = $valores[$ev->id];
                $doEixo[$chaveEixo] = $valor;
            }

            $precos = [];
            foreach ($v->precos as $p) {
                if (isset($tipoDoAlvo[$p->alvo_id])) {
                    $precos[$tipoDoAlvo[$p->alvo_id]] = $p->preco === null ? null : (float) $p->preco;
                }
            }

            return new Variante($v->combinacao_chave, $doEixo, $v->ativa, $v->orfa, array_filter([
                'estoque' => $v->estoque,
                'estoque_depositos' => $v->estoque_depositos,
                'precos' => $precos,
                'atributos' => $v->atributos->mapWithKeys(fn ($a) => [$a->attribute_id => self::valor($a)])->all(),
            ], fn ($x) => $x !== null && $x !== []), $v->publicada);
        })->all();

        $imagens = [];
        foreach ($r->imagens as $img) {
            foreach ($img->atribuicoes as $at) {
                $imagens[] = ['imagem' => (string) $img->id, 'grupo' => $at->grupo_chave, 'posicao' => $at->posicao];
            }
        }

        return new RascunhoSnapshot(
            categoriaId: (string) $r->categoria_id,
            condicao: $r->condicao,
            atributos: $r->atributos->mapWithKeys(fn ($a) => [$a->attribute_id => self::valor($a) + ['origem' => $a->origem, 'revisar' => $a->revisar]])->all(),
            eixos: $eixos,
            variantes: $variantes,
            alvos: $r->alvos->map(fn ($a) => new Alvo($a->listing_type_id, $a->titulo, $a->ativo))->all(),
            imagens: $imagens,
            fotosPorVariante: $r->fotos_por_variante,
            incluirGeral: $r->incluir_geral_nas_variantes,
            descricao: $r->descricao,
            envio: $r->envio ?? ['modo' => 'me2', 'frete_gratis' => false, 'retirada' => false],
            garantia: $r->garantia,
        );
    }

    /** Atributos do PRODUTO: o que não vier na lista sai. @param array<string, array> $atributos */
    public function gravarAtributos(PubRascunho $r, array $atributos): void
    {
        DB::transaction(function () use ($r, $atributos) {
            $r->atributos()->whereNotIn('attribute_id', array_keys($atributos) ?: [''])->delete();
            foreach ($atributos as $id => $valor) {
                $r->atributos()->updateOrCreate(['attribute_id' => $id], [
                    ...self::colunasDeValor((array) $valor),
                    'origem' => $valor['origem'] ?? 'user',
                    'revisar' => (bool) ($valor['revisar'] ?? false),
                ]);
            }
        });
    }

    /** @param list<Alvo> $alvos */
    public function gravarAlvos(PubRascunho $r, array $alvos): void
    {
        DB::transaction(function () use ($r, $alvos) {
            $tipos = array_map(fn (Alvo $a) => $a->listingTypeId, $alvos);
            $r->alvos()->whereNotIn('listing_type_id', $tipos ?: [''])->delete();
            foreach ($alvos as $i => $a) {
                $r->alvos()->updateOrCreate(['listing_type_id' => $a->listingTypeId], [
                    'titulo' => trim((string) $a->titulo) === '' ? null : trim((string) $a->titulo),
                    'ativo' => $a->ativo,
                    'posicao' => $i,
                ]);
            }
        });
    }

    /**
     * Eixos e variantes — o resultado do `EditorEixos` / `RegeneradorVariantes`.
     * Variante que não vier na lista foi descartada pelo regenerador e sai
     * (menos as publicadas, que ele nunca descarta).
     *
     * @param  list<Eixo>  $eixos
     * @param  list<Variante>  $variantes
     */
    public function gravarVariacao(PubRascunho $r, array $eixos, array $variantes): void
    {
        DB::transaction(function () use ($r, $eixos, $variantes) {
            // 1. Eixos e valores da tela.
            $idDoValor = [];
            $eixosNaTela = [];
            foreach ($eixos as $e) {
                $linha = $r->eixos()->firstOrNew(['attribute_id' => $e->attributeId()]);
                $linha->fill(['nome' => $e->nome, 'posicao' => $e->posicao, 'defines_picture' => $e->definesPicture, 'removido' => false])->save();
                $eixosNaTela[$linha->id] = true;

                foreach ($e->valores as $v) {
                    $vl = $linha->valores()->firstOrNew(['chave_hash' => ChaveCanonica::hash($v->chave())]);
                    $vl->fill(['chave' => $v->chave(), 'value_id' => $v->valueId, 'value_name' => $v->valueName, 'posicao' => $v->posicao, 'removido' => false])->save();
                    $idDoValor[$e->chave][$v->chave()] = [$linha->id, $vl->id];
                }
            }

            // 2. Variantes. Valores de eixos que já saíram da tela (órfãs) são achados pela chave.
            $naLista = [];
            foreach (array_values($variantes) as $pos => $v) {
                $linha = $r->variantes()->firstOrNew(['combinacao_hash' => ChaveCanonica::hash($v->chave)]);
                $linha->fill([
                    'combinacao_chave' => $v->chave, 'ativa' => $v->ativa, 'orfa' => $v->orfa, 'publicada' => $v->publicada,
                    'estoque' => isset($v->dados['estoque']) && is_numeric($v->dados['estoque']) ? (int) $v->dados['estoque'] : null,
                    'estoque_depositos' => $v->dados['estoque_depositos'] ?? null,
                    'posicao' => $pos,
                ])->save();
                $naLista[] = $linha->id;

                $pivo = [];
                foreach ($v->valores as $chaveEixo => $valor) {
                    [$eixoId, $valorId] = $idDoValor[$chaveEixo][$valor->chave()] ?? $this->valorAntigo($r, $chaveEixo, $valor);
                    $pivo[$valorId] = ['eixo_id' => $eixoId];
                }
                $linha->valoresDosEixos()->sync($pivo);

                $atributos = (array) ($v->dados['atributos'] ?? []);
                $linha->atributos()->whereNotIn('attribute_id', array_keys($atributos) ?: [''])->delete();
                foreach ($atributos as $id => $valor) {
                    $linha->atributos()->updateOrCreate(['attribute_id' => $id], self::colunasDeValor((array) $valor));
                }

                $alvoDoTipo = $r->alvos()->pluck('id', 'listing_type_id')->all();
                foreach ($alvoDoTipo as $tipo => $alvoId) {
                    $preco = $v->dados['precos'][$tipo] ?? null;
                    $linha->precos()->updateOrCreate(['alvo_id' => $alvoId], ['preco' => is_numeric($preco) ? round((float) $preco, 2) : null]);
                }
            }

            $r->variantes()->whereNotIn('id', $naLista ?: [0])->where('publicada', false)->get()->each->delete();

            // 3. O que saiu da tela: apaga se ninguém aponta; senão fica marcado.
            $usados = DB::table('pub_variante_eixo_valores')->whereIn('variante_id', $r->variantes()->pluck('id'))->pluck('eixo_valor_id')->all();
            foreach ($r->eixos()->with('valores')->get() as $linha) {
                foreach ($linha->valores as $vl) {
                    $naTela = isset($eixosNaTela[$linha->id]) && in_array($vl->id, array_column($idDoValor[self::chaveDoEixo($linha)] ?? [], 1), true);
                    if (! $naTela) {
                        in_array($vl->id, $usados, true) ? $vl->update(['removido' => true]) : $vl->delete();
                    }
                }
                if (! isset($eixosNaTela[$linha->id])) {
                    $linha->valores()->exists() ? $linha->update(['removido' => true]) : $linha->delete();
                }
            }
        });
    }

    /**
     * Onde cada foto está: galeria geral (`GENERAL`) ou um grupo, e a ordem
     * (`06` §3). Só as fotos deste rascunho; a lista inteira substitui a anterior.
     *
     * @param  list<array{imagem: string|int, grupo: string, posicao: int}>  $atribuicoes
     */
    public function gravarAtribuicoes(PubRascunho $r, array $atribuicoes): void
    {
        DB::transaction(function () use ($r, $atribuicoes) {
            $ids = $r->imagens()->pluck('id')->all();
            PubImagemAtribuicao::whereIn('imagem_id', $ids ?: [0])->delete();

            $vistos = [];
            foreach ($atribuicoes as $a) {
                $imagem = (int) $a['imagem'];
                $grupo = (string) $a['grupo'];
                if (! in_array($imagem, $ids, true) || isset($vistos[$imagem][$grupo])) {
                    continue;
                }
                $vistos[$imagem][$grupo] = true;
                PubImagemAtribuicao::create(['imagem_id' => $imagem, 'grupo_chave' => $grupo, 'grupo_hash' => ChaveCanonica::hash($grupo), 'posicao' => (int) $a['posicao']]);
            }
        });
    }

    /** Metadados das fotos para a validação (L1 do arquivo e upload pendente — V-IMG-08). */
    public function metadadosDasImagens(PubRascunho $r): array
    {
        return $r->imagens()->get()->mapWithKeys(fn ($i) => [(string) $i->id => [
            'mime' => $i->mime, 'bytes' => $i->bytes, 'largura' => $i->largura, 'altura' => $i->altura, 'upload_status' => $i->upload_status,
        ]])->all();
    }

    /** id da foto no rascunho → `ml_picture_id`, só das que já subiram (o montador usa ids — RN-65). */
    public function fotosNoMl(PubRascunho $r): array
    {
        return $r->imagens()->where('upload_status', 'uploaded')->whereNotNull('ml_picture_id')->pluck('ml_picture_id', 'id')
            ->mapWithKeys(fn ($ml, $id) => [(string) $id => $ml])->all();
    }

    /**
     * A categoria escolhida (E2), ou a mesma categoria revisada depois que o
     * schema mudou no ML (V-CAT-03): grava o hash que a conferência compara.
     */
    public function gravarCategoria(PubRascunho $r, CategorySchema $s): void
    {
        $r->update(['categoria_id' => $s->categoriaId, 'dominio_id' => $s->dominio(), 'schema_hash' => $s->hash()]);
        $this->tocar($r);
    }

    /** Uma edição: a validação anterior deixa de valer (`08` §1). */
    public function tocar(PubRascunho $r): void
    {
        $r->increment('revisao');
        if ($r->status === PubRascunho::VALIDATED) {
            $r->update(['status' => PubRascunho::DRAFT]);
        }
    }

    /** Valor de um eixo que saiu da tela, para uma variante órfã que ainda aponta para ele. */
    private function valorAntigo(PubRascunho $r, string $chaveEixo, ValorEixo $valor): array
    {
        $eixo = $r->eixos()->where('attribute_id', $chaveEixo === ChaveCanonica::EIXO_CUSTOM ? null : $chaveEixo)->firstOrFail();
        $vl = $eixo->valores()->firstOrCreate(
            ['chave_hash' => ChaveCanonica::hash($valor->chave())],
            ['chave' => $valor->chave(), 'value_id' => $valor->valueId, 'value_name' => $valor->valueName, 'removido' => true],
        );

        return [$eixo->id, $vl->id];
    }

    private static function chaveDoEixo(PubEixo $e): string
    {
        return $e->attribute_id ?? ChaveCanonica::EIXO_CUSTOM;
    }

    private static function valor($linha): array
    {
        return array_filter([
            'value_id' => $linha->value_id,
            'value_name' => $linha->value_name,
            'value_number' => $linha->value_number,
            'value_unit' => $linha->value_unit,
        ], fn ($x) => $x !== null);
    }

    private static function colunasDeValor(array $v): array
    {
        return [
            'value_id' => isset($v['value_id']) && $v['value_id'] !== '' ? (string) $v['value_id'] : null,
            'value_name' => isset($v['value_name']) && $v['value_name'] !== '' ? (string) $v['value_name'] : null,
            'value_number' => isset($v['value_number']) && is_numeric($v['value_number']) ? (float) $v['value_number'] : null,
            'value_unit' => $v['value_unit'] ?? null,
        ];
    }
}
