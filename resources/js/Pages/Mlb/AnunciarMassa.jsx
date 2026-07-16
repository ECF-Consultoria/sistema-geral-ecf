import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Rocket, Search, Plus, Loader2, Check, AlertTriangle, Copy, Trash2 } from 'lucide-react';
import ModoAnuncioTabs from '@/Pages/Mlb/ModoAnuncioTabs';
import GradeAnuncioGlide from '@/Pages/Mlb/GradeAnuncioGlide';
// Helpers puros da grade (validação/coerção) — moram fora daqui para a grade em
// canvas poder importá-los sem criar ciclo de import página↔grade.
// As funções de coerção do paste (semAcento/normalizarTipoAnuncio/parseDimensoes/
// casarValueList) e o gerarEan13 agora são consumidos pela grade, não por aqui.
import {
    nomeCurto,
    errosLocaisLinha,
    linhaPublicavel,
    linhaVazia,
    mesclarStatusRascunhos,
    CAMPOS_FOTO,
    fotosDaLinha,
} from '@/Pages/Mlb/gradeMassaUtils';

// ═══════════════════════════════════════════════════════════════════════
// Anunciar em massa — aba "Em massa" de /mlb/anuncios (a "Individual" é o
// wizard, AnunciarML.jsx; o ModoAnuncioTabs alterna entre as duas).
//
// Esta PÁGINA é dona do estado: abas por categoria, linhas, autosave com
// debounce por linha, puxar produtos do cliente, validar e publicar em lote.
// Ela NÃO desenha a grade — quem desenha é GradeAnuncioGlide.jsx (canvas), que
// recebe a aba ativa e devolve edições pelos callbacks daqui. A divisão é
// deliberada: a grade só desenha e delega; o ciclo de vida mora aqui.
//
// Cada linha = 1 ml_anuncio_rascunho da empresa fixada. Colunas = campos base +
// ficha técnica obrigatória da categoria da aba ATIVA (nunca a união das abas).
// Consome os 3 endpoints (massa / massa.colunas / massa.produtos) e reusa o
// shape de payload do wizard (montarPayload de AnunciarML.jsx).
// ═══════════════════════════════════════════════════════════════════════

