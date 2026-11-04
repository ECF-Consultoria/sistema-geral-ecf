// ─── Trilha de produtos — a aprovação do cliente por loja (4ª entrega, fatia 4e) ─
//
// As contas PURAS das duas pontas da aprovação (§19.16 do desenho,
// `.planning/trilha/ENTREGA-3-DESENHO.md`):
//
// - EQUIPE (a gaveta `v:{item}`, `Components/Trilha/Revisao/MandarAoCliente.jsx`): com a
//   chave da loja (`escrita.revisao.cliente`, com `__ITEM__`), o primário vira [Mandar
//   ao cliente] — `POST {revisao}` → a prévia nova com `aprovacao` —, e a equipe só
//   publica direto o anúncio que o cliente já aprovou (D56). A caixa "Aprovação do
//   cliente" diz em que pé está: com o cliente desde…, o pedido de ajuste citado, o
//   aprovado que falta publicar.
// - CLIENTE (a gaveta `v:{item}` da porta dele, `Components/Trilha/Cliente/AprovarAnuncio.jsx`):
//   `cliente_escrita.aprovacao = {previa, aprovar, ajuste}` (com `__ITEM__`) — a prévia
//   na versão dele, [Aprovar anúncio] (`POST {revisao}` → `{aprovacao, publicacao}`) e
//   [Pedir ajuste] (`POST {revisao, texto}` → `{aprovacao}`; o texto, de 1 a 1000, vai à
//   EQUIPE — D52). O cartão do anúncio dele é uma versão enxuta própria (as contas
//   abaixo): o da equipe mora na pasta `Revisao/` e na lib da prévia, que o cliente não
//   baixa.
//
// `aprovacao = {estado: com_cliente|ajuste|aprovado, rodada, enviado_em, pedido}`; na
// porta da equipe o servidor acrescenta `motivo` (o D55), `enviado_por`, `decidido_em`,
// `decidido_por`, `mudou_depois` (a revisão de agora não é a enviada) e `falta_publicar`
// (D56). 409: a revisão velha (D55), a chave desligada, não está com o cliente…; 422 em
// `errors.texto`.
//
// Sem import nenhum, de propósito: o pedaço do CLIENTE leva este arquivo, e nada da
// gaveta da equipe pode ir junto (as libs da prévia, do preço, da publicação e do
// ajuste). Por isso o marcador, a régua da mensagem do framework e as situações da
// publicação que travam são CÓPIAS — o teste confere que são iguais às de lá. Sem
// classe do Tailwind (arquivo `.js`). Nenhum texto daqui cita o marketplace, o Portal
// nem o Publicador (D59): o cliente lê boa parte deles. O que a IA fez é "do agente"
// (R9, 04/11/2026: "robô" virou "agente" para a equipe E o cliente).

/** O marcador do id do item nas URLs da revisão e da aprovação (§19.4, §19.16). */
export const MARCADOR_DO_ITEM = '__ITEM__';

/** Os estados da aprovação (`trilha_aprovacoes.estado`, §19.6). */
export const ESTADOS = Object.freeze(['com_cliente', 'ajuste', 'aprovado']);

/** O tamanho máximo do pedido de ajuste do cliente (§19.16: obrigatório, até 1000). */
export const LIMITE_DO_PEDIDO = 1000;

/**
 * As situações da PUBLICAÇÃO em que o anúncio já foi (ou está indo) para a loja: nada de mandar ao cliente. Cópia de
 * `EM_ANDAMENTO` + `publicado` de `lib/trilhaPublicar.js` (esta lib não a importa); o teste confere.
 */
export const PUBLICACAO_QUE_TRAVA = Object.freeze(['conferindo', 'na_fila', 'publicando', 'publicado']);

/** As situações da publicação que o contrato conhece (§19.13). */
const SITUACOES_DA_PUBLICACAO = ['conferindo', 'avisos', 'na_fila', 'publicando', 'publicado', 'recusado', 'erro'];

const FUSO = 'America/Sao_Paulo';

const numero = (v) => (v === null || v === undefined || v === '' || !Number.isFinite(Number(v)) ? null : Number(v));
const textoLimpo = (v) => (typeof v === 'string' && v.trim() ? v.trim() : null);
const lista = (v) => (Array.isArray(v) ? v : []);
const temChave = (objeto, chave) => !!objeto && typeof objeto === 'object' && Object.prototype.hasOwnProperty.call(objeto, chave);

/** "04/11 às 14:32" no horário de Brasília; null sem data ou com data quebrada. */
export function fmtQuando(iso) {
    if (!textoLimpo(iso)) return null;
    const data = new Date(iso);
    if (Number.isNaN(data.getTime())) return null;
    const dia = data.toLocaleDateString('pt-BR', { timeZone: FUSO, day: '2-digit', month: '2-digit' });
    const hora = data.toLocaleTimeString('pt-BR', { timeZone: FUSO, hour: '2-digit', minute: '2-digit' });

    return `${dia} às ${hora}`;
}

// ─── As URLs ─────────────────────────────────────────────────────────────────

function comItem(modelo, itemId) {
    if (typeof modelo !== 'string' || !modelo.includes(MARCADOR_DO_ITEM)) return null;
    if (itemId === null || itemId === undefined || String(itemId).trim() === '') return null;

    return modelo.trim().split(MARCADOR_DO_ITEM).join(encodeURIComponent(String(itemId)));
}

