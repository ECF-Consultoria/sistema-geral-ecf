import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { Search, CheckCircle2, AlertTriangle, Rocket, Save, Loader2, Tag, PackageOpen, Store, Copy, Check, ChevronLeft, ChevronRight, ChevronDown, Calculator, Plus, Trash2, Upload, Image, Pencil, Truck, Info, Gauge, Lightbulb } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { router } from '@inertiajs/react';
import ModoAnuncioTabs from '@/Pages/Mlb/ModoAnuncioTabs';
import {
    analisarAnuncio, MODALIDADES_FRETE, DICAS_DESCRICAO, INFO_EXPOSICAO_TIER,
    PRECO_FRETE_GRATIS_OBRIGATORIO, FOTOS_RECOMENDADAS_MIN, extrairSettingsCategoria,
} from '@/lib/mlAnuncioRegras';

// ─── Badges de status do rascunho ───
const STATUS_BADGE = {
    rascunho:   'bg-white/5 border border-white/15 text-white/70',
    validado:   'bg-blue-500/10 border border-blue-500/30 text-blue-400',
    publicando: 'bg-ecf-yellow/10 border border-ecf-yellow/30 text-ecf-yellow',
    publicado:  'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400',
    erro:       'bg-red-500/10 border border-red-500/30 text-red-400',
};
const STATUS_LABEL = {
    rascunho: 'Rascunho', validado: 'Validado', publicando: 'Publicando…',
    publicado: 'Publicado', erro: 'Erro',
};

const CONDICOES = [
    { v: 'new', l: 'Novo' },
    { v: 'used', l: 'Usado' },
    { v: 'not_specified', l: 'Não especificado' },
];

// ─── Etapas do wizard (WIZ-01: stepper sequencial) ───
const ETAPAS = [
    { k: 'categoria',  l: 'Título e categoria' },
    { k: 'atributos',  l: 'Ficha técnica' },
    { k: 'variacoes',  l: 'Variações' },
    { k: 'preco',      l: 'Preço e estoque' },
    { k: 'midia',      l: 'Imagem e frete' },
];

// ─── Parâmetros por tier (PRICE-03: mesmos defaults do Link do Publicador) ───
// classico = gold_special, premium = gold_pro
const TIER_CFG = {
    classico: { comissao: 0.115, imposto: 0.19 },
    premium:  { comissao: 0.165, imposto: 0.19 },
};

// ─── Cálculo de preço de anúncio (portado de ImplementacaoPublicador.jsx:9-13) ───
// Retorna o preço base (sem acréscimo de margem) dado custo e parâmetros do tier.
function calcPreco(custo, frete, comissao, imposto, mc, ll) {
    const d = 1 - comissao - imposto - mc - ll;
    if (d <= 0 || !custo || isNaN(parseFloat(custo))) return null;
    return (parseFloat(custo) + parseFloat(frete || 0)) / d;
}

// ─── Fallback de cópia para contextos sem Clipboard API (ex.: http não-seguro) ───
function fallbackCopiar(txt, done) {
    try {
        const ta = document.createElement('textarea');
        ta.value = txt;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        done?.();
    } catch { /* silencioso: sem clipboard disponível */ }
}

// ─── Chip de SKU clicável — copia o SKU com um clique (DRAFT-05) ───
function SkuCopyChip({ sku }) {
    const [copiado, setCopiado] = useState(false);
    if (!sku) return null;

    const copiar = (ev) => {
        ev.stopPropagation();
        const txt = String(sku);
        const ok = () => { setCopiado(true); setTimeout(() => setCopiado(false), 1200); };
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(txt).then(ok).catch(() => fallbackCopiar(txt, ok));
        } else {
            fallbackCopiar(txt, ok);
        }
    };

    return (
        <button
            type="button"
            onClick={copiar}
            title={copiado ? 'Copiado!' : `Copiar SKU (${sku})`}
            className={cn(
                'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px] font-mono tabular-nums transition shrink-0',
                copiado
                    ? 'border-emerald-400/40 bg-emerald-500/[0.12] text-emerald-300'
                    : 'border-white/10 bg-white/[0.04] text-white/45 hover:text-white/80 hover:border-white/25',
            )}
        >
            {copiado ? <Check size={10} /> : <Copy size={10} />}
            {copiado ? 'copiado' : sku}
        </button>
    );
}

// ─── Input padrão ECF com badge de origem (DRAFT-04) ───
// origem === 'cliente'    → badge violet
// origem === 'publicador' → badge amber ('editado')
// origem ausente          → sem badge (comportamento original preservado)
function Campo({ label, children, dica, origem }) {
    return (
        <label className="block">
            <span className="mb-1 flex items-center gap-1.5 text-xs font-medium text-white/60">
                {label}
                {origem === 'cliente' && (
                    <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-violet-500/10 text-violet-300/80">
                        cliente
                    </span>
                )}
                {origem === 'publicador' && (
                    <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-300/80">
                        editado
                    </span>
                )}
            </span>
            {children}
            {dica && <span className="mt-1 block text-[11px] text-white/40">{dica}</span>}
        </label>
    );
}

const inputCls = 'w-full rounded-lg border border-white/[0.08] bg-ecf-bg px-3 py-2 text-sm text-white placeholder-white/30 focus:border-ecf-yellow/50 focus:outline-none';

// ─── Formata valor em BRL ───
const fmtBRL = (n) => n != null ? `R$ ${Number(n).toFixed(2).replace('.', ',')}` : '—';

// ─── Componente StepperNav (WIZ-01: navegação sequencial — voltar sim, pular não) ───
// etapas concluídas (i < etapaAtual) são clicáveis (voltar); etapas à frente ficam esmaecidas.
function StepperNav({ etapaAtual, setEtapa }) {
    return (
        <nav className="flex items-center gap-1 mb-6 overflow-x-auto pb-1">
            {ETAPAS.map((e, i) => {
                const concluida = i < etapaAtual;
                const atual     = i === etapaAtual;
                const futura    = i > etapaAtual;
                return (
                    <button
                        key={e.k}
                        type="button"
                        onClick={() => concluida && setEtapa(i)}
                        disabled={futura}
                        className={cn(
                            'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap transition',
                            atual     && 'bg-ecf-yellow text-black',
                            concluida && 'text-emerald-400 hover:text-emerald-300 cursor-pointer',
                            futura    && 'text-white/30 cursor-default',
                        )}
                    >
                        {concluida && <CheckCircle2 size={12} />}
                        {!concluida && <span className="tabular-nums">{i + 1}.</span>}
                        {e.l}
                    </button>
                );
            })}
        </nav>
    );
}

// ─── Simulador de preço por tier (PRICE-01/02/03) ───
// Versão simplificada de PrecificacaoAcao (ImplementacaoPublicador): 1 produto, sem tabela.
// Botão "Usar este preço" preenche o campo Preço do wizard (PRICE-02).
// SHIP-02: recebe freteExterno (cotação automática do pai) — sincroniza simFrete via useEffect.
function SimuladorPreco({ setPreco, marcarEditado, freteExterno }) {
    const [simTier,     setSimTier]     = useState('classico');
    const [simCusto,    setSimCusto]    = useState('');
    const [simFrete,    setSimFrete]    = useState('');
    const [simMc,       setSimMc]       = useState('');
    const [simLl,       setSimLl]       = useState('');
    const [simAcrescimo, setSimAcrescimo] = useState('0.20');

    // SHIP-02: quando o pai obtém a estimativa de frete, preenche o campo aqui —
    // o publicador ainda pode sobrescrever manualmente (cotação é indicativa).
    useEffect(() => {
        if (freteExterno != null && freteExterno !== '') {
            setSimFrete(String(freteExterno));
        }
    }, [freteExterno]);

    // Cálculo do preço base e preço anunciado (com acréscimo)
    const cfg = TIER_CFG[simTier];
    const base = calcPreco(
        simCusto, simFrete,
        cfg.comissao, cfg.imposto,
        parseFloat(simMc  || 0),
        parseFloat(simLl  || 0),
    );
    const anunciado = base != null
        ? base * (1 + parseFloat(simAcrescimo || 0))
        : null;

    const usarPreco = () => {
        if (anunciado != null) {
            // PRICE-02: preenche o campo Preço do wizard e marca como 'publicador'
            setPreco(anunciado.toFixed(2));
            marcarEditado('price');
        }
    };

    return (
        <div className="mt-5 rounded-xl border border-white/[0.08] bg-ecf-bg p-4">
            <div className="mb-3 flex items-center gap-2">
                <Calculator size={14} className="text-ecf-yellow/70" />
                <span className="text-xs font-semibold text-white/70">Simulador de preço</span>
            </div>

            {/* Toggle Clássico / Premium (PRICE-03) */}
            <div className="mb-4 inline-flex rounded-xl bg-white/[0.04] border border-white/[0.08] p-1">
                {[{ k: 'classico', l: 'Clássico' }, { k: 'premium', l: 'Premium' }].map(({ k, l }) => (
                    <button key={k} type="button" onClick={() => setSimTier(k)}
                        className={cn(
                            'px-4 py-1.5 rounded-lg text-[13px] font-semibold transition',
                            simTier === k ? 'bg-ecf-yellow text-black' : 'text-white/50 hover:text-white/80',
                        )}>
                        {l}
                    </button>
                ))}
            </div>

            {/* Parâmetros do tier exibidos como dica */}
            <p className="mb-3 text-[11px] text-white/30">
                Comissão {(cfg.comissao * 100).toFixed(1)}% · Imposto {(cfg.imposto * 100).toFixed(0)}%
            </p>

            {/* Inputs do simulador */}
            <div className="grid gap-2 sm:grid-cols-2">
                <Campo label="Custo (R$)">
                    <input className={inputCls} type="number" step="0.01" value={simCusto}
                        onChange={e => setSimCusto(e.target.value)} placeholder="0,00" />
                </Campo>
                <Campo label="Frete pago (R$)">
                    <input className={inputCls} type="number" step="0.01" value={simFrete}
                        onChange={e => setSimFrete(e.target.value)} placeholder="0,00" />
                </Campo>
                <Campo label="Margem de contribuição (ex.: 0.10)">
                    <input className={inputCls} type="number" step="0.01" value={simMc}
                        onChange={e => setSimMc(e.target.value)} placeholder="0.10" />
                </Campo>
                <Campo label="Lucro líquido (ex.: 0.05)">
                    <input className={inputCls} type="number" step="0.01" value={simLl}
                        onChange={e => setSimLl(e.target.value)} placeholder="0.05" />
                </Campo>
                <Campo label="Acréscimo (ex.: 0.20 = +20%)">
                    <input className={inputCls} type="number" step="0.01" value={simAcrescimo}
                        onChange={e => setSimAcrescimo(e.target.value)} placeholder="0.20" />
                </Campo>
            </div>

            {/* Resultado e botão Usar preço */}
            <div className="mt-4 flex items-center justify-between gap-3 rounded-lg border border-white/[0.08] bg-ecf-card px-4 py-3">
                {anunciado != null ? (
                    <div>
                        <span className="block text-[11px] text-white/40">Preço sugerido</span>
                        <span className="text-lg font-semibold text-ecf-yellow">{fmtBRL(anunciado)}</span>
                    </div>
                ) : (
                    <span className="text-[11px] text-amber-400/80">
                        {simCusto ? 'Comissões somam 100% ou mais — revise as margens.' : 'Informe o custo do produto.'}
                    </span>
                )}
                <button
                    type="button"
                    onClick={usarPreco}
                    disabled={anunciado == null}
                    className="flex shrink-0 items-center gap-1.5 rounded-lg bg-ecf-yellow px-3 py-2 text-xs font-semibold text-black hover:brightness-95 disabled:opacity-40"
                >
                    <Check size={12} /> Usar este preço
                </button>
            </div>
        </div>
    );
}

// ─── Validação local de variações ANTES do /items/validate (WIZ-07) ───
// Detecta combinação repetida, GTIN duplicado e foto faltando.
// Retorna array de {code, campo, mensagem} — mesmo shape que a UI de erros renderiza.
function validarVariacoesLocalmente(variacoes) {
    const erros = [];
    const combinacoesVistas = new Set();
    const gtinsVistos = new Set();

    variacoes.forEach((v, i) => {
        // Combinação repetida: chave = combinações ordenadas por id:value
        const chave = (v.attribute_combinations ?? [])
            .map(ac => `${ac.id}:${ac.value_id ?? ac.value_name ?? ''}`)
            .sort()
            .join('|');
        if (chave && combinacoesVistas.has(chave)) {
            erros.push({
                code: 'combinacao_repetida',
                campo: `Variação ${i + 1}`,
                mensagem: 'Combinação duplicada — já existe uma variação com esta combinação de atributos.',
            });
        }
        if (chave) combinacoesVistas.add(chave);

        // GTIN duplicado
        const gtin = v.attributes?.find(a => a.id === 'GTIN')?.value_name;
        if (gtin) {
            if (gtinsVistos.has(gtin)) {
                erros.push({
                    code: 'gtin_duplicado',
                    campo: `Variação ${i + 1}`,
                    mensagem: `GTIN ${gtin} duplicado — cada variação precisa de um GTIN único.`,
                });
            }
            gtinsVistos.add(gtin);
        }

        // Foto faltando
        if (!v.picture_ids?.length) {
            erros.push({
                code: 'foto_faltando',
                campo: `Variação ${i + 1}`,
                mensagem: 'Esta variação está sem foto — envie ao menos uma imagem.',
            });
        }
    });

    return erros;
}

// ─── Gera um EAN-13 válido (padrão Mercado Livre) ───
// Prefixo 789 (Brasil) + 9 dígitos aleatórios + dígito verificador (Módulo 10).
// Mesma lógica do gerador do time: posição 1-indexada, ímpar ×1 e par ×3; o dígito
// é (10 − soma%10) % 10. `existentes` (Set) evita repetir GTIN dentro do mesmo anúncio.
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
    // Fallback improvável (50 colisões seguidas)
    let base = '789';
    for (let i = 0; i < 9; i++) base += Math.floor(Math.random() * 10);
    return base + digitoVerificador(base);
}

// ─── Coleta os GTINs já usados nas variações (para não gerar repetido) ───
const gtinsUsados = (variacoes) =>
    new Set((variacoes ?? [])
        .map(v => v.attributes?.find(a => a.id === 'GTIN')?.value_name)
        .filter(Boolean));

// ─── Variação vazia (estrutura inicial de uma nova variação) ───
// Já nasce com o GTIN/EAN gerado (único no anúncio), como pediu o fluxo.
function novaVariacao(existentes = new Set()) {
    return {
        attribute_combinations: [], // [{id,name,value_id,value_name}] — atributos allow_variations
        attributes: [               // GTIN e SELLER_SKU ficam aqui (não em combinations)
            { id: 'GTIN',       value_name: gerarEan13(existentes) },
            { id: 'SELLER_SKU', value_name: '' },
        ],
        picture_ids:      [],   // ids retornados pelo upload
        picture_urls:     [],   // URLs de pré-visualização (estado local, não vai no payload)
        price:            '',
        available_quantity: '',
        size_grid_row_id: '', // SIZE_GRID_ROW_ID por variação (moda) — follow-up 77-06
    };
}

/**
 * VariacaoEditor — editor inline de variações do anúncio (WIZ-04, WIZ-05, WIZ-06).
 *
 * Props:
 *   variacoes     — array de variações (estado gerenciado pelo wizard)
 *   setVariacoes  — setter do array
 *   atributosVar  — atributos com allow_variations (filtrado do catálogo da categoria)
 *   rascunhoId    — id do rascunho (necessário para a rota de upload)
 *   onCriarRascunho — callback async que cria o rascunho e retorna o id (se ainda não existe)
 *   exigeGrade    — true quando a categoria é de moda e exige grade de tamanho
 *   grades        — array de grades disponíveis [{id, names:{pt_BR}}]
 *   sizeGridId    — valor atual do SIZE_GRID_ID selecionado
 *   setSizeGridId — setter do SIZE_GRID_ID
 */
