# Plano de Implementacao — NPS Anti-Burlamento + Modulo Digisac

## Objetivo

Melhorar o modulo de NPS em producao com duas frentes principais:

1. Criar mecanismos anti-burlamento para identificar respostas suspeitas.
2. Implementar integracao com Digisac como modulo reutilizavel do sistema, nao acoplado apenas ao NPS.

O Digisac deve nascer como uma infraestrutura geral de comunicacao automatica, podendo ser usado futuramente em outros setores alem do NPS.

---

## 1. Anti-Burlamento no NPS

### 1.1 Registrar dados tecnicos da resposta

Ao cliente abrir e responder o link de NPS, registrar:

```text
ip_address
user_agent
opened_at
responded_at
generated_at
generated_by_user_id
response_duration_seconds
is_suspicious
suspicion_reasons
```

Esses dados devem ser vinculados a survey/resposta do NPS.

### 1.2 Registrar abertura do link

Hoje o sistema provavelmente registra apenas a resposta. Deve passar a registrar tambem quando o link foi aberto.

Fluxo:

1. Link e gerado.
2. Cliente acessa `/nps/{token}`.
3. Sistema registra `opened_at`, `open_ip_address`, `open_user_agent`.
4. Cliente responde.
5. Sistema registra `responded_at`, `response_ip_address`, `response_user_agent`.

Se o link for aberto varias vezes, manter pelo menos:

```text
first_opened_at
last_opened_at
open_count
```

Opcionalmente, criar tabela de eventos:

```text
nps_survey_events
- id
- survey_id
- event_type: generated | opened | submitted | expired | sent_email | sent_digisac
- ip_address
- user_agent
- user_id nullable
- metadata json
- created_at
```

Essa tabela e melhor porque cria auditoria viva e facilita investigacoes.

---

## 2. Regras de Suspeita

Criar um servico central, por exemplo:

```php
NpsSuspicionService
```

Responsavel por avaliar se uma resposta e suspeita.

### 2.1 IP da empresa ECF

Criar configuracao para IPs/redes internas da ECF:

```env
ECF_INTERNAL_IPS=
ECF_INTERNAL_CIDRS=
```

Ou via tabela/configuracao no painel.

Exemplos:

```text
201.xxx.xxx.xxx
189.xxx.xxx.xxx
10.0.0.0/8
192.168.0.0/16
```

Se o IP da resposta for igual a um IP interno da ECF, marcar como suspeita.

Motivo:

```text
Resposta enviada a partir da rede interna da ECF.
```

### 2.2 Tempo curto entre geracao e resposta

Se o link for gerado e respondido muito rapido, gerar alerta.

Regra inicial:

```text
generated_at -> responded_at <= 60 segundos
```

Motivo:

```text
Resposta enviada em menos de 1 minuto apos geracao do link.
```

Essa janela deve ser configuravel.

### 2.3 Mesmo IP/rede da ECF + resposta rapida

Se combinar:

- IP interno da ECF;
- resposta em menos de 1 minuto;

marcar com severidade maior.

Motivo:

```text
Link gerado e respondido rapidamente a partir da rede interna.
```

### 2.4 Usuario autenticado

Se alguem abrir/responder o NPS enquanto esta autenticado no sistema como usuario interno, marcar como suspeito ou bloquear.

Recomendacao:

- fase 1: marcar como suspeito;
- fase 2: bloquear resposta.

Motivo:

```text
Resposta realizada em sessao autenticada de usuario interno.
```

---

## 3. Exibicao na UI do NPS

Importante: todos os elementos visuais relacionados a anti-burlamento, confianca, suspeita, auditoria tecnica e risco da resposta devem aparecer apenas para usuarios Admin.

Para usuarios que nao sao Admin:

- o sistema deve continuar registrando tudo por baixo dos panos;
- nao mostrar badges de suspeita;
- nao mostrar coluna de confianca;
- nao mostrar filtros de confiabilidade;
- nao mostrar motivos de suspeita;
- nao mostrar IP, user-agent, tempo entre geracao/abertura/resposta ou outros dados tecnicos;
- nao revelar que a resposta foi marcada como suspeita.

