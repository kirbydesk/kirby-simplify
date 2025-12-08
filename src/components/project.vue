<template>
  <k-grid variant="fields">
    <k-column width="1/1">
      <k-textarea-field
        :label="$t('simplify.project.label')"
        :help="$t('simplify.project.help')"
        :placeholder="placeholder || $t('simplify.project.placeholder')"
        :value="description"
        :buttons="false"
        :counter="false"
        type="textarea"
        size="small"
        font="monospace"
        @input="$emit('update:description', $event)"
      />
    </k-column>
  </k-grid>
</template>

<script>
export default {
  name: "ProjectTab",
  props: {
    description: {
      type: String,
      default: "",
    },
    placeholder: {
      type: String,
      default: "",
    },
    sourceLanguage: {
      type: String,
      default: null,
    },
  },
};
</script>
