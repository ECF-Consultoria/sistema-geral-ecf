<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesquisa de Satisfação ECF — {{ $mesReferencia }}</title>
</head>
{{--
    Template email NPS mensal — Phase 32 Plan 01.

    Arquitetura table-based (compatível com Outlook 2007+) com fundo dark do
    ECF (#0f1116) no header + card branco com o conteúdo customizável.

    Variáveis recebidas do Mailable (renderizadas via NpsTextRenderer no
    NpsDispararMensal — vars já vêm com placeholders substituídos):

      - $saudacaoRender   (HTML safe via renderHtml — usado com {!! !!})
      - $corpoRender      (HTML safe via renderHtml — usado com {!! !!})
      - $ctaRender        (texto puro via render — texto do botão)
      - $assinaturaRender (HTML safe via renderHtml — usado com {!! !!})
      - $linkPesquisa     (URL absoluta do route('nps.respond', $token))
      - $mesReferencia    (string pt-BR "junho/2026" para o <title>)

    Fonte Montserrat com fallback Helvetica — clientes de email não baixam
    Google Fonts; quem tem Montserrat instalado (raros) usa, demais caem
    pra Helvetica (visualmente aceitável).
--}}
<body style="margin:0;padding:0;background:#f3f4f6;font-family:'Helvetica',Arial,sans-serif;color:#050507;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;">
        <tr>
            <td align="center" style="padding:24px 12px;">

                {{-- Header dark com o logo ECF --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#0f1116;border-radius:8px 8px 0 0;">
                    <tr>
                        <td align="center" style="padding:32px 24px;">
                            @include('partials.logo-ecf', ['theme' => 'dark'])
                        </td>
                    </tr>
                </table>

                {{-- Card branco com o conteúdo customizável --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border-radius:0 0 8px 8px;">
                    <tr>
                        <td style="padding:32px 28px;">

                            {{-- Saudação (renderHtml — já escapada + nl2br) --}}
                            <div style="font-size:18px;font-weight:600;color:#050507;margin:0 0 16px 0;">
                                {!! $saudacaoRender !!}
                            </div>

                            {{-- Corpo (renderHtml — já escapado + nl2br) --}}
                            <div style="font-size:15px;line-height:1.6;color:#1f2937;margin:0 0 28px 0;">
                                {!! $corpoRender !!}
                            </div>

                            {{-- CTA centralizado --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="padding:8px 0 24px 0;">
                                        <a href="{{ $linkPesquisa }}"
                                           style="display:inline-block;background:#ffe600;color:#050507;padding:14px 32px;border-radius:6px;text-decoration:none;font-weight:700;font-size:15px;font-family:'Helvetica',Arial,sans-serif;">
                                            {{ $ctaRender }}
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            {{-- Link cru abaixo do botão (acessibilidade + clientes que removem botões) --}}
                            <p style="font-size:12px;color:#6b7280;text-align:center;margin:0 0 24px 0;word-break:break-all;">
                                Ou copie e cole no navegador:<br>
                                <a href="{{ $linkPesquisa }}" style="color:#6b7280;text-decoration:underline;">{{ $linkPesquisa }}</a>
                            </p>

                            {{-- Assinatura (renderHtml — já escapada + nl2br) --}}
                            <div style="border-top:1px solid #e5e7eb;padding-top:18px;margin-top:8px;font-size:13px;color:#4b5563;line-height:1.6;">
                                {!! $assinaturaRender !!}
                            </div>

                        </td>
                    </tr>
                </table>

                {{-- Rodapé legal/contextual --}}
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
                    <tr>
                        <td align="center" style="padding:16px 24px;font-size:11px;color:#9ca3af;">
                            Pesquisa enviada automaticamente pelo ECF Admin — {{ $mesReferencia }}
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>

</body>
</html>
