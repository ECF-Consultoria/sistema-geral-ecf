// ─── Trilha de produtos — as ondas do Planejamento (2ª entrega, 02/11/2026) ──
//
// Uma onda é um grupo de ofertas com o DIA em que o robô (o agente de IA)
// começa a prepará-las. A data não é prazo de publicação: nenhum texto daqui
// diz "prazo", "publicar até" ou "data de publicação".
//
// Quem decide se a onda já começou é o servidor (`comecou`, no fuso de São
// Paulo): a tela só escreve. As datas que a tela calcula (o mínimo e o máximo
// do campo de data, o "amanhã" da onda nova) também são de São Paulo, para o
// campo nunca oferecer um dia que o servidor recusaria.
//
// Funções PURAS (sem React e sem classe do Tailwind — este arquivo é `.js`, e o
// Tailwind só varre `.jsx`). Testes: tests/js/trilha-escrita.test.js.

const FUSO = 'America/Sao_Paulo';

/** Até quantos dias para a frente a onda pode começar (o mesmo `OndasDaTrilha::DIAS_A_FRENTE` do servidor). */
export const DIAS_A_FRENTE = 365;

/** O tamanho máximo do nome da onda (a coluna `trilha_ondas.nome`). */
export const NOME_MAXIMO = 80;

const DATA = /^(\d{4})-(\d{2})-(\d{2})/;

/** "05/11" de uma data `2026-11-05` (ou ISO), lendo só a parte da data — sem fuso para trocar o dia. */
export function diaMes(data) {
    const m = DATA.exec(String(data ?? ''));

    return m ? `${m[3]}/${m[2]}` : '';
}

/** Hoje em São Paulo, `YYYY-MM-DD`. */
export function hojeEmSaoPaulo(agora = new Date()) {
    const partes = new Intl.DateTimeFormat('en-CA', { timeZone: FUSO, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(agora);
    const parte = (tipo) => partes.find((p) => p.type === tipo)?.value ?? '';

    return `${parte('year')}-${parte('month')}-${parte('day')}`;
}

/** A data `YYYY-MM-DD` mais `dias` dias (conta no calendário, sem fuso); '' se a data não vier nesse formato. */
export function somarDias(data, dias) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(data ?? ''));
    if (!m) return '';

    return new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + dias)).toISOString().slice(0, 10);
}

/** As ondas na ordem dos cartões e do Mapa: pela data e, no empate, pelo id. */
export function ondasEmOrdem(ondas) {
    return [...(ondas ?? [])].sort((a, b) => {
        const da = String(a?.data ?? '');
        const db = String(b?.data ?? '');
        if (da !== db) return da < db ? -1 : 1;

        return Number(a?.id) - Number(b?.id);
    });
}

/** A onda pelo id; null se não existir (ou se o id for nulo). */
export const ondaPorId = (ondas, id) => (id == null ? null : (ondas ?? []).find((w) => w.id === id) ?? null);

/** "começa 05/11" ou "começou 03/11" — a linha da oferta, o seletor e o Mapa. */
export const quandoDaOnda = (onda) => `${onda?.comecou ? 'começou' : 'começa'} ${diaMes(onda?.data)}`;

/** "começa em 05/11" ou "começou em 03/11" — o cartão e o painel. */
export const quandoDaOndaPorExtenso = (onda) => `${onda?.comecou ? 'começou' : 'começa'} em ${diaMes(onda?.data)}`;

/**
 * A oferta numa onda que ainda não começou, mas que não espera a data dela: a que já andou (preparada antes,
 * p. ex. pelo editor do Publicador) e a sem produto na Lista (o robô não a segura). Para ela, "começa 05/11"
 * seria falso — e a equipe só pode tirá-la da onda (`pode_tirar`).
 */
export const naoEsperaAOnda = (oferta, onda) => !!onda && !onda.comecou && oferta?.planejavel === false;

/**
 * O que a linha da oferta diz da onda dela, depois do nome: "começa 05/11", "começou 03/11" — ou, para a que
 * não espera a data, "já começou" (a que andou) ou nada (a sem produto: a pílula "sem produto na lista" diz).
 */
