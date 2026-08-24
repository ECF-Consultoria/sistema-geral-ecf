import { useEffect, useMemo, useRef, useState } from 'react';
import { Building2, Check, ChevronDown, Layers, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Escolher a empresa (ou o grupo inteiro) ────────────────────────────────
//
// ### Por que não é um `<select>`
// A primeira versão usava `<select>` com `<optgroup>`. O navegador desenha esse
// dropdown com o tema do SISTEMA operacional e ignora o CSS da página: no tema
// escuro do ECF, os rótulos dos grupos saíam em fundo branco. Não é ajustável —
// `optgroup` não aceita estilo em nenhum navegador de forma confiável.
//
// O segundo motivo é de uso, e vale mais: são quase 200 empresas ativas. Rolar
// uma lista dessa procurando "Camillo" é pior do que digitar três letras.
//
// ### O que a busca casa
// Nome da empresa E nome do grupo. Quem digita "camillo" encontra tanto o grupo
// quanto as sete empresas dele — e é assim que a pessoa pensa quando vai dar
// acesso a um cliente de grupo.

export default function SeletorEmpresa({
    empresas = [],
    grupos = [],
    valor,
    onChange,
    excluirIds = [],
    placeholder = 'Selecione a empresa ou o grupo…',
}) {
    const [aberto, setAberto] = useState(false);
    const [busca, setBusca] = useState('');
    // O painel abre para BAIXO por padrão e vira para cima quando não cabe.
    // Sem isso ele estoura a base da janela justamente no caso comum: o campo
    // fica na parte de baixo do diálogo, e a lista sairia da tela.
    const [paraCima, setParaCima] = useState(false);
    const caixa = useRef(null);
    const campoBusca = useRef(null);

    // Fecha ao clicar fora. Sem isso, o painel ficaria aberto atrás do diálogo.
    useEffect(() => {
        if (!aberto) return;

        const aoClicar = (e) => {
            if (caixa.current && !caixa.current.contains(e.target)) setAberto(false);
        };
        const aoTeclar = (e) => { if (e.key === 'Escape') setAberto(false); };

        document.addEventListener('mousedown', aoClicar);
        document.addEventListener('keydown', aoTeclar);

        return () => {
            document.removeEventListener('mousedown', aoClicar);
            document.removeEventListener('keydown', aoTeclar);
        };
    }, [aberto]);

    useEffect(() => {
        if (! aberto) return;

        campoBusca.current?.focus();

        const r = caixa.current?.getBoundingClientRect();
        if (r) {
            const ALTURA = 340; // busca + lista, no máximo
            const abaixo = window.innerHeight - r.bottom;
            // Só vira para cima se lá couber melhor — num campo colado no topo,
            // virar seria trocar um corte por outro.
            setParaCima(abaixo < ALTURA && r.top > abaixo);
        }
    }, [aberto]);

    const disponiveis = useMemo(
        () => empresas.filter((e) => !excluirIds.includes(e.id)),
        [empresas, excluirIds],
    );

    // Agrupa e filtra numa passada só. O grupo sobrevive à busca se o NOME DELE
    // casar, mesmo que nenhuma empresa case — é o que faz "camillo" trazer o
    // grupo inteiro.
    const { blocos, avulsas } = useMemo(() => {
        const termo = busca.trim().toLowerCase();
        const casa = (t) => !termo || (t ?? '').toLowerCase().includes(termo);

        const porGrupo = new Map();
        const soltas = [];

        for (const e of disponiveis) {
            if (!e.grupo_id) {
                if (casa(e.nome)) soltas.push(e);
                continue;
            }
            if (!porGrupo.has(e.grupo_id)) porGrupo.set(e.grupo_id, []);
            porGrupo.get(e.grupo_id).push(e);
        }

        const lista = grupos
            .filter((g) => porGrupo.has(g.id))
            .map((g) => {
                const todas = porGrupo.get(g.id);
                const grupoCasa = casa(g.nome);

                return {
                    grupo: g,
                    empresas: grupoCasa ? todas : todas.filter((e) => casa(e.nome)),
                    total: todas.length,
                };
            })
            .filter((b) => b.empresas.length > 0);

        return { blocos: lista, avulsas: soltas };
    }, [disponiveis, grupos, busca]);

    // O que mostrar no campo fechado.
    const rotulo = useMemo(() => {
        if (!valor) return null;

        const [tipo, id] = valor.split(':');

        if (tipo === 'g') {
            const g = grupos.find((x) => x.id === Number(id));

            return g ? { texto: `Todas as ${g.empresas} empresas · ${g.nome}`, grupo: true } : null;
        }

        const e = empresas.find((x) => x.id === Number(id));

        return e ? { texto: e.nome, sub: e.grupo_nome, grupo: false } : null;
    }, [valor, empresas, grupos]);

    const escolher = (v) => {
        onChange(v);
        setBusca('');
        setAberto(false);
    };

    const nada = blocos.length === 0 && avulsas.length === 0;

    return (
        <div ref={caixa} className="relative">
            <button
                type="button"
                onClick={() => setAberto((v) => !v)}
                className={cn(
                    'w-full min-h-10 flex items-center gap-2 rounded-lg px-3 py-2 text-left transition-colors',
                    'bg-white/[0.03] ring-1 ring-inset outline-none',
                    aberto ? 'ring-white/25' : 'ring-white/[0.08] hover:ring-white/[0.14]',
                )}
            >
                {rotulo ? (
                    <>
                        <span className={cn(
                            'grid place-items-center h-6 w-6 rounded-md shrink-0',
                            rotulo.grupo ? 'bg-ecf-yellow/12 text-ecf-yellow' : 'bg-white/[0.06] text-white/45',
                        )}>
                            {rotulo.grupo ? <Layers size={12} /> : <Building2 size={12} />}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block text-white text-[13px] truncate">{rotulo.texto}</span>
                            {rotulo.sub && (
                                <span className="block text-white/30 text-[11px] truncate">{rotulo.sub}</span>
                            )}
                        </span>
                        <span
                            role="button"
                            tabIndex={-1}
                            onClick={(e) => { e.stopPropagation(); onChange(''); }}
                            className="p-1 rounded text-white/25 hover:text-white/70 transition-colors shrink-0"
                            title="Limpar"
                        >
                            <X size={13} />
                        </span>
                    </>
                ) : (
                    <span className="flex-1 text-white/30 text-[13px]">{placeholder}</span>
                )}
                <ChevronDown size={14} className={cn('text-white/25 shrink-0 transition-transform', aberto && 'rotate-180')} />
            </button>

            {aberto && (
                <div className={cn(
                    'absolute z-50 left-0 right-0 rounded-xl bg-ecf-card ring-1 ring-white/[0.12]',
                    'shadow-2xl shadow-black/60 overflow-hidden',
                    paraCima ? 'bottom-full mb-1.5' : 'top-full mt-1.5',
                )}>
                    <div className="flex items-center gap-2 px-3 py-2.5 border-b border-white/[0.06]">
                        <Search size={13} className="text-white/30 shrink-0" />
                        <input
                            ref={campoBusca}
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                            placeholder="Buscar empresa ou grupo…"
                            className="flex-1 bg-transparent text-white text-[13px] placeholder:text-white/25 outline-none"
                        />
                        {busca && (
                            <button
                                type="button"
                                onClick={() => setBusca('')}
                                className="p-0.5 rounded text-white/25 hover:text-white/70"
                            >
                                <X size={12} />
                            </button>
                        )}
                    </div>

                    <div className="max-h-[280px] overflow-y-auto py-1">
                        {nada && (
                            <p className="text-white/30 text-[12.5px] text-center py-8 px-4">
                                Nada encontrado para “{busca}”.
                            </p>
                        )}

                        {blocos.map(({ grupo, empresas: doGrupo, total }) => (
                            <div key={grupo.id} className="py-1">
                                {/* O rótulo do grupo é nosso, não do navegador — é
                                    justamente o que o `<optgroup>` não deixava
                                    estilizar. */}
                                <p className="px-3 py-1.5 text-white/30 text-[10.5px] font-semibold uppercase tracking-wider">
                                    {grupo.nome}
                                </p>

                                {/* A opção do grupo inteiro só aparece com mais de
                                    uma empresa disponível — com uma só ela seria a
                                    mesma coisa que a linha de baixo. */}
                                {total > 1 && (
                                    <Opcao
                                        selecionado={valor === `g:${grupo.id}`}
                                        onClick={() => escolher(`g:${grupo.id}`)}
                                        icone={Layers}
                                        destaque
                                    >
                                        Todas as {total} empresas
                                    </Opcao>
                                )}

                                {doGrupo.map((e) => (
                                    <Opcao
                                        key={e.id}
                                        selecionado={valor === `e:${e.id}`}
                                        onClick={() => escolher(`e:${e.id}`)}
                                        icone={Building2}
                                        recuado
                                    >
                                        {e.nome}
                                    </Opcao>
                                ))}
                            </div>
                        ))}

                        {avulsas.length > 0 && (
                            <div className="py-1">
                                {blocos.length > 0 && (
                                    <p className="px-3 py-1.5 text-white/30 text-[10.5px] font-semibold uppercase tracking-wider">
                                        Sem grupo
                                    </p>
                                )}
                                {avulsas.map((e) => (
                                    <Opcao
                                        key={e.id}
                                        selecionado={valor === `e:${e.id}`}
                                        onClick={() => escolher(`e:${e.id}`)}
                                        icone={Building2}
                                    >
                                        {e.nome}
                                    </Opcao>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function Opcao({ children, onClick, selecionado, icone: Icone, destaque = false, recuado = false }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'w-full flex items-center gap-2 px-3 py-2 text-left text-[13px] transition-colors',
                recuado && 'pl-6',
                selecionado
                    ? 'bg-white/[0.08] text-white'
                    : destaque
                        ? 'text-ecf-yellow/90 hover:bg-ecf-yellow/[0.07]'
                        : 'text-white/70 hover:bg-white/[0.05] hover:text-white',
            )}
        >
            <Icone size={12} className="shrink-0 opacity-60" />
            <span className="flex-1 truncate">{children}</span>
            {selecionado && <Check size={13} className="shrink-0 text-ecf-yellow" />}
        </button>
    );
}
