<?php

namespace Database\Seeders;

use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\TemplatePasso;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Fase 135 Plano 04 — publica a VERSÃO 1 do template de onboarding do
 * serviço de Gestão (Performance): 13 passos, 5 automáticos (D-08/D-19).
 *
 * Escopo travado: só o serviço de Gestão nesta v1 (D-08) — nenhum outro
 * serviço do catálogo ganha template aqui.
 *
 * Idempotente: se já existe um OnboardingTemplate para o servico_id
 * resolvido, o seeder não cria nada — registra um log e retorna. Rodar
 * duas vezes nunca produz uma versão 2 nem duplica passo; publicar a
 * próxima versão é ação explícita do CRUD administrativo (Plano 08),
 * nunca deste seeder.
 *
 * `dono`, `auto_fonte` e `condicao` vêm sempre das constantes de
 * TemplatePasso — nunca como string literal solta no seeder. É o que
 * garante que uma renomeação futura do catálogo quebre no autoload em vez
 * de silenciosamente apontar para nada (D-09/D-14).
 */
class OnboardingTemplateGestaoSeeder extends Seeder
{
    public function run(): void
    {
        $servico = $this->resolverServicoDeGestao();

        if (! $servico) {
            Log::error(
                '[Onboarding] Nenhum serviço ativo do setor Performance com nome contendo "Gestão" '
                . 'foi encontrado — seeder do template de Gestão abortado. Cadastre o serviço no '
                . 'catálogo antes de rodar este seeder novamente.'
            );

            return;
        }

        if (OnboardingTemplate::where('servico_id', $servico->id)->exists()) {
            Log::info(
                "[Onboarding] Template do serviço {$servico->id} ({$servico->nome}) já está publicado "
                . '— seeder idempotente não recria nada.'
            );

            return;
        }

        $setorFinanceiro = Setor::where('slug', 'financeiro')->first();

        if (! $setorFinanceiro) {
            Log::warning(
                '[Onboarding] Setor "financeiro" não encontrado — o passo "Confirmação de pagamento" '
                . 'nasce sem setor_id (o painel apenas não mostra o sufixo "· financeiro").'
            );
        }

        $template = OnboardingTemplate::create([
            'servico_id'   => $servico->id,
            'versao'       => 1,
            'ativo'        => true,
            'publicado_em' => now(),
        ]);

        foreach ($this->passos($setorFinanceiro?->id) as $passo) {
            TemplatePasso::create(array_merge(['template_id' => $template->id], $passo));
        }

        Log::info(
            "[Onboarding] Template de Gestão v1 publicado — serviço {$servico->id} ({$servico->nome}), "
            . '13 passos, 5 com auto_fonte.'
        );
    }

