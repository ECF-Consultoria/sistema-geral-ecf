import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Building2, Clock, KeyRound, LogIn, Search, UserPlus } from 'lucide-react';
import { cn } from '@/lib/utils';
import AcessosDoPortal from '@/Components/Onboarding/Portal/AcessosDoPortal';

/**
 * Aba "Portal do cliente" de /companies (19/10/2026).
 *
 * A porta de entrada da equipe no portal ficava só no menu de cada linha da
 * aba Onboarding — empresa sem onboarding não tinha caminho pela tela, e o
 * portal passou a ser usado sobretudo pelo Mapeamento Estrutural, que não tem
 * nada a ver com onboarding. Aqui ficam as duas coisas do portal: entrar nele
 * e cuidar de quem do cliente tem login.
 *
 * ### Quem aparece
 * A lista chega PRONTA do servidor (`portal_empresas`), com a mesma régua de
 * `PortalEquipeService::podeEntrar()`: admin vê todas as empresas da página,
 * os demais só as da própria carteira. Nada é filtrado por permissão aqui.
 *
 * ### A lista de acessos NÃO vem na carga da página
 * É prop `optional` do servidor: só é montada quando o front pede. Quem abre
 * /companies para ver empresas não paga as consultas do portal. O preço é
 * este efeito — e o esqueleto que {@see AcessosDoPortal} mostra enquanto a
 * resposta não chega.
 */
export default function AbaPortal({
    empresas = [],
    podeGerirPortal = false,
    portalAcessosTotal = 0,
    portalAcessos,
}) {
    const [sub, setSub] = useState(() => {
        const s = new URLSearchParams(window.location.search).get('sub');

        return s === 'acessos' && podeGerirPortal ? 'acessos' : 'empresas';
    });

    // Um contador, não um booleano: `portalAcessos` volta a `undefined` depois
    // de toda escrita (o `back()` do controller é uma visita nova, e visita
    // nova não traz prop `optional`). O efeito precisa poder pedir de novo —
    // mas não infinitamente, se o servidor não mandar.
    const pedidos = useRef(0);

    useEffect(() => {
        if (sub !== 'acessos' || !podeGerirPortal) return;

        if (portalAcessos !== undefined) { pedidos.current = 0; return; }
        if (pedidos.current >= 2) return;

        pedidos.current += 1;
        router.reload({ only: ['portal_acessos'] });
    }, [sub, portalAcessos, podeGerirPortal]);

    // A sub-aba entra na URL para que o link seja compartilhável e para que o
    // `back()` das escritas devolva a pessoa onde ela estava. `replaceState`,
    // não uma visita: trocar de sub-aba não deve bater no servidor.
    const trocar = (destino) => {
        setSub(destino);

        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'portal');
        destino === 'acessos' ? url.searchParams.set('sub', 'acessos') : url.searchParams.delete('sub');
        window.history.replaceState(window.history.state, '', url);
    };

    if (!podeGerirPortal) return <EmpresasDoPortal empresas={empresas} />;

    const SUBS = [
        { key: 'empresas', label: 'Empresas',          icone: Building2, contagem: empresas.length },
        { key: 'acessos',  label: 'Acessos do portal', icone: KeyRound,  contagem: portalAcessosTotal },
    ];

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-1 p-1 rounded-xl bg-white/[0.03] w-fit">
                {SUBS.map((s) => (
                    <button
                        key={s.key}
                        type="button"
                        onClick={() => trocar(s.key)}
                        className={cn(
                            'inline-flex items-center gap-1.5 h-8 px-3 rounded-lg text-[13px] font-medium transition-colors',
                            sub === s.key
                                ? 'bg-white/[0.08] text-white'
                                : 'text-white/40 hover:text-white/75',
                        )}
                    >
                        <s.icone size={14} />
                        {s.label}
                        {s.contagem > 0 && (
                            <span className="text-[11px] text-white/35 tabular-nums">{s.contagem}</span>
                        )}
                    </button>
                ))}
            </div>

            {sub === 'acessos'
                ? <AcessosDoPortal dados={portalAcessos} />
                : <EmpresasDoPortal empresas={empresas} podeGerirPortal />}
        </div>
    );
}

const FILTROS = [
    { key: 'todas',     label: 'Todas' },
    { key: 'com_login', label: 'Com login' },
    { key: 'sem_login', label: 'Sem login' },
];

