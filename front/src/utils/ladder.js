const NS = 'http://www.w3.org/2000/svg'

function el(tag, attrs = {}, parent) {
  const n = document.createElementNS(NS, tag)
  for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v)
  if (parent) parent.appendChild(n)
  return n
}

const has = (v) => v !== null && v !== undefined && v !== '' && Number.isFinite(+v)

const COLW = 160

export function buildLadder(observations, { onSelect, fmtDate, fmtElevation, selectedPoint }) {
  const obs = observations.map((o) => {
    const t = o.date ? Date.parse(o.date) : NaN
    return { ...o, t: Number.isNaN(t) ? null : t, z: has(o.elev) ? +o.elev : null }
  })

  // Rows = distinct altitudes (top = highest)
  const alts = [...new Set(obs.map((o) => o.z).filter((v) => v !== null))].sort((a, b) => b - a)
  const levels = obs.some((o) => o.z === null) ? [...alts, null] : alts
  const hasZ = levels.length > 1

  // Columns = distinct dates; observations sharing date AND altitude get side-by-side sub-columns
  const dates = [...new Set(obs.map((o) => o.t))].sort((a, b) => (a ?? -Infinity) - (b ?? -Infinity))
  const slotCount = new Map()
  obs.forEach((o) => {
    const k = `${o.t}|${o.z}`
    o.rank = slotCount.get(k) || 0
    slotCount.set(k, o.rank + 1)
  })
  const dateCols = new Map()
  obs.forEach((o) => dateCols.set(o.t, Math.max(dateCols.get(o.t) || 1, o.rank + 1)))
  const dateStart = new Map()
  let totalCols = 0
  dates.forEach((t) => { dateStart.set(t, totalCols); totalCols += dateCols.get(t) })
  obs.forEach((o) => { o.col = dateStart.get(o.t) + o.rank })
  const hasX = totalCols > 1

  const ROWH = hasX ? 60 : 32
  const label = (o) => [o.name || o.title || o.dataset, o.date && fmtDate(o.date), o.z !== null && fmtElevation(o.z)].filter(Boolean).join(' · ')
  const oneLine = (o) => [o.name, o.date && fmtDate(o.date)].filter(Boolean).join(' · ')

  const root = document.createElement('div')
  root.className = 'gs-ladder'
  const title = document.createElement('strong')
  title.textContent = `${obs.length} observation${obs.length > 1 ? 's' : ''} à cet emplacement`
  root.appendChild(title)

  const maxText = Math.max(0, ...obs.map((o) => oneLine(o).length))
  // Compute SVG size and coordinates
  const iw = hasX ? (totalCols - 1) * COLW : 0 // width of the inner area (between left and right margins)
  const ih = hasZ ? (levels.length - 1) * ROWH : 0 // height of the inner area (between top and bottom margins)
  const L = hasZ ? 200 : 60 // left margin for altitude labels
  const R = 60 // right margin for text labels
  const T = hasX ? 70 : 24 // top margin for date labels
  const B = hasX ? 80 : 20 // bottom margin for date labels
  const W = Math.max(L + iw + R, hasX ? 140 : L + 30 + maxText * 6.5) // total width of the SVG
  const H = T + ih + B // total height of the SVG

  const x = (o) => L + o.col * COLW
  const y = (o) => T + (hasZ ? levels.indexOf(o.z) * ROWH : 0)

  const scroll = document.createElement('div')
  scroll.className = 'gs-ladder__scroll'
  const sv = el('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, role: 'img', 'aria-label': 'Chronologie et altitude des observations' })

  // Vertical rail = altitude
  if (hasZ) el('line', { x1: L, x2: L, y1: T - 8, y2: T + ih + 8, class: 'gs-ladder__rail' }, sv)

  // One horizontal rung per altitude level
  for (const z of hasZ ? levels : [null]) {
    const row = hasZ ? obs.filter((o) => o.z === z) : obs
    const yy = hasZ ? T + levels.indexOf(z) * ROWH : T
    if (hasX) {
      const xmax = Math.max(...row.map(x))
      el('line', { x1: L, x2: xmax, y1: yy, y2: yy, class: 'gs-ladder__rung' }, sv)
    }
    if (hasZ) {
      el('line', { x1: L - 4, x2: L + 4, y1: yy, y2: yy, class: 'gs-ladder__rail' }, sv)
      //altitude altitude
      el('text', { x: L - 20, y: yy + 4, 'text-anchor': 'end', class: 'gs-ladder__label' }, sv).textContent = z === null ? 'n.d.' : fmtElevation(z)
    }
  }

  // One node per observation: point + name + date
  const circles = []
  obs.forEach((o) => {
    const cx = x(o)
    const cy = y(o)
    const g = el('g', { tabindex: 0, role: 'button', class: 'gs-ladder__nodeg' }, sv)
    el('circle', { cx, cy, r: 12, class: 'gs-ladder__hit' }, g)
    const isSel = selectedPoint != null && o.id != null && o.id === selectedPoint
    const c = el('circle', { cx, cy, r: 6, class: `gs-ladder__node${isSel ? ' gs-ladder__node--sel' : ''}` }, g)
    circles.push(c)
    el('title', {}, g).textContent = label(o)

    if (hasX) {
      const above = o.col % 2 === 0
      const nameY = above ? cy - 35 : cy + 33
      const dateY = above ? cy - 21 : cy + 47
      if (o.name) el('text', { x: cx, y: nameY, 'text-anchor': 'middle', class: 'gs-ladder__label' }, g).textContent = o.name
      if (o.date) el('text', { x: cx, y: dateY, 'text-anchor': 'middle', class: 'gs-ladder__label' }, g).textContent = fmtDate(o.date)
    } else {
      const txt = oneLine(o)
      // altitude name
      if (txt) el('text', { x: cx + 20, y: cy + 4, 'text-anchor': 'start', class: 'gs-ladder__label' }, g).textContent = txt
    }

    const go = () => {
      circles.forEach((e) => e.classList.remove('gs-ladder__node--sel'))
      c.classList.add('gs-ladder__node--sel')
      onSelect(o)
    }
    g.addEventListener('click', go)
    g.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') go() })
  })

  scroll.appendChild(sv)
  root.appendChild(scroll)
  return root
}