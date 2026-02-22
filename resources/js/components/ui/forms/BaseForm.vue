<script setup>
const props = defineProps({
  onSubmit: {
    type: Function,
    required: true
  },
  customClass: {
    type: String,
    default: ''
  }
})
</script>

<template>
  <form @submit.prevent="onSubmit" class="base-form space-y-3 bg-gray-100 p-4 rounded" :class="customClass">
    <slot name="title" />
    <slot />
    <slot name="footer" />
  </form>
</template>

<style scoped>
.base-form :deep(.input) {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid #ccc;
  border-radius: 0.375rem;
  font-size: 1rem;
  appearance: textfield;
}

.base-form :deep(.btn) {
  padding: 0.5rem 1rem;
  background-color: #2563eb;
  color: white;
  font-weight: bold;
  border: none;
  border-radius: 0.375rem;
  cursor: pointer;
}

.base-form :deep(.btn:hover) {
  background-color: #1d4ed8;
}
</style>