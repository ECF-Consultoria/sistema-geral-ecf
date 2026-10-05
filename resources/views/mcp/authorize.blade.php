<!DOCTYPE html>
{{--
    Tela "Autorizar acesso" do MCP do ECF Admin (Passport::authorizationView,
    registrado no AppServiceProvider).

    Aparece depois do login, quando um conector (claude.ai, MCP Inspector,
    Claude Code) pede acesso de leitura ao Admin em nome do usuário.

    Autocontida de propósito: a versão do pacote laravel/mcp carrega
    `resources/css/app.css` pelo @vite, que não é entrada do nosso Vite (só
    `app.jsx` é) — o @vite estouraria "Unable to locate file in Vite manifest".
    Cores = tokens ecf-* do tailwind.config.js.
--}}
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Autorizar acesso — {{ config('app.name', 'ECF Admin') }}</title>
    <link rel="icon" href="/favicon.ico">
    <style>
        :root {
            --bg: #050507;
            --card: #0f1116;
            --borda: rgba(255, 255, 255, 0.08);
            --texto: #f5f5f7;
            --suave: rgba(255, 255, 255, 0.55);
            --amarelo: #ffe600;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: var(--bg); color: var(--texto); }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .cartao {
            width: 100%; max-width: 440px; background: var(--card);
            border: 1px solid var(--borda); border-radius: 14px; padding: 28px;
        }
        .marca { font-size: 12px; letter-spacing: .12em; text-transform: uppercase; color: var(--amarelo); font-weight: 700; }
        h1 { font-size: 22px; line-height: 1.3; margin: 10px 0 6px; }
        p { margin: 0; color: var(--suave); font-size: 14px; line-height: 1.55; }
        .caixa {
            margin-top: 18px; border: 1px solid var(--borda); border-radius: 10px;
            padding: 14px; background: rgba(255, 255, 255, 0.03);
        }
        .rotulo { font-size: 12px; color: var(--suave); margin-bottom: 4px; }
        .valor { font-size: 14px; font-weight: 600; word-break: break-all; }
        ul { margin: 8px 0 0; padding-left: 18px; color: var(--suave); font-size: 14px; line-height: 1.6; }
        .acoes { display: flex; gap: 10px; margin-top: 22px; }
        .acoes form { flex: 1; }
        button {
            width: 100%; height: 42px; border-radius: 10px; font-size: 14px; font-weight: 600;
            cursor: pointer; border: 1px solid var(--borda);
        }
        .negar { background: transparent; color: var(--texto); }
        .negar:hover { background: rgba(255, 255, 255, 0.05); }
        .aprovar { background: var(--amarelo); color: #111; border-color: var(--amarelo); }
        .aprovar:hover { filter: brightness(.95); }
        button:disabled { opacity: .6; cursor: default; }
        .rodape { margin-top: 16px; font-size: 12px; color: var(--suave); }
    </style>
</head>
<body>
<main class="cartao">
    <div class="marca">ECF Admin</div>
    <h1>Autorizar {{ $client->name }}?</h1>
    <p>Este aplicativo quer consultar o ECF Admin em seu nome.</p>

    <div class="caixa">
        <div class="rotulo">Conectado como</div>
        <div class="valor">{{ $user->name }} · {{ $user->email }}</div>
    </div>

    <div class="caixa">
        <div class="rotulo">O que ele vai poder fazer</div>
        <ul>
            <li>Ler empresas, sugadores, demandas, onboarding, PPAs e alertas, com o mesmo recorte do seu perfil.</li>
            <li>Nada de alterar, salvar, excluir ou marcar como visto: o acesso é só de leitura.</li>
        </ul>
    </div>

    <div class="acoes">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="negar">Cancelar</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="form-aprovar">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="aprovar" id="botao-aprovar">Autorizar</button>
        </form>
    </div>

    <p class="rodape">Para revogar depois, peça a um admin do sistema. Cada consulta fica registrada no log de acesso.</p>
</main>

<script>
    // Evita clique duplo: o segundo POST chegaria com o auth_token já usado.
    document.getElementById('form-aprovar').addEventListener('submit', function () {
        var botao = document.getElementById('botao-aprovar');
        botao.disabled = true;
        botao.textContent = 'Autorizando…';
    });
</script>
</body>
</html>
