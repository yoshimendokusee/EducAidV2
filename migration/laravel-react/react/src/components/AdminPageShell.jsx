import React from 'react';

export function AdminPageShell({ title, subtitle, userLabel, children, actions }) {
  return (
    <div className="min-h-screen bg-slate-50 py-6">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mb-8 flex flex-col gap-4 border-b border-slate-200 pb-6 md:flex-row md:items-end md:justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Admin Workspace</p>
            <h1 className="mt-2 text-3xl font-bold text-slate-900">{title}</h1>
            <p className="mt-2 max-w-2xl text-sm text-slate-600">{subtitle}</p>
            {userLabel && <p className="mt-3 text-sm text-slate-500">Signed in as {userLabel}</p>}
          </div>
          {actions ? <div className="flex flex-wrap gap-3">{actions}</div> : null}
        </div>

        <div className="space-y-6">{children}</div>
      </div>
    </div>
  );
}

export function AdminCard({ title, children, className = '' }) {
  return (
    <section className={`rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 ${className}`}>
      {title ? <div className="border-b border-slate-100 px-6 py-4"><h2 className="text-lg font-semibold text-slate-900">{title}</h2></div> : null}
      <div className="px-6 py-5">{children}</div>
    </section>
  );
}

export function AdminLoadingState({ label = 'Loading...' }) {
  return (
    <div className="flex min-h-[45vh] items-center justify-center rounded-2xl bg-white shadow-sm ring-1 ring-slate-200">
      <div className="text-center">
        <div className="inline-block h-12 w-12 animate-spin rounded-full border-b-2 border-blue-600"></div>
        <p className="mt-4 text-sm text-slate-600">{label}</p>
      </div>
    </div>
  );
}

export function AdminErrorState({ error }) {
  return (
    <div className="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-rose-800 shadow-sm">
      <p className="font-medium">Unable to load data</p>
      <p className="mt-1 text-sm">{error}</p>
    </div>
  );
}

export function AdminStatCard({ label, value, accent = 'blue', icon }) {
  const accentStyles = {
    blue: 'bg-blue-50 text-blue-700 ring-blue-100',
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
    yellow: 'bg-amber-50 text-amber-700 ring-amber-100',
    red: 'bg-rose-50 text-rose-700 ring-rose-100',
    purple: 'bg-violet-50 text-violet-700 ring-violet-100',
    slate: 'bg-slate-50 text-slate-700 ring-slate-200',
  };

  return (
    <div className={`rounded-2xl p-5 shadow-sm ring-1 ${accentStyles[accent] || accentStyles.blue}`}>
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-sm font-medium opacity-80">{label}</p>
          <p className="mt-2 text-3xl font-bold">{value}</p>
        </div>
        {icon ? <div className="text-3xl opacity-70">{icon}</div> : null}
      </div>
    </div>
  );
}

export function AdminToolbarButton({ children, className = '', ...props }) {
  return (
    <button
      {...props}
      className={`inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-offset-2 ${className}`}
    >
      {children}
    </button>
  );
}
