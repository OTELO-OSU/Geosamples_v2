/**
 * Lecture / écriture des fichiers d'import (CSV, XLSX) dans le navigateur.
 * Les bibliothèques sont chargées à la demande (exceljs est volumineux).
 */
import { toWgs84, normalizeCrs } from './crs'

const norm = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\(.*?\)/g, '').replace(/[^a-z0-9]+/g, ' ').trim()

/** Lit un fichier → { headers: string[], rows: object[] } (clé = en-tête d'origine). */
export async function readTable(file) {
  const name = file.name.toLowerCase()
  if (name.endsWith('.xlsx')) {
    const ExcelJS = (await import('exceljs')).default
    const wb = new ExcelJS.Workbook()
    await wb.xlsx.load(await file.arrayBuffer())
    const ws = wb.worksheets.find((w) => w.state !== 'hidden' && w.name !== 'Aide') || wb.worksheets[0]
    const headers = []
    ws.getRow(1).eachCell({ includeEmpty: true }, (cell, col) => { headers[col - 1] = cellText(cell.value) })
    const rows = []
    ws.eachRow({ includeEmpty: false }, (row, i) => {
      if (i === 1) return
      const o = {}
      headers.forEach((h, c) => { if (h) o[h] = cellText(row.getCell(c + 1).value) })
      rows.push(o)
    })
    return { headers: headers.filter(Boolean), rows }
  }
  const Papa = (await import('papaparse')).default
  const text = await file.text()
  const res = Papa.parse(text.replace(/^﻿/, ''), { header: true, skipEmptyLines: 'greedy', transformHeader: (h) => h.trim() })
  return { headers: res.meta.fields || [], rows: res.data }
}

function cellText(v) {
  if (v === null || v === undefined) return ''
  if (v instanceof Date) return v.toISOString().slice(0, v.getUTCHours() || v.getUTCMinutes() ? 19 : 10)
  if (typeof v === 'object') {
    if (v.richText) return v.richText.map((t) => t.text).join('')
    if (v.text) return String(v.text)
    if (v.result !== undefined) return cellText(v.result)
    if (v.hyperlink) return v.hyperlink
  }
  return String(v).trim()
}

/** Associe automatiquement chaque en-tête du fichier à une colonne du template (clé ou libellé). */
export function autoMapping(headers, columns) {
  const map = {}
  for (const h of headers) {
    const nh = norm(h)
    const col = columns.find((c) => c.key === h || norm(c.key) === nh || norm(c.label) === nh)
    map[h] = col ? col.key : null
  }
  return map
}

/**
 * Applique le mapping et convertit les coordonnées projetées (crs + x + y) en WGS84.
 * @returns {{rows: object[], warnings: string[]}}
 */
export function mapRows(rows, mapping) {
  const warnings = []
  const out = rows.map((r, i) => {
    const o = {}
    for (const [h, key] of Object.entries(mapping)) if (key && r[h] !== undefined && r[h] !== '') o[key] = r[h]
    if ((o.longitude === undefined || o.latitude === undefined) && o.x !== undefined && o.y !== undefined) {
      try {
        const { lon, lat } = toWgs84(o.crs || 'EPSG:4326', o.x, o.y)
        o.longitude = String(lon)
        o.latitude = String(lat)
        o.crs = normalizeCrs(o.crs)
      } catch (e) {
        warnings.push(`Ligne ${i + 2} : ${e.message}`)
      }
    }
    return o
  })
  return { rows: out, warnings }
}

/** Télécharge un template (vide ou pré-rempli) au format xlsx ou csv. */
export async function downloadTable({ columns, rows = [], format = 'xlsx', filename = 'template', title = '', instructions = '' }) {
  if (format === 'csv') {
    const Papa = (await import('papaparse')).default
    const csv = Papa.unparse({ fields: columns.map((c) => c.key), data: rows.map((r) => columns.map((c) => r[c.key] ?? '')) }, { delimiter: ';' })
    return save(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }), `${filename}.csv`)
  }
  const ExcelJS = (await import('exceljs')).default
  const wb = new ExcelJS.Workbook()
  wb.creator = 'GeoSamples'
  const ws = wb.addWorksheet('Données', { views: [{ state: 'frozen', ySplit: 1 }] })
  ws.columns = columns.map((c) => ({ header: c.key, key: c.key, width: Math.max(12, Math.min(40, (c.label || c.key).length + 4)) }))
  const head = ws.getRow(1)
  head.font = { bold: true, color: { argb: 'FFFFFFFF' } }
  columns.forEach((c, i) => {
    const cell = head.getCell(i + 1)
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: c.required ? 'FF7A2E1F' : c.level === 'point' ? 'FF2D5F6E' : 'FF3E4A59' } }
    cell.note = [c.label, c.required ? '(obligatoire)' : '', c.help || '', c.options?.length ? 'Valeurs : ' + c.options.join(' | ') : ''].filter(Boolean).join('\n')
  })
  const data = rows.length ? rows : [Object.fromEntries(columns.map((c) => [c.key, c.example ?? '']))].filter((r) => Object.values(r).some(Boolean))
  data.forEach((r) => ws.addRow(columns.map((c) => r[c.key] ?? '')))
  // Listes déroulantes pour les champs à valeurs contrôlées
  columns.forEach((c, i) => {
    if (c.type === 'select' && c.options?.length && c.options.join(',').length < 250) {
      const letter = ws.getColumn(i + 1).letter
      ws.dataValidations.add(`${letter}2:${letter}5000`, { type: 'list', allowBlank: true, formulae: [`"${c.options.join(',')}"`], showErrorMessage: false })
    }
  })
  const help = wb.addWorksheet('Aide')
  help.columns = [{ header: 'Colonne', key: 'key', width: 22 }, { header: 'Libellé', key: 'label', width: 34 }, { header: 'Niveau', key: 'level', width: 12 }, { header: 'Obligatoire', key: 'req', width: 12 }, { header: 'Aide / valeurs autorisées', key: 'help', width: 80 }]
  help.getRow(1).font = { bold: true }
  if (title) help.insertRow(1, [title])
  if (instructions) help.insertRow(2, [instructions])
  help.addRow({})
  help.addRow({ key: 'Règles', help: 'Une ligne = un point de prélèvement. Les lignes ayant la même « dataset_ref » forment un même jeu de données ; ses métadonnées sont lues sur la première ligne renseignée. « dataset_id » rempli = mise à jour du jeu existant (tous ses points sont remplacés).' })
  columns.forEach((c) => help.addRow({ key: c.key, label: c.label, level: c.level === 'point' ? 'point' : 'jeu', req: c.required ? 'oui' : '', help: [c.help, c.options?.length ? c.options.join(' | ') : ''].filter(Boolean).join(' — ') }))
  const buf = await wb.xlsx.writeBuffer()
  save(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), `${filename}.xlsx`)
}

function save(blob, name) {
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = name
  document.body.appendChild(a)
  a.click()
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove() }, 1000)
}
