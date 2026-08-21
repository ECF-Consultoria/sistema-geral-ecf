---
created: 2026-08-19T17:40:00.000Z
title: Grant padrão do polo Serra Gaúcha está sob a chave antiga Bento Gonçalves
area: polos
files:
  - app/Models/MlbConfiguracao.php:66-82
  - app/Models/MlbImplementacao.php:83-88
  - tests/Feature/Phase33OnboardingFichaTest.php:467
---

# Grant padrão do polo "Serra Gaúcha" está sob a chave antiga "Bento Gonçalves"

**Criado:** 2026-08-19
**Origem:** encontrado ao investigar uma falha de teste durante o deploy do quick `260819-guy`
— não é regressão daquele trabalho nem do Onboarding v10; é **pré-existente e já está em
produção**
**Criticidade:** média — empresa desse polo recebe mensagem de boas-vindas sem link de Grant

## Problema

O polo foi renomeado na planilha de 2026-07, e o comentário registra a mudança em
`MlbImplementacao::ONB_POLO_OPCOES`:

```php
'Serra Gaúcha', // renomeado de 'Bento Gonçalves' (planilha 2026-07)
```

Mas `MlbConfiguracao::GRANTS_POR_POLO_PADRAO` **continua com a chave antiga**:

```php
'Bento Gonçalves' => [
    'url'  => 'https://partners.mercadolivre.com.br/auth/a5bPc000008oswfIAA',
    'nome' => 'Projeto Polos - Serra Gaúcha',   // o NOME já foi atualizado, a CHAVE não
],
```

Repare que o `nome` dentro do array já diz "Serra Gaúcha" — só a **chave** do mapa ficou para
trás. Quem renomeou atualizou metade.

**Consequência:** a busca do grant por polo é por chave. Para uma empresa em "Serra Gaúcha",
`grants_por_polo['Serra Gaúcha']` não existe, então os placeholders `{link_grant}` e
`{projeto_grant}` da mensagem de boas-vindas não têm de onde sair. O cliente recebe a mensagem
sem o link que o texto diz ser "essencial para conectar sua conta ao projeto e validar
oficialmente sua entrada junto ao Mercado Livre".

## Como foi detectado

`Phase33OnboardingFichaTest::test_padroes_expoem_mensagem_e_grants_padrao` falha com
*"Failed asserting that an array has the key 'Serra Gaúcha'"* — o teste percorre
`ONB_POLO_OPCOES` e exige um grant configurado para cada um. Ele está certo; o dado é que
está errado.

## Solução

TBD — mas confirmar antes de trocar:

1. Se existe configuração **salva no banco** (`mlb_configuracoes`) com a chave antiga em
   produção. `GRANTS_POR_POLO_PADRAO` é só o default; se alguém já editou em "Padrões Globais",
   trocar a constante não conserta o registro salvo — precisa de migração de dado também.
2. Se a URL do grant continua válida para o polo renomeado, ou se o Mercado Livre emitiu um
   link novo junto com o rebranding.

Trocar a constante sem checar o item 1 dá a impressão de resolvido e deixa produção igual.
