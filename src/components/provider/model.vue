<template>
  <k-panel-inside>
    <k-view class="k-model-detail-view">
      <k-header>
        {{ model ? model.model : "" }}
        <template #buttons>
          <k-button-group v-if="hasChanges" layout="collapsed">
            <k-button
              icon="undo"
              theme="notice"
              variant="filled"
              size="sm"
              @click="discardChanges"
            >
              {{ $t("discard") }}
            </k-button>

            <k-button
              icon="check"
              theme="notice"
              variant="filled"
              size="sm"
              @click="saveChanges"
            >
              {{ $t("save") }}
            </k-button>
          </k-button-group>
        </template>
      </k-header>

      <k-grid variant="fields">
        <k-column width="1/1" v-if="hasCommunityData">
          <div class="k-field">
            <header class="k-field-header">
              <label class="k-label k-field-label">
                <span class="k-label-text">{{
                  $t("simplify.model.details")
                }}</span>
              </label>
            </header>
            <table class="k-table k-object-field-table simplify-model">
              <tbody>
                <tr>
                  <th data-has-button data-mobile="true">
                    <button type="button">
                      {{ $t("simplify.model.table.status") }}
                    </button>
                  </th>
                  <td data-mobile="true" class="k-table-cell">
                    <span style="padding-inline: var(--table-cell-padding)">{{
                      $t(
                        `simplify.community.status.${
                          model.github_status || "unknown"
                        }`
                      )
                    }}</span>
                  </td>
                </tr>
                <tr>
                  <th data-has-button data-mobile="true">
                    <button type="button">
                      {{ $t("simplify.model.table.quality") }}
                    </button>
                  </th>
                  <td data-mobile="true" class="k-table-cell">
                    <span style="padding-inline: var(--table-cell-padding)">
                      <span
                        v-if="model.github_quality > 0"
                        class="k-quality-stars"
                      >
                        <span class="filled">{{
                          "★".repeat(model.github_quality)
                        }}</span>
                        <span class="empty">{{
                          "☆".repeat(5 - model.github_quality)
                        }}</span>
                      </span>
                      {{
                        $t(
                          `simplify.community.quality.${
                            model.github_quality || 0
                          }`
                        )
                      }}
                    </span>
                  </td>
                </tr>
                <tr>
                  <th data-has-button data-mobile="true">
                    <button type="button">
                      {{ $t("simplify.model.table.temperature") }}
                    </button>
                  </th>
                  <td data-mobile="true" class="k-table-cell">
                    <span style="padding-inline: var(--table-cell-padding)">{{
                      model.github_temperature ? $t("yes") : $t("no")
                    }}</span>
                  </td>
                </tr>
                <tr v-if="model.github_output_token_limit">
                  <th data-has-button data-mobile="true">
                    <button type="button">
                      {{ $t("simplify.model.table.outputTokenLimit") }}
                    </button>
                  </th>
                  <td data-mobile="true" class="k-table-cell">
                    <span style="padding-inline: var(--table-cell-padding)">{{
                      model.github_output_token_limit.toLocaleString()
                    }}</span>
                  </td>
                </tr>
                <tr v-if="modelUrl">
                  <th data-has-button data-mobile="true">
                    <button type="button">
                      {{ $t("simplify.model.table.documentation") }}
                    </button>
                  </th>
                  <td data-mobile="true" class="k-table-cell">
                    <span style="padding-inline: var(--table-cell-padding)">
                      <a
                        style="
                          color: var(--color-blue-600);
                          text-decoration: underline;
                        "
                        :href="modelUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        {{ modelUrl }}
                      </a>
                    </span>
                  </td>
                </tr>
                <tr>
                  <th data-has-button data-mobile="true">
                    <button type="button">
                      {{
                        $t("simplify.model.table.pricing.input", {
                          tokens: (
                            formData.pricing.per_tokens || 1000000
                          ).toLocaleString(),
                        })
                      }}
                    </button>
                  </th>
                  <td data-mobile="true" class="k-table-cell">
                    <div>
                      <k-input
                        type="number"
                        :value="formData.pricing.input"
                        :placeholder="pricingFields.input.placeholder"
                        :step="pricingFields.input.step"
                        :min="pricingFields.input.min"
                        :before="model.provider_currency || 'USD'"
                        @input="formData.pricing.input = $event"
                      />
                    </div>
                  </td>
                </tr>
                <tr>
                  <th data-has-button data-mobile="true">
                    <button type="button">
                      {{
                        $t("simplify.model.table.pricing.output", {
                          tokens: (
                            formData.pricing.per_tokens || 1000000
                          ).toLocaleString(),
                        })
                      }}
                    </button>
                  </th>
                  <td data-mobile="true" class="k-table-cell">
                    <div>
                      <k-input
                        type="number"
                        :value="formData.pricing.output"
                        :placeholder="pricingFields.output.placeholder"
                        :step="pricingFields.output.step"
                        :min="pricingFields.output.min"
                        :before="model.provider_currency || 'USD'"
                        @input="formData.pricing.output = $event"
                      />
                    </div>
                  </td>
                </tr>
                <tr>
                  <th data-has-button data-mobile="true">
                    <button type="button">
                      {{ $t("simplify.model.table.pricing.per_tokens") }}
                    </button>
                  </th>
                  <td data-mobile="true" class="k-table-cell">
                    <div>
                      <k-input
                        type="number"
                        :value="formData.pricing.per_tokens"
                        :placeholder="pricingFields.per_tokens.placeholder"
                        :step="pricingFields.per_tokens.step"
                        before="Tokens"
                        @input="formData.pricing.per_tokens = $event"
                      />
                    </div>
                  </td>
                </tr>
                <tr v-if="providerUrl">
                  <th data-has-button data-mobile="true">
                    <button type="button">
                      {{ $t("simplify.model.table.pricing.link") }}
                    </button>
                  </th>
                  <td data-mobile="true" class="k-table-cell">
                    <span style="padding-inline: var(--table-cell-padding)">
                      <a
                        style="
                          color: var(--color-blue-600);
                          text-decoration: underline;
                        "
                        :href="providerUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        {{ providerUrl }}
                      </a>
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </k-column>
      </k-grid>
    </k-view>
  </k-panel-inside>
