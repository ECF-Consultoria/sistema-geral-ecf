<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conexão Mercado Livre — ECF Consultoria</title>
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
        .icon { font-size: 48px; margin-bottom: 20px; }
        h1 { font-size: 20px; font-weight: 700; margin-bottom: 12px; }
        p  { font-size: 14px; color: rgba(255,255,255,0.55); line-height: 1.6; }
        .warn {
            margin-top: 20px;
            padding: 12px 16px;
            background: rgba(255,193,7,0.1);
            border: 1px solid rgba(255,193,7,0.25);
            border-radius: 8px;
            font-size: 13px;
            color: rgba(255,230,0,0.85);
            text-align: left;
        }
        .close-hint {
            margin-top: 28px;
            font-size: 13px;
            color: rgba(255,255,255,0.3);
        }
    </style>
</head>
<body>
    <div class="card">
        @if($success)
            <div class="icon">✅</div>
            <h1>Conexão realizada com sucesso!</h1>
            <p>A conta do Mercado Livre foi vinculada à empresa <strong>{{ $company_name }}</strong>.</p>

            @if($diverge ?? false)
                <div class="warn">
                    ⚠️ <strong>Atenção:</strong> o ID da conta autorizada (<code>{{ $received_id }}</code>)
                    difere do ID cadastrado na empresa (<code>{{ $stored_id }}</code>).
                    Um administrador precisará verificar a vinculação.
                </div>
            @endif
        @else
            <div class="icon">❌</div>
            <h1>Falha na conexão</h1>
            <p>{{ $message ?? 'Ocorreu um erro inesperado.' }}</p>
        @endif

        <p class="close-hint">Você pode fechar esta aba.</p>
    </div>
</body>
</html>
