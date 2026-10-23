<template>
  <div v-if="groups.length">
    <div v-for="g in groups" :key="g.f.key" class="gs-sub">
      <h3 class="gs-sub__title">{{ g.f.label }}</h3>
      <dl v-for="(entries, i) in g.blocks" :key="i" class="gs-meta gs-sub__block">
        <template v-for="e in entries" :key="e.key">
          <dt>{{ e.label }}</dt>
          <dd class="gs-num">{{ e.value }}</dd>
        </template>
      </dl>
    </div>
  </div>
</template>

<script setup>
defineProps({ groups: { type: Array, required: true } })
</script>

<style scoped>
.gs-sub { margin-top: 20px; }
.gs-sub__title { font-size: 0.95rem; margin-bottom: 8px; color: rgb(var(--v-theme-primary)); }
.gs-sub__block { padding-left: 12px; border-left: 2px solid rgb(var(--v-theme-line)); }
.gs-sub__block + .gs-sub__block { margin-top: 12px; }
.gs-num { font-variant-numeric: tabular-nums; }
</style>