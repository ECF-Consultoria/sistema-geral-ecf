# Fase 179 — itens descobertos e NÃO corrigidos (fora do escopo)

Cada item aqui foi **medido**, não suposto, e deixado de fora porque a causa está em arquivo que
não é desta fase (ou de outro plano dela, já commitado). Nenhum é regressão desta fase.

---

## 1. N+1 por publicação em `PainelVisaoGeralService::resolverAtor()` — descoberto no plano 179-05

**Onde:** `app/Services/Publicador/PainelVisaoGeralService.php:136`

```php
'nome' => User::find($classificado['id'])?->name ?? self::QUEM_EQUIPE_SEM_NOME,
```

**O que foi medido** (SQLite, 2ª visita à Visão geral, `tests/Feature/Publicador/VisaoGeralCockpitTest`):

| Cenário | Consultas da página | `select * from users where id = ?` |
|---|---|---|
| 3 produtos · **2** publicações | **65** | 6x |
| 12 produtos · **2** publicações | **65** | 6x |
| 12 produtos · **12** publicações | **91** | 29x |

➡️ **A página NÃO cresce com a quantidade de produtos** (65 → 65, acréscimo zero: é o que o gate
`test_a_pagina_tem_teto_de_consultas_e_nao_cresce_com_a_quantidade_de_produto` trava). Ela cresce
com a quantidade de **publicações**, ~2 consultas por publicação.

**Por que é pré-existente e não desta fase:** `resolverAtor()` é privado e chamado por
`publicadosRecentes()` e `ultimasPublicacoes()` — as duas existem desde a **Fase 173** e são
chamadas **uma vez cada** na action, antes e depois do plano 179-05. As props novas `recentes` e
`atividade` recebem os **arrays já calculados** e não fazem nenhuma consulta de usuário.
`publicadosRecentes()` é o caso sem teto: ele varre **todas** as publicações da conta.

**Por que não foi corrigido aqui:** `PainelVisaoGeralService.php` é propriedade do plano **179-02**,
já commitado, e `publicadosRecentes()`/`ultimasPublicacoes()` servem comportamento **aprovado em
produção**. A regra de escopo deste executor é só auto-corrigir o que a própria tarefa causou.

**Como ficaria a correção, para quem pegar:** o arquivo **já tem o padrão certo** 840 linhas abaixo
— `atividadeDaEquipe()` resolve os nomes em **uma** consulta
(`User::whereIn('id', $ids)->pluck('name', 'id')`, `:976`) e o docblock de `:860` até declara
*"Nunca um `User::find()` por linha"*. A correção é levar esse mesmo padrão para
`publicadosRecentes()`/`ultimasPublicacoes()`: colher os `ator.id` de todas as linhas, resolver em
um `whereIn`, e passar o mapa para `resolverAtor()`. **Nenhuma mudança de contrato** — o valor de
saída é o mesmo.

⚠️ **Travado por teste:** `test_a_pagina_ainda_cresce_com_as_publicacoes_e_isso_e_divida_pre_existente`
cobra `< 120` consultas com 12 publicações **e** prova, por comparação, que o crescimento é por
publicação e não por produto. Quem corrigir deve baixar o teto no mesmo commit.

**Impacto em produção:** não medido — a leitura de produção está bloqueada nesta sessão. A conta
com mais publicações do Publicador é o pior caso, e a Visão geral é a primeira tela do módulo.