</template>

<script>
export default {
  props: {
    providerId: {
      type: String,
      required: true,
    },
    providerData: {
      type: Object,
      default: null,
    },
    tab: {
      type: String,
      default: "settings",
    },
  },
  data() {
    return {
      model: this.providerData || null,
      formData: {
        pricing: {
          input: null,
          output: null,
          per_tokens: null,
        },
      },
      savedData: {
        pricing: {
          input: null,
          output: null,
          per_tokens: null,
        },
      },
    };
  },
  computed: {
    modelUrl() {
      return this.model?.url || null;
    },
    providerUrl() {
      return this.model?.provider_url || null;
    },
    hasCommunityData() {
      return this.model?.github_status !== undefined;
    },
    pricingFields() {
      return {
        input: {
          type: "number",
          step: 0.01,
          min: 0,
          placeholder: "0.00",
        },
        output: {
          type: "number",
          step: 0.01,
          min: 0,
          placeholder: "0.00",
        },
        per_tokens: {
          type: "number",
          step: 100000,
          placeholder: "1000000",
        },
      };
    },
    pricingData: {
      get() {
        return this.formData.pricing;
      },
      set(val) {
        this.formData.pricing = val;
      },
    },
    hasChanges() {
      return (
        JSON.stringify(this.formData.pricing) !==
        JSON.stringify(this.savedData.pricing)
      );
    },
  },
  async mounted() {
    await this.loadModel();

    // Register keyboard shortcut for CMD-S / CTRL-S
    this.handleKeyboardShortcut = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "s") {
        e.preventDefault();
        if (this.hasChanges) {
          this.saveChanges();
        }
      }
    };
    window.addEventListener("keydown", this.handleKeyboardShortcut);
  },
  beforeDestroy() {
    if (this.handleKeyboardShortcut) {
      window.removeEventListener("keydown", this.handleKeyboardShortcut);
    }
  },
  methods: {
    async loadModel() {
      try {
        const response = await this.$api.get(
          `simplify/models/${this.providerId}`
        );

        if (response.success && response.provider) {
          // Convert to plain object
          const plainModel = JSON.parse(JSON.stringify(response.provider));

          this.model = plainModel;

          // Load form data
          this.formData.pricing = {
            input: plainModel.pricing?.input ?? null,
            output: plainModel.pricing?.output ?? null,
            per_tokens: plainModel.pricing?.per_tokens ?? null,
          };

          // Save initial state
          this.savedData = JSON.parse(JSON.stringify(this.formData));
        }
      } catch (error) {
        console.error("Failed to load model:", error);
        const errorMsg = error?.message || this.$t("simplify.model.loadError");
        this.$panel.notification.error(errorMsg);
      }
    },
    async saveChanges() {
      try {
        const response = await this.$api.patch(
          `simplify/models/${this.providerId}`,
          {
            pricing: {
              input: parseFloat(this.formData.pricing.input),
              output: parseFloat(this.formData.pricing.output),
              per_tokens: parseInt(this.formData.pricing.per_tokens),
            },
          }
        );

        if (response.success) {
          this.savedData = JSON.parse(JSON.stringify(this.formData));
          this.$panel.notification.success();
        } else {
          this.$panel.notification.error(
            response.message || this.$t("simplify.save.error")
          );
        }
      } catch (error) {
        console.error("Failed to save model:", error);
        const errorMsg = error?.message || this.$t("simplify.save.error");
        this.$panel.notification.error(errorMsg);
      }
    },
    discardChanges() {
      this.formData = JSON.parse(JSON.stringify(this.savedData));
    },
  },
};
</script>

<style scoped>
.simplify-model .k-model-detail-view .k-header {
  align-items: flex-end;
}
.simplify-model .k-table-cell .k-input {
  border: none;
  padding: 0;
  border-radius: 0;
}
.simplify-model .k-input-before {
  padding-left: 0.75rem !important;
}
.simplify-model .k-table-cell .k-input-element {
  border: none;
  border-radius: 0;
  padding: 0 0.25rem;
}
.simplify-model .k-table-cell {
  padding: 0;
}
.simplify-model .k-table-cell > span {
  display: block;
  padding: var(--table-cell-padding);
}
.simplify-model .k-table-cell > div {
  padding: 0;
}
.simplify-model .k-quality-stars {
  margin-right: 0.5em;
}
.simplify-model .k-quality-stars .empty {
  opacity: 0.3;
}
</style>