/** A URL do [Mandar ao cliente] (`escrita.revisao.cliente` com o id). Null sem o endereço (a chave da loja desligada). */
export const urlDoEnvio = (escrita, itemId) => comItem(escrita?.revisao?.cliente, itemId);

/**
 * As URLs da gaveta do cliente (`cliente_escrita.aprovacao` com o id): a prévia, aprovar e pedir ajuste. Null quando
 * falta qualquer uma — a gaveta não abre pela metade.
 *
 * @returns {?{previa: string, aprovar: string, ajuste: string}}
 */
export function urlsDoCliente(enderecos, itemId) {
    const previa = comItem(enderecos?.previa, itemId);
    const aprovar = comItem(enderecos?.aprovar, itemId);
    const ajuste = comItem(enderecos?.ajuste, itemId);

    return previa && aprovar && ajuste ? { previa, aprovar, ajuste } : null;
}

// ─── A aprovação ─────────────────────────────────────────────────────────────

/**
 * A aprovação na forma da tela: `{estado, rodada, enviado_em, decidido_em, pedido, motivo, enviado_por, decidido_por,
 * mudou_depois, falta_publicar}`. Null sem aprovação (a loja sem a chave, ou o anúncio que nunca foi ao cliente) ou com
 * um estado que o contrato não conhece. `falta_publicar` null = o servidor não disse (a porta do cliente).
 */
export function normalizarAprovacao(aprovacao) {
    if (!aprovacao || typeof aprovacao !== 'object' || !ESTADOS.includes(aprovacao.estado)) return null;
    const rodada = numero(aprovacao.rodada);

    return {
        estado: aprovacao.estado,
        rodada: rodada !== null && rodada >= 1 ? Math.floor(rodada) : null,
        enviado_em: textoLimpo(aprovacao.enviado_em),
        decidido_em: textoLimpo(aprovacao.decidido_em),
        pedido: textoLimpo(aprovacao.pedido),
        motivo: textoLimpo(aprovacao.motivo),
        enviado_por: textoLimpo(aprovacao.enviado_por),
        decidido_por: textoLimpo(aprovacao.decidido_por),
        mudou_depois: aprovacao.mudou_depois === true,
        falta_publicar: typeof aprovacao.falta_publicar === 'boolean' ? aprovacao.falta_publicar : null,
    };
}

/** A aprovação da prévia (ou de uma resposta `{aprovacao}`). */
export const aprovacaoDaPrevia = (previa) => normalizarAprovacao(previa?.aprovacao);

/** O estado da aprovação da prévia (ou null). */
export const estadoDaPrevia = (previa) => aprovacaoDaPrevia(previa)?.estado ?? null;

/** A situação de uma publicação do contrato (ou null). */
const situacaoDaPublicacao = (publicacao) => (SITUACOES_DA_PUBLICACAO.includes(publicacao?.situacao) ? publicacao.situacao : null);

const temBloqueio = (previa) => lista(previa?.bloqueios)
    .some((b) => (typeof b === 'string' ? textoLimpo(b) : textoLimpo(b?.mensagem) ?? textoLimpo(b?.texto) ?? textoLimpo(b?.message) ?? textoLimpo(b?.titulo)));

// ═══ EQUIPE: [Mandar ao cliente] ═════════════════════════════════════════════

export const ROTULO_DO_ENVIO = 'Mandar ao cliente';
export const ROTULO_DO_REENVIO = 'Mandar ao cliente de novo';
export const ROTULO_ENVIANDO = 'Enviando…';
export const ROTULO_CONFIRMAR_ENVIO = 'Mandar';

/** A pergunta da confirmação no rodapé: nada vai ao cliente sem ela. */
export const PERGUNTA_DO_ENVIO = 'Mandar este anúncio para o cliente aprovar? Ele vê a prévia como está agora.';

/** O primário desligado enquanto o anúncio está com o cliente. */
export const DICA_COM_O_CLIENTE = 'O anúncio está com o cliente: espere a resposta dele.';
export const DICA_DO_PRECO_PENDENTE = 'Salve ou descarte o preço digitado antes de mandar ao cliente.';
export const DICA_DO_PREMIUM_IGUAL = 'O Premium está com o mesmo preço do Clássico: ajuste o preço antes de mandar ao cliente.';

/** O primário desligado enquanto o ajuste pedido pela equipe anda (a 4b): o anúncio ainda vai mudar. */
export const TRAVA_DO_AJUSTE = 'Espere o ajuste terminar antes de mandar ao cliente.';

/** O aviso da página e a nota do rodapé depois do envio. */
export const AVISO_ENVIADO = 'Anúncio enviado ao cliente para aprovar.';
export const NOTA_ENVIADO = 'Enviado ao cliente.';

export const MSG_MUDOU = 'Este anúncio mudou depois que você abriu. Recarregue para ver o que mudou.';
export const MSG_CONFLITO = 'Este anúncio não pode ir ao cliente agora. Recarregue a página para ver onde ele está.';
export const MSG_CONFIRA = 'Confira o anúncio e tente de novo.';
export const MSG_PROIBIDO = 'Você não tem permissão para mandar este anúncio ao cliente.';

/** O cliente aprovou ESTA revisão do anúncio (aprovado, e a equipe não mexeu depois — `mudou_depois`)? */
const aprovadoComoEsta = (aprovacao) => aprovacao?.estado === 'aprovado' && !aprovacao.mudou_depois;