function VariacaoEditor({
    variacoes,
    setVariacoes,
    atributosVar,
    rascunhoId,
    onCriarRascunho,
    exigeGrade,
    grades,
    sizeGridId,
    setSizeGridId,
}) {
    // Estado de upload por índice de variação: { [idx]: 'enviando' | 'ok' | 'erro' }
    const [uploadStatus, setUploadStatus] = useState({});
    // Arraste de fotos: quem está sendo arrastado e sobre qual alvo — { vIdx, fIdx }
    const [arrastando, setArrastando] = useState(null);
    const [alvoFoto, setAlvoFoto]     = useState(null);

    // ─── Adiciona uma nova variação vazia ───
    const adicionarVariacao = () => {
        // Gera o GTIN/EAN da nova variação evitando repetir os já usados no anúncio
        setVariacoes(vs => [...vs, novaVariacao(gtinsUsados(vs))]);
    };

    // ─── Remove variação pelo índice ───
    const removerVariacao = (idx) => {
        setVariacoes(vs => vs.filter((_, i) => i !== idx));
        setUploadStatus(s => {
            const n = { ...s };
            delete n[idx];
            return n;
        });
    };

    // ─── Atualiza um campo de attribute_combinations de uma variação ───
    // Para atributos do tipo lista (value_id) ou livre (value_name)
    const setAttrCombinacao = (idx, attrId, attrName, valueId, valueName) => {
        setVariacoes(vs => vs.map((v, i) => {
            if (i !== idx) return v;
            const semAtual = (v.attribute_combinations ?? []).filter(ac => ac.id !== attrId);
            const novo = valueId || valueName
                ? [...semAtual, { id: attrId, name: attrName, value_id: valueId || undefined, value_name: valueName || undefined }]
                : semAtual;
            return { ...v, attribute_combinations: novo };
        }));
    };

    // ─── Valor atual de um atributo de combinação ───
    const getAttrCombinacao = (variacao, attrId) =>
        variacao.attribute_combinations?.find(ac => ac.id === attrId) ?? {};

    // ─── Atualiza atributos simples (GTIN, SELLER_SKU) ───
    const setAttrSimples = (idx, attrId, value) => {
        setVariacoes(vs => vs.map((v, i) => {
            if (i !== idx) return v;
            const attrs = (v.attributes ?? []).map(a =>
                a.id === attrId ? { ...a, value_name: value } : a
            );
            // Garante que o atributo existe mesmo que não estivesse no array inicial
            if (!attrs.find(a => a.id === attrId)) {
                attrs.push({ id: attrId, value_name: value });
            }
            return { ...v, attributes: attrs };
        }));
    };

    const getAttrSimples = (variacao, attrId) =>
        variacao.attributes?.find(a => a.id === attrId)?.value_name ?? '';

    // ─── Atualiza campo direto da variação (price, available_quantity) ───
    const setCampoVariacao = (idx, campo, value) => {
        setVariacoes(vs => vs.map((v, i) => i !== idx ? v : { ...v, [campo]: value }));
    };

    // ─── Upload de foto(s) para uma variação (WIZ-05) ───
    // O upload é imediato: assim que o(s) arquivo(s) são selecionados, envia cada um
    // para a rota de imagem. Aceita múltiplos arquivos de uma vez (envio sequencial,
    // preservando a ordem de seleção). Se o rascunho ainda não existe, cria antes.
    const handleUploadFotos = async (idx, arquivos) => {
        const lista = Array.from(arquivos ?? []).filter(Boolean);
        if (lista.length === 0) return;

        // Garante que o rascunho existe antes de enviar
        let idRascunho = rascunhoId;
        if (!idRascunho) {
            idRascunho = await onCriarRascunho();
            if (!idRascunho) return; // falhou ao criar rascunho
        }

        setUploadStatus(s => ({ ...s, [idx]: 'enviando' }));

        let enviadas = 0;
        let ultimoErro = null;

        // Envia uma a uma para manter a ordem e acumular os picture_ids conforme retornam
        for (const arquivo of lista) {
            try {
                const r = await window.axios.postForm(
                    route('mlb.anuncios.rascunho.imagem', { rascunho: idRascunho }),
                    { imagem: arquivo },
                );
                const pictureId = r.data.picture_id;

                // Grava picture_id na variação e URL de pré-visualização (local, não vai no payload)
                const previewUrl = URL.createObjectURL(arquivo);
                setVariacoes(vs => vs.map((v, i) => {
                    if (i !== idx) return v;
                    return {
                        ...v,
                        picture_ids:  [...(v.picture_ids ?? []), pictureId],
                        picture_urls: [...(v.picture_urls ?? []), previewUrl],
                    };
                }));
                enviadas++;
            } catch (e) {
                // Mensagem de erro em pt-BR vinda do backend (422) ou genérica
                ultimoErro = e.response?.data?.message ?? 'Erro ao enviar foto — tente novamente.';
            }
        }

        if (ultimoErro) {
            // Se algumas subiram e outras não, informa o parcial junto do erro
            const prefixo = enviadas > 0 ? `${enviadas} enviada(s), mas ` : '';
            setUploadStatus(s => ({ ...s, [idx]: `erro: ${prefixo}${ultimoErro}` }));
        } else {
            setUploadStatus(s => ({ ...s, [idx]: 'ok' }));
        }
    };

    // ─── Remove uma foto de uma variação ───
    const removerFoto = (idx, fotoIdx) => {
        setVariacoes(vs => vs.map((v, i) => {
            if (i !== idx) return v;
            return {
                ...v,
                picture_ids:  (v.picture_ids ?? []).filter((_, fi) => fi !== fotoIdx),
                picture_urls: (v.picture_urls ?? []).filter((_, fi) => fi !== fotoIdx),
            };
        }));
    };

    // ─── Move uma foto na sequência (−1 = para trás/capa, +1 = para frente) ───
    // A ordem das fotos aqui é EXATAMENTE a que vai para o anúncio (a 1ª é a capa).
    // Reordena picture_ids e picture_urls juntos para manter id↔preview alinhados.
    const moverFoto = (idx, fotoIdx, direcao) => {
        setVariacoes(vs => vs.map((v, i) => {
            if (i !== idx) return v;
            const ids  = [...(v.picture_ids ?? [])];
            const urls = [...(v.picture_urls ?? [])];
            const destino = fotoIdx + direcao;
            if (destino < 0 || destino >= ids.length) return v; // fora dos limites
            [ids[fotoIdx],  ids[destino]]  = [ids[destino],  ids[fotoIdx]];
            [urls[fotoIdx], urls[destino]] = [urls[destino], urls[fotoIdx]];
            return { ...v, picture_ids: ids, picture_urls: urls };
        }));
    };

    // ─── Reordena por arraste: tira a foto de 'de' e insere na posição 'para' ───
    // Diferente do moverFoto (troca vizinhos), aqui pode mover para qualquer posição.
    const reordenarFoto = (idx, de, para) => {
        if (de === para) return;
        setVariacoes(vs => vs.map((v, i) => {
            if (i !== idx) return v;
            const ids  = [...(v.picture_ids ?? [])];
            const urls = [...(v.picture_urls ?? [])];
            if (de < 0 || de >= ids.length || para < 0 || para >= ids.length) return v;
            const [idMov]  = ids.splice(de, 1);
            const [urlMov] = urls.splice(de, 1);
            ids.splice(para, 0, idMov);
            urls.splice(para, 0, urlMov);
            return { ...v, picture_ids: ids, picture_urls: urls };
        }));
    };

    // COLOR é identificado pelo id que contém 'COLOR' (o ML usa 'COLOR' em todas as categorias)
    const attrCor = atributosVar.find(a => a.id === 'COLOR' || a.id === 'Cor' || a.name?.toLowerCase().includes('cor'));

    return (
        <div className="space-y-4">
            {/* ─── Select de grade de tamanho (WIZ-06) — categoria de moda ─── */}
            {exigeGrade && (
                <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 p-3 space-y-2">
                    <p className="text-xs font-semibold text-amber-300 flex items-center gap-1.5">
                        <AlertTriangle size={13} /> Grade de tamanho (categoria de moda)
                    </p>
                    {grades.length > 0 ? (
                        <Campo label="Selecione a grade de tamanho (SIZE_GRID_ID)">
                            <select
                                className={inputCls}
                                value={sizeGridId}
                                onChange={e => setSizeGridId(e.target.value)}
                            >
                                <option value="">Selecione a grade…</option>
                                {grades.map(g => (
                                    <option key={g.id} value={g.id}>
                                        {g.names?.pt_BR ?? g.id}
                                    </option>
                                ))}
                            </select>
                        </Campo>
                    ) : (
                        <p className="text-[11px] text-amber-300/70">
                            Nenhuma grade encontrada para esta categoria. Crie a grade no painel do Mercado Livre e recarregue.
                        </p>
                    )}
                    <p className="text-[10px] text-white/30">
                        SIZE_GRID_ROW_ID por variação: preencha o código da linha da grade em cada variação.
                        O código exato pode ser consultado no GET /catalog/charts/{'{id}'} (follow-up 77-06).
                    </p>
                </div>
            )}

            {/* ─── Lista de variações ─── */}
            {variacoes.map((v, idx) => {
                const statusUpload = uploadStatus[idx];
                return (
                    <div
                        key={idx}
                        className="rounded-xl border border-white/[0.10] bg-ecf-bg p-4 space-y-3"
                    >
                        {/* Cabeçalho da variação */}
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-semibold text-white/70">
                                Variação {idx + 1}
                            </span>
                            <button
                                type="button"
                                onClick={() => removerVariacao(idx)}
                                className="flex items-center gap-1 text-[11px] text-red-400/70 hover:text-red-400 transition"
                            >
                                <Trash2 size={12} /> Remover
                            </button>
                        </div>

                        {/* ─── Atributos allow_variations (Cor obrigatória + demais) ─── */}
                        <div className="grid gap-2 sm:grid-cols-2">
                            {atributosVar.map(a => {
                                const isCor = a.id === 'COLOR' || (a.name?.toLowerCase().includes('cor') && !a.name?.toLowerCase().includes('coron'));
                                const atual = getAttrCombinacao(v, a.id);
                                return (
                                    <Campo
                                        key={a.id}
                                        label={
                                            <span className="flex items-center gap-1">
                                                {a.name}
                                                {/* Cor é obrigatória — marcação visual */}
                                                {isCor && (
                                                    <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-red-500/10 text-red-400">
                                                        obrigatório
                                                    </span>
                                                )}
                                            </span>
                                        }
                                    >
                                        {(a.value_type === 'list' || a.value_type === 'boolean') && a.values?.length ? (
                                            <select
                                                className={cn(
                                                    inputCls,
                                                    isCor && !atual.value_id && !atual.value_name
                                                        ? 'border-red-500/40'
                                                        : '',
                                                )}
                                                value={atual.value_id ?? ''}
                                                onChange={e => {
                                                    const opt = a.values.find(vv => vv.id === e.target.value);
                                                    setAttrCombinacao(idx, a.id, a.name, e.target.value, opt?.name ?? '');
                                                }}
                                            >
                                                <option value="">Selecione…</option>
                                                {a.values.map(vv => (
                                                    <option key={vv.id} value={vv.id}>{vv.name}</option>
                                                ))}
                                            </select>
                                        ) : (
                                            <input
                                                className={cn(
                                                    inputCls,
                                                    isCor && !atual.value_name ? 'border-red-500/40' : '',
                                                )}
                                                value={atual.value_name ?? ''}
                                                placeholder={isCor ? 'Ex.: Azul' : `Ex.: ${a.name}`}
                                                onChange={e => setAttrCombinacao(idx, a.id, a.name, undefined, e.target.value)}
                                            />
                                        )}
                                        {/* Aviso quando Cor não preenchida */}
                                        {isCor && !atual.value_id && !atual.value_name && (
                                            <span className="mt-0.5 block text-[11px] text-red-400/80">
                                                Cor é obrigatória em variações.
                                            </span>
                                        )}
                                    </Campo>
                                );
                            })}
                        </div>

                        {/* ─── GTIN, SKU, preço, estoque ─── */}
                        <div className="grid gap-2 sm:grid-cols-2">
                            <Campo label="GTIN / EAN (código de barras)" dica="EAN-13 válido gerado automaticamente — pode editar ou gerar outro">
                                <div className="flex gap-1.5">
                                    <input
                                        className={inputCls}
                                        value={getAttrSimples(v, 'GTIN')}
                                        onChange={e => setAttrSimples(idx, 'GTIN', e.target.value)}
                                        placeholder="7891234567890"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setAttrSimples(idx, 'GTIN', gerarEan13(gtinsUsados(variacoes)))}
                                        title="Gerar um EAN-13 válido novo (não repete os já usados)"
                                        className="shrink-0 rounded-lg border border-white/10 bg-white/[0.03] px-2 text-[11px] font-medium text-white/70 transition hover:border-ecf-yellow/40 hover:text-ecf-yellow"
                                    >
                                        Gerar
                                    </button>
                                </div>
                            </Campo>
                            <Campo label="SKU do vendedor (SELLER_SKU)">
                                <input
                                    className={inputCls}
                                    value={getAttrSimples(v, 'SELLER_SKU')}
                                    onChange={e => setAttrSimples(idx, 'SELLER_SKU', e.target.value)}
                                    placeholder="SKU-AZUL-M"
                                />
                            </Campo>
                            {/* Sem preço por variação: no ML clássico todas herdam o preço do item
                                (definido no passo "Preço e estoque"). Preços diferentes = erro 357. */}
                            <Campo label="Estoque desta variação">
                                <input
                                    className={inputCls}
                                    type="number"
                                    min="0"
                                    value={v.available_quantity}
                                    onChange={e => setCampoVariacao(idx, 'available_quantity', e.target.value)}
                                    placeholder="1"
                                />
                            </Campo>

                            {/* SIZE_GRID_ROW_ID por variação (moda) — campo livre; follow-up 77-06 */}
                            {exigeGrade && (
                                <Campo label="Linha da grade (SIZE_GRID_ROW_ID)" dica="Código da linha da grade — veja /catalog/charts/{id}">
                                    <input
                                        className={inputCls}
                                        value={v.size_grid_row_id}
                                        onChange={e => setCampoVariacao(idx, 'size_grid_row_id', e.target.value)}
                                        placeholder="ex.: 12345"
                                    />
                                </Campo>
                            )}
                        </div>

                        {/* ─── Upload de foto por variação (WIZ-05) ─── */}
                        <div>
                            <span className="mb-1.5 block text-xs font-medium text-white/60">
                                Fotos desta variação
                                <span className="ml-1 text-[10px] text-white/30">(pode selecionar várias; enviadas imediatamente ao ML)</span>
                            </span>

                            {/* Miniaturas das fotos já enviadas — a ordem aqui é a do anúncio (1ª = capa) */}
                            {v.picture_urls?.length > 0 && (
                                <>
                                    {v.picture_urls.length > 1 && (
                                        <p className="mb-1.5 text-[10px] text-white/30">
                                            A 1ª foto é a <b className="text-ecf-yellow/70">capa</b>. Arraste para reordenar (ou use ◀ ▶) — o anúncio sai nesta mesma sequência.
                                        </p>
                                    )}
                                    <div className="mb-2 flex flex-wrap gap-2">
                                        {v.picture_urls.map((url, fi) => {
                                            const arrastandoEsta = arrastando?.vIdx === idx && arrastando?.fIdx === fi;
                                            const alvoDesta      = alvoFoto?.vIdx === idx && alvoFoto?.fIdx === fi && !arrastandoEsta;
                                            return (
                                            <div
                                                key={fi}
                                                draggable
                                                onDragStart={(e) => {
                                                    setArrastando({ vIdx: idx, fIdx: fi });
                                                    e.dataTransfer.effectAllowed = 'move';
                                                }}
                                                onDragOver={(e) => {
                                                    // Só aceita arraste dentro da MESMA variação
                                                    if (arrastando?.vIdx !== idx) return;
                                                    e.preventDefault();
                                                    e.dataTransfer.dropEffect = 'move';
                                                    if (alvoFoto?.fIdx !== fi) setAlvoFoto({ vIdx: idx, fIdx: fi });
                                                }}
                                                onDrop={(e) => {
                                                    e.preventDefault();
                                                    if (arrastando?.vIdx === idx) reordenarFoto(idx, arrastando.fIdx, fi);
                                                    setArrastando(null);
                                                    setAlvoFoto(null);
                                                }}
                                                onDragEnd={() => { setArrastando(null); setAlvoFoto(null); }}
                                                className={cn(
                                                    'relative group cursor-grab active:cursor-grabbing',
                                                    arrastandoEsta && 'opacity-40',
                                                )}
                                            >
                                                <img
                                                    src={url}
                                                    draggable={false}
                                                    alt={fi === 0 ? `Capa — variação ${idx + 1}` : `Foto ${fi + 1} — variação ${idx + 1}`}
                                                    className={cn(
                                                        'h-16 w-16 rounded-lg border object-cover transition',
                                                        fi === 0 ? 'border-ecf-yellow/60' : 'border-white/10',
                                                        alvoDesta && 'ring-2 ring-ecf-yellow ring-offset-1 ring-offset-ecf-card',
                                                    )}
                                                />
                                                {/* Selo: capa ou número da sequência */}
                                                <span className={cn(
                                                    'absolute left-0.5 top-0.5 rounded px-1 text-[9px] font-semibold leading-tight',
                                                    fi === 0 ? 'bg-ecf-yellow text-black' : 'bg-black/70 text-white/80',
                                                )}>
                                                    {fi === 0 ? 'Capa' : fi + 1}
                                                </span>
                                                {/* Remover */}
                                                <button
                                                    type="button"
                                                    onClick={() => removerFoto(idx, fi)}
                                                    className="absolute -right-1.5 -top-1.5 hidden group-hover:flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-white"
                                                >
                                                    <Trash2 size={9} />
                                                </button>
                                                {/* Mover na sequência (aparece no hover) */}
                                                {v.picture_urls.length > 1 && (
                                                    <div className="absolute inset-x-0 bottom-0 hidden group-hover:flex overflow-hidden rounded-b-lg bg-black/75">
                                                        <button
                                                            type="button"
                                                            onClick={() => moverFoto(idx, fi, -1)}
                                                            disabled={fi === 0}
                                                            title="Mover para trás (mais perto da capa)"
                                                            className="flex-1 flex items-center justify-center py-0.5 text-white/80 transition hover:text-ecf-yellow disabled:opacity-20 disabled:hover:text-white/80"
                                                        >
                                                            <ChevronLeft size={12} />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => moverFoto(idx, fi, 1)}
                                                            disabled={fi === v.picture_urls.length - 1}
                                                            title="Mover para frente"
                                                            className="flex-1 flex items-center justify-center py-0.5 text-white/80 transition hover:text-ecf-yellow disabled:opacity-20 disabled:hover:text-white/80"
                                                        >
                                                            <ChevronRight size={12} />
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                            );
                                        })}
                                    </div>
                                </>
                            )}

                            {/* Input de arquivo + feedback de upload */}
                            <label className={cn(
                                'flex cursor-pointer items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-xs transition',
                                statusUpload === 'enviando'
                                    ? 'border-ecf-yellow/40 text-ecf-yellow/70'
                                    : statusUpload === 'ok'
                                    ? 'border-emerald-500/40 text-emerald-400/70'
                                    : statusUpload?.startsWith('erro')
                                    ? 'border-red-500/40 text-red-400/70'
                                    : 'border-white/15 text-white/40 hover:border-white/30 hover:text-white/60',
                            )}>
                                {statusUpload === 'enviando' ? (
                                    <><Loader2 size={13} className="animate-spin" /> Enviando…</>
                                ) : statusUpload === 'ok' ? (
                                    <><CheckCircle2 size={13} /> Fotos enviadas — adicionar mais</>
                                ) : statusUpload?.startsWith('erro') ? (
                                    <><AlertTriangle size={13} /> {statusUpload.replace('erro: ', '')}</>
                                ) : (
                                    <><Upload size={13} /> Selecionar fotos</>
                                )}
                                <input
                                    type="file"
                                    accept="image/*"
                                    multiple
                                    className="sr-only"
                                    disabled={statusUpload === 'enviando'}
                                    onChange={e => {
                                        const arquivos = e.target.files;
                                        if (arquivos?.length) handleUploadFotos(idx, arquivos);
                                        // Reseta o input para permitir re-envio dos mesmos arquivos
                                        e.target.value = '';
                                    }}
                                />
                            </label>
                        </div>
                    </div>
                );
            })}

            {/* ─── Botão adicionar variação ─── */}
            <button
                type="button"
                onClick={adicionarVariacao}
                className="flex items-center gap-2 rounded-lg border border-dashed border-ecf-yellow/30 bg-ecf-yellow/5 px-4 py-2.5 text-sm font-medium text-ecf-yellow/80 hover:bg-ecf-yellow/10 hover:border-ecf-yellow/50 transition w-full justify-center"
            >
                <Plus size={15} /> Adicionar variação
            </button>
        </div>
    );
}

