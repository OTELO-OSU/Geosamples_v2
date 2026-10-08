/** Filtres de recherche ↔ paramètres d'API ↔ query string (liens partageables). */
export function emptyFilters() {
  return { q: '', kw: [], from: null, to: null, elevMin: null, elevMax: null, f: {} }
}

export function toParams(f, { omit = [] } = {}) {
  const p = {}
  if (f.q && !omit.includes('q')) p.q = f.q
  if (f.kw?.length && !omit.includes('kw')) p.kw = f.kw.join(',')
  for (const k of ['from', 'to', 'elevMin', 'elevMax']) if (f[k] !== null && f[k] !== '' && f[k] !== undefined && !omit.includes(k)) p[k] = f[k]
  for (const [key, v] of Object.entries(f.f || {})) {
    if (omit.includes(`f.${key}`)) continue
    if (Array.isArray(v)) { if (v.length) p[`f[${key}]`] = v.join(',') }
    else if (v && typeof v === 'object') {
      if (v.min !== null && v.min !== '' && v.min !== undefined) p[`f[${key}][min]`] = v.min
      if (v.max !== null && v.max !== '' && v.max !== undefined) p[`f[${key}][max]`] = v.max
    } else if (v) p[`f[${key}]`] = v
  }
  return p
}

export function fromQuery(q, fields) {
  const f = emptyFilters()
  if (q.q) f.q = String(q.q)
  if (q.kw) f.kw = String(q.kw).split(',').filter(Boolean)
  for (const k of ['from', 'to']) if (q[k]) f[k] = String(q[k])
  for (const k of ['elevMin', 'elevMax']) if (q[k] !== undefined && q[k] !== '') f[k] = Number(q[k])
  for (const fd of fields) {
    if (!fd.filter) continue
    if (fd.filter === 'select' && q[`f[${fd.key}]`]) f.f[fd.key] = String(q[`f[${fd.key}]`]).split(',')
    if (fd.filter === 'text' && q[`f[${fd.key}]`]) f.f[fd.key] = String(q[`f[${fd.key}]`])
    if (fd.filter === 'range') {
      const mn = q[`f[${fd.key}][min]`], mx = q[`f[${fd.key}][max]`]
      if (mn !== undefined || mx !== undefined) f.f[fd.key] = { min: mn !== undefined ? Number(mn) : null, max: mx !== undefined ? Number(mx) : null }
    }
  }
  return f
}

export function activeCount(f) {
  return Object.keys(toParams(f)).length
}
