<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Título do email com o mês de referência --}}
    <title>Relatório de Fechamento — {{ $dados['mesLabel'] ?? '' }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 13px;
            background: #050507;
            color: #ffffff;
        }
        .wrapper {
            max-width: 700px;
            margin: 0 auto;
            background: #050507;
            padding: 32px 24px;
        }
        /* ── Cabeçalho ────────────────────────────────────── */
        .header {
            background: #0f1116;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
        }
        .header-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.35);
            margin-bottom: 6px;
        }
        .header-title {
            font-size: 22px;
            font-weight: 800;
            color: #ffe600;
            margin-bottom: 4px;
        }
        .header-meta {
            font-size: 12px;
            color: rgba(255,255,255,0.4);
        }
        /* ── Tabela de relatório ──────────────────────────── */
        .table-wrap {
            background: #0f1116;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead tr {
            background: rgba(255,255,255,0.03);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        thead th {
            text-align: left;
            padding: 10px 14px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.35);
        }
        tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }
        tbody tr:last-child { border-bottom: none; }
        tbody td {
            padding: 10px 14px;
            font-size: 12px;
            color: rgba(255,255,255,0.80);
            vertical-align: middle;
        }
        .empresa-nome { font-weight: 600; color: #ffffff; }
        .status-recebido {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(16,185,129,0.12);
            color: #34d399;
            border: 1px solid rgba(16,185,129,0.2);
        }
        .status-pendente {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(245,158,11,0.12);
            color: #fbbf24;
            border: 1px solid rgba(245,158,11,0.2);
        }
        /* ── Totais ───────────────────────────────────────── */
        .totais-grid {
            display: table;
            width: 100%;
            background: #0f1116;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .totais-row { display: table-row; }
        .totais-cell {
            display: table-cell;
            padding: 16px 20px;
            border-right: 1px solid rgba(255,255,255,0.06);
            vertical-align: top;
            width: 33.33%;
        }
        .totais-cell:last-child { border-right: none; }
        .totais-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.30);
            margin-bottom: 4px;
        }
        .totais-valor {
            font-size: 18px;
            font-weight: 800;
            color: #ffe600;
        }
        .totais-valor.verde { color: #34d399; }
        .totais-valor.amber { color: #fbbf24; }
        /* ── Rodapé ───────────────────────────────────────── */
        .footer {
            font-size: 11px;
            color: rgba(255,255,255,0.25);
            text-align: center;
            padding-top: 8px;
        }
    </style>
</head>
<body>
    <div class="wrapper">

        {{-- Cabeçalho com título e data de geração --}}
        <div class="header">
            <p class="header-label">ECF Consultoria — Fechamento Financeiro</p>
            <p class="header-title">{{ $dados['mesLabel'] ?? '' }}</p>
            <p class="header-meta">Gerado em {{ now()->setTimezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i') }}</p>
        </div>

        {{-- Tabela principal com uma linha por empresa --}}
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Empresa</th>
                        <th>Faturamento</th>
                        <th>Faixa</th>
                        <th>Mensalidade</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($dados['relatorios'] as $r)
                        {{-- Linha da empresa principal --}}
                        <tr>
                            <td class="empresa-nome">
                                {{ is_object($r['company']) ? $r['company']->name : ($r['company']['name'] ?? '—') }}
                            </td>
                            <td>
                                @if ($r['faturamento'] !== null)
                                    R$ {{ number_format($r['faturamento'], 2, ',', '.') }}
                                @else
                                    <span style="color: rgba(255,255,255,0.3);">—</span>
                                @endif
                            </td>
                            <td>{{ $r['faixa_label'] ?? '—' }}</td>
                            <td>
                                @if ($r['valor_mensal'] !== null)
                                    R$ {{ number_format($r['valor_mensal'], 2, ',', '.') }}
                                @else
                                    <span style="color: rgba(255,255,255,0.3);">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($r['recebido'])
                                    <span class="status-recebido">Recebido</span>
                                @else
                                    <span class="status-pendente">Pendente</span>
                                @endif
                            </td>
                        </tr>
                        {{-- Linhas das empresas filhas (se houver) --}}
                        @foreach ($r['vinculadas'] ?? [] as $v)
                        <tr style="background: rgba(255,255,255,0.015);">
                            <td style="padding-left: 24px; color: rgba(255,255,255,0.50); font-size: 11px;">
                                ↳ {{ $v['name'] }}
                            </td>
                            <td style="font-size: 11px; color: rgba(255,255,255,0.55);">
                                @if ($v['faturamento'] !== null)
                                    R$ {{ number_format($v['faturamento'], 2, ',', '.') }}
                                @else
                                    <span style="color: rgba(255,255,255,0.2);">—</span>
                                @endif
                            </td>
                            <td style="font-size: 11px; color: rgba(255,255,255,0.45);">{{ $v['faixa_label'] ?? '—' }}</td>
                            <td style="font-size: 11px; color: rgba(255,255,255,0.55);">
                                @if ($v['valor_mensal'] !== null)
                                    R$ {{ number_format($v['valor_mensal'], 2, ',', '.') }}
                                @else
                                    <span style="color: rgba(255,255,255,0.2);">—</span>
                                @endif
                            </td>
                            <td></td>
                        </tr>
                        @endforeach
                        {{-- Linha de total do grupo (quando há filhas) --}}
                        @if (!empty($r['vinculadas']))
                        <tr style="border-top: 1px solid rgba(255,255,255,0.08);">
                            <td colspan="3" style="font-size: 11px; color: rgba(255,255,255,0.35); padding-left: 14px;">Total do grupo</td>
                            <td style="font-size: 12px; font-weight: 700; color: rgba(255,255,255,0.80);">
                                R$ {{ number_format($r['total_mensalidade'] ?? 0, 2, ',', '.') }}
                            </td>
                            <td></td>
                        </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="5" style="text-align:center; color: rgba(255,255,255,0.3); padding: 24px;">
                                Nenhum registro encontrado para este mês.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Bloco de totais consolidados --}}
        @if (!empty($dados['totais']))
            <div class="totais-grid">
                <div class="totais-row">
                    <div class="totais-cell">
                        <p class="totais-label">Total Mensalidade</p>
                        <p class="totais-valor">R$ {{ number_format($dados['totais']['total_mensalidade'] ?? 0, 2, ',', '.') }}</p>
                    </div>
                    <div class="totais-cell">
                        <p class="totais-label">Total Recebido</p>
                        <p class="totais-valor verde">R$ {{ number_format($dados['totais']['total_recebido'] ?? 0, 2, ',', '.') }}</p>
                    </div>
                    <div class="totais-cell">
                        <p class="totais-label">Total Pendente</p>
                        <p class="totais-valor amber">R$ {{ number_format($dados['totais']['total_pendente'] ?? 0, 2, ',', '.') }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Rodapé com aviso de envio automático --}}
        <p class="footer">
            Este relatório foi gerado automaticamente pelo sistema ECF Admin.<br>
            Para configurar os destinatários, acesse o painel em <strong>Fechamento → Configurações</strong>.
        </p>

    </div>
</body>
</html>
