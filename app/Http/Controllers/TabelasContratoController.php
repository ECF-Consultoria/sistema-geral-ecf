<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ContratoTabelaProposta;
use App\Models\EmpresaFaixaFaturamento;
use App\Services\Fechamento\FechamentoFaixaResolver;
use App\Services\Fechamento\GravarTabelaEmpresaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * TabelasContratoController — Fase 140 Plano 05 (TAB-08/TAB-09, D-05/D-06).
 *
 * Fecha o ciclo aberto pelo plano 140-04: uma pessoa confere, contrato a contrato, o que a leitura
 * automática do Clicksign encontrou (`ContratoTabelaProposta`) e só então a tabela vira cobrança de
 * verdade. Nenhum casamento automático (D-05 do CONTEXT — zero na varredura real de 85 contratos)
 * chega perto de `empresa_faixas_faturamento`/`companies` sem essa confirmação.
 *
 * Vocabulário da tela (T-140-11 estendido a esta camada): sem "proposta"/"parser"/"envelope"/
 * "score"/"palpite" como rótulo técnico — os textos vêm das mesmas constantes de vocabulário do
 * relatório do plano 140-03 (`CONFIANCA_LABEL`/`TIPO_LABEL` abaixo, com a MESMA redação).
 *
 * ⚠️ T-140-23 — as props desta tela mandam só o necessário para conferir (nome, faixas, confiança):
 * nunca o texto do contrato nem link da Clicksign.
 */
class TabelasContratoController extends Controller
{
    /**
     * Vocabulário de confiança — MESMA redação de `ClicksignExtrairTabelas::CONFIANCA_LABEL`
     * (140-03), para a tela nunca divergir do relatório que o usuário já validou.
     *
     * @var array<string, string>
     */
    private const CONFIANCA_LABEL = [
        ContratoTabelaProposta::CONFIANCA_CERTO    => 'confirmado pelo CNPJ',
        ContratoTabelaProposta::CONFIANCA_PROVAVEL => 'parece ser esta — confira',
        ContratoTabelaProposta::CONFIANCA_INCERTO  => 'só um palpite — confira',
    ];

    /**
     * Vocabulário de tipo de cobrança — cobre as QUATRO categorias que `ContratoTabelaProposta`
     * normaliza (`TIPO_TABELA`/`TIPO_VALOR_FIXO`/`TIPO_INDEFINIDO`/`TIPO_ILEGIVEL`), nunca o rótulo
     * cru da constante.
     *
     * @var array<string, string>
     */
    private const TIPO_LABEL = [
        ContratoTabelaProposta::TIPO_TABELA     => 'cobra por faixa de faturamento',
        ContratoTabelaProposta::TIPO_VALOR_FIXO => 'valor fixo por mês',
        ContratoTabelaProposta::TIPO_INDEFINIDO => 'não deu para entender a cobrança',
        ContratoTabelaProposta::TIPO_ILEGIVEL   => 'não deu para ler o arquivo',
    ];

