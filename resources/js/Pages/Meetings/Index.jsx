import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import { useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Plus, Pencil, Trash2, Check, X, CalendarCheck, RefreshCw, Calendar,
    List, ChevronLeft, ChevronRight, Clock, Building2, Edit2, CalendarDays
} from 'lucide-react';
import {
    startOfMonth, endOfMonth, eachDayOfInterval, startOfWeek, endOfWeek,
    format, isSameMonth, isToday, parseISO, isSameDay, getMonth, getYear, addMonths, subMonths
} from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { cn } from '@/lib/utils';

const statusColor = { scheduled: 'secondary', completed: 'success', cancelled: 'destructive' };
const statusLabel = { scheduled: 'Agendada', completed: 'Realizada', cancelled: 'Cancelada' };

// ── Badge de evento no calendário ─────────────────────────────────────────────
function CalEventBadge({ meeting, onClick }) {
    if (meeting.is_google) {
        return (
            <button
                onClick={() => onClick(meeting)}
                className="w-full text-left text-[10px] rounded px-1.5 py-0.5 truncate font-medium transition-opacity hover:opacity-80 bg-blue-500/20 text-blue-300 border border-blue-500/20"
            >
                {meeting.is_all_day ? '●' : format(parseISO(meeting.scheduled_at), 'HH:mm')} {meeting.title}
            </button>
        );
    }

    return (
        <button
            onClick={() => onClick(meeting)}
            className={cn(
                'w-full text-left text-[10px] rounded px-1.5 py-0.5 truncate font-medium transition-opacity hover:opacity-80',
                meeting.status === 'completed' ? 'bg-emerald-500/20 text-emerald-300' :
                meeting.status === 'cancelled' ? 'bg-red-500/20 text-red-300' :
                'bg-ecf-yellow/20 text-ecf-yellow'
            )}
        >
            {format(parseISO(meeting.scheduled_at), 'HH:mm')} {meeting.company_name}
        </button>
    );
}