/**
 * Com a chave da loja (o endereço `escrita.revisao.cliente`), a equipe NÃO publica direto (o `POST publicar` da 4d dá
 * 409): só o anúncio que o cliente aprovou como está e não foi publicado (D56) volta a ter [Aprovar e publicar] — se a
 * equipe o mudou depois da aprovação, ele vai ao cliente de novo. Sem a chave, tudo como na 4d.
 */
export const equipePublicaDireto = ({ escrita, previa, oferta = null }) => !urlDoEnvio(escrita, oferta?.id ?? previa?.item?.id)
    || aprovadoComoEsta(aprovacaoDaPrevia(previa));

/**
 * O primário [Mandar ao cliente] (ou [Mandar ao cliente de novo], depois do pedido de ajuste ou de a equipe mudar o
 * anúncio que já tinha mandado — `mudou_depois`), ou null quando ele não aparece: sem o endereço (a chave desligada), sem
 * a prévia pronta (ou o 409 dela), fora da revisão, com bloqueio, com a publicação andando ou feita, ou aprovado como
 * está (aí o primário é o [Aprovar e publicar] da 4d). Com o anúncio com o cliente (sem mudança depois), com preço
 * digitado e não salvo ou com o Premium igual ao Clássico (o servidor recusaria), fica desligado com a dica. Enviando:
 * "Enviando…", ocupado (focável), nunca `disabled`.
 *
 * @returns {?{reenvio: boolean, rotulo: string, desligado: boolean, ocupado: boolean, dica: ?string}}
 */
export function acaoDoEnvio({ escrita, previa, oferta = null, publicacao = undefined, precoPendente = false, enviando = false }) {
    if (!urlDoEnvio(escrita, oferta?.id ?? previa?.item?.id)) return null;
    if (previa?.pronta !== true || previa?.conflito || !lista(previa?.anuncios).length) return null;
    if ((previa?.item?.etapa ?? oferta?.etapa) !== 'revisao') return null;
    if (temBloqueio(previa)) return null;
    if (PUBLICACAO_QUE_TRAVA.includes(situacaoDaPublicacao(publicacao !== undefined ? publicacao : previa?.publicacao))) return null;
    const aprovacao = aprovacaoDaPrevia(previa);
    if (aprovadoComoEsta(aprovacao)) return null;
    const reenvio = aprovacao?.estado === 'ajuste' || !!aprovacao?.mudou_depois;
    let dica = null;
    if (aprovacao?.estado === 'com_cliente' && !aprovacao.mudou_depois) dica = DICA_COM_O_CLIENTE;
    else if (precoPendente) dica = DICA_DO_PRECO_PENDENTE;
    else if (previa?.preco?.premium_igual === true) dica = DICA_DO_PREMIUM_IGUAL;

    return {
        reenvio,
        rotulo: enviando ? ROTULO_ENVIANDO : reenvio ? ROTULO_DO_REENVIO : ROTULO_DO_ENVIO,
        desligado: !!dica && !enviando,
        ocupado: !!enviando,
        dica: enviando ? null : dica,
    };
}

/** O corpo do `POST` da equipe (§19.16): a `revisao` da prévia aberta. */
export const corpoDoEnvio = (previa) => ({ revisao: previa?.revisao ?? null });

/** A resposta 200 do envio é a prévia nova (§19.5): ela, ou null quando o corpo não parece uma prévia. */
export const previaDaResposta = (resposta) => (resposta && typeof resposta === 'object' && (temChave(resposta, 'pronta') || temChave(resposta, 'anuncios')) ? resposta : null);

/**
 * O rodapé da publicação (o controle de `usePublicacaoDaPrevia`, 4d) sem o primário quando a equipe não publica direto
 * (`envio.publicaDireto` falso): a situação de uma publicação que já anda continua à vista. Sem o controle do envio
 * (a gaveta de antes da 4e), devolve como veio.
 */
export const semPublicarDireto = (publicar, envio) => (publicar && envio && envio.publicaDireto === false
    ? { ...publicar, acao: null, confirmando: false }
    : publicar);

/**
 * O controle do envio com a trava do ajuste (o `travaDaPublicacao` do hook da 4b): o primário desligado com a dica
 * daqui. O que já está desligado (com o cliente, preço pendente) e o "Enviando…" não mudam.
 */
export const comTravaDoEnvio = (envio, trava) => (trava && envio?.acao && !envio.acao.ocupado && !envio.acao.desligado
    ? { ...envio, acao: { ...envio.acao, desligado: true, dica: TRAVA_DO_AJUSTE } }
    : envio);

/** O estado do rodapé do envio ao abrir (e a cada prévia nova). */
export const ESTADO_DO_ENVIO = Object.freeze({ confirmando: false, enviando: false, erro: null, nota: null });

/** Depois do pedido (`{previa, erro}`): sai do "enviando"; sem erro, a nota "Enviado ao cliente.". */
export function estadoDepoisDoEnvio(estado, resposta) {
    const erro = resposta?.erro ?? null;

    return { ...ESTADO_DO_ENVIO, ...estado, confirmando: false, enviando: false, erro, nota: erro ? null : NOTA_ENVIADO };
}

/** O aviso de que o anúncio mudou depois do envio (ou da aprovação) e precisa ir de novo ao cliente. */
export const AVISO_MUDOU_DEPOIS = 'A equipe mudou o anúncio depois de enviar: mande de novo para o cliente ver como está.';

