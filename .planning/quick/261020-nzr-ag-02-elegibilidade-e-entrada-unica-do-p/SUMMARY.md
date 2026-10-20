---
quick_id: 261020-nzr
status: complete
---
# AG-02 — resumo

**Entregue:**
- `ElegibilidadeDoAgente::avaliar()` — contrato do plano §7.2 (`elegivel`, `versao_dos_fatos`, `pendencias[{codigo, mensagem, origem, url_correcao}]`). Sem ML e sem IA. Recorte do piloto no servidor: produto unitário do Portal, a modalidade configurada como ÚNICO alvo ativo, uma variante ativa — fora disso bloqueia e explica, sem desativar nada. Do "produto completo" do Portal entram medidas, peso, custo, categoria e frete ME1 (família/ambiente não vão ao anúncio; título a IA gera). Mais: ficha técnica (`fichaCompleta`), foto utilizável, estoque ≥ mínimo da loja, preço da modalidade.
- `EntradaDoPreparo` — a entrada única. `pedir()` (manual/automática) e `aoPreparar()` (gancho do save). Elegibilidade → admissão (uma viva por produto) → só quem admitiu chama `avaliarIa()`, o preparo de sempre. Incompleto = execução `bloqueada` com os motivos, sem gasto. Fatos novos com execução viva atualizam a mesma. Cota de IA esgotada/preparo desligado = `bloqueada` com motivo, não "pronto".
- Gancho em `PreparoIaDoRascunhoService::preparar()`: conta com agente ligado passa pela entrada; sem agente, `aoPreparar()` devolve null e nada muda. Conta ligada e pausada NÃO chama a IA pelo caminho antigo.

**Testes:** `EntradaDoPreparoDoAgenteTest` 8/8; pasta `Agente` 22/22 (82 asserções). Regressão: `Lote/*`, `PreparoIaAoSalvarNoPortalTest`, `CriativosAutomaticosTest`, `PortalParaRascunhoTest` — 113/113. O teste "edição da equipe fica" tem controle (sem a edição a cadeia escreve a descrição) para não passar à toa.

**Pendente / limites declarados:**
- `url_correcao` das pendências de origem `cadastro` é null: a correção é no Portal, que é rota do CLIENTE (`portal.auth.*`). Pendências do Publicador apontam para o editor. Decidir o destino interno na AG-07.
- "Preparar agora" não re-sincroniza o Portal antes (usa o rascunho como está); o save sincroniza.
- Varredura agendada (origem `automatica`) e limite diário/teto de fila: AG-06. Fim da execução `preparando` → encaminhamento à Aprovação: AG-03.
- Prova da migration do AG-01 no MariaDB local segue pendente.
