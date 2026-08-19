---
quick_id: 260819-guy
slug: ajustes-fluxo-contrato-administrativo
created: 2026-08-19
description: Ajustes no fluxo de contrato do Administrativo após o teste ponta-a-ponta
---

# Ajustes no fluxo de contrato do Administrativo

**Origem:** teste ponta-a-ponta do fluxo completo (HubSpot → Administrativo → Clicksign),
rodado em 2026-08-19 com os setores responsáveis. Feedback do usuário + quatro defeitos de UX
diagnosticados na mesma sessão.

**Decisões do usuário (2026-08-19, via AskUserQuestion):**
- Os campos novos são **OBRIGATÓRIOS já** — entram nos dados mínimos e travam a geração
- Filename da Clicksign usa **slug conservador ASCII**

## Descoberta que encurta o trabalho

O modelo `.docx` da Clicksign **já tem as quatro variáveis**. Em
`ContratoVariaveisModeloService::mapa()` já existem `razao_social`, `endereco`,
`data_primeira_parcela` e `dia_vencimento`. Elas saem `"A DEFINIR"` porque:

- `GerarContratoAssinaturaJob:157` chama `montar($contrato)` **sem** o 2º argumento `$complementos`
- `ContratoPdfService::montarDados()` lê `$complementos['endereco']`, `$complementos['dia_vencimento']`
- `data_primeira_parcela` é `fn () => ContratoPdfService::PLACEHOLDER` fixo, com o comentário
  *"território da Fase 131 (ADM-01)"* — era trabalho já previsto e adiado
- `razao_social` lê `$company->name` (nome fantasia), não uma razão social de verdade

**NÃO mexer no `.docx`. NÃO renomear variável nenhuma** — renomear faz a variável sumir do
contrato assinado *sem erro nenhum da API* (T-126-38, o modo de falha silencioso desta integração).

## Restrições permanentes

- Árvore compartilhada com outro dev e outras sessões: **nunca** `git add -A` / `git add .` /
  `git commit -a` / `git stash`. Commitar só os caminhos tocados, com `git commit -- <arquivos>`
- Comentários e copy em **pt-BR**
- Regra de UI do projeto: **evitar jargão**. Quem usa a tela não sabe o que é "envelope"
- PHP local: `C:\xampp\php\php.exe`
- `npm run build` ao final
- ⛔ **Não fazer deploy. Não rodar comando no VPS.**

---

## Tarefa 1 — Migration das quatro colunas

**Arquivos:** nova migration em `database/migrations/`

- `companies.razao_social` — string(255), nullable
- `companies.endereco` — string(255), nullable
- `contratos_servico.data_primeira_parcela` — date, nullable
- `contratos_servico.dia_vencimento` — unsignedTinyInteger, nullable

Todas **nullable** para não quebrar as linhas que já existem — a obrigatoriedade é aplicada na
camada de validação (Tarefa 3), não no schema. Empresa antiga vai aparecer como "falta preencher",
que é o comportamento desejado.

⚠️ Comentar na migration **por que `dia_vencimento` é dia do mês e não data**: "data de
vencimento das demais parcelas" não é uma data única num contrato mensal recorrente, e é
exatamente o que o placeholder `dia_vencimento` do `.docx` já espera.

⚠️ MariaDB: nome de índice não passa de 64 chars (SQLite dos testes não pega). Se precisar de
índice, nomear à mão.

**Aceite:** `migrate` roda limpo; `migrate:rollback` desfaz; suíte existente continua verde.

---

## Tarefa 2 — Campos na tela + persistência

**Arquivos:** `app/Http/Controllers/ContratoAdminController.php`,
`resources/js/Pages/Admin/ContratoDetalhe.jsx`, `app/Models/Company.php`,
`app/Models/ContratoServico.php`

- Bloco "Cadastro da empresa": **Razão social** e **Endereço** (por empresa)
- Bloco "Datas por serviço": **Data da 1ª parcela** e **Dia do vencimento das demais**
  (por serviço, ao lado das datas de início/término que já existem ali)
