import CascaDeModal from './CascaDeModal';
import { ROTULO_DO_BLOQUEIO } from './vocabularioDoPortao';
import {
    inteiroDoPainel,
    listaDoPainel,
    objetoDoPainel,
    textoDoPainel,
} from './ImagemGrandeDoPainel';

// ═══════════════════════════════════════════════════════════════════════════
// Fase 188, plano 11 — MOD-02: "Solicitar exceção autorizada" (`:795`-`:804`).
//
// Critério 11: a exceção libera **SÓ** resultado negativo e margem abaixo do
// mínimo, exige **responsável + justificativa de pelo menos 10 caracteres**, e
// fica no histórico como *"§ Exceção autorizada por X · data · justificativa"*.
//
// ═══ ⚠️⚠️ A CASCA É IMPORTADA, NUNCA RECRIADA (critério 15 / MOD-05) ══════
//
// `CascaDeModal.jsx` nasceu na **Fase 187, plano 09** e está **EM PRODUÇÃO**.
// ⚠️ Duas cascas divergiriam. ➡️ Este arquivo **não** declara backdrop, caixa de
// 560px, topo nem rodapé, e `CascaDeModal.jsx` sai deste plano com `numstat` ZERO.
//
// ═══ ⚠️⚠️ POR QUE `font-pub` É DECLARADO AQUI (critério 15 / REND-05) ═════
//
// *"Conteúdo portado não herda fonte"* — medido na Fase 178. O painel desta fase
// é PORTAL, e um modal aberto de dentro dele sai da subárvore do módulo e volta a
// herdar do `document.body` (Inter). ➡️ **A casca é compartilhada e está em
// produção; a fonte é declarada AQUI para o modal continuar correto tanto dentro
// do portal do painel quanto fora dele.**
//
// ═══ ⚠️⚠️ A EXCEÇÃO LIBERA SÓ DOIS BLOQUEIOS, E A LISTA É FECHADA ═════════
//
// `PubAprovacaoExcecao::LIBERA` (`app/Models/PubAprovacaoExcecao.php:86`):
// `resultado_negativo` e `margem_abaixo_do_minimo`. **Nunca** fotos
// (`fotos_a_conferir`, `sem_fotos`, `alerta_em_foto_de_ia`), **nunca** frete
// (`frete_ausente`), **nunca** alteração não conferida, ajuste em aberto, título,
// preparação nem falha.
// ⚠️⚠️ **Lista FECHADA e não lista de proibições**, e a razão está escrita no
// modelo: *"uma lista de proibições deixa permitido tudo que ninguém lembrou de
// proibir"* — e aqui "permitido a mais" significa **publicar um anúncio que
// ninguém conferiu**, sem desfazer. ➡️ Tipo fora dos dois: **o modal NÃO ABRE**,
// nem com casca vazia. A trava real é `REGRA_EXCECAO_NAO_LIBERA` no servidor.
//
// ═══ ⚠️⚠️ "EXCEÇÃO AGUARDANDO AUTORIZAÇÃO" NÃO EXISTE ════════════════════
//
// **Decisão 6 do usuário / DADO-11:** a exceção só **REGISTRA** quem liberou. Não
// há estado de espera, não há fila de aprovação, não há *"pendente de aprovação do
// gestor"*. ⚠️ `PubAprovacaoExcecao` **nasce valendo** (o docblock do modelo diz
// isso textualmente). ➡️ Nenhum texto desta tela sugere espera.
//
// ═══ ⚠️⚠️ A PROSA DO PROTÓTIPO DE `:804` NÃO VAI PARA A TELA — DIVERGÊNCIA ═
//
// `design_handoff_publicador/Aprovacao.dc.html:804` diz *"No protótipo, a exceção
// é registrada como autorizada pelo responsável escolhido."* ⚠️ É **fala SOBRE o
// protótipo**, é jargão, e **D7 proíbe jargão na tela**. ⚠️ **Não é o protótipo
// perdendo num rótulo de interface — é prosa de documento que nunca foi rótulo.**
// ➡️ Substituída por `NOTA` abaixo, que diz o que é VERDADE no produto. Já
// registrada em `188-FID01.md` §F.2, e declarada na SUMMARY com `arquivo:linha`.
//
// ═══ ⚠️⚠️ OS NOMES DE `:1399` SÃO DADO DE EXEMPLO, E NÃO ENTRAM ══════════
//
// `:1399` traz `[{v:'RG', l:'Ricardo Gomes · gestor da conta'}, {v:'CM', l:'Carla
// Mendes · coordenação'}]`: dois nomes INVENTADOS com um CARGO inventado. ⚠️ **Não
// existe dado de "cargo na conta"** neste sistema, e escrever um cargo seria a
// tela afirmando o que não é — a mesma classe das taxas fictícias (D8).
// ➡️ A lista vem do SERVIDOR (`modais.excecao.autorizadores`, plano 188-06), com
// `descricao` sendo o número REAL de ofertas da pessoa naquela conta.
// ⚠️ **Lista vazia → o modal DIZ isso e NÃO envia** (D4): `<select>` vazio é um
// botão sem destino disfarçado.
//
// ═══ ⚠️ O MOTIVO E O SEU DETALHE VÊM DO SERVIDOR, E O `12%` É `MIN` ══════
//
// `:1399` monta `kindValue` no cliente com `brl()`/`pct()` e o `12%` ali é
// `this.MIN`, **não** literal. ⚠️ **DIVERGÊNCIA DECLARADA, e ela é a favor do
// produto:** o detalhe que esta tela mostra é o **TEXTO QUE O SERVIDOR ESCREVEU**
// (`BloqueiosDaOfertaService:200`/`:207` monta *"Resultado negativo: R$ X por
// venda"* e *"Margem de X% abaixo do mínimo de Y%"*, com o mínimo **REAL** de
// FIN-09). ➡️ **A tela não formata dinheiro nem percentual** — montá-los aqui
// seria a segunda redação do mesmo fato, e o `12%` do protótipo viraria literal.
// ⚠️ O RÓTULO é lido de `ROTULO_DO_BLOQUEIO` (`vocabularioDoPortao.jsx`), que é o
// espelho de `PubAprovacaoExcecao::ROTULOS` — uma casa só.
//
// ═══ ⚠️ O QUE ESTE ARQUIVO NÃO FAZ ══════════════════════════════════════
//
// ⚠️ **Ele não escreve.** `onSubmeter` é chamado com o pedido pronto, e a escrita
// vai pelo helper único `escreverNoPainel` (W11 / COL-04 / critério 17), que
// acrescenta `versao_esperada` SEMPRE e não engole o 422.
// ⚠️ **Ele não guarda estado.** `responsavelId` e `justificativa` são props: o pai
// (a página) é o dono, pelo mesmo contrato de `SimulacaoDePreco.jsx`.
// ⚠️ **"O modal está aberto" é GESTO e NÃO vai para a URL** — pôr isso na URL
// faria um link compartilhado abrir um modal de exceção na cara de outra pessoa.
// ═══════════════════════════════════════════════════════════════════════════

