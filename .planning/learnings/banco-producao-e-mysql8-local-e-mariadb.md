# Produção é MySQL 8, o local é MariaDB 10.4 — e 7 learnings dizem o contrário

**Leitura obrigatória antes de escrever qualquer migration neste projeto.**

Medido na VPS em 2026-10-10, pela conexão da própria aplicação:

```
produção:  SELECT VERSION()  →  8.0.46-0ubuntu0.24.04.4      ← MySQL 8
local:     SELECT VERSION()  →  10.4.32-MariaDB               ← MariaDB (XAMPP)
```

## Por que isso importa, e por que ninguém notou

**São motores diferentes.** O ritual deste projeto — "provar a migration no banco local com `--path`,
migrate → rollback → migrate, antes do deploy" — valida contra **MariaDB 10.4** e depois roda em
**MySQL 8.0**. É exatamente o mecanismo de "funcionou local, quebrou no deploy".

E o projeto **não sabia**: em 10/10/2026, **7 arquivos de `.planning/learnings/` chamavam a produção de
"MariaDB"** e nenhum mencionava MySQL 8. As memórias de sessão também diziam "MariaDB de prod".

⚠️ **Os learnings sobre os erros 1059, 1830, 1553 e 1093 continuam válidos** — esses números de erro são
herança comum de MySQL e MariaDB e o comportamento descrito lá se aplica aos dois. **O que estava errado
era só o nome do motor.** Não saia reescrevendo aqueles documentos achando que a regra caiu; o que muda é
onde os dois motores **divergem**, abaixo.

## Onde MariaDB 10.4 e MySQL 8.0 divergem e a migration sente

| Assunto | MariaDB 10.4 (local) | MySQL 8.0.46 (produção) |
|---|---|---|
| `$table->json()` | `longtext` + CHECK `json_valid(...)` | **tipo JSON nativo**, sem CHECK |
| Acrescentar CHECK | exige **rebuild** da tabela | não se aplica (não cria CHECK) |
| `ADD COLUMN` INSTANT | só quando a coluna entra **última**; `after()` força rebuild | **qualquer posição desde 8.0.29** — `after()` também é INSTANT |
| Collation padrão | `utf8mb4_general_ci` | `utf8mb4_0900_ai_ci` |

Consequência prática: um raciocínio de performance de ALTER feito "para MariaDB" pode estar certo por
acidente e errado por motivo. Foi o que aconteceu no planejamento da quick `261010-rie` — a decisão
(`longText` nullable, no fim, sem índice) era segura nos dois motores, mas a justificativa citava o
CHECK do MariaDB sobre um servidor MySQL.

## Como proceder a partir daqui

1. **Continue provando no MariaDB local** — ele pega o grosso (sintaxe, idempotência do `up()`, rollback,
   nome de índice longo, FK sem `nullable()`). Não é inútil; é incompleto.
2. **Declare no plano/SUMMARY o que o teste local NÃO cobre.** Para `ADD COLUMN` nullable sem índice e sem
   CHECK, os dois motores são equivalentes e o risco é baixo — **diga isso**, em vez de deixar o leitor
   supor que o teste local cobriu produção.
3. **Desconfie quando a migration usar**: `json()`, CHECK, `after()` com expectativa de INSTANT, collation
   explícita, generated columns, ou `ALTER` que você espera ser por metadado em tabela grande.
4. **Tempo de ALTER medido localmente não vale nada.** Além do motor diferente, a tabela local costuma
   estar vazia. Exemplo: `ml_acervo_itens` tinha **1.080.206 linhas, 1.776 MB de dados e 196 MB de
   índices** (InnoDB) em produção em 10/10, contra **0 linha** no local.
5. Se precisar de certeza sobre produção, **meça lá** — `SELECT VERSION()`, `SHOW TABLE STATUS`,
   `SHOW COLUMNS` pelo `artisan tinker` via `plink`. ⚠️ O `tinker` precisa de `HOME`/`XDG_CONFIG_HOME`
   graváveis (`sudo -u www-data XDG_CONFIG_HOME=/tmp/psy HOME=/tmp/psy php artisan tinker --execute='…'
   < /dev/null`), senão falha com "Writing to directory /var/www/.config/psysh is not allowed".

## O que NÃO foi investigado

Não sei **quando** a produção passou a ser MySQL 8, nem se já foi MariaDB antes — só que em 10/10/2026 ela
é MySQL 8.0.46. Também não auditei migrations antigas em busca de divergências que tenham passado sem
sintoma. Se alguém descobrir o histórico, acrescente aqui.
