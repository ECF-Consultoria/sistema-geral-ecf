<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório de Fechamento — {{ $mes_label }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 12px;
            color: #1a1a2e;
            background: #fff;
        }

        /* ── Header ──────────────────────────────────────────── */
        .header-dark {
            background: #fff;
            padding: 14px 28px;
        }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { padding: 0; vertical-align: middle; border: none; }
        .logo-pill {
            background: #050507;
            padding: 7px 14px;
            border-radius: 6px;
            display: inline-block;
            color: #ffe600;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 1px;
        }
        .header-meta {
            text-align: right;
            font-size: 10px;
            color: #888;
            line-height: 1.6;
        }
        .header-meta strong { display: block; font-size: 14px; color: #1a1a2e; font-weight: 700; }

        /* ── Faixa gradiente ─────────────────────────────────── */
        .ecf-gradient-bar {
            height: 4px;
            background: #171392;
            margin-bottom: 0;
        }

        /* ── Capa ────────────────────────────────────────────── */
        .cover-content { padding: 20px 28px 32px; }
        .cover-title { font-size: 22px; font-weight: 800; color: #1a1a2e; margin-bottom: 4px; }
        .cover-sub { font-size: 13px; color: #888; margin-bottom: 20px; }

        .cover-stats-table { border-collapse: separate; border-spacing: 8px 0; margin-bottom: 20px; }
        .cover-stat-cell {
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-top: 3px solid #1a1a2e;
            border-radius: 6px;
            padding: 10px 16px;
            vertical-align: top;
            min-width: 100px;
        }
        .cover-stat-cell.green { border-top-color: #065f46; }
        .cover-stat-cell.amber { border-top-color: #92400e; }
        .cover-stat-cell .num { font-size: 20px; font-weight: 800; color: #1a1a2e; }
        .cover-stat-cell.green .num { color: #065f46; }
        .cover-stat-cell.amber .num { color: #92400e; }
        .cover-stat-cell .lbl { font-size: 9px; color: #888; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 2px; }

        /* ── Seção ───────────────────────────────────────────── */
        .section-title {
            font-size: 9px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1.5px; color: #888; margin-bottom: 8px;
            padding-bottom: 4px; border-bottom: 1px solid #eee;
        }

        /* ── Índice ──────────────────────────────────────────── */
        .indice-table { width: 100%; border-collapse: collapse; }
        .indice-table td {
            font-size: 11px; color: #555;
            padding: 4px 8px 4px 0;
            border-bottom: 1px solid #f5f5f5;
            vertical-align: middle;
            width: 50%;
        }
        .badge-rec { font-size: 9px; font-weight: 700; color: #065f46; background: #d1fae5; border: 1px solid #6ee7b7; padding: 1px 6px; border-radius: 8px; }
        .badge-pen { font-size: 9px; font-weight: 700; color: #92400e; background: #fef3c7; border: 1px solid #fcd34d; padding: 1px 6px; border-radius: 8px; }

        /* ── Bloco por empresa ───────────────────────────────── */
        .company-block { page-break-before: always; }
        .company-content { padding: 18px 28px 28px; }

        /* ── Status badge ────────────────────────────────────── */
        .status-badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            margin-bottom: 14px;
        }
        .status-recebido { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .status-pendente  { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }

        /* ── Dados da empresa (grid 3 cols → table) ──────────── */
        .fields-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .fields-table td { padding: 4px 8px 4px 0; vertical-align: top; border: none; }
        .fields-table td label {
            display: block; font-size: 9px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.8px; color: #aaa; margin-bottom: 1px;
        }
        .fields-table td span { font-size: 12px; color: #1a1a2e; font-weight: 500; }
        .fields-table td span.mono { font-family: monospace; font-size: 11px; }
        .fields-table td span.empty { color: #ccc; font-style: italic; }

        /* ── Tabela principal de faturamento ─────────────────── */
        .section { margin-bottom: 14px; }
        table.data-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.data-table th {
            background: #f5f5f5; font-size: 9px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.8px; color: #666;
            padding: 6px 8px; text-align: left; border-bottom: 2px solid #e5e5e5;
        }
        table.data-table th.right, table.data-table td.right { text-align: right; }
        table.data-table td {
            padding: 7px 8px; border-bottom: 1px solid #f0f0f0;
            color: #1a1a2e; vertical-align: middle; font-size: 12px;
        }
        table.data-table td .cnpj-sub {
            display: block; font-size: 9px; color: #aaa;
            font-family: monospace; margin-top: 1px;
        }
        table.data-table tr.vinculada td { color: #555; }
        table.data-table tr.vinculada td:first-child { padding-left: 18px; }
        table.data-table tr.total td {
            background: #f9f9f9; font-weight: 700;
            border-top: 2px solid #d0d0d0; border-bottom: none; font-size: 12px;
        }
        .valor-destaque { font-size: 14px; font-weight: 800; color: #1a1a2e; }
        .faixa-badge { display: inline-block; background: #f0f0f0; color: #555; font-size: 9px; padding: 2px 6px; border-radius: 8px; }
        .sem-dados { color: #ccc; font-style: italic; font-size: 11px; }

        /* ── Detalhes das vinculadas ─────────────────────────── */
        table.details-table th { font-size: 9px; padding: 5px 8px; background: #fafafa; border-bottom: 1px solid #e5e5e5; }
        table.details-table td { font-size: 11px; padding: 5px 8px; border-bottom: 1px solid #f5f5f5; vertical-align: top; }
        table.details-table tr:last-child td { border-bottom: none; }
        table.details-table td.empresa-nome { font-weight: 600; }
        table.details-table td.label-mono { font-family: monospace; font-size: 10px; color: #555; }

        /* ── Total box (flex → table) ────────────────────────── */
        .total-box {
            background: #f9f9f9; border: 1px solid #e0e0e0;
            border-left: 3px solid #1a1a2e; border-radius: 6px; margin-top: 10px;
        }
        .total-box-table { width: 100%; border-collapse: collapse; }
        .total-box-table td { padding: 12px 16px; border: none; vertical-align: middle; }
        .total-box .label { font-size: 11px; color: #888; }
        .total-box .value { font-size: 20px; font-weight: 800; color: #1a1a2e; }

        /* ── Serviço adicional ───────────────────────────────── */
        .adicional-box {
            background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 6px;
        }
        .adicional-table { width: 100%; border-collapse: collapse; }
        .adicional-table td { padding: 10px 14px; border: none; vertical-align: middle; }
        .adicional-box .name  { color: #555; font-size: 12px; }
        .adicional-box .price { font-weight: 700; color: #1a1a2e; font-size: 12px; }

        /* ── Footer da empresa ───────────────────────────────── */
        .footer-table { width: 100%; border-collapse: collapse; margin-top: 20px; border-top: 1px solid #eee; }
        .footer-table td { padding: 8px 0 0; font-size: 9px; color: #bbb; border: none; }
    </style>
</head>
<body>

@if (count($relatorios) === 0)
    <div class="header-dark">
        <table class="header-table"><tr>
            <td><div class="logo-pill">ECF</div></td>
            <td><div class="header-meta"><strong>{{ $mes_label }}</strong></div></td>
        </tr></table>
    </div>
    <div class="ecf-gradient-bar"></div>
    <div style="padding:50px 28px; text-align:center; color:#888;">
        <p style="font-size:16px; font-weight:700; color:#1a1a2e; margin-bottom:8px;">Nenhuma empresa encontrada</p>
        <p>Não há empresas para o filtro aplicado em <strong>{{ $mes_label }}</strong>.</p>
    </div>
@else

{{-- ══ CAPA ══════════════════════════════════════════════════ --}}
<div class="header-dark">
    <table class="header-table"><tr>
        <td><div class="logo-pill">ECF</div></td>
        <td><div class="header-meta"><strong>{{ $mes_label }}</strong>Gerado em {{ $gerado_em }}</div></td>
    </tr></table>
</div>
<div class="ecf-gradient-bar"></div>

<div class="cover-content">
    @php
        $totalRecebidos    = collect($relatorios)->where('recebido', true)->count();
        $totalPendentes    = collect($relatorios)->where('recebido', false)->count();
        $totalMensalidades = collect($relatorios)->sum('total_mensalidade');
    @endphp

    <h2 class="cover-title">
        @if ($filtro_recebido === 'sim') Empresas Recebidas
        @elseif ($filtro_recebido === 'nao') Empresas Pendentes
        @else Fechamento — Todas as Empresas
        @endif
    </h2>
    <p class="cover-sub">{{ $mes_label }} · {{ count($relatorios) }} empresa{{ count($relatorios) !== 1 ? 's' : '' }}</p>

    {{-- Stats da capa (flex → tabela) --}}
    <table class="cover-stats-table">
        <tr>
            <td class="cover-stat-cell">
                <div class="num">{{ count($relatorios) }}</div>
                <div class="lbl">Neste relatório</div>
            </td>
            @if (!$filtro_recebido)
            <td class="cover-stat-cell green">
                <div class="num">{{ $totalRecebidos }}</div>
                <div class="lbl">Recebidas</div>
            </td>
            <td class="cover-stat-cell amber">
                <div class="num">{{ $totalPendentes }}</div>
                <div class="lbl">Pendentes</div>
            </td>
            @endif
            <td class="cover-stat-cell">
                <div class="num">R$ {{ number_format($totalMensalidades, 0, ',', '.') }}</div>
                <div class="lbl">Total mensalidades</div>
            </td>
        </tr>
    </table>

    {{-- Índice de empresas (grid 2 cols → tabela) --}}
    <div class="section-title" style="margin-bottom:10px;">Empresas incluídas</div>
    @php $chunks = array_chunk($relatorios, 2); @endphp
    <table class="indice-table">
        @foreach ($chunks as $pair)
        <tr>
            @foreach ($pair as $r)
            <td>
                {{ is_object($r['company']) ? $r['company']->name : ($r['company']['name'] ?? '') }}
                &nbsp;
                @if ($r['recebido'])
                    <span class="badge-rec">✓ Recebido</span>
                @else
                    <span class="badge-pen">⏳ Pendente</span>
                @endif
            </td>
            @endforeach
            @if (count($pair) === 1)<td></td>@endif
        </tr>
        @endforeach
    </table>
</div>

{{-- ══ EMPRESA POR EMPRESA ════════════════════════════════════ --}}
@foreach ($relatorios as $r)
@php $company = is_object($r['company']) ? $r['company'] : (object) $r['company']; @endphp

<div class="company-block">
    <div class="header-dark">
        <table class="header-table"><tr>
            <td><div class="logo-pill">ECF</div></td>
            <td><div class="header-meta"><strong>{{ $mes_label }}</strong>Gerado em {{ $gerado_em }}</div></td>
        </tr></table>
    </div>
    <div class="ecf-gradient-bar"></div>

    <div class="company-content">

        @if ($r['recebido'])
            <div class="status-badge status-recebido">✓ Pagamento recebido</div>
        @else
            <div class="status-badge status-pendente">⏳ Pagamento pendente</div>
        @endif

        {{-- Dados da empresa (grid 3 cols → tabela) --}}
        <div class="section">
            <div class="section-title">Dados da empresa</div>
            <table class="fields-table">
                <tr>
                    <td colspan="3">
                        <label>Nome</label>
                        <span style="font-size:16px; font-weight:800">{{ $company->name }}</span>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label>CNPJ</label>
                        <span class="mono">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $company->cnpj ?? '')) ?: '—' }}</span>
                    </td>
                    <td>
                        <label>ID Loja ML</label>
                        <span class="mono">{{ $company->ml_store_id ?: ($company->adman_account_id ?? '—') }}</span>
                    </td>
                    <td>
                        @if (!empty($company->adman_store_id))
                            <label>Store ID</label>
                            <span class="mono">{{ $company->adman_store_id }}</span>
                        @elseif (!empty($company->ml_store_id))
                            <label>Loja ML</label>
                            <span class="mono">{{ $company->ml_store_id }}</span>
                        @elseif (!empty($company->segment))
                            <label>Segmento</label>
                            <span>{{ $company->segment }}</span>
                        @else
                            &nbsp;
                        @endif
                    </td>
                </tr>
                <tr>
                    <td colspan="3">
                        <label>Tipo de serviço</label>
                        {{-- Phase 14: labelFromTypes(legacy) → serviceTypeLabel(derivado de contratos) — D-09 --}}
                        <span>{{ $company->service_type_label }}</span>
                    </td>
                </tr>
                {{-- Phase 14 (Frente B): linha legacy "Tipo de contrato" / "Vigência" removida.
                     As colunas contract_type / contract_start / contract_end foram dropadas
                     no Plan 14-06. Cada contrato individual carrega data_vencimento via
                     contratos_servico. --}}
            </table>
        </div>

        {{-- Faturamento e mensalidade --}}
        <div class="section">
            <div class="section-title">Faturamento e mensalidade{{ $r['periodo_inicio'] ? ' — ' . $r['periodo_inicio'] . ' a ' . $r['periodo_fim'] : '' }}</div>

            @if (count($r['vinculadas']) > 0)
                <table class="data-table">
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
                                <strong>{{ $company->name }}</strong>
                                @if ($company->cnpj)<span class="cnpj-sub">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $company->cnpj)) }}</span>@endif
                            </td>
                            <td>@if ($r['faturamento'] !== null) {{ 'R$ '.number_format($r['faturamento'],0,',','.') }} @else <span class="sem-dados">Sem dados</span> @endif</td>
                            <td>@if ($r['faixa_label']) <span class="faixa-badge">{{ $r['faixa_label'] }}</span> @else — @endif</td>
                            <td class="right">{{ $r['valor_mensal'] ? 'R$ '.number_format($r['valor_mensal'],0,',','.') : '—' }}</td>
                        </tr>
                        @foreach ($r['vinculadas'] as $v)
                        <tr class="vinculada">
                            <td>
                                {{ $v['name'] }}
                                @if (!empty($v['cnpj']))<span class="cnpj-sub">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $v['cnpj'])) }}</span>@endif
                            </td>
                            <td>@if ($v['faturamento'] !== null) {{ 'R$ '.number_format($v['faturamento'],0,',','.') }} @else <span class="sem-dados">Sem dados</span> @endif</td>
                            <td>@if ($v['faixa_label']) <span class="faixa-badge">{{ $v['faixa_label'] }}</span> @else — @endif</td>
                            <td class="right">{{ $v['valor_mensal'] ? 'R$ '.number_format($v['valor_mensal'],0,',','.') : '—' }}</td>
                        </tr>
                        @endforeach
                        <tr class="total">
                            <td colspan="3"><strong>Total do grupo</strong></td>
                            <td class="right"><span class="valor-destaque">{{ 'R$ '.number_format($r['total_mensalidade'],0,',','.') }}</span></td>
                        </tr>
                    </tbody>
                </table>

            @elseif ($r['faturamento'] !== null)
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Faturamento (mês)</th>
                            <th>Faixa de progressão</th>
                            <th class="right">Mensalidade a cobrar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>{{ 'R$ '.number_format($r['faturamento'],0,',','.') }}</td>
                            <td>@if ($r['faixa_label']) <span class="faixa-badge">{{ $r['faixa_label'] }}</span> @else — @endif</td>
                            <td class="right"><span class="valor-destaque">{{ $r['valor_mensal'] ? 'R$ '.number_format($r['valor_mensal'],0,',','.') : '—' }}</span></td>
                        </tr>
                    </tbody>
                </table>
                @if ($r['valor_mensal'])
                <div class="total-box">
                    <table class="total-box-table"><tr>
                        <td><span class="label">Mensalidade a cobrar</span></td>
                        <td style="text-align:right"><span class="value">{{ 'R$ '.number_format($r['valor_mensal'],0,',','.') }}</span></td>
                    </tr></table>
                </div>
                @endif
            @else
                <p class="sem-dados" style="padding:10px 0">Sem dados de faturamento para este mês.</p>
            @endif
        </div>

        {{-- Detalhes compactos das vinculadas --}}
        @if (count($r['vinculadas']) > 0)
        <div class="section">
            <div class="section-title">Detalhes das empresas vinculadas</div>
            {{-- Phase 14 (Frente B): colunas "Contrato" e "Vigência" removidas — eram
                 derivadas de contract_type/contract_start/contract_end (colunas dropadas).
                 Linha extra de "additional_service" também removida — info já em "Serviço". --}}
            <table class="data-table details-table">
                <thead>
                    <tr>
                        <th>Empresa</th>
                        <th>CNPJ</th>
                        <th>Adman ID</th>
                        <th>Serviço</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($r['vinculadas'] as $v)
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
                            @if (!empty($v['adman_store_id']))Store ID: <strong>{{ $v['adman_store_id'] }}</strong>&nbsp;&nbsp;@endif
                            @if (!empty($v['ml_store_id']))Loja ML: <strong>{{ $v['ml_store_id'] }}</strong>&nbsp;&nbsp;@endif
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Phase 14 (Frente B): seção legacy "Serviço adicional" removida.
             As colunas additional_service / additional_service_price foram dropadas
             no Plan 14-06. A informação já é exibida em "Tipo de serviço" (label
             derivado dos contratos). --}}

        {{-- Rodapé por empresa --}}
        <table class="footer-table"><tr>
            <td>ECF Consultoria — Documento gerado automaticamente pelo sistema de fechamento</td>
            <td style="text-align:right">{{ $gerado_em }}</td>
        </tr></table>

    </div>
</div>
@endforeach

@endif
</body>
</html>