export function quandoDaOfertaNaOnda(oferta, onda) {
    if (!onda) return null;
    if (naoEsperaAOnda(oferta, onda)) return oferta.sem_produto ? null : 'já começou';

    return quandoDaOnda(onda);
}

/** "começa em 05/11" → "Começa em 05/11". */
export const comMaiuscula = (texto) => (texto ? texto.charAt(0).toUpperCase() + texto.slice(1) : '');

/** As ofertas (visíveis na trilha) que estão na onda. */
export const ofertasDaOnda = (dados, ondaId) => (dados?.ofertas ?? []).filter((o) => o.onda_id != null && o.onda_id === ondaId);

/** As etapas em que a oferta conta como "já na revisão ou no ar" no cartão da onda. */
const JA_PRONTAS = ['revisao', 'lanc'];

/**
 * O andamento da onda no cartão: quantas ofertas ela tem e quantas já estão na
 * revisão ou no ar. "Concluída" é quando todas estão (e ela tem alguma).
 *
 * @returns {{total: number, prontas: number, porcento: number, concluida: boolean}}
 */
export function andamentoDaOnda(dados, ondaId) {
    const ofertas = ofertasDaOnda(dados, ondaId);
    const total = ofertas.length;
    const prontas = ofertas.filter((o) => JA_PRONTAS.includes(o.etapa)).length;

    return { total, prontas, porcento: total ? Math.round((prontas / total) * 100) : 0, concluida: total > 0 && prontas === total };
}

/**
 * A chave com que o servidor compara nomes de onda (`OndasDaTrilha::conferirNomeLivre`): sem caixa, sem
 * acento e com os espaços limpos — "Onda  Única" e "onda unica" são o mesmo nome.
 */
export const chaveDoNome = (nome) => String(nome ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();

/** O nome que a "Nova onda" sugere: "Onda N", com o primeiro N livre a partir de quantas ondas já existem. */
export function proximoNomeDeOnda(ondas) {
    const lista = ondas ?? [];
    const usados = new Set(lista.map((w) => chaveDoNome(w?.nome)));
    let n = lista.length + 1;
    while (usados.has(`onda ${n}`)) n += 1;

    return `Onda ${n}`;
}

/**
 * O que impede de gravar a onda, com o texto do servidor (`OndasDaTrilha`); null
 * quando dá para enviar. A data só é conferida quando ainda pode mudar (onda
 * que começou fica com a data travada).
 *
 * @param {{nome: string, data: string}} campos
 * @param {{hoje: string, dataTravada?: boolean, outrasOndas?: Array<{nome: string}>}} regua  `outrasOndas`:
 *        as ondas da loja fora a que está sendo editada (o nome não pode repetir o de nenhuma).
 */
export function problemaDaOnda({ nome, data }, { hoje, dataTravada = false, outrasOndas = [] }) {
    const limpo = String(nome ?? '').trim();
    if (!limpo || limpo.length > NOME_MAXIMO) return `Dê um nome de 1 a ${NOME_MAXIMO} caracteres à onda.`;
    if ((outrasOndas ?? []).some((w) => chaveDoNome(w?.nome) === chaveDoNome(limpo))) return 'Já existe uma onda com esse nome nesta loja.';
    if (dataTravada) return null;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(data ?? ''))) return 'Escolha o dia em que o robô começa.';
    if (data < hoje) return 'Escolha hoje ou um dia depois: hoje faz o robô começar agora.';
    if (data > somarDias(hoje, DIAS_A_FRENTE)) return 'Escolha uma data até um ano para a frente.';

    return null;
}

/**
 * A dica na oferta que pediu a "+ Nova onda", quando volta para ela: a onda foi
 * criada, mas a oferta ainda NÃO está nela — falta o "Colocar na onda".
 */
export const textoDaOndaCriada = (onda) => `${onda?.nome ?? 'Onda'} criada (${onda?.comecou ? 'começa hoje' : `começa em ${diaMes(onda?.data)}`}). Agora coloque esta oferta nela.`;

