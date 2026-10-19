<!--
  Round white badge with the department logo.
  If the logo file is missing (e.g. philhealth.png not added yet), shows the initials instead.
-->
<template>
  <span class="agency-badge" :style="{ '--agency': agencyColor(code) }">
    <img v-if="src && !failed" :src="src" alt="" @error="failed = true" />
    <span v-else class="initials">{{ initials }}</span>
  </span>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { AGENCY, agencyColor } from '../lib/agency'

const props = defineProps({ code: String, logo: String, name: String })
const failed = ref(false)
const src = computed(() => AGENCY[props.code]?.logo || props.logo || null)
watch(src, () => (failed.value = false))

const initials = computed(() => {
  if (AGENCY[props.code]?.short) return AGENCY[props.code].short
  const words = (props.name || props.code || '?').replace(/[^A-Za-z ]/g, ' ').split(/\s+/).filter(Boolean)
  return (words.length > 1 ? words.map((w) => w[0]).join('') : words[0]?.slice(0, 2) || '?').slice(0, 3).toUpperCase()
})
</script>

<style scoped>
.agency-badge {
  flex: none;
  display: grid;
  place-items: center;
  aspect-ratio: 1;
  border-radius: 50%;
  background: #fff;
  overflow: hidden;
  container-type: inline-size;
}
img { width: 80%; height: 80%; object-fit: contain; }
.initials { font: 700 38cqw/1 var(--display); color: var(--agency); letter-spacing: 0.02em; }
</style>