/**
 * A caixa "Aprovação do cliente" da gaveta da equipe (com a chave da loja): o título, o tom, o detalhe, o pedido do
 * cliente (citado, como ele escreveu), a rodada, quem mandou ou decidiu e se o aprovado ainda falta publicar (D56 —
 * o `falta_publicar` do servidor; sem ele, nenhuma publicação andando). `publicacao` undefined = a da prévia.
 *
 * @returns {{estado: ?string, tom: string, titulo: string, detalhe: ?string, pedido: ?string, rodada: ?string,
 *   quem: ?string, mudouDepois: boolean, faltaPublicar: boolean}}
 */
export function vistaDaAprovacao(previa, publicacao = undefined) {
    const a = aprovacaoDaPrevia(previa);
    const rodada = a?.rodada >= 2 ? `${a.rodada}ª rodada` : null;
    const base = { estado: a?.estado ?? null, pedido: null, rodada, quem: null, mudouDepois: !!a?.mudou_depois, faltaPublicar: false };
    if (!a) {
        return {
            ...base,
            tom: 'neutro',
            titulo: 'Esta loja aprova os anúncios antes de publicar.',
            detalhe: 'Mande a prévia ao cliente: ele aprova (e o anúncio entra na fila de publicação) ou pede ajuste.',
        };
    }
    if (a.estado === 'com_cliente') {
        const quando = fmtQuando(a.enviado_em);

        return {
            ...base,
            tom: a.mudou_depois ? 'warn' : 'run',
            titulo: quando ? `Com o cliente para aprovar desde ${quando}` : 'Com o cliente para aprovar',
            quem: a.enviado_por ? `Mandado por ${a.enviado_por}.` : null,
            detalhe: a.mudou_depois
                ? AVISO_MUDOU_DEPOIS
                : 'Se a equipe mudar o anúncio agora (preço, ajuste), a aprovação dele não vale: o anúncio volta para a equipe e vai de novo ao cliente.',
        };
    }
    if (a.estado === 'ajuste') {
        const quando = fmtQuando(a.decidido_em);
        if (a.pedido) {
            return {
                ...base,
                tom: 'warn',
                titulo: 'O cliente pediu ajuste',
                pedido: a.pedido,
                quem: a.decidido_por ? `Pedido de ${a.decidido_por}.` : null,
                detalhe: `${quando ? `Pedido em ${quando}. ` : ''}Ajuste o que precisar e mande de novo.`,
            };
        }

        return {
            ...base,
            tom: 'warn',
            titulo: 'O anúncio voltou para a equipe',
            detalhe: `${a.motivo ?? 'A equipe mudou o anúncio depois de enviar.'} Confira e mande de novo.`,
        };
    }
    const quando = fmtQuando(a.decidido_em);
    const situacao = situacaoDaPublicacao(publicacao !== undefined ? publicacao : previa?.publicacao);
    const faltaPublicar = !a.mudou_depois && (a.falta_publicar ?? !PUBLICACAO_QUE_TRAVA.includes(situacao));
    let detalhe = null;
    if (a.mudou_depois) detalhe = AVISO_MUDOU_DEPOIS;
    else if (faltaPublicar) detalhe = situacao ? 'Falta publicar: a publicação precisa da equipe (veja no rodapé).' : 'Falta publicar: confira e use “Aprovar e publicar”.';

    return {
        ...base,
        tom: a.mudou_depois ? 'warn' : 'ok',
        titulo: quando ? `Aprovado pelo cliente em ${quando}` : 'Aprovado pelo cliente',
        quem: a.decidido_por ? `Aprovado por ${a.decidido_por}.` : null,
        detalhe,
        faltaPublicar,
    };
}

// ─── Os erros (as duas pontas) ───────────────────────────────────────────────

// O idioma do Laravel nesta instalação é o inglês: a mensagem padrão do framework (e a das regras sem texto próprio)
// não vai para a tela — a cópia da régua de `lib/trilhaPublicar.js`, que esta lib não importa.
const DO_FRAMEWORK = /^(?:conflict|not found|forbidden|server error|unauthenticated|page expired|too many (?:attempts|requests)|this action is unauthorized|the given data was invalid)\b|^the .+ (?:field|must|may|is)\b/i;

const doServidor = (m) => {
    const t = textoLimpo(m);

    return t && !DO_FRAMEWORK.test(t) ? t : null;
};

/** A 1ª mensagem que serve de `errors` — as de `campo` (e `campo.0`…) primeiro. */
function primeiraDosErros(errors, campo = null) {
    const entradas = errors && typeof errors === 'object' ? Object.entries(errors) : [];
    const doCampo = (k) => campo !== null && (k === campo || k.startsWith(`${campo}.`));
    const ordem = [...entradas.filter(([k]) => doCampo(k)), ...entradas.filter(([k]) => !doCampo(k))];

    return ordem.flatMap(([, m]) => [m].flat()).map(doServidor).find(Boolean) ?? null;
}

const dadosDo = (erro) => (erro?.response?.data && typeof erro.response.data === 'object' ? erro.response.data : {});

/**
 * O que um erro do [Mandar ao cliente] quer dizer para o rodapé da equipe:
 * - `revisao` — 409 com `revisao_atual` (sem `travado`, o padrão F6): o anúncio mudou depois que a gaveta abriu → [Recarregar];
 * - `conflito` — os outros 409 (a chave desligada, fora da revisão, na fila, o ajuste andando, já com o cliente);
 * - `proibido` — 403; `invalido` — 422 (o Premium igual ao Clássico);
 * - `outro` — o resto (rede, 429, 500…): `comum`, o texto de `mensagemDoErro` que quem chama passa.
 *
 * @returns {{tipo: 'revisao'|'conflito'|'proibido'|'invalido'|'outro', mensagem: string, revisaoAtual: ?number}}
 */
