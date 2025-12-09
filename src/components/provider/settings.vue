<template>
  <k-grid variant="fields">
    <k-column width="1/1">
      <div class="k-field">
        <header class="k-field-header">
          <label class="k-label k-field-label">
            <span class="k-label-text">{{ $t("simplify.provider.info") }}</span>
          </label>
        </header>
        <table class="k-table k-object-field-table">
          <tbody>
            <tr v-if="provider.apikey">
              <th data-has-button data-mobile="true">
                <button type="button">
                  {{ $t("simplify.provider.apikey") }}
                </button>
              </th>
              <td data-mobile="true" class="k-table-cell">
                <div class="k-provider-apikey-wrapper">
                  <span
                    style="
                      padding-inline: var(--table-cell-padding);
                      font-family: var(--code-font-family);
                    "
                    >{{ maskedApiKey }}</span
                  >
                  <k-button
                    :icon="apiTestState.icon"
                    :theme="apiTestState.theme"
                    size="xs"
                    @click="testApiKey"
                  >
                    {{ apiTestState.text }}
                  </k-button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
    </k-column>

    <k-column width="1/1" v-if="providerModels.length > 0">
      <div class="k-field">
        <header class="k-field-header">
          <label class="k-label k-field-label">
            <span class="k-label-text">{{
              $t("simplify.provider.models.headline")
            }}</span>
          </label>
        </header>

        <div class="k-collection">
          <div
            class="k-items k-list-items"
            data-layout="list"
            data-size="medium"
          >
            <div
              v-for="model in providerModels"
              :key="model.config_id"
              data-has-image="true"
              data-layout="list"
              class="k-item k-list-item"
            >
              <figure
                v-if="provider && provider.icon"
                class="k-frame k-icon-frame k-item-image"
                style="--fit: cover; --ratio: auto; --back: var(--color-black)"
              >
                <svg
                  aria-hidden="true"
                  :data-type="provider.icon"
                  class="k-icon"
                  style="color: var(--color-gray)"
                >
                  <use :xlink:href="'#icon-' + provider.icon"></use>
                </svg>
              </figure>

              <div class="k-item-content">
                <h3 :title="model.model" class="k-item-title">
                  <a
                    :href="
                      $panel.url(
                        'simplify/providers/' +
                          providerId +
                          '/models/' +
                          model.model
                      )
                    "
                    class="k-link"
                  >
                    <span>{{ model.model }}</span>
                  </a>
                </h3>
              </div>

              <nav class="k-item-options">
                <k-button
                  icon="dots"
                  class="k-item-options-button"
                  @click="toggleModelOptions(model.config_id)"
                />
                <k-dropdown-content
                  :ref="modelDropdownRefKey(model.config_id)"
                  alignx="right"
                >
                  <k-dropdown-item icon="edit" @click="openModel(model)">
                    {{ $t("edit") }}
                  </k-dropdown-item>
                  <hr />
                  <k-dropdown-item icon="trash" @click="deleteModel(model)">
                    {{ $t("delete") }}
                  </k-dropdown-item>
                </k-dropdown-content>
              </nav>
            </div>
          </div>
        </div>
      </div>
    </k-column>
  </k-grid>
</template>

<script>
import costFormatting from "../../mixins/costFormatting.js";