- Validar no servidor em `salvarCadastro()` e persistir; `$fillable` atualizado nos models
- Enviar os valores como prop em `show()`

Rótulo do dia: deixar claro que é **o dia do mês (1 a 31)**, não uma data.

**Aceite:** preencher e salvar persiste os quatro; recarregar a página traz os valores de volta.

---

## Tarefa 3 — Obrigatoriedade em `ContratoDadosMinimosService`

**Arquivo:** `app/Services/Contratos/ContratoDadosMinimosService.php`

Os quatro campos entram em `faltantes()`, respeitando o **contrato de retorno PÚBLICO**
(a Fase 131 consome isso para montar a tela): chaves `campo`, `rotulo`, `motivo`
(só `ausente` | `formato`), `servico_id`. Os por-serviço usam `servico_id`, como já faz a
regra 5 de `data_contratacao`.

⚠️ O docblock da classe hoje diz: *"Os 3 campos que saem como A DEFINIR (`endereco`,
`dia_vencimento`, `data_primeira_parcela`) NÃO existem no banco e são placeholder por decisão do
usuário — NÃO adicionar checagem para eles aqui, isso travaria toda geração de contrato."*

Essa instrução está **SUPERADA** por decisão explícita do usuário em 2026-08-19. **Atualizar o
docblock** explicando a mudança e a data — não apagar o histórico.

**Consequência aceita e conhecida:** enquanto os quatro não forem preenchidos, nenhuma empresa
gera contrato. É exatamente o que o usuário pediu.

**Aceite:** empresa sem os campos aparece com as pendências nomeadas na tela e o botão
desabilitado; preenchendo tudo, o botão libera.

---

## Tarefa 4 — Validação de dígito verificador do CNPJ

**Arquivos:** novo helper/Rule em `app/`, `ContratoAdminController::salvarCadastro()`,
`ContratoDadosMinimosService` (regra 2)

Não existe helper de CNPJ no projeto — criar um, com teste de unidade cobrindo: CNPJ válido,
dígito errado, todos os dígitos iguais (`00000000000000`), tamanho errado, com e sem pontuação.

Na regra 2 de `faltantes()`, dígito inválido vira `motivo: 'formato'` (valor já existente do
contrato público — **não inventar um `motivo` novo**, isso quebraria a tela).

⚠️ O comentário da regra 2 hoje diz que dígito verificador está **deliberadamente** fora de
escopo. Atualizar, com a data e o porquê.

**Aceite:** `26.754.383/0001-87` passa se for válido; um CNPJ com dígito trocado é recusado no
save e listado como pendência.

---

## Tarefa 5 — Ligar os valores no documento

**Arquivos:** `app/Jobs/GerarContratoAssinaturaJob.php`, `app/Services/ContratoPdfService.php`,
`app/Services/Clicksign/ContratoVariaveisModeloService.php`

- Passar `$complementos` do job para `montar()` — `endereco`, `dia_vencimento`, e o que mais o
  mapa consumir
- `data_primeira_parcela` deixa de ser `PLACEHOLDER` fixo e passa a ler o dado real
- `razao_social` em `montarDados()` lê a coluna nova, com **fallback para `$company->name`**

⚠️ O job monta a partir do `servicos_snapshot` **congelado** (D-04) — respeitar essa disciplina.
Não passar a ler tabela ao vivo onde hoje se lê snapshot. Se o dado novo precisar estar no
snapshot, incluí-lo **na hora de criar o snapshot**, não na leitura.

⚠️ `ContratoVariaveisModeloService` é **puro por decisão** (T-126-40): sem `Http`, `Storage`,
`Log`, `Cache` nem consulta a `ContratoServico`. Manter.

**Aceite:** teste provando que as quatro variáveis saem com valor real, e que ausência de dado
ainda cai em `campos_pendentes` em vez de quebrar.

---

## Tarefa 6 — Filename identificável na Clicksign

**Arquivo:** `app/Jobs/GerarContratoAssinaturaJob.php:175`

Hoje: `$nomeArquivo = "contrato-{$contrato->id}.docx"` — e é **isso** que a lista de Rascunhos
do painel da Clicksign mostra, então é impossível saber de que empresa/serviço se trata.

