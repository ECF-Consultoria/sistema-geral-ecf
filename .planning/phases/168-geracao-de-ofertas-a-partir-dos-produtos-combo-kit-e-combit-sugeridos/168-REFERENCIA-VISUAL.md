# Fase 168 — Referência visual da tela "Sugestões de ofertas" (pedido do usuário, 07/10/2026)

O usuário mandou o mockup `168-REF-1-sugestoes-de-ofertas.png` com um texto longo de instruções. A resposta ao
checkpoint do 168-16 não foi "aprovado", e sim um pedido de redesenho. As decisões estão em D-24..D-31 do
`168-CONTEXT.md`.

## Regra de ouro (palavras do usuário)

**A imagem define o layout, a hierarquia, a organização, as proporções, os espaçamentos e a aparência.** O
resultado tem de ficar o mais próximo possível dela. Comparando lado a lado, tem de ser "claramente o MESMO
conceito e praticamente o mesmo layout", sem copiar pixel.

A imagem **não** define: lógica de negócio, regras, endpoints, cálculos, geração, aceite, descarte, filtros ou
integrações. O trabalho é FRONT-END, UX e organização visual. Os números da imagem (181, 46, 92, 43, 12, 8,
preços, SKUs, famílias, imagens) são EXEMPLOS; a tela usa os valores reais. "Se alguma decisão visual da imagem
entrar em conflito com funcionalidade real existente, preserve a funcionalidade e adapte o layout da forma mais
próxima possível."

## Estrutura, de cima para baixo

1. **Cabeçalho compacto.**
   - Trilha "Produtos > Sugestões de ofertas".
   - Título "Sugestões de ofertas".
   - Subtítulo "O sistema encontrou combinações possíveis de produtos para aumentar suas vendas."
   - À direita, "● Atualizado agora" com ponto verde pequeno.
2. **Quatro cartões de resumo, numa linha**, todos com a mesma altura, baixos, número em destaque, descrição
   pequena, ícone num círculo suave e borda discreta:
   - total de sugestões, "Total de combinações encontradas", azul;
   - Combos, "N% do total", roxo;
   - Kits, "N% do total", verde;
   - Combits, "N% do total", amarelo.
3. **Uma barra de filtros num único container horizontal:**
   - busca (o campo mais largo);
   - Família;
   - [Ambiente na imagem → ver D-27];
   - "Tipo de sugestão" como controle segmentado (Todos/Combo/Kit/Combit). O selecionado tem borda e texto
     amarelos e fundo levemente destacado;
   - Status;
   - botão secundário "↻ Atualizar sugestões", com fundo transparente, borda, ícone e texto amarelos (NÃO
     preenchido).
4. **Abas** "Pendentes N · Sem tipo N · Descartadas N".
   - A ativa tem texto claro, contador amarelo/dourado e linha amarela embaixo; as outras ficam secundárias.
   - Na mesma linha, à direita: "[ ] N selecionadas" e o CTA principal "✓ Aceitar selecionadas" (amarelo, texto
     escuro, largura confortável).
5. **Grupos.** Cada família fica num container próprio, com fundo escuro, borda discreta, raio e padding pequeno.
   - Cabeçalho dentro do container: ícone pequeno, família em negrito · ambiente, badge "N sugestões" e, à
     direita, "[ ] Selecionar todas" e um chevron para recolher e expandir.
6. **Cada sugestão é UMA LINHA horizontal compacta**, "uma tabela visual enriquecida" e não vários cartões soltos:
   `| seleção | imagens | tipo | nome/SKU | composição | motivo + logística + frete | ações |`
   - **Seleção:** checkbox; marcado fica com fundo amarelo e check escuro. A linha marcada ganha uma borda
     amarela discreta, sem pintar a linha.
   - **Imagens:** uma imagem principal maior e, ao lado, miniaturas dos componentes. Fundo claro, raio pequeno,
     proporção constante, sem distorção.
   - **Tipo:** badge pequena COMBO (roxo/azul), KIT (verde) ou COMBIT (amarelo/dourado).
   - **Nome e SKU:** "Nome sugerido ✎" e "SKU sugerido ✎" com aparência de texto. Ao clicar, vira input
     (edição em linha), sem prejudicar o fluxo atual.
   - **Composição:** uma linha por componente, "[ícone/foto] 4x Cadeira (SKU)", com a quantidade primeiro e o SKU
     entre parênteses.
   - **Motivo:** o dado REAL que o sistema gera; nada de motivo inventado.
   - **Logística e frete**, abaixo do motivo: "[ícone] ME2" e, ao lado, "[caminhão] Frete estimado R$ …",
     separados por espaço ou divisória vertical, sem virar mini-cartões.
   - **Ações** à direita: [Descartar] secundário (fundo escuro, borda azul/cinza) e [Aceitar] amarelo preenchido.
     Os dois botões têm a mesma altura.
7. **Densidade alta com boa leitura.** Nada de alturas, paddings, miniaturas, botões e títulos grandes demais,
   mas sem espremer.
8. **Hierarquia visual:** combinação > nome > tipo > composição > motivo > frete/logística > SKU.
9. **Cores:** fundo escuro, cartões um tom acima, bordas muito sutis, amarelo como ação. Os HEX navy da imagem
   são aproximados: "PREFERIR os tokens já existentes no sistema".
10. **Responsivo:** a prioridade é o computador, mas sem rolagem horizontal no celular.
    - Filtros e métricas quebram em grade, e a linha da sugestão se reorganiza na vertical.
    - Nenhuma função some no celular.

## Tem de continuar funcionando (lista do usuário)

- carregamento das sugestões, nas três fases;
- agrupamento;
- filtros, busca, status e tipo;
- as três abas;
- seleção: uma, várias, todas do grupo;
- aceitar e aceitar selecionadas;
- descartar;
- editar nome e SKU;
- composição, motivo, logística e frete;
- atualizar e contadores;
- voltar do navegador e confirmação de alterações não salvas;
- comportamento das aceitas e descartadas.
