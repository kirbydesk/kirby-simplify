<template>
  <div>
    <header class="k-field-header">
      <label class="k-label k-field-label">
        <span class="k-label-text">
          {{ $t("simplify.prompt.field") }}
        </span>
      </label>
      <div style="display: flex; gap: var(--spacing-2); align-items: center">
        <button
          v-if="hasCustomPrompt"
          data-has-icon="true"
          data-size="xs"
          data-variant="filled"
          type="button"
          class="k-button"
          :title="$t('simplify.prompt.reset')"
          @click="$emit('reset')"
        >
          <span class="k-button-icon">
            <svg aria-hidden="true" data-type="undo" class="k-icon">
              <use xlink:href="#icon-undo"></use>
            </svg>
          </span>
        </button>
      </div>
    </header>

    <k-textarea-field
      v-bind="systemPromptField"
      :value="value"
      @input="$emit('update:value', $event)"
    />
  </div>
</template>

<script>
export default {
  name: "PromptTab",
  props: {
    value: {
      type: String,
      default: "",
    },
    saved: {
      type: Object,
      required: true,
    },
    defaults: {
      type: Object,
      required: true,
    },
  },
  computed: {
    systemPromptField() {
      return {
        type: "textarea",
        buttons: false,
        counter: false,
        size: "large",
        font: "monospace",
        help: this.$t("simplify.prompt.help"),
      };
    },
    hasCustomPrompt() {
      return (
        this.saved.ai_system_prompt !== "" &&
        this.saved.ai_system_prompt !== this.defaults.ai_system_prompt
      );
    },
  },
};
</script>