/** `:1399`/`:1395` — o título do modal e o rótulo do botão, LITERAIS. */
export const TITULO = 'Solicitar exceção autorizada';

export const ROTULO_DO_BOTAO = 'Registrar exceção autorizada';

/** `:798` — o rótulo do `<select>` de responsável. */
export const ROTULO_DO_RESPONSAVEL = 'Responsável pela autorização';

/**
 * ⚠️ `PubAprovacaoExcecao::MINIMO_JUSTIFICATIVA` = **10**, e `:1398`/`:1203` usam
 * `length < 10` para recusar — logo a trava é **`>= 10`**, não `> 10`.
 *
 * O número fica NUMA constante porque ele aparece em DOIS lugares desta tela (o
 * rótulo e a trava do botão): dois literais divergiriam na primeira edição.
 */
export const MINIMO_DA_JUSTIFICATIVA = 10;

/** `:801` — o rótulo literal, com o mínimo LIDO da constante acima. */
export const ROTULO_DA_JUSTIFICATIVA = `Justificativa (mín. ${MINIMO_DA_JUSTIFICATIVA} caracteres)`;

/** `:802` — o placeholder do `<textarea>`, byte a byte. */
export const PLACEHOLDER_DA_JUSTIFICATIVA = 'Ex.: oferta de entrada para ganhar relevância por 15 dias';

/**
 * ⚠️ `:804` — A NOTA, **substituindo a prosa do protótipo** (ver o docblock).
 *
 * Ela diz o que é VERDADE no produto: a exceção **registra** quem liberou, com
 * data e justificativa, no histórico desta oferta. ⚠️ Nada de "aguardando".
 */