Essa camada deve existir silenciosamente para proteger o NPS, mas so deve ser revelada para Admins.

### 3.1 Listagem de NPS

Na tabela/listagem de NPS respondidos, adicionar indicador visual apenas para Admin:

```text
Suspeita
```

Exemplo de badge:

- verde: confiavel;
- amarelo: atencao;
- vermelho: suspeita.

Na linha do NPS, mostrar:

```text
Empresa
Mes
Nota
Respondido em
Canal
Status de confianca
```

Para usuarios nao Admin, manter a listagem sem qualquer coluna ou indicativo de confiabilidade.

### 3.2 Detalhamento do NPS

No modal/tela de detalhes do NPS, mostrar secao de auditoria apenas para Admin:

```text
Gerado em
Gerado por
Aberto em
Respondido em
Tempo ate resposta
IP da abertura
IP da resposta
User-agent
Canal de envio
Motivos de suspeita
```

Exemplo:

```text
Status: Suspeita

Motivos:
- Resposta enviada a partir da rede interna da ECF.
- Link respondido 42 segundos apos geracao.
```

Para usuarios nao Admin, ocultar totalmente essa secao.

### 3.3 Filtros

Adicionar filtros apenas para Admin:

```text
Todos
Confiaveis
Com alerta
Suspeitos
```

Para usuarios nao Admin, nao exibir filtros relacionados a confianca ou suspeita.

---

## 4. Verificacao de Link Ja Gerado no Mesmo Mes

Antes de gerar qualquer link NPS, seja manual, email ou Digisac, verificar se ja existe survey para:

```text
company_id
month_reference
nps_template_id/modelo
```

Regra:

- se ja existe survey pendente: reutilizar o mesmo link;
- se ja existe survey respondida: nao gerar novo link equivalente;
- se existe survey expirada: avaliar se pode reabrir/reemitir conforme regra do sistema;
- nunca criar dois links ativos para a mesma empresa no mesmo mes/modelo.

Criar metodo central:

```php
NpsSurveyService::getOrCreateMonthlySurvey($company, $template, $monthReference)
```

Todos os fluxos devem usar esse metodo:

- botao manual;
- envio automatico por email;
- envio automatico por Digisac;
- reenvio.

---

## 5. Modulo Digisac Independente

O Digisac nao deve ser implementado dentro do NPS. Ele deve ser um modulo/infraestrutura propria, reutilizavel por outros setores.

### 5.1 Configuracao Digisac

Criar configuracao geral:

```text
DIGISAC_BASE_URL
DIGISAC_TOKEN
DIGISAC_DEFAULT_SERVICE_ID
DIGISAC_DEFAULT_USER_ID
```

Criar arquivo:

```php
config/digisac.php
```

### 5.2 Services

Criar camada propria:

```php
App\Services\Digisac\DigisacClient
App\Services\Digisac\DigisacMessageService
App\Services\Digisac\DigisacContactService
App\Services\Digisac\DigisacGroupService
```

Responsabilidades:

- autenticar chamadas;
- listar grupos;
- buscar contatos;
- enviar mensagens;
- tratar erros;
- registrar logs;
- retornar resposta padronizada.

### 5.3 Auditoria geral de mensagens

Criar tabela reutilizavel:

```text
digisac_messages
- id
- company_id nullable
- related_type nullable
- related_id nullable
- contact_id
- service_id
- user_id nullable
- destination_name_snapshot
- message
- status: pending | sent | failed | skipped
- provider_message_id nullable
- error_message nullable
- metadata json
- sent_at
- created_at
- updated_at
```

Assim o NPS pode criar registros com:

```text
related_type = NpsSurvey
related_id = survey_id
```

E outros setores tambem poderao usar a mesma estrutura futuramente.

---

