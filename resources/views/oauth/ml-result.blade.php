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
        {{-- Polos: a conta autorizada não bate com a cadastrada. Nada foi gravado —
             quem resolve é a ECF. Acontece quando o navegador já está logado no
             Mercado Livre com outra conta: o ML pula a tela de autorização e
             devolve o code sem perguntar nada. --}}
        @if($divergente ?? false)
            <div class="icon">⚠️</div>
            <h1>Conta diferente da cadastrada</h1>
            <p>
                Você autorizou com a conta <strong>{{ $nickname ?? 'sem apelido' }}</strong>
                (<code>{{ $received_id }}</code>), mas a empresa
                <strong>{{ $company_name }}</strong> está cadastrada com a conta
                <code>{{ $previous_id }}</code>.
            </p>
            <div class="warn">
                ⚠️ <strong>Nada foi alterado.</strong> Se você tem mais de uma conta no
                Mercado Livre, saia da conta atual em
                <code>mercadolivre.com.br</code>, entre com a conta da loja e abra o
                link de novo. Se acredita que a conta está certa, fale com a equipe da
                ECF — já registramos esta tentativa para conferência.
            </div>
        @elseif($success)
            <div class="icon">✅</div>
            <h1>Conexão realizada com sucesso!</h1>
            <p>A conta do Mercado Livre foi vinculada com sucesso ao sistema <strong>ECF Consultoria</strong>.</p>

            {{-- Polos: sem token persistido, então o texto não promete vínculo — e o
                 apelido aparece SEMPRE, para o cliente perceber na hora se autorizou
                 com a conta errada (o ML não mostra tela quando já há sessão ativa). --}}
            @if($nickname ?? false)
                <div class="warn">
                    ℹ️ Conta identificada: <strong>{{ $nickname }}</strong>
                    (<code>{{ $received_id }}</code>). Se esta não é a conta da sua loja,
                    avise a equipe da ECF.
                </div>
            @endif

            @if($corrected ?? false)
                <div class="warn">
                    ℹ️ <strong>ID atualizado:</strong> o identificador da loja foi ajustado
                    automaticamente para o da conta autorizada (<code>{{ $received_id }}</code>),
                    substituindo o valor anterior (<code>{{ $previous_id }}</code>).
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