export default {
  name: "SettingsTab",
  mixins: [costFormatting],
  props: {
    provider: {
      type: Object,
      required: true,
    },
    providerId: {
      type: String,
      required: true,
    },
    providerModels: {
      type: Array,
      default: () => [],
    },
  },
  data() {
    return {
      apiTestStatus: null, // null | 'testing' | 'success' | 'error'
    };
  },
  computed: {
    maskedApiKey() {
      if (!this.provider?.apikey) return "–";
      const key = this.provider.apikey;
      if (key.length <= 8) return "••••••••";
      return key.substring(0, 4) + "••••••••" + key.substring(key.length - 4);
    },
    apiTestState() {
      switch (this.apiTestStatus) {
        case "testing":
          return {
            icon: "loader",
            theme: "notice",
            text: this.$t("simplify.test.action.running"),
          };
        case "success":
          return {
            icon: "check",
            theme: "positive",
            text: this.$t("simplify.provider.test.success"),
          };
        case "error":
          return {
            icon: "cancel",
            theme: "negative",
            text: this.$t("simplify.provider.test.error"),
          };
        default:
          return {
            icon: "sparkling",
            theme: "notice",
            text: this.$t("simplify.providers.test.apikey"),
          };
      }
    },
  },
  methods: {
    async testApiKey() {
      this.apiTestStatus = "testing";
      try {
        const response = await this.$api.post(
          `simplify/providers/${this.providerId}/test`,
          { prompt: "Test" }
        );
        this.apiTestStatus = response.success ? "success" : "error";

        if (!response.success) {
          this.$panel.notification.error(
            response.message || this.$t("simplify.provider.test.error")
          );
        }
      } catch (error) {
        this.apiTestStatus = "error";
        this.$panel.notification.error(
          error.message || this.$t("simplify.provider.test.error")
        );
      }

      setTimeout(() => {
        this.apiTestStatus = null;
      }, 5000);
    },
    modelDropdownRefKey(configId) {
      return `modelDropdown-${configId.replace(/\//g, "-")}`;
    },
    toggleModelOptions(configId) {
      const refKey = this.modelDropdownRefKey(configId);
      this.$refs[refKey]?.[0]?.toggle();
    },
    openModel(model) {
      window.location.href = this.$panel.url(
        "simplify/providers/" + this.providerId + "/models/" + model.model
      );
    },
    async openAddModelDialog() {
      try {
        const response = await this.$api.get("simplify/models/available");
        if (!response.success) {
          this.$panel.notification.error(
            this.$t("simplify.models.notification.apiUnavailable")
          );
          return;
        }

        const availableModels = response.models;
        const models = availableModels[this.providerId] || [];
        const alreadyConfigured = this.providerModels.map((m) => m.model);
        const availableModelsList = models.filter(
          (model) => !alreadyConfigured.includes(model)
        );

        let communityStatus = {};
        try {
          const statusResponse = await this.$api.get(
            `simplify/models/community-status/${this.providerId}`
          );
          if (statusResponse.success) {
            communityStatus = statusResponse.status || {};
          }
        } catch (error) {
          console.warn("Failed to load community status:", error);
        }

        const modelOptions = availableModelsList.map((model) => {
          const status = communityStatus[model];
          let label = model;

          if (status && status.recommended) {
            label = `${model} (${this.$t(
              "simplify.models.status.recommended"
            )})`;
          }

          return {
            value: model,
            text: label,
          };
        });

        modelOptions.push({
          value: "custom",
          text: this.$t("simplify.models.dialog.customModel"),
        });

        const fields = {
          model: {
            label: this.$t("simplify.models.dialog.model"),
            type: "select",
            options: modelOptions,
            required: true,
            empty: false,
          },
          model_name: {
            label: this.$t("simplify.models.dialog.modelName"),
            type: "text",
            counter: false,
            required: true,
            help: this.$t("simplify.models.dialog.modelNameHelp"),
            when: { model: "custom" },
          },
        };

        const firstModel =
          availableModelsList.length > 0 ? availableModelsList[0] : null;
        if (firstModel && communityStatus[firstModel]) {
          const status = communityStatus[firstModel];
          fields.model.help = this.formatCommunityStatusHelp(status);
        }

        this.$panel.dialog.open({
          component: "k-form-dialog",
          props: {
            fields,
            value: {
              model: modelOptions.length > 0 ? modelOptions[0].value : null,
            },
            submitButton: this.$t("simplify.models.dialog.submit"),
          },
          on: {
            input: (formValues) => {
              if (formValues.model && formValues.model !== "custom") {
                const status = communityStatus[formValues.model];
                if (status) {
                  fields.model.help = this.formatCommunityStatusHelp(status);
                } else {
                  fields.model.help = "";
                }
              } else {
                fields.model.help = "";
              }
            },
            submit: async (values) => {
              await this.addModel(values);
            },
          },
        });
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.models.notification.apiUnavailable")
        );
      }
    },
    formatCommunityStatusHelp(status) {
      const statusText = this.$t(`simplify.community.status.${status.status}`);
      const qualityText = this.$t(
        `simplify.community.quality.${status.quality}`
      );
      return `${statusText} ${qualityText}`;
    },
    async addModel(values) {
      try {
        const payload = {
          provider_type: this.providerId,
          model: values.model === "custom" ? values.model_name : values.model,
          custom_name: values.model === "custom" ? values.model_name : null,
        };

        const response = await this.$api.post("simplify/models/add", payload);

        if (response.success) {
          window.location.reload();
        } else {
          throw new Error(response.message || "Failed to add model");
        }
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.models.notification.error", {
            message: error.message,
          })
        );
      }
    },
    deleteModel(model) {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.models.delete.confirm", {
            model: model.model,
          }),
          submitButton: this.$t("delete"),
          icon: "trash",
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.delete(
                `simplify/models/${model.config_id}`
              );
              if (response.success) {
                window.location.reload();
              }
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.error.generic", { message: error.message })
              );
            }
          },
        },
      });
    },
  },
};
</script>

<style scoped>
.k-provider-apikey-wrapper {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--spacing-4);
  padding-right: var(--table-cell-padding);
}
</style>
