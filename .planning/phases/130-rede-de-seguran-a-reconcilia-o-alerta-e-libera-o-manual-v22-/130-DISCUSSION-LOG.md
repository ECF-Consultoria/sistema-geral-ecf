# Phase 130: Rede de segurança — reconciliação, alerta e liberação manual (v22.0) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-13
**Phase:** 130-rede-de-seguranca-reconciliacao-alerta-e-liberacao-manual-v22-0
**Areas discussed:** Canal e urgência do alerta, Qual é o prazo aceitável, O que a reconciliação faz ao achar divergência, Onde vive a liberação manual, O que conta como "preso", O PDF pendente não tem dono, Quem vigia a rede de segurança, A forma do motivo

---

## Canal e urgência do alerta

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Sino in-app, como o resto do sistema | Segue `BaseNotification via database`; consistente, zero infra nova | ✓ |
| Sino + e-mail | Tira a pessoa de onde ela estiver; quebra o canal único | |
| Sino + WhatsApp (Digisac) | Alcança em minutos; reaproveita client que hoje só serve ao NPS | |

**Escolha:** Sino in-app.
**Notas:** Registrado como consequência assumida que a promessa do goal ("em minutos, não em dias") não se sustenta com sino apenas — o ajuste é na redação do goal, não na implementação.

### Quem recebe

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Todos com role admin | Mesma trava da Fase 129; troca em um lugar só quando a 131 criar a permissão | |
| Criar audiência dedicada agora | Espelha `AudienciaComercial`; inventa estrutura que a 131 pode definir diferente | |
| **Resposta livre do usuário** | "Todos com role de admin e quem tiver com cargo de comercial no sistema, se ainda não existir no futuro vou criar usuarios comerciais" | ✓ |

**Escolha:** `role:admin` ∪ usuários ativos do setor comercial.
**Notas:** Descoberto durante a discussão que **não existe setor "Administrativo"** (semeados: Comercial, Desenvolvimento, Dev, Shopee). Também registrado que `AudienciaComercial::lideresEPermissionados()` é mais estreito que o pedido — devolve só líderes + permissionados —, então reusá-lo silenciosamente estreitaria a audiência.

---

## Qual é o prazo aceitável

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Número configurável, igual para todos | Simples; ignora prazo por contrato | |
| Fração do prazo do próprio contrato | Respeita a D3; mais difícil de explicar | |
| Os dois: o que vier primeiro | Cobre contrato curto e longo; duas regras para manter | ✓ |

**Escolha:** Os dois, o que vier primeiro.

### Repetição

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Avisa uma vez por contrato | Zero ruído; se ninguém viu, o aviso morre | |
| Repete em intervalo, até resolver | Insiste sem inundar; exige registrar o último aviso | ✓ |
| Você decide | | |

**Escolha:** Repete em intervalo.

---

## O que a reconciliação faz ao achar divergência

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Corrige sozinha, pelo mesmo caminho | `liberarEmpresa()` com `via='reconciliacao'`; seguro pelo lock e idempotência | ✓ |
| Só marca e alerta um humano | Mais conservador; empresa segue presa até alguém agir | |
| Você decide | | |

**Escolha:** Corrige sozinha.
**Notas:** Sustentado pelo fato de que a Fase 129 deixou `liberarEmpresa()` idempotente e protegido por lock por empresa (correção do CR-01).

### Frequência

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Uma vez por dia, como o resto | Padrão do projeto; zero risco de rate limit | ✓ |
| De hora em hora | Recupera no mesmo dia; exige lote e pausa pelo limite de 20/min | |
| Você decide | | |

**Escolha:** Diária.

---

## Onde vive a liberação manual

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Comando artisan com motivo obrigatório | Rápido; só quem tem servidor usa | |
| Rota mínima com formulário simples | Administrativo usa de verdade; tela descartável na 131 | ✓ |
| Só o service; tela fica para a 131 | Menos trabalho jogado fora; SC3 não verificável nesta fase | |

**Escolha:** Rota mínima com formulário.

### Freio

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Ignora o gate, mas avisa e registra | Confia no humano informado; motivo + autor são a prestação de contas | ✓ |
| Bloqueia se o contrato foi recusado | Protege contra o erro mais caro; cria caso que nem o admin destrava | |
| Você decide | | |

**Escolha:** Ignora o gate, com o estado real exibido antes de confirmar.

---

## O que conta como "preso"

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Tudo que não terminou bem | Regra vira "empresa sem liberação há tempo demais", causa na mensagem | ✓ |
| Só aguardando e erro | Menos ruído; empresa recusada fica sem aviso | |
| Você decide | | |

**Escolha:** Tudo que não terminou bem.
**Notas:** Levantados na discussão os 7 estados reais de `ContratoAssinatura` e o fato de que o escopo do alerta é mais largo que o da reconciliação — reconsultar só faz sentido em `aguardando_assinaturas`.

---

## O PDF pendente não tem dono

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| A reconciliação tenta baixar de novo | Funciona dias depois porque todo retry reconsulta para link fresco | ✓ |
| Só alerta, humano resolve | Transforma falha de rede passageira em trabalho manual | |
| Você decide | | |

**Escolha:** A reconciliação redispara o download.
**Notas:** Fecha lacuna real entre as fases — a D-14 da 129 criou o sinal `pdf_assinado_erro` e nenhum código agia nele.

---

## Quem vigia a rede de segurança

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Registra cada execução e alerta quando falha | Cobre Clicksign fora e exceção; não cobre cron parado | |
| Registro + checagem de ausência | Cobre também o cron parado, a falha mais silenciosa | ✓ |
| Você decide | | |

**Escolha:** Registro + checagem de ausência.

---

## A forma do motivo

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Lista de motivos + campo de detalhe | Dá para agrupar e ver padrão, sem perder o caso específico | ✓ |
| Só texto livre obrigatório | Mais rápido; auditar exige ler tudo | |
| Você decide | | |

**Escolha:** Lista + detalhe.

---

## Claude's Discretion

Nenhuma decisão foi delegada nesta rodada — o usuário decidiu as 12 explicitamente.
Ficam a critério do planejamento apenas detalhes de implementação: onde mora o carimbo da D-09,
os valores default da D-03, o intervalo da D-04 e o refinamento da lista da D-12.

## Deferred Ideas

- Alerta por e-mail ou WhatsApp (recusado na D-01; `DigisacClient` já existe)
- Tela definitiva do Administrativo e permissão `admin.contratos` (Fase 131)
- Criar setor "Administrativo" como estrutura organizacional (não existe hoje)
- Painel de taxa/tempo de assinatura (já fora de escopo pela D3 da milestone)
- Ligar o bloqueio do roteamento (Fase 133)
