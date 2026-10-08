<?php

namespace App\Services\Publicador;

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
     * Um rascunho por produto; a oferta, quando há, é a do produto.
     * O rascunho nasce com a variante única (RN-40) e os alvos que a régua manda publicar.
     *
     * @param  list<Alvo>  $alvos
     */
    public function criar(PubProduto $produto, array $alvos, ?array $ator = null): PubRascunho
    {
        return DB::transaction(function () use ($produto, $alvos, $ator) {
            // D27: só produto_id. pub_rascunhos.oferta_id é coluna legada dormente; a oferta (quando há) é a do produto.
            $r = PubRascunho::create(['produto_id' => $produto->id, 'status' => PubRascunho::DRAFT, 'ator' => $ator,
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
            $multi = $this->multiGuardados($r);
            foreach ($atributos as $id => $valor) {
                $r->atributos()->updateOrCreate(['attribute_id' => $id], [
                    ...self::colunasDoProduto((array) $valor, $multi[$id] ?? null),
                    'origem' => $valor['origem'] ?? 'user',
                    'revisar' => (bool) ($valor['revisar'] ?? false),
                ]);
            }
        });
    }

    /**
     * Atributos do PRODUTO por chave (WR-B02): grava SÓ os ids dados; os outros ficam como estão.
     * Para quem escreve sem ser a tela (a IA) — nunca apaga o que a pessoa gravou no meio.
     *
     * @param  array<string, array>  $atributos
     */
    public function mesclarAtributos(PubRascunho $r, array $atributos): void
    {
        DB::transaction(function () use ($r, $atributos) {
            $multi = $this->multiGuardados($r);
            foreach ($atributos as $id => $valor) {
                $r->atributos()->updateOrCreate(['attribute_id' => $id], [
                    ...self::colunasDoProduto((array) $valor, $multi[$id] ?? null),
                    'origem' => $valor['origem'] ?? 'user',
                    'revisar' => (bool) ($valor['revisar'] ?? false),
                ]);
            }
        });
    }

    /**
     * O título de alvos que JÁ existem, por tipo de anúncio (WR-B02): não cria nem apaga alvo,
     * não mexe em `ativo` nem na ordem — o resto da lista fica como está.
     *
     * @param  array<string, ?string>  $porTipo  listing_type_id → título
     */
    public function gravarTitulos(PubRascunho $r, array $porTipo): void
    {
        DB::transaction(function () use ($r, $porTipo) {
            foreach ($porTipo as $tipo => $titulo) {
                $r->alvos()->where('listing_type_id', $tipo)->update([
                    'titulo' => trim((string) $titulo) === '' ? null : mb_substr(trim((string) $titulo), 0, 255),
                ]);
            }
        });
    }

    /**
     * Trava a linha do rascunho até o fim da transação em curso (WR-B02). É a mesma trava de
     * `PublicacaoService::iniciar`: editor, IA e publicação escrevem um de cada vez, e quem
     * entra depois lê o que o outro gravou. Fora de transação não segura nada — chamar dentro
     * de `DB::transaction`. (No SQLite dos testes o `FOR UPDATE` não existe: a ordem é que vale.)
     */
    public function travar(PubRascunho $r): void
    {
        PubRascunho::whereKey($r->id)->lockForUpdate()->value('id');
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
            // D-13: as opções de um atributo de várias opções vão à tela e voltam (o payload não as usa).
            'values_multi' => $linha->values_multi ?? null,
        ], fn ($x) => $x !== null && $x !== []);
    }

    /** @return array<string, array{value_id: ?string, values_multi: array}> atributo → o que está guardado com várias opções */
    private function multiGuardados(PubRascunho $r): array
    {
        $saida = [];
        foreach ($r->atributos()->whereNotNull('values_multi')->get(['attribute_id', 'value_id', 'values_multi']) as $a) {
            if (is_array($a->values_multi) && $a->values_multi !== []) {
                $saida[$a->attribute_id] = ['value_id' => $a->value_id, 'values_multi' => $a->values_multi];
            }
        }

        return $saida;
    }

    /**
     * Colunas de um atributo do PRODUTO. Quem grava sem mandar `values_multi` (a tela, a IA) não apaga as
     * opções guardadas enquanto a 1ª opção (`value_id`) continuar a mesma; trocou a opção, a lista velha sai.
     *
     * @param  ?array{value_id: ?string, values_multi: array}  $guardado
     */
    private static function colunasDoProduto(array $v, ?array $guardado): array
    {
        $colunas = self::colunasDeValor($v);
        if (! array_key_exists('values_multi', $v) && $guardado !== null && $colunas['value_id'] !== null
            && $colunas['value_id'] === (string) $guardado['value_id']) {
            $colunas['values_multi'] = array_values($guardado['values_multi']);
        }

        return $colunas;
    }

    private static function colunasDeValor(array $v): array
    {
        return [
            'value_id' => isset($v['value_id']) && $v['value_id'] !== '' ? (string) $v['value_id'] : null,
            'value_name' => isset($v['value_name']) && $v['value_name'] !== '' ? (string) $v['value_name'] : null,
            'value_number' => isset($v['value_number']) && is_numeric($v['value_number']) ? (float) $v['value_number'] : null,
            'value_unit' => $v['value_unit'] ?? null,
            // Atributo de várias opções (Fase 172): a 1ª fica em value_id, todas aqui. Sem lista, a coluna zera.
            'values_multi' => isset($v['values_multi']) && is_array($v['values_multi']) && $v['values_multi'] !== [] ? array_values($v['values_multi']) : null,
        ];
    }
}
