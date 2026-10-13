// ═══════════════════════════════════════════════════════════════════════
// Precificação: o ENVIO de cada oferta, em palavras (funções puras).
//
// O servidor manda, por oferta, `envio`: a logística calculada (`chave`: me2,
// me2_full, me1, pendente), o pacote, se ele é a SOMA das caixas de um conjunto,
// os limites que estourou e os componentes sem medida.
//
// POR QUE EXISTE (11/10/2026): o usuário montou um Combo 2 e a Precificação ficou
// com o frete em branco, sem dizer nada — "nem sei se é ME1". Era: duas caixas de
// 80 cm empilhadas dão 160 cm, e o limite da conta é 100 cm no maior lado. A
// unidade cabia e mostrava o frete; o combo não, e a tela não explicava.
// ═══════════════════════════════════════════════════════════════════════

const NOME_DA_MODALIDADE = { drop_off: 'Correios', xd_drop_off: 'Agências', cross_docking: 'Coleta', fulfillment: 'Full' };

/** 53 → "53"; 12.5 → "12,5"; sem zeros à toa. */
const num = (v) => String(Math.round(Number(v) * 100) / 100).replace('.', ',');

/** "53 × 60 × 160 cm · 16 kg", ou null sem pacote. */
export function textoDoPacote(pacote) {
    if (! pacote) return null;

    return `${num(pacote.c)} × ${num(pacote.l)} × ${num(pacote.a)} cm · ${num(pacote.peso)} kg`;
}

/** Um limite estourado, curto: "maior lado 160 cm (máx. 100)". */
export function textoDoEstouro(e) {
    const nome = { maior: 'maior lado', soma: 'soma dos lados', peso: 'peso' }[e?.medida];
    if (! nome) return null;
    const unidade = e.medida === 'peso' ? 'kg' : 'cm';

    return `${nome} ${num(e.valor)} ${unidade} (máx. ${num(e.limite)})`;
}

/**
 * O que a linha mostra sobre o envio da oferta:
 * `{ chave, rotulo, tom, pacote, motivo, dica }`, ou null quando não há o que dizer
 * (oferta sem produto e sem componentes).
 *
 * `motivo` é a frase curta, sempre à vista, de por que NÃO há frete sugerido; `dica` é a explicação
 * inteira (vai no balão). Oferta dentro do Mercado Envios não tem motivo.
 */
export function resumoDoEnvio(envio) {
    if (! envio || ! envio.chave) return null;

    const medidas = textoDoPacote(envio.pacote);
    const caixas = Number(envio.volumes) || 0;
    const pacote = medidas ? `${envio.somado ? `Pacote somado (${caixas} ${caixas === 1 ? 'caixa' : 'caixas'})` : 'Pacote'}: ${medidas}` : null;
    const daSoma = envio.somado ? ' As medidas são a soma das caixas de cada unidade: para mudar, altere as medidas do produto.' : '';

    if (envio.declarada) {
        return {
            chave: 'declarada', tom: 'neutro', pacote, motivo: 'digite o frete',
            rotulo: envio.declarada === 'combinar' ? 'A combinar com o comprador' : 'Transportadora (ME1)',
            dica: 'A logística desta oferta foi declarada fora do Mercado Envios: não há frete do Mercado Livre a sugerir. Digite o valor do frete.',
        };
    }

    if (envio.chave === 'me1') {
        const estouros = (Array.isArray(envio.estouros) ? envio.estouros : []).map(textoDoEstouro).filter(Boolean);
        const modalidade = NOME_DA_MODALIDADE[envio.modalidade] ?? NOME_DA_MODALIDADE.drop_off;

        return {
            chave: 'me1', tom: 'alerta', pacote, rotulo: 'Fora do Mercado Envios (ME1)',
            motivo: estouros.length ? `passa do limite: ${estouros.join(' · ')}` : 'passa do limite do Mercado Envios',
            dica: `O pacote passa do limite do Mercado Envios na modalidade da sua conta (${modalidade})${estouros.length ? `: ${estouros.join('; ')}` : ''}. `
                + `Por isso não há frete do Mercado Livre a sugerir: o envio é pela sua transportadora, e o frete é o que você digitar.${daSoma}`,
        };
    }

    if (envio.chave === 'pendente') {
        const quem = Array.isArray(envio.sem_medida) ? envio.sem_medida.filter(Boolean) : [];

        return {
            chave: 'pendente', tom: 'alerta', pacote, rotulo: 'Faltam medidas',
            motivo: quem.length ? `sem medidas: ${quem.join(', ')}` : 'alguma medida ou o peso está em branco',
            dica: `${quem.length ? `Faltam as medidas de ${quem.join(', ')}.` : 'Alguma medida ou o peso do produto está em branco ou zerado.'} `
                + 'Sem elas não dá para saber o envio nem sugerir o frete. Preencha no cadastro do produto.',
        };
    }

    return {
        chave: envio.chave, tom: 'ok', pacote, motivo: null, dica: null,
        rotulo: envio.chave === 'me2_full' ? 'Mercado Envios · cabe no Full' : 'Mercado Envios',
    };
}

const SELO = { me2_full: 'Full', me2: 'Mercado Envios', me1: 'ME1', pendente: 'Faltam medidas' };

/**
 * O envio em uma etiqueta, ao lado do código (13/10/2026, "linhas enxutas"): "Full", "ME1", "Faltam medidas".
 * O texto inteiro — pacote, limites estourados — deixou de ocupar a linha e vai no detalhe; aqui ele fica no
 * balão (`dica`). `{ chave, texto, tom, dica }`, ou null sem o que dizer.
 */
export function seloDoEnvio(envio) {
    const r = resumoDoEnvio(envio);
    if (! r) return null;

    const texto = r.chave === 'declarada' ? (envio.declarada === 'combinar' ? 'A combinar' : 'Transportadora') : (SELO[r.chave] ?? r.rotulo);
    const dica = [r.rotulo, r.pacote, r.motivo && r.tom === 'alerta' ? r.motivo : null].filter(Boolean).join(' · ');

    return { chave: r.chave, texto, tom: r.tom, dica };
}

/**
 * A frase sob o campo de frete que está em branco SEM nada a mostrar (nem digitado, nem sugerido, nem
 * o do outro tipo): diz por que não há valor ali. Com qualquer valor em uso, quem fala é a origem dele.
 */
export function avisoSemSugestao(envio, tipo) {
    if (! envio || tipo?.frete_origem) return null;
    const r = resumoDoEnvio(envio);
    if (! r || r.tom === 'ok') return null;

    return r.chave === 'pendente' ? 'sem medidas' : 'sem tabela: digite';
}
