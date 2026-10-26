import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { AlertTriangle, Building2, ExternalLink, FileText, LayoutDashboard, Search } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Aba "Polos" de /companies (26/10/2026).
 *
 * As empresas dos Polos ficam fora da aba Empresas de propósito (Phase 35) e
 * moram em `mlb_empresas` — quase nenhuma tem cadastro em `companies`. Esta
 * aba só as LISTA; editar fase, polo ou responsável continua no Painel Polos.
 *
 * ### A lista NÃO vem na carga da página
 * É prop `optional` do servidor, pedida aqui quando a aba abre — o mesmo
 * esquema da sub-aba "Acessos do portal". Quem abre /companies e não vem até
 * esta aba não paga a consulta.
 */

// Ordem do funil dos Polos; fase fora desta lista vai para o fim.
const ORDEM_FASES = ['Encaminhar Comercial', 'Aceite no Projeto', 'M0', 'M1', 'M2', 'M3', 'M4'];

const posicaoDaFase = (fase) => {
    const i = ORDEM_FASES.indexOf(fase);
    return i === -1 ? ORDEM_FASES.length : i;
};

// 'AAAA-MM-DD' → 'DD/MM/AAAA', sem passar por Date (evita virar o dia por fuso).
const fmtData = (iso) => (iso ? iso.split('-').reverse().join('/') : null);

const SEM_REGIAO = '__sem_regiao__';

