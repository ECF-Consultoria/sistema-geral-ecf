<!DOCTYPE html>
<html lang="pt-BR">
<head>
    {{-- UTF-8 obrigatório para acentuação pt-BR no Dompdf — mesmo padrão do precedente
         RelatorioMensalPdfService (resources/views/emails/relatorios/mensal-pdf.blade.php) --}}
    <meta charset="UTF-8">
    <title>Contrato de Prestação de Serviços</title>
    <style>
        /* CSS inline — o Dompdf não interpreta classes Tailwind (mesmo padrão do precedente) */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        /* DejaVu Sans: fonte padrão do Dompdf com suporte UTF-8 completo (acentuação pt-BR) */
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #050507;
            background: #ffffff;
        }

        .container { width: 100%; padding: 24px; }

        .header { border-bottom: 3px solid #ffe600; padding-bottom: 10px; margin-bottom: 18px; }
        .header h1 { font-size: 16px; font-weight: bold; }
        .header .meta { font-size: 9px; color: #666; margin-top: 4px; }

        /* Cada bloco lógico fica isolado em .section — nenhuma cláusula é cortada
           no meio de uma página (Success Criteria 5 / RESEARCH §Pattern 3) */
        .section { margin-bottom: 18px; page-break-inside: avoid; }
        .section h2 {
            font-size: 12px;
            font-weight: bold;
            border-bottom: 1px solid #ffe600;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }

        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        th { background: #0f1116; color: #ffe600; text-align: left; padding: 6px 8px; }
        td { padding: 6px 8px; border-bottom: 1px solid #eaeaea; vertical-align: top; }

        /* table-layout: fixed nas tabelas que recebem dado de comprimento variável do banco
           (razão social, endereço, nome do contato) — sem isto, nome de empresa extremo
           estoura a largura da coluna e desalinha o layout (RESEARCH §Pitfall 3) */
        .tabela-dados-variaveis { table-layout: fixed; }

        /* O Dompdf não quebra palavra longa sozinho como um browser faria — sem isto,
           um nome de empresa de 80+ caracteres sem espaço estoura a célula */
        .quebra-palavra {
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .assinaturas { margin-top: 30px; }
        .assinatura-linha { margin-top: 40px; border-top: 1px solid #050507; width: 60%; padding-top: 4px; font-size: 10px; }

        .footer { border-top: 1px solid #eaeaea; padding-top: 8px; margin-top: 20px; color: #999; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h1>Contrato de Prestação de Serviços</h1>
        <div class="meta">Gerado em {{ $dados['gerado_em'] }}</div>
    </div>

    {{-- ═══ 1. QUALIFICAÇÃO DAS PARTES (resumo — qualificação completa está nas cláusulas) ═══ --}}
    <div class="section">
        <h2>1. Qualificação das partes</h2>
        <table class="tabela-dados-variaveis">
            <tr>
                <td style="width: 28%;"><strong>Contratante</strong></td>
                <td class="quebra-palavra">
                    {{ $dados['empresa']['razao_social'] }}, inscrita no CNPJ sob o nº
                    {{ $dados['empresa']['cnpj'] }}, com endereço em {{ $dados['empresa']['endereco'] }},
                    doravante denominada CONTRATANTE.
                </td>
            </tr>
            <tr>
                <td><strong>Contato</strong></td>
                <td class="quebra-palavra">
                    {{ $dados['contato']['nome'] }} — {{ $dados['contato']['email'] }} — {{ $dados['contato']['telefone'] }}
                </td>
            </tr>
            <tr>
                <td><strong>Contratada</strong></td>
                <td class="quebra-palavra">
                    ECF Consultoria, doravante denominada CONTRATADA, na forma da qualificação completa
                    descrita nas cláusulas deste contrato.
                </td>
            </tr>
        </table>
    </div>

    {{-- ═══ 2. OBJETO ═══ --}}
    <div class="section">
        <h2>2. Objeto</h2>
        <p>
            O presente contrato tem por objeto a prestação, pela CONTRATADA à CONTRATANTE, dos serviços
            listados na cláusula seguinte, conforme as condições e os valores nela especificados.
        </p>
    </div>

    {{-- ═══ 3. SERVIÇOS E VALORES ═══ --}}
    <div class="section">
        <h2>3. Serviços e valores</h2>
        <table>
            <thead>
                <tr>
                    <th>Serviço</th>
                    <th>Início</th>
                    <th>Término</th>
                    <th style="text-align:right">Valor mensal</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($dados['servicos'] as $servico)
                    <tr>
                        <td>{{ $servico['servico'] }}</td>
                        <td>{{ $servico['inicio'] }}</td>
                        <td>{{ $servico['fim'] }}</td>
                        <td style="text-align:right">{{ $servico['valor_formatado'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Nenhum serviço no snapshot.</td></tr>
                @endforelse
            </tbody>
        </table>
        <p style="margin-top: 8px;"><strong>Valor mensal total: {{ $dados['totais']['valor_mensal_formatado'] }}</strong></p>
    </div>

    {{-- ═══ 4. VIGÊNCIA ═══ --}}
    <div class="section">
        <h2>4. Vigência</h2>
        <p>
            Este contrato vigora de {{ $dados['vigencia']['inicio'] }} até {{ $dados['vigencia']['fim'] }},
            podendo ser renovado conforme cláusula própria.
        </p>
    </div>

    {{-- ═══ 5. CONDIÇÕES DE PAGAMENTO ═══ --}}
    <div class="section">
        <h2>5. Condições de pagamento</h2>
        <p>
            Vencimento: {{ $dados['pagamento']['dia_vencimento'] }}. Forma de pagamento:
            {{ $dados['pagamento']['forma_pagamento'] }}.
        </p>
    </div>

    {{-- ═══ CLÁUSULAS — texto jurídico isolado (D-01), editável sem tocar em código ═══ --}}
    @include('contratos.clausulas', ['dados' => $dados])

    {{-- ═══ ASSINATURAS ═══ --}}
    <div class="section assinaturas">
        <h2>Assinaturas</h2>
        <div class="assinatura-linha quebra-palavra">CONTRATANTE — {{ $dados['empresa']['razao_social'] }}</div>
        <div class="assinatura-linha">CONTRATADA — ECF Consultoria</div>
    </div>

    <div class="footer">
        Documento gerado eletronicamente pelo ECF Admin. Assinatura digital via Clicksign.
    </div>

</div>
</body>
</html>
