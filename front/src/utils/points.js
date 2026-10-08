/** Conversion des points entre l'API et le formulaire. */
let k = 0
const s = (v) => (v === null || v === undefined ? '' : v)

export function newPoint() {
  return { _k: `n${Date.now()}${k++}`, name: '', crs: 'EPSG:4326', x: '', y: '', latitude: '', longitude: '', elevation: '', verticalDatum: '', observedAt: '', observedEnd: '', properties: {} }
}

const dt = (v) => {
  if (!v) return ''
  const str = String(v)
  return /T00:00:00/.test(str) ? str.slice(0, 10) : str.slice(0, 16).replace('T', ' ')
}

export function fromApi(p, keepId = true) {
  const projected = p.srcCrs && p.srcCrs !== 'EPSG:4326'
  return {
    _k: `p${p.id ?? k++}${k++}`,
    id: keepId ? p.id : undefined,
    name: s(p.name),
    crs: projected ? p.srcCrs : 'EPSG:4326',
    x: projected ? s(p.srcX) : '',
    y: projected ? s(p.srcY) : '',
    latitude: s(p.latitude),
    longitude: s(p.longitude),
    elevation: s(p.elevation),
    verticalDatum: s(p.verticalDatum),
    observedAt: dt(p.observedAt),
    observedEnd: dt(p.observedEnd),
    properties: { ...(p.properties || {}) },
  }
}

export function toPayload(p) {
  const projected = p.crs && p.crs !== 'EPSG:4326'
  return {
    id: p.id,
    name: p.name,
    longitude: p.longitude,
    latitude: p.latitude,
    elevation: p.elevation === '' ? null : p.elevation,
    verticalDatum: p.verticalDatum || null,
    observedAt: p.observedAt || null,
    observedEnd: p.observedEnd || null,
    srcCrs: projected ? p.crs : null,
    srcX: projected ? p.x : null,
    srcY: projected ? p.y : null,
    properties: p.properties,
  }
}