// ─── Iniciais da empresa para o "dot" do chip ───
const iniciais = (nome) =>
    (nome ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('') || '?';

// ─── Aba temporária "Sem categoria" (rascunhos ainda sem category_id) ───
const SEM_CATEGORIA = '__sem_categoria__';

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
    // Polling do resultado da publicação: deadline em ref (não re-renderiza) e o
    // sinal de estouro, que vira aviso pt-BR na PublishBar.
    const deadlineRef = useRef(null);
    const [pollingEstourado, setPollingEstourado] = useState(false);

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

    // ─── Sincroniza o estado do SERVIDOR quando a prop `rascunhos` recarrega ───
    // O efeito de init acima roda UMA vez (deps []) — de propósito: re-agrupar as abas
    // a cada poll faria o publicador perder a aba ativa e a ordem das linhas. Mas sem
    // ESTE segundo efeito, `router.reload({only:['rascunhos']})` atualiza a prop e a
    // grade (que renderiza `abas`) não vê nada. Era essa a causa de "publiquei e a tela
    // ficou em loading para sempre".
    // O merge só toca os 4 campos de servidor — nunca o que o usuário está digitando.
    useEffect(() => {
        setAbas((prev) => mesclarStatusRascunhos(prev, rascunhos));
    }, [rascunhos]);

    // ─── Polling enquanto houver anúncio em publicação (FIX-83-2) ───
    // Mesma forma que já roda em produção no wizard (AnunciarML.jsx): recarrega só a
    // prop `rascunhos` a cada 3s e para sozinho quando ninguém mais está `publicando`.
    // O teto existe porque o lote aceita até 50 rascunhos e o backend escalona os jobs
    // a 3s por posição: um job morto deixaria isto girando para sempre — que é
    // justamente o bug que esta fase conserta.
    useEffect(() => {
        const emVoo = (rascunhos ?? []).filter((r) => r.status === 'publicando').length;
        if (emVoo === 0 || pollingEstourado) {
            deadlineRef.current = null; // próximo lote recalcula
            return;
        }
        if (deadlineRef.current === null) {
            // 3s por posição de fan-out + folga; nunca mais que 10 min
            deadlineRef.current = Date.now() + Math.min(600_000, 120_000 + 3_000 * emVoo * 2);
        }
        const t = setInterval(() => {
            if (Date.now() > deadlineRef.current) {
                clearInterval(t);
                setPollingEstourado(true);
                return;
            }
            router.reload({ only: ['rascunhos'] });
        }, 3000);
        return () => clearInterval(t);
    }, [rascunhos, pollingEstourado]);

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
                        // Características secundárias (atributos opcionais da categoria):
                        // o ML pede no anúncio dele e elas pesam na qualidade/busca.
                        opcionais: d.opcionais ?? [],
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

    // ═══════════════════════════════════════════════════════════════════════
    // Remover a categoria ativa (FIX-83-6b).
    //
    // As linhas NÃO são apagadas: migram para a aba "Sem categoria" (decisão do
    // usuário — remover categoria por engano não pode custar o trabalho digitado).
    // Nenhum destroy no backend; os rascunhos continuam lá, só perdem a aba.
    //
    // Aba vazia some direto, sem perguntar (não há o que preservar).
    //
    // As linhas migradas param de contar como publicáveis (linhaPublicavel exige
    // aba.category_id) — sem isso, publicar mandaria category_id: null e o ML
    // devolveria 400 uma vez por linha. O chip "N sem categoria" na PublishBar
    // mostra que elas estão esperando classificação.
    // ═══════════════════════════════════════════════════════════════════════
    const removerCategoria = useCallback(() => {
        const idx = abaAtivaRef.current;
        const alvo = abasRef.current[idx];
        if (!alvo?.category_id) return;

        const n = alvo.linhas?.length ?? 0;
        if (n > 0) {
            const msg = n === 1
                ? 'Remover esta categoria? A linha vai para “Sem categoria” (nada é apagado).'
                : `Remover esta categoria? As ${n} linhas vão para “Sem categoria” (nada é apagado).`;
            if (!window.confirm(msg)) return;
        }

        setAbas((prev) => {
            const remover = prev[idx];
            if (!remover) return prev;
            const restantes = prev.filter((_, i) => i !== idx);
            if (n === 0) {
                setAbaAtiva(0);
                return restantes.length ? restantes : prev;
            }
            // Migra as linhas para a aba "Sem categoria" (cria se não existir)
            const orfas = remover.linhas.map((l) => ({ ...l, salvo: false }));
            const iSem = restantes.findIndex((a) => a.key === SEM_CATEGORIA);
            if (iSem >= 0) {
                const novas = restantes.map((a, i) => i !== iSem ? a : { ...a, linhas: [...a.linhas, ...orfas] });
                setAbaAtiva(iSem);
                return novas;
            }
            const novas = [...restantes, {
                key: SEM_CATEGORIA, category_id: null, caminho: [], obrigatorios: [],
                max_title_length: 60, catalog_required: false, carregando: false, linhas: orfas,
            }];
            setAbaAtiva(novas.length - 1);
            return novas;
        });
    }, []);

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
    const editarCelula = useCallback((uid, campo, valor, { attr = false, chaveOrigem } = {}) => {
        setAbas((prev) => prev.map((a, i) => {
            if (i !== abaAtiva) return a;
            return {
                ...a,
                linhas: a.linhas.map((l) => {
                    if (l.uid !== uid) return l;
                    // A chave do mapa de origem nem sempre e o nome do campo: puxarProduto
                    // grava origem.available_quantity / origem.description (e nao .estoque /
                    // .descricao). Quem chama informa a chave certa via opts.chaveOrigem;
                    // sem ela, cai no proprio campo (comportamento anterior, intacto).
                    const kOrigem = chaveOrigem ?? campo;
                    const origemAntiga = l.origem[kOrigem];
                    const origem = origemAntiga === 'cliente'
                        ? { ...l.origem, [kOrigem]: 'publicador' } // publicador reescreveu campo do cliente
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
    // ═══════════════════════════════════════════════════════════════════════
    // Histórico de undo/redo (Ctrl+Z / Ctrl+Y) — FASE 84.
    //
    // A lib NÃO tem undo nativo (conferido no .d.ts: não está em
    // ConfigurableKeybinds nem em ForcedKeybinds), então Ctrl+Z nem é
    // interceptado por ela — chega aqui pelo wrapper DOM da grade.
    //
    // O snapshot é BARATO porque `abas` já é imutável: todo setAbas cria objetos
    // novos, então guardar histórico é guardar REFERÊNCIA, não clonar dados.
    //
    // Escopo (decisão do usuário): undo LOCAL da grade — digitação, paste, fill e
    // delete. Não desfaz criação/remoção de linha já persistida: exigiria endpoint
    // de restauração e reconciliar ids no banco.
    // ═══════════════════════════════════════════════════════════════════════
    const historicoRef = useRef({ undo: [], redo: [] });
    const [podeDesfazer, setPodeDesfazer] = useState(false);
    const [podeRefazer, setPodeRefazer] = useState(false);

    const sincronizarBotoes = useCallback(() => {
        const h = historicoRef.current;
        setPodeDesfazer(h.undo.length > 0);
        setPodeRefazer(h.redo.length > 0);
    }, []);

    // Empilha o estado ANTES da mutação. Uma ação nova invalida o redo (como no Excel).
    //
    // AGRUPAMENTO: um paste de 50 células chama isto 50 vezes em sequência síncrona;
    // sem agrupar, cada Ctrl+Z desfaria UMA célula e seriam 50 undos para desfazer um
    // paste — no Excel, um paste é UMA ação. Edições dentro da mesma janela curta
    // (o mesmo onCellsEdited: paste, fill handle, delete em range) entram como uma
    // ação só. Digitar célula a célula fica bem acima dessa janela, então continua
    // sendo um undo por célula.
    const ultimaEdicaoRef = useRef(0);
    const empilharHistorico = useCallback(() => {
        const agora = Date.now();
        const mesmoLote = agora - ultimaEdicaoRef.current < 120;
        ultimaEdicaoRef.current = agora;
        if (mesmoLote) return; // já empilhou no início deste lote

        const h = historicoRef.current;
        h.undo.push(abasRef.current);
        if (h.undo.length > 50) h.undo.shift(); // teto: 50 ações
        h.redo = [];
        sincronizarBotoes();
    }, [sincronizarBotoes]);

    // Re-agenda o autosave das linhas que o undo/redo mudou.
    // Sem isto, a tela mostraria o valor revertido e o banco continuaria com o novo
    // — pior que não ter undo.
    const reSalvarDiferencas = useCallback((depois, antes) => {
        depois.forEach((aba, i) => {
            const abaAntes = antes[i];
            if (!abaAntes || abaAntes === aba) return; // referência igual = nada mudou
            aba.linhas.forEach((l) => {
                const lAntes = abaAntes.linhas.find((x) => x.uid === l.uid);
                if (l.id && lAntes !== l) agendarSalvar(i, l.uid);
            });
        });
    }, [agendarSalvar]);

    const desfazer = useCallback(() => {
        const h = historicoRef.current;
        const anterior = h.undo.pop();
        if (!anterior) return;
        const atual = abasRef.current;
        h.redo.push(atual);
        setAbas(anterior);
        reSalvarDiferencas(anterior, atual);
        sincronizarBotoes();
    }, [reSalvarDiferencas, sincronizarBotoes]);

    const refazer = useCallback(() => {
        const h = historicoRef.current;
        const proximo = h.redo.pop();
        if (!proximo) return;
        const atual = abasRef.current;
        h.undo.push(atual);
        setAbas(proximo);
        reSalvarDiferencas(proximo, atual);
        sincronizarBotoes();
    }, [reSalvarDiferencas, sincronizarBotoes]);

    const editarComSalvar = useCallback((uid, campo, valor, opts) => {
        empilharHistorico(); // snapshot ANTES da mutação
        editarCelula(uid, campo, valor, opts);
        agendarSalvar(abaAtivaRef.current, uid);
    }, [editarCelula, agendarSalvar, empilharHistorico]);

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
        // Estado do servidor: o que já foi publicado, o que falhou de verdade no ML,
        // e o que não pode publicar por não ter categoria (payload iria com
        // category_id: null = 400 garantido).
        let publicados = 0, comErroPublicacao = 0, semCategoria = 0;
        const idsPublicaveis = [];
        abas.forEach((a) => {
            a.linhas.forEach((l) => {
                // Ignora linhas totalmente em branco (não contam no lote)
                const vazia = !String(l.title ?? '').trim() && !l.price && Object.keys(l.attrs ?? {}).length === 0;
                if (vazia) return;
                total++;
                if (l.statusServidor === 'publicado') publicados++;
                if (l.statusServidor === 'erro') comErroPublicacao++;
                if (!a.category_id) semCategoria++;
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
        return { total, publicaveis, comErroLocal, comAvisosMl, idsPublicaveis,
                 publicados, comErroPublicacao, semCategoria };
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
        // Lote novo: zera o estouro do lote anterior, senão o polling deste nem liga
        setPollingEstourado(false);
        deadlineRef.current = null;
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
            // 422 (token desconectado) ou 403 (empresa não atribuída) → mensagem pt-BR
            setErrosLote(err.response?.data?.erros ?? [{ mensagem: 'Erro ao enfileirar publicação em lote.' }]);
        } finally {
            // SEMPRE destrava. Antes o setPublicandoLote(false) só existia no catch:
            // no caminho de SUCESSO o botão ficava travado para sempre, porque
            // router.reload({only}) é recarga parcial do Inertia e preserva o estado
            // local do React (o componente não remonta, o estado não zera).
            // Quem mostra o andamento a partir daqui é o status por linha + o polling.
            setPublicandoLote(false);
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

                {/* Alternância entre modo Individual e Em massa (esta grade) */}
                <div className="mb-4">
                    <ModoAnuncioTabs empresaId={empresa.id} modo="massa" />
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

                    {/* Remover a categoria ativa — as linhas NÃO são apagadas, migram
                        para "Sem categoria" (decisão do usuário: reversível por design). */}
                    {aba?.category_id && (
                        <button
                            onClick={removerCategoria}
                            title="Remove a aba — as linhas vão para “Sem categoria”, nada é apagado"
                            className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-white/[0.12] px-3 py-2 text-sm text-white/40 hover:border-red-500/40 hover:text-red-300"
                        >
                            <Trash2 className="h-3.5 w-3.5" /> Remover categoria
                        </button>
                    )}
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

                {/* ─── Grade da aba ativa (planilha em canvas) ─── */}
                {aba && (
                    <GradeAnuncioGlide
                        aba={aba}
                        empresa={empresa}
                        onEditarCelula={editarComSalvar}
                        onAdicionarLinha={adicionarLinha}
                        onRemoverLinha={removerLinha}
                        onDesfazer={desfazer}
                        onRefazer={refazer}
                        podeDesfazer={podeDesfazer}
                        podeRefazer={podeRefazer}
                    />
                )}

                {/* Detalhes do lote: erros reais e avisos do ML (fora do canvas) */}
                <PainelDetalhesLote abas={abas} />
            </div>

            {/* ═══ Barra fixa inferior: resumo do lote + ações (SHEET-05/07) ═══ */}
            <PublishBar
                resumo={resumoLote}
                validando={validandoLote}
                publicando={publicandoLote}
                erros={errosLote}
                aviso={pollingEstourado
                    ? 'Ainda há anúncio(s) em publicação. O resultado pode estar incompleto — recarregue a página para ver o status atual.'
                    : avisoLote}
                onValidarTudo={validarTudo}
                onPublicarLote={publicarLote}
            />
        </AppLayout>
    );
}

// ─── Copia texto pro clipboard (com fallback pra contexto sem navigator.clipboard) ───
function copiarTexto(txt) {
    if (!txt) return;
    navigator.clipboard?.writeText(txt).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = txt;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch { /* sem clipboard: falha em silêncio */ }
        document.body.removeChild(ta);
    });
}

// ═══════════════════════════════════════════════════════════════════════
// Painel de detalhes do lote — FORA do canvas (aqui Tailwind funciona).
//
// Existe porque canvas não hospeda tooltip nem <details>: a grade mostra o
// glifo (✕ / ◐) e o motivo legível vem aqui embaixo. Percorre TODAS as abas,
// não só a ativa — o lote é o conjunto, e o erro pode estar numa aba que o
// publicador não está olhando.
//
// Erros REAIS de publicação (o ML recusou) trazem a resposta crua da API num
// <details> expansível + botão de copiar. Avisos do /items/validate são
// orientativos e NÃO impedem publicar — por isso ficam numa seção separada.
// ═══════════════════════════════════════════════════════════════════════
function PainelDetalhesLote({ abas }) {
    const erros = useMemo(() => {
        const out = [];
        abas.forEach((a) => {
            a.linhas.forEach((l, i) => {
                if (l.statusServidor !== 'erro') return;
                out.push({
                    uid: l.uid,
                    numero: i + 1, // o mesmo número que o rowMarkers mostra na grade
                    aba: nomeCurto(a.caminho, a.category_id),
                    titulo: l.title || '(sem título)',
                    erroResumo: l.erroResumo,
                    erroCompleto: l.erroCompleto,
                });
            });
        });
        return out;
    }, [abas]);

    const avisos = useMemo(() => {
        const out = [];
        abas.forEach((a) => {
            a.linhas.forEach((l, i) => {
                const lista = (l.valida && !l.valida.valido) ? (l.valida.erros ?? []) : [];
                if (lista.length === 0) return;
                out.push({
                    uid: l.uid,
                    numero: i + 1,
                    aba: nomeCurto(a.caminho, a.category_id),
                    titulo: l.title || '(sem título)',
                    itens: lista,
                });
            });
        });
        return out;
    }, [abas]);

    if (erros.length === 0 && avisos.length === 0) return null;

    return (
        <div className="mb-24 mt-4 space-y-3">
            {/* ─── Falhas REAIS de publicação ─── */}
            {erros.length > 0 && (
                <div className="rounded-xl border border-red-500/30 bg-red-500/[0.04] p-3">
                    <div className="mb-2 flex items-center gap-1.5 text-[13px] font-medium text-red-300">
                        <AlertTriangle className="h-4 w-4" />
                        {erros.length} linha(s) falharam ao publicar no Mercado Livre
                    </div>
                    <div className="space-y-1.5">
                        {erros.map((e) => (
                            <details key={e.uid} className="group rounded-lg border border-white/[0.06] bg-ecf-bg/40">
                                <summary className="cursor-pointer list-none px-3 py-1.5 text-[12px] text-red-300/90 hover:text-red-200">
                                    <span className="text-white/40">Linha {e.numero} · {e.aba} ·</span>{' '}
                                    <span className="text-white/70">{e.titulo.slice(0, 40)}{e.titulo.length > 40 ? '…' : ''}</span>
                                    {e.erroResumo && <> — {e.erroResumo}</>}
                                    <span className="ml-1 text-white/25 group-open:hidden">▸ ver detalhes</span>
                                </summary>
                                <div className="border-t border-white/[0.06] px-3 py-2">
                                    <pre className="max-h-48 overflow-auto whitespace-pre-wrap break-words rounded border border-red-500/20 bg-red-500/[0.05] p-2 text-[11px] leading-relaxed text-red-200/80">
{e.erroCompleto || e.erroResumo || '(sem detalhes da API)'}
                                    </pre>
                                    <button
                                        type="button"
                                        onClick={() => copiarTexto(e.erroCompleto || e.erroResumo)}
                                        className="mt-1.5 inline-flex items-center gap-1 rounded-md border border-white/[0.1] bg-white/[0.03] px-2 py-1 text-[11px] text-white/60 hover:border-white/25 hover:text-white"
                                    >
                                        <Copy className="h-3 w-3" /> Copiar erro
                                    </button>
                                </div>
                            </details>
                        ))}
                    </div>
                </div>
            )}

            {/* ─── Avisos do /items/validate (orientativos: NÃO impedem publicar) ─── */}
            {avisos.length > 0 && (
                <div className="rounded-xl border border-amber-400/25 bg-amber-400/[0.03] p-3">
                    <div className="mb-2 text-[13px] font-medium text-amber-300/90">
                        ◐ {avisos.length} linha(s) com avisos do Mercado Livre
                        <span className="ml-1 font-normal text-white/35">— revisar, mas não impedem publicar</span>
                    </div>
                    <div className="space-y-1">
                        {avisos.map((a) => (
                            <div key={a.uid} className="rounded-lg border border-white/[0.06] bg-ecf-bg/40 px-3 py-1.5">
                                <div className="text-[12px]">
                                    <span className="text-white/40">Linha {a.numero} · {a.aba} ·</span>{' '}
                                    <span className="text-white/70">{a.titulo.slice(0, 40)}{a.titulo.length > 40 ? '…' : ''}</span>
                                </div>
                                <ul className="mt-0.5 space-y-0.5">
                                    {a.itens.map((it, k) => (
                                        <li key={k} className="text-[11px] text-amber-200/70">
                                            • {it.mensagem ?? String(it)}
                                            {it.campo && <span className="text-white/30"> ({it.campo})</span>}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// ═══════════════════════════════════════════════════════════════════════
// Barra fixa inferior (sketch .publish-bar): resumo agregado do lote + ações.
// SHEET-05: distingue "N publicáveis" (sem erro local) de "N com erro local"
// (bloqueante) e "N com avisos do ML" (orientativo, NÃO bloqueia).
// SHEET-07: botão primário publica todas as publicáveis; trava após o dispatch.
// ═══════════════════════════════════════════════════════════════════════
function PublishBar({ resumo, validando, publicando, erros, aviso, onValidarTudo, onPublicarLote }) {
    const { total, publicaveis, comErroLocal, comAvisosMl,
            publicados, comErroPublicacao, semCategoria } = resumo;
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
                        <span className="inline-flex items-center gap-1 text-amber-300/80" title="Avisos do Mercado Livre — revisar, mas NÃO impedem publicar. Veja a lista abaixo da grade.">
                            ◐ <b className="tabular-nums">{comAvisosMl}</b> com avisos do ML
                        </span>
                    )}
                    {/* Estado do servidor (chega pelo polling após publicar) */}
                    {publicados > 0 && (
                        <span className="text-emerald-300/80" title="Publicados no Mercado Livre nesta sessão">
                            ✓ <b className="tabular-nums">{publicados}</b> publicado(s)
                        </span>
                    )}
                    {comErroPublicacao > 0 && (
                        <span className="inline-flex items-center gap-1 text-red-300" title="Falha REAL na publicação (o Mercado Livre recusou) — diferente de erro local. Veja o motivo abaixo da grade.">
                            <AlertTriangle className="h-3.5 w-3.5" />
                            <b className="tabular-nums">{comErroPublicacao}</b> com erro na publicação
                        </span>
                    )}
                    {semCategoria > 0 && (
                        <span className="text-white/40" title="Defina a categoria da aba para poder publicar">
                            <b className="tabular-nums">{semCategoria}</b> sem categoria
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
        // Fotos por URL (COL-85-1). Era `pictures: []` HARDCODED: a grade publicava
        // todo anúncio sem foto nenhuma, e o ML recusa Premium sem foto
        // ("Item pictures are mandatory for listing type gold_pro") — todo Premium
        // do lote falhava 100% das vezes. Mesmo shape que o wizard já usa e que o
        // ItemBuilder já aceita: [{ source: url }].
        pictures: fotosDaLinha(l).map((source) => ({ source })),
        sale_terms: [],
        shipping: { mode: 'me2', local_pick_up: false, free_shipping: false },
        description: l.descricao ?? '',
        // Mapa de origem por campo (cliente × publicador) — SHEET-04
        meta_campos: { ...(l.origem ?? {}) },
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

    // Round-trip das fotos: sem ler `pictures` de volta, reabrir a página perde as
    // URLs e a linha volta a publicar sem foto EM SILÊNCIO (e o autosave seguinte
    // grava o payload sem elas). Aceita { source } e string crua.
    const pics = Array.isArray(p.pictures) ? p.pictures : [];
    CAMPOS_FOTO.forEach((campo, i) => {
        const pic = pics[i];
        l[campo] = (typeof pic === 'string' ? pic : pic?.source) ?? '';
    });

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

    // Estado do servidor: sem isto, reabrir a página no meio de um lote mostra as
    // linhas paradas e sem erro — e o merge não teria o `publicando` de que precisa
    // para inferir sucesso quando o rascunho sumir da prop.
    l.statusServidor = r.status ?? null;
    l.erroResumo = r.erro_resumo ?? null;
    l.erroCompleto = r.erro_completo ?? null;
    l.publicando = r.status === 'publicando';

    return l;
}