export const NOTA = 'A exceção fica registrada no histórico desta oferta, com quem autorizou, a data e a justificativa.';

/** ⚠️ Lista vazia → a tela DIZ isso, e o botão não envia (D4). */
export const SEM_AUTORIZADOR = 'Nenhuma pessoa desta conta pode autorizar a exceção. Peça acesso ao time do Publicador.';

/**
 * ⚠️⚠️ As DUAS chaves que a exceção libera, LIDAS de `PubAprovacaoExcecao::LIBERA`
 * (`resultado_negativo`, `margem_abaixo_do_minimo`).
 *
 * ⚠️ Lista FECHADA, nunca lista de proibições (critério 11 / BLOQ-08).
 */
export const LIBERA = ['resultado_negativo', 'margem_abaixo_do_minimo'];

/** ⚠️ O corpo do modal, e é ele que DECLARA A FONTE DO MÓDULO (ver o docblock). */
export const CLASSES_CORPO_DO_MODAL = 'flex flex-col gap-[14px] font-pub text-pub-txt';

/** `:797` — a caixa VERMELHA do motivo. */
export const CLASSES_CAIXA_DO_MOTIVO =
    'flex flex-col gap-[3px] rounded-pub-control border border-pub-danger/[0.35] bg-pub-danger/[0.08] p-[12px]';

/** `:797` — o rótulo do motivo. */
export const CLASSES_ROTULO_DO_MOTIVO = 'text-[13px] font-semibold text-pub-danger-txt';

/** `:797` — o detalhe do motivo (o texto que o SERVIDOR escreveu). */
export const CLASSES_DETALHE_DO_MOTIVO = 'text-[12.5px] text-pub-fase1';

/** `:798`/`:801` — o `<label>` em coluna, com o texto discreto. */
export const CLASSES_ROTULO = 'flex flex-col gap-[6px] text-[12.5px] text-pub-txt-2';

/** `:799` — o `<select>` de responsável. ⚠️ `rounded-[8px]`: 8px não existe na escala. */
export const CLASSES_SELECT =
    'h-[36px] rounded-[8px] border border-pub-line-strong bg-pub-sunken px-[10px] text-[13px] text-pub-txt outline-none';

/** `:802` — o `<textarea>` de justificativa. */
export const CLASSES_TEXTAREA =
    'resize-y rounded-[8px] border border-pub-line-strong bg-pub-sunken px-[12px] py-[10px] text-[13px] leading-[1.45] text-pub-txt outline-none';

/** `:802` — `rows="3"`, do protótipo. */
export const LINHAS_DO_TEXTAREA = 3;

/** `:804` — a nota de rodapé. */
export const CLASSES_NOTA = 'text-[11.5px] text-pub-txt-4';

/** O separador entre nome e descrição do autorizador (`:1399`: `nome · descricao`). */
export const SEPARADOR_DO_AUTORIZADOR = ' · ';

/**
 * ⚠️⚠️ O tipo é um dos DOIS que a exceção libera? (critério 11 / BLOQ-08)
 *
 * Named export porque é ela que decide se o modal abre, e é ela que o gate
 * exercita com os SETE outros bloqueios (isca por BLOQUEIO).
 */
export function tipoLiberado(tipo) {
    return typeof tipo === 'string' && LIBERA.includes(tipo);
}

/**
 * ⚠️ `:1398` — a justificativa tem **pelo menos** `MINIMO_DA_JUSTIFICATIVA`
 * caracteres **depois do `trim()`**.
 *
 * ⚠️ `>=`, e não `>`: `:1203` recusa com `length < 10`, então **10 passa**.
 * ⚠️ E `trim()` antes de contar: 12 espaços + 2 letras **não** é 10.
 */
export function justificativaValida(texto) {
    const limpo = textoDoPainel(texto);

    return limpo !== null && limpo.trim().length >= MINIMO_DA_JUSTIFICATIVA;
}

/** Os autorizadores saneados, cada um com id inteiro positivo e nome não-vazio. */
export function autorizadoresDoPainel(valor) {
    const lista = [];

    for (const cru of listaDoPainel(valor)) {
        // ⚠️ Flag DENTRO do laço, e guarda de tipo ANTES de qualquer acesso.
        const pessoa = objetoDoPainel(cru);
        if (pessoa === null) continue;

        const id = inteiroDoPainel(pessoa.id);
        const nome = textoDoPainel(pessoa.nome);

        // ⚠️ `id = 0` passava pela guarda de "inteiro": exige-se POSITIVO. Id zero
        // é plausível e sairia numa rota que não existe.
        if (id === null || id <= 0 || nome === null) continue;

        lista.push({ id, nome, descricao: textoDoPainel(pessoa.descricao) });
    }

    return lista;
}