/**
 * Wizard de criação de anúncio — Momento 2 do módulo "Anunciar ML".
 *
 * Recebe `empresa` já fixada (passada pelo backend via rota mlb.anuncios.wizard).
 * NÃO exibe seletor de empresa — a empresa de destino é imutável no wizard (SEL-01).
 *
 * SEL-01: empresa fixada no header; ausência de seleção inline.
 * SEL-05: modal de confirmação antes de publicar, exibindo o nome da empresa.
 * SEL-07: salvar() envia mlb_empresa_id (não company_id) no POST rascunho.store.
 *
 * DRAFT-01: lista de produtos do cliente exibida antes do formulário.
 * DRAFT-04: badge violet 'cliente' / amber 'editado' por campo.
 * DRAFT-05: SkuCopyChip — copiar SKU com um clique + fallback.
 *
 * WIZ-01: stepper multi-etapa (StepperNav) com contador de título X/60.
 * WIZ-02: breadcrumb (path_from_root) nas sugestões e na categoria escolhida.
 * WIZ-03: banner + bloqueio de Publicar quando catalog_required sem CATALOG_PRODUCT_ID.
 * WIZ-08: preview lateral em tempo real (título/preço/imagem/empresa/breadcrumb).
 * PRICE-01/02/03: SimuladorPreco embutido na etapa de preço.
 */
