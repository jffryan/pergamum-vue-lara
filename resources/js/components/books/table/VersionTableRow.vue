<template>
  <div class="grid grid-cols-12">
    <div class="col-span-3 p-2">
      {{ version.format.name }}
    </div>
    <div class="col-span-3 p-2">
      {{ version.page_count }}
    </div>
    <div class="col-span-3 p-2">
      {{ version.audio_runtime }}
    </div>
    <div class="col-span-3 p-2">
      {{ version.nickname }}
    </div>
  </div>
</template>

<script>
export default {
  name: "VersionTableRow",
  props: {
    version: {
      type: Object,
      required: true,
    },
  },
};
</script>
