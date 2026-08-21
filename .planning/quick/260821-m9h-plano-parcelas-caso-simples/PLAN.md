---
quick_id: 260821-m9h
slug: plano-parcelas-caso-simples
date: 2026-08-21
status: complete
---

# Emitir `{{plano_parcelas}}` com a frase do caso simples

## Problema

O modelo Clicksign do serviço **Gestão** (`d030a010-54d8-4873-a03a-73cee8eb1cf7`) foi
substituído hoje pelo `.docx` do jurídico, que contém `{{plano_parcelas}}`. O sistema
**não emite** essa variável.

A Clicksign **não acusa erro** para variável não preenchida — substitui por vazio. Hoje,
em produção, a Cláusula 6ª §1º de um contrato de Gestão sairia assim, sem a frase do
parcelamento:

    "...com vencimento no dia 24 de cada mês. §2º. A CONTRATADA emitirá..."

**Alcance medido em produção:** só o serviço Gestão aponta para esse modelo. Os outros 8
usam o modelo global (`68c524fd-...`), que não tem a variável.

## Por que uma frase constante resolve 100% dos casos geráveis hoje

A guarda `servicos_duplicados` (commit `5af2b4d1`, deployado hoje) **recusa** gerar
contrato quando o mesmo serviço aparece em mais de um `ContratoServico` ativo — que é
exatamente a forma do pagamento escalonado (dois itens de linha do mesmo serviço).

Logo, **todo contrato que consegue ser gerado hoje tem uma linha só por serviço = caso
simples**. O caso escalonado segue barrado, com explicação na tela, até a implementação
completa (consolidar as duas linhas + compor a frase + campo editável), que **não é
escopo aqui**.

## Tarefa 1 — a variável

Em `app/Services/Clicksign/ContratoVariaveisModeloService.php`:

1. `public const` com a frase, nome autoexplicativo, e comentário pt-BR registrando:
   - que é o **caso simples deliberado**, não a solução final;
   - que o texto é **acoplado ao modelo de Gestão** — cita "Cláusula 2.1.2", numeração
     daquele `.docx`. Se outro serviço passar a usar `{{plano_parcelas}}`, a frase estará
     errada.

   Texto (do contrato KIVE real, caso sem personalização — `contrato-kive-ESPECIFICACAO-VARIAVEIS.md`,
   §"Os três casos", caso 1):

       As parcelas seguirão a faixa apurada na forma da Cláusula 2.1.2.

2. Uma entrada nova no `mapa()`, chave `plano_parcelas`, no estilo do arquivo (uma chave
   por linha, closure, comentário pt-BR explicando a origem — igual às vizinhas
   `data_primeira_parcela` e `dia_vencimento`).

### Restrições não negociáveis

- ⚠️ A classe é **PURA por decisão** (T-126-40, docblock do topo): não consulta
  `ContratoServico`, `Http`, `Storage`, `Log` nem `Cache`. Mesma entrada, mesma saída.
  **Não quebrar isso.**
- ⚠️ T-126-38 (docblock da classe): renomear variável faz ela **sumir do contrato
  assinado sem erro de API**. A chave tem que ser exatamente `plano_parcelas`, batendo
  com o `{{plano_parcelas}}` do `.docx` já cadastrado. Não abreviar, não pluralizar
  diferente.
- **Fora de escopo:** consolidar linhas, somar valores, campo na tela, mexer na guarda de
  duplicados, `.env`, VPS, Clicksign, `servicos.clicksign_template_id`.

## Tarefa 2 — testes

- `montar()` emite `plano_parcelas` com a frase esperada.
- `nomes()` inclui `plano_parcelas`.
- ⚠️ `tests/Feature/Phase126/ClicksignSondarModeloTest.php` (~linha 275) monta um `.docx`
  de teste com lista explícita e confronta com `nomes()`. A variável nova muda o resultado
  de "faltando no .docx" — **conferir e ajustar**, junto com o comentário obsoleto que diz
  "As 10 variáveis" (já eram 14, vão para 15).
- Regressão zero nas outras 14 variáveis.

## Gate

`C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase126|Phase127|Phase131"` verde.

## Restrições operacionais

- ⚠️ **Árvore compartilhada** (outro dev + outras abas): nunca `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os próprios paths.
- ⚠️ **Antes de commitar:** `git status --porcelain app/ tests/` **sem**
  `--untracked-files=no`, e conferir os `??` — já houve 500 em produção por classe nova
  nunca commitada.
- ⛔ **Não fazer deploy.**
- Sem mudança de JSX → não precisa `npm run build`.
- Comentários em pt-BR. Commits atômicos.