## 6. Mapeamento Empresa x Grupo Digisac

Criar vinculo entre empresa e grupo Digisac.

Preferencia: tabela separada.

```text
company_digisac_channels
- id
- company_id
- service_id
- contact_id
- contact_name_snapshot
- is_group
- mapping_source: manual | cust_id_name | imported
- status: not_mapped | mapped | needs_review | invalid
- verified_at
- created_at
- updated_at
```

A fonte de verdade deve ser o `contact_id`, nao o nome do grupo.

O nome do grupo com `cust_id` pode ser usado apenas para sugestao automatica.

---

## 7. Envio Automatico NPS via Digisac

Depois de criar o modulo Digisac, integrar no NPS.

Fluxo mensal:

1. Buscar empresas elegiveis.
2. Resolver modelo/template NPS.
3. Buscar ou criar survey do mes.
4. Enviar email, se habilitado.
5. Enviar Digisac, se habilitado.
6. Usar o mesmo link nos dois canais.
7. Registrar auditoria por canal.
8. Se empresa nao tiver grupo Digisac, marcar como `skipped`.

Mensagem Digisac deve permitir placeholders:

```text
{nome_empresa}
{mes_referencia}
{link_nps}
{nome_estrategista}
{nome_analista}
```

---

## 8. Pagina/Aba "Envio Automatico" no NPS

Criar aba dentro do modulo NPS:

```text
NPS > Configuracao > Envio Automatico
```

Ela deve conter:

- status do envio por email;
- status do envio por Digisac;
- configuracao da mensagem WhatsApp;
- listagem de empresas sem grupo mapeado;
- listagem de empresas com grupo mapeado;
- botao para buscar grupos no Digisac;
- acao para vincular grupo a empresa;
- historico de envios por canal.

---

## 9. Ordem Recomendada de Implementacao

### Fase 1 — Auditoria anti-burlamento

- adicionar campos/eventos de abertura e resposta;
- registrar IP e user-agent;
- criar servico de suspeita;
- exibir badge "suspeita" na UI.

### Fase 2 — Unicidade mensal do link

- centralizar criacao/reuso de survey mensal;
- impedir links duplicados;
- ajustar botao manual e envio automatico existente.

### Fase 3 — Modulo Digisac base

- criar config;
- criar services;
- listar grupos;
- enviar mensagem;
- criar auditoria geral de mensagens.

### Fase 4 — Mapeamento empresa x grupo

- criar tabela;
- criar UI de vinculo;
- sugerir grupo por `cust_id`;
- destacar empresas sem mapeamento.

### Fase 5 — Envio NPS via Digisac

- integrar envio automatico;
- usar o mesmo link do email;
- registrar envio por canal;
- tratar falhas sem quebrar o fluxo.

### Fase 6 — Melhorias de seguranca

- bloquear resposta por usuario interno logado;
- configurar IPs internos pela UI;
- criar filtros avancados de suspeita;
- permitir invalidacao manual de resposta suspeita por admin.

---

## Criterios de Aceite

A melhoria estara pronta quando:

1. Toda resposta NPS registrar IP, user-agent e horario.
2. Toda abertura de link NPS registrar horario, IP e user-agent.
3. Respostas vindas de IP interno da ECF forem marcadas como suspeitas.
4. Respostas muito rapidas apos geracao forem marcadas como suspeitas.
5. A UI mostrar alerta na linha ou detalhe do NPS respondido apenas para usuarios Admin.
6. O sistema impedir/reutilizar link ja gerado para a empresa no mesmo mes.
7. Digisac existir como modulo separado e reutilizavel.
8. Empresas puderem ser vinculadas ao grupo Digisac correto.
9. O NPS conseguir enviar link pelo Digisac no grupo do cliente.
10. Email e Digisac usarem o mesmo link/survey mensal.
11. Usuarios nao Admin nao conseguirem ver badges, colunas, filtros, motivos ou detalhes tecnicos de confiabilidade/suspeita.
