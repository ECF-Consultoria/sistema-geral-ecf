<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório Mensal Executivo — {{ $mesLabel }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #050507; max-width: 600px; margin: 0 auto; padding: 20px;">

    {{-- Cabeçalho com marca amarela ECF --}}
    <div style="border-bottom: 3px solid #ffe600; padding-bottom: 8px; margin-bottom: 20px;">
        <h2 style="color: #050507; font-size: 18px; margin: 0;">Relatório Executivo Mensal</h2>
        <div style="color: #666; font-size: 13px; margin-top: 2px;">{{ $mesLabel }}</div>
    </div>

    {{-- Saudação --}}
    <p>Olá, equipe ECF Admin.</p>

    <p style="margin-top: 12px;">
        O <strong>Relatório Executivo Mensal de {{ $mesLabel }}</strong> está pronto.
        O PDF completo está em anexo.
    </p>

    {{-- 4 linhas de resumo essencial (exibidas se o Job passou os KPIs) --}}
    @if (!empty($resumo))
        <p style="margin-top: 16px;"><strong>Resumo do mês:</strong></p>
        <ul style="line-height: 1.8; margin-top: 6px;">
            <li>
                Faturamento bruto:
                <strong>R$ {{ number_format((float) ($resumo['gmvAtual'] ?? 0), 2, ',', '.') }}</strong>
                ({{ ($resumo['gmvDeltaPct'] ?? 0) >= 0 ? '+' : '' }}{{ number_format((float) ($resumo['gmvDeltaPct'] ?? 0), 2, ',', '.') }}% MoM)
            </li>
            <li>Lojistas ativos: <strong>{{ number_format((int) ($resumo['sellersAtivos'] ?? 0), 0, ',', '.') }}</strong></li>
            <li>Investimento em Ads: <strong>R$ {{ number_format((float) ($resumo['invAds'] ?? 0), 2, ',', '.') }}</strong></li>
            <li>Signals detectados: <strong>{{ number_format((int) ($resumo['signalsTotal'] ?? 0), 0, ',', '.') }}</strong></li>
        </ul>
    @endif

    <p style="margin-top: 20px;">
        PDF completo em anexo com 4 seções: resumo executivo + distribuição + top 10 lojistas + alertas críticos.
    </p>

    {{-- Assinatura --}}
    <div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #eaeaea; color: #666; font-size: 12px;">
        <strong>ECF Admin — Setor Dev</strong><br>
        Gerado automaticamente pelo pipeline ECF Drive · {{ now()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
    </div>

</body>
</html>
