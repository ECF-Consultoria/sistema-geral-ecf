{{--
    Fase 184 (BLING-17, BLING-18, BLING-19, BLING-29) — landing pública da
    conexão com o Bling. Pública de propósito: protegida só pela assinatura
    do link (middleware `signed`), nunca por `auth`.

    Quatro estados possíveis em `$estado`, nunca mais de um renderizado por
    vez:
      - `convite`        — o cliente ainda não autorizou; mostra o botão.
      - `concluido`       — o Bling já está conectado; sem botão.
      - `erro`            — qualquer falha no caminho; sem botão, com a
                             instrução de pedir um link novo à equipe.
      - `nao_configurado` — o `.env` do app Bling ainda não foi preenchido
                             (D-02 do CONTEXT); texto neutro, sem detalhe.

    Regra de sigilo (CONTEXT §4.6): nenhum texto AQUI cita a plataforma de
    venda. Isso é conferido por `assertSemOrigem` nos quatro estados — a
    tela de consentimento do próprio Bling está fora do nosso controle
    (D-03), mas o que ESTA blade escreve continua sob a regra.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Conectar o estoque — ECF Consultoria</title>
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
        .brand { color: #ffe600; }

        .btn {
            display: inline-block; margin-top: 28px; padding: 13px 28px;
            background: #ffe600; color: #050507; font-size: 15px; font-weight: 600;
            border-radius: 10px; text-decoration: none;
        }
        .btn:hover { background: #e6cf00; }
        .hint { margin-top: 24px; font-size: 13px; color: rgba(255,255,255,0.3); }
    </style>
</head>
<body>
    <div class="card" data-estado="{{ $estado }}">
        @if($estado === 'convite')
            <div class="icon">🔗</div>
            <h1>Conectar o estoque de <span class="brand">{{ $empresa_nome }}</span></h1>
            <p>A ECF vai ler os produtos e o estoque cadastrados no seu sistema de gestão (Bling) para manter o seu cadastro sempre em dia. A autorização é feita dentro do seu próprio Bling — nenhuma senha passa pela nossa equipe.</p>
            <a class="btn" href="{{ $button_url }}">{{ $button_label }}</a>

        @elseif($estado === 'concluido')
            <div class="icon">✅</div>
            <h1>Conexão concluída com sucesso!</h1>
            <p>O estoque de <strong class="brand">{{ $empresa_nome }}</strong> foi conectado ao sistema da ECF Consultoria.</p>
            <p class="hint">Você pode fechar esta aba.</p>

        @elseif($estado === 'nao_configurado')
            <div class="icon">⏳</div>
            <h1>Essa conexão ainda não está disponível</h1>
            <p>A equipe da ECF já foi avisada e vai liberar esta conexão em breve. Não é necessário fazer nada agora.</p>
            <p class="hint">Você pode fechar esta aba e tentar novamente mais tarde.</p>

        @else
            <div class="icon">❌</div>
            <h1>Não foi possível concluir a conexão</h1>
            <p>{{ $mensagem ?? 'Ocorreu um erro inesperado.' }}</p>
            <p class="hint">Você pode fechar esta aba e tentar novamente pelo link enviado.</p>
        @endif
    </div>
</body>
</html>
