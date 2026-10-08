<template>
  <v-select
    v-if="field.type === 'select'" :model-value="modelValue" :items="field.options" :label="label" :hint="field.help" persistent-hint
    clearable :error-messages="error" @update:model-value="emit('update:modelValue', $event)"
  />
  <v-autocomplete
    v-else-if="field.type === 'multiselect'" :model-value="modelValue || []" :items="field.options" :label="label" :hint="field.help" persistent-hint
    multiple chips closable-chips :error-messages="error" @update:model-value="emit('update:modelValue', $event)"
  />
  <v-switch
    v-else-if="field.type === 'boolean'" :model-value="!!modelValue" :label="label" color="primary" :hint="field.help" persistent-hint
    inset :error-messages="error" @update:model-value="emit('update:modelValue', $event)"
  />
  <v-textarea
    v-else-if="field.type === 'textarea'" :model-value="modelValue" :label="label" :hint="field.help" persistent-hint auto-grow rows="3"
    :error-messages="error" @update:model-value="emit('update:modelValue', $event)"
  />
  <v-text-field
    v-else :model-value="modelValue" :label="label" :hint="field.help" persistent-hint
    :type="['number', 'integer'].includes(field.type) ? 'number' : field.type === 'date' ? 'date' : field.type === 'url' ? 'url' : 'text'"
    :step="field.type === 'integer' ? 1 : 'any'" :suffix="field.unit || undefined"
    :error-messages="error" @update:model-value="emit('update:modelValue', $event)"
  />
</template>

<script setup>
import { computed } from 'vue'
const props = defineProps({ field: Object, modelValue: null, error: String })
const emit = defineEmits(['update:modelValue'])
const label = computed(() => props.field.label + (props.field.required ? ' *' : ''))
</script>
