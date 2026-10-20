---
quick_id: 261020-ozm
status: complete
---
# AG-06 — resumo

**Entregue:**
- **Capacidade na admissão** (`ExecucoesDoAgente`), sob a trava da configuração da loja: `limite_diario` conta PRODUTOS admitidos hoje (fuso SP; bloqueada/cancelada não contam) → `AG-06-LIMITE-DIARIO`; `teto_fila` conta OFERTAS esperando gente na Aprovação da conta + execuções vivas → `AG-06-TETO-FILA`. Devolver execução que já existe não reserva vaga.
- **Pausa/suspensão no worker**: `PoliticaDoAgente::recusaParaRascunho()` e dois ganchos em `PreparoIaDoRascunhoService::executarEtapa()` — antes de chamar a IA (marca `desistiu`, sem chamada) e antes de gravar (o valor que voltou espera como `adiado`, guardado no Job, sem nova chamada). Rascunho sem execução viva do agente não muda nada. O encaminhador devolve `pausada` sem criar oferta.
- **`ControleDoAgente`**: pausar/suspender/retomar; retomar continua as execuções vivas no MESMO registro (`EntradaDoPreparo::continuar`), e `avaliarIa` refaz só as etapas que não deram certo.
- **Recuperação**: `situacaoDoTexto()` (rodando/completo/incompleto/sem_preparo) substitui `textoEmAndamento`; cadeia que morreu é pedida de novo até `MAX_TENTATIVAS = 2` (coluna nova `tentativas`) e depois bloqueia com `texto_nao_concluido`. Execução `admitida` sem sinal há 10 min é continuada pela varredura. O encaminhador anda da mais esquecida para a mais recente.
- **Varredura** `publicador:agente-varrer` a cada 15 min: só DESCOBRE produto que nunca passou pelo agente (sem oferta, sem execução viva/com resultado, sem bloqueio nas últimas 24 h), no máximo 5 por loja por passada; recusa de loja (pausa, janela, limite, teto) encerra a loja na passada.

**Testes:** `LimitesPausaERecuperacaoDoAgenteTest` 8/8; pasta `Agente` 36/36. Regressão Agente+Aprovação+Lote+preparo+criativos+Portal 514/514; gate `186-04 unica-porta` passou.

**Limites declarados / pendências:**
- **G10 (EditorEmUso segurando a fila) NÃO foi tratado**: a presença é marcada pelas rotas do editor que o painel de fotos da revisão chama (`FotosDoPainelService`/`LightboxDoPainelService`, área da Fase 189) e só tem efeito quando a 193 enfileirar a publicação. Fazer depois de 189 + 193.
- Concorrência real (dois workers) não foi exercitada: SQLite dos testes ignora `lockForUpdate`. A garantia é o lock da linha da configuração no MariaDB; provar no MariaDB local junto da migration.
- Pausado e suspenso param o preparo do mesmo jeito; a parada de PUBLICAÇÃO é controle da fila de publicação (fora do agente).
- A migration `2026_10_20_100000` foi EDITADA (coluna `tentativas`) — só é seguro porque nunca rodou fora do SQLite dos testes (nem MariaDB local, nem produção).
