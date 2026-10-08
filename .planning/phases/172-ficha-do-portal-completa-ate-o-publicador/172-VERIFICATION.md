---
phase: 172-ficha-do-portal-completa-ate-o-publicador
verified: 2026-10-08T00:00:00Z
status: human_needed
score: 9/9 requisitos verificados no código (itens de produção pendentes como humanos)
overrides_applied: 0
human_verification:
  - test: "Deploy autorizado: integrar origin/main, contar estrutura_produtos / estrutura_produto_variacoes / pub_produtos antes e depois, migrate, queue:restart"
    expected: "Contagens idênticas antes/depois; 4 migrations aditivas aplicadas; workers reiniciados (jobs novos em high e default)"
    why_human: "Só em produção; deploy exige autorização explícita (FP172-04)"
  - test: "GD com suporte a WebP na VPS (conversão WebP -> JPG, D-15)"
    expected: "Foto WebP do portal chega como JPG no rascunho"
    why_human: "Depende da extensão PHP da VPS"
  - test: "Sincronizar do Portal na #459 real, com a IA real, e abrir o rascunho (sem publicar)"
    expected: "Um rascunho por produto com N variações, SKU, estoque, ficha, pacote, fotos; descrição MAG T8 gerada sozinha"
    why_human: "Ponta a ponta com dado e IA reais; conta de cliente nunca publica"
  - test: "Pendência de sigilo da Fase 167: texto 'N anúncios' no cartão da variação da ficha do portal"
    expected: "Decisão do usuário sobre manter ou neutralizar"
    why_human: "Registrado no 172-13-SUMMARY como pendente fora da fase"
---

# Fase 172: Verificação

**Objetivo:** tudo o que o cliente preenche na ficha do portal chega ao Publicador no "Sincronizar do Portal", preenchendo só o vazio e sem nunca publicar.
**Status:** human_needed (nenhuma lacuna encontrada no código)
**Re-verificação:** Não

## Verdades por requisito

| ID | Verdade | Status | Evidência |
|----|---------|--------|-----------|
| FP172-01 | Estoque por variação (anulável, 0 != vazio) | VERIFICADO | migration `2026_10_08_150000` (`unsignedInteger nullable`); testes de GravarLinhas/Normalizador e JS verdes |
| FP172-02 | Descrição por produto na ficha | VERIFICADO | migration `150100` (`text nullable`); `DescricaoDoProdutoTest` dentro da suíte verde |
| FP172-03 | Sigilo cobre campos novos | VERIFICADO | `assertSemOrigem` estendido; suíte `tests/Feature/PortalCliente/Estrutura` 465 OK |
| FP172-04 | Migrations aditivas, idempotentes, com down seguro | VERIFICADO (código) | 4 migrations aditivas lidas (hasColumn, nullable, down só tira vínculo); prova no MariaDB local registrada no baseline. Contagem em prod: item humano |
| FP172-05 | Agrupamento D-06 (N ofertas -> 1 rascunho) | VERIFICADO | `estrutura_produto_id` em `pub_produtos`; `SincronizaPortalAgrupamentoTest`/`CompletoTest` verdes |
| FP172-06 | Sincronizar completo, só-vazio, idempotente | VERIFICADO | `PortalParaRascunhoService` (832 linhas, regra D-05 em todos os blocos, fotos só em grupo vazio, `enviar: false`); `PortalParaRascunhoTest`, `PortalFotosTest`, `PortalCamposDoEditorNoRascunhoTest` verdes |
| FP172-07 | Combo/Kit/Combit derivam o que der | VERIFICADO | `PortalComposicaoNoRascunhoTest`, `ComposicaoDoPortalTest` verdes; estoque nulo quando componente descartado (ajuste pós-visual, coerente com D-07) |
| FP172-08 | Descrição do cliente alimenta MAG T8, automática no rascunho vazio | VERIFICADO | `DescricaoIaService`, `DescricaoIaTest` verde (D-11) |
| FP172-09 | Isolamento entre empresas; sem gate de piloto no Sincronizar (D-10); nada no ML | VERIFICADO | `test_empresa_fora_do_piloto_tambem_e_preenchida_sem_escrever_no_ml`; grep sem `contas_liberadas` no serviço |

## Ajustes pós-plano (conferência visual) versus decisões travadas

Nenhuma contradição. Explicações de campo (`atributo_explicacoes`, aditiva), `ClassificadorAtributos` na ficha (+16 campos, "Mais detalhes", "Não se aplica"), eixo por produto e estoque nulo de kit com componente descartado respeitam D-01..D-16 (sigilo preservado: suíte de sigilo verde; só-vazio e D-10 intactos).

## Execução (por mim, neste worktree)

| Grupo | Resultado |
|-------|-----------|
| `tests/Feature/Publicador` + `tests/Unit/Publicador` | 984 testes, 6459 asserções, OK |
| `tests/Unit/PortalEstrutura` | 218 OK |
| `tests/Feature/PortalCliente/Estrutura` | 465 OK |
| `npm run test:js` | 1357 testes, 2 falhas (as 2 antigas conhecidas, baseline) |

Observação: uma rodada combinada de PortalEstrutura + PortalCliente/Estrutura teve 1 erro e 5 falhas, que não reproduziram nem isoladas nem numa segunda rodada combinada (provável flaky de execução simultânea de outra sessão). Fica como aviso, não lacuna.

## Anti-padrões

Sem TODO/FIXME/XXX/TBD nos serviços novos da fase.

## Lacunas

Nenhuma. Itens pendentes são apenas de produção/humanos (frontmatter).

_Verificado por Claude (gsd-verifier)_
