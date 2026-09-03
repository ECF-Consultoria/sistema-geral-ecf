<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relatório de Fechamento — {{ $company->name }} — {{ $mes_label }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 12px;
            color: #1a1a2e;
            background: #fff;
            max-width: 800px;
            margin: 0 auto;
        }

        /* ── Header com logo ─────────────────────────────── */
        .header-dark {
            background: #fff;
            padding: 14px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo-pill {
            background: #050507;
            padding: 7px 14px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .logo-pill img { height: 28px; width: auto; display: block; }
        .header-meta {
            text-align: right;
            font-size: 10px;
            color: #888;
            line-height: 1.5;
        }
        .header-meta strong { display: block; font-size: 14px; color: #1a1a2e; font-weight: 700; }

        /* ── Faixa gradiente ECF ─────────────────────────── */
        .ecf-gradient-bar {
            height: 4px;
            background: linear-gradient(90deg, #171392 0%, #FE4D46 55%, #EDBA06 100%);
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .content { padding: 18px 28px 28px; }

        /* ── Status badge ────────────────────────────────── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
        }
        .status-recebido { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .status-pendente  { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }

        /* ── Seções ──────────────────────────────────────── */
        .section { margin-bottom: 16px; }
        .section-title {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #888;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #eee;
        }

        /* ── Grid de campos ─────────────────────────────── */
        .fields-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .field label {
            display: block;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #aaa;
            margin-bottom: 1px;
        }
        .field span { font-size: 12px; color: #1a1a2e; font-weight: 500; }
        .field span.mono { font-family: monospace; font-size: 11px; }
        .field span.empty { color: #ccc; font-style: italic; }

        /* ── Tabela principal ────────────────────────────── */
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th {
            background: #f5f5f5;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #666;
            padding: 6px 8px;
            text-align: left;
            border-bottom: 2px solid #e5e5e5;
        }
        th.right, td.right { text-align: right; }
        td {
            padding: 7px 8px;
            border-bottom: 1px solid #f0f0f0;
            color: #1a1a2e;
            vertical-align: middle;
            font-size: 12px;
        }
        td .cnpj-sub {
            display: block;
            font-size: 9px;
            color: #aaa;
            font-family: monospace;
            margin-top: 1px;
        }
        tr.vinculada td { color: #555; }
        tr.vinculada td:first-child { padding-left: 18px; }
        tr.vinculada td:first-child::before { content: '↳ '; color: #bbb; }
        tr.total td {
            background: #f9f9f9;
            font-weight: 700;
            border-top: 2px solid #d0d0d0;
            border-bottom: none;
            font-size: 12px;
        }
        .valor-destaque { font-size: 14px; font-weight: 800; color: #1a1a2e; }
        .faixa-badge {
            display: inline-block;
            background: #f0f0f0;
            color: #555;
            font-size: 9px;
            padding: 2px 6px;
            border-radius: 8px;
        }
        .sem-dados { color: #ccc; font-style: italic; font-size: 11px; }

        /* ── Tabela de detalhes das vinculadas ───────────── */
        .details-table th {
            font-size: 9px;
            padding: 5px 8px;
            border-bottom: 1px solid #e5e5e5;
            background: #fafafa;
        }
        .details-table td {
            font-size: 11px;
            padding: 5px 8px;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: top;
        }
        .details-table tr:last-child td { border-bottom: none; }
        .details-table td.empresa-nome { font-weight: 600; }
        .details-table td.label-mono { font-family: monospace; font-size: 10px; color: #555; }
        .details-table td.muted { color: #aaa; font-style: italic; }

        /* ── Total box ───────────────────────────────────── */
        .total-box {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-left: 3px solid #1a1a2e;
            border-radius: 6px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }
        .total-box .label { font-size: 11px; color: #888; }
        .total-box .value { font-size: 20px; font-weight: 800; color: #1a1a2e; }

        /* ── Serviço adicional ───────────────────────────── */
        .adicional-box {
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            padding: 10px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .adicional-box .name  { color: #555; font-size: 12px; }
        .adicional-box .price { font-weight: 700; color: #1a1a2e; font-size: 12px; }

        /* ── Footer ──────────────────────────────────────── */
        .footer {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            color: #bbb;
        }

        /* ── Botão imprimir ──────────────────────────────── */
        .print-btn {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #050507;
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        }
        .print-btn:hover { background: #1a1a2e; }

        @media print {
            body { max-width: 100%; }
            .print-btn { display: none; }
            @page { margin: 10mm 12mm; }
        }
    </style>
</head>
<body>

@php
    // Fase 137 (D-02b) — a última faixa de Gestão/Brigada é PISO ("a partir
    // de"), não valor fechado; sem o prefixo o Administrativo cobraria a
    // menos de quem faturou mais que o teto da tabela.
    $fmtPiso = fn ($valor, $piso) => $valor
        ? (($piso ? 'a partir de ' : '') . 'R$ ' . number_format($valor, 0, ',', '.'))
        : '—';
@endphp

<div class="header-dark">
    <div class="logo-pill"><img src="{{ asset('images/logo.png') }}" alt="ECF Consultoria"></div>
    <div class="header-meta">
        <strong>{{ $mes_label }}</strong>
        Gerado em {{ $gerado_em }}
    </div>
</div>
<div class="ecf-gradient-bar"></div>

<div class="content">

@if ($recebido)
    <div class="status-badge status-recebido">✓ Pagamento recebido</div>
@else
    <div class="status-badge status-pendente">⏳ Pagamento pendente</div>
@endif

{{-- ── DADOS DA EMPRESA PRINCIPAL ──────────────────────── --}}
<div class="section">
    <div class="section-title">Dados da empresa</div>
    <div class="fields-grid">
        <div class="field" style="grid-column: span 3">
            <label>Nome</label>
            <span style="font-size:16px; font-weight:800">{{ $titulo ?? $company->name }}</span>
        </div>
        <div class="field">
            <label>CNPJ</label>
            <span class="mono">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $company->cnpj ?? '')) ?: '—' }}</span>
        </div>
        <div class="field">
            <label>Cust ID (Adman)</label>
            <span class="mono">{{ $company->adman_account_id ?? '—' }}</span>
        </div>
        @if ($company->adman_store_id)
        <div class="field"><label>Store ID</label><span class="mono">{{ $company->adman_store_id }}</span></div>
        @endif
        @if ($company->ml_store_id)
        <div class="field"><label>Loja ML</label><span class="mono">{{ $company->ml_store_id }}</span></div>
        @endif
        @if ($company->segment)
        <div class="field"><label>Segmento</label><span>{{ $company->segment }}</span></div>
        @endif
        <div class="field">
            <label>Tipo de serviço</label>
            {{-- Phase 14: labelFromTypes(legacy) → serviceTypeLabel(derivado de contratos) — D-09 --}}
            <span>{{ $company->service_type_label }}</span>
        </div>
        {{-- Phase 14 (Frente B): blocos "Tipo de contrato" e "Vigência" removidos.
             As colunas legacy contract_type / contract_start / contract_end foram
             dropadas no Plan 14-06. Cada contrato individual carrega sua própria
             data_vencimento via contratos_servico. --}}
        @if (!empty($servicos_contratados_pai))
        <div class="field" style="grid-column: span 2">
            <label>Contratos ativos</label>
            <span>{{ $servicos_contratados_pai }}</span>
        </div>
        @endif
    </div>
</div>

{{-- ── FATURAMENTO ──────────────────────────────────────── --}}
<div class="section">
    <div class="section-title">Faturamento e mensalidade{{ $periodo_inicio ? ' — ' . $periodo_inicio . ' a ' . $periodo_fim : '' }}</div>

    @if (count($vinculadas) > 0)
        <table>
            <thead>
                <tr>
                    <th>Empresa</th>
                    <th>Faturamento (mês)</th>
                    <th>Faixa</th>
                    <th class="right">Mensalidade</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>{{ $titulo ?? $company->name }}</strong>
                        @if ($company->cnpj)<span class="cnpj-sub">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $company->cnpj)) }}</span>@endif
                    </td>
                    <td>@if ($faturamento !== null) {{ 'R$ '.number_format($faturamento,0,',','.') }} @else <span class="sem-dados">Sem dados</span> @endif</td>
                    <td>@if ($faixa_label) <span class="faixa-badge">{{ $faixa_label }}</span> @else — @endif</td>
                    <td class="right">
                        {{-- Phase 14 (Frente B): coluna mostra cobrança total (faixa + contratos
                             ativos via CobrancaCalculator::novo). Bloco legacy de
                             additional_service / additional_service_price removido — info
                             já consta na seção "Contratos ativos" acima. --}}
                        {{ $fmtPiso($cobranca_mensal, $valor_e_piso ?? false) }}
                    </td>
                </tr>
                @foreach ($vinculadas as $v)
                <tr class="vinculada">
                    <td>
                        {{ $v['name'] }}
                        @if (!empty($v['cnpj']))<span class="cnpj-sub">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $v['cnpj'])) }}</span>@endif
                    </td>
                    <td>@if ($v['faturamento'] !== null) {{ 'R$ '.number_format($v['faturamento'],0,',','.') }} @else <span class="sem-dados">Sem dados</span> @endif</td>
                    <td>@if ($v['faixa_label']) <span class="faixa-badge">{{ $v['faixa_label'] }}</span> @else — @endif</td>
                    <td class="right">
                        {{-- Phase 14 (Frente B): cobranca_mensal já agrega faixa + contratos da filha. --}}
                        {{ $fmtPiso($v['cobranca_mensal'] ?? null, $v['valor_e_piso'] ?? false) }}
                    </td>
                </tr>
                @endforeach
                <tr class="total">
                    <td colspan="3"><strong>Total do grupo</strong></td>
                    <td class="right"><span class="valor-destaque">{{ 'R$ '.number_format($total_mensalidade,0,',','.') }}</span></td>
                </tr>
            </tbody>
        </table>

    @elseif ($faturamento !== null)
        <table>
            <thead>
                <tr>
                    <th>Faturamento (mês)</th>
                    <th>Faixa de progressão</th>
                    <th class="right">Mensalidade a cobrar</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ 'R$ '.number_format($faturamento,0,',','.') }}</td>
                    <td>@if ($faixa_label) <span class="faixa-badge">{{ $faixa_label }}</span> @else — @endif</td>
                    <td class="right"><span class="valor-destaque">{{ $fmtPiso($cobranca_mensal, $valor_e_piso ?? false) }}</span></td>
                </tr>
            </tbody>
        </table>
        @if ($cobranca_mensal)
        {{-- Phase 14 (Frente B): box simplificado. A decomposição "faixa + adicional"
             foi removida porque os componentes não vivem mais em colunas distintas
             no schema (Plan 14-06). cobranca_mensal já agrega faixa + soma dos
             contratos ativos mensais via CobrancaCalculator::novo. --}}
        <div class="total-box">
            <span class="label">Mensalidade a cobrar</span>
            <span class="value">{{ $fmtPiso($cobranca_mensal, $valor_e_piso ?? false) }}</span>
        </div>
        @endif
    @else
        <p class="sem-dados" style="padding:10px 0">Sem dados de faturamento para este mês.</p>
    @endif
</div>

{{-- ── DETALHES DAS VINCULADAS (tabela compacta) ───────── --}}
@if (count($vinculadas) > 0)
<div class="section">
    <div class="section-title">Detalhes das empresas vinculadas</div>
    {{-- Phase 14 (Frente B): colunas "Contrato" e "Vigência" removidas — eram
         derivadas de contract_type/contract_start/contract_end (colunas dropadas).
         Linha extra de "additional_service" também removida — info já em "Serviço". --}}
    <table class="details-table">
        <thead>
            <tr>
                <th>Empresa</th>
                <th>CNPJ</th>
                <th>Adman ID</th>
                <th>Serviço</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($vinculadas as $v)
            <tr>
                <td class="empresa-nome">{{ $v['name'] }}</td>
                <td class="label-mono">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $v['cnpj'] ?? '')) ?: '—' }}</td>
                <td class="label-mono">{{ $v['adman_account_id'] ?? '—' }}</td>
                {{-- Phase 14: service_type (legacy) → servicos_contratados (string formatada pelo AdminController) --}}
                <td>{{ $v['servicos_contratados'] ?? '—' }}</td>
            </tr>
            {{-- linha extra apenas para Store ID / Loja ML (additional_service legacy removido) --}}
            @if (!empty($v['adman_store_id']) || !empty($v['ml_store_id']))
            <tr style="background:#fafafa">
                <td colspan="4" style="padding:3px 8px 6px 8px; font-size:10px; color:#888; border-bottom:1px solid #f0f0f0;">
                    @if (!empty($v['adman_store_id']))
                        Store ID: <strong>{{ $v['adman_store_id'] }}</strong>&nbsp;&nbsp;
                    @endif
                    @if (!empty($v['ml_store_id']))
                        Loja ML: <strong>{{ $v['ml_store_id'] }}</strong>&nbsp;&nbsp;
                    @endif
                </td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Phase 14 (Frente B): seção legacy "Serviço adicional" removida. As colunas
     additional_service / additional_service_price foram dropadas no Plan 14-06.
     A informação já é exibida em "Contratos ativos" (servicos_contratados_pai)
     e o valor agregado em "Mensalidade a cobrar" (cobranca_mensal). --}}

<div class="footer">
    <span>ECF Consultoria — Documento gerado automaticamente pelo sistema de fechamento</span>
    <span>{{ $gerado_em }}</span>
</div>

</div>{{-- .content --}}

<button class="print-btn" onclick="window.print()">⬇ Salvar como PDF</button>

<script>
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
</script>
</body>
</html>
