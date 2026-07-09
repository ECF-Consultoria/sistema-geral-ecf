import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import {
    Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/Components/ui/table';
import { Link, router, useForm } from '@inertiajs/react';
import { useState, useEffect, useMemo, useRef } from 'react';
import {
    Mail, MessageCircle, AlertTriangle, Search, RefreshCw,
    Check, X, Link2, ExternalLink, Inbox, ShieldAlert,
    ArrowRight, FileText,
} from 'lucide-react';
import { cn, formatDateTime } from '@/lib/utils';

/**
 * Página Nps/EnvioAutomatico — v15.5.
 *
 * Concentra o controle operacional do disparo mensal automático de NPS por
 * canal (email + WhatsApp/Digisac):
 *  - 4 cards de status no topo
 *  - Seção Configurações (toggles + mensagem padrão + IDs default)
 *  - Tabela Mapeamento Digisac (busca + selecionar grupo por empresa)
 *  - Tabela Auditoria (unifica email + digisac, filtro por canal/status)
 *
 * Segue padrão dark do sistema (bg-ecf-card, border-white/[0.08], ecf-yellow).
 */

// ─── Formatação numérica curta ─────────────────────────────────────────────
const fmtNum = (n) => (n == null ? '0' : new Intl.NumberFormat('pt-BR').format(n));

// ─── Toggle switch simples ─────────────────────────────────────────────────
function Toggle({ checked, onChange, label, description, disabled }) {
    return (
        <label className={cn(
            'flex items-start gap-3 rounded-xl border border-white/[0.08] bg-white/[0.02] p-4 transition-colors',
            disabled ? 'opacity-60 cursor-not-allowed' : 'hover:bg-white/[0.04] cursor-pointer',
        )}>
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => !disabled && onChange(!checked)}
                className={cn(
                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors mt-0.5',
                    checked ? 'bg-ecf-yellow' : 'bg-white/10',
                )}
            >
                <span
                    className={cn(
                        'inline-block h-5 w-5 rounded-full bg-white shadow transform transition-transform',
                        checked ? 'translate-x-5' : 'translate-x-0.5',
                    )}
                />
            </button>
            <div className="min-w-0">
                <div className="text-white text-sm font-semibold">{label}</div>
                {description && <div className="text-white/50 text-xs mt-0.5">{description}</div>}
            </div>
        </label>
    );
}

// ─── Cards de status no topo ───────────────────────────────────────────────
function StatsCard({ icon: Icon, label, value, sub, tone = 'default' }) {
    const tones = {
        default: 'text-white',
        good:    'text-emerald-300',
        warn:    'text-amber-300',
        bad:     'text-red-300',
    };
    return (
        <div className="rounded-2xl border border-white/[0.08] bg-ecf-card p-5 flex items-start gap-4">
            <div className="w-10 h-10 rounded-xl bg-white/[0.04] border border-white/[0.08] flex items-center justify-center shrink-0">
                <Icon className="w-5 h-5 text-white/70" />
            </div>
            <div className="min-w-0">
                <div className="text-white/50 text-xs uppercase tracking-wider font-semibold">{label}</div>
                <div className={cn('text-2xl font-bold mt-1', tones[tone])}>{value}</div>
                {sub && <div className="text-white/40 text-xs mt-0.5">{sub}</div>}
            </div>
        </div>
    );
}

