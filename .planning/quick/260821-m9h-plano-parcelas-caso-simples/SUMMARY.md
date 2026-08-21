---
quick_id: 260821-m9h
slug: plano-parcelas-caso-simples
date: 2026-08-21
status: complete
---

# Emitir `{{plano_parcelas}}` com a frase do caso simples — Resumo

## O que foi feito

**Tarefa 1 — a variável** (`app/Services/Clicksign/ContratoVariaveisModeloService.php`):

- Nova constante pública `PLANO_PARCELAS_CASO_SIMPLES` com a frase do contrato
  KIVE real (caso 1, sem personalização): *"As parcelas seguirão a faixa
  apurada na forma da Cláusula 2.1.2."* Docblock registra que é o caso simples
  deliberado (não a solução final do parcelamento escalonado) e que o texto é
  acoplado ao modelo de Gestão (Cláusula 2.1.2 daquele `.docx`).
- Nova entrada `'plano_parcelas' => fn () => self::PLANO_PARCELAS_CASO_SIMPLES`
  no `mapa()`, no mesmo estilo das vizinhas — closure pura, sem consultar
  `ContratoServico`, `Http`, `Storage`, `Log` ou `Cache`. A classe continua
  puramente determinística (T-126-40 preservado).
- Chave conferida caractere a caractere contra `{{plano_parcelas}}` do `.docx`
  já cadastrado (T-126-38).

**Tarefa 2 — testes:**

- `tests/Feature/Phase126/ContratoVariaveisModeloTest.php`: dois testes novos —
  `montar()` emite a frase exata em `plano_parcelas`, e `nomes()` inclui a
  chave.
- `tests/Feature/Phase126/ClicksignSondarModeloTest.php`: teste de confronto
  (`tabela_de_confronto_lista_ok_faltando_e_sobrando`) não precisou de
  mudança de asserção — as strings verificadas (`ok`, `faltando no .docx`,
  `sobrando no .docx`, etc.) continuam presentes, já que `plano_parcelas` cai
  naturalmente em "faltando no .docx" (o `.docx` de teste da fixture não
  inclui a variável nova). Só o **comentário explicativo** foi corrigido: já
  estava desatualizado antes deste quick (dizia "As 10 variáveis" quando já
  eram 14); agora reflete as 15 variáveis atuais.

## Fora de escopo (confirmado, não tocado)

- Consolidação de linhas duplicadas de `ContratoServico`, soma de valores,
  campo editável na tela, guarda `servicos_duplicados`, `.env`, VPS,
  Clicksign, `servicos.clicksign_template_id`.
- Nenhuma mudança em JSX — `npm run build` não foi necessário.
- Nenhum deploy realizado.

## Commits

- `0746f698` — `feat(clicksign): emite {{plano_parcelas}} com frase do caso simples`
  (`app/Services/Clicksign/ContratoVariaveisModeloService.php`)
- `53dae225` — `test(clicksign): cobre plano_parcelas e ajusta confronto do sondar-modelo`
  (`tests/Feature/Phase126/ContratoVariaveisModeloTest.php`,
  `tests/Feature/Phase126/ClicksignSondarModeloTest.php`)

## Gate

```
C:\xampp\php\php.exe vendor/bin/phpunit --filter="Phase126|Phase127|Phase131"
```

**Resultado: OK — Tests: 311, Assertions: 1079** (332 PHPUnit Deprecations
pré-existentes, não relacionadas a este quick — nenhuma menção aos arquivos
tocados aqui na saída de `--display-deprecations`).

## Árvore compartilhada

Só os 3 arquivos tocados neste quick foram adicionados/commitados, por path
explícito (`git add <arquivo>`, nunca `-A`/`.`/`-a`). O arquivo não rastreado
`tests/Feature/CompanyPortfolioAccessTest.php` (presente antes deste quick,
de outra sessão/dev) foi deixado intocado.
