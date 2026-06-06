<!DOCTYPE html>
<html lang="pt-BR">
<head>
    {{-- UTF-8 obrigatório para acentuação pt-BR no Dompdf (Pitfall 3 CONTEXT) --}}
    <meta charset="UTF-8">
    <title>{{ $mesLabel ?? 'Relatório Mensal Executivo' }}</title>
    <style>
        /* CSS inline — Dompdf NÃO interpreta classes Tailwind (Pitfall 1 CONTEXT) */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        /* DejaVu Sans: font padrão Dompdf com suporte UTF-8 completo */
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #050507;
            background: #ffffff;
        }

        .container { width: 100%; padding: 20px; }

        /* ─── Cabeçalho ─── */
        .header { border-bottom: 3px solid #ffe600; padding-bottom: 12px; margin-bottom: 20px; }
        .header-logo { height: 40px; }
        .header h1 { color: #050507; font-size: 18px; font-weight: bold; margin-top: 8px; }
        .header .meta { color: #666; font-size: 10px; margin-top: 4px; }

        /* ─── Seções ─── */
        .section { margin-bottom: 24px; page-break-inside: avoid; }
        .section h2 {
            color: #050507;
            font-size: 13px;
            font-weight: bold;
            border-bottom: 1px solid #ffe600;
            padding-bottom: 4px;
            margin-bottom: 10px;
        }

        /* ─── Tabelas ─── */
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        th { background: #0f1116; color: #ffe600; text-align: left; padding: 6px 8px; font-weight: bold; }
        td { padding: 6px 8px; border-bottom: 1px solid #eaeaea; }
        tr:nth-child(even) td { background: #fafafa; }

        /* ─── KPI Grid (8 cards em 2x4) ─── */
        .kpi-grid { width: 100%; }
        .kpi-grid td { padding: 8px; border: 1px solid #eaeaea; vertical-align: top; width: 25%; }
        .kpi-label { color: #666; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
        .kpi-value { color: #050507; font-size: 13px; font-weight: bold; margin-top: 4px; }
        .kpi-delta { font-size: 9px; margin-top: 2px; }

        /* ─── Deltas MoM coloridos ─── */
        .delta-up   { color: #16a34a; }
        .delta-down { color: #dc2626; }

        /* ─── Badge crítico ─── */
        .badge-critical {
            background: #dc2626;
            color: #fff;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
        }

        /* ─── Footer ─── */
        .footer {
            border-top: 1px solid #eaeaea;
            padding-top: 8px;
            margin-top: 24px;
            color: #999;
            font-size: 9px;
            text-align: center;
        }
    </style>
</head>
<body>

@php
    /* ─── Helpers locais de formatação pt-BR ─── */

    // Formato moeda completo: R$ 42.859.191,37
    $fmtBRL = fn ($v) => 'R$ ' . number_format((float) ($v ?? 0), 2, ',', '.');

    // Formato moeda abreviado: R$ 42,86 M / R$ 1,5 k
    $fmtBRLShort = function ($v) {
        $v = (float) ($v ?? 0);
        if (abs($v) >= 1_000_000) return 'R$ ' . number_format($v / 1_000_000, 2, ',', '.') . ' M';
        if (abs($v) >= 1_000)     return 'R$ ' . number_format($v / 1_000, 1, ',', '.') . ' k';
        return 'R$ ' . number_format($v, 2, ',', '.');
    };

    // Inteiro formatado: 357.531
    $fmtInt = fn ($v) => number_format((int) ($v ?? 0), 0, ',', '.');

    // Percentual: 11,74%
    $fmtPct = fn ($v) => number_format((float) ($v ?? 0), 2, ',', '.') . '%';

    // Delta MoM com sinal e cor verde/vermelho
    $fmtDelta = function ($delta) use ($fmtPct) {
        if ($delta === null) return '—';
        $sinal  = $delta > 0 ? '+' : '';
        $classe = $delta > 0 ? 'delta-up' : ($delta < 0 ? 'delta-down' : '');
        return '<span class="' . $classe . '">' . $sinal . $fmtPct($delta) . '</span>';
    };

    /* ─── Extraindo subnós do payload da API (defensivos ?? []) ─── */
    $resumo       = $dados['resumo']       ?? [];
    $distribuicao = $dados['distribuicao'] ?? [];
    $rankings     = $dados['rankings']     ?? [];
    $signals      = $dados['signals']      ?? [];
@endphp

<div class="container">

    {{-- ═══ CABEÇALHO — sempre renderiza ═══ --}}
    <div class="header">
        @if ($logoBase64)
            {{-- Logo via base64 inline — Dompdf não busca URLs externas --}}
            <img src="{{ $logoBase64 }}" alt="ECF Consultoria" class="header-logo">
        @else
            {{-- Fallback texto se arquivo de logo ausente no ambiente --}}
            <div style="font-size: 18px; font-weight: bold; color: #ffe600; background: #050507; padding: 6px 12px; display: inline-block;">ECF Consultoria</div>
        @endif
        <h1>Relatório Executivo Mensal — {{ $mesLabel ?? $periodo }}</h1>
        <div class="meta">Gerado em {{ $geradoEm }} · Fonte: ECF Drive (parceiro oficial)</div>
    </div>

    {{-- ═══ SEÇÃO 1 — Resumo executivo (8 KPIs) ═══ --}}
    @if (!empty($resumo))
        <div class="section">
            <h2>Resumo executivo</h2>
            <table class="kpi-grid">
                {{-- Linha 1: Faturamento, Vendas, Lojistas, Investimento Ads --}}
                <tr>
                    <td>
                        <div class="kpi-label">Faturamento bruto</div>
                        <div class="kpi-value">{{ $fmtBRLShort($resumo['gmv']['atual'] ?? 0) }}</div>
                        <div class="kpi-delta">{!! $fmtDelta($resumo['gmv']['deltaPct'] ?? null) !!} MoM</div>
                    </td>
                    <td>
                        <div class="kpi-label">Vendas</div>
                        <div class="kpi-value">{{ $fmtInt($resumo['vendas']['atual'] ?? 0) }}</div>
                        <div class="kpi-delta">{!! $fmtDelta($resumo['vendas']['deltaPct'] ?? null) !!} MoM</div>
                    </td>
                    <td>
                        <div class="kpi-label">Lojistas ativos</div>
                        <div class="kpi-value">{{ $fmtInt($resumo['sellersAtivos']['atual'] ?? 0) }}</div>
                        <div class="kpi-delta">{!! $fmtDelta($resumo['sellersAtivos']['deltaPct'] ?? null) !!} MoM</div>
                    </td>
                    <td>
                        <div class="kpi-label">Investimento Ads</div>
                        <div class="kpi-value">{{ $fmtBRLShort($resumo['investimentoAds']['atual'] ?? 0) }}</div>
                        <div class="kpi-delta">{!! $fmtDelta($resumo['investimentoAds']['deltaPct'] ?? null) !!} MoM</div>
                    </td>
                </tr>
                {{-- Linha 2: Faturamento Ads, Full, Flex, Visitas --}}
                <tr>
                    <td>
                        <div class="kpi-label">Faturamento Ads</div>
                        <div class="kpi-value">{{ $fmtBRLShort($resumo['gmvAds']['atual'] ?? 0) }}</div>
                        <div class="kpi-delta">{!! $fmtDelta($resumo['gmvAds']['deltaPct'] ?? null) !!} MoM</div>
                    </td>
                    <td>
                        <div class="kpi-label">Envio Full</div>
                        <div class="kpi-value">{{ $fmtBRLShort($resumo['gmvFull']['atual'] ?? 0) }}</div>
                        <div class="kpi-delta">{!! $fmtDelta($resumo['gmvFull']['deltaPct'] ?? null) !!} MoM</div>
                    </td>
                    <td>
                        <div class="kpi-label">Envio Flex</div>
                        <div class="kpi-value">{{ $fmtBRLShort($resumo['gmvFlex']['atual'] ?? 0) }}</div>
                        <div class="kpi-delta">{!! $fmtDelta($resumo['gmvFlex']['deltaPct'] ?? null) !!} MoM</div>
                    </td>
                    <td>
                        <div class="kpi-label">Visitas</div>
                        <div class="kpi-value">{{ $fmtInt($resumo['visitas']['atual'] ?? 0) }}</div>
                        <div class="kpi-delta">{!! $fmtDelta($resumo['visitas']['deltaPct'] ?? null) !!} MoM</div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    {{-- ═══ SEÇÃO 2 — Distribuição por programa ═══ --}}
    @if (!empty($distribuicao['programa']['distribuicao'] ?? null))
        <div class="section">
            <h2>Distribuição por programa</h2>
            <table>
                <thead>
                    <tr>
                        <th>Programa</th>
                        <th>Lojistas</th>
                        <th>Faturamento</th>
                        <th>%</th>
                        <th>TSI</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($distribuicao['programa']['distribuicao'] as $p)
                        <tr>
                            <td><strong>{{ $p['programa'] ?? '—' }}</strong></td>
                            <td>{{ $fmtInt($p['count'] ?? 0) }}</td>
                            <td>{{ $fmtBRLShort($p['gmv'] ?? 0) }}</td>
                            <td>{{ $fmtPct($p['pct'] ?? 0) }}</td>
                            <td>{{ $fmtInt($p['tsi'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ═══ SEÇÃO 2b — Distribuição por cluster (top 5) ═══ --}}
    @if (!empty($distribuicao['cluster']['distribuicao'] ?? null))
        <div class="section">
            <h2>Distribuição por cluster (top 5)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Cluster</th>
                        <th>Lojistas</th>
                        <th>Faturamento</th>
                        <th>%</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_slice($distribuicao['cluster']['distribuicao'], 0, 5) as $c)
                        <tr>
                            <td><strong>{{ $c['cluster'] ?? '—' }}</strong></td>
                            <td>{{ $fmtInt($c['count'] ?? 0) }}</td>
                            <td>{{ $fmtBRLShort($c['gmv'] ?? 0) }}</td>
                            <td>{{ $fmtPct($c['pct'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ═══ SEÇÃO 3 — Top 10 lojistas por faturamento ═══ --}}
    @if (!empty($rankings['topGmv'] ?? null))
        <div class="section">
            <h2>Top 10 lojistas por faturamento</h2>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Razão social</th>
                        <th>CNPJ</th>
                        <th>Programa</th>
                        <th>Medalha</th>
                        <th style="text-align:right">Faturamento</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (array_slice($rankings['topGmv'], 0, 10) as $r)
                        <tr>
                            <td>{{ $r['rank'] ?? '—' }}</td>
                            <td>{{ $r['razaoSocial'] ?? $r['custId'] ?? '—' }}</td>
                            <td>{{ $r['cnpj'] ?? '—' }}</td>
                            <td>{{ $r['programa'] ?? '—' }}</td>
                            <td>{{ $r['nivelSolucion'] ?? '—' }}</td>
                            <td style="text-align:right"><strong>{{ $fmtBRLShort($r['valor'] ?? 0) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ═══ SEÇÃO 4 — Alertas e signals ═══ --}}
    @if (!empty($signals))
        <div class="section">
            <h2>Alertas e signals</h2>
            <p style="margin-bottom:8px">
                Total de signals detectados: <strong>{{ $fmtInt($signals['total'] ?? 0) }}</strong>
            </p>

            {{-- Tabela por tipo e severidade --}}
            @if (!empty($signals['porTipoESeveridade'] ?? null))
                <table>
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Severidade</th>
                            <th>Quantidade</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($signals['porTipoESeveridade'] as $s)
                            <tr>
                                <td>{{ $s['eventType'] ?? $s['event_type'] ?? '—' }}</td>
                                <td>
                                    @if (($s['severity'] ?? '') === 'critical')
                                        <span class="badge-critical">CRÍTICO</span>
                                    @else
                                        {{ ucfirst($s['severity'] ?? '—') }}
                                    @endif
                                </td>
                                <td>{{ $fmtInt($s['total'] ?? $s['count'] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            {{-- Amostra de oportunidades PADS (até 5) --}}
            @if (!empty($signals['oportunidadesPads'] ?? null))
                <p style="margin-top:12px"><strong>Oportunidades de ADS (amostra de 5):</strong></p>
                <ul style="padding-left: 20px; margin-top: 4px;">
                    @foreach (array_slice($signals['oportunidadesPads'], 0, 5) as $o)
                        <li>{{ $o['razaoSocial'] ?? $o['custId'] ?? '—' }} — GMV mensal: {{ $fmtBRLShort($o['gmv'] ?? 0) }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- ═══ FOOTER — sempre renderiza ═══ --}}
    <div class="footer">
        Gerado automaticamente pelo ECF Admin a partir dos dados oficiais do parceiro ECF Drive.<br>
        Milestone v8.0 — {{ $geradoEm }}
    </div>

</div>

</body>
</html>