export function erroDoEnvio(erro, comum = null) {
    const status = erro?.response?.status;
    const data = dadosDo(erro);
    if (status === 409) {
        if (temChave(data, 'revisao_atual') && !data.travado) {
            return { tipo: 'revisao', mensagem: doServidor(data.message) ?? MSG_MUDOU, revisaoAtual: numero(data.revisao_atual) };
        }

        return { tipo: 'conflito', mensagem: doServidor(data.message) ?? doServidor(data.motivo) ?? MSG_CONFLITO, revisaoAtual: null };
    }
    if (status === 403) return { tipo: 'proibido', mensagem: doServidor(data.message) ?? MSG_PROIBIDO, revisaoAtual: null };
    if (status === 422) return { tipo: 'invalido', mensagem: primeiraDosErros(data.errors) ?? doServidor(data.message) ?? MSG_CONFIRA, revisaoAtual: null };

    return { tipo: 'outro', mensagem: textoLimpo(comum) ?? 'Não deu certo. Tente de novo.', revisaoAtual: null };
}

// ═══ CLIENTE: [Aprovar anúncio] e [Pedir ajuste] ═════════════════════════════

export const ROTULO_DO_APROVAR = 'Aprovar anúncio';
export const ROTULO_CONFIRMAR_APROVACAO = 'Aprovar';
export const ROTULO_APROVANDO = 'Aprovando…';
export const ROTULO_DO_PEDIR_AJUSTE = 'Pedir ajuste';
export const ROTULO_DO_ENVIAR_PEDIDO = 'Enviar pedido';
export const ROTULO_ENVIANDO_PEDIDO = 'Enviando…';

/** A confirmação do [Aprovar anúncio] (§19.16). */
export const PERGUNTA_DO_APROVAR = 'O anúncio vai ao ar depois da sua aprovação.';

export const ROTULO_DO_CAMPO = 'O que precisa mudar no anúncio?';
export const DICA_DO_CAMPO = 'A equipe lê o seu pedido, ajusta o anúncio e manda de novo para você.';
export const MSG_PEDIDO_VAZIO = 'Escreva o que precisa mudar no anúncio.';
export const MSG_PEDIDO_LONGO = `O pedido passa de ${LIMITE_DO_PEDIDO} caracteres. Resuma um pouco.`;

export const MSG_APROVADO = 'Aprovado. A equipe publica em seguida.';
export const MSG_PEDIDO_ENVIADO = 'Pedido enviado. A equipe ajusta o anúncio e manda de novo para você.';

/** As notas do rodapé do cliente logo depois de cada resposta (a caixa de cima diz o resto). */
export const NOTA_APROVADO = 'Aprovação registrada.';
export const NOTA_PEDIDO = 'Pedido enviado à equipe.';

/** A guarda da gaveta: o pedido escrito e não enviado não se perde sem perguntar. */
export const PERGUNTA_DA_SAIDA = 'Descartar o pedido de ajuste que você escreveu?';
export const MSG_MUDOU_PARA_O_CLIENTE = 'Este anúncio mudou depois que a equipe mandou para você. Recarregue para ver como ele está agora.';
export const MSG_INDISPONIVEL = 'Este anúncio não está com você para aprovar agora. A equipe avisa quando ele estiver pronto.';
export const MSG_EM_PREPARO = 'Este anúncio ainda está sendo preparado pelo agente.';

/**
 * As ações do rodapé do cliente: [Pedir ajuste] e [Aprovar anúncio] só com a prévia pronta, o anúncio COM ELE
 * (`com_cliente`) e as duas URLs. Null quando não há o que fazer (ele já respondeu, ou o anúncio voltou à equipe).
 *
 * @returns {?{aprovar: true, ajuste: true}}
 */
export function acoesDoCliente({ previa, urls }) {
    if (!urls?.aprovar || !urls?.ajuste) return null;
    if (previa?.pronta !== true || !lista(previa?.anuncios).length) return null;

    return estadoDaPrevia(previa) === 'com_cliente' ? { aprovar: true, ajuste: true } : null;
}

/** O corpo do [Aprovar anúncio]: a `revisao` da prévia que ele viu (D55). */
export const corpoDaAprovacao = (previa) => ({ revisao: previa?.revisao ?? null });

/** O corpo do [Pedir ajuste]: a `revisao` e o texto, sem os espaços das pontas (só essas duas chaves). */
export const corpoDoPedido = (previa, texto) => ({ revisao: previa?.revisao ?? null, texto: typeof texto === 'string' ? texto.trim() : '' });

/**
 * Confere o pedido de ajuste ANTES de enviar (o servidor confere de novo, 422 `errors.texto`): obrigatório (sem contar
 * os espaços) e até 1000 caracteres. `contador` é o "N/1000" do campo (o que o campo tem, como o `maxLength` conta).
 *
 * @returns {{ok: boolean, erro: ?string, contador: string, restantes: number}}
 */