Trocar por razão social (fallback `company->name`) + nome do serviço, com **slug conservador
ASCII**: só `[A-Za-z0-9_-]`, acento transliterado, espaço/parêntese/pontuação viram hífen,
hífens repetidos colapsados, limite de tamanho, **sempre** terminando em `.docx`.

⚠️ Medido no plano 126-11: `contrato.docx` e `contrato_sondagem.docx` = 201;
`.pdf` e nome sem extensão = 400 `"filename não está em um formato válido"`. A guarda de `.docx`
em `ClicksignClient::anexarDocumentoPorModelo()` continua valendo — **o slug não pode comer a
extensão**.

**Aceite:** teste com o caso real `"Embralumi - Novo(a) Deal"` + `"Gestão"` produzindo algo como
`Embralumi-Novo-a-Deal-Gestao.docx`, sem caractere fora de `[A-Za-z0-9_-]` antes do `.docx`.

---

## Tarefa 7 — Os quatro defeitos de UX da tela de contrato

**Ler primeiro:** `.planning/todos/pending/260819-tela-contrato-esconde-motivo-e-proximo-passo.md`

**Arquivos:** `app/Http/Controllers/ContratoAdminController.php`,
`resources/js/Pages/Admin/ContratoDetalhe.jsx`

1. **Expor `erro_mensagem` como prop.** O mapeamento em `ContratoAdminController:310-338` não a
   inclui, então o motivo real do erro é invisível. E derivar "já tentou antes" do **backend** —
   hoje é o `useState({})` de `ContratoDetalhe.jsx:163`, que zera a cada reload, deixando a tela
   presa em "Tente novamente — na maioria das vezes resolve" para sempre.
   ⚠️ `erro_mensagem` já passa por `podarPii()` antes de ser gravada — conferir que continua
   assim antes de exibir.

2. **`abort(422)` da linha 439 vira `back()->with('error', ...)`**, igual ao ramo de emissão
   congelada dez linhas acima no mesmo método. A checagem no servidor está certa (o `disabled`
   do client não é controle, T-131-04-03) — o que está errado é só a apresentação, que hoje é
   a página branca do Symfony, fora da aplicação.

3. **Contar na tela que o contrato precisa ser enviado pelo painel da Clicksign.** O sistema para
   no rascunho **de propósito** (D-02 da Fase 127-05, `ativar: false`, "a ativação acontece FORA
   do sistema"), mas nada na tela diz isso e não há link — o único link para a Clicksign é o do
   fluxo de cancelamento. Usar a prop `painel_clicksign_url`, que **já existe**.
   Revisar a copy de `rascunho` em `resources/js/lib/contratoStatus.js` (hoje "Não enviado") para
   descrever a **ação pendente**, não uma falha.
   ⚠️ `contratoStatus.js` é compartilhado por três telas e tem um aviso explícito de que o mapa
   precisa ter **exatamente 7 chaves** — mudar o texto é seguro, mudar a quantidade não é.

4. **Validar nome completo do signatário** (mínimo duas palavras) no servidor. Hoje `nome_contato`
   é `['nullable','string','max:255']` e `"teste"` só falha ~6 minutos depois, num 400 da
   Clicksign (`"name não está em um formato válido"`), com o registro terminando em `status = erro`.
   Entra também em `faltantes()` como `motivo: 'formato'`.

**Aceite:** teste cobrindo a prop de erro, o 422 virando flash, e a recusa de nome sem sobrenome.

---

## Fora de escopo

⛔ **Não** investigar o erro *"Ocorreu um erro ao salvar os posicionamentos!"* da Clicksign —
está em `.planning/todos/pending/260819-clicksign-erro-salvar-posicionamentos.md` e precisa de
teste controlado em sandbox, não de mudança de código.

⛔ Não fazer deploy. Não rodar comando no VPS.

## Fechamento

Mover para `.planning/todos/completed/` os todos `260819-*` que este trabalho fechar —
**exceto** o de posicionamento, que continua aberto.