/** Quem está escolhido: o id pedido, se estiver na lista; senão o PRIMEIRO. */
export function responsavelEscolhido(autorizadores, pedido) {
    const lista = autorizadoresDoPainel(autorizadores);
    if (lista.length === 0) return null;

    const id = inteiroDoPainel(pedido);
    const achado = id === null ? null : lista.find((pessoa) => pessoa.id === id);

    return achado ?? lista[0];
}

/**
 * O corpo do modal de exceção — ⚠️ NAMED EXPORT, exercido direto pelos gates.
 *
 * @param {object} props
 * @param {unknown} [props.tipo] a chave do bloqueio (um dos dois de `LIBERA`)
 * @param {unknown} [props.detalhe] o TEXTO que o servidor escreveu para o bloqueio
 * @param {unknown} [props.autorizadores] `modais.excecao.autorizadores`
 * @param {unknown} [props.responsavelId] quem está escolhido
 * @param {unknown} [props.justificativa] o que foi digitado
 * @param {unknown} [props.onResponsavel] `(id) => void`
 * @param {unknown} [props.onJustificativa] `(texto) => void`
 */
export function CorpoDaExcecao({
    tipo = null,
    detalhe = null,
    autorizadores = null,
    responsavelId = null,
    justificativa = '',
    onResponsavel = null,
    onJustificativa = null,
}) {
    if (!tipoLiberado(tipo)) return null;

    const trocarResponsavel = typeof onResponsavel === 'function' ? onResponsavel : null;
    const escreverJustificativa = typeof onJustificativa === 'function' ? onJustificativa : null;

    // ⚠️ O rótulo vem de `vocabularioDoPortao.jsx`, que é o espelho de
    // `PubAprovacaoExcecao::ROTULOS` — uma casa só para a mesma frase.
    // ⚠️ Acesso por chave só DEPOIS de `tipoLiberado()`: `mapa[chave]` com chave
    // `{toString:null}` **LANÇA**.
    const rotulo = textoDoPainel(ROTULO_DO_BLOQUEIO[tipo]);
    const texto = textoDoPainel(detalhe);

    const lista = autorizadoresDoPainel(autorizadores);
    const escolhido = responsavelEscolhido(autorizadores, responsavelId);

    return (
        <div className={CLASSES_CORPO_DO_MODAL}>
            <div className={CLASSES_CAIXA_DO_MOTIVO}>
                {rotulo === null ? null : <span className={CLASSES_ROTULO_DO_MOTIVO}>{rotulo}</span>}
                {/* ⚠️ O detalhe é o texto do SERVIDOR: a tela não formata dinheiro
                    nem percentual, e o mínimo que aparece ali é o REAL. */}
                {texto === null ? null : <span className={CLASSES_DETALHE_DO_MOTIVO}>{texto}</span>}
            </div>

            {/* ⚠️ Lista vazia → a tela DIZ isso em vez de desenhar um `<select>`
                vazio, que é um botão sem destino disfarçado (D4). */}
            {lista.length === 0 ? (
                <span className={CLASSES_NOTA}>{SEM_AUTORIZADOR}</span>
            ) : (
                <label className={CLASSES_ROTULO}>
                    {ROTULO_DO_RESPONSAVEL}
                    <select
                        className={CLASSES_SELECT}
                        {...(trocarResponsavel === null
                            ? { defaultValue: escolhido.id }
                            : { value: escolhido.id })}
                        onChange={trocarResponsavel === null ? undefined : (evento) => {
                            // ⚠️ Flag DENTRO do callback. ⚠️ E NUNCA `String(valor)`.
                            const cru = evento?.target?.value;
                            const numero = typeof cru === 'string' ? Number.parseInt(cru, 10) : null;

                            if (!Number.isInteger(numero) || numero <= 0) return;

                            trocarResponsavel(numero);
                        }}
                    >
                        {lista.map((pessoa) => {
                            // ⚠️ Tudo que esta expressão lê é PARÂMETRO ou nome de
                            // MÓDULO, que o Rollup nunca elide.
                            const sufixo = pessoa.descricao === null ? '' : SEPARADOR_DO_AUTORIZADOR + pessoa.descricao;

                            return (
                                <option key={`autorizador-${pessoa.id}`} value={pessoa.id}>
                                    {pessoa.nome + sufixo}
                                </option>
                            );
                        })}
                    </select>
                </label>
            )}

            <label className={CLASSES_ROTULO}>
                {ROTULO_DA_JUSTIFICATIVA}
                <textarea
                    className={CLASSES_TEXTAREA}
                    rows={LINHAS_DO_TEXTAREA}
                    placeholder={PLACEHOLDER_DA_JUSTIFICATIVA}
                    {...(escreverJustificativa === null
                        ? { defaultValue: typeof justificativa === 'string' ? justificativa : '' }
                        : { value: typeof justificativa === 'string' ? justificativa : '' })}
                    onChange={escreverJustificativa === null ? undefined : (evento) => {
                        const cru = evento?.target?.value;

                        escreverJustificativa(typeof cru === 'string' ? cru : '');
                    }}
                />
            </label>

            {/* ⚠️ A nota do produto, NÃO a prosa do protótipo de `:804`. */}
            <span className={CLASSES_NOTA}>{NOTA}</span>
        </div>
    );
}