export function conferirPedido(texto) {
    const bruto = typeof texto === 'string' ? texto : '';
    const limpo = bruto.trim();
    let erro = null;
    if (!limpo) erro = MSG_PEDIDO_VAZIO;
    else if (limpo.length > LIMITE_DO_PEDIDO) erro = MSG_PEDIDO_LONGO;

    return { ok: !erro, erro, contador: `${bruto.length}/${LIMITE_DO_PEDIDO}`, restantes: LIMITE_DO_PEDIDO - bruto.length };
}

/**
 * O que um erro do cliente quer dizer para a gaveta dele:
 * - `conflito` — 409 (o anúncio mudou depois do envio — D55 —, ou não está mais com ele): a mensagem do servidor e
 *   [Recarregar];
 * - `texto` — 422 em `errors.texto`: a mensagem fica no campo, e o texto digitado não se perde;
 * - `indisponivel` — 404 (o anúncio não está com ele): o texto neutro, sem "tentar de novo";
 * - `outro` — o resto: o 422 de outro campo, o 403, a rede…; `comum` é o texto de `mensagemDoErro`.
 *
 * @returns {{tipo: 'conflito'|'texto'|'indisponivel'|'outro', mensagem: string}}
 */
export function erroDoCliente(erro, comum = null) {
    const status = erro?.response?.status;
    const data = dadosDo(erro);
    if (status === 409) return { tipo: 'conflito', mensagem: doServidor(data.message) ?? doServidor(data.motivo) ?? MSG_MUDOU_PARA_O_CLIENTE };
    if (status === 404) return { tipo: 'indisponivel', mensagem: MSG_INDISPONIVEL };
    if (status === 422) {
        const doTexto = lista(Object.keys(data.errors ?? {})).some((k) => k === 'texto' || k.startsWith('texto.'));
        const mensagem = primeiraDosErros(data.errors, 'texto') ?? doServidor(data.message);
        if (doTexto) return { tipo: 'texto', mensagem: mensagem ?? MSG_PEDIDO_VAZIO };

        return { tipo: 'outro', mensagem: mensagem ?? 'Confira e tente de novo.' };
    }
    if (status === 403) return { tipo: 'outro', mensagem: doServidor(data.message) ?? 'Você não tem acesso a esta ação.' };

    return { tipo: 'outro', mensagem: textoLimpo(comum) ?? 'Não deu certo. Tente de novo.' };
}

/**
 * A aprovação que vem no 409 do cliente (o servidor a manda quando sabe onde o anúncio está agora: não está mais com
 * ele, ou voltou para a equipe — D55): a gaveta mostra o estado novo sem esperar o [Recarregar]. Null sem ela.
 */
export function aprovacaoDoConflito(erro) {
    if (erro?.response?.status !== 409) return null;
    const aprovacao = dadosDo(erro).aprovacao;

    return normalizarAprovacao(aprovacao) ? aprovacao : null;
}

/** A prévia com a aprovação nova (a do 409, ou de uma resposta); sem ela, como veio. */
export const previaComAprovacao = (previa, aprovacao) => (previa && normalizarAprovacao(aprovacao) ? { ...previa, aprovacao } : previa);

/** A prévia depois do [Aprovar anúncio] (`{aprovacao, publicacao}`): a aprovação e a publicação da resposta. */
export function previaDepoisDaAprovacao(previa, resposta) {
    const aprovacao = normalizarAprovacao(resposta?.aprovacao) ? resposta.aprovacao : { ...(previa?.aprovacao ?? {}), estado: 'aprovado' };
    const publicacao = temChave(resposta, 'publicacao') ? resposta.publicacao : previa?.publicacao ?? null;

    return { ...previa, aprovacao, publicacao };
}

/** A prévia depois do [Pedir ajuste] (`{aprovacao}`): a aprovação da resposta (sem ela, `ajuste` com o texto enviado). */
export function previaDepoisDoPedido(previa, resposta, texto = null) {
    const aprovacao = normalizarAprovacao(resposta?.aprovacao)
        ? resposta.aprovacao
        : { ...(previa?.aprovacao ?? {}), estado: 'ajuste', pedido: textoLimpo(texto) };

    return { ...previa, aprovacao };
}

/** O estado do rodapé do cliente ao abrir: nada aberto. `modo` = null | 'confirmando' | 'pedido'. */
export const ESTADO_DO_CLIENTE = Object.freeze({ modo: null, texto: '', enviando: null, erro: null, erroDoTexto: null, nota: null });

/** A publicação contada ao CLIENTE: só a situação, em texto neutro (sem o porquê de um erro — isso é da equipe). */
const PUBLICACAO_PARA_O_CLIENTE = {
    conferindo: 'A equipe está conferindo o anúncio para publicar.',
    na_fila: 'O anúncio está na fila de publicação.',
    publicando: 'O anúncio está sendo publicado agora.',
    publicado: 'Publicado. Nas primeiras horas o anúncio fica em análise antes de ir à venda.',
    avisos: 'A equipe está cuidando da publicação.',
    recusado: 'A equipe está cuidando da publicação.',
    erro: 'A equipe está cuidando da publicação.',
};

/** A publicação em andamento (o cliente pode pedir para ver de novo como está). */
export const publicacaoAndando = (publicacao) => ['conferindo', 'na_fila', 'publicando'].includes(situacaoDaPublicacao(publicacao));

/**
 * A caixa de situação da gaveta do CLIENTE: o título e o tom de cada estado, o detalhe e o pedido dele (citado).
 *
 * @returns {{estado: ?string, tom: string, titulo: string, detalhe: ?string, pedido: ?string}}
 */
