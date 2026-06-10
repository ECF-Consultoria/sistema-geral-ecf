<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Pesquisa de Satisfação ECF — {{ $mesLabel }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; color: #050507; max-width: 600px; margin: 0 auto; padding: 20px;">

    {{-- Cabeçalho com marca amarela ECF --}}
    <div style="border-bottom: 3px solid #ffe600; padding-bottom: 8px; margin-bottom: 20px;">
        <h2 style="color: #050507; font-size: 18px; margin: 0;">Pesquisa de Satisfação ECF</h2>
        <div style="color: #666; font-size: 13px; margin-top: 2px;">{{ $mesLabel }}</div>
    </div>

    {{-- Saudação --}}
    <p>Olá!</p>

    <p style="margin-top: 12px;">
        Esta é a pesquisa mensal de satisfação da
        <strong>ECF Consultoria</strong> com a <strong>{{ $companyName }}</strong>.
        Sua opinião nos ajuda a evoluir o atendimento todo mês.
    </p>

    {{-- Quem te atende: estrategista sempre, analista apenas se houver (D-07) --}}
    <p style="margin-top: 16px;"><strong>Quem te atende:</strong></p>
    <ul style="line-height: 1.8; margin-top: 6px;">
        <li>Estrategista: <strong>{{ $estrategistaName }}</strong></li>
        @if ($analistaName)
            <li>Analista: <strong>{{ $analistaName }}</strong></li>
        @endif
    </ul>

    {{-- CTA principal --}}
    <p style="margin-top: 24px; text-align: center;">
        <a href="{{ $linkPublico }}"
           style="display: inline-block; background: #ffe600; color: #050507; padding: 14px 28px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 15px;">
            Responder pesquisa
        </a>
    </p>

    <p style="margin-top: 20px; color: #555; font-size: 13px; text-align: center;">
        Leva menos de 1 minuto. Suas respostas são confidenciais.
    </p>

    {{-- Assinatura --}}
    <div style="margin-top: 30px; padding-top: 12px; border-top: 1px solid #eaeaea; color: #666; font-size: 12px;">
        <strong>ECF Consultoria</strong><br>
        Pesquisa enviada automaticamente · {{ now()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
    </div>

</body>
</html>
