/**
 * Systèmes de coordonnées proposés à la saisie et à l'import.
 * Toutes les positions sont stockées en WGS84 (EPSG:4326) ; la saisie d'origine
 * (système + X/Y) est conservée pour traçabilité.
 */
import proj4 from 'proj4'

const DEFS = {
  'EPSG:2154': '+proj=lcc +lat_0=46.5 +lon_0=3 +lat_1=49 +lat_2=44 +x_0=700000 +y_0=6600000 +ellps=GRS80 +towgs84=0,0,0,0,0,0,0 +units=m +no_defs',
  'EPSG:27572': '+proj=lcc +lat_1=46.8 +lat_0=46.8 +lon_0=0 +k_0=0.99987742 +x_0=600000 +y_0=2200000 +a=6378249.2 +b=6356515 +towgs84=-168,-60,320,0,0,0,0 +pm=paris +units=m +no_defs',
  'EPSG:3163': '+proj=lcc +lat_0=-21.5 +lon_0=166 +lat_1=-20.6666666666667 +lat_2=-22.3333333333333 +x_0=400000 +y_0=300000 +ellps=GRS80 +towgs84=0,0,0,0,0,0,0 +units=m +no_defs',
  'EPSG:23031': '+proj=utm +zone=31 +ellps=intl +towgs84=-87,-98,-121,0,0,0,0 +units=m +no_defs',
  'EPSG:23032': '+proj=utm +zone=32 +ellps=intl +towgs84=-87,-98,-121,0,0,0,0 +units=m +no_defs',
}

export const CRS_OPTIONS = [
  { value: 'EPSG:4326', title: 'WGS84 — degrés décimaux' },
  { value: 'EPSG:2154', title: 'RGF93 / Lambert-93' },
  { value: 'EPSG:27572', title: 'NTF (Paris) / Lambert II étendu' },
  { value: 'EPSG:32631', title: 'WGS84 / UTM 31N' },
  { value: 'EPSG:32632', title: 'WGS84 / UTM 32N' },
  { value: 'EPSG:23031', title: 'ED50 / UTM 31N' },
  { value: 'EPSG:23032', title: 'ED50 / UTM 32N' },
  { value: 'EPSG:3163', title: 'RGNC91-93 / Lambert Nouvelle-Calédonie' },
  { value: 'EPSG:32758', title: 'WGS84 / UTM 58S' },
]

const ALIASES = { WGS84: 'EPSG:4326', 'LAMBERT93': 'EPSG:2154', 'LAMBERT 93': 'EPSG:2154', L93: 'EPSG:2154', 'LAMBERT II': 'EPSG:27572', 'LAMBERT2': 'EPSG:27572' }

export function normalizeCrs(code) {
  if (!code) return 'EPSG:4326'
  let c = String(code).trim().toUpperCase()
  if (ALIASES[c]) return ALIASES[c]
  if (/^\d+$/.test(c)) c = `EPSG:${c}`
  return c
}

function def(code) {
  if (code === 'EPSG:4326') return 'EPSG:4326'
  if (DEFS[code]) return DEFS[code]
  const m = /^EPSG:32([67])(\d{2})$/.exec(code) // UTM WGS84 nord (326xx) / sud (327xx)
  if (m) return `+proj=utm +zone=${+m[2]}${m[1] === '7' ? ' +south' : ''} +datum=WGS84 +units=m +no_defs`
  return null
}

export function isSupported(code) { return def(normalizeCrs(code)) !== null }

const num = (v) => (v === null || v === undefined || v === '' ? NaN : Number(String(v).replace(',', '.').replace(/\s/g, '')))

/** Convertit (x, y) dans `code` en {lon, lat} WGS84. Lève une erreur si impossible. */
export function toWgs84(code, x, y) {
  const c = normalizeCrs(code)
  const d = def(c)
  if (!d) throw new Error(`Système ${code} non pris en charge`)
  const X = num(x), Y = num(y)
  if (!Number.isFinite(X) || !Number.isFinite(Y)) throw new Error('Coordonnées non numériques')
  if (c === 'EPSG:4326') return { lon: X, lat: Y }
  const [lon, lat] = proj4(d, 'EPSG:4326', [X, Y])
  return { lon: Math.round(lon * 1e7) / 1e7, lat: Math.round(lat * 1e7) / 1e7 }
}

export function fromWgs84(code, lon, lat) {
  const c = normalizeCrs(code)
  const d = def(c)
  if (!d || c === 'EPSG:4326') return { x: lon, y: lat }
  const [x, y] = proj4('EPSG:4326', d, [Number(lon), Number(lat)])
  return { x: Math.round(x * 100) / 100, y: Math.round(y * 100) / 100 }
}