export default function AbaPolos({
    empresas,
    total = 0,
    podeAbrirPainel = false,
    podeAbrirFicha = false,
}) {
    const [busca, setBusca] = useState('');
    const [fase, setFase] = useState('todas');
    const [regiao, setRegiao] = useState('todas');

    // Um contador, não um booleano: se o servidor não mandar a lista, pede de
    // novo uma vez e para — nunca em loop.
    const pedidos = useRef(0);

    useEffect(() => {
        if (empresas !== undefined) { pedidos.current = 0; return; }
        if (pedidos.current >= 2) return;

        pedidos.current += 1;
        router.reload({ only: ['polos_empresas'] });
    }, [empresas]);

    const lista = empresas ?? [];

    const fases = useMemo(() => {
        const contagem = {};
        lista.forEach((e) => {
            const f = e.fase || 'Sem fase';
            contagem[f] = (contagem[f] ?? 0) + 1;
        });

        return Object.entries(contagem)
            .sort(([a], [b]) => posicaoDaFase(a) - posicaoDaFase(b) || a.localeCompare(b))
            .map(([nome, qtd]) => ({ nome, qtd }));
    }, [lista]);

    const regioes = useMemo(
        () => [...new Set(lista.map((e) => e.regiao).filter(Boolean))].sort((a, b) => a.localeCompare(b)),
        [lista],
    );
    const temSemRegiao = lista.some((e) => !e.regiao);

    const visiveis = useMemo(() => {
        const termo = busca.trim().toLowerCase();

        return lista.filter((e) => {
            if (termo && !e.nome.toLowerCase().includes(termo) && !String(e.cust_id ?? '').includes(termo)) return false;
            if (fase !== 'todas' && (e.fase || 'Sem fase') !== fase) return false;
            if (regiao === SEM_REGIAO && e.regiao) return false;
            if (regiao !== 'todas' && regiao !== SEM_REGIAO && e.regiao !== regiao) return false;
            return true;
        });
    }, [lista, busca, fase, regiao]);

    if (empresas === undefined) {
        return <Carregando total={total} />;
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-full max-w-sm">
                    <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                    <Input
                        placeholder="Buscar por nome ou Cust ID..."
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        className="pl-8"
                    />
                </div>

                <select
                    value={regiao}
                    onChange={(e) => setRegiao(e.target.value)}
                    className="h-9 rounded-md border border-white/[0.08] bg-white/[0.03] px-3 text-[13px] text-white/80 focus:outline-none focus:ring-1 focus:ring-ecf-yellow/40"
                    aria-label="Filtrar por região"
                >
                    <option value="todas">Todas as regiões</option>
                    {regioes.map((r) => <option key={r} value={r}>{r}</option>)}
                    {temSemRegiao && <option value={SEM_REGIAO}>Sem região</option>}
                </select>

                {podeAbrirPainel && (
                    <Link
                        href={route('mlb.polos-painel')}
                        className="ml-auto inline-flex items-center gap-1.5 h-9 px-3 rounded-lg bg-white/[0.06] hover:bg-white/[0.11] text-[12.5px] text-white/85 transition-colors whitespace-nowrap"
                    >
                        <LayoutDashboard size={13} /> Abrir Painel Polos
                    </Link>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-1">
                <ChipFase ativo={fase === 'todas'} onClick={() => setFase('todas')} rotulo="Todas" qtd={lista.length} />
                {fases.map((f) => (
                    <ChipFase key={f.nome} ativo={fase === f.nome} onClick={() => setFase(f.nome)} rotulo={f.nome} qtd={f.qtd} />
                ))}
            </div>

            <p className="text-white/40 text-[12px]">
                Empresas ativas do projeto Polos (arquivadas não aparecem). Para editar fase, polo ou responsável, use o Painel Polos.
            </p>

            <Card>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Empresa</TableHead>
                                <TableHead>Cust ID</TableHead>
                                <TableHead>Região</TableHead>
                                <TableHead>Fase</TableHead>
                                <TableHead title="Com ficha de onboarding, o responsável da ficha; sem ficha, o da empresa">Responsável</TableHead>
                                <TableHead title="Data de solicitação registrada na ficha de onboarding">Entrada</TableHead>
                                <TableHead className="text-right">Ações</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visiveis.map((e) => (
                                <TableRow key={e.id}>
                                    <TableCell className="font-medium">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            {e.nome}
                                            {e.problema && (
                                                <span className="inline-flex items-center gap-1 text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-amber-400/12 text-amber-300">
                                                    <AlertTriangle size={10} /> Com problema
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>

                                    <TableCell className="text-[12.5px] text-white/70 font-mono tabular-nums">
                                        {e.cust_id || <span className="text-white/30">—</span>}
                                    </TableCell>

                                    <TableCell className="text-[13px] text-white/75 whitespace-nowrap">
                                        {e.regiao || <span className="text-white/30">—</span>}
                                    </TableCell>

                                    <TableCell>
                                        {e.fase
                                            ? <span className="inline-flex items-center bg-white/10 border border-white/10 text-white/85 text-[11px] px-2 py-0.5 rounded-full whitespace-nowrap">{e.fase}</span>
                                            : <span className="text-white/30">—</span>}
                                    </TableCell>

                                    <TableCell className="text-[13px] text-white/75">
                                        {e.responsavel || <span className="text-white/30">—</span>}
                                    </TableCell>

                                    <TableCell className="text-[13px] text-white/70 tabular-nums whitespace-nowrap">
                                        {fmtData(e.entrada) ?? <span className="text-white/30">—</span>}
                                    </TableCell>

                                    <TableCell className="text-right">
                                        <div className="flex items-center justify-end gap-1.5">
                                            {e.company_id && (
                                                <Link
                                                    href={route('companies.show', e.company_id)}
                                                    className="inline-flex items-center gap-1 h-8 px-2.5 rounded-lg text-[12px] text-white/45 hover:text-white/85 hover:bg-white/[0.05] transition-colors"
                                                    title="Esta empresa também tem cadastro em /companies"
                                                >
                                                    <Building2 size={13} /> Cadastro
                                                </Link>
                                            )}
                                            {podeAbrirFicha && e.implementacao_id && (
                                                <a
                                                    href={route('mlb.implementacao.ficha', e.implementacao_id)}
                                                    target="_blank"
                                                    rel="noopener"
                                                    className="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg bg-white/[0.06] hover:bg-white/[0.11] text-[12.5px] text-white/85 transition-colors whitespace-nowrap"
                                                    title="Abre a ficha de onboarding numa aba nova"
                                                >
                                                    <FileText size={13} /> Ficha <ExternalLink size={11} className="opacity-50" />
                                                </a>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}

                            {visiveis.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={7} className="text-center text-muted-foreground py-10">
                                        <Building2 className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                        {lista.length === 0 ? 'Nenhuma empresa ativa nos Polos' : 'Nenhuma empresa encontrada'}
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    );
}

function ChipFase({ ativo, onClick, rotulo, qtd }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[12px] border transition-colors',
                ativo
                    ? 'bg-ecf-yellow/15 border-ecf-yellow/40 text-ecf-yellow'
                    : 'bg-white/[0.03] border-white/[0.08] text-white/60 hover:text-white/90',
            )}
        >
            {rotulo}
            <span className={cn('text-[11px] tabular-nums', ativo ? 'text-ecf-yellow/70' : 'text-white/35')}>{qtd}</span>
        </button>
    );
}

function Carregando({ total }) {
    return (
        <div className="space-y-3">
            <p className="text-white/40 text-[12px]">Carregando {total > 0 ? `${total} empresas` : 'as empresas'} dos Polos…</p>
            <div className="space-y-2">
                {Array.from({ length: 6 }).map((_, i) => (
                    <div key={i} className="h-10 rounded-lg bg-white/[0.03] animate-pulse" />
                ))}
            </div>
        </div>
    );
}