/**
 * A oferta pode entrar, mudar ou sair de onda? É o `planejavel` do servidor (tem
 * produto na Lista e ainda não andou). Sem o campo, a régua de antes dele: a
 * oferta com produto no Planejamento ou numa onda que não começou.
 */
export function ehPlanejavel(oferta, onda = null) {
    if (typeof oferta?.planejavel === 'boolean') return oferta.planejavel;

    return !oferta?.sem_produto && (oferta?.etapa === 'plano' || (!!onda && !onda.comecou));
}

/**
 * A ajuda embaixo do campo de data da onda. A onda NOVA nasce vazia: com a data de hoje, quem começa na hora é
 * o que entrar nela (não "o robô começa ao salvar"). A que já existe, posta para hoje, solta as ofertas dela.
 */
export function ajudaDaDataDaOnda({ nova = false, comecou = false, data, hoje }) {
    if (comecou) return 'Já começou: a data não muda mais.';
    if (data === hoje) return nova ? 'Hoje: o que entrar nesta onda começa na hora.' : 'Hoje: o robô começa assim que a onda for salva.';

    return 'O dia em que o robô começa a preparar as ofertas desta onda.';
}

/** Como cada onda aparece no seletor da oferta. A que já começou avisa que a oferta posta nela começa na hora. */
export const rotuloNoSeletor = (onda) => (onda?.comecou
    ? `${onda.nome} · começou ${diaMes(onda.data)} · começa na hora`
    : `${onda?.nome ?? ''} · começa ${diaMes(onda?.data)}`);

/** "A, B e C". */
export function emTexto(nomes) {
    const lista = [...new Set((nomes ?? []).filter(Boolean))];
    if (lista.length <= 1) return lista[0] ?? '';

    return `${lista.slice(0, -1).join(', ')} e ${lista.at(-1)}`;
}

/**
 * Os avisos de dependência da oferta que a tela já sabe calcular (desenho da 2ª
 * entrega, §4.4) — antes de pôr numa onda, ou com a onda ainda por começar:
 *
 * - peça com pendência na Lista: é só aviso — no dia da onda o robô prepara com
 *   o que estiver lá (`Na fila · com pendência na Lista` no status); complete antes;
 * - Kit e Combit: as fotos das peças entram como estiverem — a galeria do
 *   composto leva as fotos da simples de cada peça no estado em que estiverem.
 *   Esse é da equipe: o cliente não vê o preparo.
 *
 * @returns {string[]}
 */
export function avisosDaOferta(oferta, dados, { cliente = false } = {}) {
    const produtos = new Map((dados?.produtos ?? []).map((p) => [p.id, p]));
    const ofertas = new Map((dados?.ofertas ?? []).map((o) => [o.id, o]));
    const pecas = [...new Set((oferta?.componentes ?? []).map((c) => c.produto_id).filter((id) => id != null))];
    const nomeDe = (id) => produtos.get(id)?.nome || (oferta.componentes ?? []).find((c) => c.produto_id === id)?.nome || 'uma peça';
    const avisos = [];

    const naLista = pecas.filter((id) => produtos.get(id)?.etapa === 'lista');
    const comOQueHouver = 'no dia da onda o robô prepara com o que estiver lá. Complete antes da data.';
    if (naLista.length === 1) avisos.push(`O produto ${nomeDe(naLista[0])} tem pendência na Lista: ${comOQueHouver}`);
    if (naLista.length > 1) avisos.push(`Os produtos ${emTexto(naLista.map(nomeDe))} têm pendência na Lista: ${comOQueHouver}`);

    if (!cliente && (oferta?.tipo === 'Kit' || oferta?.tipo === 'Combit')) {
        const semPreparo = pecas.filter((id) => !JA_PRONTAS.includes(ofertas.get(`s-${id}`)?.etapa));
        if (semPreparo.length) {
            avisos.push(`As fotos das peças entram como estiverem: a simples de ${emTexto(semPreparo.map(nomeDe))} ainda não foi preparada.`);
        }
    }

    return avisos;
}

