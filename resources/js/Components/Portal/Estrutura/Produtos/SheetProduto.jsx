import { useRef, useState } from 'react';
import axios from 'axios';
import { Loader2, Plus, X } from 'lucide-react';
import { Sheet, SheetBody, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import JanelaExcluirVariacao from '@/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao';
import PickerLista from '@/Components/Portal/Estrutura/Produtos/PickerLista';
import PickerCategoria from '@/Components/Portal/Estrutura/Produtos/PickerCategoria';
import { campoEditaveis, linhaDaGrade, linhaParaServidor } from '@/lib/produtosEstrutura';
import { cn } from '@/lib/utils';

// ─── Formulário do produto no celular (167-16) ──────────────────────────────
//
// Uma folha de baixo com o produto inteiro: nome, família, ambiente(s),
// categoria e, por variação, Ref, eixo, valor, custo e volumes. Um único botão
// amarelo grava tudo de uma vez pelo MESMO POST `linhas` da tabela — a regra
// (validação, empresa, log, cálculo de frete) é a do servidor; aqui só se coleta.
//
// Família, ambiente e categoria são "do produto": a escolha vale para todas as
// variações do formulário (o servidor só olha a 1ª). Logística, peso cubado e
// frete não aparecem: o servidor calcula e a tabela mostra depois de gravar.

const CAMPO = 'h-11 w-full min-w-0 rounded-xl border border-white/20 bg-black/40 px-3 text-[14px] text-white placeholder:text-white/30 focus:border-ecf-yellow/40 focus:outline-none focus:ring-0';
const ROTULO = 'mb-1 block text-[12px] font-semibold text-white/70';
const BOTAO_ESCOLHA = 'flex h-11 w-full items-center justify-between gap-2 rounded-xl border border-white/20 bg-black/40 px-3 text-left text-[14px] focus:border-ecf-yellow/40 focus:outline-none';
const BOTAO_SECUNDARIO = 'inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border border-white/[0.10] bg-white/[0.03] px-3 text-[13px] font-medium text-white/80 hover:bg-white/[0.07] hover:text-white';

const MEDIDAS = [
    { chave: 'c', rotulo: 'Comprimento (cm)' },
    { chave: 'l', rotulo: 'Largura (cm)' },
    { chave: 'a', rotulo: 'Altura (cm)' },
    { chave: 'kg', rotulo: 'Peso (kg)' },
];

let contador = 0;
const novaChave = () => `m${++contador}`;

const linhaEmBranco = () => ({
    _k: novaChave(), codigo: '', nome: '', eixo_rotulo: '', valor: '', familia: '', ambientes_texto: '',
    categoria: '', volumes_texto: '', custo: '', volumes: [],
});

const texto = (n) => (n == null ? '' : String(n).replace('.', ','));

/** Caixas editáveis (textos) a partir do que a variação tem hoje. */
const caixasDe = (row) => {
    const origem = Array.isArray(row.volumes_digitados) ? row.volumes_digitados : (Array.isArray(row.volumes) ? row.volumes : []);

    return origem.map((v) => ({ c: texto(v.c), l: texto(v.l), a: texto(v.a), kg: texto(v.kg) }));
};

const caixaVazia = (c) => MEDIDAS.every((m) => String(c[m.chave] ?? '').trim() === '');

/** Campos que valem para o produto inteiro: a escolha vai para todas as variações do formulário. */
const CAMPOS_DO_PRODUTO = ['familia', 'ambientes_texto', 'categoria', 'categoria_ml_id', 'categoria_ml_nome', 'categoria_ml_caminho', '_categoriaEscolhida'];

export default function SheetProduto({ aberto = true, linhas = [], listas, vocabulario, onListas, onGravado, onRemovida, onFechar }) {
    const [vars, setVars] = useState(() => (linhas.length ? linhas.map((l) => ({ ...l })) : [linhaEmBranco()]));
    const [erros, setErros] = useState({});          // { _k: mensagem }
    const [aviso, setAviso] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [escolhendo, setEscolhendo] = useState(null);   // 'familia' | 'ambientes' | 'categoria'
    const [exclusao, setExclusao] = useState(null);       // { linha, ultima }
    const fecharPicker = useRef(null);

    const primeira = vars[0];
    const novoProduto = ! primeira.produto_id;
    const eixos = Object.values(vocabulario?.eixos ?? {});

    const alterar = (chave, campo, valor) => setVars((atual) => atual.map((v) => (v._k === chave ? { ...v, [campo]: valor } : v)));

    /** Nome vale para todas as variações; os demais campos do produto vêm do picker. */
    const alterarNome = (valor) => setVars((atual) => atual.map((v) => ({ ...v, nome: valor })));

    const aplicarEscolha = (patch) => setVars((atual) => atual.map((v) => {
        const parte = Object.fromEntries(Object.entries(patch).filter(([c]) => CAMPOS_DO_PRODUTO.includes(c)));

        return { ...v, ...parte };
    }));

    // ─── Volumes (cartões empilhados) ───────────────────────────────────────

    /** Caixas em edição (inclui as em branco); sempre ao menos uma. */
    const caixasEdit = (v) => {
        const atuais = v._caixas ?? caixasDe(v);

        return atuais.length ? atuais : [{ c: '', l: '', a: '', kg: '' }];
    };

    const gravarCaixas = (chave, caixas) => setVars((atual) => atual.map((v) => {
        if (v._k !== chave) return v;
        const preenchidas = caixas.filter((c) => ! caixaVazia(c)).map((c) => ({ c: c.c.trim(), l: c.l.trim(), a: c.a.trim(), kg: c.kg.trim() }));

        return {
            ...v,
            _caixas: caixas,
            volumes_digitados: preenchidas,
            volumes_texto: preenchidas.map((c) => `${c.c}×${c.l}×${c.a} · ${c.kg}`).join(' | '),
        };
    }));

    const mudarCaixa = (v, indice, campo, valor) => {
        gravarCaixas(v._k, caixasEdit(v).map((c, i) => (i === indice ? { ...c, [campo]: valor } : c)));
    };
    const adicionarVolume = (v) => gravarCaixas(v._k, [...caixasEdit(v), { c: '', l: '', a: '', kg: '' }]);
    const removerCaixa = (v, indice) => gravarCaixas(v._k, caixasEdit(v).filter((_, i) => i !== indice));

    // ─── Nova variação (D-04): nasce com tudo da 1ª, só o Valor fica vazio ──

    const novaVariacao = () => {
        const quantas = vars.length;
        const base = vars[0];
        const nova = {
            ...base,
            _k: novaChave(),
            id: undefined, oferta: null, frete: null, pendencias: [], falta: '', peso_cubado: null, peso_faturado: null,
            cubado_cobrado: false, logistica: null, oferta_id: undefined,
            codigo: `${base.grupo ?? base.codigo}-${quantas + 1}`,
            valor: '',
            volumes_digitados: caixasEdit(base).filter((c) => ! caixaVazia(c)).map((c) => ({ c: c.c.trim(), l: c.l.trim(), a: c.a.trim(), kg: c.kg.trim() })),
            _caixas: undefined,
        };
        // Com produto já gravado o servidor copia o resto da 1ª; só Ref e Valor precisam ir.
        if (nova.produto_id) nova._base = { ...campoEditaveis(nova), codigo: '', valor: '' };
        else delete nova._base;
        setVars((atual) => [...atual, nova]);
    };

    // ─── Salvar: UM POST com todas as variações ─────────────────────────────

    const salvar = async () => {
        if (salvando) return;
        setAviso(null);
        const faltando = {};
        vars.forEach((v) => {
            if (String(v.codigo).trim() === '' || String(v.nome).trim() === '') {
                faltando[v._k] = 'Não salvamos esta linha: informe a Ref e o nome do produto.';
            }
        });
        if (Object.keys(faltando).length) { setErros(faltando); return; }

        // Produto novo: todas as variações se juntam pelo código da 1ª, que vira o grupo.
        const grupo = novoProduto ? String(vars[0].codigo).trim() : null;
        const enviadas = vars.map((v) => ({ ...v, ...(grupo && ! v.produto_id ? { grupo } : {}) }));

        setSalvando(true);
        setErros({});
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.produtos.linhas'), { linhas: enviadas.map(linhaParaServidor) });
            const comErro = {};
            (data.erros ?? []).forEach((e) => {
                if (e.chave) comErro[e.chave] = `Não salvamos esta linha: ${String(e.mensagem).replace(/\.$/, '')}.`;
            });
            onGravado(data);

            if (Object.keys(comErro).length === 0) { onFechar(); return; }

            // Parcial: o que gravou volta com os ids do servidor; o que falhou fica como está, com o motivo.
            const porChave = new Map((data.linhas ?? []).filter((l) => l.chave).map((l) => [l.chave, l]));
            setVars((atual) => atual.map((v) => {
                const servidor = porChave.get(v._k);

                return servidor && ! comErro[v._k] ? { ...linhaDaGrade(servidor, vocabulario?.pendencias), _k: v._k } : v;
            }));
            setErros(comErro);
        } catch (e) {
            setAviso(e.response && e.response.status < 500
                ? (e.response.data?.message ?? 'Não foi possível salvar agora. O que você digitou fica aqui.')
                : 'Não foi possível salvar agora. O que você digitou fica aqui; tente de novo.');
        } finally {
            setSalvando(false);
        }
    };

    // ─── Escolhas (família, ambiente, categoria) numa folha de baixo ─────────

    const abrirEscolha = (qual) => { fecharPicker.current = null; setEscolhendo(qual); };
    const fecharEscolha = () => {
        // Ambiente grava as caixas marcadas ao fechar (o picker registra a função).
        if (fecharPicker.current) { const f = fecharPicker.current; fecharPicker.current = null; f(); return; }
        setEscolhendo(null);
    };

    const resumoAmbientes = String(primeira.ambientes_texto ?? '').split(',').map((s) => s.trim()).filter(Boolean);
    const rotuloEscolha = (valor) => (valor ? <span className="truncate text-white">{valor}</span> : <span className="text-white/30">escolher</span>);

    return (
        <>
            <Sheet open={aberto} onOpenChange={(v) => { if (! v && ! escolhendo && ! exclusao) onFechar(); }}>
                <SheetContent side="bottom" className="max-h-[90vh] overflow-y-auto rounded-t-2xl" data-sheet-produto>
                    <SheetHeader className="px-4">
                        <SheetTitle>{novoProduto ? 'Novo produto' : (primeira.nome || 'Produto')}</SheetTitle>
                        <SheetDescription>Preencha e toque em Salvar produto. Medidas, peso e custo deixam o frete calculado.</SheetDescription>
                    </SheetHeader>

                    <SheetBody className="space-y-5 px-4">
                        {aviso && <p role="alert" className="rounded-xl border border-red-400/20 bg-red-500/10 px-3 py-2 text-[12px] text-red-200">{aviso}</p>}

                        <div>
                            <label className={ROTULO} htmlFor="sheet-produto-nome">Produto</label>
                            <input id="sheet-produto-nome" className={CAMPO} value={primeira.nome} onChange={(e) => alterarNome(e.target.value)} placeholder="nome do produto" />
                        </div>

                        <div>
                            <span className={ROTULO}>Família (linha de design)</span>
                            <button type="button" className={BOTAO_ESCOLHA} onClick={() => abrirEscolha('familia')} data-escolha="familia">
                                {rotuloEscolha(primeira.familia)}
                            </button>
                        </div>

                        <div>
                            <span className={ROTULO}>Ambiente</span>
                            <button type="button" className={BOTAO_ESCOLHA} onClick={() => abrirEscolha('ambientes')} data-escolha="ambientes">
                                {resumoAmbientes.length ? <span className="truncate text-white">{resumoAmbientes.join(', ')}</span> : <span className="text-white/30">escolher</span>}
                            </button>
                        </div>

                        <div>
                            <span className={ROTULO}>Categoria ML</span>
                            <button type="button" className={BOTAO_ESCOLHA} onClick={() => abrirEscolha('categoria')} data-escolha="categoria">
                                {rotuloEscolha(primeira.categoria)}
                            </button>
                        </div>

                        {vars.map((v, n) => {
                            const caixas = caixasEdit(v);

                            return (
                                <section key={v._k} className="space-y-4 rounded-2xl border border-white/[0.08] bg-white/[0.02] p-4" data-variacao-form>
                                    <h3 className="text-[13px] font-semibold text-white">Variação {n + 1}</h3>

                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <label className={ROTULO} htmlFor={`ref-${v._k}`}>Ref</label>
                                            <input id={`ref-${v._k}`} className={cn(CAMPO, 'font-mono')} value={v.codigo} onChange={(e) => alterar(v._k, 'codigo', e.target.value)} placeholder="código" />
                                        </div>
                                        <div>
                                            <label className={ROTULO} htmlFor={`eixo-${v._k}`}>Eixo</label>
                                            <select id={`eixo-${v._k}`} className={CAMPO} value={v.eixo_rotulo ?? ''} onChange={(e) => alterar(v._k, 'eixo_rotulo', e.target.value)}>
                                                <option value="">—</option>
                                                {eixos.map((r) => <option key={r} value={r}>{r}</option>)}
                                            </select>
                                        </div>
                                        <div>
                                            <label className={ROTULO} htmlFor={`valor-${v._k}`}>Valor</label>
                                            <input id={`valor-${v._k}`} className={CAMPO} value={v.valor ?? ''} onChange={(e) => alterar(v._k, 'valor', e.target.value)} placeholder="ex.: Natural" />
                                        </div>
                                        <div>
                                            <label className={ROTULO} htmlFor={`custo-${v._k}`}>Custo</label>
                                            <input id={`custo-${v._k}`} className={CAMPO} inputMode="decimal" value={v.custo ?? ''} onChange={(e) => alterar(v._k, 'custo', e.target.value)} placeholder="0,00" />
                                        </div>
                                    </div>

                                    <div className="space-y-3">
                                        <span className={ROTULO}>Volumes</span>
                                        {caixas.map((c, i) => (
                                            <div key={i} className="rounded-xl border border-white/[0.08] bg-white/[0.03] p-3" data-volume-cartao>
                                                <div className="mb-2 flex items-center justify-between">
                                                    <span className="text-[12px] text-white/60">Caixa {i + 1}</span>
                                                    <button type="button" onClick={() => removerCaixa(v, i)} aria-label={`Remover caixa ${i + 1}`}
                                                        className="grid h-8 w-8 place-items-center rounded-lg text-white/40 hover:bg-white/[0.06] hover:text-white">
                                                        <X size={14} />
                                                    </button>
                                                </div>
                                                <div className="grid grid-cols-2 gap-3">
                                                    {MEDIDAS.map((m) => (
                                                        <div key={m.chave}>
                                                            <label className={ROTULO} htmlFor={`cx-${v._k}-${i}-${m.chave}`}>{m.rotulo}</label>
                                                            <input id={`cx-${v._k}-${i}-${m.chave}`} className={cn(CAMPO, 'tabular-nums')} inputMode="decimal"
                                                                value={c[m.chave]} onChange={(e) => mudarCaixa(v, i, m.chave, e.target.value)} />
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ))}
                                        <button type="button" onClick={() => adicionarVolume(v)} className={BOTAO_SECUNDARIO} data-acao="adicionar-volume">
                                            <Plus size={14} /> Adicionar volume
                                        </button>
                                    </div>

                                    {erros[v._k] && <p role="alert" className="text-[12px] text-red-300" data-erro-variacao>{erros[v._k]}</p>}

                                    {v.id && (
                                        <button type="button" onClick={() => setExclusao({ linha: v, ultima: vars.filter((x) => x.id).length <= 1 })}
                                            className="min-h-[44px] text-[12px] text-red-300 hover:text-red-200" data-acao="excluir-variacao">
                                            Excluir variação
                                        </button>
                                    )}
                                </section>
                            );
                        })}

                        <button type="button" onClick={novaVariacao} className={BOTAO_SECUNDARIO} data-acao="nova-variacao">
                            <Plus size={14} /> Nova variação
                        </button>
                    </SheetBody>

                    <SheetFooter className="px-4">
                        <button type="button" onClick={salvar} disabled={salvando} data-acao="salvar-produto"
                            className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-ecf-yellow px-4 text-[14px] font-semibold text-black hover:brightness-95 disabled:opacity-60">
                            {salvando ? <Loader2 size={14} className="animate-spin" /> : null}
                            {salvando ? 'Salvando…' : 'Salvar produto'}
                        </button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>

            <Sheet open={!! escolhendo} onOpenChange={(v) => { if (! v) fecharEscolha(); }}>
                <SheetContent side="bottom" className="max-h-[90vh] overflow-y-auto rounded-t-2xl" data-sheet-escolha>
                    <SheetHeader className="px-4">
                        <SheetTitle>{{ familia: 'Família', ambientes: 'Ambiente', categoria: 'Categoria ML' }[escolhendo] ?? ''}</SheetTitle>
                        <SheetDescription className="sr-only">Escolha uma opção da lista.</SheetDescription>
                    </SheetHeader>
                    <SheetBody className="px-4 [&>div]:w-full [&>div]:max-w-none">
                        {escolhendo === 'familia' && (
                            <PickerLista tipo="familia" opcoes={listas?.familias ?? []} valor={primeira.familia}
                                onCommit={aplicarEscolha} onClose={() => setEscolhendo(null)}
                                registrarFechar={(f) => { fecharPicker.current = f; }} onListas={onListas} />
                        )}
                        {escolhendo === 'ambientes' && (
                            <PickerLista tipo="ambiente" multiplo opcoes={listas?.ambientes ?? []} valor={primeira.ambientes_texto}
                                onCommit={aplicarEscolha} onClose={() => setEscolhendo(null)}
                                registrarFechar={(f) => { fecharPicker.current = f; }} onListas={onListas} />
                        )}
                        {escolhendo === 'categoria' && (
                            <PickerCategoria row={primeira} onCommit={aplicarEscolha} onClose={() => setEscolhendo(null)} />
                        )}
                    </SheetBody>
                    <SheetFooter className="px-4">
                        <button type="button" onClick={fecharEscolha} className={cn(BOTAO_SECUNDARIO, 'w-full')} data-acao="fechar-escolha">
                            {escolhendo === 'ambientes' ? 'Concluir' : 'Fechar'}
                        </button>
                    </SheetFooter>
                </SheetContent>
            </Sheet>

            <JanelaExcluirVariacao aberta={!! exclusao} linha={exclusao?.linha} ultima={exclusao?.ultima}
                onFechar={() => setExclusao(null)}
                onExcluida={(resposta) => {
                    const linha = exclusao.linha;
                    setExclusao(null);
                    onRemovida?.(linha, resposta);
                    const resto = vars.filter((v) => v._k !== linha._k);
                    if (resto.length === 0) { onFechar(); return; }
                    setVars(resto);
                }} />
        </>
    );
}
