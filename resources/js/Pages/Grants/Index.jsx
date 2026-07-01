import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { useForm, router } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import { ShieldCheck, Pencil, Trash2, RefreshCw, AlertTriangle, CheckCircle2, Clock, XCircle, Building2, Info, Wifi, WifiOff, RotateCcw } from 'lucide-react';
import { cn } from '@/lib/utils';

const statusColor = {
    active:  'success',
    pending: 'secondary',
    expired: 'destructive',
};
const statusLabel = { active: 'Ativo', pending: 'Pendente', expired: 'Expirado' };

function StatCard({ label, value, color = 'text-white', icon: Icon, alert = false }) {
    return (
        <div className={cn('card-ecf rounded-2xl p-5 flex items-center gap-4', alert && 'border-red-500/30')}>
            <div className={cn('w-10 h-10 rounded-xl flex items-center justify-center shrink-0',
                alert ? 'bg-red-500/10' : 'bg-white/[0.04]')}>
                <Icon size={18} className={alert ? 'text-red-400' : 'text-white/30'} />
            </div>
            <div>
                <p className={cn('font-display font-extrabold text-2xl leading-none', color)}>{value}</p>
                <p className="text-white/40 text-[11px] mt-1">{label}</p>
            </div>
        </div>
    );
}

export default function GrantsIndex({ stats, grants, expiring_soon, no_grant, sync_pending }) {
    const [editOpen, setEditOpen] = useState(false);
    const [regrantOpen, setRegrantOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState('');
    const [syncing, setSyncing] = useState(false);
    const [syncResult, setSyncResult] = useState(null);
    const pollRef = useRef(null);

    const startPolling = () => {
        setSyncing(true);
        const deadline = Date.now() + 5 * 60 * 1000; // 5 minutos máximo
        pollRef.current = setInterval(async () => {
            // Timeout de segurança no frontend
            if (Date.now() > deadline) {
                clearInterval(pollRef.current);
                setSyncing(false);
                setSyncResult({ status: 'done', success: false, error: 'Tempo limite atingido no cliente (5 min). Verifique os logs do servidor.' });
                return;
            }
            try {
                const res  = await fetch(route('grants.sync.status'));
                const data = await res.json();
                if (data.status === 'done') {
                    clearInterval(pollRef.current);
                    setSyncing(false);
                    setSyncResult(data);
                    if (data.success) {
                        router.reload({ only: ['grants', 'stats', 'expiring_soon', 'no_grant'] });
                    }
                }
            } catch { /* continua polling */ }
        }, 3000);
    };

    // Se a página foi carregada com sync_pending (background já rodando), retoma o polling
    useEffect(() => {
        if (sync_pending) startPolling();
        return () => { if (pollRef.current) clearInterval(pollRef.current); };
    }, []);

    const syncNow = () => {
        setSyncResult(null);
        setSyncing(true);
        router.post(route('grants.sync'), {}, {
            onSuccess: startPolling,
            onError:   () => setSyncing(false),
        });
    };

    const editForm = useForm({
        ml_grant_token: '', status: 'active',
        granted_at: '', expires_at: '', regranted_at: '', notes: '',
    });

    const regrantForm = useForm({ ml_grant_token: '', expires_at: '' });

    const openEdit = (g) => {
        setEditing(g);
        editForm.setData({
            ml_grant_token: g.ml_grant_token || '',
            status: g.status,
            granted_at: g.granted_at || '',
            expires_at: g.expires_at || '',
            regranted_at: g.regranted_at || '',
            notes: g.notes || '',
        });
        setEditOpen(true);
    };

    const openRegrant = (g) => {
        setEditing(g);
        regrantForm.reset();
        setRegrantOpen(true);
    };

    const openDetail = (g) => {
        setEditing(g);
        setDetailOpen(true);
    };

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('grants.update', editing.id), { onSuccess: () => setEditOpen(false) });
    };

    const submitRegrant = (e) => {
        e.preventDefault();
        regrantForm.post(route('grants.regrant', editing.id), { onSuccess: () => setRegrantOpen(false) });
    };

    const remove = (id) => {
        if (confirm('Remover este grant?')) router.delete(route('grants.destroy', id));
    };

    const filtered = grants.filter(g =>
        g.company_name.toLowerCase().includes(search.toLowerCase())
    );

    const daysColor = (days) => {
        if (days === null || days === undefined) return 'text-white/30';
        if (days <= 7) return 'text-red-400 font-bold';
        if (days <= 30) return 'text-ecf-yellow';
        return 'text-emerald-400';
    };

    return (
        <AppLayout title="Grants Mercado Livre">
            <div className="space-y-5 max-w-[1200px]">
                {/* Alertas de sync */}
                {syncResult && !syncResult.success && (
                    <div className={cn(
                        'flex items-start gap-3 rounded-2xl border p-4',
                        syncResult.error_ip
                            ? 'bg-orange-500/5 border-orange-500/20'
                            : 'bg-red-500/5 border-red-500/20'
                    )}>
                        {syncResult.error_ip
                            ? <Wifi size={16} className="text-orange-400 mt-0.5 shrink-0" />
                            : <AlertTriangle size={16} className="text-red-400 mt-0.5 shrink-0" />
                        }
                        <div>
                            <p className={cn('text-[13px] font-semibold', syncResult.error_ip ? 'text-orange-300' : 'text-red-300')}>
                                {syncResult.error_ip ? 'IP não autorizado pelo SFTP do Mercado Livre' : 'Erro na sincronização'}
                            </p>
                            <p className="text-white/50 text-[12px] mt-0.5">{syncResult.error}</p>
                            {syncResult.error_ip && (
                                <p className="text-orange-400/70 text-[11px] mt-1">
                                    Troque para a rede WiFi com o IP cadastrado na whitelist e tente novamente.
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {syncResult?.success && (
                    <div className="flex items-center gap-3 rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                        <CheckCircle2 size={16} className="text-emerald-400 shrink-0" />
                        <p className="text-emerald-300 text-[13px]">{syncResult.message}</p>
                    </div>
                )}

                {/* Barra de info + botão sync */}
                <div className="flex items-center justify-between gap-2 text-white/40 text-[12px] px-1">
                    <div className="flex items-center gap-1.5">
                        <Info size={13} />
                        Lista importada do ECF Drive (API HTTP) automaticamente às 03:00.
                    </div>
                    <div className="flex items-center gap-2">
                        {/* Phase 51 W3 — badge "API offline" quando /grants/resumo falha e usa contagem local */}
                        {stats.source === 'local' && (
                            <span
                                className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-400 text-[10px] font-semibold"
                                title="API /grants/resumo indisponível — estatísticas calculadas localmente a partir do banco"
                            >
                                <WifiOff size={10} /> API offline
                            </span>
                        )}
                        <button
                            onClick={syncNow}
                            disabled={syncing}
                            className="flex items-center gap-1.5 h-7 px-3 rounded-lg border border-white/10 text-white/50 hover:text-white hover:border-white/20 text-[12px] font-medium transition-all disabled:opacity-40"
                        >
                            <RotateCcw size={11} className={syncing ? 'animate-spin' : ''} />
                            {syncing ? 'Sincronizando...' : 'Sincronizar Agora'}
                        </button>
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <StatCard label="Empresas totais" value={stats.total_companies} icon={Building2} />
                    <StatCard label="Grants ativos" value={stats.active_grants} color="text-emerald-400" icon={CheckCircle2} />
                    <StatCard label="Expirando em 30d" value={stats.expiring_soon} color="text-ecf-yellow" icon={Clock} alert={stats.expiring_soon > 0} />
                    <StatCard label="Expirados" value={stats.expired_grants} color="text-red-400" icon={XCircle} />
                    <StatCard label="Sem grant" value={stats.no_grant} color="text-white/50" icon={ShieldCheck} />
                </div>

                {/* Phase 51 W3 — Buckets progressivos de expiração (7/15/30/60/90d) — cores RESEARCH §5 */}
                <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <StatCard
                        label="Expira em 7d"
                        value={stats.buckets?.d7 ?? 0}
                        color="text-red-400"
                        icon={AlertTriangle}
                        alert={(stats.buckets?.d7 ?? 0) > 0}
                    />
                    <StatCard
                        label="Expira em 15d"
                        value={stats.buckets?.d15 ?? 0}
                        color="text-orange-400"
                        icon={Clock}
                        alert={(stats.buckets?.d15 ?? 0) > 0}
                    />
                    <StatCard
                        label="Expira em 30d"
                        value={stats.buckets?.d30 ?? 0}
                        color="text-ecf-yellow"
                        icon={Clock}
                    />
                    <StatCard
                        label="Expira em 60d"
                        value={stats.buckets?.d60 ?? 0}
                        color="text-white/60"
                        icon={Clock}
                    />
                    <StatCard
                        label="Expira em 90d"
                        value={stats.buckets?.d90 ?? 0}
                        color="text-white/40"
                        icon={Clock}
                    />
                </div>

                {/* Phase 51 W3 — Divergência ML (informativo, tooltip explicativo) */}
                <div
                    className="grid grid-cols-1 sm:grid-cols-5 gap-3"
                    title="Sellers em BASE_VENDEDORES sem cadastro em ContatosCPP — divergência de cadastro ML"
                >
                    <StatCard
                        label="Divergência ML"
                        value={stats.divergencia_ml ?? '—'}
                        color="text-amber-400"
                        icon={Info}
                    />
                </div>

                {/* Expiring soon alert */}
                {expiring_soon.length > 0 && (
                    <div className="card-ecf rounded-2xl border border-ecf-yellow/20 p-4 space-y-3">
                        <div className="flex items-center gap-2 text-ecf-yellow text-[13px] font-semibold">
                            <AlertTriangle size={15} /> {expiring_soon.length} grant{expiring_soon.length > 1 ? 's' : ''} expirando em breve
                        </div>
                        <div className="grid gap-2">
                            {expiring_soon.map(g => (
                                <div key={g.id} className="flex items-center justify-between text-sm px-3 py-2 rounded-xl bg-ecf-yellow/5 border border-ecf-yellow/10">
                                    <span className="text-white/80 font-medium">{g.company_name}</span>
                                    <span className={cn('text-[12px]', daysColor(g.days_remaining))}>
                                        {g.days_remaining === 0 ? 'Expira hoje!' : `${g.days_remaining}d restantes`}
                                        {g.expires_at && <span className="text-white/30 ml-1">({g.expires_at})</span>}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Table */}
                <div className="card-ecf rounded-2xl overflow-hidden">
                    <div className="flex items-center justify-between px-5 py-4 border-b border-white/[0.06]">
                        <p className="text-white/50 text-sm flex items-center gap-2">
                            <ShieldCheck size={14} className="text-ecf-yellow/60" /> Todos os grants
                        </p>
                        <Input
                            placeholder="Buscar empresa..."
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            className="h-8 w-48 text-[13px]"
                        />
                    </div>

                    <div className="divide-y divide-white/[0.04]">
                        {/* Header */}
                        <div className="grid grid-cols-[1fr_7rem_8rem_8rem_6rem_7rem_7rem_6rem_6rem_6rem_6rem_5rem] gap-3 px-5 py-2.5 text-white/30 text-[11px] font-semibold uppercase tracking-wide">
                            <span>Empresa</span>
                            <span>Status</span>
                            <span>E-mail</span>
                            <span>Telefone</span>
                            <span>Segmento</span>     {/* Phase 20 — via ECF Drive */}
                            <span>Concedido</span>
                            <span>Expira</span>
                            {/* Phase 51 W3 — colunas opcionais (mostra '—' quando NULL) */}
                            <span>Programa</span>
                            <span>Nível</span>
                            <span>Medalha In</span>
                            <span>Medalha Out</span>
                            <span className="text-right">Ações</span>
                        </div>
                        {filtered.map(g => (
                            <div key={g.id} className="grid grid-cols-[1fr_7rem_8rem_8rem_6rem_7rem_7rem_6rem_6rem_6rem_6rem_5rem] gap-3 px-5 py-3.5 items-center hover:bg-white/[0.02] transition-colors">
                                <div>
                                    <span className="text-white font-medium text-[13px]">{g.company_name}</span>
                                    {g.days_remaining !== null && (
                                        <span className={cn('ml-2 text-[11px]', daysColor(g.days_remaining))}>
                                            {g.days_remaining < 0 ? '· expirado' :
                                             g.days_remaining === 0 ? '· hoje' :
                                             `· ${g.days_remaining}d`}
                                        </span>
                                    )}
                                </div>
                                <span><Badge variant={statusColor[g.status]}>{statusLabel[g.status]}</Badge></span>
                                <span className="text-white/50 text-[12px] truncate">{g.ml_email || '—'}</span>
                                <span className="text-white/50 text-[12px]">{g.ml_phone || '—'}</span>
                                <span
                                    className="text-white/50 text-[12px] truncate"
                                    title={g.segmento || ''}
                                >
                                    {g.segmento || '—'}
                                </span>
                                <span className="text-white/50 text-[12px]">{g.granted_at || '—'}</span>
                                <span className="text-white/50 text-[12px]">{g.expires_at || '—'}</span>
                                {/* Phase 51 W3 — colunas opcionais; '—' em text-white/30 quando NULL */}
                                <span
                                    className={cn('text-[12px] truncate', g.programa ? 'text-white/50' : 'text-white/30')}
                                    title={g.programa || ''}
                                >
                                    {g.programa || '—'}
                                </span>
                                <span
                                    className={cn('text-[12px] truncate', g.nivel_solucion ? 'text-white/50' : 'text-white/30')}
                                    title={g.nivel_solucion || ''}
                                >
                                    {g.nivel_solucion || '—'}
                                </span>
                                <span className={cn('text-[12px]', g.medalha_fecha_in ? 'text-white/50' : 'text-white/30')}>
                                    {g.medalha_fecha_in || '—'}
                                </span>
                                <span className={cn('text-[12px]', g.medalha_fecha_out ? 'text-white/50' : 'text-white/30')}>
                                    {g.medalha_fecha_out || '—'}
                                </span>
                                <div className="flex justify-end gap-1">
                                    {g.status === 'active' && (
                                        <button
                                            onClick={() => openRegrant(g)}
                                            title="Regrant"
                                            className="p-1.5 rounded-lg hover:bg-ecf-yellow/10 text-ecf-yellow/60 hover:text-ecf-yellow transition-colors"
                                        >
                                            <RefreshCw size={13} />
                                        </button>
                                    )}
                                    <button
                                        onClick={() => openEdit(g)}
                                        className="p-1.5 rounded-lg hover:bg-white/10 text-white/40 hover:text-white transition-colors"
                                    >
                                        <Pencil size={13} />
                                    </button>
                                    <button
                                        onClick={() => remove(g.id)}
                                        className="p-1.5 rounded-lg hover:bg-red-500/10 text-red-400/40 hover:text-red-400 transition-colors"
                                    >
                                        <Trash2 size={13} />
                                    </button>
                                </div>
                            </div>
                        ))}
                        {filtered.length === 0 && (
                            <div className="py-10 text-center text-white/25 text-sm">
                                <ShieldCheck size={28} className="mx-auto mb-2 opacity-30" />
                                Nenhum grant encontrado
                            </div>
                        )}
                    </div>

                    {/* Empresas sem grant */}
                    {no_grant.length > 0 && (
                        <div className="border-t border-white/[0.06] px-5 py-3">
                            <p className="text-white/30 text-[11px] mb-2">Empresas sem grant cadastrado ({no_grant.length}):</p>
                            <div className="flex flex-wrap gap-1.5">
                                {no_grant.map(c => (
                                    <span key={c.id} className="text-[11px] px-2 py-0.5 rounded-full bg-white/[0.04] text-white/40 border border-white/[0.06]">
                                        {c.name}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Dialog: Editar Grant */}
            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Editar Grant — {editing?.company_name}</DialogTitle>
                        <DialogDescription>Ajuste manual do status ou datas. O sync automático atualiza os demais campos.</DialogDescription>
                    </DialogHeader>
                    {editing && (
                        <form onSubmit={submitEdit} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label>Status</Label>
                                <Select value={editForm.data.status} onValueChange={v => editForm.setData('status', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="pending">Pendente</SelectItem>
                                        <SelectItem value="active">Ativo</SelectItem>
                                        <SelectItem value="expired">Expirado</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1.5">
                                    <Label>Concedido em</Label>
                                    <Input type="date" value={editForm.data.granted_at} onChange={e => editForm.setData('granted_at', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Expira em</Label>
                                    <Input type="date" value={editForm.data.expires_at} onChange={e => editForm.setData('expires_at', e.target.value)} />
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                <Label>Token / Link ML</Label>
                                <Input value={editForm.data.ml_grant_token} onChange={e => editForm.setData('ml_grant_token', e.target.value)} />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Observações</Label>
                                <Textarea value={editForm.data.notes} onChange={e => editForm.setData('notes', e.target.value)} rows={2} />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>Cancelar</Button>
                                <Button type="submit" disabled={editForm.processing}>{editForm.processing ? 'Salvando...' : 'Salvar'}</Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>

            {/* Dialog: Regrant */}
            <Dialog open={regrantOpen} onOpenChange={setRegrantOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Regrant — {editing?.company_name}</DialogTitle>
                        <DialogDescription>Renovar o vínculo com novo token e nova data de expiração.</DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submitRegrant} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Novo Token / Link ML *</Label>
                            <Input value={regrantForm.data.ml_grant_token} onChange={e => regrantForm.setData('ml_grant_token', e.target.value)} required />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Nova data de expiração *</Label>
                            <Input type="date" value={regrantForm.data.expires_at} onChange={e => regrantForm.setData('expires_at', e.target.value)} required />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setRegrantOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={regrantForm.processing || !regrantForm.data.ml_grant_token || !regrantForm.data.expires_at}>
                                {regrantForm.processing ? 'Salvando...' : 'Confirmar Regrant'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
