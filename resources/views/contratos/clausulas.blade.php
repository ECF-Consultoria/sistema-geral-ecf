{{--
    Cláusulas do contrato de prestação de serviços — Fase 126 (D-01, 126-CONTEXT.md).

    Este arquivo é o PONTO DE EDIÇÃO do texto jurídico do contrato. Trocar o texto aqui
    e fazer deploy muda as cláusulas dos PRÓXIMOS contratos gerados — nunca dos contratos
    já gerados. O que vale juridicamente é o arquivo PDF salvo em disco (D-02): um
    contrato assinado nunca é re-renderizado a partir desta view, então editar este
    arquivo depois de um contrato já ter sido gerado NÃO muda nada do que já foi
    assinado ou enviado ao cliente.

    Nenhuma lógica de dados aqui: este arquivo só recebe o array `$dados`, já pronto,
    vindo de ContratoPdfService::montarDados(). Proibido `@php` com consulta a modelo
    Eloquent, escopo de query ou qualquer acesso direto ao banco — ver o teste estático
    que garante isso em ContratoPdfServiceTest.
--}}

<div class="section">
    <h2>6. Obrigações das partes</h2>
    <p>
        A CONTRATADA se compromete a prestar os serviços descritos na cláusula 3 com zelo,
        diligência técnica e dentro dos prazos acordados, mantendo a CONTRATANTE informada
        sobre o andamento das atividades. A CONTRATANTE se compromete a fornecer as
        informações e os acessos necessários à execução dos serviços, bem como a efetuar
        o pagamento nos termos da cláusula 5.
    </p>
</div>

<div class="section">
    <h2>7. Vigência e renovação</h2>
    <p>
        Este contrato vigora de {{ $dados['vigencia']['inicio'] }} a {{ $dados['vigencia']['fim'] }},
        sendo renovado automaticamente por períodos iguais e sucessivos, salvo manifestação
        em contrário de qualquer das partes, por escrito, com antecedência mínima de 30
        (trinta) dias do término da vigência em curso.
    </p>
</div>

<div class="section">
    <h2>8. Valores e reajuste</h2>
    <p>
        O valor mensal total dos serviços contratados é de {{ $dados['totais']['valor_mensal_formatado'] }},
        conforme detalhado na cláusula 3, podendo ser reajustado anualmente pela variação
        acumulada do IGP-M (FGV) ou índice que vier a substituí-lo, mediante comunicação
        prévia à CONTRATANTE.
    </p>
</div>

<div class="section">
    <h2>9. Rescisão</h2>
    <p>
        Qualquer das partes poderá rescindir este contrato mediante aviso prévio por
        escrito, com antecedência mínima de 30 (trinta) dias, sem prejuízo do pagamento
        pelos serviços já prestados até a data efetiva da rescisão. A rescisão por justa
        causa, decorrente de descumprimento contratual, independe de aviso prévio.
    </p>
</div>

<div class="section">
    <h2>10. Confidencialidade</h2>
    <p>
        As partes se comprometem a manter sigilo sobre todas as informações confidenciais
        trocadas em razão deste contrato, não as divulgando a terceiros sem autorização
        prévia e por escrito da outra parte, obrigação que permanece válida mesmo após o
        término da vigência contratual.
    </p>
</div>

<div class="section">
    <h2>11. Proteção de dados pessoais (LGPD)</h2>
    <p>
        As partes se comprometem a tratar os dados pessoais aos quais tiverem acesso em
        razão deste contrato em conformidade com a Lei nº 13.709/2018 (Lei Geral de
        Proteção de Dados Pessoais), adotando as medidas técnicas e administrativas
        necessárias para proteger tais dados contra acessos não autorizados e situações
        acidentais ou ilícitas de destruição, perda, alteração, comunicação ou qualquer
        forma de tratamento inadequado ou ilícito.
    </p>
</div>

<div class="section">
    <h2>12. Foro</h2>
    <p>
        Fica eleito o foro da comarca da sede da CONTRATADA para dirimir quaisquer dúvidas
        ou controvérsias oriundas deste contrato, com renúncia expressa a qualquer outro,
        por mais privilegiado que seja.
    </p>
</div>

<div class="section">
    <h2>13. Qualificação completa das partes</h2>
    <p class="quebra-palavra">
        CONTRATANTE: {{ $dados['empresa']['razao_social'] }}, pessoa jurídica inscrita no
        CNPJ sob o nº {{ $dados['empresa']['cnpj'] }}, com sede em {{ $dados['empresa']['endereco'] }},
        neste ato representada na forma de seus atos constitutivos.
    </p>
    <p style="margin-top: 6px;">
        CONTRATADA: ECF Consultoria, pessoa jurídica de direito privado, neste ato
        representada na forma de seus atos constitutivos.
    </p>
    <p style="margin-top: 6px;">
        E, por estarem assim justas e contratadas, as partes firmam o presente instrumento.
    </p>
</div>
