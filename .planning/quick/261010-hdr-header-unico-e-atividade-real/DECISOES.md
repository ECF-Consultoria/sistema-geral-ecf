# Decisão do usuário — desenho do cabeçalho único (10/10/2026)

Escolhido entre três propostas: **"Identidade grande + abas em pílula"**.

```
┌────────────────────────────────────────────────────────────┐
│ Publicador › Gestão › Dev 02 Testes API                    │
│                                                            │
│ ┌──┐                                                       │
│ │BT│  Dev 02 Testes API            [Sincronizar] [+ Prod]  │
│ └──┘  company-459  ● Conectada  ⇄ Bling  1.420 SKUs        │
│       Trocar empresa ▾                                     │
│                                                            │
│ ╭──────────────────────────────────────────╮    Config. ⚙  │
│ │ ▰ Visão geral │ Produtos 22 │ Publica… │ │               │
│ ╰──────────────────────────────────────────╯               │
└────────────────────────────────────────────────────────────┘
```

## O que isso determina

1. **A conta ocupa a primeira linha com destaque.** Iniciais num quadrado, **nome em 30px**
   (hoje são 24px), chave em mono, e os selos de estado na linha de baixo.
2. **As abas viram um grupo segmentado em pílula**, logo abaixo da identidade — no mesmo
   vocabulário visual dos chips de filtro do Stitch, não as abas sublinhadas de hoje.
3. **"Configurações" fica à direita**, fora da pílula, como já é hoje.
4. **O slot de ações da tela** (Sincronizar do Portal, + Produto, Atualizar agora…) fica à
   direita da primeira linha.
5. **A trilha continua** acima de tudo.

## Restrições que seguem valendo

⚠️ O cabeçalho vale para **9 páginas**: `AnunciarMassa`, `AnunciarML`, `AnunciosHistorico`,
`MeusAnuncios` e as cinco do Publicador. Algumas **não têm Company** — as abas de Publicações e
o "Editar em grade" nascem **desabilitadas com explicação** (D23), nunca escondidas.

⚠️ O segmentado **No ar / Histórico** dentro da aba Publicações continua existindo.

⚠️ A contagem de produtos na aba Produtos continua.

⚠️ O amarelo da aba ativa é o **translúcido** do módulo, não o sólido — é gate
(`publicador-entrada.test.js:53`), não escolha de layout.

## Tipografia (pedido 2 do usuário)

> *"o layout questão de fontes poderia melhorar um pouco, aumentando as fontes, principalmente
> as escritas principais como nome da loja"*

Nome da conta **24px → 30px**; abas **15px → 16px**; trilha e rótulos de apoio um degrau acima.
⚠️ Subir a escala **proporcionalmente**, não um número solto. O gate de design confere tamanhos:
**atualizar o gate** para a escala nova, nunca afrouxá-lo.
