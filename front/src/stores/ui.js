import { defineStore } from 'pinia'

export const useUiStore = defineStore('ui', {
  state: () => ({ snack: { show: false, text: '', color: 'success', timeout: 4000 } }),
  actions: {
    notify(text, color = 'success') { this.snack = { show: true, text, color, timeout: color === 'error' ? 8000 : 4000 } },
    error(text) { this.notify(text, 'error') },
  },
})