    /**
     * GET /administrativo/contratos/tabelas — lista das leituras para conferência (T-140-18/TAB-08).
     *
     * Filtro por `situacao` (pendente por padrão — é o que sobra para conferir) e por `confianca`.
     * O resumo com as quatro contagens do relatório (140-03) é calculado sobre o universo INTEIRO,
     * nunca sobre o recorte filtrado — mesma disciplina de `ContratoAdminController::index()`: o
     * resumo é a régua fixa contra a qual a pessoa compara o que está vendo.
     */
    public function index(Request $request, FechamentoFaixaResolver $resolver): \Inertia\Response
    {
        $situacaoWhitelist = [
            ContratoTabelaProposta::SITUACAO_PENDENTE,
            ContratoTabelaProposta::SITUACAO_CONFIRMADA,
            ContratoTabelaProposta::SITUACAO_DESCARTADA,
        ];
        $situacaoInput = $request->input('situacao', ContratoTabelaProposta::SITUACAO_PENDENTE);
        $situacao = in_array($situacaoInput, $situacaoWhitelist, true)
            ? $situacaoInput
            : ContratoTabelaProposta::SITUACAO_PENDENTE;

        $confiancaWhitelist = [
            ContratoTabelaProposta::CONFIANCA_CERTO,
            ContratoTabelaProposta::CONFIANCA_PROVAVEL,
            ContratoTabelaProposta::CONFIANCA_INCERTO,
        ];
        $confiancaInput = $request->input('confianca');
        $confianca = in_array($confiancaInput, $confiancaWhitelist, true) ? $confiancaInput : null;

        $query = ContratoTabelaProposta::query()
            ->with(['company', 'confirmadoPor'])
            ->where('situacao', $situacao);

        if ($confianca !== null) {
            $query->where('confianca', $confianca);
        }

        $propostas = $query->orderByDesc('envelope_data')->orderByDesc('id')->paginate(20)->withQueryString();
        $propostas->through(fn (ContratoTabelaProposta $p) => $this->linha($p, $resolver));

        return Inertia::render('Admin/TabelasContrato', [
            'propostas' => $propostas,
            'filters'   => [
                'situacao'  => $situacao,
                'confianca' => $confianca,
            ],
            // Resumo (140-03) — sempre sobre o universo inteiro, nunca o recorte filtrado.
            'resumo' => $this->resumoContagens(),
            // Universo do seletor de empresa (T-140-20/D-05) — nunca gerado no client. Só o
            // necessário para o seletor de busca (T-140-23).
            'empresas' => Company::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'cnpj', 'razao_social'])
                ->values(),
        ]);
    }

    /**
     * POST /administrativo/contratos/tabelas/{proposta}/confirmar (T-140-20/T-140-21/T-140-22).
     *
     * A pessoa confirma a empresa SEMPRE — mesmo quando o palpite já veio preenchido (D-05: um
     * vínculo errado grava a tabela de uma empresa em outra e ninguém revisa depois). `company_id`
     * é obrigatório e existente nesta requisição, nunca herdado silenciosamente do que a leitura
     * automática chutou.
     */
    public function confirmar(Request $request, ContratoTabelaProposta $proposta, GravarTabelaEmpresaService $servico): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        // T-140-20 — proposta já conferida (confirmada OU descartada) nunca é confirmada de novo.
        if ($proposta->situacao !== ContratoTabelaProposta::SITUACAO_PENDENTE) {
            abort(422, 'Este contrato já foi conferido — não é possível confirmar de novo.');
        }

        $avisoCnpj = null;

        DB::transaction(function () use ($data, $proposta, $request, $servico, &$avisoCnpj) {
            $company = Company::findOrFail($data['company_id']);

            // ── All-or-nothing (D-13 da Fase 137) ──────────────────────────
            // Fase 142 (142-01) — escrita centralizada: a mesma porta única de
            // `FechamentoController::salvarFaixasEmpresa()`, com origem 'contrato' — confirmação
            // humana da leitura do Clicksign, que sobrescreve qualquer presunção anterior.
            if ($proposta->tipo_cobranca === ContratoTabelaProposta::TIPO_TABELA) {
                $servico->gravar(
                    $company,
                    $proposta->faixas ?? [],
                    EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
                    null,
                    $request->user(),
                    'contrato_leitura',
                );
            }
            // D-03 — valor_fixo/indefinido/ilegivel NUNCA viram faixa. É exatamente o erro que
            // esta fase existe para corrigir: pôr em faixa quem tem contrato de valor fixo.

            // ── Completa cadastro, de carona (T-140-21) ────────────────────
            $mudancasCompany = [];

            if (blank($company->razao_social) && filled($proposta->razao_social_lida)) {
                $mudancasCompany['razao_social'] = $proposta->razao_social_lida;
            }

            if (blank($company->cnpj) && filled($proposta->cnpj_lido)) {
                $cnpjNormalizado = preg_replace('/\D/', '', $proposta->cnpj_lido) ?? $proposta->cnpj_lido;

                // Coluna é única — se o CNPJ lido já pertence a OUTRA empresa, não grava (senão a
                // transação inteira quebraria por violação de unicidade) e avisa quem confirmou.
                $donoDoCnpj = Company::where('id', '!=', $company->id)
                    ->where(fn ($w) => $w->where('cnpj', $proposta->cnpj_lido)->orWhere('cnpj', $cnpjNormalizado))
                    ->exists();

                if ($donoDoCnpj) {
                    $avisoCnpj = 'O CNPJ lido neste contrato já pertence a outra empresa cadastrada — não foi gravado. Confira manualmente.';
                } else {
                    $mudancasCompany['cnpj'] = $proposta->cnpj_lido;
                }
            }

            if ($mudancasCompany !== []) {
                $company->fill($mudancasCompany);
                $company->save();
            }

            $proposta->fill([
                'company_id'     => $company->id,
                'situacao'       => ContratoTabelaProposta::SITUACAO_CONFIRMADA,
                'confirmado_por' => $request->user()->id,
                'confirmado_em'  => now(),
            ]);
            $proposta->save();
        });

        $mensagem = $proposta->tipo_cobranca === ContratoTabelaProposta::TIPO_TABELA
            ? 'Tabela confirmada — a cobrança desta empresa já está atualizada.'
            : 'Conferido — este contrato é de valor fixo, nenhuma faixa foi criada.';

        $response = back()->with('success', $mensagem);

        return $avisoCnpj !== null ? $response->with('aviso', $avisoCnpj) : $response;
    }

    /**
     * POST /administrativo/contratos/tabelas/{proposta}/descartar — marca como descartada, não
     * grava nada em cobrança nenhuma. Registra quem e quando (mesmos campos de auditoria da
     * confirmação — LogsActivity do model já dá a trilha completa, T-140-22).
     */
    public function descartar(Request $request, ContratoTabelaProposta $proposta): RedirectResponse
    {
        if ($proposta->situacao !== ContratoTabelaProposta::SITUACAO_PENDENTE) {
            abort(422, 'Este contrato já foi conferido.');
        }

        $proposta->fill([
            'situacao'       => ContratoTabelaProposta::SITUACAO_DESCARTADA,
            'confirmado_por' => $request->user()->id,
            'confirmado_em'  => now(),
        ]);
        $proposta->save();

        return back()->with('success', 'Descartado. Nada foi gravado na cobrança desta empresa.');
    }

    /**
     * Achata UMA proposta para a tela — nunca o model inteiro (T-140-23).
     */
    private function linha(ContratoTabelaProposta $proposta, FechamentoFaixaResolver $resolver): array
    {
        return [
            'id'                  => $proposta->id,
            'nome_envelope'       => $proposta->nome_envelope,
            'envelope_data'       => optional($proposta->envelope_data)->format('Y-m-d'),
            'situacao'            => $proposta->situacao,
            'confianca'           => $proposta->confianca,
            'confianca_label'     => self::CONFIANCA_LABEL[$proposta->confianca] ?? $proposta->confianca,
            'ambiguo'             => (bool) $proposta->ambiguo,
            'tipo_cobranca'       => $proposta->tipo_cobranca,
            'tipo_cobranca_label' => self::TIPO_LABEL[$proposta->tipo_cobranca] ?? $proposta->tipo_cobranca,
            'valor_fixo'          => $proposta->valor_fixo !== null ? (float) $proposta->valor_fixo : null,
            'valor_fixo_formatado' => $proposta->valor_fixo !== null ? $this->fmtBRL((float) $proposta->valor_fixo) : null,
            'faixas'              => $proposta->faixas,
            'faixas_formatadas'   => $proposta->faixas !== null ? $this->formatarFaixas($proposta->faixas) : [],
            'cnpj_lido'           => $proposta->cnpj_lido,
            'razao_social_lida'   => $proposta->razao_social_lida,
            'motivo'              => $proposta->motivo,
            'company_id'          => $proposta->company_id,
            'company_nome'        => $proposta->company?->name,
            'candidatos'          => $proposta->candidatos ?? [],
            'confirmado_por_nome' => $proposta->confirmadoPor?->name,
            'confirmado_em'       => optional($proposta->confirmado_em)->toIso8601String(),
            // Comparação "lida × em uso hoje" — só quando há empresa palpitada.
            'tabela_em_uso'       => $proposta->company !== null ? $this->tabelaEmUso($proposta->company, $resolver) : null,
        ];
    }

    /**
     * A tabela que a empresa palpitada usa HOJE (`FechamentoFaixaResolver`), para o painel de
     * conferência mostrar lado a lado com a lida do contrato. `null` quando o resolver não acha
     * nenhuma tabela aplicável (empresa ainda "a definir") — estado legítimo, nunca R$ 0.
     */
    private function tabelaEmUso(Company $company, FechamentoFaixaResolver $resolver): ?array
    {
        $resolvido = $resolver->paraEmpresa($company);

        if ($resolvido === null) {
            return null;
        }

        return [
            'origem'       => $resolvido['origem'],
            'servico_nome' => $resolvido['servico_nome'],
            'grupo_nome'   => $resolvido['grupo_nome'],
            'faixas'       => $resolvido['faixas']->map(fn ($f) => [
                'ordem'           => $f->ordem,
                'limite_superior' => $f->limite_superior !== null ? (float) $f->limite_superior : null,
                'valor'           => (float) $f->valor,
                'valor_e_piso'    => (bool) $f->valor_e_piso,
            ])->values()->all(),
        ];
    }

    /**
     * Resumo com as quatro contagens do relatório do plano 140-03 — mesma disciplina de
     * independência de `ClicksignExtrairTabelas::contabilizar()`: um contrato pode contar em mais
     * de um bucket (ex.: valor fixo com palpite certo).
     *
     * @return array{total: int, casaram_seguranca: int, duvidosos: int, valor_fixo: int, ilegiveis: int}
     */
    private function resumoContagens(): array
    {
        $propostas = ContratoTabelaProposta::query()->get(['confianca', 'tipo_cobranca']);

        $resumo = [
            'total'             => $propostas->count(),
            'casaram_seguranca' => 0,
            'duvidosos'         => 0,
            'valor_fixo'        => 0,
            'ilegiveis'         => 0,
        ];

        foreach ($propostas as $p) {
            if ($p->tipo_cobranca === ContratoTabelaProposta::TIPO_ILEGIVEL) {
                $resumo['ilegiveis']++;

                continue;
            }

            if ($p->confianca === ContratoTabelaProposta::CONFIANCA_CERTO) {
                $resumo['casaram_seguranca']++;
            } else {
                $resumo['duvidosos']++;
            }

            if ($p->tipo_cobranca === ContratoTabelaProposta::TIPO_VALOR_FIXO) {
                $resumo['valor_fixo']++;
            }
        }

        return $resumo;
    }

    /**
     * @param  array<int, array{ordem: int, limite_superior: ?float, valor: float, valor_e_piso: bool}>  $faixas
     * @return array<int, string>
     */
    private function formatarFaixas(array $faixas): array
    {
        return array_map(function (array $faixa) {
            $limite = $faixa['limite_superior'] !== null
                ? 'até '.$this->fmtBRL((float) $faixa['limite_superior'])
                : 'acima disso';

            $prefixoValor = ($faixa['valor_e_piso'] ?? false) ? 'a partir de ' : '';

            return "{$limite} → {$prefixoValor}".$this->fmtBRL((float) $faixa['valor']);
        }, $faixas);
    }

    private function fmtBRL(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