// ─── R12 (04/11/2026): pôr VÁRIAS ofertas numa onda de uma vez ──────────────
//
// No Planejamento, quem escreve marca as ofertas e as põe todas numa onda. As
// regras são as de pôr uma (quem decide é o servidor, item a item); a tela só
// marca, manda e mostra o resultado de cada uma.

/** Até quantas ofertas vão num pedido (o mesmo `OndasDaTrilha::MAXIMO_DO_LOTE` do servidor). */
export const MAXIMO_DO_LOTE = 100;

/**
 * As ofertas da lista que podem ser marcadas, na ordem da lista: as planejáveis (`planejavel` do servidor — têm
 * produto e não andaram). A sem produto e a que já começou ficam sem caixa marcável.
 *
 * @param {Array<{tipo: string, item: object}>} entradas  o formato de `itensDaEtapa()`
 * @returns {string[]}
 */
export const marcaveisDoLote = (entradas) => (entradas ?? [])
    .filter((e) => e?.tipo === 'o' && !e.item?.sem_produto && ehPlanejavel(e.item, null))
    .map((e) => e.item.id);

/** As marcadas que ainda valem (a recarga tira da lista o que entrou na onda), na ordem da lista. */
export const marcadasQueValem = (marcadas, marcaveis) => {
    const conjunto = new Set(marcadas ?? []);

    return (marcaveis ?? []).filter((id) => conjunto.has(id));
};

/** Marca ou desmarca uma; não passa do máximo. */
export function alternarMarcacao(marcadas, id, maximo = MAXIMO_DO_LOTE) {
    const lista = [...(marcadas ?? [])];
    if (lista.includes(id)) return lista.filter((x) => x !== id);

    return lista.length >= maximo ? lista : [...lista, id];
}

/**
 * "Marcar todas desta página": todas as marcáveis, até o máximo (`cortadas` = as que ficaram de fora). Com todas já
 * marcadas, desmarca.
 *
 * @returns {{marcadas: string[], cortadas: number}}
 */
export function marcarTodas(marcadas, marcaveis, maximo = MAXIMO_DO_LOTE) {
    const todas = marcaveis ?? [];
    const valem = marcadasQueValem(marcadas, todas);
    const limite = Math.min(todas.length, maximo);
    if (limite > 0 && valem.length >= limite) return { marcadas: [], cortadas: 0 };

    return { marcadas: todas.slice(0, maximo), cortadas: Math.max(0, todas.length - maximo) };
}

/** "Pôr 3 ofertas na onda" — o botão diz quantas vão. */
export const textoDoBotaoDoLote = (n) => (n > 0 ? `Pôr ${n === 1 ? '1 oferta' : `${n} ofertas`} na onda` : 'Pôr na onda');

/**
 * A resposta do lote para a tela: a mensagem do servidor e os itens separados — os recusados primeiro (são os que
 * pedem ação), com o motivo do servidor; os que entraram (e quem veio junto); os que já estavam.
 *
 * @param {{mensagem?: string, resultados?: Array<{item: string, nome: ?string, situacao: string, motivo: ?string, junto?: string[]}>, avisos?: string[]}} resposta
 * @param {{ofertas?: Array}} [dados]  para o nome de quem entrou junto
 */
export function resumoDoLote(resposta, dados = null) {
    const nomeDe = (id) => (dados?.ofertas ?? []).find((o) => o.id === id)?.nome ?? id;
    const resultados = Array.isArray(resposta?.resultados) ? resposta.resultados : [];
    const comNome = (r) => ({ ...r, nome: r?.nome || nomeDe(r?.item) });

    return {
        mensagem: String(resposta?.mensagem ?? '').trim() || 'Pronto.',
        recusadas: resultados.filter((r) => r?.situacao === 'recusado').map((r) => ({ ...comNome(r), motivo: String(r.motivo ?? '').trim() || 'Não pode entrar nesta onda.' })),
        entraram: resultados.filter((r) => r?.situacao === 'entrou').map((r) => ({ ...comNome(r), junto: (r.junto ?? []).map(nomeDe) })),
        jaEstavam: resultados.filter((r) => r?.situacao === 'ja_estava').map(comNome),
        avisos: (resposta?.avisos ?? []).filter(Boolean),
    };
}
