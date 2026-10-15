# REQUIREMENTS — Milestone v28.0: Acervo de imagens no Cloudflare R2

**Origem:** plano "Cloudflare R2 e Acervo Inteligente de Imagens" trazido pelo usuário em 15/10/2026, e a
auditoria técnica (Fase 1 daquele plano) feita na mesma sessão. As decisões D1..D9 abaixo foram dadas pelo
usuário depois do diagnóstico e **não se reabrem**.

**Objetivo:** tirar da VPS o armazenamento definitivo das imagens do Publicador, colocando-as no Cloudflare R2,
e transformar o acervo — hoje só de gerações de IA — numa biblioteca única por conta do Mercado Livre, com
uploads manuais e imagens de IA aprovadas, exclusão segura e retenção controlada.

---

## O que a auditoria mediu (15/10/2026) — base de todas as decisões

- **Tudo está no disco `local` da VPS** (`storage/app/private`). Nada usa `public`, `Storage::url`, `/storage`
  nem S3. O driver `league/flysystem-aws-s3-v3` **não está instalado** (o disco `s3` do config não funciona).
- **O Mercado Livre recebe BYTES, não URL nossa**: os dois fluxos usam `POST /pictures/items/upload` e o payload
  leva `ml_picture_id` (`ClienteMlPublicador::enviarFoto`, `MlImagemService::enviar`). ➡️ O bucket pode ser
  **100% privado**; **apagar no R2 não derruba anúncio que já está no ar** (o ML tem a cópia dele) — só afeta
  foto de rascunho que ainda não subiu.
- **Pontos com o disco `'local'` cravado:** `ImagemAssetService` (`DISCO`), `CriarFaseService:633`,
  `PublicadorCriativoAprovacaoService`, `PublicadorAcervoService`, `MlbPublicadorController::arquivoFoto`,
  `MlbPublicadorAcervoController::imagem`, `ExcluirProdutoService`, `GerarCriativoIaJob:223`.
- **Upload manual e aprovação de IA já convergem** em `ImagemAssetService::receber()` (dedup por sha256 dentro
  do rascunho; `pub_imagens` com sha256/mime/bytes/largura/altura). É o ponto único de entrada no acervo.
- **O acervo atual** (`MlbPublicadorAcervoController` + `PublicadorAcervoService` + `AcervoDaConta.jsx`) lista
  só `ml_anuncio_criativos` (IA), inclusive não aprovadas e reprovadas, limite 60, sem paginação, filtro único
  `toda_conta`. "Usar" **copia** os bytes para um `pub_imagens` novo.
- **Bug provável:** os slots criados em `PlanejarKitCriativosJob:280` não recebem `pub_rascunho_id`, mas
  `PublicadorAcervoService:62` filtra o slot por essa coluna → a visão "este produto" sai vazia e `produto_nome`
  nulo. Os testes da Fase 171 preenchem a coluna à mão e não pegam.
- **Lixo que nunca é apagado:** `creative-geradas/` (nenhum caminho do código remove — nem exclusão de empresa,
  que apaga a linha em cascata e deixa o arquivo); rascunho excluído fora do `ExcluirProdutoService`; arquivo
  gravado antes do `create` em `receber()` quando o insert falha.
- **Foto de referência do Gemini já é efêmera** (`creative-referencias/`, apagada na aprovação e por
  `creative:limpar-referencias` às 04:10, retenção 48 h). Não há base64 em log.
- **v27.0 (Fase 186) criou tabelas que apontam para `pub_imagens` com `cascadeOnDelete`**
  (`pub_aprovacao_imagens`, `pub_imagem_referencias`) e `nullOnDelete` (`pub_aprovacao_conjuntos.principal`).
  Apagar uma linha de `pub_imagens` apaga histórico de aprovação.
- **Deploy:** `deploy.sh` faz `git reset --hard`; `storage/app` sobrevive só por estar no `.gitignore`; não há
  backup de storage. `deploy_first_time.sh` não exclui `storage/app` do rsync.

---

## Decisões travadas (D1..D9 — NÃO reabrir)

- **D1 — Tabela de ativos.** Um registro lógico de imagem por conta, **acima** de `pub_imagens` (que continua
  existindo, com as FKs da v27 intactas). `pub_imagens` ganha a referência ao ativo; o acervo lê do ativo.
