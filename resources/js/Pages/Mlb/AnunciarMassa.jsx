import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Rocket, Search, Plus, Loader2, ChevronRight, Store, Trash2, Check, AlertTriangle } from 'lucide-react';

// ═══════════════════════════════════════════════════════════════════════
// Anunciar em massa (Variante A do sketch 001): grade editável por categoria.
// Cada linha = 1 ml_anuncio_rascunho da empresa fixada. Colunas = base fixas
// (azul) + ficha técnica obrigatória da categoria (violeta). Consome os 3
// endpoints do Plan 01 (massa / massa.colunas / massa.produtos) e reusa o
// shape de payload do wizard (montarPayload de AnunciarML.jsx).
// ═══════════════════════════════════════════════════════════════════════

// ─── Gera um EAN-13 válido (padrão Mercado Livre) — copiado de AnunciarML.jsx:327 ───
// Prefixo 789 (Brasil) + 9 dígitos aleatórios + dígito verificador (Módulo 10).
function gerarEan13(existentes = new Set()) {
    const digitoVerificador = (base12) => {
        let soma = 0;
        for (let i = 0; i < 12; i++) {
            const mult = ((i + 1) % 2 === 0) ? 3 : 1; // par ×3, ímpar ×1
            soma += Number(base12[i]) * mult;
        }
        const resto = soma % 10;
        return resto === 0 ? 0 : 10 - resto;
    };
    for (let t = 0; t < 50; t++) {
        let base = '789';
        for (let i = 0; i < 9; i++) base += Math.floor(Math.random() * 10);
        const ean = base + digitoVerificador(base);
        if (!existentes.has(ean)) return ean;
    }
    let base = '789';
    for (let i = 0; i < 9; i++) base += Math.floor(Math.random() * 10);
    return base + digitoVerificador(base);
}

