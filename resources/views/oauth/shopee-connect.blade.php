<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conectar Shopee — ECF Consultoria</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #050507;
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #0f1116;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 40px 48px;
            max-width: 480px;
            width: 100%;
            text-align: center;
        }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { font-size: 20px; font-weight: 700; margin-bottom: 12px; line-height: 1.3; }
        p  { font-size: 14px; color: rgba(255,255,255,0.55); line-height: 1.6; }
        .brand { color: #ee4d2d; }

        /* Barra de progresso dos 2 passos */
        .steps { display: flex; gap: 8px; margin: 0 auto 24px; max-width: 240px; }
        .steps .seg { flex: 1; height: 5px; border-radius: 999px; background: rgba(255,255,255,0.12); }
        .steps .seg.on { background: #ee4d2d; }
        .steplabel {
            font-size: 12px; font-weight: 600; letter-spacing: .04em;
            text-transform: uppercase; color: rgba(255,255,255,0.4); margin-bottom: 20px;
        }

        .btn {
            display: inline-block; margin-top: 28px; padding: 13px 28px;
            background: #ee4d2d; color: #fff; font-size: 15px; font-weight: 600;
            border-radius: 10px; text-decoration: none;
        }
        .btn:hover { background: #d8431f; }
        .hint { margin-top: 24px; font-size: 13px; color: rgba(255,255,255,0.3); }
    </style>
</head>
<body>
    <div class="card">
        @if($step === '1')
            <div class="steps"><span class="seg on"></span><span class="seg"></span></div>
            <div class="steplabel">Passo 1 de 2 · Loja (ERP)</div>
            <div class="icon">🛍️</div>
            <h1>Conecte sua conta <span class="brand">Shopee</span></h1>
            <p>São 2 autorizações rápidas na Shopee: primeiro a <strong>loja</strong> (pedidos e faturamento) e depois os <strong>anúncios</strong> (Ads). Clique abaixo para começar.</p>
            <a class="btn" href="{{ $button_url }}">{{ $button_label ?? 'Conectar Shopee' }}</a>

        @elseif($step === '2')
            <div class="steps"><span class="seg on"></span><span class="seg on"></span></div>
            <div class="steplabel">Passo 2 de 2 · Anúncios (Ads)</div>
            <div class="icon">📣</div>
            <h1>Loja conectada! Falta 1 passo</h1>
            <p>Agora autorize o app de <strong>Anúncios (Ads)</strong> da Shopee — use a <strong>mesma loja</strong> do passo anterior para concluir a conexão.</p>
            <a class="btn" href="{{ $button_url }}">{{ $button_label ?? 'Conectar Shopee Ads' }}</a>

        @elseif($step === 'done')
            <div class="steps"><span class="seg on"></span><span class="seg on"></span></div>
            <div class="icon">✅</div>
            <h1>Conexão concluída com sucesso!</h1>
            <p>A sua loja <strong class="brand">Shopee</strong> foi vinculada ao sistema <strong>ECF Consultoria</strong>.
            @if(!empty($only_erp)) O app de anúncios (Ads) será conectado em breve. @endif</p>
            <p class="hint">Você pode fechar esta aba.</p>

        @else
            <div class="icon">❌</div>
            <h1>Falha na conexão</h1>
            <p>{{ $message ?? 'Ocorreu um erro inesperado.' }}</p>
            <p class="hint">Você pode fechar esta aba e tentar novamente pelo link enviado.</p>
        @endif
    </div>
</body>
</html>
