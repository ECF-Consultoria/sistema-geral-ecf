<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório de Fechamento — {{ $dados['mesLabel'] ?? '' }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1a1a2e; background: #fff; }

        /* ── Cabeçalho ── */
        .header { background: #050507; padding: 14px 20px; margin-bottom: 0; }
        .header-title { color: #ffe600; font-size: 18px; font-weight: 700; }
        .header-sub { color: rgba(255,255,255,0.5); font-size: 10px; margin-top: 2px; }
        .gradient-bar { height: 3px; background: #ffe600; margin-bottom: 18px; }

        /* ── Seção ── */
        .section-label {
            font-size: 9px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; color: #888; border-bottom: 1px solid #eee;
            padding-bottom: 3px; margin-bottom: 8px; margin-top: 16px;
        }

        /* ── Tabela principal ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th {
            background: #f5f5f5; font-size: 9px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.6px; color: #666;
            padding: 6px 8px; text-align: left; border-bottom: 2px solid #e0e0e0;
        }
        th.right, td.right { text-align: right; }
        td { padding: 7px 8px; border-bottom: 1px solid #f2f2f2; color: #1a1a2e; font-size: 11px; }
        tr:last-child td { border-bottom: none; }

        /* ── Filha (sub-linha) ── */
        tr.filha td { color: #666; font-size: 10px; background: #fafafa; }
        tr.filha td:first-child { padding-left: 20px; }

        /* ── Linha total do grupo ── */
        tr.total-grupo td { font-weight: 700; background: #f0f0f0; border-top: 1px solid #d0d0d0; font-size: 11px; }

        /* ── Status badges ── */
        .badge-rec { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: 700; }
        .badge-pen { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: 700; }

        /* ── Totais ── */
        .totais-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .totais-table td { padding: 12px 14px; border: 1px solid #e0e0e0; vertical-align: top; width: 33.33%; }
        .totais-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: #888; margin-bottom: 4px; }
        .totais-valor { font-size: 16px; font-weight: 800; color: #1a1a2e; }
        .totais-valor.verde { color: #065f46; }
        .totais-valor.amber { color: #92400e; }

        /* ── Rodapé ── */
        .footer { margin-top: 20px; font-size: 9px; color: #bbb; border-top: 1px solid #eee; padding-top: 6px; }
    </style>
</head>
<body>

    {{-- Cabeçalho --}}
    <div class="header">
        <div class="header-title">ECF Consultoria — Fechamento Financeiro</div>
        <div class="header-sub">{{ $dados['mesLabel'] ?? '' }} · Gerado em {{ now()->setTimezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i') }}</div>
    </div>
    <div class="gradient-bar"></div>

    {{-- Tabela de empresas --}}
    <div class="section-label">Relatório por empresa</div>

    <table>
        <thead>
            <tr>
                <th>Empresa</th>
                <th>Faturamento</th>
                <th>Faixa</th>
                <th class="right">Mensalidade</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($dados['relatorios'] as $r)
                {{-- Linha da empresa principal --}}
                <tr>
                    <td><strong>{{ is_object($r['company']) ? $r['company']->name : ($r['company']['name'] ?? '—') }}</strong></td>
                    <td>{{ $r['faturamento'] !== null ? 'R$ '.number_format($r['faturamento'], 2, ',', '.') : '—' }}</td>
                    <td>{{ $r['faixa_label'] ?? '—' }}</td>
                    <td class="right">{{ $r['valor_mensal'] !== null ? 'R$ '.number_format($r['valor_mensal'], 2, ',', '.') : '—' }}</td>
                    <td>
                        @if ($r['recebido'])
                            <span class="badge-rec">Recebido</span>
                        @else
                            <span class="badge-pen">Pendente</span>
                        @endif
                    </td>
                </tr>
                {{-- Linhas das filhas --}}
                @foreach ($r['vinculadas'] ?? [] as $v)
                <tr class="filha">
                    <td>↳ {{ $v['name'] }}</td>
                    <td>{{ $v['faturamento'] !== null ? 'R$ '.number_format($v['faturamento'], 2, ',', '.') : '—' }}</td>
                    <td>{{ $v['faixa_label'] ?? '—' }}</td>
                    <td class="right">{{ $v['valor_mensal'] !== null ? 'R$ '.number_format($v['valor_mensal'], 2, ',', '.') : '—' }}</td>
                    <td></td>
                </tr>
                @endforeach
                {{-- Total do grupo quando há filhas --}}
                @if (!empty($r['vinculadas']))
                <tr class="total-grupo">
                    <td colspan="3">Total do grupo</td>
                    <td class="right">R$ {{ number_format($r['total_mensalidade'] ?? 0, 2, ',', '.') }}</td>
                    <td></td>
                </tr>
                @endif
            @empty
                <tr>
                    <td colspan="5" style="text-align:center; color:#aaa; padding: 20px;">
                        Nenhum registro encontrado para este mês.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totais consolidados --}}
    @if (!empty($dados['totais']))
    <div class="section-label">Totais consolidados</div>
    <table class="totais-table">
        <tr>
            <td>
                <div class="totais-label">Total mensalidade</div>
                <div class="totais-valor">R$ {{ number_format($dados['totais']['total_mensalidade'] ?? 0, 2, ',', '.') }}</div>
            </td>
            <td>
                <div class="totais-label">Total recebido</div>
                <div class="totais-valor verde">R$ {{ number_format($dados['totais']['total_recebido'] ?? 0, 2, ',', '.') }}</div>
            </td>
            <td>
                <div class="totais-label">Total pendente</div>
                <div class="totais-valor amber">R$ {{ number_format($dados['totais']['total_pendente'] ?? 0, 2, ',', '.') }}</div>
            </td>
        </tr>
    </table>
    @endif

    <div class="footer">
        Documento gerado automaticamente pelo sistema ECF Admin — {{ now()->setTimezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i') }}
    </div>

</body>
</html>