export function vistaParaOCliente(previa) {
    const a = aprovacaoDaPrevia(previa);
    if (!a) return { estado: null, tom: 'neutro', titulo: MSG_INDISPONIVEL, detalhe: null, pedido: null };
    if (a.estado === 'com_cliente') {
        const quando = fmtQuando(a.enviado_em);

        return {
            estado: a.estado,
            tom: 'run',
            titulo: 'Este anúncio espera a sua aprovação',
            detalhe: `${quando ? `A equipe mandou em ${quando}. ` : ''}Confira o anúncio abaixo: aprove, ou peça ajuste se algo precisar mudar.`,
            pedido: null,
        };
    }
    if (a.estado === 'ajuste') {
        if (a.pedido) {
            const quando = fmtQuando(a.decidido_em);

            return {
                estado: a.estado,
                tom: 'neutro',
                titulo: 'Você pediu ajuste',
                detalhe: `${quando ? `Pedido enviado em ${quando}. ` : ''}A equipe ajusta o anúncio e manda de novo para você.`,
                pedido: a.pedido,
            };
        }

        return { estado: a.estado, tom: 'neutro', titulo: 'A equipe está ajustando este anúncio', detalhe: 'Ele mudou depois do envio: a equipe manda de novo para você aprovar.', pedido: null };
    }

    return { estado: a.estado, tom: 'ok', titulo: MSG_APROVADO, detalhe: PUBLICACAO_PARA_O_CLIENTE[situacaoDaPublicacao(previa?.publicacao)] ?? null, pedido: null };
}

// ═══ CLIENTE: o cartão do anúncio (versão enxuta própria) ═════════════════════
//
// As mesmas regras do cartão da equipe (`lib/trilhaPrevia.js`, que o cliente não baixa), com os textos dele: a foto que
// o agente fez sai "criada pelo agente" (R9), e a entrega não usa as siglas do marketplace.

/** O selo da foto criada (a `gerada` do §19.5), no texto do cliente. */
export const ROTULO_DA_CRIADA = 'criada pelo agente';

const NOME_DO_TIPO = { classico: 'Clássico', premium: 'Premium' };

const tipoDo = (anuncio) => (anuncio?.tipo == null ? null : String(anuncio.tipo));
const varianteDo = (anuncio) => {
    const v = anuncio?.variante;
    if (v === null || v === undefined) return null;

    return typeof v === 'object' ? (v.chave == null ? null : String(v.chave)) : String(v);
};

/** Os anúncios por tipo e cor (`{classico: {v1: anúncio}}`); o primeiro de cada par vale. */
function agrupar(previa) {
    const grupos = {};
    for (const anuncio of lista(previa?.anuncios)) {
        const tipo = tipoDo(anuncio);
        const variante = varianteDo(anuncio);
        if (tipo === null || variante === null) continue;
        grupos[tipo] ??= {};
        grupos[tipo][variante] ??= anuncio;
    }

    return grupos;
}

/** As abas de tipo: os que têm anúncio, na ordem de `tipos`. */
export function tiposDoAnuncio(previa) {
    const grupos = agrupar(previa);
    const saida = lista(previa?.tipos)
        .filter((t) => t?.chave != null && grupos[String(t.chave)])
        .map((t) => ({ chave: String(t.chave), nome: textoLimpo(t.nome) ?? NOME_DO_TIPO[t.chave] ?? String(t.chave) }));
    for (const chave of Object.keys(grupos)) {
        if (!saida.some((t) => t.chave === chave)) saida.push({ chave, nome: NOME_DO_TIPO[chave] ?? chave });
    }

    return saida;
}

/** As abas de cor do tipo: as que têm anúncio, na ordem de `variantes`. */
export function coresDoAnuncio(previa, tipo = null) {
    const grupos = agrupar(previa);
    const tem = (chave) => (tipo ? !!grupos[tipo]?.[chave] : Object.values(grupos).some((g) => !!g[chave]));
    const saida = lista(previa?.variantes)
        .filter((v) => v?.chave != null && tem(String(v.chave)))
        .map((v) => ({ chave: String(v.chave), rotulo: textoLimpo(v.rotulo) ?? String(v.chave) }));
    for (const anuncio of lista(previa?.anuncios)) {
        const chave = varianteDo(anuncio);
        if (chave === null || !tem(chave) || saida.some((v) => v.chave === chave)) continue;
        saida.push({ chave, rotulo: textoLimpo(anuncio?.variante?.rotulo) ?? chave });
    }

    return saida;
}

/** Uma escolha que existe: o tipo pedido (ou o 1º) e a cor pedida, se o tipo a tem (senão a 1ª dele). */
export function escolhaDoAnuncio(previa, escolha = {}) {
    const tipos = tiposDoAnuncio(previa);
    const tipo = tipos.some((t) => t.chave === escolha?.tipo) ? escolha.tipo : tipos[0]?.chave ?? null;
    const cores = coresDoAnuncio(previa, tipo);
    const variante = cores.some((v) => v.chave === escolha?.variante) ? escolha.variante : cores[0]?.chave ?? null;

    return { tipo, variante };
}

/** O anúncio da escolha (sem o par exato, o 1º do tipo; sem o tipo, o 1º da prévia). */
export function anuncioDaEscolha(previa, escolha = {}) {
    const doTipo = agrupar(previa)[escolha?.tipo] ?? null;

    return doTipo?.[escolha?.variante] ?? Object.values(doTipo ?? {})[0] ?? lista(previa?.anuncios)[0] ?? null;
}