/** A lista de empresas com a porta de entrada de cada uma. */
function EmpresasDoPortal({ empresas, podeGerirPortal = false }) {
    const [busca, setBusca] = useState('');
    const [filtro, setFiltro] = useState('todas');

    const visiveis = useMemo(() => {
        const termo = busca.trim().toLowerCase();

        return empresas.filter((e) => {
            if (termo && !e.nome.toLowerCase().includes(termo)) return false;
            if (filtro === 'com_login') return e.logins.logins > 0;
            if (filtro === 'sem_login') return e.logins.logins === 0;
            return true;
        });
    }, [empresas, busca, filtro]);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-full max-w-sm">
                    <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                    <Input
                        placeholder="Buscar empresa..."
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        className="pl-8"
                    />
                </div>

                <div className="flex items-center gap-1">
                    {FILTROS.map((f) => (
                        <button
                            key={f.key}
                            type="button"
                            onClick={() => setFiltro(f.key)}
                            className={cn(
                                'inline-flex items-center px-3 py-1 rounded-full text-[12px] border transition-colors',
                                filtro === f.key
                                    ? 'bg-ecf-yellow/15 border-ecf-yellow/40 text-ecf-yellow'
                                    : 'bg-white/[0.03] border-white/[0.08] text-white/60 hover:text-white/90',
                            )}
                        >
                            {f.label}
                        </button>
                    ))}
                </div>
            </div>

            <p className="text-white/40 text-[12px]">
                Entrar no portal abre a mesma tela que o cliente vê, numa aba nova e no seu nome — o que você fizer lá fica registrado como seu.
            </p>

            <Card>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Empresa</TableHead>
                                <TableHead title="Pessoas do cliente com login ativo no portal">Logins do cliente</TableHead>
                                <TableHead title="O login é da pessoa: quem responde por um grupo carimba a mesma data em todas as empresas dele">
                                    Último login
                                </TableHead>
                                <TableHead className="text-right">Ações</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visiveis.map((e) => (
                                <TableRow key={e.id}>
                                    <TableCell className="font-medium">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            {e.nome}
                                            {e.grupo && (
                                                <span
                                                    className="text-[10px] font-medium px-1.5 py-0.5 rounded-full border"
                                                    style={{ borderColor: `${e.grupo.color}55`, backgroundColor: `${e.grupo.color}18`, color: e.grupo.color }}
                                                >
                                                    {e.grupo.name}
                                                </span>
                                            )}
                                            {!e.ativa && (
                                                <span className="text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-white/[0.06] text-white/45">
                                                    Inativa
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>

                                    <TableCell>
                                        <LoginsDoCliente logins={e.logins} />
                                    </TableCell>

                                    <TableCell className="text-[13px] text-white/70 tabular-nums whitespace-nowrap">
                                        {e.logins.ultimo_login ?? <span className="text-white/30">—</span>}
                                    </TableCell>

                                    <TableCell className="text-right">
                                        <div className="flex items-center justify-end gap-1.5">
                                            {podeGerirPortal && (
                                                <Link
                                                    href={route('companies.index', { tab: 'portal', sub: 'acessos', portal_company: e.id })}
                                                    className="inline-flex items-center gap-1 h-8 px-2.5 rounded-lg text-[12px] text-white/45 hover:text-white/85 hover:bg-white/[0.05] transition-colors"
                                                    title="Cadastrar ou gerenciar o login do cliente nesta empresa"
                                                >
                                                    <UserPlus size={13} /> {e.logins.logins > 0 ? 'Logins' : 'Dar acesso'}
                                                </Link>
                                            )}
                                            <a
                                                href={route('companies.portal.abrir', e.id)}
                                                target="_blank"
                                                rel="noopener"
                                                className="inline-flex items-center gap-1.5 h-8 px-3 rounded-lg bg-white/[0.06] hover:bg-white/[0.11] text-[12.5px] text-white/85 transition-colors whitespace-nowrap"
                                                title="Abre o portal desta empresa no seu nome — fica registrado"
                                            >
                                                <LogIn size={13} /> Entrar no portal
                                            </a>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}

                            {visiveis.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={4} className="text-center text-muted-foreground py-10">
                                        <Building2 className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                        {empresas.length === 0
                                            ? 'Nenhuma empresa da sua carteira para abrir no portal'
                                            : 'Nenhuma empresa encontrada'}
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

/**
 * "Nunca entrou" tem selo próprio pelo mesmo motivo da sub-aba de acessos:
 * separa "convidei e a pessoa não usou" de "usou e parou".
 */
function LoginsDoCliente({ logins }) {
    if (logins.logins === 0) {
        return <span className="text-white/30 text-[12.5px]">Sem login</span>;
    }

    return (
        <div className="flex items-center gap-1.5 flex-wrap">
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-emerald-400/12 text-emerald-300">
                <KeyRound size={11} /> {logins.logins} {logins.logins === 1 ? 'pessoa' : 'pessoas'}
            </span>
            {logins.nunca_entrou > 0 && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-amber-400/12 text-amber-300">
                    <Clock size={11} /> {logins.nunca_entrou} nunca {logins.nunca_entrou === 1 ? 'entrou' : 'entraram'}
                </span>
            )}
        </div>
    );
}
