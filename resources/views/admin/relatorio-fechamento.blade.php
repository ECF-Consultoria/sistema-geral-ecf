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
            font-size: 13px;
            color: #1a1a2e;
            background: #fff;
            padding: 40px;
            max-width: 800px;
            margin: 0 auto;
        }

        /* ── Header ECF ─────────────────────────────── */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 3px solid #ffe600;
            padding-bottom: 16px;
            margin-bottom: 28px;
        }
        .header-brand h1 {
            font-size: 22px;
            font-weight: 800;
            color: #1a1a2e;
            letter-spacing: -0.5px;
        }
        .header-brand h1 span { color: #ffe600; }
        .header-brand p {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header-meta {
            text-align: right;
            font-size: 11px;
            color: #888;
            line-height: 1.6;
        }
        .header-meta strong {
            display: block;
            font-size: 15px;
            color: #1a1a2e;
            font-weight: 700;
        }

        /* ── Status badge ────────────────────────────── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 20px;
        }
        .status-recebido { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .status-pendente { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }

        /* ── Seções ──────────────────────────────────── */
        .section {
            margin-bottom: 24px;
        }
        .section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #888;
            margin-bottom: 10px;
            padding-bottom: 4px;
            border-bottom: 1px solid #eee;
        }

        /* ── Grid de campos ─────────────────────────── */
        .fields-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .field { }
        .field label {
            display: block;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #aaa;
            margin-bottom: 2px;
        }
        .field span {
            font-size: 13px;
            color: #1a1a2e;
            font-weight: 500;
        }
        .field span.mono { font-family: monospace; font-size: 12px; }
        .field span.empty { color: #ccc; font-style: italic; }

        /* ── Tabela de faturamento ───────────────────── */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        th {
            background: #f5f5f5;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #888;
            padding: 8px 10px;
            text-align: left;
            border-bottom: 2px solid #e5e5e5;
        }
        th.right, td.right { text-align: right; }
        td {
            padding: 9px 10px;
            border-bottom: 1px solid #f0f0f0;
            color: #1a1a2e;
            vertical-align: middle;
        }
        tr.vinculada td { color: #555; }
        tr.vinculada td:first-child { padding-left: 22px; }
        tr.vinculada td:first-child::before { content: '↳ '; color: #bbb; }
        tr.total td {
            background: #fffbe6;
            font-weight: 700;
            border-top: 2px solid #ffe600;
            border-bottom: none;
        }
        .valor-destaque {
            font-size: 15px;
            font-weight: 800;
            color: #1a1a2e;
        }
        .faixa-badge {
            display: inline-block;
            background: #f0f0f0;
            color: #555;
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
        }
        .sem-dados { color: #ccc; font-style: italic; font-size: 12px; }

        /* ── Total destacado (empresa sem grupo) ────── */
        .total-box {
            background: #fffbe6;
            border: 2px solid #ffe600;
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
        }
        .total-box .label { font-size: 12px; color: #888; }
        .total-box .value { font-size: 22px; font-weight: 800; color: #1a1a2e; }

        /* ── Serviço adicional ───────────────────────── */
        .adicional-box {
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-radius: 6px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .adicional-box .name { color: #555; }
        .adicional-box .price { font-weight: 700; color: #1a1a2e; }

        /* ── Footer ──────────────────────────────────── */
        .footer {
            margin-top: 40px;
            padding-top: 12px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #bbb;
        }

        /* ── Botão imprimir (some no PDF) ────────────── */
        .print-btn {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #ffe600;
            color: #1a1a2e;
            font-weight: 700;
            font-size: 13px;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .print-btn:hover { background: #f0d800; }

        @media print {
            body { padding: 0; max-width: 100%; }
            .print-btn { display: none; }
            @page { margin: 15mm 12mm; }
        }
    </style>
</head>
<body>

{{-- ── HEADER ─────────────────────────────────────────── --}}
<div class="header">
    <div class="header-brand">
        <h1>ECF <span>●</span> Consultoria</h1>
        <p>Relatório de Fechamento</p>
    </div>
    <div class="header-meta">
        <strong>{{ $mes_label }}</strong>
        Gerado em {{ $gerado_em }}
    </div>
</div>

{{-- ── STATUS DE PAGAMENTO ──────────────────────────────── --}}
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
            <span style="font-size:18px; font-weight:800">{{ $company->name }}</span>
        </div>
        <div class="field">
            <label>CNPJ</label>
            <span class="mono">
                {{ preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', preg_replace('/\D/', '', $company->cnpj ?? '')) ?: '—' }}
            </span>
        </div>
        <div class="field">
            <label>Cust ID (Adman)</label>
            <span class="mono">{{ $company->adman_account_id ?? '—' }}</span>
        </div>
        @if ($company->adman_store_id)
        <div class="field">
            <label>Store ID</label>
            <span class="mono">{{ $company->adman_store_id }}</span>
        </div>
        @endif
        @if ($company->ml_store_id)
        <div class="field">
            <label>Loja ML</label>
            <span class="mono">{{ $company->ml_store_id }}</span>
        </div>
        @endif
        @if ($company->segment)
        <div class="field">
            <label>Segmento</label>
            <span>{{ $company->segment }}</span>
        </div>
        @endif
        <div class="field">
            <label>Tipo de serviço</label>
            <span>
                @switch($company->service_type)
                    @case('polo') POLO @break
                    @case('assessoria') Assessoria @break
                    @case('incubadora') Incubadora @break
                    @default <span class="empty">Não definido</span>
                @endswitch
            </span>
        </div>
        <div class="field">
            <label>Tipo de contrato</label>
            <span>
                @switch($company->contract_type)
                    @case('fixo') Fixo @break
                    @case('progressao') Escala de Progressão @break
                    @default <span class="empty">Não definido</span>
                @endswitch
            </span>
        </div>
        <div class="field">
            <label>Vigência do contrato</label>
            <span>
                @if ($company->contract_start)
                    {{ $company->contract_start->format('d/m/Y') }}
                    @if ($company->contract_end) – {{ $company->contract_end->format('d/m/Y') }} @endif
                @else
                    <span class="empty">Não definida</span>
                @endif
            </span>
        </div>
    </div>
</div>

{{-- ── FATURAMENTO E MENSALIDADE ────────────────────────── --}}
<div class="section">
    <div class="section-title">
        Faturamento e mensalidade
        @if ($periodo_inicio) — {{ $periodo_inicio }} a {{ $periodo_fim }} @endif
    </div>

    @if (count($vinculadas) > 0)
        {{-- Grupo com empresas vinculadas --}}
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
                {{-- Empresa principal --}}
                <tr>
                    <td><strong>{{ $company->name }}</strong></td>
                    <td>
                        @if ($faturamento !== null)
                            {{ 'R$ ' . number_format($faturamento, 0, ',', '.') }}
                        @else
                            <span class="sem-dados">Sem dados</span>
                        @endif
                    </td>
                    <td>
                        @if ($faixa_label)
                            <span class="faixa-badge">{{ $faixa_label }}</span>
                        @else — @endif
                    </td>
                    <td class="right">
                        @if ($valor_mensal)
                            {{ 'R$ ' . number_format($valor_mensal, 0, ',', '.') }}
                        @else — @endif
                    </td>
                </tr>
                {{-- Vinculadas --}}
                @foreach ($vinculadas as $v)
                <tr class="vinculada">
                    <td>{{ $v['name'] }}</td>
                    <td>
                        @if ($v['faturamento'] !== null)
                            {{ 'R$ ' . number_format($v['faturamento'], 0, ',', '.') }}
                        @else
                            <span class="sem-dados">Sem dados</span>
                        @endif
                    </td>
                    <td>
                        @if ($v['faixa_label'])
                            <span class="faixa-badge">{{ $v['faixa_label'] }}</span>
                        @else — @endif
                    </td>
                    <td class="right">
                        @if ($v['valor_mensal'])
                            {{ 'R$ ' . number_format($v['valor_mensal'], 0, ',', '.') }}
                        @else — @endif
                    </td>
                </tr>
                @endforeach
                {{-- Total do grupo --}}
                <tr class="total">
                    <td colspan="3"><strong>Total do grupo</strong></td>
                    <td class="right">
                        <span class="valor-destaque">{{ 'R$ ' . number_format($total_mensalidade, 0, ',', '.') }}</span>
                    </td>
                </tr>
            </tbody>
        </table>

    @elseif ($faturamento !== null)
        {{-- Empresa individual com dados --}}
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
                    <td>{{ 'R$ ' . number_format($faturamento, 0, ',', '.') }}</td>
                    <td>
                        @if ($faixa_label)
                            <span class="faixa-badge">{{ $faixa_label }}</span>
                        @else — @endif
                    </td>
                    <td class="right">
                        <span class="valor-destaque">
                            {{ $valor_mensal ? 'R$ ' . number_format($valor_mensal, 0, ',', '.') : '—' }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

    @else
        <p class="sem-dados" style="padding: 12px 0">Sem dados de faturamento para este mês.</p>
    @endif
</div>

{{-- ── SERVIÇO ADICIONAL ────────────────────────────────── --}}
@if ($company->additional_service)
<div class="section">
    <div class="section-title">Serviço adicional</div>
    <div class="adicional-box">
        <span class="name">{{ $company->additional_service }}</span>
        @if ($company->additional_service_price)
            <span class="price">R$ {{ number_format($company->additional_service_price, 0, ',', '.') }}/mês</span>
        @endif
    </div>
</div>
@endif

{{-- ── FOOTER ───────────────────────────────────────────── --}}
<div class="footer">
    <span>ECF Consultoria — Documento gerado automaticamente pelo sistema de fechamento</span>
    <span>{{ $gerado_em }}</span>
</div>

<button class="print-btn" onclick="window.print()">⬇ Salvar como PDF</button>

<script>
    // Abre diálogo de impressão automaticamente
    window.addEventListener('load', () => setTimeout(() => window.print(), 400));
</script>
</body>
</html>
