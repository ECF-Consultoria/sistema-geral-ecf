<!DOCTYPE html>
<html lang="pt-BR">
<head>
    {{-- UTF-8 obrigatório para acentuação pt-BR no Dompdf --}}
    <meta charset="UTF-8">
    <title>Relatório de bonificação — {{ $competencia_label }}</title>
    <style>
        /* CSS inline — Dompdf NÃO interpreta classes Tailwind */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #050507; background: #fff; }
        .container { width: 100%; padding: 18px; }

        .header { border-bottom: 3px solid #ffe600; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { font-size: 17px; font-weight: bold; }
        .header .meta { color: #666; font-size: 10px; margin-top: 4px; }

        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        th { background: #0f1116; color: #ffe600; text-align: left; padding: 6px 8px; font-weight: bold; }
        td { padding: 6px 8px; border-bottom: 1px solid #eaeaea; }
        tr:nth-child(even) td { background: #fafafa; }
        .num { text-align: right; }
        .pos { color: #16a34a; }
        .neg { color: #dc2626; }
        .nota { font-weight: bold; }

        .faixa { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 9px; font-weight: bold; }
        .faixa-maximo        { background: #dcfce7; color: #15803d; }
        .faixa-intermediario { background: #fef9c3; color: #a16207; }
        .faixa-basico        { background: #e0f2fe; color: #0369a1; }

        .empty { text-align: center; color: #999; padding: 30px; font-size: 11px; }

        .footer { border-top: 1px solid #eaeaea; padding-top: 8px; margin-top: 18px; color: #999; font-size: 9px; }
    </style>
</head>
<body>
@php
    $fmtNum  = fn ($n, $dec = 2) => $n === null ? '—' : number_format((float) $n, $dec, ',', '.');
    $fmtPct  = function ($v) {
        if ($v === null) return '—';
        $v = (float) $v;
        return ($v >= 0 ? '+' : '') . number_format($v, 1, ',', '.') . '%';
    };
    $pctCls  = fn ($v) => $v === null ? '' : ((float) $v >= 0 ? 'pos' : 'neg');
@endphp
<div class="container">
    <div class="header">
        <h1>Relatório de bonificação</h1>
        <div class="meta">
            Competência: <strong>{{ $competencia_label }}</strong>
            &nbsp;·&nbsp; {{ $cargo_label }}
            &nbsp;·&nbsp; {{ count($profissionais) }} profissional(is) contemplado(s)
            &nbsp;·&nbsp; Gerado em {{ $gerado_em }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:24px;">#</th>
                <th>Profissional</th>
                <th>Cargo</th>
                <th class="num">NPS médio</th>
                <th class="num">Var. faturamento</th>
                <th class="num">Var. margem %</th>
                <th class="num">Nota final</th>
                <th>Faixa</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($profissionais as $i => $p)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $p['name'] }}</td>
                    <td>{{ $p['cargo_label'] }}</td>
                    <td class="num">{{ $fmtNum($p['nps_medio'], 2) }}</td>
                    <td class="num {{ $pctCls($p['var_faturamento_pct']) }}">{{ $fmtPct($p['var_faturamento_pct']) }}</td>
                    <td class="num {{ $pctCls($p['var_margem_pct']) }}">{{ $fmtPct($p['var_margem_pct']) }}</td>
                    <td class="num nota">{{ $fmtNum($p['nota_final'], 2) }}</td>
                    <td><span class="faixa faixa-{{ $p['faixa_slug'] }}">{{ $p['faixa_label'] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">Nenhum profissional atingiu o bônus nesta competência.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        "Atingiu o bônus" = nota final ≥ 4,00 (faixa acima de "Sem bônus"). Notas por parâmetro: NPS médio,
        variação de faturamento e variação de margem % vs a janela anterior de mesmo tamanho. Valores idênticos
        aos do ranking de desempenho da mesma competência. ECF Consultoria — documento interno.
    </div>
</div>
</body>
</html>