    /**
     * Resolve o serviço-alvo por consulta — nunca por id fixo. Primeiro
     * serviço ativo do setor Performance cujo nome contenha "Gestão".
     */
    private function resolverServicoDeGestao(): ?Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->first();
    }

    /**
     * As 13 linhas exatas do template de Gestão v1 — copiadas literalmente
     * da tabela travada em 135-04-PLAN.md (bloco <interfaces>). Não é
     * decisão do executor.
     *
     * @return array<int, array<string, mixed>>
     */
    private function passos(?int $setorFinanceiroId): array
    {
        return [
            [
                'ordem'      => 1,
                'chave'      => 'ficha_cliente_recebida',
                'titulo'     => 'Ficha do cliente recebida',
                'dono'       => TemplatePasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 3,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 2,
                'chave'      => 'acesso_colaborador_ml',
                'titulo'     => 'Acesso colaborador Mercado Livre',
                'dono'       => TemplatePasso::DONO_CLIENTE,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 3,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 3,
                'chave'      => 'planilha_custos_adman',
                'titulo'     => 'Planilha de custos ADMAN',
                'dono'       => TemplatePasso::DONO_SISTEMA,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 5,
                'auto_fonte' => TemplatePasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
                'condicao'   => null,
            ],
            [
                'ordem'      => 4,
                'chave'      => 'grant_consultoria_adman',
                'titulo'     => 'Grant com a Consultoria (Adman)',
                'dono'       => TemplatePasso::DONO_SISTEMA,
                'setor_id'   => null,
                'depende_de' => ['planilha_custos_adman'],
                'sla_dias'   => 5,
                'auto_fonte' => TemplatePasso::AUTO_FONTE_ADMAN_GRANT,
                'condicao'   => null,
            ],
            [
                'ordem'      => 5,
                'chave'      => 'grant_sistema_ecf',
                'titulo'     => 'Grant com o Sistema ECF (OAuth)',
                // D-19 em ação: a bola é do cliente (precisa autorizar o OAuth),
                // mas ninguém digita nada — o auto_fonte abaixo fecha sozinho.
                'dono'       => TemplatePasso::DONO_CLIENTE,
                'setor_id'   => null,
                'depende_de' => ['acesso_colaborador_ml'],
                'sla_dias'   => 5,
                'auto_fonte' => TemplatePasso::AUTO_FONTE_ML_TOKEN,
                'condicao'   => null,
            ],
            [
                'ordem'      => 6,
                'chave'      => 'confirmacao_pagamento',
                'titulo'     => 'Confirmação de pagamento',
                'dono'       => TemplatePasso::DONO_INTERNO,
                'setor_id'   => $setorFinanceiroId,
                'depende_de' => null,
                'sla_dias'   => 5,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 7,
                'chave'      => 'metricas_da_conta',
                'titulo'     => 'Métricas da conta',
                'dono'       => TemplatePasso::DONO_SISTEMA,
                'setor_id'   => null,
                'depende_de' => ['planilha_custos_adman', 'grant_sistema_ecf'],
                'sla_dias'   => 1,
                'auto_fonte' => TemplatePasso::AUTO_FONTE_METRICAS,
                'condicao'   => null,
            ],
            [
                'ordem'      => 8,
                'chave'      => 'anuncios_ativos_inativos',
                'titulo'     => 'Anúncios ativos / inativos',
                'dono'       => TemplatePasso::DONO_SISTEMA,
                'setor_id'   => null,
                'depende_de' => ['grant_sistema_ecf'],
                'sla_dias'   => 1,
                'auto_fonte' => TemplatePasso::AUTO_FONTE_ACERVO,
                'condicao'   => null,
            ],
            [
                'ordem'      => 9,
                'chave'      => 'excluir_anuncios_inativos',
                'titulo'     => 'Excluir anúncios inativos',
                'dono'       => TemplatePasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['anuncios_ativos_inativos'],
                'sla_dias'   => 5,
                'auto_fonte' => null,
                // D-12: só nasce se o passo 8 apurar inativos > 0.
                'condicao'   => ['tipo' => TemplatePasso::CONDICAO_ANUNCIOS_INATIVOS],
            ],
            [
                'ordem'      => 10,
                'chave'      => 'custos_app_ecf',
                'titulo'     => 'Custos no App ECF',
                'dono'       => TemplatePasso::DONO_CLIENTE,
                'setor_id'   => null,
                'depende_de' => null,
                'sla_dias'   => 5,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 11,
                'chave'      => 'grant_de_ads',
                'titulo'     => 'Grant de Ads',
                'dono'       => TemplatePasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['grant_sistema_ecf'],
                'sla_dias'   => 5,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 12,
                'chave'      => 'agendar_reuniao_onboarding',
                'titulo'     => 'Agendar reunião de onboarding',
                'dono'       => TemplatePasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['metricas_da_conta', 'anuncios_ativos_inativos'],
                'sla_dias'   => 3,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
            [
                'ordem'      => 13,
                'chave'      => 'reuniao_realizada',
                'titulo'     => 'Reunião de onboarding realizada',
                // D-15 traduzida literalmente: depende do agendamento E do
                // pagamento — pagamento trava a CONCLUSÃO, nunca o mapeamento.
                'dono'       => TemplatePasso::DONO_INTERNO,
                'setor_id'   => null,
                'depende_de' => ['agendar_reuniao_onboarding', 'confirmacao_pagamento'],
                'sla_dias'   => 10,
                'auto_fonte' => null,
                'condicao'   => null,
            ],
        ];
    }
}
