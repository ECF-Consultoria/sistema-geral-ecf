# Fase 196 — Disco configurável nos pontos de gravação e leitura (v28.0)

**Status:** concluída em 15/10/2026 · **Requisitos:** ARM-01, ARM-02, ARM-03, ARM-04, ARM-05

## O que foi feito

- **`App\Support\Publicador\Imagem\ArquivosDeImagem`** — o único caminho para o arquivo de `pub_imagens.caminho`:
  grava no disco `filesystems.imagens`; lê, confere e serve desse disco e, se o objeto não estiver lá, do `local`
  (fotos anteriores à virada); apaga dos dois. Com `IMAGENS_DISK=local` os dois são o mesmo disco.
- Trocados os `'local'` cravados por ele em:
  - `ImagemAssetService` (receber, enviarAoMl, remover);
  - `CriarFaseService::copiarImagens` (cópia de fotos ao criar fase de kit; com R2 vira HTTP dentro da transação,
    mas o rollback de disco segue por `$escritos`);
  - `ExcluirProdutoService` (arquivos e pasta vazia, depois do commit);
  - `MlbPublicadorController::arquivoFoto` (streaming ao navegador);
  - `PublicadorCriativoReferenciaService` (filtro e cópia da foto do rascunho para a referência do Gemini — trocou
    `->path()`, que não existe no R2, por bytes num temporário apagado no `finally`);
  - `CriativosAutomaticosService` (filtro de fotos com arquivo).
- **ARM-03:** `receber()` apaga o arquivo recém-gravado quando o insert falha de vez (na corrida do unique não
  apaga — o caminho é o mesmo da linha que ganhou).
- **ARM-04:** falha do storage em `receber()` devolve o bloqueio **V-IMG-12** ("Não foi possível guardar a foto
  agora…") sem criar linha; `enviarAoMl()` com arquivo ilegível marca `failed` sem mandar corpo vazio ao ML.
- **ARM-05 / D3 / D8:** `creative-geradas/` e `creative-referencias/` continuam no `local` — não foram tocados
  `GerarCriativoIaJob`, `ReferenciaEfemeraService`, `CreativeJuiz`, aprovação e acervo de criativos (eles leem a
  geração local e entregam a `receber()`, que já grava no disco das imagens).

## Verificação

- `tests/Feature/Phase196/DiscoDasImagensNoPublicadorTest.php`: 7 testes / 24 asserções (disco `r2` falso).
- Regressão: ver `## Regressão` abaixo.

## Fica para depois

- A chave do objeto ainda é `publicador/{rascunho_id}/{sha256}.{ext}` (cópia física por rascunho). A chave por
  conta e a deduplicação vêm com a tabela de ativos (Fase 197, com gate).

## Regressão

- `Publicador` + `Phase160/161/162/165/170/171/195/196` + `Unit/Phase160/162`: **2129 testes, 18.491 asserções, OK**
  (1 incompleto e 107 depreciações já existentes).
- `Quick261003L8o`, `Quick261007`, `Quick261007Amb`, `Quick261007Rmv`: 25 testes OK.
