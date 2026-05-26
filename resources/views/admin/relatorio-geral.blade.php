<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Relatório de Fechamento — {{ $mes_label }}
        @if ($filtro_recebido === 'sim') — Recebidos @elseif ($filtro_recebido === 'nao') — Pendentes @endif
    </title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 12px;
            color: #1a1a2e;
            background: #fff;
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

        /* ── Capa ────────────────────────────────────────── */
        .cover-content {
            padding: 20px 28px 32px;
            page-break-after: always;
        }
        .cover-title { font-size: 22px; font-weight: 800; color: #1a1a2e; margin-bottom: 4px; }
        .cover-sub { font-size: 13px; color: #888; margin-bottom: 20px; }
        .cover-stats { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 24px; }
        .cover-stat {
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-top: 3px solid #1a1a2e;
            border-radius: 6px;
            padding: 10px 16px;
            min-width: 120px;
        }
        .cover-stat .num { font-size: 22px; font-weight: 800; color: #1a1a2e; }
        .cover-stat .lbl { font-size: 9px; color: #888; text-transform: uppercase; letter-spacing: 0.8px; margin-top: 2px; }
        .cover-stat.green .num { color: #065f46; }
        .cover-stat.amber .num { color: #92400e; }

        /* ── Índice ──────────────────────────────────────── */
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
        .indice-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 2px 24px; }
        .indice-row {
            font-size: 11px;
            color: #555;
            padding: 4px 0;
            border-bottom: 1px solid #f5f5f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge-rec { font-size: 9px; font-weight: 700; color: #065f46; background: #d1fae5; border: 1px solid #6ee7b7; padding: 1px 6px; border-radius: 8px; }
        .badge-pen { font-size: 9px; font-weight: 700; color: #92400e; background: #fef3c7; border: 1px solid #fcd34d; padding: 1px 6px; border-radius: 8px; }

        /* ── Bloco por empresa ───────────────────────────── */
        .company-block {
            page-break-before: always;
            page-break-inside: avoid;
        }
        .company-content { padding: 18px 28px 28px; }

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
        .section { margin-bottom: 14px; }

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
        .faixa-badge { display: inline-block; background: #f0f0f0; color: #555; font-size: 9px; padding: 2px 6px; border-radius: 8px; }
        .sem-dados { color: #ccc; font-style: italic; font-size: 11px; }

        /* ── Tabela compacta de detalhes das vinculadas ──── */
        .details-table th { font-size: 9px; padding: 5px 8px; background: #fafafa; border-bottom: 1px solid #e5e5e5; }
        .details-table td { font-size: 11px; padding: 5px 8px; border-bottom: 1px solid #f5f5f5; vertical-align: top; }
        .details-table tr:last-child td { border-bottom: none; }
        .details-table td.empresa-nome { font-weight: 600; }
        .details-table td.label-mono { font-family: monospace; font-size: 10px; color: #555; }

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
            margin-top: 20px;
            padding-top: 8px;
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
            z-index: 100;
        }
        .print-btn:hover { background: #1a1a2e; }

        @media print {
            .print-btn { display: none; }
            @page { margin: 10mm 12mm; }
        }
    </style>
</head>
<body>

@if (count($relatorios) === 0)
    <div class="header-dark">
        <div class="logo-pill"><img src="{{ asset('images/logo.png') }}" alt="ECF Consultoria"></div>
        <div class="header-meta"><strong>{{ $mes_label }}</strong></div>
    </div>
    <div class="ecf-gradient-bar"></div>
    <div style="padding:50px 28px; text-align:center; color:#888;">
        <p style="font-size:16px; font-weight:700; color:#1a1a2e; margin-bottom:8px;">Nenhuma empresa encontrada</p>
        <p>Não há empresas para o filtro aplicado em <strong>{{ $mes_label }}</strong>.</p>
    </div>
@else

{{-- ══ CAPA ═════════════════════════════════════════════ --}}
<div class="header-dark">
    <div class="logo-pill"><img src="{{ asset('images/logo.png') }}" alt="ECF Consultoria"></div>
    <div class="header-meta">
        <strong>{{ $mes_label }}</strong>
        Gerado em {{ $gerado_em }}
    </div>
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

    <div class="cover-stats">
        <div class="cover-stat">
            <div class="num">{{ count($relatorios) }}</div>
            <div class="lbl">Neste relatório</div>
        </div>
        @if (!$filtro_recebido)
        <div class="cover-stat green">
            <div class="num">{{ $totalRecebidos }}</div>
            <div class="lbl">Recebidas</div>
        </div>
        <div class="cover-stat amber">
            <div class="num">{{ $totalPendentes }}</div>
            <div class="lbl">Pendentes</div>
        </div>
        @endif
        <div class="cover-stat">
            <div class="num">R$ {{ number_format($totalMensalidades, 0, ',', '.') }}</div>
            <div class="lbl">Total mensalidades</div>
        </div>
    </div>

    <div class="section-title" style="margin-bottom:10px;">Empresas incluídas</div>
    <div class="indice-grid">
        @foreach ($relatorios as $r)
        <div class="indice-row">
            <span>{{ $r['company']->name }}</span>
            @if ($r['recebido'])
                <span class="badge-rec">✓ Recebido</span>
            @else
                <span class="badge-pen">⏳ Pendente</span>
            @endif
        </div>
        @endforeach
    </div>
</div>

{{-- ══ EMPRESA POR EMPRESA ══════════════════════════════ --}}
@foreach ($relatorios as $r)
@php $company = $r['company']; @endphp

<div class="company-block">
    <div class="header-dark">
        <div class="logo-pill"><img src="{{ asset('images/logo.png') }}" alt="ECF Consultoria"></div>
        <div class="header-meta">
            <strong>{{ $mes_label }}</strong>
            Gerado em {{ $gerado_em }}
        </div>
    </div>
    <div class="ecf-gradient-bar"></div>

    <div class="company-content">

        @if ($r['recebido'])
            <div class="status-badge status-recebido">✓ Pagamento recebido</div>
        @else
            <div class="status-badge status-pendente">⏳ Pagamento pendente</div>
        @endif

        {{-- Dados da empresa --}}
        <div class="section">
            <div class="section-title">Dados da empresa</div>
            <div class="fields-grid">
                <div class="field" style="grid-column:span 3">
                    <label>Nome</label>
                    <span style="font-size:16px; font-weight:800">{{ $company->name }}</span>
                </div>
                <div class="field">
                    <label>CNPJ</label>
                    <span class="mono">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $company->cnpj ?? '')) ?: '—' }}</span>
                </div>
                <div class="field">
                    <label>ID Loja ML</label>
                    <span class="mono">{{ $company->ml_store_id ?: ($company->adman_account_id ?? '—') }}</span>
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
                    <span>{{ $company->serviceTypeLabel() }}</span>
                </div>
                <div class="field">
                    <label>Tipo de contrato</label>
                    <span>{{ match($company->contract_type ?? '') { 'fixo' => 'Fixo', 'progressao' => 'Escala de Progressão', default => '—' } }}</span>
                </div>
                <div class="field">
                    <label>Vigência</label>
                    <span>{{ $company->contract_start ? $company->contract_start->format('d/m/Y') . ($company->contract_end ? ' – ' . $company->contract_end->format('d/m/Y') : '') : '—' }}</span>
                </div>
            </div>
        </div>

        {{-- Faturamento --}}
        <div class="section">
            <div class="section-title">Faturamento e mensalidade{{ $r['periodo_inicio'] ? ' — ' . $r['periodo_inicio'] . ' a ' . $r['periodo_fim'] : '' }}</div>

            @if (count($r['vinculadas']) > 0)
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
                                <strong>{{ $company->name }}</strong>
                                @if ($company->cnpj)<span class="cnpj-sub">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $company->cnpj)) }}</span>@endif
                            </td>
                            <td>@if ($r['faturamento'] !== null) {{ 'R$ '.number_format($r['faturamento'],0,',','.') }} @else <span class="sem-dados">Sem dados</span> @endif</td>
                            <td>@if ($r['faixa_label']) <span class="faixa-badge">{{ $r['faixa_label'] }}</span> @else — @endif</td>
                            <td class="right">
                                @if ($r['valor_mensal'])R$ {{ number_format($r['valor_mensal'],0,',','.') }}@else —@endif
                                @if ($company->additional_service_price)
                                    <br><span style="font-size:10px;color:#888">+ R$ {{ number_format($company->additional_service_price,0,',','.') }} ({{ $company->additional_service ?: 'adicional' }})</span>
                                @endif
                            </td>
                        </tr>
                        @foreach ($r['vinculadas'] as $v)
                        <tr class="vinculada">
                            <td>
                                {{ $v['name'] }}
                                @if (!empty($v['cnpj']))<span class="cnpj-sub">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $v['cnpj'])) }}</span>@endif
                            </td>
                            <td>@if ($v['faturamento'] !== null) {{ 'R$ '.number_format($v['faturamento'],0,',','.') }} @else <span class="sem-dados">Sem dados</span> @endif</td>
                            <td>@if ($v['faixa_label']) <span class="faixa-badge">{{ $v['faixa_label'] }}</span> @else — @endif</td>
                            <td class="right">
                                @if ($v['valor_mensal'])R$ {{ number_format($v['valor_mensal'],0,',','.') }}@else —@endif
                                @if (!empty($v['additional_service_price']))
                                    <br><span style="font-size:10px;color:#888">+ R$ {{ number_format($v['additional_service_price'],0,',','.') }} ({{ $v['additional_service'] ?: 'adicional' }})</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                        <tr class="total">
                            <td colspan="3"><strong>Total do grupo</strong></td>
                            <td class="right"><span class="valor-destaque">{{ 'R$ '.number_format($r['total_mensalidade'],0,',','.') }}</span></td>
                        </tr>
                    </tbody>
                </table>

            @elseif ($r['faturamento'] !== null)
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
                            <td>{{ 'R$ '.number_format($r['faturamento'],0,',','.') }}</td>
                            <td>@if ($r['faixa_label']) <span class="faixa-badge">{{ $r['faixa_label'] }}</span> @else — @endif</td>
                            <td class="right"><span class="valor-destaque">{{ $r['cobranca_mensal'] ? 'R$ '.number_format($r['cobranca_mensal'],0,',','.') : '—' }}</span></td>
                        </tr>
                    </tbody>
                </table>
                @if ($r['cobranca_mensal'])
                <div class="total-box">
                    @if ($company->additional_service_price && $r['valor_mensal'])
                        <div style="display:flex;flex-direction:column;gap:4px;width:100%">
                            <div style="display:flex;justify-content:space-between;font-size:12px;color:#555">
                                <span>{{ match($company->contract_type ?? '') { 'progressao' => 'Mensalidade (Progressão)', default => 'Mensalidade (Fixo)' } }}</span>
                                <span>R$ {{ number_format($r['valor_mensal'],0,',','.') }}</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:12px;color:#555">
                                <span>{{ $company->additional_service ?: 'Serviço adicional' }}</span>
                                <span>R$ {{ number_format($company->additional_service_price,0,',','.') }}</span>
                            </div>
                            <div style="border-top:1px solid #ddd;margin-top:4px;padding-top:6px;display:flex;justify-content:space-between;align-items:center">
                                <span class="label">Total a cobrar</span>
                                <span class="value">R$ {{ number_format($r['cobranca_mensal'],0,',','.') }}</span>
                            </div>
                        </div>
                    @else
                        <span class="label">Mensalidade a cobrar</span>
                        <span class="value">R$ {{ number_format($r['cobranca_mensal'],0,',','.') }}</span>
                    @endif
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
            <table class="details-table">
                <thead>
                    <tr>
                        <th>Empresa</th>
                        <th>CNPJ</th>
                        <th>Adman ID</th>
                        <th>Serviço</th>
                        <th>Contrato</th>
                        <th>Vigência</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($r['vinculadas'] as $v)
                    <tr>
                        <td class="empresa-nome">{{ $v['name'] }}</td>
                        <td class="label-mono">{{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $v['cnpj'] ?? '')) ?: '—' }}</td>
                        <td class="label-mono">{{ $v['adman_account_id'] ?? '—' }}</td>{{-- adman_account_id já é ml_store_id ?: adman_account_id via AdminController --}}
                        {{-- Phase 14: service_type (legacy) → servicos_contratados (string formatada pelo AdminController) --}}
                        <td>{{ $v['servicos_contratados'] ?? '—' }}</td>
                        <td>{{ match($v['contract_type'] ?? '') { 'fixo' => 'Fixo', 'progressao' => 'Progressão', default => '—' } }}</td>
                        <td class="label-mono">{{ !empty($v['contract_start']) ? $v['contract_start'] . (!empty($v['contract_end']) ? ' – ' . $v['contract_end'] : '') : '—' }}</td>
                    </tr>
                    @if (!empty($v['adman_store_id']) || !empty($v['ml_store_id']) || !empty($v['additional_service']))
                    <tr style="background:#fafafa">
                        <td colspan="6" style="padding:3px 8px 6px 8px; font-size:10px; color:#888; border-bottom:1px solid #f0f0f0;">
                            @if (!empty($v['adman_store_id']))
                                Store ID: <strong>{{ $v['adman_store_id'] }}</strong>&nbsp;&nbsp;
                            @endif
                            @if (!empty($v['ml_store_id']))
                                Loja ML: <strong>{{ $v['ml_store_id'] }}</strong>&nbsp;&nbsp;
                            @endif
                            @if (!empty($v['additional_service']))
                                Serviço adicional: <strong>{{ $v['additional_service'] . (!empty($v['additional_service_price']) ? ' — R$ ' . number_format($v['additional_service_price'],0,',','.') . '/mês' : '') }}</strong>
                            @endif
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Serviço adicional da empresa principal --}}
        @if ($company->additional_service)
        <div class="section">
            <div class="section-title">Serviço adicional</div>
            <div class="adicional-box">
                <span class="name">{{ $company->additional_service }}</span>
                @if ($company->additional_service_price)
                    <span class="price">R$ {{ number_format($company->additional_service_price,0,',','.') }}/mês</span>
                @endif
            </div>
        </div>
        @endif

        <div class="footer">
            <span>ECF Consultoria — Documento gerado automaticamente pelo sistema de fechamento</span>
            <span>{{ $gerado_em }}</span>
        </div>

    </div>{{-- .company-content --}}
</div>{{-- .company-block --}}
@endforeach

@endif

<button class="print-btn" onclick="window.print()">⬇ Salvar como PDF</button>

<script>
    window.addEventListener('load', () => setTimeout(() => window.print(), 600));
</script>
</body>
</html>
