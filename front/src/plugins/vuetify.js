import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import { aliases, mdi } from 'vuetify/iconsets/mdi'
import { fr } from 'vuetify/locale'

let saved = 'light'
try {
  const s = localStorage.getItem('gs-theme')
  if (s === 'light' || s === 'dark') saved = s
} catch { /* stockage indisponible */ }

// Neutres communs à tous les sites ; primary / secondary / accent sont remplacés
// par le thème du site au démarrage (cf. applySiteTheme).
export default createVuetify({
  locale: { locale: 'fr', messages: { fr } },
  icons: { defaultSet: 'mdi', aliases, sets: { mdi } },
  defaults: {
    VBtn: { variant: 'flat', rounded: 'md', class: 'text-none' },
    VTextField: { variant: 'outlined', density: 'comfortable', color: 'primary' },
    VTextarea: { variant: 'outlined', density: 'comfortable', color: 'primary' },
    VSelect: { variant: 'outlined', density: 'comfortable', color: 'primary' },
    VAutocomplete: { variant: 'outlined', density: 'comfortable', color: 'primary' },
    VCombobox: { variant: 'outlined', density: 'comfortable', color: 'primary' },
    VCard: { rounded: 'lg', elevation: 0 },
    VChip: { rounded: 'md' },
  },
  theme: {
    defaultTheme: saved,
    themes: {
      light: {
        dark: false,
        colors: {
          background: '#F3F5F4', surface: '#FFFFFF', 'surface-variant': '#E3E8E6',
          primary: '#00616F', secondary: '#3E4A59', accent: '#E07A1F',
          ink: '#1D2A2F', muted: '#5E6B70', line: '#D5DBD8',
          error: '#B3261E', warning: '#9A6700', success: '#2E7D32', info: '#1F5F9E',
        },
      },
      dark: {
        dark: true,
        colors: {
          background: '#141A1C', surface: '#1C2427', 'surface-variant': '#2A3539',
          primary: '#4FB3BF', secondary: '#9DB0BF', accent: '#F0A060',
          ink: '#E6ECEA', muted: '#A2B0B4', line: '#33413F',
          error: '#F2B8B5', warning: '#E3B341', success: '#81C784', info: '#90CAF9',
        },
      },
    },
  },
})

/** Applique les couleurs du site (et une variante éclaircie pour le mode sombre). */
export function applySiteTheme(vuetify, theme) {
  if (!theme) return
  const t = (vuetify.theme ?? vuetify).themes.value
  for (const k of ['primary', 'secondary', 'accent']) {
    if (!theme[k]) continue
    t.light.colors[k] = theme[k]
    t.dark.colors[k] = lighten(theme[k], 0.45)
  }
}

function lighten(hex, amt) {
  const n = parseInt(hex.slice(1), 16)
  const c = [16, 8, 0].map((s) => Math.round(((n >> s) & 255) + (255 - ((n >> s) & 255)) * amt))
  return '#' + c.map((x) => x.toString(16).padStart(2, '0')).join('')
}
