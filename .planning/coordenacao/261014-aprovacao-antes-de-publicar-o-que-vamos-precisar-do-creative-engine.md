# Área de aprovação antes de publicar — o que ela vai precisar do Creative Engine

**Escrito em 14/10/2026** pela sessão do Publicador, ao abrir a milestone da **aprovação humana antes
da publicação** (handoff de design em `design_handoff_publicador/README-v2.md` + `Aprovacao.dc.html`).

**Decisão da gestão da ECF, em reunião de 14/10:** o sistema deve publicar de forma **autônoma**, mas
com **aprovação humana antes de ir ao ar**. A área nova é esse portão.

---

## Por que isto é do seu interesse direto

⚠️ **A automação que a gestão pediu JÁ EXISTE do seu lado, pronta e DESLIGADA, esperando você.**

`CriativosAutomaticosService` (10/10/2026) tem a cadeia completa — produto chega do Portal com ficha
e fotos → título → Modelo → descrição → **criativos automáticos** — com sete condições de segurança,
travada em `publicador.criativos_auto.ativo = false`. O docblock diz, textualmente, que ela espera
*"depois que o dono do Creative Engine ajustar o lado dele"*, e aponta para
`.planning/coordenacao/261010-criativos-automaticos.md`.

➡️ **O portão de aprovação é o que torna seguro ligar aquela chave.** Hoje, ligar significaria imagem
de IA indo ao ar sem ninguém conferir. Com a fila de aprovação, significa imagem de IA entrando numa
fila onde alguém aprova foto por foto antes de publicar. **São peças da mesma decisão.**

---

## O que a área nova vai precisar do seu lado

### 1. LER o estado de validação da imagem (sem escrever)
`MlAnuncioCriativo` já tem `validacao_status` com `pendente` / `aprovada` / `reprovada` /
`indisponivel` (Fase 162, D-06) e `status` com `pendente`/`rodando`/`pronto`/`aprovado`/`erro`.
A fila precisa **ler** isso para mostrar "N fotos · N geradas por IA" e o resumo colorido por imagem.

⚠️ **Não vamos duplicar a sua régua.** Se você mudar os valores ou a semântica, nos diga — a fila
mostra o que você gravou.

### 2. ACIONAR a regeneração de UMA imagem, com referências
O desenho tem um modal "Gerar novamente com IA" que regenera **só uma imagem** e manda:
- a imagem atual e a posição dela na galeria;
- **chips de problema** (pés/base, cor, formato/proporção, material/textura, peça duplicada ou
  ausente, quantidade do kit, texto ilegível, fundo/cenário);
- uma **instrução em texto livre**;
- **imagens de referência**: as originais do produto (a referência daquela imagem já vem marcada)
  **e fotos enviadas pelo usuário na hora** (até 6).

✅ **MEDIDO EM 14/10, DEPOIS DE ESCREVER ESTE AVISO: as quatro peças JÁ EXISTEM do seu lado.**
Eu havia escrito aqui que isso "pode não existir" porque o Engine gera KIT (2 imagens, ≈ US$ 0,20).
Fui medir e estava errado — **retiro a pergunta**:

| peça do desenho | o que já existe |
|---|---|
| gerar **uma** imagem | `GeminiImageProvider::gerarImagem(CreativeGenerationRequest)` |
| instrução livre do operador | `CreativePromptBuilder::paraSlot(..., int $regeneracao, ?string $ajusteOperador)` |
| permissão de regenerar | `CreativePermissao::podeRegenerar(User)` |
| **fotos enviadas pelo usuário** | `ReferenciaEfemeraService::guardar(MlAnuncioCriativo, array $arquivos)` |

➡️ **Então o modal "Gerar novamente com IA" do desenho é COMPOSIÇÃO do que você já tem, não
serviço novo.** Vamos **chamar**, nunca reimplementar.

⚠️ **O que ainda pode faltar, e é pergunta de verdade:**
1. os **chips de problema** do desenho (pés/base, cor, formato, material, peça duplicada ou ausente,
   quantidade do kit, texto ilegível, fundo) são **estruturados**. Hoje o `$ajusteOperador` é **texto
   livre**. Transformamos os chips em texto na nossa borda, ou vale guardar o problema de forma
   estruturada do seu lado (serve ao seu `CreativeJuiz` também)?
2. o `ReferenciaEfemeraService` indexa por **`MlAnuncioCriativo`**. A nossa fila trabalha em cima de
   **oferta/conjunto de fotos** do Publicador novo. Precisamos conferir se o criativo é sempre
   alcançável a partir da imagem que estamos revisando.

### 3. A foto ORIGINAL de referência de cada imagem de IA
A revisão detalhada compara **lado a lado** "gerada por IA" × "original de referência", com zoom
sincronizado. Precisamos saber **qual original** gerou cada imagem.

✅ **MEDIDO EM 14/10, TAMBÉM DEPOIS DE ESCREVER ESTE AVISO — e isto também é um recuo meu:**
eu havia escrito que "é melhor vir do seu lado". **Não é: é nosso, e você não precisa fazer nada.**

