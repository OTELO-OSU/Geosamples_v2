<template>
  <v-app>
    <AppHeader />
    <v-main :class="{ 'gs-main-full': route.meta.fullscreen }">
      <router-view />
    </v-main>
    <v-snackbar v-model="ui.snack.show" :color="ui.snack.color" :timeout="ui.snack.timeout" location="bottom right">
      {{ ui.snack.text }}
      <template #actions><v-btn variant="text" icon="mdi-close" aria-label="Fermer" @click="ui.snack.show = false" /></template>
    </v-snackbar>
  </v-app>
</template>

<script setup>
import { useRoute } from 'vue-router'
import AppHeader from '@/components/layout/AppHeader.vue'
import { useUiStore } from '@/stores/ui'

const route = useRoute()
const ui = useUiStore()
</script>

<style>
.gs-main-full { height: 100dvh; }
</style>