/** As fotos do anúncio na ordem dele (a 1ª é a capa), com `criada` (a `gerada` do servidor). Sem URL, fora. */
export function fotosDoAnuncio(anuncio) {
    return lista(anuncio?.fotos)
        .filter((f) => textoLimpo(f?.url))
        .map((f, i) => ({ id: String(f.id ?? `foto-${i}`), url: f.url.trim(), criada: f.gerada === true }));
}

/** "Foto 2 de 6, criada pelo agente". */
export const rotuloDaFoto = (foto, indice, total) => `Foto ${indice + 1} de ${total}${foto?.criada ? `, ${ROTULO_DA_CRIADA}` : ''}`;

/** Quantas fotos o agente criou, em texto; null quando nenhuma. */
export function textoDasCriadas(fotos) {
    const total = lista(fotos).length;
    const criadas = lista(fotos).filter((f) => f?.criada).length;
    if (!criadas) return null;
    if (criadas === total) return total === 1 ? 'A foto foi criada pelo agente.' : `As ${total} fotos foram criadas pelo agente.`;

    return criadas === 1 ? `1 das ${total} fotos foi criada pelo agente.` : `${criadas} das ${total} fotos foram criadas pelo agente.`;
}

/** "R$ 899,90" (com o espaço sem quebra do `toLocaleString`); "—" sem número. */
export const fmtPreco = (valor) => (numero(valor) === null ? '—' : numero(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

/** As parcelas sem juros (o Premium): a forma direta ou a do retrato (`sem_promocao`). Null sem parcelas. */
export function parcelasDoAnuncio(anuncio) {
    const bruto = anuncio?.parcelas;
    if (!bruto || typeof bruto !== 'object') return null;
    const p = 'vezes' in bruto ? bruto : bruto.sem_promocao;
    const vezes = numero(p?.vezes);
    const valor = numero(p?.valor);

    return vezes !== null && vezes >= 1 && valor !== null ? { vezes, valor } : null;
}

/** "em 10× R$ 98,99 sem juros"; null sem parcelas. */
export const textoDasParcelas = (parcelas) => (parcelas ? `em ${parcelas.vezes}× ${fmtPreco(parcelas.valor)} sem juros` : null);

/** A promoção que o sistema tenta depois de publicar; null sem ela. */
export function textoDaPromocao(anuncio) {
    const preco = numero(anuncio?.promocao?.preco);
    if (preco === null) return null;
    const percentual = numero(anuncio.promocao.percentual);

    return `Depois de publicar, o sistema tenta uma promoção: ${fmtPreco(preco)}${percentual !== null ? ` (${Math.floor(percentual)}% de desconto)` : ''}.`;
}

/** "Casa, Móveis e Decoração › Sala › Racks"; null sem categoria. */
export function caminhoDaCategoria(previa) {
    const partes = lista(previa?.categoria?.caminho).map(textoLimpo).filter(Boolean);

    return partes.length ? partes.join(' › ') : null;
}

/** A descrição (uma para todos os anúncios); null sem ela. */
export const descricaoDoAnuncio = (previa) => textoLimpo(previa?.descricao);

/** A ficha agrupada; grupo vazio e item sem nome ou valor saem. */
export function fichaDoAnuncio(anuncio) {
    return lista(anuncio?.caracteristicas)
        .map((g) => ({
            grupo: textoLimpo(g?.grupo) ?? 'Características',
            itens: lista(g?.itens)
                .map((i) => ({ nome: textoLimpo(i?.nome), valor: textoLimpo(i?.valor == null ? null : String(i.valor)) }))
                .filter((i) => i.nome && i.valor),
        }))
        .filter((g) => g.itens.length > 0);
}

const ENTREGA = {
    me2: 'Entrega pelo canal de venda',
    me1: 'Entrega por transportadora',
    not_specified: 'Entrega a combinar com o comprador',
    custom: 'Entrega com frete próprio',
};

/** A entrega em uma linha, sem as siglas do canal de venda; frete grátis = o COMPRADOR não paga. Null sem envio conhecido. */
export function textoDaEntrega(envio) {
    const modo = ENTREGA[envio?.modo] ?? null;
    if (!modo) return null;

    return `${modo}${envio?.frete_gratis === true ? ', com frete grátis para o comprador' : ''}.`;
}

/**
 * A caixa "Preço" do cliente (só leitura): uma linha por tipo, com o valor do anúncio (os valores da Precificação e o
 * mínimo nunca chegam a ele). `porCor`: o preço muda conforme a cor.
 *
 * @returns {{linhas: Array<{chave: string, nome: string, valor: ?number}>, porCor: boolean}}
 */
export function precoParaOCliente(previa) {
    const precos = previa?.preco && typeof previa.preco === 'object' ? previa.preco : {};
    const nomes = new Map(tiposDoAnuncio(previa).map((t) => [t.chave, t.nome]));
    const ordem = [...new Set([...nomes.keys(), ...Object.keys(precos)])].filter((chave) => precos[chave] && typeof precos[chave] === 'object');

    return {
        linhas: ordem.map((chave) => ({ chave, nome: nomes.get(chave) ?? NOME_DO_TIPO[chave] ?? chave, valor: numero(precos[chave]?.valor) })),
        porCor: precos.por_cor === true,
    };
}
