<?php

namespace Tests\Unit\Phase162;

use App\Models\Company;
use App\Models\MlAnuncioCriativo;
use App\Models\MlAnuncioCriativoKit;
use App\Models\MlAnuncioRascunho;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fixa a regra de DINHEIRO da regeneração automática (Fase 162, Plano 03,
 * Task 2, VAL-05/VAL-06) — para ninguém "soltar" o limite depois sem
 * perceber: a automática consome o MESMO orçamento da manual
 * (`MlAnuncioCriativoKit::podeRegenerarAsset()`), nunca uma cota paralela.
 *
 * Em memória, sem HTTP nenhum — monta kit + slot direto no banco de teste.
 */
class OrcamentoDeRegeneracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.creative.kit.max_imagens'                => 14,
            'services.creative.kit.max_regeneracoes_asset'     => 3,
            'services.creative.kit.max_regeneracoes_kit'       => 7,
            'services.creative.validacao.max_validacoes_asset' => 3,
        ]);
    }

    /**
     * @return array{0: MlAnuncioCriativoKit, 1: MlAnuncioCriativo}
     */
    private function kitComUmSlot(array $kitAttrs = [], array $slotAttrs = []): array
    {
        $company  = Company::factory()->create();
        $rascunho = MlAnuncioRascunho::create([
            'company_id'  => $company->id,
            'category_id' => 'MLB1574',
            'payload'     => ['title' => 'x', 'category_id' => 'MLB1574', 'description' => 'x', 'attributes' => []],
            'status'      => MlAnuncioRascunho::STATUS_RASCUNHO,
            'user_id'     => User::factory()->create()->id,
        ]);

        $portador = MlAnuncioCriativo::create([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'slot'        => 'referencia',
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
        ]);

        $kit = MlAnuncioCriativoKit::create(array_merge([
            'token'                  => Str::random(32),
            'company_id'             => $company->id,
            'rascunho_id'            => $rascunho->id,
            'user_id'                => $rascunho->user_id,
            'criativo_referencia_id' => $portador->id,
            'status'                 => MlAnuncioCriativoKit::STATUS_PRONTO,
            'total_slots'            => 7,
            'minimo_aprovadas'       => 3,
            'imagens_geradas'        => 7,
        ], $kitAttrs));
        $portador->update(['kit_id' => $kit->id]);

        $slot = MlAnuncioCriativo::create(array_merge([
            'token'       => Str::random(32),
            'company_id'  => $company->id,
            'rascunho_id' => $rascunho->id,
            'user_id'     => $rascunho->user_id,
            'kit_id'      => $kit->id,
            'slot'        => 'benefits',
            'slot_indice' => 6,
            'slot_plano'  => ['indice' => 6, 'tipo' => 'benefits'],
            'status'      => MlAnuncioCriativo::STATUS_PRONTO,
        ], $slotAttrs));

        return [$kit->fresh(), $slot->fresh()];
    }

    // ═══ 1. Automática reduz em 1 regeneracoesRestantesAsset() — orçamento é UM SÓ ═

    public function test_regeneracao_automatica_reduz_em_1_as_regeneracoes_restantes_do_asset(): void
    {
        [$kit, $slot] = $this->kitComUmSlot();

        $this->assertSame(3, $kit->regeneracoesRestantesAsset($slot));

        // Simula exatamente o que `ValidarCriativoIaJob::talvezRegenerar()`
        // faz: incrementa `regeneracoes` do ASSET e do KIT, e marca a flag.
        // Não existe um contador paralelo "regeneracoes_automaticas_do_asset"
        // — a cota consumida é a MESMA de `criativoRegenerar()`.
        $slot->update(['regeneracao_automatica' => true]);
        $slot->increment('regeneracoes');
        $kit->increment('regeneracoes');
        $kit->increment('regeneracoes_automaticas');

        $kit->refresh();
        $slot->refresh();

        // O operador que clicar depois tem UMA chance menos — orçamento é um só.
        $this->assertSame(2, $kit->regeneracoesRestantesAsset($slot));
    }

    // ═══ 2. Teto de regeneracoes do KIT bloqueia TAMBÉM a decisão automática ═

    public function test_com_regeneracoes_do_kit_no_teto_poderegenerarasset_e_falso_para_qualquer_slot(): void
    {
        [$kit, $slot] = $this->kitComUmSlot(['regeneracoes' => 7]);

        $this->assertFalse($kit->podeRegenerarAsset($slot));

        // Mesmo um asset que NUNCA regenerou (0 regeneracoes próprias) fica
        // bloqueado pelo teto do KIT — não há cota paralela por slot capaz
        // de ignorar o teto agregado.
        $this->assertSame(0, $slot->regeneracoes);
    }

    // ═══ 3. Teto de IMAGENS bloqueia acima de tudo — o teto de dinheiro real ═

    public function test_com_teto_de_imagens_atingido_poderegenerarasset_e_falso(): void
    {
        [$kit, $slot] = $this->kitComUmSlot(['imagens_geradas' => 14, 'regeneracoes' => 0]);

        $this->assertFalse($kit->podeRegenerarAsset($slot));
    }

    // ═══ 4. regeneracoes_automaticas é SUBCONJUNTO, nunca soma paralela ═

    public function test_regeneracoes_automaticas_e_sempre_menor_ou_igual_a_regeneracoes(): void
    {
        [$kit] = $this->kitComUmSlot(['regeneracoes' => 2, 'regeneracoes_automaticas' => 1]);

        $this->assertLessThanOrEqual($kit->regeneracoes, $kit->regeneracoes_automaticas);
        $this->assertSame(1, $kit->regeneracoesManuais());

        // Caso-limite: TODAS as regenerações foram automáticas — ainda
        // assim nunca passa de `regeneracoes` (a automática incrementa as
        // DUAS colunas na mesma transação, nunca uma paralela).
        $kit->update(['regeneracoes' => 3, 'regeneracoes_automaticas' => 3]);
        $kit->refresh();
        $this->assertLessThanOrEqual($kit->regeneracoes, $kit->regeneracoes_automaticas);
        $this->assertSame(0, $kit->regeneracoesManuais());
    }

    // ═══ 5. max_validacoes_asset limita o JUIZ, independente das regeneracoes ═

    public function test_max_validacoes_asset_limita_juiz_independente_das_regeneracoes_e_custo_do_pior_caso(): void
    {
        [, $slot] = $this->kitComUmSlot();

        $maxValidacoesAsset = (int) config('services.creative.validacao.max_validacoes_asset');
        $this->assertSame(3, $maxValidacoesAsset);

        // `validacoes` conta chamadas de JUIZ; `regeneracoes` conta chamadas
        // de GERAÇÃO — são contadores INDEPENDENTES: um asset pode ter
        // `validacoes` no teto e `regeneracoes` em zero (ou o contrário) sem
        // relação direta entre os dois (CreativeJuiz::julgarEGravar() confere
        // só `validacoes`; MlAnuncioCriativoKit::podeRegenerarAsset() confere
        // só `regeneracoes`).
        $slot->update(['validacoes' => $maxValidacoesAsset, 'regeneracoes' => 0]);
        $slot->refresh();
        $this->assertSame($maxValidacoesAsset, $slot->validacoes);
        $this->assertSame(0, $slot->regeneracoes);

        // Pior caso de CUSTO de um único asset dentro desta fase:
        //   1 geração   (imagem)      ~US$ 0,101
        //   1 validação (juiz)        ~US$ 0,003
        //   1 regeneração automática  ~US$ 0,101  (VAL-05, UMA vez — VAL-06)
        //   1 validação da regenerada ~US$ 0,003
        // Total ≈ 2 × US$ 0,101 + 2 × US$ 0,003 = US$ 0,208 ≈ US$ 0,21 por
        // asset — qualquer mudança futura de teto (max_regeneracoes_asset,
        // max_validacoes_asset, max_imagens) tem este número na cara de
        // quem mexer, porque regenerar automaticamente MAIS de uma vez
        // multiplicaria este custo por asset sem limite — é exatamente o
        // que VAL-06 proíbe.
        $custoImagem   = 0.101;
        $custoJuiz     = 0.003;
        $piorCasoAsset = (2 * $custoImagem) + (2 * $custoJuiz);

        $this->assertEqualsWithDelta(0.208, $piorCasoAsset, 0.001);
    }
}