| o que eu perguntava | o que eu medi |
|---|---|
| a ponte entre a nossa imagem e o seu criativo existe? | **existe e é indexada** — `ml_anuncio_criativos.pub_imagem_id` → `pub_imagens.id`, criada pela Fase 165 (migration `2026_10_04_120000_...`, bloco (e), índice `ml_criativos_pubimg_idx`), preenchida por `PublicadorCriativoAprovacaoService:117` |
| a original de referência é sua? | **é nossa** — é uma linha de `pub_imagens` do próprio rascunho. O docblock do `PublicadorCriativoReferenciaService` (L19-32) é textual: *"As fotos do rascunho são do cliente e continuam do rascunho — nada aqui move nem apaga `pub_imagens`. A CÓPIA em `creative-referencias/{token}/` é efêmera."* |
| o vínculo "esta imagem nasceu desta original" está gravado? | **só de forma EFÊMERA** — veja abaixo |

⚠️ **O único ponto de atenção, e ele é NOSSO de resolver:** o vínculo vive em
`ml_anuncio_criativos.referencias`, que é apagado **duas vezes** — na aprovação
(`MlbAnuncioController::criativoAprovar()`) e pela varredura diária `creative:limpar-referencias`,
janela padrão de **48h** (`services.creative.retencao_referencias_horas`), carimbada em
`referencias_apagadas_em`.
➡️ **Então nós vamos gravar o vínculo duravelmente do nosso lado**, no momento da geração, a partir
dos ids que `selecionarFotos()` já recebe. **Não mexemos em `referencias`, não mexemos na retenção de
48h e não mexemos no `creative:limpar-referencias`** — a janela é decisão sua (D-02 da v24), com o
incidente do ECF Drive citado no seu próprio docblock como razão do desenho. Se algum dia parecer que
precisamos mudar isso, **paramos e perguntamos**.
⚠️ **Consequência que assumimos, e que declaramos na nossa tela:** todo criativo gerado **antes** desse
vínculo existir fica **sem original para comparar**, e a comparação mostra *"original de referência não
disponível"* em vez de painel vazio ou da imagem de IA repetida. Na estreia, isso vale para **todo o
acervo atual**.

### 4. O que NÃO vamos tocar
⚠️ `app/Services/Creative/`, os endpoints `criativo*` do `MlbAnuncioController`, `AnunciarML.jsx`,
`PainelCriativosIa.jsx` e `KitCriativosGrade.jsx` seguem **seus**. `PublicadorCriativoAprovacaoService`
é **seu** — vamos **reusar**, não reescrever. Se a área nova precisar de mudança ali, **paramos e
perguntamos**, como fizemos no aviso dos gates do ERP (e aquele deu certo: você mudou o desenho e os
três gates ficaram verdes sem tocar asserção nenhuma).

---

## O que a área nova traz de novo, e que é NOSSO

Para você saber onde a fronteira cai. Nada disto existe hoje, e é o que a milestone vai construir:

- **8 estados de andamento interno** da oferta (preparando, aguardando aprovação, em revisão pela ECF,
  ajustes solicitados, aprovado, publicando, falha, publicado). ⚠️ Hoje `pub_rascunhos` tem **6**
  estados e eles são de **publicação**, não de aprovação — eixos diferentes, e o README é explícito:
  *"o andamento interno nunca aparece na lista de Publicadas, e o status do ML nunca aparece na fila"*.
- **Estado de aprovação por imagem NA REVISÃO** — ⚠️ o `PubImagem` de hoje tem status de **upload**
  (`pending`/`uploaded`/`failed`), que é outra coisa.
- **Conjunto de fotos compartilhado** entre ofertas (Clássico e Premium do mesmo produto e formato
  usam o mesmo conjunto, e aprovar vale para as duas).
- Checklist de fidelidade, defeitos marcados, ajuste/comentário com escopo (oferta / conjunto /
  cadastro do produto), histórico por oferta, versão e `approvedVersion`, exceção autorizada por
  gestor, origem por linha financeira (`Confirmado`/`Estimado`/`Pendente`), frete do vendedor
  informado, e verificações automáticas com coordenadas na imagem.

---

## Perguntas, em ordem de urgência

1. ✅ **RESPONDIDA POR MEDIÇÃO (14/10): a regeneração de uma imagem com instrução e referências
   enviadas EXISTE** — ver a seção 2 acima. No lugar dela, as duas perguntas novas: os chips de
   problema viram texto na nossa borda ou estruturado no seu lado, e se o `MlAnuncioCriativo` é
   sempre alcançável a partir da imagem que a fila revisa.
2. ✅ **RESPONDIDA POR MEDIÇÃO (14/10): o vínculo é NOSSO de gravar, e a ponte já existe** — ver a
   seção 3 acima. **Não precisamos de nada seu aqui**, e não vamos encostar na retenção de 48h.
3. **Você pretende mexer em `validacao_status`, no `PublicadorCriativoAprovacaoService` ou nos
   endpoints de criativo nas próximas semanas?** Se sim, combinamos a ordem.
4. ⚠️ **Sobre ligar o `publicador.criativos_auto.ativo`:** o que falta do seu lado? Agora há um motivo
   forte para destravar — com a fila de aprovação, a imagem automática não vai mais ao ar sem alguém
   conferir.

**Não estamos pedindo mudança no seu plano.** É para a fronteira ficar clara antes de nós começarmos,
e para você não descobrir depois que alguém construiu por cima.
