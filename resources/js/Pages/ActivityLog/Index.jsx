import AppLayout from '@/Layouts/AppLayout';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Search, Filter, ChevronLeft, ChevronRight, Clock, User, Database, LogIn, LogOut, Plus, Pencil, Trash2 } from 'lucide-react';

const EVENT_LABELS = {
    created: { label: 'Criado',     color: 'bg-green-100 text-green-800',  icon: Plus },
    updated: { label: 'Atualizado', color: 'bg-blue-100 text-blue-800',    icon: Pencil },
    deleted: { label: 'Excluído',   color: 'bg-red-100 text-red-800',      icon: Trash2 },
    login:   { label: 'Login',      color: 'bg-purple-100 text-purple-800', icon: LogIn },
    logout:  { label: 'Logout',     color: 'bg-gray-100 text-gray-700',    icon: LogOut },
};

function EventBadge({ event }) {
    const cfg = EVENT_LABELS[event] || { label: event, color: 'bg-gray-100 text-gray-700', icon: Database };
    const Icon = cfg.icon;
    return (
        <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ${cfg.color}`}>
            <Icon size={11} />
            {cfg.label}
        </span>
    );
}

function PropertiesDetail({ properties }) {
    if (!properties) return null;

    const attrs = properties.attributes || {};
    const old   = properties.old || {};
    const diffKeys = [...new Set([...Object.keys(attrs), ...Object.keys(old)])];

    // props extras além de attributes/old (ex: ip, empresa, user_agent)
    const extraKeys = Object.keys(properties).filter(k => k !== 'attributes' && k !== 'old');

    if (diffKeys.length === 0 && extraKeys.length === 0) return null;

    const formatVal = (v) => {
        if (v === null || v === undefined) return '—';
        if (typeof v === 'boolean') return v ? 'sim' : 'não';
        if (typeof v === 'object') return JSON.stringify(v);
        return String(v);
    };

    return (
        <div className="mt-2 text-xs bg-gray-50 rounded p-2 space-y-1">
            {/* Diff de campos */}
            {diffKeys.map(key => (
                <div key={key} className="flex flex-wrap gap-1 items-center">
                    <span className="font-medium text-gray-600 min-w-[110px]">{key}:</span>
                    {old[key] !== undefined && (
                        <span className="line-through text-red-500">{formatVal(old[key])}</span>
                    )}
                    {old[key] !== undefined && attrs[key] !== undefined && (
                        <span className="text-gray-400">→</span>
                    )}
                    {attrs[key] !== undefined && (
                        <span className={old[key] !== undefined ? 'text-green-700' : 'text-gray-700'}>
                            {formatVal(attrs[key])}
                        </span>
                    )}
                </div>
            ))}
            {/* Propriedades extras (ip, empresa, etc.) */}
            {extraKeys.length > 0 && diffKeys.length > 0 && (
                <hr className="border-gray-200 my-1" />
            )}
            {extraKeys.map(key => (
                <div key={key} className="flex flex-wrap gap-1 items-center text-gray-500">
                    <span className="font-medium min-w-[110px]">{key}:</span>
                    <span>{formatVal(properties[key])}</span>
                </div>
            ))}
        </div>
    );
}

export default function ActivityLogIndex({ logs, users, subjectTypes, filters }) {
    const [expanded, setExpanded] = useState(new Set());
    const [form, setForm] = useState(filters || {});

    const toggle = (id) => {
        const next = new Set(expanded);
        next.has(id) ? next.delete(id) : next.add(id);
        setExpanded(next);
    };

    const applyFilters = (e) => {
        e.preventDefault();
        router.get(route('activity-log.index'), form, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        setForm({});
        router.get(route('activity-log.index'), {}, { preserveState: false, replace: true });
    };

    const hasActiveFilters = Object.values(form).some(v => v && v !== '');

    return (
        <AppLayout title="Log de Atividades">
            <div className="p-6 space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Log de Atividades</h1>
                        <p className="text-sm text-gray-500 mt-1">Registro completo de ações dos usuários no sistema</p>
                    </div>
                    <span className="text-sm text-gray-500">{logs.total} registro{logs.total !== 1 ? 's' : ''}</span>
                </div>

                {/* Filtros */}
                <form onSubmit={applyFilters} className="bg-white border border-gray-200 rounded-lg p-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
                        <div className="relative">
                            <Search size={14} className="absolute left-2.5 top-2.5 text-gray-400" />
                            <input
                                type="text"
                                placeholder="Buscar descrição..."
                                value={form.search || ''}
                                onChange={e => setForm(f => ({ ...f, search: e.target.value }))}
                                className="w-full pl-8 pr-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>

                        <select
                            value={form.user_id || ''}
                            onChange={e => setForm(f => ({ ...f, user_id: e.target.value }))}
                            className="text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">Todos os usuários</option>
                            {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
                        </select>

                        <select
                            value={form.subject_type || ''}
                            onChange={e => setForm(f => ({ ...f, subject_type: e.target.value }))}
                            className="text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">Todos os tipos</option>
                            {subjectTypes.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </select>

                        <select
                            value={form.event || ''}
                            onChange={e => setForm(f => ({ ...f, event: e.target.value }))}
                            className="text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">Todos os eventos</option>
                            <option value="created">Criado</option>
                            <option value="updated">Atualizado</option>
                            <option value="deleted">Excluído</option>
                        </select>

                        <input
                            type="date"
                            value={form.date_from || ''}
                            onChange={e => setForm(f => ({ ...f, date_from: e.target.value }))}
                            className="text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            title="De"
                        />

                        <input
                            type="date"
                            value={form.date_to || ''}
                            onChange={e => setForm(f => ({ ...f, date_to: e.target.value }))}
                            className="text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            title="Até"
                        />
                    </div>

                    <div className="flex gap-2 mt-3">
                        <button
                            type="submit"
                            className="flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700"
                        >
                            <Filter size={14} /> Filtrar
                        </button>
                        {hasActiveFilters && (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="px-4 py-2 text-sm text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50"
                            >
                                Limpar
                            </button>
                        )}
                    </div>
                </form>

                {/* Lista de logs */}
                <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
                    {logs.data.length === 0 ? (
                        <div className="py-16 text-center text-gray-500">
                            <Database size={40} className="mx-auto mb-3 text-gray-300" />
                            <p className="font-medium">Nenhum registro encontrado</p>
                            <p className="text-sm mt-1">Ajuste os filtros ou aguarde atividade no sistema</p>
                        </div>
                    ) : (
                        <div className="divide-y divide-gray-100">
                            {logs.data.map(log => (
                                <div
                                    key={log.id}
                                    className="px-4 py-3 hover:bg-gray-50 cursor-pointer"
                                    onClick={() => toggle(log.id)}
                                >
                                    <div className="flex flex-wrap items-start gap-2">
                                        {/* Evento */}
                                        <EventBadge event={log.event} />

                                        {/* Tipo do subject */}
                                        {log.subject_type && log.subject_type !== '—' && (
                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-yellow-50 text-yellow-800 border border-yellow-200">
                                                <Database size={10} />
                                                {log.subject_type}
                                                {log.subject_id ? ` #${log.subject_id}` : ''}
                                            </span>
                                        )}

                                        {/* Descrição */}
                                        <span className="text-sm text-gray-800 flex-1">{log.description}</span>
                                    </div>

                                    <div className="flex flex-wrap gap-4 mt-1.5 text-xs text-gray-500">
                                        <span className="flex items-center gap-1">
                                            <User size={11} />
                                            {log.causer_name}
                                        </span>
                                        <span className="flex items-center gap-1">
                                            <Clock size={11} />
                                            {log.created_at}
                                        </span>
                                    </div>

                                    {/* Detalhes expandíveis */}
                                    {expanded.has(log.id) && (
                                        <PropertiesDetail properties={log.properties} />
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Paginação */}
                {logs.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-gray-500">
                            Página {logs.current_page} de {logs.last_page}
                        </p>
                        <div className="flex gap-2">
                            {logs.prev_page_url && (
                                <button
                                    onClick={() => router.get(logs.prev_page_url)}
                                    className="flex items-center gap-1 px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50"
                                >
                                    <ChevronLeft size={14} /> Anterior
                                </button>
                            )}
                            {logs.next_page_url && (
                                <button
                                    onClick={() => router.get(logs.next_page_url)}
                                    className="flex items-center gap-1 px-3 py-1.5 text-sm border border-gray-300 rounded-md hover:bg-gray-50"
                                >
                                    Próxima <ChevronRight size={14} />
                                </button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
