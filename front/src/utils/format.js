import dayjs from 'dayjs'
import 'dayjs/locale/fr'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

dayjs.locale('fr')

export function fmtDate(v, withTime = false) {
  if (!v) return ''
  const d = dayjs(v)
  if (!withTime && d.hour() === 0 && d.minute() === 0 && d.date() === 1 && d.month() === 0) return d.format('YYYY')
  return d.format(withTime && (d.hour() || d.minute()) ? 'D MMM YYYY, HH:mm' : 'D MMM YYYY')
}

export function fmtPeriod(start, end) {
  if (!start) return ''
  if (!end || dayjs(end).isSame(dayjs(start), 'day')) return fmtDate(start)
  return `${fmtDate(start)} – ${fmtDate(end)}`
}

export function fmtNumber(v, digits = 2) {
  if (v === null || v === undefined || v === '') return ''
  const n = Number(v)
  if (!Number.isFinite(n)) return String(v)
  return n.toLocaleString('fr-FR', { maximumFractionDigits: digits })
}

export function fmtElevation(v, datum) {
  if (v === null || v === undefined) return ''
  return `${fmtNumber(v, 1)} m${datum ? ` (${datum})` : ''}`
}

export function fmtBytes(n) {
  if (!n) return '0 o'
  const u = ['o', 'Ko', 'Mo', 'Go']
  const i = Math.min(3, Math.floor(Math.log(n) / Math.log(1024)))
  return `${(n / 1024 ** i).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} ${u[i]}`
}

export function fmtValue(field, v) {
  if (v === null || v === undefined || v === '') return ''
  if (Array.isArray(v)) return v.join(', ')
  if (typeof v === 'boolean') return v ? 'Oui' : 'Non'
  if (field?.type === 'number' || field?.type === 'integer') return fmtNumber(v, 4) + (field.unit ? ` ${field.unit}` : '')
  if (field?.type === 'date') return fmtDate(v)
  return String(v)
}

export function markdown(src) {
  return DOMPurify.sanitize(marked.parse(src || '', { breaks: true }))
}

export const STATUS = {
  draft: { label: 'Brouillon', color: 'grey', icon: 'mdi-pencil-outline' },
  submitted: { label: 'En attente de validation', color: 'amber-darken-2', icon: 'mdi-timer-sand' },
  published: { label: 'Publié', color: 'green-darken-1', icon: 'mdi-check-circle-outline' },
  rejected: { label: 'À revoir', color: 'red-darken-1', icon: 'mdi-alert-circle-outline' },
}

export const LICENSES = ['CC-BY-4.0', 'CC-BY-SA-4.0', 'CC-BY-NC-4.0', 'CC-BY-NC-SA-4.0', 'CC-BY-ND-4.0', 'CC0-1.0', 'Etalab-2.0']
export const LANGUAGES = [{ value: 'fr', title: 'Français' }, { value: 'en', title: 'Anglais' }]