/**
 * O modal "Solicitar exceção autorizada" (MOD-02), **dentro da casca da Fase 187**.
 *
 * @param {object} props
 * @param {unknown} [props.aberto] só `true` desenha
 * @param {unknown} [props.tipo] a chave do bloqueio — ⚠️ fora de `LIBERA` NÃO abre
 * @param {unknown} [props.sub] o título da oferta (`:1399`)
 * @param {unknown} [props.detalhe] o TEXTO do bloqueio, escrito pelo servidor
 * @param {unknown} [props.autorizadores] `modais.excecao.autorizadores`
 * @param {unknown} [props.responsavelId] quem está escolhido
 * @param {unknown} [props.justificativa] o que foi digitado
 * @param {unknown} [props.onResponsavel] `(id) => void`
 * @param {unknown} [props.onJustificativa] `(texto) => void`
 * @param {unknown} [props.onFechar] `() => void`
 * @param {unknown} [props.onSubmeter] `({tipo, justificativa, responsavel_id}) => void`
 *   — ⚠️ a escrita é do CHAMADOR, pelo helper único `escreverNoPainel`
 */
export default function ModalDeExcecao({
    aberto = false,
    tipo = null,
    sub = null,
    detalhe = null,
    autorizadores = null,
    responsavelId = null,
    justificativa = '',
    onResponsavel = null,
    onJustificativa = null,
    onFechar = null,
    onSubmeter = null,
}) {
    // ⚠️⚠️ CRITÉRIO 11 — tipo fora dos DOIS: o modal **NÃO ABRE**, nem com casca
    // vazia. Abrir e recusar no clique treinaria a pessoa a tentar.
    if (aberto !== true || !tipoLiberado(tipo)) return null;

    const escolhido = responsavelEscolhido(autorizadores, responsavelId);
    const pode = escolhido !== null && justificativaValida(justificativa);
    const enviar = typeof onSubmeter === 'function' ? onSubmeter : null;

    return (
        <CascaDeModal
            aberto
            titulo={TITULO}
            sub={textoDoPainel(sub) ?? undefined}
            rotuloDoBotao={ROTULO_DO_BOTAO}
            podeSubmeter={pode}
            onFechar={onFechar}
            onSubmeter={enviar === null ? null : () => {
                // ⚠️ A guarda é REPETIDA aqui de propósito: a casca já desabilita o
                // botão, mas a régua é "o clique NÃO chama a rota", não "o botão
                // está cinza". ⚠️ Sem autorizador NADA é enviado.
                const quem = responsavelEscolhido(autorizadores, responsavelId);
                if (quem === null || !justificativaValida(justificativa)) return;

                enviar({ tipo, justificativa, responsavel_id: quem.id });
            }}
        >
            <CorpoDaExcecao
                tipo={tipo}
                detalhe={detalhe}
                autorizadores={autorizadores}
                responsavelId={responsavelId}
                justificativa={justificativa}
                onResponsavel={onResponsavel}
                onJustificativa={onJustificativa}
            />
        </CascaDeModal>
    );
}
