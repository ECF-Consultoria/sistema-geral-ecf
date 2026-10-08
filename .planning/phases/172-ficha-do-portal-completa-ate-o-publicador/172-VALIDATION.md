---
phase: 172
slug: ficha-do-portal-completa-ate-o-publicador
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-10-08
---

# Phase 172 — Estratégia de Validação

> Contrato de validação da fase. O mapa requisito → teste detalhado está em `172-RESEARCH.md`
> § "Arquitetura de validação (Nyquist)"; aqui ficam a infraestrutura, a amostragem e os ajustes pelas
> decisões D-10..D-16 tomadas depois da pesquisa.

---

## Infraestrutura de teste

| Propriedade | Valor |
|----------|-------|
| **Framework** | PHPUnit 11 (SQLite em memória) + `node --test` (`npm run test:js`) |
| **Config** | `phpunit.xml`, `package.json` (`test:js`) |
| **Rápido (por commit)** | `C:/xampp/php/php.exe vendor/bin/phpunit <arquivo de teste tocado>` |
| **Suíte da fase** | `phpunit tests/Feature/Publicador`, `tests/Unit/Publicador`, `tests/Unit/PortalEstrutura`, `tests/Feature/PortalCliente` + `npm run test:js` + `npm run build` |
| **Tempo estimado** | ~4–6 min a suíte da fase |

---

## Amostragem

- **A cada commit de tarefa:** o(s) arquivo(s) de teste tocado(s).
- **A cada onda:** o grupo da pasta tocada.
- **Portão da fase:** os 4 grupos PHP + `npm run test:js` (piso = as 2 falhas antigas conhecidas) + `npm run build`.
- **Regra:** contagem ≥ baseline do Wave 0 (`172-BASELINE-TESTES.md`) e nenhuma falha nova.
- **Mutação obrigatória** em 2 testes-chave: tirar o "só-vazio" e a trava de `company_id` e ver o teste quebrar.

Baseline medido na pesquisa (08/10): Feature/Publicador 565, Unit/Publicador 254, Unit/PortalEstrutura 205,
PortalCliente/Estrutura/Produtos 226. PortalCliente inteiro e test:js: medir no Wave 0.

---

## Mapa requisito → teste

Ver `172-RESEARCH.md` (FP172-01..09). Ajustes pelas decisões posteriores:

| Req | Ajuste | Teste |
|-----|--------|-------|
| FP172-07 | Principal do Kit/Combit = lado que NÃO repete no par de tipo (D-12); fallback maior custo | `tests/Unit/Publicador/ComposicaoDoPortalTest.php` cobre os dois caminhos |
| FP172-08 | Descrição MAG T8 dispara SOZINHA no rascunho vazio com descrição do cliente, uma vez (D-11); não aplica se a pessoa digitou | `tests/Feature/Publicador/DescricaoIaTest.php` + teste JS do disparo automático |
| FP172-09 | SEM gate de piloto no Sincronizar (D-10); o teste passa a afirmar que empresa fora do piloto TAMBÉM é enriquecida e que nada chama `/items` nem sobe foto ao ML | `tests/Feature/Publicador/PortalParaRascunhoTest.php --filter isolamento` |
| FP172-06 | WebP convertida para JPG (D-15); pequena demais contada no resumo | `tests/Feature/Publicador/PortalFotosTest.php` |
| FP172-06 | Multivalor: 1ª opção + `values_multi` + `revisar` (D-13) | `tests/Unit/Publicador/PortalValorDeAtributoTest.php` |

---

## Wave 0

- [x] `172-BASELINE-TESTES.md` com as contagens dos 4 grupos PHP e do test:js
- [x] Prova das migrations no MariaDB local com `--path` (guarda `guarda-sqlite.php`), registrada

---

## Verificações só manuais

| Comportamento | Req | Por que manual | Instruções |
|----------|-------------|------------|-------------------|
| Ficha do portal com estoque e descrição | FP172-01/02 | conferência visual do usuário | abrir `/portal/estrutura/produtos/{id}` na #459 local, preencher e salvar |
| Sincronizar na #459 traz tudo para um rascunho com N variações e fotos | FP172-05/06 | ponta a ponta com dado real | depois do deploy, "Sincronizar do Portal" na #459 e abrir o rascunho — sem publicar |
| Contagem de linhas em prod antes/depois do deploy | FP172-04 | só em produção | `estrutura_produtos`, `estrutura_produto_variacoes`, `pub_produtos` |

---

## Assinatura

- [x] Toda tarefa tem `<automated>` ou dependência de Wave 0
- [x] Nenhuma sequência de 3 tarefas sem verificação automática
- [x] Sem flags de watch
- [x] `nyquist_compliant: true` no frontmatter

**Aprovação:** portão automático verde em 08/10/2026 (172-13); conferência visual do usuário pendente (Task 3)