export default function AnunciarML({ empresa = null, rascunhos = [], produtos = [], abrirRascunhoId = null }) {
    const [rascunhoId, setRascunhoId] = useState(null);

    // ─── Navegação do wizard (WIZ-01) ───
    const [etapa, setEtapa] = useState(0);

    // Seção "Características secundárias" ABERTA por padrão — quem publica deve preencher
    // (o ML pede e pesa na busca). Continua colapsável, mas nasce visível (não oculta).
    const [secundariasAbertas, setSecundariasAbertas] = useState(true);

    // Campos do anúncio
    const [titulo, setTitulo]         = useState('');
    const [categoryId, setCategoryId] = useState('');
    const [categoria, setCategoria]   = useState(null);   // settings da categoria
    const [atributos, setAtributos]   = useState([]);     // catálogo de atributos
    const [valores, setValores]       = useState({});     // {attrId: {value_id|value_name}}
    const [preco, setPreco]           = useState('');
    const [estoque, setEstoque]       = useState('1');
    const [condicao, setCondicao]     = useState('new');
    const [tipoAnuncio, setTipoAnuncio] = useState('gold_special');
    const [imagemUrl, setImagemUrl]   = useState('');
    const [garantia, setGarantia]     = useState('30 dias');
    const [descricao, setDescricao]   = useState('');
    // SHIP: modalidade de frete (me2/me1/not_specified) + frete grátis (free_shipping).
    // Flex e Full NÃO entram aqui — o logistic_type é derivado da habilitação da conta
    // no Mercado Livre, não do payload de publicação.
    const [shippingMode, setShippingMode] = useState('me2');
    const [freteGratis, setFreteGratis]   = useState(false);
    // Peso e dimensões do pacote — necessários para o ML calcular o frete me2
    const [pesoG, setPesoG]                 = useState('');
    const [comprimentoCm, setComprimentoCm] = useState('');
    const [larguraCm, setLarguraCm]         = useState('');
    const [alturaCm, setAlturaCm]           = useState('');

    // SHIP-02: frete externo elevado do SimuladorPreco para o pai —
    // preenchido automaticamente pela cotação e passado de volta como prop.
    const [freteExterno, setFreteExterno] = useState('');

    // ─── Rastreamento de origem dos campos (DRAFT-04) ───
    // Mapa { campo: 'cliente' | 'publicador' } — populado por hidratarDoRascunho()
    const [origemCampos, setOrigemCampos] = useState({});

    // ─── Catálogo obrigatório (WIZ-03: catalog_required vindo do backend em atributos()) ───
    const [catalogRequired, setCatalogRequired] = useState(false);

    // ─── Variações (WIZ-04) ───
    const [variacoes, setVariacoes]       = useState([]);
    // Grades de tamanho (WIZ-06) — populadas quando categoria de moda com exigeGrade=true
    const [grades, setGrades]             = useState([]);
    const [sizeGridId, setSizeGridId]     = useState('');
    // domain_id da categoria — usado para buscar as grades de moda
    const [domainId, setDomainId]         = useState('');
    // Guarda User Products (UP deferido P3): se true, variações são bloqueadas com aviso.
    // A detecção efetiva virá de um sinal do backend (family_name obrigatório em /items/validate
    // ou na criação do rascunho). Por ora o flag é falso e pode ser ligado manualmente
    // quando a conta retornar esse sinal. Documentado como UP-VAR no STATE.md.
    const [contaUserProducts, setContaUserProducts] = useState(false);

    const [candidatos, setCandidatos] = useState([]);
    const [tipos, setTipos]           = useState([]);
    const [erros, setErros]           = useState(null);   // {valido, erros:[]}
    const [busy, setBusy]             = useState('');      // '', 'prever', 'attrs', 'salvar', 'validar', 'publicar', 'criar', 'grade'

    const [flash, setFlash]           = useState('');

    // UX-01: estado do autosave — separado do flash para não poluir o toast
    const [autoSaveStatus, setAutoSaveStatus] = useState(''); // '' | 'salvando' | 'salvo'
    // Ref para limpar o timer de "Salvo" auto-desaparecer após 2s
    const autoSaveTimerRef = useRef(null);

    // SEL-05: modal de confirmação — null=fechado, true=aguardando confirmação
    const [confirmPublicar, setConfirmPublicar] = useState(false);

    // DUP-02: modal de confirmação do fluxo Clássico+Premium em 1 clique
    const [confirmPublicarDuplo, setConfirmPublicarDuplo] = useState(false);

    // ─── BULK-01: seleção múltipla para publicação em lote ───
    const [selecionados, setSelecionados]   = useState(() => new Set()); // ids marcados
    const [publicandoLote, setPublicandoLote] = useState(false);         // trava o botão pós-dispatch
    const [errosLote, setErrosLote]         = useState(null);            // erros do POST do lote

    // Tipos de anúncio (uma vez). O ML devolve tipos legados (Ouro, Prata, Bronze,
    // Grátis, Diamante) que não usamos — só Clássico (gold_special) e Premium
    // (gold_pro) existem de fato para nós. Filtra para não confundir quem publica.
    useEffect(() => {
        const TIERS_VALIDOS = ['gold_special', 'gold_pro'];
        window.axios.get(route('mlb.anuncios.meta.tipos'))
            .then(r => setTipos((Array.isArray(r.data) ? r.data : []).filter(t => TIERS_VALIDOS.includes(t.id))))
            .catch(() => {});
    }, []);

    // ─── SHIP-02: cotação automática de frete ao ter os 4 SELLER_PACKAGE_* + preço ───
    // Dispara com debounce de 800ms ao mudar peso/dimensões/preço/tipo.
    // Falha silenciosa: .catch vazio — nunca bloqueia a publicação.
    useEffect(() => {
        const dimOk = pesoG && comprimentoCm && larguraCm && alturaCm;
        if (!dimOk || !preco || !rascunhoId) return;

        const t = setTimeout(() => {
            window.axios.get(
                route('mlb.anuncios.rascunho.frete', { rascunho: rascunhoId }),
                {
                    params: {
                        peso_g:          pesoG,
                        altura_cm:       alturaCm,
                        largura_cm:      larguraCm,
                        comprimento_cm:  comprimentoCm,
                        item_price:      preco,
                        listing_type_id: tipoAnuncio,
                    },
                },
            )
                .then(r => {
                    const est = r.data?.estimativa_frete;
                    if (est != null) setFreteExterno(String(est));
                })
                .catch(() => { /* cotação falhou silenciosamente — publicação não bloqueada (SHIP-02) */ });
        }, 800);

        return () => clearTimeout(t);
    }, [pesoG, comprimentoCm, larguraCm, alturaCm, preco, tipoAnuncio, rascunhoId]);

    // ─── Hidrata o formulário a partir de um rascunho pré-preenchido (DRAFT-01) ───
    // Lê rascunho.payload e popula os estados existentes.
    // parseFloat ignora sufixos ' g' / ' cm' nativamente — sem regex.
    const hidratarDoRascunho = (rascunho) => {
        const payload = rascunho.payload ?? {};

        // Campos básicos
        if (payload.title != null)              setTitulo(payload.title);
        if (payload.price != null)              setPreco(String(payload.price));
        if (payload.available_quantity != null) setEstoque(String(payload.available_quantity));
        if (payload.description != null)        setDescricao(payload.description);

        // Extrai SELLER_PACKAGE_* de payload.attributes → states individuais de dimensão
        const attrs = Array.isArray(payload.attributes) ? payload.attributes : [];
        attrs.forEach(attr => {
            const v = parseFloat(attr.value_name); // parseFloat ignora sufixo ' g' / ' cm'
            if (isNaN(v)) return;
            if (attr.id === 'SELLER_PACKAGE_WEIGHT') setPesoG(String(v));
            if (attr.id === 'SELLER_PACKAGE_HEIGHT') setAlturaCm(String(v));
            if (attr.id === 'SELLER_PACKAGE_WIDTH')  setLarguraCm(String(v));
            if (attr.id === 'SELLER_PACKAGE_LENGTH') setComprimentoCm(String(v));
        });

        // SHIP: restaura modalidade de frete e frete grátis do payload salvo
        if (payload.shipping && typeof payload.shipping === 'object') {
            if (payload.shipping.mode) setShippingMode(payload.shipping.mode);
            setFreteGratis(payload.shipping.free_shipping === true);
        }

        // Popula origemCampos a partir de meta_campos gravado pelo backend
        if (payload.meta_campos && typeof payload.meta_campos === 'object') {
            setOrigemCampos({ ...payload.meta_campos });
        }
    };

    // ─── Abre um rascunho da lista para edição no wizard ───
    // Restaura título, categoria (+ atributos da categoria), atributos do item,
    // preço, estoque, dimensões, descrição, tipo e variações. rascunhoId passa a
    // ser o do rascunho aberto — os saves seguintes ATUALIZAM ele (não criam novo).
    const abrirRascunho = async (r) => {
        const payload = r.payload ?? {};
        limparFormulario();
        setRascunhoId(r.id);
        hidratarDoRascunho(r);

        // Tipo de anúncio (Clássico/Premium)
        if (payload.listing_type_id) setTipoAnuncio(payload.listing_type_id);

        // Restaura os atributos do ITEM em `valores` (exceto pacote/grade)
        const attrs = Array.isArray(payload.attributes) ? payload.attributes : [];
        const vals = {};
        attrs.forEach(a => {
            const id = String(a.id);
            if (id.startsWith('SELLER_PACKAGE_') || id.includes('GRID')) return;
            vals[id] = a.value_id ? { value_id: a.value_id } : { value_name: a.value_name };
        });
        setValores(vals);

        // Restaura variações (o que dá — attribute_combinations, GTIN/SKU, estoque, fotos)
        if (Array.isArray(payload.variations) && payload.variations.length > 0) {
            setVariacoes(payload.variations.map(v => ({
                attribute_combinations: v.attribute_combinations ?? [],
                attributes:             v.attributes ?? [],
                available_quantity:     v.available_quantity != null ? String(v.available_quantity) : '',
                size_grid_row_id:       '',
                picture_ids:            v.picture_ids ?? [],
                picture_urls:           [],
            })));
        }

        // Restaura categoria + atributos do catálogo (para render dinâmico da Ficha técnica/Variações)
        if (r.category_id) {
            setCategoryId(r.category_id);
            setBusy('attrs');
            try {
                const resp = await window.axios.get(route('mlb.anuncios.meta.atributos', { categoryId: r.category_id }));
                setCategoria(resp.data.categoria ?? null);
                setAtributos(Array.isArray(resp.data.atributos) ? resp.data.atributos : []);
                setCatalogRequired(resp.data.catalog_required === true);
            } catch { /* segue sem atributos */ } finally { setBusy(''); }
        }

        setEtapa(0);
        setErros(null);
        setFlash('Rascunho aberto para edição.');
    };

    // ─── HIST-86-2: abre sozinho o rascunho vindo do "Anunciar semelhante" ───
    // O histórico clona o anúncio (duplicar-template) e manda ?rascunho=N. Sem o
    // parâmetro, `abrirRascunhoId` é null e o efeito sai na primeira linha.
    // Fica DEPOIS de `abrirRascunho` de propósito: `const` não sofre hoisting, e
    // referenciá-la antes daria TDZ — o mesmo erro que já deixou este wizard com
    // tela preta uma vez (commit 83d4a70).
    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect(() => {
        if (!abrirRascunhoId) return;
        const alvo = (rascunhos ?? []).find((r) => r.id === abrirRascunhoId);
        if (alvo) abrirRascunho(alvo);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [abrirRascunhoId]);

    // ─── Exclui um rascunho da lista (não remove o anúncio no ML, só a cópia local) ───
    const excluirRascunho = async (r) => {
        if (!window.confirm(`Excluir este rascunho${r.titulo ? ` (“${r.titulo}”)` : ''}? Isso não remove o anúncio já publicado no Mercado Livre.`)) return;
        try {
            await window.axios.delete(route('mlb.anuncios.rascunho.destroy', { rascunho: r.id }));
            if (rascunhoId === r.id) limparFormulario();
            router.reload({ only: ['rascunhos'] });
        } catch {
            setFlash('Não foi possível excluir o rascunho.');
        }
    };

    // ─── Marca um campo como 'publicador' quando editado (DRAFT-04) ───
    // Só age se o campo já estava mapeado como 'cliente' — não polui campos livres.
    const marcarEditado = (campo) => {
        setOrigemCampos(o => {
            if (!o[campo]) return o; // campo não vem do cliente → sem badge
            return { ...o, [campo]: 'publicador' };
        });
    };

    // ─── Limpa o formulário para escolher outro produto ───
    const limparFormulario = () => {
        setRascunhoId(null);
        setTitulo('');
        setCategoryId('');
        setCategoria(null);
        setAtributos([]);
        setValores({});
        setPreco('');
        setEstoque('1');
        setCondicao('new');
        setTipoAnuncio('gold_special');
        setImagemUrl('');
        setGarantia('30 dias');
        setDescricao('');
        setShippingMode('me2');
        setFreteGratis(false);
        setPesoG('');
        setComprimentoCm('');
        setLarguraCm('');
        setAlturaCm('');
        setOrigemCampos({});
        setCandidatos([]);
        setErros(null);
        setFlash('');
        // WIZ-03: resetar flag de catálogo ao limpar
        setCatalogRequired(false);
        // WIZ-01: voltar à primeira etapa ao limpar
        setEtapa(0);
        // WIZ-04/06: resetar variações, grades e grade selecionada ao limpar
        setVariacoes([]);
        setGrades([]);
        setSizeGridId('');
        setDomainId('');
    };

    // ─── Cria rascunho pré-preenchido a partir de um produto do cliente (DRAFT-01) ───
    const criarRascunhoProduto = async (produto) => {
        setBusy('criar');
        setFlash('');
        try {
            const r = await window.axios.post(
                route('mlb.anuncios.rascunho.por-produto', { company: empresa.id }),
                { sku: produto.sku },
            );
            hidratarDoRascunho(r.data.rascunho);
            setRascunhoId(r.data.rascunho.id);
            if (r.data.preco_indisponivel) {
                setFlash('Rascunho criado — preço não calculado (custo ausente na precificação do cliente).');
            } else {
                setFlash('Rascunho criado com dados do cliente.');
            }
        } catch (e) {
            if (e.response?.status === 422) {
                setFlash('Produto não encontrado na precificação do cliente.');
            } else {
                setFlash('Erro ao criar rascunho — tente novamente.');
            }
        } finally {
            setBusy('');
        }
    };

    // ─── Preditor de categoria ───
    const preverCategoria = async () => {
        if (!titulo.trim()) return;
        setBusy('prever');
        try {
            const r = await window.axios.get(route('mlb.anuncios.meta.prever'), { params: { q: titulo } });
            setCandidatos(Array.isArray(r.data) ? r.data : []);
        } finally { setBusy(''); }
    };

    // ─── Escolher categoria → carrega atributos ───
    // WIZ-03: salva catalog_required retornado pelo backend de atributos()
    // WIZ-04/06: salva domain_id e reseta variações/grades ao trocar de categoria
    const escolherCategoria = async (cat) => {
        setCategoryId(cat.category_id);
        setCandidatos([]);
        setValores({});
        // WIZ-03: resetar flag ao trocar de categoria
        setCatalogRequired(false);
        // WIZ-04: resetar variações ao trocar de categoria (combinações mudam por categoria)
        setVariacoes([]);
        // WIZ-06: salvar domain_id do candidato para buscar grades depois
        setDomainId(cat.domain_id ?? '');
        setGrades([]);
        setSizeGridId('');
        setBusy('attrs');
        try {
            const r = await window.axios.get(route('mlb.anuncios.meta.atributos', { categoryId: cat.category_id }));
            setCategoria(r.data.categoria ?? null);
            setAtributos(Array.isArray(r.data.atributos) ? r.data.atributos : []);
            // WIZ-03: flag de catálogo obrigatório vinda do backend (77-01)
            setCatalogRequired(r.data.catalog_required === true);
        } finally { setBusy(''); }
    };

    // Só os atributos obrigatórios (o essencial do MVP), sem grade de moda
    // Ficha técnica = atributos fixos obrigatórios (Marca, Modelo, material…).
    // EXCLUI os que aceitam variação (Cor, Tamanho): esses vão na etapa de Variações,
    // igual ao fluxo do próprio Mercado Livre (não duplicar aqui).
    const obrigatorios = useMemo(
        () => atributos.filter(a =>
            a.tags?.required
            && !a.tags?.allow_variations
            && a.id !== 'SIZE_GRID_ID'
            && !String(a.id).includes('GRID')
        ),
        [atributos],
    );
    const exigeGrade = useMemo(
        () => atributos.some(a => a.tags?.required && (a.id === 'SIZE_GRID_ID' || String(a.id).includes('GRID'))),
        [atributos],
    );

    // ─── Atributos que aceitam variações (WIZ-04): filtro do catálogo da categoria ───
    // allow_variations = true → atributo entra em attribute_combinations de cada variação
    const atributosVar = useMemo(
        () => atributos.filter(a => a.tags?.allow_variations),
        [atributos],
    );
    // Categoria aceita variações quando existe ao menos um atributo allow_variations
    const temVariacoes = useMemo(() => atributosVar.length > 0, [atributosVar]);

    // ─── Características secundárias: atributos OPCIONAIS/complementares ───
    // Espelha a seção do próprio Mercado Livre ("Inclua mais informações sobre seu
    // produto para complementar as características principais"). São tudo que NÃO é:
    //   • obrigatório (já na Ficha técnica)      • de variação (allow_variations → etapa Variações)
    //   • grade de moda (SIZE_GRID_ID/*GRID*)    • oculto/somente-leitura (não editável)
    //   • catálogo (CATALOG_PRODUCT_ID — desligado por decisão do negócio)
    //   • GTIN/SELLER_SKU (tratados no editor de variações, campo dedicado)
    const opcionais = useMemo(
        () => atributos.filter(a =>
            !a.tags?.required
            && !a.tags?.allow_variations
            && !a.tags?.hidden
            && !a.tags?.read_only
            && a.id !== 'SIZE_GRID_ID'
            && !String(a.id).includes('GRID')
            && a.id !== 'CATALOG_PRODUCT_ID'
            && a.id !== 'GTIN'
            && a.id !== 'SELLER_SKU'
        ),
        [atributos],
    );
    // Quantos opcionais já foram preenchidos (contador no cabeçalho da seção)
    const opcionaisPreenchidos = useMemo(
        () => opcionais.filter(a => valores[a.id]?.value_id || valores[a.id]?.value_name).length,
        [opcionais, valores],
    );

    // ─── WIZ-03: catálogo preenchido quando CATALOG_PRODUCT_ID tiver value_id ou value_name ───
    const catalogPreenchido = useMemo(
        () => Boolean(valores['CATALOG_PRODUCT_ID']?.value_id || valores['CATALOG_PRODUCT_ID']?.value_name),
        [valores],
    );

    // WIZ-03: publicar fica bloqueado quando catálogo é obrigatório e não foi informado
    // DECISÃO DO NEGÓCIO: nunca publicamos vinculado ao catálogo do ML — sempre anúncio
    // normal. A tag `catalog_required` em atributos NÃO significa que a categoria exige
    // catálogo (só que o atributo é necessário SE fosse catálogo). Portanto NÃO bloqueamos.
    const bloqueadoPorCatalogo = false;

    // ─── WIZ-06: busca grades quando entra na etapa de variações com exigeGrade=true ───
    // Requer que o rascunho exista (para resolver o token da empresa via rota autenticada).
    // Dispara apenas quando: etapa=2 + exigeGrade + rascunhoId disponível + grades ainda vazias.
    // Se grades vier vazio, o VariacaoEditor exibe aviso pt-BR (não bloqueia o fluxo).
    // NOTA: precisa ficar DEPOIS do useMemo `exigeGrade` (senão TDZ = tela preta na montagem).
    useEffect(() => {
        if (etapa !== 2 || !exigeGrade || !rascunhoId || grades.length > 0) return;
        setBusy('grade');
        window.axios.get(
            route('mlb.anuncios.rascunho.grades', { rascunho: rascunhoId }),
            { params: domainId ? { domain_id: domainId } : {} },
        )
            .then(r => setGrades(Array.isArray(r.data.grades) ? r.data.grades : []))
            .catch(() => { /* grade indisponível: aviso no VariacaoEditor */ })
            .finally(() => setBusy(b => b === 'grade' ? '' : b));
    }, [etapa, exigeGrade, rascunhoId, domainId]);

    // ─── BULK-01: rascunhos que podem ser (re)enviados — exclui publicando/publicado ───
    const rascunhosSelecionaveis = useMemo(
        () => rascunhos.filter(r => r.status !== 'publicando' && r.status !== 'publicado'),
        [rascunhos],
    );

    // ─── BULK-01: marcar/desmarcar um rascunho individual ───
    const toggleSelecionado = (id) => {
        setSelecionados(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    // ─── BULK-01: marcar/desmarcar todos os selecionáveis de uma vez ───
    const toggleTodos = () => {
        setSelecionados(prev =>
            prev.size === rascunhosSelecionaveis.length
                ? new Set()
                : new Set(rascunhosSelecionaveis.map(r => r.id)),
        );
    };

    // ─── BULK-01/04: dispara o lote via POST e inicia o ciclo de poll ───
    const publicarLote = async () => {
        setPublicandoLote(true);
        setErrosLote(null);
        try {
            await window.axios.post(
                route('mlb.anuncios.publicar-lote', { company: empresa.id }),
                { company_id: empresa.id, rascunho_ids: [...selecionados] },
            );
            // Limpa seleção; o status real de cada rascunho vem no próximo poll (BULK-04)
            setSelecionados(new Set());
            router.reload({ only: ['rascunhos'] });
        } catch (err) {
            setErrosLote(err.response?.data?.erros ?? [{ mensagem: 'Erro ao enfileirar publicação.' }]);
        } finally {
            setPublicandoLote(false);
        }
    };

    // ─── UX-03: duplica um anúncio publicado como ponto de partida (rota criada em 81-01) ───
    // Cria novo rascunho a partir do publicado (zera ml_item_ids, status rascunho) e hidrata o wizard.
    const usarComoTemplate = async (r) => {
        setBusy('template');
        try {
            const resp = await window.axios.post(
                route('mlb.anuncios.rascunho.duplicar-template', { rascunho: r.id }),
            );
            const novo = resp.data.rascunho;
            hidratarDoRascunho(novo);
            setRascunhoId(novo.id);
            setFlash('Template criado a partir do anúncio publicado — edite o que precisar.');
        } finally {
            setBusy('');
        }
    };

    // ─── BULK-04: recarrega só a prop rascunhos enquanto há job em andamento ───
    // Sem endpoint novo — usa router.reload({only}) p/ economizar payload (BULK-04).
    useEffect(() => {
        const temPublicando = rascunhos.some(r => r.status === 'publicando');
        if (!temPublicando) return;
        const id = setInterval(() => router.reload({ only: ['rascunhos'] }), 3000);
        return () => clearInterval(id);
    }, [rascunhos]);

    // ─── UX-01: autosave com debounce 800ms ───
    // Dispara salvar() automaticamente ao mudar qualquer campo relevante do formulário.
    // Guarda 1: só autossalva quando já existe rascunhoId (evita criar rascunho de form vazio).
    // Guarda 2: busy lido via ref (não como dep) para evitar loop — muda busy sem re-triggar o effect.
    // Guarda 3: NÃO dispara se busy é 'publicar'/'publicarDuplo' (publicação já salva internamente).
    // Padrão idêntico ao SHIP-02 (cotação de frete): setTimeout + clearTimeout no cleanup.
    // busyRef: espelho de busy como ref para leitura não-reativa dentro do callback do setTimeout.
    const busyRef = useRef(busy);
    useEffect(() => { busyRef.current = busy; }, [busy]);

    useEffect(() => {
        // Guarda 1: só autossalva com rascunho existente
        if (!rascunhoId) return;

        const t = setTimeout(async () => {
            // Guarda 2 (lida via ref para não re-triggar o effect ao mudar busy):
            // não duplica save já em andamento nem interfere com publicação
            const b = busyRef.current;
            if (b === 'salvar' || b === 'publicar' || b === 'publicarDuplo' || b === 'criar') return;

            setAutoSaveStatus('salvando');
            try {
                await salvar();
                setAutoSaveStatus('salvo');
                // Limpa o indicador "Salvo" após 2 segundos
                clearTimeout(autoSaveTimerRef.current);
                autoSaveTimerRef.current = setTimeout(() => setAutoSaveStatus(''), 2000);
            } catch {
                // Falha silenciosa: o publicador ainda pode salvar manualmente
                setAutoSaveStatus('');
            }
        }, 800);

        return () => clearTimeout(t);
    }, [
        // Campos que montarPayload() consome — mudar qualquer um dispara o autosave.
        // busy NÃO está aqui (lido via busyRef para evitar loop de re-trigger pós-save).
        titulo, preco, estoque, descricao, condicao, tipoAnuncio,
        imagemUrl, garantia, categoryId, valores, variacoes,
        pesoG, comprimentoCm, larguraCm, alturaCm,
        shippingMode, freteGratis,
        rascunhoId,
    ]);

    // ─── UX-04: atalhos de teclado no wizard ───
    // NÃO interceptar Tab (acessibilidade — Tab nativo move foco entre inputs).
    // Navegação por setas: ArrowRight avança, ArrowLeft volta (sem salvar explícito).
    // Guarda contra INPUT/TEXTAREA para não conflitar com digitação.
    // Dependências incluem etapa/busy para evitar stale closure.
    useEffect(() => {
        const onKey = (e) => {
            // Guarda: ignora quando o foco está num campo de texto
            const el = document.activeElement;
            if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable)) return;

            // Ctrl+S → salvar manual
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (!busy) salvar();
                return;
            }

            // Ctrl+Shift+Enter → publicar Clássico+Premium (verificar antes de Ctrl+Enter simples)
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'Enter') {
                e.preventDefault();
                if (etapa === ETAPAS.length - 1 && !busy && categoryId && !bloqueadoPorCatalogo) {
                    setConfirmPublicarDuplo(true);
                }
                return;
            }

            // Ctrl+Enter → publicar (só na última etapa, se habilitado)
            if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key === 'Enter') {
                e.preventDefault();
                if (etapa === ETAPAS.length - 1 && !busy && categoryId && !bloqueadoPorCatalogo) {
                    setConfirmPublicar(true);
                }
                return;
            }

            // ArrowRight → avançar etapa (não interceptar na última etapa)
            if (e.key === 'ArrowRight' && etapa < ETAPAS.length - 1 && !busy) {
                e.preventDefault();
                avancarEtapa();
                return;
            }

            // ArrowLeft → voltar etapa (não interceptar na primeira etapa)
            if (e.key === 'ArrowLeft' && etapa > 0) {
                e.preventDefault();
                voltarEtapa();
            }
        };

        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [etapa, busy, categoryId, bloqueadoPorCatalogo]);

    const setValor = (id, patch) => setValores(v => ({ ...v, [id]: { ...v[id], ...patch } }));

    // ─── Monta o payload no shape que o backend (ItemBuilder) espera ───
    const montarPayload = () => {
        // Mapa id → definição do atributo (value_type, default_unit) para normalizar unidades
        const defAttr = {};
        (atributos || []).forEach(a => { defAttr[a.id] = a; });

        // Atributos number_unit (ex.: WIDTH/HEIGHT/DEPTH) exigem valor COM unidade no ML
        // ("100 cm", não "100"). Se o publicador digitou só o número, anexa a unidade padrão.
        const normalizarValor = (id, valueName) => {
            const def = defAttr[id];
            const bruto = String(valueName).trim();
            if (def?.value_type === 'number_unit' && /^[\d.,]+$/.test(bruto)) {
                const unidade = def.default_unit || 'cm';
                return `${bruto} ${unidade}`;
            }
            return bruto;
        };

        const attributes = Object.entries(valores)
            .filter(([, v]) => v && (v.value_id || v.value_name))
            .map(([id, v]) => (v.value_id
                ? { id, value_id: v.value_id }
                : { id, value_name: normalizarValor(id, v.value_name) }));

        // Peso e dimensões do pacote viram atributos SELLER_PACKAGE_* (habilitam o me2)
        const pacote = [];
        if (pesoG)         pacote.push({ id: 'SELLER_PACKAGE_WEIGHT', value_name: `${pesoG} g` });
        if (alturaCm)      pacote.push({ id: 'SELLER_PACKAGE_HEIGHT', value_name: `${alturaCm} cm` });
        if (comprimentoCm) pacote.push({ id: 'SELLER_PACKAGE_LENGTH', value_name: `${comprimentoCm} cm` });
        if (larguraCm)     pacote.push({ id: 'SELLER_PACKAGE_WIDTH',  value_name: `${larguraCm} cm` });

        // WIZ-06 (moda): SIZE_GRID_ID vai nos attributes do item raiz quando selecionado
        if (exigeGrade && sizeGridId) {
            pacote.push({ id: 'SIZE_GRID_ID', value_name: sizeGridId });
        }

        // ─── WIZ-04: variações no payload ───
        // Regra STACK.md: quando há variações, available_quantity=0 no item raiz.
        // Cada variação traz: attribute_combinations (allow_variations), attributes (GTIN/SKU),
        // picture_ids, price e available_quantity individuais.
        // SIZE_GRID_ROW_ID por variação entra em attributes da variação quando exigeGrade.
        const temVarsNoPayload = temVariacoes && variacoes.length > 0;

        const variacoesPayload = temVarsNoPayload
            ? variacoes.map(v => {
                // Filtra attribute_combinations sem valor
                const combValidas = (v.attribute_combinations ?? []).filter(
                    ac => ac.value_id || ac.value_name,
                );
                // Filtra attributes simples (GTIN/SKU) sem valor
                const attrsSimples = (v.attributes ?? []).filter(
                    a => a.value_name?.trim(),
                );
                // SIZE_GRID_ROW_ID por variação (moda) — campo livre informado pelo publicador
                const attrsVar = exigeGrade && v.size_grid_row_id?.trim()
                    ? [...attrsSimples, { id: 'SIZE_GRID_ROW_ID', value_name: v.size_grid_row_id.trim() }]
                    : attrsSimples;

                return {
                    // Preço por variação = preço do item (todas iguais). O ML EXIGE `price`
                    // dentro de cada variação em várias categorias/contas (erro 369
                    // body.required_fields quando falta). Como todas herdam o MESMO preço do
                    // item, não dispara o erro 357 (item.variations.price.different).
                    price:               preco ? Number(preco) : null,
                    available_quantity:  v.available_quantity !== '' ? Number(v.available_quantity) : 1,
                    attribute_combinations: combValidas,
                    attributes:          attrsVar,
                    picture_ids:         v.picture_ids ?? [],
                };
            })
            : undefined;

        // ML erro 146 (item.attributes.invalid): um mesmo atributo NÃO pode estar em
        // item.attributes E nas variações ao mesmo tempo. Quando há variações, removemos
        // do item-raiz os atributos já usados nelas (attribute_combinations = Cor/Tamanho;
        // attributes = GTIN/SKU). SELLER_PACKAGE_* e SIZE_GRID_ID ficam (não vão na variação).
        let attributesItem = [...attributes, ...pacote];
        if (temVarsNoPayload && variacoesPayload) {
            const idsNasVariacoes = new Set();
            variacoesPayload.forEach(v => {
                (v.attribute_combinations ?? []).forEach(ac => ac.id && idsNasVariacoes.add(ac.id));
                (v.attributes ?? []).forEach(a => a.id && idsNasVariacoes.add(a.id));
            });
            attributesItem = attributesItem.filter(a => !idsNasVariacoes.has(a.id));
        }

        return {
            title: titulo,
            category_id: categoryId,
            price: preco ? Number(preco) : null,
            currency_id: 'BRL',
            // Regra ML: available_quantity=0 no raiz quando há variações (estoque fica por variação)
            available_quantity: temVarsNoPayload ? 0 : (estoque ? Number(estoque) : null),
            condition: condicao,
            listing_type_id: tipoAnuncio,
            attributes: attributesItem,
            pictures: imagemUrl ? [{ source: imagemUrl }] : [],
            sale_terms: garantia
                ? [{ id: 'WARRANTY_TYPE', value_name: 'Garantia do vendedor' }, { id: 'WARRANTY_TIME', value_name: garantia }]
                : [],
            // SHIP: modalidade escolhida + frete grátis. free_methods vazio deixa o ML
            // aplicar o método padrão quando o frete grátis está ligado (só faz sentido no ME2).
            shipping: (() => {
                const s = { mode: shippingMode, local_pick_up: false, free_shipping: !!freteGratis };
                if (freteGratis && shippingMode === 'me2') s.free_methods = [];
                return s;
            })(),
            description: descricao,
            // variations incluso apenas quando existem variações montadas
            ...(variacoesPayload !== undefined ? { variations: variacoesPayload } : {}),
        };
    };

    // ─── Salva/atualiza o rascunho ───
    const salvar = async () => {
        setBusy('salvar');
        try {
            const payload = montarPayload();
            let id = rascunhoId;
            if (!id) {
                // Envia company_id (âncora = empresa com conta ML) — fixada na criação
                const r = await window.axios.post(route('mlb.anuncios.rascunho.store'), {
                    company_id: empresa.id, category_id: categoryId || null, payload,
                });
                id = r.data.rascunho.id;
                setRascunhoId(id);
            } else {
                // SEL-03: update não envia empresa — company_id e mlb_empresa_id são imutáveis
                await window.axios.put(route('mlb.anuncios.rascunho.update', { rascunho: id }), {
                    category_id: categoryId || null, payload,
                });
            }
            setFlash('Rascunho salvo.');
            return id;
        } finally { setBusy(''); }
    };

    // ─── Avançar etapa (salva antes de prosseguir) ───
    const avancarEtapa = async () => {
        await salvar();
        setEtapa(e => Math.min(e + 1, ETAPAS.length - 1));
    };

    // ─── Voltar etapa ───
    const voltarEtapa = () => setEtapa(e => Math.max(e - 1, 0));

    // ─── Valida no ML (tempo real) ───
    const validar = async () => {
        // WIZ-07: validação própria ANTES do /items/validate — pega o que o ML não pega
        if (temVariacoes && variacoes.length > 0) {
            const locais = validarVariacoesLocalmente(variacoes);
            if (locais.length > 0) {
                setErros({ valido: false, erros: locais });
                setFlash('Corrija as variações antes de validar.');
                return;
            }
        }
        const id = await salvar();
        if (!id) return;
        setBusy('validar');
        try {
            const r = await window.axios.post(route('mlb.anuncios.validar', { rascunho: id }));
            setErros(r.data);
            setFlash(r.data.valido ? 'Anúncio válido — pronto para publicar.' : 'Há pendências antes de publicar.');
        } finally { setBusy(''); }
    };

    // ─── Publica de verdade (chamado após confirmação no modal) ───
    // Guarda de campos-base: o ML exige category_id + (preço/estoque quando NÃO há variações).
    // Sem eles o ItemBuilder descarta os campos null e o ML devolve "body.required_fields".
    // Bloqueamos ANTES de tocar o ML, com mensagem clara do que falta.
    const camposBaseFaltando = () => {
        const pl = montarPayload();
        const temVars = !!(pl.variations && pl.variations.length);
        const faltando = [];
        if (!pl.category_id) faltando.push('categoria');
        if (!temVars) {
            if (pl.price == null) faltando.push('preço');
            if (pl.available_quantity == null) faltando.push('estoque');
        }
        return faltando;
    };

    const publicar = async () => {
        // WIZ-07: validação própria ANTES de publicar — mesma guarda que validar()
        if (temVariacoes && variacoes.length > 0) {
            const locais = validarVariacoesLocalmente(variacoes);
            if (locais.length > 0) {
                setErros({ valido: false, erros: locais });
                setFlash('Corrija as variações antes de publicar.');
                return;
            }
        }
        // Guarda de campos-base + guard-rail: bloqueia o que o ML rejeitaria com
        // certeza (foto, obrigatórios da ficha, preço fora da faixa, condição,
        // categoria) ANTES de gastar a chamada à API. Avisos NÃO bloqueiam.
        const faltando = camposBaseFaltando();
        const bloqueios = [
            ...faltando.map(f => ({ campo: f, mensagem: 'obrigatório para publicar' })),
            ...analise.erros,
        ];
        if (bloqueios.length > 0) {
            setErros({ valido: false, erros: bloqueios });
            setFlash('Corrija os itens obrigatórios antes de publicar (veja ao lado).');
            return;
        }
        const id = await salvar();
        if (!id) return;
        setBusy('publicar');
        try {
            const r = await window.axios.post(route('mlb.anuncios.publicar', { rascunho: id }));
            if (r.data.ok) {
                setErros({ valido: true, erros: [] });
                setFlash(`Publicado! Anúncio ${r.data.ml_item_id || ''} criado na conta do cliente.`);
            } else {
                setErros({ valido: false, erros: r.data.erros ?? [] });
                setFlash('Não foi possível publicar — veja as pendências ao lado.');
            }
        } catch (e) {
            if (e.response?.status === 422) { setErros({ valido: false, erros: e.response.data.erros ?? [] }); setFlash('Não foi possível publicar — veja as pendências ao lado.'); }
            else { setFlash('Falha ao publicar — tente novamente.'); }
        } finally { setBusy(''); }
    };

    // SEL-05: confirmar publicação no modal → fecha o modal e chama publicar()
    const confirmarPublicacao = () => {
        setConfirmPublicar(false);
        publicar();
    };

    // DUP-02: publica Clássico + Premium em 1 clique via rota publicar-duplo
    // Segue a mesma guarda de validação de variações que publicar() usa (WIZ-07).
    const publicarDuplo = async () => {
        // WIZ-07: validação própria ANTES de publicar — mesma guarda que publicar()
        if (temVariacoes && variacoes.length > 0) {
            const locais = validarVariacoesLocalmente(variacoes);
            if (locais.length > 0) {
                setErros({ valido: false, erros: locais });
                setFlash('Corrija as variações antes de publicar.');
                return;
            }
        }
        // Guarda de campos-base + guard-rail: bloqueia o que o ML rejeitaria com
        // certeza (foto, obrigatórios da ficha, preço fora da faixa, condição,
        // categoria) ANTES de gastar a chamada à API. Avisos NÃO bloqueiam.
        const faltando = camposBaseFaltando();
        const bloqueios = [
            ...faltando.map(f => ({ campo: f, mensagem: 'obrigatório para publicar' })),
            ...analise.erros,
        ];
        if (bloqueios.length > 0) {
            setErros({ valido: false, erros: bloqueios });
            setFlash('Corrija os itens obrigatórios antes de publicar (veja ao lado).');
            return;
        }
        const id = await salvar();
        if (!id) return;
        // DUP-02: busy='publicarDuplo' (reutiliza o estado busy string existente)
        setBusy('publicarDuplo');
        try {
            const r = await window.axios.post(route('mlb.anuncios.publicar-duplo', { rascunho: id }));
            const { classico, premium } = r.data;

            // DUP-03: ambos publicaram com sucesso — confirma ids distintos
            if (classico?.ok && premium?.ok) {
                setErros({ valido: true, erros: [] });
                setFlash(
                    `Publicado! Clássico ${classico.ml_item_id} e Premium ${premium.ml_item_id} criados na conta do cliente.`,
                );
            } else {
                // DUP-04: falha parcial ou total — combina pendências de cada tier que falhou
                const errosCombinados = [];
                if (!classico?.ok) {
                    (classico?.erros ?? []).forEach(e => errosCombinados.push({ ...e, campo: `[Clássico] ${e.campo ?? ''}`.trim() }));
                }
                if (!premium?.ok) {
                    (premium?.erros ?? []).forEach(e => errosCombinados.push({ ...e, campo: `[Premium] ${e.campo ?? ''}`.trim() }));
                }
                setErros({ valido: false, erros: errosCombinados });

                // Mensagem diferenciada: parcial (um ok) vs. total (nenhum ok)
                const algumOk = classico?.ok || premium?.ok;
                setFlash(
                    algumOk
                        ? 'Publicação parcial — veja as pendências ao lado.'
                        : 'Não foi possível publicar — veja as pendências ao lado.',
                );
            }
        } catch (e) {
            // DUP-04: 422 pode trazer erros estruturados de classico/premium
            if (e.response?.status === 422) {
                const data = e.response.data;
                const errosCombinados = [];
                (data?.classico?.erros ?? []).forEach(err => errosCombinados.push({ ...err, campo: `[Clássico] ${err.campo ?? ''}`.trim() }));
                (data?.premium?.erros ?? []).forEach(err => errosCombinados.push({ ...err, campo: `[Premium] ${err.campo ?? ''}`.trim() }));
                setErros({ valido: false, erros: errosCombinados.length ? errosCombinados : (data?.erros ?? []) });
                setFlash('Não foi possível publicar — veja as pendências ao lado.');
            } else {
                setFlash('Falha ao publicar — tente novamente.');
            }
        } finally {
            setBusy('');
        }
    };

    // DUP-02: confirma publicação dupla → fecha modal e chama publicarDuplo()
    const confirmarPublicacaoDupla = () => {
        setConfirmPublicarDuplo(false);
        publicarDuplo();
    };

    // ─── Título: limite máximo dinâmico por categoria (WIZ-01) ───
    const maxTitulo = categoria?.settings?.max_title_length ?? 60;

    // ─── Análise de saúde + guard-rail (regras puras em @/lib/mlAnuncioRegras) ───
    // Produz { erros (bloqueiam), avisos (não bloqueiam), ficha, score }.
    // Consumida pelo painel "Saúde do anúncio" (aside) e pela guarda de publicar().
    const settingsCat = useMemo(() => extrairSettingsCategoria(categoria), [categoria]);
    const analise = useMemo(() => analisarAnuncio({
        titulo, categoria, categoryId, preco, estoque, condicao, tipoAnuncio,
        imagemUrl, descricao, temVariacoes, variacoes,
        obrigatorios, opcionais,
        preenchido: (a) => !!(valores[a.id]?.value_id || valores[a.id]?.value_name),
        pesoG, comprimentoCm, larguraCm, alturaCm,
        shippingMode, freteGratis,
    }), [
        titulo, categoria, categoryId, preco, estoque, condicao, tipoAnuncio,
        imagemUrl, descricao, temVariacoes, variacoes, obrigatorios, opcionais, valores,
        pesoG, comprimentoCm, larguraCm, alturaCm, shippingMode, freteGratis,
    ]);

    // Guarda de rota: se empresa não foi passada, o usuário acessou o wizard diretamente
    if (!empresa) {
        return (
            <AppLayout>
                <div className="mx-auto max-w-6xl px-4 py-6">
                    <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-10 text-center text-white/40">
                        Abra o wizard a partir do painel de empresas.
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <div className="mx-auto max-w-6xl px-4 py-6">
                <header className="mb-6">
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-white">
                        <Rocket size={20} className="text-ecf-yellow" /> Anunciar no Mercado Livre
                    </h1>
                    <p className="mt-1 text-sm text-white/50">Monte o anúncio aqui e publique direto na conta do cliente — sem entrar no painel do ML.</p>

                    {/* SEL-01: empresa fixada — sempre visível no header do wizard */}
                    <div className="mt-3 flex items-center gap-2 rounded-lg border border-white/[0.08] bg-ecf-card px-4 py-2.5">
                        <Store size={15} className="shrink-0 text-white/40" />
                        <span className="text-xs text-white/50">Publicando na conta:</span>
                        <span className="text-sm font-semibold text-white">{empresa?.nome ?? '—'}</span>
                        {/* UX-01: indicador de autosave ancorado no header da empresa */}
                        {autoSaveStatus === 'salvando' && (
                            <span className="ml-auto flex items-center gap-1 text-[11px] text-white/40">
                                <Loader2 size={12} className="animate-spin" /> Salvando…
                            </span>
                        )}
                        {autoSaveStatus === 'salvo' && (
                            <span className="ml-auto flex items-center gap-1 text-[11px] text-white/40">
                                <Check size={12} /> Salvo
                            </span>
                        )}
                    </div>

                    {/* Alternância entre modo Individual (este wizard) e Em massa */}
                    <div className="mt-3">
                        <ModoAnuncioTabs empresaId={empresa.id} modo="individual" />
                    </div>
                </header>

                {flash && (
                    <div className="mb-4 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/10 px-4 py-2 text-sm text-ecf-yellow">{flash}</div>
                )}

                {/* ─── Seção "Produtos do cliente" (DRAFT-01) ───
                    Visível quando ainda não há rascunho aberto e a empresa tem produtos cadastrados.
                    Ao clicar "Criar rascunho", o rascunho é criado (76-01) e o formulário é hidratado.
                    A seção recolhe automaticamente quando rascunhoId é setado. */}
                {!rascunhoId && produtos.length > 0 && (
                    <div className="mb-6 rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                        <div className="mb-3">
                            <h2 className="text-sm font-semibold text-white">Produtos do cliente</h2>
                            <p className="mt-0.5 text-[11px] text-white/40">
                                Dados que o cliente preencheu — clique em Criar rascunho para começar já preenchido.
                            </p>
                        </div>

                        <div className="space-y-2">
                            {produtos.map((p, idx) => (
                                <div
                                    key={p.sku ?? idx}
                                    className="flex flex-wrap items-center gap-3 rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2.5"
                                >
                                    {/* SKU copiável (DRAFT-05) */}
                                    <SkuCopyChip sku={p.sku} />

                                    {/* Nome do produto */}
                                    <span className="flex-1 min-w-0 truncate text-sm text-white/80 font-medium">
                                        {p.produto ?? '—'}
                                    </span>

                                    {/* Dimensões resumidas */}
                                    {p.tem_dimensoes ? (
                                        <span className="text-[11px] text-white/40 shrink-0">
                                            {p.altura}×{p.largura}×{p.profundidade} cm · {p.peso_kg} kg
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-300/80 shrink-0">
                                            <AlertTriangle size={10} /> dimensões incompletas
                                        </span>
                                    )}

                                    {/* Estoque */}
                                    {p.estoque != null && (
                                        <span className="text-[11px] text-white/40 shrink-0">
                                            estq {p.estoque}
                                        </span>
                                    )}

                                    {/* Preço anunciado clássico */}
                                    {p.tem_preco ? (
                                        <span className="text-[11px] font-semibold text-ecf-yellow shrink-0">
                                            {fmtBRL(p.preco_anunciado_c)}
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-500/10 text-amber-300/80 shrink-0">
                                            sem preço
                                        </span>
                                    )}

                                    {/* Botão Criar rascunho */}
                                    <button
                                        type="button"
                                        onClick={() => criarRascunhoProduto(p)}
                                        disabled={busy === 'criar'}
                                        className="flex shrink-0 items-center gap-1.5 rounded-lg bg-ecf-yellow px-3 py-1.5 text-xs font-semibold text-black hover:brightness-95 disabled:opacity-40"
                                    >
                                        {busy === 'criar' ? <Loader2 size={12} className="animate-spin" /> : <Rocket size={12} />}
                                        Criar rascunho
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* ─── StepperNav (WIZ-01): renderizado acima do grid principal ─── */}
                <StepperNav etapaAtual={etapa} setEtapa={setEtapa} />

                <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
                    {/* ─── Coluna do formulário (wizard) ─── */}
                    <div className="space-y-5">
                        {/* Botão de voltar à lista de produtos (quando rascunho está aberto) */}
                        {rascunhoId && produtos.length > 0 && (
                            <button
                                type="button"
                                onClick={limparFormulario}
                                className="flex items-center gap-1.5 text-xs text-white/40 hover:text-white/70 transition mb-1"
                            >
                                <ChevronLeft size={14} /> Voltar aos produtos do cliente
                            </button>
                        )}

                        {/* ════════════════════════════════════════════════════
                            ETAPA 0 — Título e categoria (WIZ-01/02)
                            ════════════════════════════════════════════════════ */}
                        {etapa === 0 && (
                            <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                <h2 className="mb-3 text-sm font-semibold text-white">1. Título e categoria</h2>
                                <Campo
                                    label="Título do anúncio"
                                    origem={origemCampos['title']}
                                >
                                    <div className="flex gap-2">
                                        <input
                                            className={inputCls}
                                            value={titulo}
                                            maxLength={maxTitulo}
                                            onChange={e => { setTitulo(e.target.value); marcarEditado('title'); }}
                                            placeholder="Ex.: Tênis de corrida masculino leve"
                                        />
                                        <button onClick={preverCategoria} disabled={busy === 'prever' || !titulo.trim()}
                                            className="flex shrink-0 items-center gap-1 rounded-lg bg-ecf-yellow px-3 py-2 text-sm font-medium text-black disabled:opacity-40">
                                            {busy === 'prever' ? <Loader2 size={15} className="animate-spin" /> : <Search size={15} />} Categoria
                                        </button>
                                    </div>

                                    {/* WIZ-01: contador de título X/60 — fica vermelho perto do limite */}
                                    <div className="mt-1 flex justify-end">
                                        <span className={cn(
                                            'text-[11px] tabular-nums',
                                            titulo.length >= maxTitulo - 5 ? 'text-red-400' : 'text-white/30',
                                        )}>
                                            {titulo.length}/{maxTitulo}
                                        </span>
                                    </div>
                                </Campo>

                                {/* WIZ-02: breadcrumb nas sugestões de categoria (desambigua IDs parecidos) */}
                                {candidatos.length > 0 && (
                                    <div className="mt-3 space-y-1">
                                        <p className="text-[11px] text-white/40">Sugestões de categoria:</p>
                                        {candidatos.map(c => (
                                            <button key={c.category_id} onClick={() => escolherCategoria(c)}
                                                className="flex w-full flex-col gap-0.5 rounded-lg border border-white/[0.08] bg-ecf-bg px-3 py-2 text-left hover:border-ecf-yellow/40">
                                                <div className="flex items-center gap-2">
                                                    <Tag size={14} className="text-ecf-yellow/70 shrink-0" />
                                                    <span className="text-sm text-white/80">{c.category_name}</span>
                                                    <span className="ml-auto text-[11px] text-white/30">{c.category_id}</span>
                                                </div>
                                                {/* WIZ-02: exibe path quando disponível (caminho completo na árvore ML) */}
                                                {c.path && (
                                                    <p className="pl-[22px] text-[11px] text-white/30 truncate">
                                                        {c.path}
                                                    </p>
                                                )}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                {/* WIZ-02: categoria escolhida exibe breadcrumb (path_from_root) em vez de só o nome */}
                                {categoryId && (
                                    <div className="mt-3 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-3 py-2">
                                        <div className="flex items-center gap-2 text-sm text-emerald-400">
                                            <CheckCircle2 size={15} className="shrink-0" />
                                            <span>
                                                {/* breadcrumb completo (path_from_root) ou fallback para o nome */}
                                                {categoria?.path_from_root?.length > 0
                                                    ? categoria.path_from_root.map(p => p.name).join(' › ')
                                                    : (categoria?.name ?? categoryId)}
                                            </span>
                                            <span className="ml-auto text-[11px] text-white/30 shrink-0">{categoryId}</span>
                                        </div>
                                    </div>
                                )}

                                {/* WIZ-06: aviso de moda na etapa 0; a grade em si é configurada na etapa de Variações */}
                                {exigeGrade && (
                                    <p className="mt-2 flex items-center gap-1 text-[11px] text-amber-400/80">
                                        <AlertTriangle size={12} /> Categoria de moda: configure a grade de tamanho na etapa de Variações.
                                    </p>
                                )}
                            </section>
                        )}

                        {/* ════════════════════════════════════════════════════
                            ETAPA 1 — Ficha técnica / atributos (WIZ-03)
                            ════════════════════════════════════════════════════ */}
                        {etapa === 1 && (
                            <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                <h2 className="mb-3 text-sm font-semibold text-white">2. Ficha técnica (obrigatórios)</h2>

                                {/* Completude da ficha técnica + impacto na exposição (P1) — o ML usa a ficha
                                    para ranquear e exibir filtros de busca; quanto mais completa, melhor. */}
                                {categoryId && busy !== 'attrs' && (analise.ficha.obrTotal > 0 || analise.ficha.opcTotal > 0) && (
                                    <div className="mb-3 rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2.5">
                                        <div className="flex items-center gap-2 text-[12px]">
                                            <Gauge size={14} className="shrink-0 text-ecf-yellow/70" />
                                            <span className="text-white/70">
                                                Obrigatórios: <b className={analise.ficha.obrCompleta ? 'text-emerald-400' : 'text-amber-300'}>{analise.ficha.obrOk}/{analise.ficha.obrTotal}</b>
                                            </span>
                                            {analise.ficha.opcTotal > 0 && (
                                                <span className="text-white/50">
                                                    · Secundários: {analise.ficha.opcOk}/{analise.ficha.opcTotal} ({analise.ficha.opcPct}%)
                                                </span>
                                            )}
                                        </div>
                                        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-white/[0.08]">
                                            <div className="h-full rounded-full bg-ecf-yellow transition-all"
                                                style={{ width: `${analise.ficha.opcTotal ? analise.ficha.opcPct : (analise.ficha.obrCompleta ? 100 : 0)}%` }} />
                                        </div>
                                        <p className="mt-1.5 text-[11px] text-white/40">
                                            Ficha técnica completa melhora o ranqueamento e a exibição nas buscas do Mercado Livre.
                                        </p>
                                    </div>
                                )}

                                {/* Catálogo desativado por decisão do negócio — publicamos sempre como anúncio normal. */}

                                {busy === 'attrs' ? (
                                    <p className="flex items-center gap-2 text-sm text-white/50"><Loader2 size={15} className="animate-spin" /> Carregando atributos…</p>
                                ) : (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {obrigatorios.map(a => (
                                            <Campo key={a.id} label={a.name}>
                                                {(a.value_type === 'list' || a.value_type === 'boolean') && a.values?.length ? (
                                                    <select className={inputCls} value={valores[a.id]?.value_id ?? ''}
                                                        onChange={e => setValor(a.id, { value_id: e.target.value, value_name: undefined })}>
                                                        <option value="">Selecione…</option>
                                                        {a.values.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                                                    </select>
                                                ) : (
                                                    <input className={inputCls} value={valores[a.id]?.value_name ?? ''}
                                                        placeholder={a.value_type === 'number_unit' ? `Ex.: 10 ${a.default_unit ?? ''}` : ''}
                                                        onChange={e => setValor(a.id, { value_name: e.target.value, value_id: undefined })} />
                                                )}
                                            </Campo>
                                        ))}
                                        {obrigatorios.length === 0 && <p className="text-sm text-white/40">Sem atributos obrigatórios nesta categoria.</p>}
                                    </div>
                                )}

                                {/* ─── Características secundárias ───
                                    Aberta por padrão (quem publica deve preencher). Espelha a seção
                                    homônima do Mercado Livre. Reusa o mesmo Campo/select/input dos obrigatórios. */}
                                {busy !== 'attrs' && opcionais.length > 0 && (
                                    <div className="mt-4 border-t border-white/[0.06] pt-4">
                                        <button
                                            type="button"
                                            onClick={() => setSecundariasAbertas(o => !o)}
                                            className="flex w-full items-center gap-2 text-left text-sm font-medium text-white/80 transition hover:text-white"
                                        >
                                            {secundariasAbertas ? <ChevronDown size={16} className="shrink-0" /> : <ChevronRight size={16} className="shrink-0" />}
                                            <span>Características secundárias</span>
                                            {opcionaisPreenchidos > 0 && (
                                                <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] text-emerald-400">
                                                    {opcionaisPreenchidos} preenchido{opcionaisPreenchidos > 1 ? 's' : ''}
                                                </span>
                                            )}
                                            <span className="ml-auto text-[11px] text-white/30">{opcionais.length} campo{opcionais.length > 1 ? 's' : ''}</span>
                                        </button>

                                        {secundariasAbertas && (
                                            <>
                                                <p className="mt-2 mb-3 text-[12px] text-white/40">
                                                    Inclua mais informações sobre seu produto para complementar as características principais.
                                                </p>
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    {opcionais.map(a => (
                                                        <Campo key={a.id} label={a.name}>
                                                            {(a.value_type === 'list' || a.value_type === 'boolean') && a.values?.length ? (
                                                                <select className={inputCls} value={valores[a.id]?.value_id ?? ''}
                                                                    onChange={e => setValor(a.id, { value_id: e.target.value, value_name: undefined })}>
                                                                    <option value="">Selecione…</option>
                                                                    {a.values.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                                                                </select>
                                                            ) : (
                                                                <input className={inputCls} value={valores[a.id]?.value_name ?? ''}
                                                                    placeholder={a.value_type === 'number_unit' ? `Ex.: 10 ${a.default_unit ?? ''}` : ''}
                                                                    onChange={e => setValor(a.id, { value_name: e.target.value, value_id: undefined })} />
                                                            )}
                                                        </Campo>
                                                    ))}
                                                </div>
                                            </>
                                        )}
                                    </div>
                                )}
                            </section>
                        )}

                        {/* ════════════════════════════════════════════════════
                            ETAPA 2 — Variações (WIZ-04/05/06)
                            ════════════════════════════════════════════════════ */}
                        {etapa === 2 && (
                            <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                <h2 className="mb-3 text-sm font-semibold text-white">3. Variações</h2>

                                {/* Guarda User Products (deferido P3): conta com modelo UP bloqueia variações.
                                    A detecção efetiva virá de sinal do backend (family_name obrigatório).
                                    Por ora o flag é falso (conta 356 = Clássica). Documentado como UP-VAR. */}
                                {contaUserProducts ? (
                                    <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-300">
                                        <AlertTriangle size={15} className="inline mr-2 shrink-0" />
                                        Esta conta usa o modelo <strong>User Products</strong> do Mercado Livre —
                                        variações por família ainda não são suportadas nesta versão.
                                        Publique sem variações ou entre em contato com o suporte.
                                    </div>
                                ) : !temVariacoes ? (
                                    /* Categoria sem atributos allow_variations — etapa pode ser pulada */
                                    <div className="rounded-lg border border-white/[0.06] bg-ecf-bg px-4 py-3 text-sm text-white/40">
                                        Esta categoria não aceita variações — pule esta etapa e avance para Preço e estoque.
                                    </div>
                                ) : (
                                    /* Editor de variações (WIZ-04, WIZ-05, WIZ-06) */
                                    <VariacaoEditor
                                        variacoes={variacoes}
                                        setVariacoes={setVariacoes}
                                        atributosVar={atributosVar}
                                        rascunhoId={rascunhoId}
                                        onCriarRascunho={salvar}
                                        exigeGrade={exigeGrade}
                                        grades={grades}
                                        sizeGridId={sizeGridId}
                                        setSizeGridId={setSizeGridId}
                                    />
                                )}
                            </section>
                        )}

                        {/* ════════════════════════════════════════════════════
                            ETAPA 3 — Preço, estoque e exposição (PRICE-01)
                            ════════════════════════════════════════════════════ */}
                        {etapa === 3 && (
                            <>
                                <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                    <h2 className="mb-3 text-sm font-semibold text-white">4. Preço, estoque e exposição</h2>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Campo label="Preço (R$)" origem={origemCampos['price']}>
                                            <input
                                                className={inputCls}
                                                type="number"
                                                step="0.01"
                                                value={preco}
                                                onChange={e => { setPreco(e.target.value); marcarEditado('price'); }}
                                                placeholder="0,00"
                                            />
                                        </Campo>
                                        <Campo label="Estoque" origem={origemCampos['available_quantity']}>
                                            <input
                                                className={inputCls}
                                                type="number"
                                                min="1"
                                                value={estoque}
                                                onChange={e => { setEstoque(e.target.value); marcarEditado('available_quantity'); }}
                                            />
                                        </Campo>
                                        <Campo label="Condição">
                                            <select className={inputCls} value={condicao} onChange={e => setCondicao(e.target.value)}>
                                                {CONDICOES.map(c => <option key={c.v} value={c.v}>{c.l}</option>)}
                                            </select>
                                        </Campo>
                                        <Campo label="Tipo de anúncio">
                                            <select className={inputCls} value={tipoAnuncio} onChange={e => setTipoAnuncio(e.target.value)}>
                                                {(tipos.length ? tipos : [{ id: 'gold_special', name: 'Clássico' }, { id: 'gold_pro', name: 'Premium' }])
                                                    .map(t => <option key={t.id} value={t.id}>{t.name ?? t.id}</option>)}
                                            </select>
                                        </Campo>
                                    </div>

                                    {/* Faixa de preço da categoria (quando o ML expõe um piso) */}
                                    {(settingsCat.precoMin > 0 || settingsCat.precoMax) && (
                                        <p className="mt-2 text-[11px] text-white/40">
                                            Faixa de preço desta categoria:
                                            {settingsCat.precoMin > 0 && <> mínimo <b className="text-white/60">R$ {settingsCat.precoMin.toFixed(2)}</b></>}
                                            {settingsCat.precoMax && <> · máximo <b className="text-white/60">R$ {settingsCat.precoMax.toFixed(2)}</b></>}
                                        </p>
                                    )}

                                    {/* Exposição e parcelamento por tipo de anúncio (P1) */}
                                    {INFO_EXPOSICAO_TIER[tipoAnuncio] && (
                                        <div className="mt-3 rounded-lg border border-ecf-yellow/20 bg-ecf-yellow/[0.04] px-3 py-2.5">
                                            <p className="mb-1 flex items-center gap-1.5 text-[12px] font-semibold text-ecf-yellow/90">
                                                <Info size={13} /> {INFO_EXPOSICAO_TIER[tipoAnuncio].nome}
                                            </p>
                                            <ul className="space-y-0.5 text-[11px] text-white/55">
                                                <li>• {INFO_EXPOSICAO_TIER[tipoAnuncio].exposicao}</li>
                                                <li>• {INFO_EXPOSICAO_TIER[tipoAnuncio].parcelamento}</li>
                                                <li>• {INFO_EXPOSICAO_TIER[tipoAnuncio].comissao}</li>
                                            </ul>
                                        </div>
                                    )}

                                    {/* PRICE-01: simulador de preço embutido na etapa de preço */}
                                    {/* SHIP-02: freteExterno vem da cotação automática do pai */}
                                    <SimuladorPreco setPreco={setPreco} marcarEditado={marcarEditado} freteExterno={freteExterno} />
                                </section>
                            </>
                        )}

                        {/* ════════════════════════════════════════════════════
                            ETAPA 4 — Imagem, garantia, descrição e frete
                            ════════════════════════════════════════════════════ */}
                        {etapa === 4 && (
                            <>
                                {/* Imagem, garantia, descrição */}
                                <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                    <h2 className="mb-3 text-sm font-semibold text-white">5. Imagem, garantia e descrição</h2>
                                    <div className="space-y-3">
                                        {/* Com variações, as fotos vêm da etapa de Variações (por variação);
                                            o campo de imagem principal só aparece quando NÃO há variações. */}
                                        {variacoes.length === 0 && (
                                            <Campo label="URL da imagem principal" dica="Upload direto será adicionado numa próxima versão">
                                                <input className={inputCls} value={imagemUrl} onChange={e => setImagemUrl(e.target.value)} placeholder="https://…" />
                                            </Campo>
                                        )}

                                        {/* Regras de imagem do Mercado Livre (moderação — a foto é moderada
                                            na publicação; violações pausam/reprovam o anúncio). Advisory. */}
                                        <div className="rounded-lg border border-amber-500/15 bg-amber-500/[0.04] px-3 py-2.5">
                                            <p className="mb-1 flex items-center gap-1.5 text-[12px] font-semibold text-amber-300/90">
                                                <Image size={13} /> Regras de imagem do Mercado Livre
                                            </p>
                                            <ul className="space-y-0.5 text-[11px] text-white/50">
                                                <li>• Mínimo 500 px no menor lado; use {FOTOS_RECOMENDADAS_MIN}+ fotos (até {settingsCat.maxFotos}).</li>
                                                <li>• Sem logos, marca d'água, texto promocional ou dados de contato na imagem.</li>
                                                <li>• A 1ª foto (capa) deve ter fundo branco em muitas categorias.</li>
                                            </ul>
                                        </div>

                                        <Campo label="Garantia"><input className={inputCls} value={garantia} onChange={e => setGarantia(e.target.value)} placeholder="Ex.: 30 dias" /></Campo>
                                        <Campo label="Descrição" origem={origemCampos['description']}>
                                            <textarea
                                                className={cn(inputCls, 'min-h-[100px] resize-y')}
                                                value={descricao}
                                                onChange={e => { setDescricao(e.target.value); marcarEditado('description'); }}
                                                placeholder="Descreva o produto…"
                                                maxLength={settingsCat.maxDescricao}
                                            />
                                            {/* Contador de caracteres + sugestão de tamanho ideal (P2) */}
                                            <div className="mt-1 flex items-center justify-between">
                                                <span className="text-[11px] text-white/30">Ideal: 300–800 caracteres, texto puro.</span>
                                                <span className={cn('text-[11px] tabular-nums',
                                                    descricao.length > settingsCat.maxDescricao - 100 ? 'text-red-400' : 'text-white/30')}>
                                                    {descricao.length.toLocaleString('pt-BR')}/{settingsCat.maxDescricao.toLocaleString('pt-BR')}
                                                </span>
                                            </div>
                                        </Campo>

                                        {/* Boas práticas de descrição (P2) */}
                                        <div className="rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2.5">
                                            <p className="mb-1 flex items-center gap-1.5 text-[12px] font-semibold text-white/70">
                                                <Lightbulb size={13} className="text-ecf-yellow/70" /> Boas práticas para a descrição
                                            </p>
                                            <ul className="space-y-0.5 text-[11px] text-white/45">
                                                {DICAS_DESCRICAO.map((d, i) => <li key={i}>• {d}</li>)}
                                            </ul>
                                        </div>

                                        {/* Nota fiscal: informativo — NF NÃO é declarada na publicação, é
                                            pós-venda (após a compra, atrelada ao pedido/envio). */}
                                        <div className="flex items-start gap-2 rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2 text-[11px] text-white/45">
                                            <Info size={13} className="mt-0.5 shrink-0 text-white/35" />
                                            <span>
                                                <b className="text-white/60">Nota fiscal:</b> não é informada na publicação. A NF-e é emitida/anexada
                                                <b> após a venda</b>, atrelada ao pedido e ao envio (fluxo de expedição do Mercado Livre).
                                            </span>
                                        </div>
                                    </div>
                                </section>

                                {/* SHIP-03: dimensões do pacote embalado (habilitam o frete me2) */}
                                {(() => {
                                    // SHIP-03: peso volumétrico = C×L×A / 6.000 (kg)
                                    const pesoVolumetricoKg = (comprimentoCm && larguraCm && alturaCm)
                                        ? ((Number(comprimentoCm) * Number(larguraCm) * Number(alturaCm)) / 6000).toFixed(3)
                                        : null;
                                    // SHIP-03: aviso quando 1–3 dos 4 campos estão preenchidos mas não todos
                                    const dimPreenchidos = [pesoG, comprimentoCm, larguraCm, alturaCm].filter(Boolean).length;
                                    const aviso4Campos   = dimPreenchidos > 0 && dimPreenchidos < 4;

                                    return (
                                        <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                            <h2 className="mb-1 text-sm font-semibold text-white">6. Dimensões do pacote embalado</h2>
                                            <p className="mb-3 text-[11px] text-white/40">
                                                Medidas do pacote COM a embalagem (não do produto nu) — o ML usa isso para calcular o frete (Mercado Envios).
                                                Sem preencher, a publicação falha.
                                            </p>
                                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                                {/* Cada campo de dimensão tem badge individual (DRAFT-04) */}
                                                <Campo label="Peso (g)" origem={origemCampos['pesoG']}>
                                                    <input
                                                        className={inputCls}
                                                        type="number"
                                                        min="1"
                                                        value={pesoG}
                                                        onChange={e => { setPesoG(e.target.value); marcarEditado('pesoG'); }}
                                                        placeholder="300"
                                                    />
                                                </Campo>
                                                <Campo label="Comprimento (cm)" origem={origemCampos['comprimentoCm']}>
                                                    <input
                                                        className={inputCls}
                                                        type="number"
                                                        min="1"
                                                        value={comprimentoCm}
                                                        onChange={e => { setComprimentoCm(e.target.value); marcarEditado('comprimentoCm'); }}
                                                        placeholder="20"
                                                    />
                                                </Campo>
                                                <Campo label="Largura (cm)" origem={origemCampos['larguraCm']}>
                                                    <input
                                                        className={inputCls}
                                                        type="number"
                                                        min="1"
                                                        value={larguraCm}
                                                        onChange={e => { setLarguraCm(e.target.value); marcarEditado('larguraCm'); }}
                                                        placeholder="15"
                                                    />
                                                </Campo>
                                                <Campo label="Altura (cm)" origem={origemCampos['alturaCm']}>
                                                    <input
                                                        className={inputCls}
                                                        type="number"
                                                        min="1"
                                                        value={alturaCm}
                                                        onChange={e => { setAlturaCm(e.target.value); marcarEditado('alturaCm'); }}
                                                        placeholder="10"
                                                    />
                                                </Campo>
                                            </div>

                                            {/* SHIP-03: peso volumétrico estimado (C×L×A / 6.000 kg) */}
                                            {pesoVolumetricoKg != null && (
                                                <p className="mt-2 text-[11px] text-white/40">
                                                    Peso volumétrico estimado: {pesoVolumetricoKg} kg (C×L×A / 6.000)
                                                </p>
                                            )}

                                            {/* SHIP-03: aviso quando 1–3 campos preenchidos — cotação só dispara com os 4 */}
                                            {aviso4Campos && (
                                                <p className="mt-2 text-[11px] text-amber-400/80">
                                                    Preencha os 4 campos de dimensão e peso para habilitar a cotação de frete automática.
                                                </p>
                                            )}
                                        </section>
                                    );
                                })()}

                                {/* SHIP: modalidade de frete + frete grátis (P1).
                                    Flex/Full não entram aqui — o logistic_type é derivado da conta no ML. */}
                                <section className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                    <h2 className="mb-1 flex items-center gap-2 text-sm font-semibold text-white">
                                        <Truck size={15} className="text-ecf-yellow/70" /> 7. Frete
                                    </h2>
                                    <p className="mb-3 text-[11px] text-white/40">
                                        Como o comprador recebe o produto. Mercado Envios é o recomendado (elegível a Flex/Full conforme a conta).
                                    </p>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Campo label="Modalidade de envio">
                                            <select className={inputCls} value={shippingMode} onChange={e => setShippingMode(e.target.value)}>
                                                {MODALIDADES_FRETE.map(m => <option key={m.v} value={m.v}>{m.l}</option>)}
                                            </select>
                                        </Campo>
                                        <div className="flex items-end">
                                            {/* Frete grátis — só faz sentido no Mercado Envios (me2) */}
                                            <label className={cn(
                                                'flex w-full cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition',
                                                shippingMode !== 'me2'
                                                    ? 'cursor-not-allowed border-white/[0.08] opacity-40'
                                                    : 'border-white/[0.08] hover:border-ecf-yellow/40',
                                            )}>
                                                <input
                                                    type="checkbox"
                                                    className="accent-ecf-yellow h-4 w-4"
                                                    checked={freteGratis}
                                                    disabled={shippingMode !== 'me2'}
                                                    onChange={e => setFreteGratis(e.target.checked)}
                                                />
                                                <span className="text-white/80">Oferecer frete grátis</span>
                                            </label>
                                        </div>
                                    </div>

                                    {/* Dica da modalidade escolhida */}
                                    <p className="mt-2 text-[11px] text-white/40">
                                        {MODALIDADES_FRETE.find(m => m.v === shippingMode)?.dica}
                                    </p>

                                    {/* Aviso: frete grátis obrigatório acima de R$79 no ME2 */}
                                    {shippingMode === 'me2' && preco && Number(preco) >= PRECO_FRETE_GRATIS_OBRIGATORIO && !freteGratis && (
                                        <p className="mt-1 flex items-center gap-1 text-[11px] text-amber-400/80">
                                            <AlertTriangle size={12} /> Acima de R$ {PRECO_FRETE_GRATIS_OBRIGATORIO} o Mercado Envios costuma exigir frete grátis — ative para evitar recusa.
                                        </p>
                                    )}

                                    {/* Flex/Full: dependem da conta, não do payload de publicação */}
                                    <div className="mt-2 flex items-start gap-2 rounded-lg border border-white/[0.06] bg-ecf-bg px-3 py-2 text-[11px] text-white/45">
                                        <Info size={13} className="mt-0.5 shrink-0 text-white/35" />
                                        <span>
                                            <b className="text-white/60">Flex</b> (entrega no mesmo dia) e <b className="text-white/60">Full</b> (estoque no ML) não são escolhidos aqui —
                                            dependem da habilitação da conta no Mercado Livre e são aplicados automaticamente quando disponíveis.
                                        </span>
                                    </div>
                                </section>

                            </>
                        )}

                        {/* ─── Rodapé de navegação do wizard: Anterior / Próxima / Ações finais ─── */}
                        <div className="flex flex-wrap items-center gap-3">
                            {/* Botão Anterior (todas as etapas exceto a primeira) */}
                            {etapa > 0 && (
                                <button
                                    type="button"
                                    onClick={voltarEtapa}
                                    disabled={!!busy}
                                    className="flex items-center gap-1.5 rounded-lg border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-white/80 hover:bg-white/10 disabled:opacity-40"
                                >
                                    <ChevronLeft size={15} /> Anterior
                                </button>
                            )}

                            {/* Botão Próxima (todas as etapas exceto a última) */}
                            {etapa < ETAPAS.length - 1 && (
                                <button
                                    type="button"
                                    onClick={avancarEtapa}
                                    disabled={!!busy}
                                    className="flex items-center gap-1.5 rounded-lg bg-ecf-yellow px-4 py-2 text-sm font-semibold text-black hover:brightness-95 disabled:opacity-40"
                                >
                                    {busy === 'salvar' ? <Loader2 size={15} className="animate-spin" /> : null}
                                    Próxima <ChevronRight size={15} />
                                </button>
                            )}

                            {/* Ações disponíveis em qualquer etapa */}
                            <button onClick={salvar} disabled={!!busy}
                                className="flex items-center gap-2 rounded-lg border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-white/80 hover:bg-white/10 disabled:opacity-40">
                                {busy === 'salvar' ? <Loader2 size={15} className="animate-spin" /> : <Save size={15} />} Salvar
                            </button>
                            <button onClick={validar} disabled={!!busy || !categoryId}
                                className="flex items-center gap-2 rounded-lg border border-blue-500/30 bg-blue-500/10 px-4 py-2 text-sm font-medium text-blue-400 hover:bg-blue-500/20 disabled:opacity-40">
                                {busy === 'validar' ? <Loader2 size={15} className="animate-spin" /> : <CheckCircle2 size={15} />} Validar
                            </button>

                            {/* SEL-05: publicar só na última etapa; WIZ-03: bloqueado por catálogo */}
                            {etapa === ETAPAS.length - 1 && (
                                <>
                                    <button
                                        onClick={() => setConfirmPublicar(true)}
                                        disabled={!!busy || !categoryId || bloqueadoPorCatalogo}
                                        className="flex items-center gap-2 rounded-lg bg-ecf-yellow px-4 py-2 text-sm font-semibold text-black hover:brightness-95 disabled:opacity-40"
                                    >
                                        {busy === 'publicar' ? <Loader2 size={15} className="animate-spin" /> : <Rocket size={15} />} Publicar
                                    </button>

                                    {/* DUP-02: botão de 1 clique — publica Clássico + Premium simultaneamente.
                                        DUP-04: catalog_required desabilita via bloqueadoPorCatalogo (mesma condição do Publicar).
                                        Estilo amarelo SECUNDÁRIO (contornado) — distinto do Publicar principal (sólido). */}
                                    <button
                                        type="button"
                                        onClick={() => setConfirmPublicarDuplo(true)}
                                        disabled={!!busy || !categoryId || bloqueadoPorCatalogo}
                                        className="flex items-center gap-2 rounded-lg border border-ecf-yellow/40 bg-ecf-yellow/10 px-4 py-2 text-sm font-semibold text-ecf-yellow hover:bg-ecf-yellow/20 disabled:opacity-40"
                                    >
                                        {busy === 'publicarDuplo' ? <Loader2 size={15} className="animate-spin" /> : <Rocket size={15} />} Publicar Clássico + Premium
                                    </button>

                                    {/* WIZ-03: hint de bloqueio por catálogo — cobre ambos os botões (sem duplicar) */}
                                    {bloqueadoPorCatalogo && (
                                        <span className="text-[11px] text-amber-400/80 flex items-center gap-1">
                                            <AlertTriangle size={12} /> Informe o produto de catálogo na Ficha técnica para publicar.
                                        </span>
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                    {/* ─── Coluna de preview + validação (WIZ-08: sempre visível em todas as etapas) ─── */}
                    <aside className="space-y-4">
                        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                            <p className="mb-2 text-xs font-medium uppercase tracking-wide text-white/40">Pré-visualização</p>
                            <div className="rounded-lg border border-white/[0.06] bg-ecf-bg p-3">
                                {/* WIZ-08: imagem principal ou primeira foto de variação */}
                                <div className="mb-2 flex aspect-square items-center justify-center rounded-md bg-white/5">
                                    {imagemUrl ? (
                                        <img src={imagemUrl} alt="" className="h-full w-full rounded-md object-contain" />
                                    ) : variacoes[0]?.picture_urls?.[0] ? (
                                        <img src={variacoes[0].picture_urls[0]} alt="" className="h-full w-full rounded-md object-contain" />
                                    ) : (
                                        <PackageOpen size={40} className="text-white/15" />
                                    )}
                                </div>
                                <p className="line-clamp-2 text-sm font-medium text-white">{titulo || 'Título do anúncio'}</p>
                                <p className="mt-1 text-lg font-semibold text-ecf-yellow">{preco ? `R$ ${Number(preco).toFixed(2)}` : 'R$ —'}</p>

                                {/* WIZ-08: contador de variações abaixo do preço */}
                                {variacoes.length > 0 && (
                                    <p className="mt-0.5 text-[11px] text-ecf-yellow/70">
                                        {variacoes.length} {variacoes.length === 1 ? 'variação' : 'variações'}
                                    </p>
                                )}

                                <p className="mt-1 text-[11px] text-white/40">{empresa?.nome ?? '—'} · {categoria?.name ?? 'sem categoria'}</p>
                                {/* WIZ-08: breadcrumb da categoria em tempo real no preview */}
                                {categoria?.path_from_root?.length > 0 && (
                                    <p className="mt-1 text-[10px] text-white/30 truncate">
                                        {categoria.path_from_root.map(p => p.name).join(' › ')}
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* Saúde do anúncio (P1): score + guard-rail (erros) + avisos de qualidade.
                            Proativo — aparece com a categoria escolhida, antes mesmo de Validar/Publicar. */}
                        {categoryId && (
                            <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                <div className="mb-2 flex items-center justify-between">
                                    <p className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-white/40">
                                        <Gauge size={13} /> Saúde do anúncio
                                    </p>
                                    <span className={cn('text-sm font-bold tabular-nums',
                                        analise.score >= 80 ? 'text-emerald-400' : analise.score >= 50 ? 'text-amber-300' : 'text-red-400')}>
                                        {analise.score}<span className="text-[10px] font-normal text-white/30">/100</span>
                                    </span>
                                </div>
                                <div className="h-2 overflow-hidden rounded-full bg-white/[0.08]">
                                    <div className={cn('h-full rounded-full transition-all',
                                        analise.score >= 80 ? 'bg-emerald-400' : analise.score >= 50 ? 'bg-amber-300' : 'bg-red-400')}
                                        style={{ width: `${analise.score}%` }} />
                                </div>

                                {/* Erros bloqueantes (guard-rail — impedem publicar) */}
                                {analise.erros.length > 0 && (
                                    <ul className="mt-3 space-y-1">
                                        {analise.erros.map((e, i) => (
                                            <li key={`e${i}`} className="flex items-start gap-1.5 text-[11px] text-red-400/90">
                                                <AlertTriangle size={12} className="mt-0.5 shrink-0" />
                                                <span><b className="capitalize">{e.campo}:</b> {e.mensagem}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {/* Avisos (não bloqueiam, mas pioram qualidade/exposição) */}
                                {analise.avisos.length > 0 && (
                                    <ul className="mt-2 space-y-1">
                                        {analise.avisos.map((a, i) => (
                                            <li key={`a${i}`} className="flex items-start gap-1.5 text-[11px] text-amber-300/80">
                                                <Info size={12} className="mt-0.5 shrink-0" />
                                                <span><b className="capitalize">{a.campo}:</b> {a.mensagem}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {analise.erros.length === 0 && analise.avisos.length === 0 && (
                                    <p className="mt-3 flex items-center gap-1.5 text-[11px] text-emerald-400/80">
                                        <CheckCircle2 size={12} /> Anúncio completo — sem pendências detectadas.
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Resultado da validação */}
                        {erros && (
                            <div className={cn('rounded-xl border p-4', erros.valido ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-red-500/30 bg-red-500/5')}>
                                <p className={cn('mb-2 flex items-center gap-2 text-sm font-semibold', erros.valido ? 'text-emerald-400' : 'text-red-400')}>
                                    {erros.valido ? <><CheckCircle2 size={15} /> Válido para publicar</> : <><AlertTriangle size={15} /> {erros.erros.length} pendência(s)</>}
                                </p>
                                <ul className="space-y-1">
                                    {erros.erros.map((e, i) => (
                                        <li key={i} className="text-xs text-white/70">
                                            {e.tipo === 'warning' && (
                                                <span className="mr-1 rounded bg-amber-500/15 px-1 py-0.5 text-[9px] font-semibold text-amber-300">aviso</span>
                                            )}
                                            {e.campo && <span className="font-medium text-white/90">{e.campo}: </span>}{e.mensagem}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {/* Rascunhos recentes desta empresa — BULK-01/04: seleção múltipla + lote */}
                        {rascunhos.length > 0 && (
                            <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-4">
                                {/* Cabeçalho: selecionar-todos + botão de lote */}
                                <div className="mb-2 flex items-center gap-2">
                                    {rascunhosSelecionaveis.length > 0 && (
                                        <input
                                            type="checkbox"
                                            title="Selecionar todos"
                                            checked={selecionados.size > 0 && selecionados.size === rascunhosSelecionaveis.length}
                                            onChange={toggleTodos}
                                            className="accent-ecf-yellow h-3.5 w-3.5 shrink-0 cursor-pointer"
                                        />
                                    )}
                                    <p className="flex-1 text-xs font-medium uppercase tracking-wide text-white/40">
                                        Rascunhos recentes
                                    </p>
                                    {/* BULK-01: botão publicar lote — desabilitado após dispatch */}
                                    <button
                                        type="button"
                                        disabled={publicandoLote || selecionados.size === 0}
                                        onClick={publicarLote}
                                        className={cn(
                                            'inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-[11px] font-semibold transition',
                                            'bg-ecf-yellow text-black hover:brightness-95',
                                            'disabled:opacity-40 disabled:cursor-not-allowed',
                                        )}
                                    >
                                        {publicandoLote
                                            ? <><Loader2 className="animate-spin" size={12} /> Enviando…</>
                                            : <><Rocket size={12} /> Publicar lote ({selecionados.size})</>
                                        }
                                    </button>
                                </div>

                                {/* Erros do lote (ex.: token expirado, rascunho de outra empresa) */}
                                {errosLote && (
                                    <div className="mb-2 rounded-lg border border-red-500/30 bg-red-500/5 p-2">
                                        {errosLote.map((m, i) => (
                                            <p key={i} className="text-[11px] text-red-400">{m.mensagem}</p>
                                        ))}
                                    </div>
                                )}

                                {/* Lista de rascunhos com checkbox individual */}
                                <ul className="space-y-2">
                                    {rascunhos.slice(0, 8).map(r => {
                                        const selecionavel = r.status !== 'publicando' && r.status !== 'publicado';
                                        const abrivel = r.status !== 'publicando';
                                        return (
                                            <li key={r.id} className="rounded-lg border border-white/[0.06] bg-ecf-bg/40 p-2">
                                                {/* Linha 1: checkbox + status + TÍTULO do anúncio + MLB id */}
                                                <div className="flex items-center gap-2 text-sm">
                                                    {selecionavel
                                                        ? (
                                                            <input
                                                                type="checkbox"
                                                                checked={selecionados.has(r.id)}
                                                                onChange={() => toggleSelecionado(r.id)}
                                                                className="accent-ecf-yellow h-3.5 w-3.5 shrink-0 cursor-pointer"
                                                            />
                                                        )
                                                        : <span className="h-3.5 w-3.5 shrink-0" />
                                                    }
                                                    <span className={cn('shrink-0 rounded px-1.5 py-0.5 text-[10px]', STATUS_BADGE[r.status] ?? STATUS_BADGE.rascunho)}>
                                                        {STATUS_LABEL[r.status] ?? r.status}
                                                    </span>
                                                    {/* Título do anúncio (identifica o rascunho) */}
                                                    <span className="min-w-0 flex-1 truncate font-medium text-white/80" title={r.titulo || ''}>
                                                        {r.titulo?.trim() || '(sem título)'}
                                                    </span>
                                                    {r.ml_item_id && (
                                                        <a
                                                            href={`https://produto.mercadolivre.com.br/MLB-${String(r.ml_item_id).replace(/^MLB-?/i, '')}`}
                                                            target="_blank" rel="noreferrer"
                                                            className="shrink-0 text-[10px] text-emerald-400/70 hover:underline"
                                                        >
                                                            {r.ml_item_id}
                                                        </a>
                                                    )}
                                                </div>

                                                {/* Erro: resumo em 1 linha por padrão; expande para o erro completo + copiar */}
                                                {r.status === 'erro' && r.erro_resumo && (
                                                    <details className="mt-1 pl-6">
                                                        <summary className="cursor-pointer text-[11px] text-red-400 marker:text-red-400/60">
                                                            {r.erro_resumo}
                                                        </summary>
                                                        <div className="mt-1">
                                                            <pre className="max-h-48 overflow-auto whitespace-pre-wrap break-words rounded-md border border-red-500/20 bg-red-500/[0.05] p-2 text-[10px] leading-relaxed text-red-300/80">
                                                                {r.erro_completo || r.erro_resumo}
                                                            </pre>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    const txt = r.erro_completo || r.erro_resumo || '';
                                                                    if (navigator.clipboard?.writeText) navigator.clipboard.writeText(txt).catch(() => fallbackCopiar(txt));
                                                                    else fallbackCopiar(txt);
                                                                    setFlash('Erro copiado.');
                                                                }}
                                                                className="mt-1 flex items-center gap-1 rounded-md border border-white/10 bg-white/[0.03] px-2 py-0.5 text-[10px] text-white/60 hover:text-white/90"
                                                            >
                                                                <Copy size={10} /> Copiar erro
                                                            </button>
                                                        </div>
                                                    </details>
                                                )}

                                                {/* Linha 2: ações — Abrir / Template / Excluir */}
                                                <div className="mt-1.5 flex items-center gap-1.5 pl-6">
                                                    {abrivel && (
                                                        <button
                                                            type="button"
                                                            onClick={() => abrirRascunho(r)}
                                                            title="Abrir este rascunho para editar"
                                                            className="flex items-center gap-1 rounded-md border border-white/10 bg-white/[0.03] px-2 py-0.5 text-[10px] font-medium text-white/70 transition hover:border-white/25 hover:text-white"
                                                        >
                                                            <Pencil size={10} /> Abrir
                                                        </button>
                                                    )}
                                                    {r.status === 'publicado' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => usarComoTemplate(r)}
                                                            disabled={busy === 'template'}
                                                            title="Criar novo rascunho a partir deste anúncio publicado"
                                                            className="flex items-center gap-1 rounded-md border border-ecf-yellow/30 bg-ecf-yellow/5 px-2 py-0.5 text-[10px] font-medium text-ecf-yellow/80 transition hover:border-ecf-yellow/50 hover:bg-ecf-yellow/15 disabled:opacity-40"
                                                        >
                                                            {busy === 'template' ? <Loader2 size={10} className="animate-spin" /> : <Copy size={10} />} Template
                                                        </button>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={() => excluirRascunho(r)}
                                                        title="Excluir este rascunho"
                                                        className="ml-auto flex items-center gap-1 rounded-md border border-red-500/20 bg-red-500/[0.06] px-2 py-0.5 text-[10px] font-medium text-red-400/80 transition hover:border-red-500/40 hover:bg-red-500/15"
                                                    >
                                                        <Trash2 size={10} /> Excluir
                                                    </button>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        )}
                    </aside>
                </div>
            </div>

            {/* SEL-05: modal de confirmação de publicação com nome da empresa */}
            <Dialog open={confirmPublicar} onOpenChange={setConfirmPublicar}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Confirmar publicação</DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-white/60">
                        O anúncio será publicado na conta{' '}
                        <span className="font-semibold text-white">{empresa?.nome}</span>.
                        {' '}Confirma?
                    </p>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmPublicar(false)}>Cancelar</Button>
                        <Button onClick={confirmarPublicacao}>Publicar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* DUP-02: modal de confirmação do fluxo Clássico+Premium (1 clique, 2 anúncios) */}
            <Dialog open={confirmPublicarDuplo} onOpenChange={setConfirmPublicarDuplo}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Publicar Clássico + Premium</DialogTitle>
                    </DialogHeader>
                    <p className="text-sm text-white/60">
                        Serão criados <span className="font-semibold text-white">dois anúncios</span> (um Clássico e
                        um Premium, com títulos diferentes) na conta{' '}
                        <span className="font-semibold text-white">{empresa?.nome}</span>.
                        {' '}Confirma?
                    </p>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmPublicarDuplo(false)}>Cancelar</Button>
                        <Button onClick={confirmarPublicacaoDupla}>Publicar os dois</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