- **D2 — Dono = conta do Mercado Livre.** Na prática a âncora `MlbEmpresa` ou `Company` (cada uma tem no
  máximo um `ml_tokens` — unique). Mesma ordem de `PubProduto::ancoraComToken()` (MlbEmpresa primeiro). O
  `ml_user_id` da conta é guardado no ativo quando conhecido. **Publicador é só Mercado Livre** — sem dimensão
  de marketplace.
- **D3 — IA só entra aprovada.** Imagem gerada vai para o acervo/R2 **somente quando aprovada** (a aprovação já
  passa por `receber()`). Gerações não aprovadas ficam no disco local como rascunho transitório e são limpas
  por prazo. `GerarCriativoIaJob` **não muda**.
- **D4 — Um bucket privado, dois prefixos** (`production/`, `dev/`). Chave R2 é por bucket, não por prefixo:
  a credencial só existe no `.env` da VPS; máquinas de dev seguem no disco local; testes usam `Storage::fake`.
- **D5 — Sem URL pública e sem CORS.** O ML recebe bytes; o navegador recebe por streaming do Laravel, que já
  autoriza por empresa.
- **D6 — Formato original preservado** (JPEG/PNG, o que o validador do Publicador aceita). WebP **só** em
  miniatura.
- **D7 — Chave do objeto por conteúdo:** `{prefixo}/{c|m}{id}/imagens/{sha256}.{ext}`. Produto, SKU e nome
  original são metadados no banco, nunca caminho. Dedup natural por conta.
- **D8 — Foto de referência do Gemini continua efêmera e local.** Não entra no R2 nem no acervo.
- **D9 — Coordenação com o Creative Engine: combinada** (usuário, 15/10/2026).

**Gates humanos (do plano do usuário):** aprovação **antes de criar a tabela de ativos** (Fase 197) e **antes
de qualquer exclusão física** (Fase 199). Exclusão automática destrutiva em produção nunca liga sem aprovação.
Credenciais do R2: o usuário as coloca no `.env` quando pedido — **nunca coladas no chat**.

---

## Requisitos

### R2 — Infraestrutura

- [x] **R2-01** — O driver S3 do Flysystem está instalado e o disco `imagens` existe em `config/filesystems.php`,
  configurado só por variáveis de ambiente (endpoint, bucket, chaves, prefixo), com `local` como padrão.
- [x] **R2-02** — Com `IMAGENS_DISK=local` (padrão) o comportamento do sistema é idêntico ao de hoje.
- [x] **R2-03** — Um comando artisan testa a conexão (grava, lê, confere sha256 e apaga um objeto de teste sob o
  prefixo do ambiente) sem imprimir segredo.
- [x] **R2-04** — `.env.example` e a documentação listam as variáveis do R2 e o passo a passo de criação do
  bucket e da chave no painel da Cloudflare.

### ARM — Armazenamento definitivo

- [x] **ARM-01** — Toda gravação definitiva de imagem do Publicador (upload manual, aprovação de IA, uso do
  acervo, cópia de fase de kit) usa o disco `imagens`.
- [x] **ARM-02** — Leitura com compatibilidade: se o objeto não está no disco `imagens`, lê do `local`
  (caminhos antigos seguem funcionando durante a migração).
- [x] **ARM-03** — `receber()` não deixa arquivo órfão quando o insert falha.
- [x] **ARM-04** — Falha/timeout do R2 no upload devolve erro claro ao usuário e não cria linha apontando para
  objeto inexistente.
- [x] **ARM-05** — Foto de referência do Gemini e gerações não aprovadas continuam no disco local.

### ATV — Tabela de ativos (GATE: aprovação do usuário antes)

- [x] **ATV-01** — Tabela de ativos por conta (âncora MlbEmpresa/Company, `ml_user_id` quando houver) com chave
  do objeto, sha256 (único por conta), mime, bytes, largura, altura, origem (`upload`|`ia`), modelo de IA,
  nome original, produto/SKU/nome de origem, status (`disponivel`|`excluindo`|`excluida`|`falhou`) e datas.
