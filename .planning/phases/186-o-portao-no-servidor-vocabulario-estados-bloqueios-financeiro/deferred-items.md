
## 15/10/2026 — gate `guarda-sem-enum-sem-try` do plano 186-04: colisão de homônimo (PRÉ-EXISTENTE)

Achado durante o plano **186-08**, ao reexecutar os 33 gates do `contagens-186-04.txt` depois de
elevar as transições para 13 pares. ⚠️ **NÃO é regressão do 186-08** — nenhum arquivo meu tem
método com esse nome.

```
[guarda-sem-enum-sem-try] REPROVOU: nao casou 'Tests: 1 passed (20 assertions)'.
                          medido:   Tests: 2 passed (44 assertions)
```

**Causa:** o filtro do manifesto é
`test_guarda_a_migration_nao_usa_enum_nem_try_catch_e_o_up_e_idempotente`, e o `--filter` do
PHPUnit é regex **não ancorado** ⇒ ele casa **dois** métodos, em arquivos de planos diferentes:

- `tests/Feature/Publicador/Aprovacao/OfertaDoPortaoTest.php` → `…_idempotente`
- `tests/Feature/Publicador/Aprovacao/MigrationDoPortaoGuardaTest.php` → `…_idempotente_por_tabela`

É exatamente a armadilha "nome de método de guarda genérico COLIDE entre planos: o `--filter` casa
homônimos de vários arquivos e SOMA contagens". O gate continua **verde nos dois testes** (2 passed,
0 failed) — o que está errado é a **contagem do manifesto**, que mede dois arquivos achando que mede
um.

**Conserto (de quem for dono daqueles dois planos):** ancorar o filtro (`…_idempotente$` não é
possível no formato atual) ou **dar sufixo próprio** a um dos dois métodos e remedir a linha.
⚠️ **Não toquei**: é manifesto e arquivo de outro plano, e não foi este plano que quebrou.