// ─── Página principal ─────────────────────────────────────────────────────
export default function EnvioAutomatico({
    config,
    stats,
    empresas_sem_mapeamento_total = 0,
    mapeamentos,
    filtros = {},
    digisac_configurado = false,
}) {
    // ─── Form de configurações ──────────────────────────────────────────
    const { data, setData, patch, processing, errors } = useForm({
        nps_envio_email_ativo:        !!config?.nps_envio_email_ativo,
        nps_envio_digisac_ativo:      !!config?.nps_envio_digisac_ativo,
        nps_digisac_service_id:       config?.nps_digisac_service_id ?? '',
        nps_digisac_user_id:          config?.nps_digisac_user_id ?? '',
        nps_digisac_mensagem_default: config?.nps_digisac_mensagem_default ?? '',
    });

    const submitConfig = (e) => {
        e.preventDefault();
        patch(route('nps.envio-automatico.config.update'), { preserveScroll: true });
    };

    // ─── Filtro de busca da tabela de mapeamento ────────────────────────
    const [busca, setBusca] = useState(filtros.q ?? '');
    const debounceRef = useRef(null);

    useEffect(() => {
        if (busca === (filtros.q ?? '')) return;
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            router.get(
                route('nps.envio-automatico.index'),
                { ...filtros, q: busca, page: 1 },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);
        return () => clearTimeout(debounceRef.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [busca]);

    // Muda de página preservando o filtro de busca.
    const irParaPagina = (pagina) => {
        router.get(
            route('nps.envio-automatico.index'),
            { ...filtros, page: pagina },
            { preserveState: true, preserveScroll: true },
        );
    };

    // ─── Estado do modal de vincular grupo ──────────────────────────────
    const [empresaMapeando, setEmpresaMapeando] = useState(null);
    const [grupos, setGrupos] = useState([]);
    const [buscandoGrupos, setBuscandoGrupos] = useState(false);
    const [erroGrupos, setErroGrupos] = useState('');
    const [grupoSelecionado, setGrupoSelecionado] = useState('');
    const [buscaGrupo, setBuscaGrupo] = useState('');

    const abrirMapeamento = (empresa) => {
        setEmpresaMapeando(empresa);
        setGrupoSelecionado(empresa.digisac_group_contact_id ?? '');
        setBuscaGrupo('');
        setGrupos([]);
        setErroGrupos('');
    };

    const fecharMapeamento = () => {
        setEmpresaMapeando(null);
        setGrupos([]);
        setErroGrupos('');
    };

    const buscarGrupos = async () => {
        setBuscandoGrupos(true);
        setErroGrupos('');
        try {
            const params = new URLSearchParams();
            const serviceId = empresaMapeando?.digisac_service_id || data.nps_digisac_service_id || config?.digisac_env_default_service_id;
            if (serviceId) params.set('service_id', serviceId);
            const url = route('nps.envio-automatico.digisac.grupos') + (params.toString() ? '?' + params.toString() : '');
            const r = await window.axios.get(url);
            setGrupos(r.data?.groups ?? []);
        } catch (err) {
            const msg = err?.response?.data?.message ?? err?.message ?? 'Erro ao consultar Digisac';
            setErroGrupos(msg);
        } finally {
            setBuscandoGrupos(false);
        }
    };

    // Sugestão automática: grupo cujo nome contém o cust_id/nome da empresa
    const gruposFiltrados = useMemo(() => {
        const q = buscaGrupo.trim().toLowerCase();
        if (!q) return grupos;
        return grupos.filter(g => (g.name ?? '').toLowerCase().includes(q));
    }, [grupos, buscaGrupo]);

    const salvarMapeamento = () => {
        if (!empresaMapeando || !grupoSelecionado) return;
        const grupo = grupos.find(g => g.id === grupoSelecionado);
        router.put(
            route('nps.envio-automatico.mapeamento.update', empresaMapeando.id),
            {
                digisac_service_id:          empresaMapeando.digisac_service_id
                                              || data.nps_digisac_service_id
                                              || config?.digisac_env_default_service_id
                                              || null,
                digisac_group_contact_id:    grupoSelecionado,
                digisac_group_name_snapshot: grupo?.name ?? null,
            },
            {
                preserveScroll: true,
                onSuccess: () => fecharMapeamento(),
            },
        );
    };

    const removerMapeamento = (empresa) => {
        if (!confirm(`Remover vínculo Digisac de "${empresa.name}"?`)) return;
        router.delete(
            route('nps.envio-automatico.mapeamento.destroy', empresa.id),
            { preserveScroll: true },
        );
    };

    const dataMapeamentos = mapeamentos?.data ?? [];

    return (
        <AppLayout title="Envio automático de NPS">
            <div className="max-w-[1600px] mx-auto p-6 space-y-6">

                {/* ─── Header ─────────────────────────────────────────── */}
                <header>
                    <p className="text-white/40 text-[11px] uppercase tracking-widest font-semibold">
                        NPS · Operações
                    </p>
                    <h1 className="text-white font-display font-bold text-2xl tracking-tight">
                        Envio automático de NPS
                    </h1>
                    <p className="text-white/50 text-sm mt-1 max-w-2xl">
                        Controle os canais de disparo mensal, mapeie os grupos WhatsApp de cada empresa
                        e acompanhe o histórico dos envios.
                    </p>
                </header>

                {/* Aviso quando digisac ativo mas env não configurado */}
                {data.nps_envio_digisac_ativo && !digisac_configurado && (
                    <div className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 flex items-start gap-3">
                        <ShieldAlert className="w-5 h-5 text-amber-300 shrink-0 mt-0.5" />
                        <div className="text-sm text-amber-200/90">
                            O canal WhatsApp está ativo, mas as credenciais do Digisac
                            (<code className="text-xs">DIGISAC_BASE_URL</code>,
                            <code className="text-xs"> DIGISAC_TOKEN</code>) não estão configuradas
                            no <code className="text-xs">.env</code>. Nenhuma mensagem será enviada até que sejam definidas.
                        </div>
                    </div>
                )}

                {/* ─── Cards de status (3 cards do mês corrente) ───────── */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <StatsCard
                        icon={Mail}
                        label="Email (mês)"
                        value={fmtNum(stats?.email?.enviados)}
                        sub={stats?.email?.falhas ? `${fmtNum(stats.email.falhas)} falha(s)` : 'sem falhas'}
                        tone={stats?.email?.falhas ? 'warn' : 'good'}
                    />
                    <StatsCard
                        icon={MessageCircle}
                        label="WhatsApp (mês)"
                        value={fmtNum(stats?.digisac?.enviados)}
                        sub={
                            stats?.digisac?.falhas
                                ? `${fmtNum(stats.digisac.falhas)} falha(s) · ${fmtNum(stats.digisac.skipped)} sem grupo`
                                : `${fmtNum(stats?.digisac?.skipped ?? 0)} sem grupo mapeado`
                        }
                        tone={stats?.digisac?.falhas ? 'warn' : 'good'}
                    />
                    <StatsCard
                        icon={AlertTriangle}
                        label="Empresas sem grupo"
                        value={fmtNum(empresas_sem_mapeamento_total)}
                        sub="ainda não vinculadas ao Digisac"
                        tone={empresas_sem_mapeamento_total > 0 ? 'warn' : 'good'}
                    />
                </div>

                {/* ─── Ver também: links relacionados ─────────────────── */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Link
                        href={route('nps.configuracao.textos-legado')}
                        className="group rounded-2xl border border-white/[0.08] bg-ecf-card p-5 flex items-start gap-4 hover:border-ecf-yellow/40 hover:bg-white/[0.04] transition-colors"
                    >
                        <div className="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/25 flex items-center justify-center shrink-0">
                            <FileText className="w-5 h-5 text-blue-300" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="text-white font-semibold text-sm">
                                Personalização do email
                            </div>
                            <div className="text-white/50 text-xs mt-0.5">
                                Editar assunto, saudação, corpo e assinatura do email NPS mensal.
                            </div>
                        </div>
                        <ArrowRight className="w-4 h-4 text-white/40 group-hover:text-ecf-yellow shrink-0 mt-1" />
                    </Link>
                    <Link
                        href={route('nps.emails-enviados.index')}
                        className="group rounded-2xl border border-white/[0.08] bg-ecf-card p-5 flex items-start gap-4 hover:border-ecf-yellow/40 hover:bg-white/[0.04] transition-colors"
                    >
                        <div className="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/25 flex items-center justify-center shrink-0">
                            <Inbox className="w-5 h-5 text-emerald-300" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="text-white font-semibold text-sm">
                                Emails enviados
                            </div>
                            <div className="text-white/50 text-xs mt-0.5">
                                Ver histórico completo dos disparos por email, com filtros e detalhes de falha.
                            </div>
                        </div>
                        <ArrowRight className="w-4 h-4 text-white/40 group-hover:text-ecf-yellow shrink-0 mt-1" />
                    </Link>
                </div>

                {/* ─── Configurações ──────────────────────────────────── */}
                <section className="rounded-2xl border border-white/[0.08] bg-ecf-card p-6 space-y-5">
                    <div>
                        <h2 className="text-white font-semibold text-lg">Configuração geral</h2>
                        <p className="text-white/50 text-sm mt-1">
                            Ativar / desativar canais e definir a mensagem padrão do WhatsApp para
                            templates que ainda não têm mensagem própria.
                        </p>
                    </div>

                    <form onSubmit={submitConfig} className="space-y-5">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <Toggle
                                checked={data.nps_envio_email_ativo}
                                onChange={(v) => setData('nps_envio_email_ativo', v)}
                                label="Envio por email"
                                description="Dispara o email mensal para email_cliente da empresa."
                            />
                            <Toggle
                                checked={data.nps_envio_digisac_ativo}
                                onChange={(v) => setData('nps_envio_digisac_ativo', v)}
                                label="Envio por WhatsApp (Digisac)"
                                description="Envia o link do NPS no grupo Digisac mapeado."
                            />
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="text-white/60 text-xs uppercase tracking-wider font-semibold mb-1 block">
                                    Digisac serviceId padrão
                                </label>
                                <Input
                                    value={data.nps_digisac_service_id}
                                    onChange={(e) => setData('nps_digisac_service_id', e.target.value)}
                                    placeholder={config?.digisac_env_default_service_id || 'ex: 3f6a…'}
                                    className="bg-white/[0.03] border-white/[0.08]"
                                />
                                {errors.nps_digisac_service_id && (
                                    <p className="text-red-400 text-xs mt-1">{errors.nps_digisac_service_id}</p>
                                )}
                                <p className="text-white/40 text-[11px] mt-1">
                                    Conexão WhatsApp usada quando a empresa não define outra.
                                </p>
                            </div>
                            <div>
                                <label className="text-white/60 text-xs uppercase tracking-wider font-semibold mb-1 block">
                                    Digisac userId padrão
                                </label>
                                <Input
                                    value={data.nps_digisac_user_id}
                                    onChange={(e) => setData('nps_digisac_user_id', e.target.value)}
                                    placeholder={config?.digisac_env_default_user_id || 'ex: 8b12…'}
                                    className="bg-white/[0.03] border-white/[0.08]"
                                />
                                {errors.nps_digisac_user_id && (
                                    <p className="text-red-400 text-xs mt-1">{errors.nps_digisac_user_id}</p>
                                )}
                                <p className="text-white/40 text-[11px] mt-1">
                                    Usuário Digisac exibido como origem do envio (bot).
                                </p>
                            </div>
                        </div>

                        <div>
                            <label className="text-white/60 text-xs uppercase tracking-wider font-semibold mb-1 block">
                                Mensagem WhatsApp (padrão)
                            </label>
                            <Textarea
                                value={data.nps_digisac_mensagem_default}
                                onChange={(e) => setData('nps_digisac_mensagem_default', e.target.value)}
                                rows={7}
                                className="bg-white/[0.03] border-white/[0.08] font-mono text-sm"
                            />
                            {errors.nps_digisac_mensagem_default && (
                                <p className="text-red-400 text-xs mt-1">{errors.nps_digisac_mensagem_default}</p>
                            )}
                            <p className="text-white/40 text-[11px] mt-1">
                                Placeholders: <code>{'{nome_empresa}'}</code>{' '}
                                <code>{'{mes_referencia}'}</code>{' '}
                                <code>{'{link_nps}'}</code>{' '}
                                <code>{'{nome_estrategista}'}</code>{' '}
                                <code>{'{nome_analista}'}</code>. Templates NPS que definem sua
                                própria mensagem sobrescrevem este texto.
                            </p>
                        </div>

                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={processing}
                                className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90"
                            >
                                {processing ? 'Salvando…' : 'Salvar configurações'}
                            </Button>
                        </div>
                    </form>
                </section>

                {/* ─── Mapeamento Digisac ─────────────────────────────── */}
                <section className="rounded-2xl border border-white/[0.08] bg-ecf-card p-6 space-y-4">
                    <div className="flex items-start justify-between gap-4 flex-wrap">
                        <div>
                            <h2 className="text-white font-semibold text-lg">Mapeamento Digisac por empresa</h2>
                            <p className="text-white/50 text-sm mt-1 max-w-2xl">
                                Vincule cada empresa ao grupo Digisac correto. O <code>contactId</code> do grupo é a fonte
                                de verdade — se o grupo for renomeado no WhatsApp, o vínculo continua funcionando.
                            </p>
                        </div>
                        <div className="flex items-center gap-2 flex-1 md:max-w-md">
                            <Search className="h-4 w-4 text-white/40" />
                            <Input
                                type="text"
                                value={busca}
                                onChange={(e) => setBusca(e.target.value)}
                                placeholder="Buscar empresa ou grupo…"
                                className="bg-white/[0.03] border-white/[0.08] text-sm"
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Empresa</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Grupo Digisac</TableHead>
                                    <TableHead>Mapeado em</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {dataMapeamentos.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center py-10 text-white/40">
                                            Nenhuma empresa encontrada.
                                        </TableCell>
                                    </TableRow>
                                )}

                                {dataMapeamentos.map(emp => {
                                    const mapeado = emp.digisac_group_mapping_status === 'mapped' && emp.digisac_group_contact_id;
                                    return (
                                        <TableRow key={emp.id}>
                                            <TableCell>
                                                <div className="font-medium text-white">{emp.name}</div>
                                                {emp.email_cliente && (
                                                    <div className="text-white/40 text-xs">{emp.email_cliente}</div>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {mapeado
                                                    ? <Badge className="bg-emerald-500/15 text-emerald-300 border-emerald-500/25">Mapeado</Badge>
                                                    : <Badge className="bg-amber-500/15 text-amber-300 border-amber-500/25">Sem mapeamento</Badge>
                                                }
                                            </TableCell>
                                            <TableCell>
                                                {mapeado ? (
                                                    <div>
                                                        <div className="text-sm text-white">
                                                            {emp.digisac_group_name_snapshot ?? '(nome não capturado)'}
                                                        </div>
                                                        <div className="text-white/40 text-[11px] font-mono">
                                                            id: {emp.digisac_group_contact_id}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span className="text-white/40 text-sm">—</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm text-white/60 whitespace-nowrap">
                                                {emp.digisac_group_mapped_at ? formatDateTime(emp.digisac_group_mapped_at) : '—'}
                                            </TableCell>
                                            <TableCell className="text-right whitespace-nowrap">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => abrirMapeamento(emp)}
                                                    className="border-white/[0.08] hover:bg-white/[0.06]"
                                                >
                                                    <Link2 className="w-3.5 h-3.5 mr-1.5" />
                                                    {mapeado ? 'Alterar' : 'Vincular'}
                                                </Button>
                                                {mapeado && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => removerMapeamento(emp)}
                                                        className="ml-2 text-red-300 hover:text-red-200 hover:bg-red-500/10"
                                                    >
                                                        <X className="w-3.5 h-3.5" />
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Paginação */}
                    {mapeamentos?.last_page > 1 && (
                        <div className="flex items-center justify-between text-sm text-white/60">
                            <div>
                                Página {mapeamentos.current_page} de {mapeamentos.last_page}
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={mapeamentos.current_page <= 1}
                                    onClick={() => irParaPagina(mapeamentos.current_page - 1)}
                                    className="border-white/[0.08]"
                                >
                                    Anterior
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={mapeamentos.current_page >= mapeamentos.last_page}
                                    onClick={() => irParaPagina(mapeamentos.current_page + 1)}
                                    className="border-white/[0.08]"
                                >
                                    Próxima
                                </Button>
                            </div>
                        </div>
                    )}
                </section>

            </div>

            {/* ─── Modal de vincular grupo ────────────────────────────── */}
            {empresaMapeando && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
                     onClick={fecharMapeamento}>
                    <div className="w-full max-w-lg rounded-2xl bg-ecf-card border border-white/[0.08] p-6 shadow-2xl"
                         onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-start justify-between mb-4">
                            <div>
                                <p className="text-white/40 text-xs uppercase tracking-wider font-semibold">Vincular grupo Digisac</p>
                                <h3 className="text-white font-semibold text-lg mt-1">{empresaMapeando.name}</h3>
                            </div>
                            <button
                                type="button"
                                onClick={fecharMapeamento}
                                className="text-white/40 hover:text-white"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <div className="space-y-4">
                            <div>
                                <Button
                                    type="button"
                                    onClick={buscarGrupos}
                                    disabled={buscandoGrupos}
                                    className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90"
                                >
                                    <RefreshCw className={cn('w-4 h-4 mr-1.5', buscandoGrupos && 'animate-spin')} />
                                    {buscandoGrupos ? 'Buscando…' : 'Buscar grupos do Digisac'}
                                </Button>
                            </div>

                            {erroGrupos && (
                                <div className="rounded-lg border border-red-500/30 bg-red-500/[0.06] p-3 text-sm text-red-200">
                                    {erroGrupos}
                                </div>
                            )}

                            {grupos.length > 0 && (
                                <>
                                    <div className="flex items-center gap-2">
                                        <Search className="w-4 h-4 text-white/40" />
                                        <Input
                                            value={buscaGrupo}
                                            onChange={(e) => setBuscaGrupo(e.target.value)}
                                            placeholder="Filtrar grupos…"
                                            className="bg-white/[0.03] border-white/[0.08]"
                                        />
                                    </div>
                                    <div className="max-h-[280px] overflow-y-auto rounded-lg border border-white/[0.08]">
                                        {gruposFiltrados.map(g => (
                                            <label
                                                key={g.id}
                                                className={cn(
                                                    'flex items-center gap-3 px-3 py-2 border-b border-white/[0.06] cursor-pointer transition-colors',
                                                    grupoSelecionado === g.id ? 'bg-ecf-yellow/10' : 'hover:bg-white/[0.04]',
                                                )}
                                            >
                                                <input
                                                    type="radio"
                                                    name="grupo"
                                                    checked={grupoSelecionado === g.id}
                                                    onChange={() => setGrupoSelecionado(g.id)}
                                                    className="accent-ecf-yellow"
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-sm text-white truncate">{g.name}</div>
                                                    <div className="text-white/40 text-[10px] font-mono truncate">{g.id}</div>
                                                </div>
                                                {grupoSelecionado === g.id && <Check className="w-4 h-4 text-ecf-yellow" />}
                                            </label>
                                        ))}
                                        {gruposFiltrados.length === 0 && (
                                            <div className="p-4 text-center text-white/40 text-sm">
                                                Nenhum grupo bate com o filtro.
                                            </div>
                                        )}
                                    </div>
                                </>
                            )}
                        </div>

                        <div className="flex items-center justify-end gap-2 mt-6">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={fecharMapeamento}
                                className="text-white/60"
                            >
                                Cancelar
                            </Button>
                            <Button
                                type="button"
                                onClick={salvarMapeamento}
                                disabled={!grupoSelecionado}
                                className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90"
                            >
                                Salvar vínculo
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
