# Deferred items — Fase 168

## Flakiness pré-existente na suíte Feature/Phase160-162 + Phase161 (filesystem Windows)

**Descoberto durante:** 168-02, Task 1 (verify do baseline de 228 testes do Creative Engine).

**Não é regressão desta task** — `CreativePromptBuilder` (único arquivo tocado pelo 168-02) não é
referenciado por nenhum dos testes afetados (`tests/Feature/Phase160/CriativoRetencaoTest.php`,
`tests/Feature/Phase161/CriativoKitAprovacaoTest.php`). Confirmado por isolamento: os mesmos testes
passam tanto com o código ANTES quanto DEPOIS da mudança deste plano quando rodados sozinhos
(`--filter`); e, rodando a suíte completa 3 vezes seguidas sem nenhuma mudança de código entre as
rodadas, um teste diferente falhou em cada rodada (ora `CriativoRetencaoTest`, ora
`CriativoKitAprovacaoTest::test_aprovar_slot_fora_de_ordem...`, ora
`CriativoKitAprovacaoTest::test_aprovar_kit_corta_no_limite_da_categoria...`), sempre por erro de
filesystem — `FilesystemIterator` não encontra diretório, ou arquivo de disco fake não encontrado,
ou contagem de `pictures` zerada. Padrão consistente com disco fake do Laravel
(`storage/framework/testing/disks/local`) não sendo limpo/isolado de forma confiável entre testes no
Windows (mistura de separador `/` e `\` no path do erro:
`...disks/local\creative-geradas\...`).

**Não corrigido nesta task** — fora de escopo (SCOPE BOUNDARY: só auto-fix de problemas causados
pelas mudanças desta task). Quem pegar Fase 169+ e precisar rodar a suíte completa do Creative
Engine: rode 2-3 vezes antes de concluir que uma falha é regressão de verdade — há flakiness de
ambiente pré-existente, independente de código.
