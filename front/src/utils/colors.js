/** Couleurs des points selon map.colorBy (catégoriel ou continu). */
// Ordre catégoriel fixe (palette validée daltonisme, 8 teintes) ; au-delà : « Autres » en gris.
const CATEGORICAL = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948', '#8a8f91']
// Rampe séquentielle mono-teinte (clair → foncé)
const RAMP = ['#b7d3f6', '#6da7ec', '#2a78d6', '#1c5cab', '#104281']

function lerp(a, b, t) {
  const pa = parseInt(a.slice(1), 16), pb = parseInt(b.slice(1), 16)
  const c = [16, 8, 0].map((s) => Math.round(((pa >> s) & 255) * (1 - t) + ((pb >> s) & 255) * t))
  return '#' + c.map((x) => x.toString(16).padStart(2, '0')).join('')
}

export function rampColor(t) {
  if (!Number.isFinite(t)) return '#888888'
  t = Math.max(0, Math.min(1, t))
  const i = Math.min(RAMP.length - 2, Math.floor(t * (RAMP.length - 1)))
  return lerp(RAMP[i], RAMP[i + 1], t * (RAMP.length - 1) - i)
}

/** Construit une échelle à partir des valeurs : {colorOf(v), legend:[{label,color}], kind} */
export function buildScale(values, field, { grouping = true } = {}) {
  const vals = values.filter((v) => v !== null && v !== undefined && v !== '')
  if (!vals.length) return { colorOf: () => null, legend: [], kind: 'none' }
  const numeric = vals.every((v) => Number.isFinite(Number(v))) && !(field && ['select', 'multiselect', 'text', 'boolean'].includes(field.type))
  if (numeric) {
    const nums = vals.map(Number)
    const min = Math.min(...nums), max = Math.max(...nums)
    const span = max - min || 1
    const legend = [0, 0.25, 0.5, 0.75, 1].map((t) => ({ label: (min + t * span).toLocaleString('fr-FR', { maximumFractionDigits: grouping ? 1 : 0, useGrouping: grouping }), color: rampColor(t) }))
    return { colorOf: (v) => (v === null || v === undefined ? null : rampColor((Number(v) - min) / span)), legend, kind: 'ramp' }
  }
  // La couleur suit la valeur (ordre de la liste du champ), jamais son rang dans la vue
  const order = field?.options?.length ? [...field.options] : [...new Set(vals.map(String))].sort((a, b) => a.localeCompare(b, 'fr'))
  const other = CATEGORICAL[CATEGORICAL.length - 1]
  const map = {}
  order.forEach((c, i) => { map[c] = i < CATEGORICAL.length - 1 ? CATEGORICAL[i] : other })
  const present = new Set(vals.map(String))
  const legend = order.filter((c) => present.has(c) && map[c] !== other).map((c) => ({ label: c, color: map[c] }))
  if ([...present].some((c) => (map[c] ?? other) === other)) legend.push({ label: 'Autres', color: other })
  return { colorOf: (v) => (v === null || v === undefined || v === '' ? null : map[v] ?? other), legend, kind: 'categories' }
}