// ─── Iniciais da empresa para o "dot" do chip ───
const iniciais = (nome) =>
    (nome ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('') || '?';

// ─── Nome curto do caminho da categoria (última folha do breadcrumb) ───
const nomeCurto = (caminho, categoryId) => {
    if (Array.isArray(caminho) && caminho.length) return caminho[caminho.length - 1];
    return categoryId ?? 'Sem categoria';
};

// ─── Aba temporária "Sem categoria" (rascunhos ainda sem category_id) ───
const SEM_CATEGORIA = '__sem_categoria__';

// ─── Ids de atributo que representam Marca e Modelo no ML ───
// Obrigatórios locais além de título/preço/estoque (espelha AnunciarML.jsx L1102-1106).
const ATTR_MARCA = 'BRAND';
const ATTR_MODELO = 'MODEL';

// ═══════════════════════════════════════════════════════════════════════
// SHEET-05: erro LOCAL bloqueante de uma linha (derivado no front, síncrono).
// É a ÚNICA barreira dura da grade — avisos do /items/validate NÃO entram aqui.
// Retorna a lista de campos obrigatórios faltando (vazia = linha publicável).
// Marca/Modelo só são exigidos quando a categoria da aba os traz como obrigatórios
// (algumas categorias não pedem MODEL); título/preço/estoque valem sempre.
// ═══════════════════════════════════════════════════════════════════════
function errosLocaisLinha(l, aba) {
    const faltando = [];
    if (!String(l.title ?? '').trim()) faltando.push('título');
    if (!(Number(l.price) > 0)) faltando.push('preço');
    if (!(Number(l.estoque) > 0)) faltando.push('estoque');

    // Marca/Modelo: só cobra se a categoria ativa os declara obrigatórios
    const obrigIds = new Set((aba?.obrigatorios ?? []).map((o) => String(o.id)));
    if (obrigIds.has(ATTR_MARCA) && !String(l.attrs?.[ATTR_MARCA] ?? '').trim()) faltando.push('marca');
    if (obrigIds.has(ATTR_MODELO) && !String(l.attrs?.[ATTR_MODELO] ?? '').trim()) faltando.push('modelo');

    return faltando;
}

// Linha é publicável quando: já foi salva (tem id) E não tem erro local bloqueante.
const linhaPublicavel = (l, aba) => !!l.id && errosLocaisLinha(l, aba).length === 0;

// ═══════════════════════════════════════════════════════════════════════
// SHEET-06 — COLAR DO EXCEL (Ctrl+V).
// A ordem das colunas VISÍVEIS da aba ativa define o mapeamento do paste:
// base fixas primeiro (na mesma ordem da grade), depois os obrigatórios.
// Cada coluna declara `tipo` p/ a normalização (dim3 = AxLxC único, tipoAnuncio,
// attrList = value_type=list, texto/numero = crus). GTIN/SKU/Peso/Descrição também
// entram como colunas coláveis (facilita colar planilhas completas).
// ═══════════════════════════════════════════════════════════════════════
function colunasColaveis(aba) {
    const cols = [
        { key: 'title', tipo: 'texto', campo: 'title' },
        { key: 'tier', tipo: 'tipoAnuncio', campo: 'tier' },
        { key: 'price', tipo: 'numero', campo: 'price' },
        { key: 'estoque', tipo: 'numero', campo: 'estoque' },
        { key: 'sku', tipo: 'texto', campo: 'sku' },
        { key: 'gtin', tipo: 'texto', campo: 'gtin' },
        { key: 'pesoG', tipo: 'numero', campo: 'pesoG' },
        // A×L×C: 1 coluna única "AxLxC" (parse pelo separador) — distribui em 3 campos
        { key: 'dimensoes', tipo: 'dim3' },
    ];
    // Ficha técnica obrigatória: uma coluna por atributo (list → casa value)
    (aba?.obrigatorios ?? []).forEach((o) => {
        cols.push({
            key: `attr:${o.id}`,
            tipo: o.value_type === 'list' && Array.isArray(o.values) && o.values.length ? 'attrList' : 'attrTexto',
            attrId: o.id,
            values: o.values ?? [],
        });
    });
    return cols;
}

// Remove acentos + trim + lowercase (p/ casar "Clássico" com "classico" etc.)
const semAcento = (s) =>
    String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').trim().toLowerCase();

// "Clássico"/"Classico" → gold_special ; "Premium" → gold_pro ; senão null (mantém célula)
function normalizarTipoAnuncio(texto) {
    const t = semAcento(texto);
    if (!t) return null;
    if (t.startsWith('clas')) return 'gold_special';
    if (t.startsWith('prem')) return 'gold_pro';
    return null; // não reconhecido → deixa intacto + aviso
}

// "10x20x30" (ou 10×20×30 / 10*20*30) → { alturaCm, larguraCm, comprimentoCm }
function parseDimensoes(texto) {
    const partes = String(texto ?? '').split(/[x×*]/i).map((p) => p.trim()).filter(Boolean);
    if (partes.length < 2) return null;
    const [a, l, c] = partes;
    return {
        alturaCm: a ?? '',
        larguraCm: l ?? '',
        comprimentoCm: c ?? '',
    };
}

// Casa o texto colado com values[].name (case-insensitive+trim) → value_id;
// se nenhum casar, devolve o texto cru (value_name livre — o backend aceita).
function casarValueList(texto, values) {
    const alvo = semAcento(texto);
    if (!alvo) return '';
    const match = (values ?? []).find((v) => semAcento(v.name) === alvo);
    return match ? (match.name ?? texto) : texto; // grava o name (a grade guarda name; payload manda value_name)
}

export default function AnunciarMassa({ empresa = {}, rascunhos = [], produtos = [] }) {
    // Cada aba: { key, category_id, caminho:[], obrigatorios:[], max_title_length, catalog_required, linhas:[], carregando }
    const [abas, setAbas] = useState([]);
    const [abaAtiva, setAbaAtiva] = useState(0);

    // "+ Nova categoria": busca via mlb.anuncios.meta.prever
    const [novaAberta, setNovaAberta] = useState(false);
    const [buscaCat, setBuscaCat] = useState('');
    const [candidatos, setCandidatos] = useState([]);
    const [buscando, setBuscando] = useState(false);

    // Lista de produtos do cliente (prop) para o botão "Puxar produtos do cliente"
    const [produtosAbertos, setProdutosAbertos] = useState(false);
    const listaProdutos = useMemo(() => produtos ?? [], [produtos]);

    // ─── SHEET-05/07: estados de validação orientativa e publicação em lote ───
    const [validandoLote, setValidandoLote] = useState(false); // "Validar tudo" em andamento
    const [publicandoLote, setPublicandoLote] = useState(false); // trava o botão após dispatch
    const [errosLote, setErrosLote] = useState(null); // erro pt-BR do backend (422/403)
    const [avisoLote, setAvisoLote] = useState(''); // feedback ("N enfileirados", "X fora do lote")

    // ─── Inicializa as abas agrupando os rascunhos por category_id (SHEET-01) ───
    // Rascunhos sem categoria caem numa aba temporária "Sem categoria".
    useEffect(() => {
        const grupos = new Map();
        (rascunhos ?? []).forEach((r) => {
            const cid = r.category_id || SEM_CATEGORIA;
            if (!grupos.has(cid)) grupos.set(cid, []);
            grupos.get(cid).push(r);
        });

        const iniciais = [];
        grupos.forEach((rs, cid) => {
            iniciais.push({
                key: cid,
                category_id: cid === SEM_CATEGORIA ? null : cid,
                caminho: [],
                obrigatorios: [],
                max_title_length: 60,
                catalog_required: false,
                carregando: cid !== SEM_CATEGORIA,
                linhas: rs.map((r) => linhaDeRascunho(r)),
            });
        });

        // Sempre garante ao menos uma aba (nova sessão sem rascunhos → "Sem categoria" vazia)
        if (iniciais.length === 0) {
            iniciais.push({
                key: SEM_CATEGORIA, category_id: null, caminho: [], obrigatorios: [],
                max_title_length: 60, catalog_required: false, carregando: false,
                linhas: [linhaVazia()],
            });
        }
        setAbas(iniciais);
        setAbaAtiva(0);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // ─── Busca as colunas (obrigatórios + breadcrumb) de cada categoria uma vez ───
    useEffect(() => {
        abas.forEach((aba, idx) => {
            if (!aba.category_id || !aba.carregando) return;
            window.axios
                .get(route('mlb.anuncios.massa.colunas', { categoryId: aba.category_id }))
                .then((r) => {
                    const d = r.data ?? {};
                    setAbas((prev) => prev.map((a, i) => i === idx ? {
                        ...a,
                        caminho: d.caminho ?? [],
                        obrigatorios: d.obrigatorios ?? [],
                        max_title_length: d.max_title_length ?? 60,
                        catalog_required: !!d.catalog_required,
                        carregando: false,
                    } : a));
                })
                .catch(() => {
                    setAbas((prev) => prev.map((a, i) => i === idx ? { ...a, carregando: false } : a));
                });
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [abas.length]);

    const aba = abas[abaAtiva] ?? null;

    // ─── "+ Nova categoria" — preditor de categoria (SHEET-03) ───
    const preverCategoria = async () => {
        if (!buscaCat.trim()) return;
        setBuscando(true);
        try {
            const r = await window.axios.get(route('mlb.anuncios.meta.prever'), { params: { q: buscaCat } });
            setCandidatos(Array.isArray(r.data) ? r.data : []);
        } finally {
            setBuscando(false);
        }
    };

    const adicionarCategoria = async (cat) => {
        // Evita duplicar aba da mesma categoria
        const existe = abas.findIndex((a) => a.category_id === cat.category_id);
        if (existe >= 0) {
            setAbaAtiva(existe);
            fecharNova();
            return;
        }
        // Cria a aba já com o breadcrumb do candidato; colunas completas vêm do efeito
        const nova = {
            key: cat.category_id,
            category_id: cat.category_id,
            caminho: cat.path_from_root?.map((p) => p.name) ?? (cat.caminho ?? []),
            obrigatorios: [],
            max_title_length: 60,
            catalog_required: false,
            carregando: true,
            linhas: [linhaVazia()],
        };
        setAbas((prev) => [...prev, nova]);
        setAbaAtiva(abas.length);
        fecharNova();
    };

    const fecharNova = () => {
        setNovaAberta(false);
        setBuscaCat('');
        setCandidatos([]);
    };

    // ─── Mutação de linhas da aba ativa (usado pelas Tarefas 2 e 3) ───
    const patchAba = useCallback((patch) => {
        setAbas((prev) => prev.map((a, i) => i === abaAtiva ? { ...a, ...patch } : a));
    }, [abaAtiva]);

    // ─── Edita uma célula da linha (campo base OU atributo da ficha técnica) ───
    // SHEET-04: ao editar uma célula que era do cliente, a origem passa a 'publicador'.
    const editarCelula = useCallback((uid, campo, valor, { attr = false } = {}) => {
        setAbas((prev) => prev.map((a, i) => {
            if (i !== abaAtiva) return a;
            return {
                ...a,
                linhas: a.linhas.map((l) => {
                    if (l.uid !== uid) return l;
                    const origemAntiga = l.origem[campo];
                    const origem = origemAntiga === 'cliente'
                        ? { ...l.origem, [campo]: 'publicador' } // publicador reescreveu campo do cliente
                        : l.origem;
                    return attr
                        ? { ...l, attrs: { ...l.attrs, [campo]: valor }, origem, salvo: false }
                        : { ...l, [campo]: valor, origem, salvo: false };
                }),
            };
        }));
    }, [abaAtiva]);

    // ─── Adiciona uma linha vazia à aba ativa ───
    const adicionarLinha = useCallback(() => {
        patchAba({ linhas: [...(aba?.linhas ?? []), linhaVazia()] });
    }, [aba, patchAba]);

    // ─── Referências vivas para o autosave (evita closures obsoletas no debounce) ───
    const abasRef = useRef(abas);
    const abaAtivaRef = useRef(abaAtiva);
    useEffect(() => { abasRef.current = abas; }, [abas]);
    useEffect(() => { abaAtivaRef.current = abaAtiva; }, [abaAtiva]);

    // Timers de debounce por uid de linha
    const timers = useRef({});

    // Atualiza uma linha específica por uid, em qualquer aba (usado no autosave)
    const patchLinha = useCallback((abaIdx, uid, patch) => {
        setAbas((prev) => prev.map((a, i) => i !== abaIdx ? a : {
            ...a,
            linhas: a.linhas.map((l) => l.uid === uid ? { ...l, ...patch } : l),
        }));
    }, []);

    // ─── Autosave por linha (SHEET-01): store na criação, update depois ───
    // Uma linha vira/atualiza um ml_anuncio_rascunho da empresa fixada.
    const salvarLinha = useCallback(async (abaIdx, uid) => {
        const a = abasRef.current[abaIdx];
        const l = a?.linhas.find((x) => x.uid === uid);
        if (!a || !l) return;
        // Não salva linha totalmente vazia (sem título nem preço)
        if (!l.title?.trim() && !l.price && Object.keys(l.attrs ?? {}).length === 0) return;

        const payload = montarPayloadLinha(l, a);
        patchLinha(abaIdx, uid, { salvando: true });
        try {
            if (!l.id) {
                const r = await window.axios.post(route('mlb.anuncios.rascunho.store'), {
                    company_id: empresa.id,
                    category_id: a.category_id || null,
                    payload,
                });
                const novoId = r.data?.rascunho?.id ?? null;
                patchLinha(abaIdx, uid, { id: novoId, salvando: false, salvo: true });
                return novoId; // devolve o id p/ quem precisa salvar-antes-de-validar
            } else {
                await window.axios.put(route('mlb.anuncios.rascunho.update', { rascunho: l.id }), {
                    category_id: a.category_id || null,
                    payload,
                });
                patchLinha(abaIdx, uid, { salvando: false, salvo: true });
                return l.id;
            }
        } catch {
            patchLinha(abaIdx, uid, { salvando: false });
            return null;
        }
    }, [empresa.id, patchLinha]);

    // Debounce (~600ms) por linha ao editar qualquer célula
    const agendarSalvar = useCallback((abaIdx, uid) => {
        const chave = `${abaIdx}:${uid}`;
        if (timers.current[chave]) clearTimeout(timers.current[chave]);
        timers.current[chave] = setTimeout(() => salvarLinha(abaIdx, uid), 600);
    }, [salvarLinha]);

    // Envolve editarCelula com o agendamento do autosave
    const editarComSalvar = useCallback((uid, campo, valor, opts) => {
        editarCelula(uid, campo, valor, opts);
        agendarSalvar(abaAtivaRef.current, uid);
    }, [editarCelula, agendarSalvar]);

    // ─── Remove uma linha (destroy no backend se já tinha id) ───
    const removerLinha = useCallback(async (uid) => {
        const abaIdx = abaAtivaRef.current;
        const a = abasRef.current[abaIdx];
        const l = a?.linhas.find((x) => x.uid === uid);
        // Some da grade imediatamente
        setAbas((prev) => prev.map((x, i) => i !== abaIdx ? x : {
            ...x, linhas: x.linhas.filter((y) => y.uid !== uid),
        }));
        if (l?.id) {
            try { await window.axios.delete(route('mlb.anuncios.rascunho.destroy', { rascunho: l.id })); }
            catch { /* linha já removida da UI; erro de rede é benigno aqui */ }
        }
    }, []);

    // ─── Puxar produtos do cliente → cria linhas pré-preenchidas (SHEET-04) ───
    // Campos vindos do cliente ganham origem 'cliente' (badge violeta).
    const puxarProduto = useCallback((p) => {
        const abaIdx = abaAtivaRef.current;
        const l = linhaVazia();
        l.title = p.produto ?? '';
        l.sku = p.sku ?? '';
        l.price = p.preco_anunciado_c != null ? String(p.preco_anunciado_c) : '';
        l.estoque = p.estoque != null ? String(p.estoque) : '';
        l.descricao = p.descricao ?? '';
        l.pesoG = p.peso_kg != null ? String(Math.round(Number(p.peso_kg) * 1000)) : '';
        l.alturaCm = p.altura != null ? String(p.altura) : '';
        l.larguraCm = p.largura != null ? String(p.largura) : '';
        l.comprimentoCm = p.profundidade != null ? String(p.profundidade) : '';
        // Marca a origem 'cliente' só nos campos que o cliente de fato forneceu
        const origem = {};
        if (p.produto) origem.title = 'cliente';
        if (p.sku) origem.sku = 'cliente';
        if (p.preco_anunciado_c != null) origem.price = 'cliente';
        if (p.estoque != null) origem.available_quantity = 'cliente';
        if (p.descricao) origem.description = 'cliente';
        if (p.peso_kg != null) origem.pesoG = 'cliente';
        if (p.altura != null) origem.alturaCm = 'cliente';
        if (p.largura != null) origem.larguraCm = 'cliente';
        if (p.profundidade != null) origem.comprimentoCm = 'cliente';
        l.origem = origem;

        setAbas((prev) => prev.map((x, i) => i !== abaIdx ? x : { ...x, linhas: [...x.linhas, l] }));
        // Salva a linha recém-criada
        setTimeout(() => agendarSalvar(abaIdx, l.uid), 50);
    }, [agendarSalvar]);

    // ═══════════════════════════════════════════════════════════════════════
    // SHEET-06: colar do Excel (Ctrl+V) na aba ativa. Divide por linha (\n) e por
    // tab (\t), mapeia cada coluna colada para a coluna VISÍVEL correspondente e
    // NORMALIZA por tipo (dim AxLxC, Tipo→listing_type, list→value). Linhas a mais
    // criam novas linhas; colunas a mais são ignoradas com aviso. Autosalva o afetado.
    // ═══════════════════════════════════════════════════════════════════════
    const [avisoColagem, setAvisoColagem] = useState('');
    const colarNaGrade = useCallback((e) => {
        const abaIdx = abaAtivaRef.current;
        const a = abasRef.current[abaIdx];
        if (!a) return;

        const texto = e.clipboardData?.getData('text') ?? '';
        // Só intercepta se parece um bloco de planilha (tem tab ou múltiplas linhas)
        if (!texto || (!texto.includes('\t') && !texto.includes('\n'))) return;
        e.preventDefault();

        const linhasTxt = texto.replace(/\r/g, '').split('\n').filter((ln, i, arr) => !(i === arr.length - 1 && ln === ''));
        const cols = colunasColaveis(a);
        const avisos = new Set();

        // Aplica os valores de uma célula colada à linha-alvo (mutação em objeto novo)
        const aplicarCelula = (linhaObj, colDef, valorBruto) => {
            const valor = String(valorBruto ?? '').trim();
            switch (colDef.tipo) {
                case 'dim3': {
                    const dim = parseDimensoes(valor);
                    if (dim) Object.assign(linhaObj, dim);
                    else if (valor) avisos.add(`Dimensão "${valor}" ignorada (use AxLxC, ex.: 10x20x30).`);
                    break;
                }
                case 'tipoAnuncio': {
                    const tier = normalizarTipoAnuncio(valor);
                    if (tier) linhaObj.tier = tier;
                    else if (valor) avisos.add(`Tipo "${valor}" não reconhecido (use Clássico ou Premium).`);
                    break;
                }
                case 'attrList': {
                    linhaObj.attrs = { ...linhaObj.attrs, [colDef.attrId]: casarValueList(valor, colDef.values) };
                    break;
                }
                case 'attrTexto': {
                    linhaObj.attrs = { ...linhaObj.attrs, [colDef.attrId]: valor };
                    break;
                }
                default: // texto / numero → valor cru trimado
                    linhaObj[colDef.campo] = valor;
            }
            // Editar via paste = origem 'publicador' no campo (SHEET-04)
            const chaveOrigem = colDef.campo ?? (colDef.attrId ? colDef.attrId : null);
            if (chaveOrigem) linhaObj.origem = { ...linhaObj.origem, [chaveOrigem]: 'publicador' };
        };

        setAbas((prev) => prev.map((ab, i) => {
            if (i !== abaIdx) return ab;
            const linhas = [...ab.linhas];
            const uidsAfetados = [];

            linhasTxt.forEach((linhaTxt, rIdx) => {
                const celulas = linhaTxt.split('\t');
                if (celulas.length > cols.length) {
                    avisos.add(`${celulas.length - cols.length} coluna(s) a mais ignorada(s).`);
                }
                // Linha a mais → cria nova linha (novo rascunho via autosave)
                if (rIdx >= linhas.length) linhas.push(linhaVazia());
                const alvo = { ...linhas[rIdx] };
                celulas.slice(0, cols.length).forEach((cel, cIdx) => aplicarCelula(alvo, cols[cIdx], cel));
                alvo.salvo = false;
                linhas[rIdx] = alvo;
                uidsAfetados.push(alvo.uid);
            });

            // Autosalva as linhas afetadas
            uidsAfetados.forEach((uid) => setTimeout(() => agendarSalvar(abaIdx, uid), 50));
            return { ...ab, linhas };
        }));

        setAvisoColagem(avisos.size ? [...avisos].join(' ') : `Colado: ${linhasTxt.length} linha(s).`);
        setTimeout(() => setAvisoColagem(''), 6000);
    }, [agendarSalvar]);

    // ═══════════════════════════════════════════════════════════════════════
    // SHEET-05: "Validar tudo" — valida cada linha via /items/validate (ORIENTATIVO).
    // A resposta ({valido, erros pt-BR}) é gravada em `l.valida`; NÃO impede publicar
    // (validate dá falso-positivo, ex.: shipping.lost_me1_by_user). Linhas sem id são
    // salvas antes (reuso do autosave do Plan 02); linhas sem título/preço são puladas.
    // ═══════════════════════════════════════════════════════════════════════
    const validarTudo = useCallback(async () => {
        setValidandoLote(true);
        setErrosLote(null);
        setAvisoLote('');
        try {
            // Percorre TODAS as abas (o resumo é do lote inteiro)
            for (let ai = 0; ai < abasRef.current.length; ai++) {
                const a = abasRef.current[ai];
                for (const l0 of a.linhas) {
                    // Pula linha totalmente em branco (sem título nem preço)
                    if (!String(l0.title ?? '').trim() && !l0.price) continue;
                    // Garante id: salva a linha se ainda não persistida
                    let id = l0.id;
                    if (!id) id = await salvarLinha(ai, l0.uid);
                    if (!id) continue; // não deu p/ salvar — pula (aviso implícito)
                    patchLinha(ai, l0.uid, { validando: true });
                    try {
                        const r = await window.axios.post(route('mlb.anuncios.validar', { rascunho: id }));
                        // Resultado ORIENTATIVO: valido=true → sem avisos; senão erros pt-BR do ML
                        patchLinha(ai, l0.uid, {
                            validando: false,
                            valida: { valido: !!r.data?.valido, erros: r.data?.erros ?? [] },
                        });
                    } catch {
                        patchLinha(ai, l0.uid, { validando: false });
                    }
                }
            }
        } finally {
            setValidandoLote(false);
        }
    }, [salvarLinha, patchLinha]);

    // ═══════════════════════════════════════════════════════════════════════
    // SHEET-05: resumo AGREGADO do lote inteiro (todas as abas), via useMemo.
    // Distingue N publicáveis (sem erro local) × N com erro local × N com avisos do ML.
    // NÃO mistura "erro local" (bloqueante) com "aviso do ML" (não bloqueia).
    // ═══════════════════════════════════════════════════════════════════════
    const resumoLote = useMemo(() => {
        let total = 0, publicaveis = 0, comErroLocal = 0, comAvisosMl = 0;
        const idsPublicaveis = [];
        abas.forEach((a) => {
            a.linhas.forEach((l) => {
                // Ignora linhas totalmente em branco (não contam no lote)
                const vazia = !String(l.title ?? '').trim() && !l.price && Object.keys(l.attrs ?? {}).length === 0;
                if (vazia) return;
                total++;
                // SHEET-07: publicável = tem id (salvo) E sem erro local bloqueante.
                // NÃO exige status 'validado' do ML (espelha publicar() no backend).
                if (errosLocaisLinha(l, a).length > 0) {
                    comErroLocal++;
                } else if (linhaPublicavel(l, a)) {
                    publicaveis++;
                    idsPublicaveis.push(l.id);
                }
                // Avisos do ML: linha validada com valido=false (orientativo, não bloqueia)
                if (l.valida && !l.valida.valido && (l.valida.erros?.length ?? 0) > 0) comAvisosMl++;
            });
        });
        return { total, publicaveis, comErroLocal, comAvisosMl, idsPublicaveis };
    }, [abas]);

    // ═══════════════════════════════════════════════════════════════════════
    // SHEET-07: publica em lote TODAS as linhas PUBLICÁVEIS (id + sem erro local).
    // NÃO porteira pelo status `validado` do ML — espelha a decisão do backend
    // publicar() (MlbAnuncioController L357-360): o POST /items é a fonte da verdade.
    // Envia máx 50 ids a mlb.anuncios.publicar-lote; trava o botão após o dispatch.
    // ═══════════════════════════════════════════════════════════════════════
    const publicarLote = useCallback(async () => {
        const ids = resumoLote.idsPublicaveis.slice(0, 50); // publicarLote aceita máx 50
        if (ids.length === 0) return;
        setPublicandoLote(true); // trava imediatamente (anti-duplo-clique)
        setErrosLote(null);
        setAvisoLote('');
        try {
            const r = await window.axios.post(
                route('mlb.anuncios.publicar-lote', { company: empresa.id }),
                { company_id: empresa.id, rascunho_ids: ids },
            );
            const enfileirados = r.data?.enfileirados ?? ids.length;
            const foraDoLote = resumoLote.comErroLocal;
            setAvisoLote(
                `${enfileirados} anúncio(s) enfileirado(s).` +
                (foraDoLote > 0 ? ` ${foraDoLote} linha(s) fora do lote por campo obrigatório vazio.` : ''),
            );
            // Marca as linhas enviadas como 'publicando' na UI (status real vem do backend)
            const enviados = new Set(ids);
            setAbas((prev) => prev.map((a) => ({
                ...a,
                linhas: a.linhas.map((l) => enviados.has(l.id) ? { ...l, publicando: true } : l),
            })));
            // Reflete o status real (published/erro) recarregando os rascunhos
            setTimeout(() => router.reload({ only: ['rascunhos'] }), 1500);
        } catch (err) {
            // 422 (token desconectado) ou 403 (empresa não atribuída) → mensagem pt-BR + reabilita
            setErrosLote(err.response?.data?.erros ?? [{ mensagem: 'Erro ao enfileirar publicação em lote.' }]);
            setPublicandoLote(false); // reabilita o botão em caso de erro
        }
    }, [resumoLote, empresa.id]);

    return (
        <AppLayout title="Anunciar em massa">
            <div className="mx-auto max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 pb-28">

                {/* ─── Cabeçalho + chip da empresa fixada ─── */}
                <div className="mb-4 flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-3">
                        <Rocket className="mt-0.5 h-6 w-6 text-ecf-yellow" />
                        <div>
                            <h1 className="text-xl font-semibold text-white">Anunciar em massa</h1>
                            <p className="text-sm text-white/40">
                                Preencha várias linhas, valide e publique tudo de uma vez. Cada linha vira um anúncio.
                            </p>
                        </div>
                    </div>

                    {/* Chip da empresa (não editável — âncora da conta ML) */}
                    <div className="inline-flex items-center gap-2 rounded-full border border-white/[0.08] bg-ecf-card px-3 py-1.5 pl-1.5">
                        <span className="grid h-7 w-7 place-items-center rounded-full bg-gradient-to-br from-indigo-800 to-red-500 text-[11px] font-bold text-white">
                            {iniciais(empresa.nome)}
                        </span>
                        <div className="pr-1 text-sm leading-tight text-white">
                            {empresa.nome ?? '—'}
                            <div className="text-[11px] text-white/40">
                                conta ML{empresa.tem_token ? '' : ' · sem token'}
                            </div>
                        </div>
                    </div>
                </div>

                {/* ─── Cápsulas de aba por categoria ─── */}
                {/* SHEET-03: cada aba mostra nome curto + contador + código MLB. */}
                <div className="mb-3 flex flex-wrap items-center gap-2">
                    {abas.map((a, idx) => (
                        <button
                            key={a.key}
                            onClick={() => setAbaAtiva(idx)}
                            className={cn(
                                'inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition',
                                idx === abaAtiva
                                    ? 'border-ecf-yellow bg-ecf-yellow/[0.06] text-white'
                                    : 'border-white/[0.08] bg-ecf-card text-white/60 hover:bg-white/[0.04] hover:text-white',
                            )}
                        >
                            <span>{nomeCurto(a.caminho, a.category_id) || 'Sem categoria'}</span>
                            <span className={cn(
                                'rounded-full px-2 py-0.5 text-[11px] tabular-nums',
                                idx === abaAtiva ? 'bg-ecf-yellow font-bold text-black' : 'bg-white/[0.06]',
                            )}>
                                {a.linhas.length}
                            </span>
                            {a.category_id && (
                                <span className="font-mono text-[10px] text-white/30">{a.category_id}</span>
                            )}
                        </button>
                    ))}

                    {/* + Nova categoria */}
                    <button
                        onClick={() => setNovaAberta((v) => !v)}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-white/[0.12] px-3 py-2 text-sm text-white/40 hover:text-white"
                    >
                        <Plus className="h-3.5 w-3.5" /> Nova categoria
                    </button>
                </div>

                {/* Busca de nova categoria (mlb.anuncios.meta.prever) */}
                {novaAberta && (
                    <div className="mb-3 rounded-xl border border-white/[0.08] bg-ecf-card p-3">
                        <div className="flex items-center gap-2 rounded-lg border border-white/[0.08] bg-ecf-bg px-3 py-2">
                            <Search className="h-4 w-4 text-white/30" />
                            <input
                                value={buscaCat}
                                onChange={(e) => setBuscaCat(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && preverCategoria()}
                                placeholder="Descreva o produto para prever a categoria (ex.: cadeira de escritório)…"
                                className="w-full bg-transparent text-sm text-white placeholder-white/30 focus:outline-none"
                                autoFocus
                            />
                            <button
                                onClick={preverCategoria}
                                disabled={buscando}
                                className="shrink-0 rounded-md bg-ecf-yellow px-3 py-1 text-xs font-bold text-black disabled:opacity-50"
                            >
                                {buscando ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : 'Prever'}
                            </button>
                        </div>

                        {candidatos.length > 0 && (
                            <div className="mt-2 space-y-1">
                                {candidatos.map((c) => {
                                    const bc = c.path_from_root?.map((p) => p.name) ?? (c.caminho ?? []);
                                    return (
                                        <button
                                            key={c.category_id}
                                            onClick={() => adicionarCategoria(c)}
                                            className="flex w-full items-center justify-between gap-3 rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2 text-left hover:border-white/20"
                                        >
                                            <span className="text-sm text-white/80">
                                                {c.category_name ?? nomeCurto(bc, c.category_id)}
                                                {bc.length > 0 && (
                                                    <span className="ml-2 text-[11px] text-white/30">
                                                        {bc.join(' › ')}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="font-mono text-[10px] text-white/30">{c.category_id}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                )}

                {/* ─── Barra de contexto: caminho completo (breadcrumb) + obrigatórios ─── */}
                {/* SHEET-03: o breadcrumb resolve o MLBxxxx confuso — mostra o caminho, não só o código. */}
                {aba && (
                    <div className="mb-3 flex flex-wrap items-center gap-4 rounded-xl border border-white/[0.08] bg-gradient-to-r from-ecf-yellow/[0.05] to-transparent px-4 py-2.5">
                        <div className="text-sm">
                            {aba.carregando ? (
                                <span className="inline-flex items-center gap-2 text-white/40">
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" /> Carregando categoria…
                                </span>
                            ) : aba.caminho.length > 0 ? (
                                <span className="text-white/40">
                                    {aba.caminho.map((c, i) => (
                                        <span key={i}>
                                            {i > 0 && <span className="mx-1">›</span>}
                                            <span className={i === aba.caminho.length - 1 ? 'font-semibold text-white' : ''}>{c}</span>
                                        </span>
                                    ))}
                                </span>
                            ) : (
                                <span className="text-white/40">Defina uma categoria para esta aba</span>
                            )}
                        </div>
                        {!aba.carregando && aba.category_id && (
                            <div className="text-[11px] text-white/50">
                                <b className="text-amber-400">{aba.obrigatorios.length}</b> atributo{aba.obrigatorios.length !== 1 ? 's' : ''} obrigatório{aba.obrigatorios.length !== 1 ? 's' : ''}
                            </div>
                        )}
                    </div>
                )}

                {/* ─── Ações da grade: puxar produtos do cliente ─── */}
                {aba && listaProdutos.length > 0 && (
                    <div className="mb-3">
                        <button
                            onClick={() => setProdutosAbertos((v) => !v)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-violet-500/30 bg-violet-500/[0.06] px-3 py-1.5 text-xs font-medium text-violet-300 hover:bg-violet-500/[0.1]"
                        >
                            <Plus className="h-3.5 w-3.5" /> Puxar produtos do cliente ({listaProdutos.length})
                        </button>

                        {produtosAbertos && (
                            <div className="mt-2 max-h-64 space-y-1 overflow-auto rounded-xl border border-white/[0.08] bg-ecf-card p-2">
                                {listaProdutos.map((p, i) => (
                                    <button
                                        key={p.sku ?? i}
                                        onClick={() => puxarProduto(p)}
                                        className="flex w-full items-center gap-3 rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2 text-left hover:border-white/20"
                                    >
                                        <span className="font-mono text-[11px] text-white/40">{p.sku ?? '—'}</span>
                                        <span className="flex-1 truncate text-sm text-white/80">{p.produto ?? '—'}</span>
                                        {p.tem_dimensoes ? (
                                            <span className="shrink-0 text-[11px] text-white/40">{p.altura}×{p.largura}×{p.profundidade} cm</span>
                                        ) : (
                                            <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-500/10 px-1.5 py-0.5 text-[10px] text-amber-300/80">
                                                <AlertTriangle className="h-2.5 w-2.5" /> dimensões incompletas
                                            </span>
                                        )}
                                        {p.tem_preco && (
                                            <span className="shrink-0 text-[11px] font-semibold text-ecf-yellow">R$ {p.preco_anunciado_c}</span>
                                        )}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {/* Aviso de colagem (SHEET-06) */}
                {avisoColagem && (
                    <div className="mb-2 rounded-lg border border-white/[0.08] bg-white/[0.03] px-3 py-1.5 text-[12px] text-white/60">
                        {avisoColagem}
                    </div>
                )}

                {/* ─── Grade da aba ativa ─── */}
                {aba && (
                    <Grade
                        aba={aba}
                        empresa={empresa}
                        onEditarCelula={editarComSalvar}
                        onAdicionarLinha={adicionarLinha}
                        onRemoverLinha={removerLinha}
                        onColar={colarNaGrade}
                    />
                )}
            </div>

            {/* ═══ Barra fixa inferior: resumo do lote + ações (SHEET-05/07) ═══ */}
            <PublishBar
                resumo={resumoLote}
                validando={validandoLote}
                publicando={publicandoLote}
                erros={errosLote}
                aviso={avisoLote}
                onValidarTudo={validarTudo}
                onPublicarLote={publicarLote}
            />
        </AppLayout>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Barra fixa inferior (sketch .publish-bar): resumo agregado do lote + ações.
// SHEET-05: distingue "N publicáveis" (sem erro local) de "N com erro local"
// (bloqueante) e "N com avisos do ML" (orientativo, NÃO bloqueia).
// SHEET-07: botão primário publica todas as publicáveis; trava após o dispatch.
// ═══════════════════════════════════════════════════════════════════════
function PublishBar({ resumo, validando, publicando, erros, aviso, onValidarTudo, onPublicarLote }) {
    const { total, publicaveis, comErroLocal, comAvisosMl } = resumo;
    const nadaAPublicar = publicaveis === 0;

    return (
        <div className="fixed inset-x-0 bottom-0 z-30 border-t border-white/[0.08] bg-ecf-card/95 backdrop-blur">
            <div className="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-3 px-4 py-2.5 sm:px-6 lg:px-8">
                {/* Resumo agregado do lote inteiro (todas as abas) */}
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px]">
                    <span className="text-white/50">
                        <b className="tabular-nums text-white">{total}</b> no lote
                    </span>
                    <span className="text-emerald-300/80">
                        <b className="tabular-nums">{publicaveis}</b> publicável(is)
                    </span>
                    {comErroLocal > 0 && (
                        <span className="inline-flex items-center gap-1 text-red-300/90" title="Campo obrigatório vazio — bloqueia a publicação">
                            <AlertTriangle className="h-3.5 w-3.5" />
                            <b className="tabular-nums">{comErroLocal}</b> com erro local
                        </span>
                    )}
                    {comAvisosMl > 0 && (
                        <span className="inline-flex items-center gap-1 text-amber-300/80" title="Avisos do Mercado Livre — revisar, mas NÃO impedem publicar">
                            ◐ <b className="tabular-nums">{comAvisosMl}</b> com avisos do ML
                        </span>
                    )}
                    {/* Feedback / erros pt-BR do backend */}
                    {aviso && <span className="text-white/60">{aviso}</span>}
                    {erros && erros.length > 0 && (
                        <span className="text-red-300">{erros.map((e) => e.mensagem).join(' · ')}</span>
                    )}
                </div>

                {/* Ações: Validar tudo (orientativo) + Publicar N em lote */}
                <div className="flex items-center gap-2">
                    <button
                        onClick={onValidarTudo}
                        disabled={validando || total === 0}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.12] bg-ecf-card-2 px-3 py-1.5 text-sm text-white/80 hover:text-white disabled:opacity-40"
                    >
                        {validando ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
                        Validar tudo
                    </button>
                    <button
                        onClick={onPublicarLote}
                        disabled={publicando || nadaAPublicar}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-4 py-1.5 text-sm font-bold text-black hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {publicando ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Rocket className="h-3.5 w-3.5" />}
                        Publicar {publicaveis} em lote
                    </button>
                </div>
            </div>
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Grade editável (SHEET-02): colunas base fixas + ficha técnica da categoria.
// HTML table própria e leve (sem lib de grid). Header sticky, coluna # sticky.
// A grade da aba ativa mostra as colunas base + SÓ os obrigatórios da categoria
// ativa — nunca a união de todas as categorias do lote.
// ═══════════════════════════════════════════════════════════════════════
function Grade({ aba, empresa, onEditarCelula, onAdicionarLinha, onRemoverLinha, onColar }) {
    const obrig = aba.obrigatorios ?? [];
    const nomeCat = nomeCurto(aba.caminho, aba.category_id);

    // GTINs já usados na aba (não gerar repetido)
    const gtinsUsados = useMemo(
        () => new Set(aba.linhas.map((l) => l.gtin).filter(Boolean)),
        [aba.linhas],
    );

    // Nº de colunas do grupo base (para o colspan do cabeçalho de grupo)
    const COLS_BASE = 10; // Título, Tipo, Preço, Estoque, SKU, GTIN, Peso, A×L×C, Foto

    return (
        // onPaste na área da grade (SHEET-06): colar da planilha preenche as células.
        <div className="overflow-auto rounded-xl border border-white/[0.08] bg-ecf-card" onPaste={onColar}>
            <table className="w-max min-w-full border-separate border-spacing-0 text-sm">
                <thead>
                    {/* Linha de grupos de coluna: Campos base (azul) × Ficha técnica (violeta) */}
                    <tr>
                        <th className="sticky left-0 z-20 bg-ecf-card-2" />
                        <th
                            colSpan={COLS_BASE}
                            className="sticky top-0 z-10 border-b border-r border-white/[0.08] bg-blue-400/[0.06] px-2 py-1.5 text-center text-[10px] font-medium text-white/40"
                        >
                            Campos base
                        </th>
                        {obrig.length > 0 && (
                            <th
                                colSpan={obrig.length}
                                className="sticky top-0 z-10 border-b border-r border-white/[0.08] bg-violet-400/[0.06] px-2 py-1.5 text-center text-[10px] font-medium text-white/40"
                            >
                                Ficha técnica · {nomeCat}
                            </th>
                        )}
                        <th className="sticky right-0 top-0 z-20 border-b border-l border-white/[0.08] bg-ecf-card-2" />
                    </tr>
                    {/* Cabeçalhos das colunas */}
                    <tr className="[&>th]:sticky [&>th]:top-[30px] [&>th]:z-10 [&>th]:whitespace-nowrap [&>th]:border-b [&>th]:border-r [&>th]:border-white/[0.08] [&>th]:bg-ecf-card-2 [&>th]:px-2.5 [&>th]:py-2 [&>th]:text-left [&>th]:text-[10px] [&>th]:font-semibold [&>th]:uppercase [&>th]:tracking-wide [&>th]:text-white/40">
                        <th className="!sticky !left-0 !z-20 text-center">#</th>
                        <Th req>Título ({aba.max_title_length})</Th>
                        <Th>Tipo</Th>
                        <Th req>Preço</Th>
                        <Th req>Estoque</Th>
                        <Th>SKU</Th>
                        <Th>GTIN</Th>
                        <Th>Peso g</Th>
                        <Th>A×L×C cm</Th>
                        <Th>Foto</Th>
                        {obrig.map((o) => (
                            <Th key={o.id} req>{o.name}</Th>
                        ))}
                        <th className="!sticky !right-0 !z-20 !border-l !text-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    {aba.linhas.map((l, idx) => (
                        <LinhaGrade
                            key={l.uid}
                            linha={l}
                            idx={idx}
                            aba={aba}
                            obrig={obrig}
                            maxTitle={aba.max_title_length}
                            gtinsUsados={gtinsUsados}
                            totalCols={COLS_BASE + obrig.length + 2}
                            onEditarCelula={onEditarCelula}
                            onRemoverLinha={onRemoverLinha}
                        />
                    ))}
                    <tr>
                        <td className="sticky left-0 z-10 bg-ecf-card-2" />
                        <td colSpan={COLS_BASE + obrig.length + 1} className="border-t border-white/[0.06]">
                            <button
                                onClick={onAdicionarLinha}
                                className="w-full px-3 py-2.5 text-left text-sm text-white/40 hover:text-white"
                            >
                                + Adicionar linha <span className="ml-1 text-white/25">ou colar da planilha (Ctrl+V)</span>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

// ─── Cabeçalho de coluna (marca obrigatório com asterisco vermelho) ───
function Th({ children, req }) {
    return (
        <th>
            {children}
            {req && <span className="text-red-400"> *</span>}
        </th>
    );
}

// ─── Uma linha da grade (SHEET-02/05) ───
function LinhaGrade({ linha: l, idx, aba, obrig, maxTitle, gtinsUsados, totalCols, onEditarCelula, onRemoverLinha }) {
    const set = (campo, valor) => onEditarCelula(l.uid, campo, valor);
    const setAttr = (id, valor) => onEditarCelula(l.uid, id, valor, { attr: true });

    // SHEET-05: erro LOCAL bloqueante (síncrono) × avisos do ML (orientativo).
    const errosLocais = errosLocaisLinha(l, aba);
    const temErroLocal = errosLocais.length > 0;
    const avisosMl = (l.valida && !l.valida.valido) ? (l.valida.erros ?? []) : [];
    const validadoOk = !!(l.valida && l.valida.valido);

    return (
        <>
        <tr className={cn(
            '[&>td]:border-b [&>td]:border-r [&>td]:border-white/[0.06]',
            // Realce FORTE p/ erro local (bloqueante); realce SUAVE p/ avisos do ML.
            temErroLocal && 'shadow-[inset_3px_0_0_0_theme(colors.red.500)]',
            !temErroLocal && avisosMl.length > 0 && 'shadow-[inset_3px_0_0_0_theme(colors.amber.400)]',
        )}>
            {/* # (sticky) + status da linha (salvando / validando / erro local / avisos ML / OK) */}
            <td className="sticky left-0 z-10 bg-ecf-card-2 px-2 text-center font-mono text-[11px] text-white/30">
                {idx + 1}
                {l.publicando ? (
                    <Loader2 className="mx-auto mt-0.5 h-3 w-3 animate-spin text-ecf-yellow" title="Publicando…" />
                ) : l.salvando || l.validando ? (
                    <Loader2 className="mx-auto mt-0.5 h-3 w-3 animate-spin text-white/30" />
                ) : temErroLocal ? (
                    <span className="mt-0.5 block text-red-400" title={`Faltando: ${errosLocais.join(', ')}`}>!</span>
                ) : avisosMl.length > 0 ? (
                    <span className="mt-0.5 block text-amber-400" title="Avisos do ML (revisar)">◐</span>
                ) : validadoOk ? (
                    <Check className="mx-auto mt-0.5 h-3 w-3 text-emerald-500/70" title="Válido no ML" />
                ) : l.id ? (
                    <Check className="mx-auto mt-0.5 h-3 w-3 text-white/40" title="Salvo" />
                ) : null}
            </td>

            {/* Título com contador (truncado em maxTitle) */}
            <Cell origem={l.origem['title']}>
                <input
                    className="w-full min-w-[220px] bg-transparent px-2.5 py-2 text-white outline-none focus:bg-ecf-yellow/[0.08]"
                    value={l.title}
                    maxLength={maxTitle}
                    onChange={(e) => set('title', e.target.value.slice(0, maxTitle))}
                    placeholder="Título do anúncio"
                />
                <span className="pointer-events-none absolute bottom-0.5 right-1 text-[9px] tabular-nums text-white/25">
                    {l.title.length}/{maxTitle}
                </span>
            </Cell>

            {/* Tipo (Clássico/Premium → gold_special/gold_pro) */}
            <td className="px-1">
                <div className="inline-flex gap-0.5 p-0.5">
                    {[['gold_special', 'Clás'], ['gold_pro', 'Prem']].map(([v, lb]) => (
                        <button
                            key={v}
                            onClick={() => set('tier', v)}
                            className={cn(
                                'rounded px-2 py-0.5 text-[11px]',
                                l.tier === v ? 'border border-white/[0.12] bg-ecf-card-2 text-white' : 'text-white/40',
                            )}
                        >
                            {lb}
                        </button>
                    ))}
                </div>
            </td>

            {/* Preço */}
            <CellInput num origem={l.origem['price']} value={l.price} onChange={(v) => set('price', v)} placeholder="—" />

            {/* Estoque */}
            <CellInput num origem={l.origem['available_quantity']} value={l.estoque} onChange={(v) => set('estoque', v)} placeholder="—" />

            {/* SKU */}
            <CellInput origem={l.origem['sku']} value={l.sku} onChange={(v) => set('sku', v)} placeholder="—" />

            {/* GTIN (vazio → botão gerar EAN-13 válido) */}
            <td className="relative">
                <div className="flex items-center">
                    <input
                        className="w-full min-w-[130px] bg-transparent px-2.5 py-2 text-right font-mono text-white outline-none focus:bg-ecf-yellow/[0.08]"
                        value={l.gtin}
                        onChange={(e) => set('gtin', e.target.value)}
                        placeholder="—"
                    />
                    {!l.gtin && (
                        <button
                            onClick={() => set('gtin', gerarEan13(gtinsUsados))}
                            title="Gerar EAN-13 válido"
                            className="shrink-0 px-1.5 text-[10px] text-ecf-yellow/70 hover:text-ecf-yellow"
                        >
                            gerar
                        </button>
                    )}
                </div>
            </td>

            {/* Peso g */}
            <CellInput num origem={l.origem['pesoG']} value={l.pesoG} onChange={(v) => set('pesoG', v)} placeholder="—" />

            {/* A×L×C cm (3 inputs) */}
            <td className="px-1">
                <div className="flex items-center gap-0.5">
                    {[['alturaCm', 'A'], ['larguraCm', 'L'], ['comprimentoCm', 'C']].map(([campo, lb]) => (
                        <input
                            key={campo}
                            className="w-11 rounded bg-transparent px-1 py-2 text-right font-mono text-[12px] text-white outline-none focus:bg-ecf-yellow/[0.08]"
                            value={l[campo]}
                            onChange={(e) => set(campo, e.target.value)}
                            placeholder={lb}
                            title={{ alturaCm: 'Altura', larguraCm: 'Largura', comprimentoCm: 'Comprimento' }[campo]}
                        />
                    ))}
                </div>
            </td>

            {/* Foto (placeholder — upload real fora do escopo desta grade) */}
            <td className="px-2">
                <div className="mx-auto grid h-8 w-8 place-items-center rounded border border-dashed border-white/[0.12] text-white/30">+</div>
            </td>

            {/* Ficha técnica dinâmica: uma coluna por atributo obrigatório da categoria */}
            {obrig.map((o) => (
                <td key={o.id} className="min-w-[130px]">
                    {o.value_type === 'list' && Array.isArray(o.values) && o.values.length > 0 ? (
                        <div className="relative">
                            {l.origem[o.id] && <OrigemBadge origem={l.origem[o.id]} />}
                            <select
                                className="w-full bg-transparent px-2.5 py-2 text-white outline-none focus:bg-ecf-yellow/[0.08] [&>option]:bg-ecf-card"
                                value={l.attrs[o.id] ?? ''}
                                onChange={(e) => setAttr(o.id, e.target.value)}
                            >
                                <option value="">—</option>
                                {o.values.map((v) => (
                                    <option key={v.id ?? v.name} value={v.name}>{v.name}</option>
                                ))}
                            </select>
                        </div>
                    ) : (
                        <Cell origem={l.origem[o.id]}>
                            <input
                                className="w-full bg-transparent px-2.5 py-2 text-white outline-none focus:bg-ecf-yellow/[0.08]"
                                value={l.attrs[o.id] ?? ''}
                                onChange={(e) => setAttr(o.id, e.target.value)}
                                placeholder="—"
                            />
                        </Cell>
                    )}
                </td>
            ))}

            {/* Ações: remover linha (coluna FIXA à direita — sempre visível no scroll) */}
            <td className="sticky right-0 z-10 bg-ecf-card-2 px-2 text-center">
                <button
                    onClick={() => onRemoverLinha?.(l.uid)}
                    title="Remover linha"
                    className="inline-flex items-center justify-center rounded-md border border-white/[0.08] bg-white/[0.03] p-1.5 text-white/50 hover:border-red-400/40 hover:bg-red-500/10 hover:text-red-400"
                >
                    <Trash2 className="h-4 w-4" />
                </button>
            </td>
        </tr>

        {/* Linha-de-erro expandida: erro LOCAL bloqueante (campo obrigatório vazio) */}
        {temErroLocal && (
            <tr>
                <td className="sticky left-0 z-10 bg-ecf-card-2" />
                <td colSpan={totalCols} className="border-b border-red-500/20 bg-red-500/[0.06] px-3 py-1.5">
                    <span className="inline-flex items-center gap-1.5 text-[12px] text-red-300">
                        <AlertTriangle className="h-3 w-3" />
                        Fora do lote — preencha: <b>{errosLocais.join(', ')}</b>
                    </span>
                </td>
            </tr>
        )}

        {/* Linha-de-avisos expandida: avisos do ML (orientativos, NÃO bloqueiam publicar) */}
        {!temErroLocal && avisosMl.length > 0 && (
            <tr>
                <td className="sticky left-0 z-10 bg-ecf-card-2" />
                <td colSpan={totalCols} className="border-b border-amber-400/20 bg-amber-400/[0.05] px-3 py-1.5">
                    <span className="text-[12px] text-amber-200/90">
                        <b>Avisos do ML (revisar — não impedem publicar):</b>{' '}
                        {avisosMl.map((e) => e.mensagem).join(' · ')}
                    </span>
                </td>
            </tr>
        )}
        </>
    );
}

// ─── Célula com badge de origem no canto (violeta cliente / âmbar publicador) ───
function Cell({ children, origem }) {
    return (
        <td className="relative">
            {origem && <OrigemBadge origem={origem} />}
            {children}
        </td>
    );
}

// ─── Célula-input simples (numérica ou texto) com badge de origem ───
function CellInput({ value, onChange, placeholder, num, origem }) {
    return (
        <td className="relative">
            {origem && <OrigemBadge origem={origem} />}
            <input
                className={cn(
                    'w-full min-w-[90px] bg-transparent px-2.5 py-2 text-white outline-none focus:bg-ecf-yellow/[0.08]',
                    num && 'text-right font-mono tabular-nums',
                )}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
            />
        </td>
    );
}

// ─── Bolinha de origem (SHEET-04): violeta = cliente, âmbar = publicador ───
function OrigemBadge({ origem }) {
    return (
        <span
            title={origem === 'cliente' ? 'Preenchido pelo cliente' : 'Editado pelo publicador'}
            className={cn(
                'pointer-events-none absolute right-1 top-1 z-10 h-1.5 w-1.5 rounded-full',
                origem === 'cliente' ? 'bg-violet-400' : 'bg-amber-400',
            )}
        />
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Monta o payload de UMA linha no shape do ItemBuilder — espelha montarPayload()
// de AnunciarML.jsx:1350. Base → title/price/available_quantity/listing_type_id;
// dimensões → attributes SELLER_PACKAGE_*; ficha técnica → attributes {id,value_name}.
// ═══════════════════════════════════════════════════════════════════════
function montarPayloadLinha(l, aba) {
    const maxTitle = aba.max_title_length ?? 60;

    // Ficha técnica da categoria → attributes {id, value_name}
    const attributes = Object.entries(l.attrs ?? {})
        .filter(([, v]) => v != null && String(v).trim() !== '')
        .map(([id, v]) => ({ id, value_name: String(v).trim() }));

    // GTIN e SKU como atributos do item (quando informados)
    if (l.gtin?.trim()) attributes.push({ id: 'GTIN', value_name: l.gtin.trim() });
    if (l.sku?.trim()) attributes.push({ id: 'SELLER_SKU', value_name: l.sku.trim() });

    // Peso e dimensões do pacote → SELLER_PACKAGE_* (habilita o me2)
    if (l.pesoG)         attributes.push({ id: 'SELLER_PACKAGE_WEIGHT', value_name: `${l.pesoG} g` });
    if (l.alturaCm)      attributes.push({ id: 'SELLER_PACKAGE_HEIGHT', value_name: `${l.alturaCm} cm` });
    if (l.comprimentoCm) attributes.push({ id: 'SELLER_PACKAGE_LENGTH', value_name: `${l.comprimentoCm} cm` });
    if (l.larguraCm)     attributes.push({ id: 'SELLER_PACKAGE_WIDTH',  value_name: `${l.larguraCm} cm` });

    return {
        title: (l.title ?? '').slice(0, maxTitle),
        category_id: aba.category_id,
        price: l.price ? Number(l.price) : null,
        currency_id: 'BRL',
        available_quantity: l.estoque ? Number(l.estoque) : null,
        condition: 'new',
        listing_type_id: l.tier || 'gold_special',
        attributes,
        pictures: [],
        sale_terms: [],
        shipping: { mode: 'me2', local_pick_up: false, free_shipping: false },
        description: l.descricao ?? '',
        // Mapa de origem por campo (cliente × publicador) — SHEET-04
        meta_campos: { ...(l.origem ?? {}) },
    };
}

// ─── Linha vazia (estrutura em memória de uma linha da grade) ───
// campos base + atributos por id (attrs) + meta de origem + estado de persistência.
function linhaVazia() {
    return {
        uid: Math.random().toString(36).slice(2),
        id: null,            // id do ml_anuncio_rascunho (null enquanto não salvo)
        title: '',
        tier: 'gold_special', // Clássico; gold_pro = Premium
        price: '',
        estoque: '',
        sku: '',
        gtin: '',
        pesoG: '',
        alturaCm: '',
        larguraCm: '',
        comprimentoCm: '',
        descricao: '',
        attrs: {},           // { [attrId]: value } — ficha técnica da categoria
        origem: {},          // { [campo]: 'cliente' | 'publicador' }
        salvando: false,
        salvo: false,
    };
}

// ─── Reconstrói uma linha da grade a partir de um rascunho salvo (payload) ───
function linhaDeRascunho(r) {
    const p = r.payload ?? {};
    const l = linhaVazia();
    l.id = r.id;
    l.title = p.title ?? '';
    l.tier = p.listing_type_id ?? 'gold_special';
    l.price = p.price != null ? String(p.price) : '';
    l.estoque = p.available_quantity != null ? String(p.available_quantity) : '';
    l.descricao = p.description ?? '';
    l.salvo = true;

    const attrs = Array.isArray(p.attributes) ? p.attributes : [];
    attrs.forEach((a) => {
        const id = String(a.id);
        if (id === 'SELLER_PACKAGE_WEIGHT') { l.pesoG = String(parseFloat(a.value_name) || ''); return; }
        if (id === 'SELLER_PACKAGE_HEIGHT') { l.alturaCm = String(parseFloat(a.value_name) || ''); return; }
        if (id === 'SELLER_PACKAGE_WIDTH')  { l.larguraCm = String(parseFloat(a.value_name) || ''); return; }
        if (id === 'SELLER_PACKAGE_LENGTH') { l.comprimentoCm = String(parseFloat(a.value_name) || ''); return; }
        if (id.includes('GRID')) return;
        // GTIN e SKU são atributos do item no ML
        if (id === 'GTIN') { l.gtin = a.value_name ?? ''; return; }
        if (id === 'SELLER_SKU') { l.sku = a.value_name ?? ''; return; }
        // Demais → ficha técnica da categoria
        l.attrs[id] = a.value_id ?? a.value_name ?? '';
    });

    // Origem por campo (cliente × publicador) — SHEET-04
    if (p.meta_campos && typeof p.meta_campos === 'object') {
        l.origem = { ...p.meta_campos };
    }
    return l;
}
