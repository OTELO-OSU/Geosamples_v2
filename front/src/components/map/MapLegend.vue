<template>
  <div class="gs-legend">
    <div class="gs-legend__title">{{ title }}</div>
    <div v-if="kind === 'ramp'" class="gs-legend__ramp">
      <div class="gs-legend__bar" :style="{ background: `linear-gradient(90deg, ${items.map((i) => i.color).join(',')})` }" />
      <div class="gs-legend__ends"><span>{{ items[0].label }}</span><span>{{ items[items.length - 1].label }}</span></div>
    </div>
    <ul v-else class="gs-legend__cats">
      <li v-for="i in items" :key="i.label"><span :style="{ background: i.color }" />{{ i.label }}</li>
    </ul>
  </div>
</template>

<script setup>
defineProps({ title: String, items: Array, kind: String })
</script>

<style scoped>
.gs-legend { background: rgb(var(--v-theme-surface)); border: 1px solid rgb(var(--v-theme-line)); border-radius: 8px; padding: 8px 12px; font-size: 0.8rem; min-width: 150px; max-width: 220px; }
.gs-legend__title { font-weight: 600; margin-bottom: 4px; }
.gs-legend__bar { height: 8px; border-radius: 4px; }
.gs-legend__ends { display: flex; justify-content: space-between; color: rgb(var(--v-theme-muted)); margin-top: 2px; font-variant-numeric: tabular-nums; }
.gs-legend__cats { list-style: none; padding: 0; margin: 0; display: grid; gap: 2px; }
.gs-legend__cats li { display: flex; align-items: center; gap: 8px; }
.gs-legend__cats span { width: 10px; height: 10px; border-radius: 50%; border: 1px solid rgba(0,0,0,.25); flex: none; }
</style>