// ── Vista de Calendário ───────────────────────────────────────────────────────
function CalendarView({ meetings, googleEvents, calMonth, calYear, onMeetingClick, onGoogleClick, onNavigate }) {
    const current  = new Date(calYear, calMonth - 1, 1);
    const monthStart = startOfMonth(current);
    const monthEnd   = endOfMonth(current);
    const gridStart  = startOfWeek(monthStart, { weekStartsOn: 0 });
    const gridEnd    = endOfWeek(monthEnd, { weekStartsOn: 0 });
    const days       = eachDayOfInterval({ start: gridStart, end: gridEnd });
    const WEEKDAYS   = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    const eventsForDay = (day) => {
        const sys = meetings.filter(m => isSameDay(parseISO(m.scheduled_at), day))
            .map(m => ({ ...m, _type: 'system' }));
        const goog = googleEvents.filter(e => isSameDay(parseISO(e.scheduled_at), day))
            .map(e => ({ ...e, _type: 'google' }));
        return [...sys, ...goog].sort((a, b) => a.scheduled_at.localeCompare(b.scheduled_at));
    };

    return (
        <div className="space-y-3">
            {/* Month navigation */}
            <div className="flex items-center justify-between">
                <button
                    onClick={() => onNavigate(-1)}
                    className="p-1.5 rounded-lg text-white/40 hover:text-white hover:bg-white/[0.05] transition-colors"
                >
                    <ChevronLeft size={16} />
                </button>
                <p className="text-white font-semibold capitalize">
                    {format(current, 'MMMM yyyy', { locale: ptBR })}
                </p>
                <button
                    onClick={() => onNavigate(1)}
                    className="p-1.5 rounded-lg text-white/40 hover:text-white hover:bg-white/[0.05] transition-colors"
                >
                    <ChevronRight size={16} />
                </button>
            </div>

            {/* Grid */}
            <div className="rounded-2xl border border-white/[0.07] overflow-hidden">
                <div className="grid grid-cols-7 border-b border-white/[0.07]">
                    {WEEKDAYS.map(d => (
                        <div key={d} className="py-2 text-center text-white/30 text-[11px] font-semibold uppercase tracking-wide">
                            {d}
                        </div>
                    ))}
                </div>

                <div className="grid grid-cols-7">
                    {days.map((day, i) => {
                        const dayEvents = eventsForDay(day);
                        const inMonth = isSameMonth(day, current);
                        const today   = isToday(day);
                        return (
                            <div
                                key={i}
                                className={cn(
                                    'min-h-[80px] p-1.5 border-b border-r border-white/[0.04] last:border-r-0',
                                    !inMonth && 'opacity-30',
                                    today && 'bg-ecf-yellow/[0.04]'
                                )}
                            >
                                <span className={cn(
                                    'inline-flex items-center justify-center w-5 h-5 text-[11px] font-semibold rounded-full mb-1',
                                    today ? 'bg-ecf-yellow text-[#252525]' : 'text-white/50'
                                )}>
                                    {format(day, 'd')}
                                </span>
                                <div className="space-y-0.5">
                                    {dayEvents.slice(0, 3).map(e => (
                                        <CalEventBadge
                                            key={e._type + e.id}
                                            meeting={e}
                                            onClick={e.is_google ? onGoogleClick : onMeetingClick}
                                        />
                                    ))}
                                    {dayEvents.length > 3 && (
                                        <p className="text-white/30 text-[9px] px-1">+{dayEvents.length - 3} mais</p>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>

            {/* Legend */}
            <div className="flex flex-wrap gap-4 text-[11px] text-white/40">
                {[
                    ['bg-ecf-yellow', 'Agendada'],
                    ['bg-emerald-400', 'Realizada'],
                    ['bg-red-400', 'Cancelada'],
                    ['bg-blue-400', 'Google Calendar'],
                ].map(([c, l]) => (
                    <span key={l} className="flex items-center gap-1.5">
                        <span className={cn('w-2 h-2 rounded-full', c)} /> {l}
                    </span>
                ))}
            </div>
        </div>
    );
}

// ── Componente principal ──────────────────────────────────────────────────────
export default function Meetings({ meetings, companies, googleConnected, calendarMeetings = [], googleEvents = [], calMonth, calYear }) {
    const [view, setView] = useState(googleConnected ? 'calendar' : 'list');
    const [open, setOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [selectedMeeting, setSelectedMeeting] = useState(null);
    const [selectedGoogleEvent, setSelectedGoogleEvent] = useState(null);
    const [syncing, setSyncing] = useState(false);

    const currentDate = new Date(calYear, calMonth - 1, 1);

    const { data, setData, post, processing, reset } = useForm({
        company_id: '', scheduled_at: '', notes: '',
    });

    const editForm = useForm({
        status: 'completed', consultant_present: true, mentor_present: true,
        client_present: true, notes: '', scheduled_at: '',
    });

    const openEdit = (m) => {
        setEditing(m);
        editForm.setData({
            status: m.status,
            consultant_present: m.consultant_present,
            mentor_present: m.mentor_present,
            client_present: m.client_present,
            notes: m.notes || '',
            scheduled_at: '',
        });
        setEditOpen(true);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('meetings.store'), { onSuccess: () => { reset(); setOpen(false); } });
    };

    const submitEdit = (e) => {
        e.preventDefault();
        editForm.put(route('meetings.update', editing.id), { onSuccess: () => setEditOpen(false) });
    };

    const removeMeeting = (id) => {
        if (confirm('Remover esta reunião? Esta ação não pode ser desfeita.')) {
            router.delete(route('meetings.destroy', id));
        }
    };

    const syncGoogle = () => {
        setSyncing(true);
        router.post(route('google.sync'), {}, { onFinish: () => setSyncing(false) });
    };

    const navigate = (direction) => {
        const next = direction > 0 ? addMonths(currentDate, 1) : subMonths(currentDate, 1);
        router.get(route('meetings.index'), {
            month: getMonth(next) + 1,
            year: getYear(next),
        }, { preserveState: true });
    };

    const BoolIcon = ({ val }) => val
        ? <Check className="h-4 w-4 text-emerald-400" />
        : <X className="h-4 w-4 text-red-400" />;

    return (
        <AppLayout title="Reuniões">
            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between gap-2 flex-wrap">
                    {googleConnected ? (
                        <div className="flex gap-1 rounded-xl border border-white/[0.08] p-1 bg-white/[0.02]">
                            <button
                                onClick={() => setView('calendar')}
                                className={cn(
                                    'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[13px] font-medium transition-all',
                                    view === 'calendar' ? 'bg-ecf-yellow text-[#252525]' : 'text-white/50 hover:text-white'
                                )}
                            >
                                <Calendar size={13} /> Calendário
                            </button>
                            <button
                                onClick={() => setView('list')}
                                className={cn(
                                    'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[13px] font-medium transition-all',
                                    view === 'list' ? 'bg-ecf-yellow text-[#252525]' : 'text-white/50 hover:text-white'
                                )}
                            >
                                <List size={13} /> Lista
                            </button>
                        </div>
                    ) : (
                        <p className="text-white/40 text-[13px] flex items-center gap-1.5">
                            <List size={13} /> Lista de Reuniões
                        </p>
                    )}

                    <div className="flex gap-2 items-center">
                        {googleConnected ? (
                            <Button variant="outline" size="sm" onClick={syncGoogle} disabled={syncing}>
                                <RefreshCw className={cn('h-3.5 w-3.5 mr-1.5', syncing && 'animate-spin')} />
                                {syncing ? 'Sincronizando...' : 'Sincronizar Google'}
                            </Button>
                        ) : (
                            <Button variant="ghost" size="sm" onClick={() => router.get(route('profile.edit'))}>
                                <Calendar className="h-3.5 w-3.5 mr-1.5 text-white/40" />
                                <span className="text-white/40">Conectar Google</span>
                            </Button>
                        )}
                        <Button onClick={() => setOpen(true)}>
                            <Plus className="h-4 w-4 mr-1" /> Agendar Reunião
                        </Button>
                    </div>
                </div>

                {/* Calendar view */}
                {view === 'calendar' && (
                    <CalendarView
                        meetings={calendarMeetings}
                        googleEvents={googleEvents}
                        calMonth={calMonth}
                        calYear={calYear}
                        onMeetingClick={setSelectedMeeting}
                        onGoogleClick={setSelectedGoogleEvent}
                        onNavigate={navigate}
                    />
                )}

                {/* List view */}
                {view === 'list' && (
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Empresa</TableHead>
                                        <TableHead>Data/Hora</TableHead>
                                        <TableHead>Analista</TableHead>
                                        <TableHead>Mentor</TableHead>
                                        <TableHead>Cliente</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">Ações</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {meetings.data?.map(m => (
                                        <TableRow key={m.id}>
                                            <TableCell className="font-medium">{m.company_name}</TableCell>
                                            <TableCell className="text-sm">{m.scheduled_at}</TableCell>
                                            <TableCell><BoolIcon val={m.consultant_present} /></TableCell>
                                            <TableCell><BoolIcon val={m.mentor_present} /></TableCell>
                                            <TableCell><BoolIcon val={m.client_present} /></TableCell>
                                            <TableCell>
                                                <Badge variant={statusColor[m.status]}>{statusLabel[m.status]}</Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-1">
                                                    <Button size="icon" variant="ghost" onClick={() => openEdit(m)}>
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        size="icon" variant="ghost"
                                                        className="text-red-400 hover:text-red-300 hover:bg-red-500/10"
                                                        onClick={() => removeMeeting(m.id)}
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {(!meetings.data || meetings.data.length === 0) && (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center py-8 text-muted-foreground">
                                                <CalendarCheck className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                                Nenhuma reunião encontrada
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                {/* Paginação lista */}
                {view === 'list' && meetings.last_page > 1 && (
                    <div className="flex justify-center gap-2">
                        {meetings.current_page > 1 && (
                            <Button variant="outline" size="sm" onClick={() => router.get(route('meetings.index'), { page: meetings.current_page - 1 })}>Anterior</Button>
                        )}
                        <span className="text-sm text-muted-foreground self-center">Página {meetings.current_page} de {meetings.last_page}</span>
                        {meetings.current_page < meetings.last_page && (
                            <Button variant="outline" size="sm" onClick={() => router.get(route('meetings.index'), { page: meetings.current_page + 1 })}>Próxima</Button>
                        )}
                    </div>
                )}
            </div>

            {/* Dialog: detalhe de reunião do sistema (clique no calendário) */}
            <Dialog open={!!selectedMeeting} onOpenChange={() => setSelectedMeeting(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Building2 size={16} className="text-ecf-yellow" />
                            {selectedMeeting?.company_name}
                        </DialogTitle>
                    </DialogHeader>
                    {selectedMeeting && (
                        <div className="space-y-3 text-sm">
                            <div className="flex items-center gap-2 text-white/60">
                                <Clock size={14} />
                                {format(parseISO(selectedMeeting.scheduled_at), "dd/MM/yyyy 'às' HH:mm", { locale: ptBR })}
                            </div>
                            <Badge variant={statusColor[selectedMeeting.status]}>
                                {statusLabel[selectedMeeting.status]}
                            </Badge>
                            <div className="grid grid-cols-3 gap-2 pt-1">
                                {[
                                    { label: 'Analista', val: selectedMeeting.consultant_present },
                                    { label: 'Mentor', val: selectedMeeting.mentor_present },
                                    { label: 'Cliente', val: selectedMeeting.client_present },
                                ].map(({ label, val }) => (
                                    <div key={label} className="text-center rounded-lg border border-white/[0.07] p-2">
                                        <p className="text-white/40 text-[10px] mb-1">{label}</p>
                                        {val
                                            ? <Check size={16} className="text-emerald-400 mx-auto" />
                                            : <X size={16} className="text-red-400 mx-auto" />
                                        }
                                    </div>
                                ))}
                            </div>
                            {selectedMeeting.notes && (
                                <p className="text-white/50 text-xs">{selectedMeeting.notes}</p>
                            )}
                            <DialogFooter className="pt-1">
                                <Button size="sm" onClick={() => { openEdit(selectedMeeting); setSelectedMeeting(null); }}>
                                    <Edit2 size={13} className="mr-1.5" /> Editar Presença
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Dialog: evento do Google Calendar (não importado) */}
            <Dialog open={!!selectedGoogleEvent} onOpenChange={() => setSelectedGoogleEvent(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <CalendarDays size={16} className="text-blue-400" />
                            {selectedGoogleEvent?.title}
                        </DialogTitle>
                    </DialogHeader>
                    {selectedGoogleEvent && (
                        <div className="space-y-3 text-sm">
                            <div className="flex items-center gap-2 text-white/60">
                                <Clock size={14} />
                                {selectedGoogleEvent.is_all_day
                                    ? format(parseISO(selectedGoogleEvent.scheduled_at), 'dd/MM/yyyy', { locale: ptBR }) + ' — dia inteiro'
                                    : format(parseISO(selectedGoogleEvent.scheduled_at), "dd/MM/yyyy 'às' HH:mm", { locale: ptBR })
                                }
                            </div>
                            <p className="text-white/40 text-xs rounded-lg border border-blue-500/20 bg-blue-500/10 px-3 py-2">
                                Evento do Google Calendar — não importado como reunião do sistema. Use "Sincronizar Google" para importar os eventos com cliente identificado.
                            </p>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Dialog: Agendar */}
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>Agendar Reunião</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1.5">
                            <Label>Empresa *</Label>
                            <Select value={data.company_id} onValueChange={v => setData('company_id', v)} required>
                                <SelectTrigger><SelectValue placeholder="Selecionar empresa..." /></SelectTrigger>
                                <SelectContent>
                                    {companies.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label>Data e Hora *</Label>
                            <Input type="datetime-local" value={data.scheduled_at} onChange={e => setData('scheduled_at', e.target.value)} required />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Observações</Label>
                            <Textarea value={data.notes} onChange={e => setData('notes', e.target.value)} rows={2} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancelar</Button>
                            <Button type="submit" disabled={processing}>Agendar</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Dialog: Editar presença */}
            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader><DialogTitle>Registrar Presença</DialogTitle></DialogHeader>
                    {editing && (
                        <form onSubmit={submitEdit} className="space-y-4">
                            <p className="text-sm text-muted-foreground">
                                {editing.company_name} — {editing.scheduled_at?.includes('T')
                                    ? format(parseISO(editing.scheduled_at), "dd/MM/yyyy 'às' HH:mm", { locale: ptBR })
                                    : editing.scheduled_at}
                            </p>
                            <div className="space-y-1.5">
                                <Label>Status</Label>
                                <Select value={editForm.data.status} onValueChange={v => editForm.setData('status', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="scheduled">Agendada</SelectItem>
                                        <SelectItem value="completed">Realizada</SelectItem>
                                        <SelectItem value="cancelled">Cancelada</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid grid-cols-3 gap-3">
                                {[
                                    { key: 'consultant_present', label: 'Analista' },
                                    { key: 'mentor_present', label: 'Mentor' },
                                    { key: 'client_present', label: 'Cliente' },
                                ].map(({ key, label }) => (
                                    <label key={key} className="flex flex-col items-center gap-1 cursor-pointer">
                                        <span className="text-xs text-muted-foreground">{label}</span>
                                        <input
                                            type="checkbox"
                                            checked={editForm.data[key]}
                                            onChange={e => editForm.setData(key, e.target.checked)}
                                            className="w-4 h-4"
                                        />
                                    </label>
                                ))}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Observações</Label>
                                <Textarea value={editForm.data.notes} onChange={e => editForm.setData('notes', e.target.value)} rows={2} />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setEditOpen(false)}>Cancelar</Button>
                                <Button type="submit" disabled={editForm.processing}>Salvar</Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