- [x] **ATV-02** — `pub_imagens` ganha referência anulável ao ativo; nenhuma FK da v27 muda.
- [x] **ATV-03** — `receber()` cria ou reaproveita o ativo da conta (mesmo sha256 = mesmo objeto físico, sem
  cópia nova).
- [x] **ATV-04** — Backfill **só de banco**, idempotente e com dry-run: registra como ativos as `pub_imagens`
  existentes com arquivo e as criativas aprovadas.
- [x] **ATV-05** — Migration segura em MariaDB (nomes de índice ≤ 64, nullable onde há `nullOnDelete`, índice
  antes de FK) e com branch SQLite quando preciso.

### ACV — Acervo

- [x] **ACV-01** — O acervo mostra uploads manuais e imagens de IA aprovadas da conta; não mostra IA não
  aprovada nem reprovada.
- [x] **ACV-02** — Filtros por origem, produto, SKU/nome e período; ordenação por data; paginação.
- [x] **ACV-03** — Miniaturas WebP (GD) geradas e guardadas; a grade nunca carrega o original.
- [x] **ACV-04** — Visualização ampliada do original sob demanda.
- [x] **ACV-05** — "Usar no anúncio" vincula o ativo existente ao rascunho sem nova cópia física.
- [x] **ACV-06** — Cada imagem exibe produto, SKU, origem e data quando disponíveis, e onde está em uso.
- [x] **ACV-07** — Corrigido o `pub_rascunho_id` dos slots (visão "este produto" e `produto_nome`).
- [x] **ACV-08** — Uma conta nunca vê nem lê imagem de outra (teste de acesso cruzado).

### EXC — Exclusão (GATE: aprovação do usuário antes de exclusão física)

- [x] **EXC-01** — Antes de excluir, o sistema lista onde o ativo está em uso (rascunhos, conjuntos de
  aprovação da v27, anúncios) e bloqueia quando a exclusão quebraria um rascunho ou histórico.
- [x] **EXC-02** — Excluir do acervo marca o ativo como `excluindo`; um job idempotente com retry apaga o objeto
  e confirma o status.
- [x] **EXC-03** — Remover a foto de um rascunho remove o vínculo, não o objeto físico, enquanto houver outra
  referência.
- [x] **EXC-04** — Gerações de IA não aprovadas em `creative-geradas/` são limpas por prazo configurável, com
  dry-run.
- [x] **EXC-05** — Exclusão em lote com confirmação e o mesmo bloqueio de referências.

### MIG — Migração do legado

- [ ] **MIG-01** — Inventário de produção somente leitura (volume e contagem por pasta) antes de migrar.
- [ ] **MIG-02** — Comando idempotente local → R2 que confere sha256 e tamanho, retoma de onde parou e não
  duplica.
- [ ] **MIG-03** — Arquivos locais preservados por 30 dias após a migração; rollback = voltar `IMAGENS_DISK`.
- [ ] **MIG-04** — Validação pós-migração: acervo e rascunhos exibem tudo; anúncios publicados não mudam.

### RET — Retenção e custo

- [x] **RET-01** — Relatório por conta: imagens, volume, uploads e gerações por mês, crescimento.
- [x] **RET-02** — Relatório dry-run de descartáveis (sem vínculo, rascunho abandonado, órfãos no R2, conta
  inativa > 90 dias pelo critério que a operação definir) — **sem excluir nada**.

---

## Fora do escopo

- Assistente antigo (`AnunciarML`): não guarda arquivo local (vai direto ao ML) — nada a migrar.
- URL pública, domínio customizado e CORS (D5).
- Classes de armazenamento do R2 (Infrequent Access) — só com o relatório RET-01 em mãos.
- Exclusão automática de imagem definitiva por inatividade — só relatório (RET-02).
- Fotos do Portal do cliente (`estrutura/{company}/...`) — seguem no disco atual nesta milestone.

## Rastreabilidade

| Fase | Requisitos |
|---|---|
| 195 | R2-01..04 |
| 196 | ARM-01..05 |
| 197 | ATV-01..05 |
| 198 | ACV-01..08 |
| 199 | EXC-01..05 |
| 200 | MIG-01..04 |
| 201 | RET-01..02 |
