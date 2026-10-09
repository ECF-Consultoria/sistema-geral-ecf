<?php

namespace Tests\Feature\Publicador;

use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-01 (§2 da ETAPA-3): prova do schema de fases em `pub_produtos`
 * (`2026_10_08_120000_add_fases_to_pub_produtos`). NENHUM código consome estas colunas
 * ainda — as relações `base()`/`kits()` e os casts chegam no 175-02 —, então aqui se lê
 * o valor cru por `DB::table` e por `fresh()`, sem relação Eloquent.
 *
 * O que está em jogo:
 * - O DEFAULT É O BACKFILL: produto criado sem nenhum campo de fase nasce base da Fase 1.
 *   É essa linha que garante que os 12 produtos da conta #459 em produção continuem
 *   exatamente como estavam, sem um único UPDATE.
 * - O unique `pubprod_base_qtd_uq (produto_base_id, quantidade_kit)` recusa um SEGUNDO
 *   "Kit N" do MESMO base e NÃO atrapalha as bases, todas em `(NULL, 1)`: NULL se repete
 *   em `unique` no MariaDB e no SQLite.
 * - `pubprod_base_fk` é SET NULL (CR-B02): apagar o produto base deixa o kit solto COM o
 *   histórico de publicação — rascunho, publicação, item, `ml_item_id`, payload e
 *   resposta crua do ML. Em CASCADE o histórico ia embora com o base.
 */
class FasesDoProdutoSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(string $nome = 'Polo das Fases'): MlbEmpresa
    {
        return MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS']);
    }

    private function base(MlbEmpresa $empresa, string $sku = 'CAD-01', string $nome = 'Cadeira'): PubProduto
    {
        return PubProduto::create(['mlb_empresa_id' => $empresa->id, 'sku' => $sku, 'nome' => $nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR]);
    }

    private function kit(PubProduto $base, int $quantidade, int $fase): PubProduto
    {
        return PubProduto::create(['mlb_empresa_id' => $base->mlb_empresa_id, 'company_id' => $base->company_id,
            'sku' => $base->sku.'-KIT'.$quantidade, 'nome' => 'Kit '.$quantidade.' '.$base->nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR, 'produto_base_id' => $base->id,
            'quantidade_kit' => $quantidade, 'fase' => $fase, 'estoque_calculado' => true]);
    }

    /** @return array{0: PubRascunho, 1: PubPublicacao, 2: PubPublicacaoItem} histórico de um produto já publicado no ML */
    private function publicar(PubProduto $produto, string $mlb): array
    {
        $r = (new RascunhoRepository())->criar($produto, [new Alvo('gold_special', $produto->nome)]);
        $p = $r->publicacoes()->create(['revisao' => 1, 'modelo_publicacao' => 'UP', 'status' => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(), 'iniciada_em' => now(), 'concluida_em' => now(),
            'ator' => ['equipe' => true, 'id' => 1, 'nome' => 'Dev ECF']]);
        $item = $p->itens()->create(['indice' => 0, 'listing_type_id' => 'gold_special', 'variante_chave' => ChaveCanonica::UNICA,
            'caminho' => 'items', 'payload' => ['family_name' => $produto->nome, 'category_id' => 'MLB193945'],
            'status' => PubPublicacaoItem::CREATED, 'http_status' => 201,
            'resposta' => ['status' => 201, 'corpo' => ['id' => $mlb]], 'ml_item_id' => $mlb, 'criado_em' => now()]);
        $r->update(['status' => PubRascunho::PUBLISHED]);

        return [$r, $p, $item];
    }

    /** O default É o backfill: produto que não sabe o que é fase nasce base da Fase 1. */
    public function test_produto_criado_sem_campo_de_fase_nasce_base_da_fase_1(): void
    {
        $produto = $this->base($this->empresa());

        // Valor cru no banco: é o DEFAULT da coluna que responde, não o PHP.
        $linha = DB::table('pub_produtos')->where('id', $produto->id)->first();
        $this->assertNull($linha->produto_base_id, 'base não aponta para ninguém');
        $this->assertSame(1, (int) $linha->quantidade_kit, '1 unidade');
        $this->assertSame(1, (int) $linha->fase, 'Fase 1');
        // `estoque_calculado` só vira booleano de verdade com o cast do 175-02; aqui vale o 0 do banco.
        $this->assertSame(0, (int) $linha->estoque_calculado, 'estoque próprio, não calculado');
        $this->assertFalse((bool) $linha->estoque_calculado);
        $this->assertNull($linha->kit_sugestao_recusada_em, 'ninguém recusou sugestão de kit');

        // E o modelo relê o mesmo, sem nenhuma mudança em PubProduto.
        $recarregado = $produto->fresh();
        $this->assertNull($recarregado->produto_base_id);
        $this->assertSame(1, (int) $recarregado->fase);
        $this->assertSame(1, (int) $recarregado->quantidade_kit);
    }

    /** NULL se repete em unique: o `pubprod_base_qtd_uq` não encosta nas bases. */
    public function test_duas_bases_da_mesma_empresa_convivem_no_unique(): void
    {
        $empresa = $this->empresa();

        $primeira = $this->base($empresa, 'CAD-01', 'Cadeira');
        $segunda = $this->base($empresa, 'MES-01', 'Mesa');

        $this->assertNotSame($primeira->id, $segunda->id);
        $this->assertSame(2, DB::table('pub_produtos')->whereNull('produto_base_id')->where('quantidade_kit', 1)->count(),
            'duas linhas em (NULL, 1) — NULL repetido passa no unique');
    }

    public function test_kit_2_e_kit_3_do_mesmo_base_convivem(): void
    {
        $base = $this->base($this->empresa());

        $kit2 = $this->kit($base, 2, 2);
        $kit3 = $this->kit($base, 3, 3);

        $this->assertSame(2, (int) $kit2->fresh()->fase);
        $this->assertSame(3, (int) $kit3->fresh()->fase);
        $this->assertSame(2, DB::table('pub_produtos')->where('produto_base_id', $base->id)->count());
        $this->assertSame([2, 3], DB::table('pub_produtos')->where('produto_base_id', $base->id)
            ->orderBy('quantidade_kit')->pluck('quantidade_kit')->map(fn ($q) => (int) $q)->all());
    }

    /** A única coisa que o unique faz: recusar um segundo "Kit N" do MESMO base. */
    public function test_um_segundo_kit_2_do_mesmo_base_e_recusado_pelo_banco(): void
    {
        $base = $this->base($this->empresa());
        $this->kit($base, 2, 2);

        try {
            $this->kit($base, 2, 3);
            $this->fail('o banco aceitou um segundo Kit 2 do mesmo base (pubprod_base_qtd_uq não está no ar)');
        } catch (QueryException $e) {
            $this->assertSame(1, DB::table('pub_produtos')->where('produto_base_id', $base->id)->count(),
                'o duplicado não entrou');
        }
    }

    /** Kit 2 de OUTRO base não é duplicado: a chave é (base, quantidade). */
    public function test_kit_2_de_outro_base_e_aceito(): void
    {
        $empresa = $this->empresa();
        $cadeira = $this->base($empresa, 'CAD-01', 'Cadeira');
        $mesa = $this->base($empresa, 'MES-01', 'Mesa');

        $this->kit($cadeira, 2, 2);
        $this->kit($mesa, 2, 2);

        $this->assertSame(2, DB::table('pub_produtos')->where('quantidade_kit', 2)->count());
    }

    /** CR-B02: apagar o base deixa o kit solto COM o histórico (SET NULL, nunca CASCADE). */
    public function test_apagar_o_base_deixa_o_kit_solto_com_o_historico(): void
    {
        $base = $this->base($this->empresa());
        $this->publicar($base, 'MLB9000000001');
        $kit = $this->kit($base, 2, 2);
        [$rascunhoKit, $publicacaoKit, $itemKit] = $this->publicar($kit, 'MLB9000000002');

        $base->delete();

        $this->assertNull(PubProduto::find($base->id), 'o base foi apagado de verdade');

        $kit = $kit->fresh();
        $this->assertNotNull($kit, 'o kit sobrevive ao delete do base');
        $this->assertNull($kit->produto_base_id, 'nullOnDelete: o vínculo virou NULL em vez de levar o kit');
        $this->assertSame(2, (int) $kit->quantidade_kit, 'continua sendo um Kit 2');
        $this->assertSame(2, (int) $kit->fase);

        $this->assertNotNull($rascunhoKit->fresh(), 'o rascunho do kit fica');
        $this->assertNotNull($publicacaoKit->fresh(), 'a publicação do kit fica');
        $itemKit = $itemKit->fresh();
        $this->assertNotNull($itemKit, 'o item do kit fica');
        $this->assertSame('MLB9000000002', $itemKit->ml_item_id, 'o anúncio que segue no ar continua registrado');
        $this->assertSame('Kit 2 Cadeira', $itemKit->payload['family_name'], 'o payload enviado fica');
        $this->assertSame(201, $itemKit->resposta['status'], 'a resposta crua do ML fica');
    }

    /** Depois de solto, o kit cabe no unique junto com as bases: (NULL, 2) não bate com (NULL, 1). */
    public function test_kit_solto_nao_conflita_com_as_bases_no_unique(): void
    {
        $empresa = $this->empresa();
        $base = $this->base($empresa, 'CAD-01', 'Cadeira');
        $kit = $this->kit($base, 2, 2);

        $base->delete();
        $this->base($empresa, 'MES-01', 'Mesa');

        $this->assertNull($kit->fresh()->produto_base_id);
        $this->assertSame(2, DB::table('pub_produtos')->whereNull('produto_base_id')->count());
    }
}
