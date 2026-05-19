<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidade - Painel ECF</title>
    <meta name="description" content="Política de Privacidade da extensão Painel ECF para Chrome.">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.7;
            color: #1f2937;
            max-width: 760px;
            margin: 0 auto;
            padding: 48px 24px;
            background: #ffffff;
        }
        header {
            border-bottom: 2px solid #fbbf24;
            padding-bottom: 20px;
            margin-bottom: 32px;
        }
        h1 {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 6px;
            color: #111827;
        }
        .subtitle {
            color: #6b7280;
            font-size: 14px;
            margin: 0;
        }
        h2 {
            font-size: 18px;
            font-weight: 600;
            margin: 32px 0 10px;
            color: #111827;
        }
        p { margin: 0 0 14px; }
        ul { margin: 0 0 14px; padding-left: 22px; }
        li { margin-bottom: 6px; }
        .effective {
            background: #f9fafb;
            border-left: 3px solid #fbbf24;
            padding: 10px 14px;
            margin: 24px 0;
            font-size: 14px;
            color: #4b5563;
        }
        footer {
            margin-top: 48px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 13px;
            color: #6b7280;
        }
        a { color: #0284c7; }
    </style>
</head>
<body>
    <header>
        <h1>Política de Privacidade</h1>
        <p class="subtitle">Painel ECF — Extensão para Chrome</p>
    </header>

    <div class="effective">
        Última atualização: {{ date('d \d\e F \d\e Y', strtotime('2026-05-16')) }}
    </div>

    <p>
        Esta Política de Privacidade descreve como a extensão <strong>Painel ECF</strong>
        (ID: <code>eaofkkacbkmiialocohbibjalhkbmcib</code>) trata as informações dos usuários.
    </p>

    <h2>1. Coleta de dados</h2>
    <p>
        A extensão <strong>não coleta, armazena ou compartilha dados pessoais</strong> dos
        usuários. Nenhuma informação de identificação pessoal é enviada a servidores externos
        ou a terceiros.
    </p>

    <h2>2. Finalidade da extensão</h2>
    <p>
        A extensão é utilizada apenas para fornecer funcionalidades de automação, geração de
        prompts e melhorias de produtividade dentro das plataformas <strong>ChatGPT</strong> e
        <strong>Gemini</strong>.
    </p>

    <h2>3. Armazenamento local</h2>
    <p>
        Os dados manipulados pela extensão (preferências do usuário, prompts salvos e
        configurações) permanecem <strong>localmente no navegador</strong> do usuário,
        utilizando a API de armazenamento do próprio Chrome. Esses dados não são transmitidos
        para nenhum servidor remoto.
    </p>

    <h2>4. Compartilhamento e venda de dados</h2>
    <p>
        <strong>Nenhuma informação pessoal é vendida, transferida ou utilizada para fins de
        publicidade.</strong> A extensão não integra redes de anúncios, rastreadores de
        terceiros ou analytics que identifiquem o usuário.
    </p>

    <h2>5. Permissões solicitadas</h2>
    <p>
        As permissões requisitadas pela extensão no Chrome Web Store são utilizadas
        exclusivamente para operar dentro das páginas do ChatGPT e do Gemini, conforme a
        descrição publicada na loja. Nenhuma permissão é usada para finalidade diferente da
        declarada.
    </p>

    <h2>6. Alterações nesta Política</h2>
    <p>
        Esta política pode ser atualizada conforme novas funcionalidades sejam adicionadas
        à extensão. Quaisquer mudanças significativas serão refletidas nesta página com
        atualização da data acima.
    </p>

    <h2>7. Contato</h2>
    <p>
        Em caso de dúvidas sobre esta Política de Privacidade ou sobre o funcionamento da
        extensão, entre em contato pelo e-mail do desenvolvedor através do site oficial:
        <a href="https://ecfconsultoria.com.br" target="_blank" rel="noopener">ecfconsultoria.com.br</a>.
    </p>

    <footer>
        &copy; {{ date('Y') }} ECF Consultoria — Painel ECF.
    </footer>
</body>
</html>
