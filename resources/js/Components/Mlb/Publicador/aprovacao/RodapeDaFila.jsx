import { cn } from '@/lib/utils';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 187, plano 09 — O RODAPÉ DA FILA: a legenda dos anéis e dos quadrados
// (FILA-16).
//
// ⚠️⚠️ **"Restaurar dados de exemplo" NÃO EXISTE NO PRODUTO** — FILA-16 e critério
// 13. É recurso de PROTÓTIPO, junto com o `localStorage` (`KEY='ecf-aprovacao-v1'`,
// `:914`): no protótipo os dados nascem de uma semente no navegador, e o botão
// restaura essa semente. Aqui os dados são de produção; "restaurar exemplo" não
// teria o que restaurar, e um botão sem destino é a definição de enfeite. Há gate
// de ausência lendo o arquivo **SEM COMENTÁRIO**, com ISCA — e esta prosa cita a
// frase **de propósito**, para provar que a remoção de comentário funciona.
// ⚠️ Ler o arquivo COM comentário faria o gate reprovar **pelo próprio conserto**
// (uma palavra no docblock que explica "não uso isso" casa com o gate que conta
// isso): foi medido em 5 dos 12 planos da Fase 184.
//
// ⚠️⚠️ **MEDIDO E REPRODUZIDO: A LEGENDA NÃO TEM O ANEL DE FALHA NEM O QUADRADO
// DE REGENERANDO**, embora os dois existam na tabela. É assim no protótipo
// (`:193`-`:198`), e está **reproduzido de propósito** — acrescentar entradas
// seria desenho novo sem pedido. Registrado na SUMMARY como divergência observada.
//
// ⚠️ **COR NUNCA É O ÚNICO SINAL** (regra permanente) — e este rodapé é
// literalmente a peça que torna isso verdade na tabela: ele é a legenda que diz o
// que cada forma significa, em TEXTO.
//
// ⚠️ **DIVERGÊNCIA DE PONTEIRO DO PLANO, MEDIDA POR SCRIPT nesta sessão** em
// `design_handoff_publicador/Aprovacao.dc.html` (1432 linhas,
// sha256 `68624883ce35547e`). O plano aponta o rodapé em `:196`-`:204`; os números
// REAIS são **5 linhas acima**:
//   · rodapé                         → `:191`  (o plano diz `:196`)
//   · grupo de legendas              → `:192`  (o plano diz `:197`)
//   · as 6 legendas                  → `:193`-`:198` (o plano diz `:198`-`:203`)
//   · "Restaurar dados de exemplo"   → `:200`  (o plano diz `:204`)
// Os VALORES da tabela do plano estão certos; os PONTEIROS, não. As tabelas deste
// projeto erram por omissão e por referência, quase nunca por valor.
// ═══════════════════════════════════════════════════════════════════════════

/** `:191` — o rodapé. */
export const CLASSES_RODAPE =
    'flex h-[42px] items-center justify-between border-t border-pub-line-subtle bg-pub-sunken px-[16px] text-[12px] text-pub-txt-4';

/** `:192` — o grupo das legendas. */
export const CLASSES_GRUPO = 'flex items-center gap-[14px]';

/** `:193` — um item da legenda. */
export const CLASSES_ITEM = 'flex items-center gap-[6px]';

/** `:193`-`:198` — os 10px comuns a todas as formas. */
export const CLASSES_FORMA = 'h-[10px] w-[10px] flex-none';

/**
 * As **SEIS** entradas da legenda, na ordem do protótipo (`:193`-`:198`).
 *
 * ⚠️ Os rótulos são **BYTE A BYTE** os do protótipo, inclusive a caixa: os três
 * primeiros começam com MAIÚSCULA (são estados da oferta) e os três últimos com
 * minúscula (são estados de FOTO, e no protótipo eles aparecem assim). Parece
 * inconsistência e **não é**: a caixa diferente é o que separa "o que é a oferta"
 * de "o que é a foto" na mesma linha.
 *
 * ⚠️ **MEDIDO: NÃO HÁ entrada para o anel de FALHA nem para o quadrado de
 * REGENERANDO**, embora os dois estados existam na tabela. Reproduzido, não
 * consertado.
 */
export const LEGENDAS = [
    // `:193` — o anel de "ninguém analisou ainda": só a borda amarela.
    { chave: 'nao_analisada', rotulo: 'Não analisada', classes: 'rounded-full border-[1.5px] border-pub-accent' },
    // `:194` — o anel de "revisão parcial salva": meia-lua pelo gradiente do token.
    { chave: 'revisao_parcial', rotulo: 'Revisão parcial', classes: 'rounded-full border-[1.5px] border-pub-txt-2 bg-pub-anel-parcial' },
    // `:195` — o anel de "aprovada": preenchido.
    { chave: 'aprovada', rotulo: 'Aprovada', classes: 'rounded-full bg-pub-ok' },
    // `:196` — o quadrado da foto aprovada.
    { chave: 'foto_aprovada', rotulo: 'foto aprovada', classes: 'rounded-[3px] bg-pub-ok' },
    // `:197` — o quadrado da foto a conferir: só a borda âmbar.
    { chave: 'foto_a_conferir', rotulo: 'a conferir', classes: 'rounded-[3px] border-[1.5px] border-pub-warn-txt' },
    // `:198` — o quadrado da foto com alerta: borda vermelha + fundo a 30%.
    { chave: 'foto_com_alerta', rotulo: 'com alerta', classes: 'rounded-[3px] border-[1.5px] border-pub-danger bg-pub-danger/[0.3]' },
];

/**
 * O rodapé da fila.
 *
 * ⚠️ Nenhuma prop: ele é **estático por natureza** — a legenda não depende de
 * dado nenhum. Receber dado aqui abriria caminho para a legenda "sumir" quando a
 * fila estiver vazia, e é justamente quando a tela está vazia que a pessoa mais
 * precisa saber o que as formas significam.
 */
export default function RodapeDaFila() {
    return (
        <div className={CLASSES_RODAPE}>
            <span className={CLASSES_GRUPO}>
                {LEGENDAS.map((entrada) => {
                    // ⚠️⚠️ FLAG DENTRO DO CALLBACK: o Rollup elimina variável de
                    // escopo do componente usada dentro de `.map()`, e o
                    // `ReferenceError` só aparece no bundle.
                    const classesDaForma = cn(CLASSES_FORMA, entrada.classes);

                    return (
                        <span key={entrada.chave} className={CLASSES_ITEM}>
                            <span className={classesDaForma} />
                            {entrada.rotulo}
                        </span>
                    );
                })}
            </span>
        </div>
    );
}
