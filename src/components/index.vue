<template>
  <k-panel-inside>
    <k-view>
      <k-header>
        {{ title }}
        <template #buttons>
          <k-button-group v-if="tab === 'variants'">
            <k-button
              icon="add"
              variant="filled"
              size="sm"
              @click="openAddVariantDialog"
            >
              {{ $t("simplify.languages.addVariant") }}
            </k-button>
          </k-button-group>
          <k-button-group
            v-if="tab === 'project' && hasProjectChanges"
            layout="collapsed"
          >
            <k-button
              icon="undo"
              theme="notice"
              variant="filled"
              size="sm"
              @click="discardProjectChanges"
            >
              {{ $t("discard") }}
            </k-button>
            <k-button
              icon="check"
              theme="notice"
              variant="filled"
              size="sm"
              @click="saveProjectData"
            >
              {{ $t("save") }}
            </k-button>
          </k-button-group>
          <div
            v-if="tab === 'project' && sourceLanguages.length > 1"
            class="k-view-button k-languages-dropdown"
          >
            <k-button
              icon="translate"
              variant="filled"
              size="sm"
              :dropdown="true"
              @click="$refs.languageDropdown.toggle()"
            >
              {{ currentSourceLanguageName }}
            </k-button>
            <k-dropdown-content
              ref="languageDropdown"
              align-x="end"
              theme="dark"
            >
              <nav class="k-navigate">
                <button
                  v-for="lang in sourceLanguages"
                  :key="lang.code"
                  type="button"
                  data-has-text="true"
                  :aria-current="
                    currentSourceLanguage === lang.code ? 'true' : undefined
                  "
                  :aria-label="lang.name"
                  class="k-dropdown-item k-languages-dropdown-item k-button"
                  @click="switchSourceLanguage(lang.code)"
                >
                  <span class="k-button-text">
                    {{ lang.name }}
                    <span class="k-languages-dropdown-item-info">
                      <span class="k-languages-dropdown-item-code">
                        {{ lang.code.toUpperCase() }}
                      </span>
                    </span>
                  </span>
                </button>
              </nav>
            </k-dropdown-content>
          </div>
        </template>
      </k-header>

      <nav class="k-tabs">
        <k-link
          v-for="tabItem in tabs"
          :key="tabItem.name"
          :to="tabItem.link"
          :title="tabItem.label"
          :aria-label="tabItem.label"
          :aria-current="tab === tabItem.name ? 'true' : undefined"
          data-variant="dimmed"
          data-has-text="true"
          class="k-tabs-button k-button"
        >
          <span class="k-button-icon">
            <k-icon v-if="tabItem.icon" :type="tabItem.icon" />
          </span>
          <span class="k-button-text">{{ tabItem.label }}</span>
          <span
            v-if="tabItem.badge > 0"
            data-theme="notice"
            class="k-button-badge"
          >
            {{ tabItem.badge }}
          </span>
        </k-link>
      </nav>

      <variants-tab
        v-if="tab === 'variants'"
        :variants="data.languageVariants"
        @add-variant="openAddVariantDialog"
        @open-settings="openVariantSettings"
        @delete="openDeleteVariantDialog"
        @assign-provider="openAssignProviderDialog"
      />

      <providers-tab
        v-if="tab === 'providers'"
        :providers="filteredProviders"
      />

      <project-tab
        v-if="tab === 'project'"
        :description="projectDescription"
        :placeholder="projectPlaceholder"
        :source-language="currentSourceLanguage"
        @update:description="updateProjectDescription"
      />
    </k-view>
  </k-panel-inside>
</template>

<script>
import VariantsTab from "./variants.vue";
import ProvidersTab from "./providers.vue";
import ProjectTab from "./project.vue";
import systemCheck from "../mixins/systemCheck.js";

export default {
  mixins: [systemCheck],
  components: {
    VariantsTab,
    ProvidersTab,
    ProjectTab,
  },
  props: {
    title: {
      type: String,
      default: "Simplify",
    },
    tab: {
      type: String,
      default: "variants",
    },
    data: {
      type: Object,
      default: () => ({}),
    },
    config: {
      type: Object,
      default: () => ({}),
    },
  },
  data() {
    return {
      projectDescription: "",
      projectPlaceholder: "",
      currentSourceLanguage: null,
      savedProjectDescription: "",
    };
  },
  computed: {
    filteredProviders() {
      const providers = this.config.providers || {};
      const filtered = {};

      // Filter out providers without API key
      for (const [id, config] of Object.entries(providers)) {
        if (config.apikey && config.apikey.trim() !== "") {
          filtered[id] = config;
        }
      }

      return filtered;
    },
    hasVariants() {
      const variants = this.data.languageVariants || [];
      return variants.length > 0;
    },
    tabs() {
      const tabs = [
        {
          name: "variants",
          label: this.$t("simplify.languages"),
          icon: "translate",
          link: "simplify/variants",
          badge: 0,
        },
        {
          name: "providers",
          label: this.$t("simplify.providers"),
          icon: "sparkling",
          link: "simplify/providers",
          badge: 0,
        },
      ];

      // Only show project tab if there are variants
      if (this.hasVariants) {
        tabs.push({
          name: "project",
          label: this.$t("simplify.project"),
          icon: "project",
          link: "simplify/project",
          badge: this.projectChangesCount,
        });
      }

      return tabs;
    },
    sourceLanguages() {
      // Get unique source languages from language variants
      const variants = this.data.languageVariants || [];
      const sourceCodesSet = new Set();

      variants.forEach((variant) => {
        if (variant.source) {
          sourceCodesSet.add(variant.source);
        }
      });

      // Filter siteLanguages to only include those that have variants
      const siteLanguages = this.data.siteLanguages || [];
      return siteLanguages.filter((lang) => sourceCodesSet.has(lang.code));
    },
    currentSourceLanguageName() {
      if (!this.currentSourceLanguage) return "";
      const lang = this.sourceLanguages.find(
        (l) => l.code === this.currentSourceLanguage
      );
      return lang ? lang.name : "";
    },
    hasProjectChanges() {
      return this.projectDescription !== this.savedProjectDescription;
    },
    projectChangesCount() {
      return this.projectDescription !== this.savedProjectDescription ? 1 : 0;
    },

    reports() {
      if (!this.data.byLanguage) return [];

      return Object.entries(this.data.byLanguage).map(([lang, count]) => {
        const total = this.data.total || 1;
        const percent = Math.round((count / total) * 100);
        const theme =
          percent > 80 ? "positive" : percent > 50 ? "notice" : "negative";

        return {
          label: lang,
          value: `${count} / ${total}`,
          info: `${percent}%`,
          theme,
        };
      });
    },
  },
  async mounted() {
    // Set default source language to first available
    if (this.sourceLanguages.length > 0) {
      this.currentSourceLanguage = this.sourceLanguages[0].code;
    }
    await this.loadProjectData();

    // Add keyboard shortcut for saving (Cmd+S / Ctrl+S)
    this.handleKeyboardShortcut = (event) => {
      if ((event.metaKey || event.ctrlKey) && event.key === "s") {
        event.preventDefault();
        if (this.tab === "project" && this.hasProjectChanges) {
          this.saveProjectData();
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
    async loadProjectData() {
      if (!this.currentSourceLanguage) return;

      try {
        const response = await this.$api.get(
          `simplify/project/${this.currentSourceLanguage}`
        );
        if (response.success) {
          this.projectDescription = response.data.description || "";
          this.projectPlaceholder = response.data.placeholder || "";
          this.savedProjectDescription = this.projectDescription;
        }
      } catch (error) {
        console.error("Failed to load project data:", error);
      }
    },
    async switchSourceLanguage(languageCode) {
      this.currentSourceLanguage = languageCode;
      await this.loadProjectData();
    },
    updateProjectDescription(value) {
      this.projectDescription = value;
    },
    discardProjectChanges() {
      this.projectDescription = this.savedProjectDescription;
    },
    async saveProjectData() {
      if (!this.currentSourceLanguage) return;

      try {
        const response = await this.$api.post(
          `simplify/project/${this.currentSourceLanguage}/save`,
          {
            description: this.projectDescription,
          }
        );
        if (response.success) {
          this.savedProjectDescription = this.projectDescription;
          this.$panel.notification.success();
        } else {
          this.$panel.notification.error(
            response.message || this.$t("simplify.project.save.error")
          );
        }
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.error.generic", {
            message: error.message,
          })
        );
      }
    },
    mapExistingVariants(variants) {
      return variants.reduce((acc, variant) => {
        if (variant.source && variant.variant) {
          acc[variant.source] = acc[variant.source] || [];
          // Store variant_code (e.g., 'ls', 'es', 'falc') instead of language code
          acc[variant.source].push(variant.variant);
        }
        return acc;
      }, {});
    },
    filterAvailableSourceLanguages(
      sourceLanguages,
      availableRuleVariants,
      existingVariantsBySource
    ) {
      return sourceLanguages.filter((lang) => {
        const availableVariants = availableRuleVariants[lang.code] || [];
        if (availableVariants.length === 0) {
          return false;
        }

        const existingForLang = existingVariantsBySource[lang.code] || [];
        if (availableVariants.length === existingForLang.length) {
          return !availableVariants.every((variant) =>
            existingForLang.includes(variant.variant_code)
          );
        }

        return true;
      });
    },
    buildLanguageMap(languages) {
      return languages.reduce((acc, lang) => {
        acc[lang.code] = lang;
        return acc;
      }, {});
    },
    buildSourceOptions(languages) {
      return languages.map((lang) => ({
        value: lang.code,
        text: `${lang.name} (${lang.code})`,
      }));
    },
    openVariantSettings(variant) {
      // Open a form dialog to edit variant settings
      this.$panel.dialog.open({
        component: "k-form-dialog",
        props: {
          fields: {
            name: {
              label: this.$t("language.name"),
              type: "text",
              required: true,
              counter: false,
            },
            code: {
              label: this.$t("language.code"),
              type: "text",
              required: true,
              disabled: true,
            },
            locale: {
              label: this.$t("language.locale"),
              type: "text",
              disabled: true,
            },
          },
          value: {
            name: variant.name,
            code: variant.code,
            locale: variant.locale || "",
          },
          submitButton: this.$t("save"),
          cancelButton: this.$t("cancel"),
        },
        on: {
          submit: async (values) => {
            try {
              const response = await this.$api.patch(
                `languages/${variant.code}`,
                {
                  name: values.name,
                  locale: values.locale,
                }
              );

              this.$panel.notification.success();
              this.$panel.dialog.close();

              // Reload the view to show updated data
              this.$panel.view.refresh();
            } catch (error) {
              this.$panel.notification.error(
                error.message ||
                  this.$t("simplify.languages.notification.updateError")
              );
            }
          },
        },
      });
    },
    openAddVariantDialog() {
      const sourceLanguages = this.data.siteLanguages || [];
      const existingVariants = this.data.languageVariants || [];
      const availableRuleVariants = this.data.availableRuleVariants || {};

      const existingVariantsBySource =
        this.mapExistingVariants(existingVariants);
      const availableSourceLanguages = this.filterAvailableSourceLanguages(
        sourceLanguages,
        availableRuleVariants,
        existingVariantsBySource
      );

      if (availableSourceLanguages.length === 0) {
        this.$panel.notification.error(
          this.$t("simplify.languages.notification.allVariants")
        );
        return;
      }

      const languageMap = this.buildLanguageMap(sourceLanguages);
      const sourceOptions = this.buildSourceOptions(availableSourceLanguages);
      const onlyOneLanguage = availableSourceLanguages.length === 1;

      if (onlyOneLanguage) {
        this.openVariantDetailsDialog(
          availableSourceLanguages[0].code,
          existingVariantsBySource,
          availableRuleVariants,
          languageMap
        );
        return;
      }

      this.showSourceSelectionDialog(sourceOptions, (sourceCode) =>
        this.openVariantDetailsDialog(
          sourceCode,
          existingVariantsBySource,
          availableRuleVariants,
          languageMap
        )
      );
    },
    buildVariantFields(allVariants, availableVariants) {
      const fields = {};
      const values = {};

      if (allVariants.length > 1) {
        // Multiple variants: show dropdown with disabled items for existing ones
        fields.variant = {
          label: this.$t("simplify.languages.dialog.variant"),
          type: "select",
          required: true,
          options: allVariants.map((variant) => {
            let optionText = `${variant.variant_name} (${variant.standard})`;
            if (variant.isExisting) {
              optionText += " ✓";
            }
            return {
              value: variant.bcp47_tag,
              text: optionText,
              disabled: variant.isExisting,
            };
          }),
          help: availableVariants[0].description,
        };
        values.variant = availableVariants[0].bcp47_tag;
      } else if (availableVariants.length === 1) {
        // Only one variant: show info about the variant
        const onlyVariant = availableVariants[0];
        values.variant = onlyVariant.bcp47_tag;

        // Show variant info as read-only info field
        fields.variantInfo = {
          label: this.$t("simplify.languages.dialog.variant"),
          type: "info",
          text: `${onlyVariant.variant_name} (${onlyVariant.standard})`,
          help: onlyVariant.description,
        };
      }

      fields.name = {
        label: this.$t("language.name"),
        type: "text",
        required: true,
        icon: "title",
        help: this.$t("simplify.languages.dialog.nameHelp"),
      };

      fields.code = {
        label: this.$t("language.code"),
        type: "text",
        required: true,
        icon: "translate",
        help: this.$t("simplify.languages.dialog.codeHelp"),
      };

      return { fields, values };
    },
    openVariantDetailsDialog(
      sourceCode,
      existingVariantsBySource,
      availableRuleVariants,
      languageMap
    ) {
      const sourceLang = languageMap[sourceCode];
      if (!sourceLang) {
        return;
      }

      const allVariantsForSource = availableRuleVariants[sourceCode] || [];
      const existingForSource = existingVariantsBySource[sourceCode] || [];

      // Mark variants as available or existing
      const variantsWithAvailability = allVariantsForSource.map((variant) => ({
        ...variant,
        isExisting: existingForSource.includes(variant.variant_code),
      }));

      const availableVariants = variantsWithAvailability.filter(
        (v) => !v.isExisting
      );

      const { fields, values } = this.buildVariantFields(
        variantsWithAvailability,
        availableVariants
      );

      if (availableVariants.length > 0) {
        const firstVariant = availableVariants[0];
        values.name = `${firstVariant.language} - ${firstVariant.variant_name}`;
        values.code = firstVariant.bcp47_tag;
        values._previousCode = firstVariant.bcp47_tag; // Track initial code
        values._previousName = values.name; // Track initial name
      } else {
        values.name = `${sourceLang.name} - ${this.$t(
          "simplify.languages.dialog.defaultNameSuffix"
        )}`;
        values.code = `${sourceCode}-x-ls`;
        values._previousCode = `${sourceCode}-x-ls`; // Track initial code
        values._previousName = values.name; // Track initial name
      }

      this.$dialog({
        component: "k-form-dialog",
        props: {
          fields,
          submitButton: this.$t("simplify.languages.dialog.submit"),
          value: values,
        },
        on: {
          input: (formValues) => {
            if (variantsWithAvailability.length > 1 && formValues.variant) {
              const selectedVariant = variantsWithAvailability.find(
                (variant) => variant.bcp47_tag === formValues.variant
              );
              if (selectedVariant) {
                // Update name only if it hasn't been manually changed
                if (
                  !formValues._previousName ||
                  formValues.name === formValues._previousName
                ) {
                  formValues.name = `${selectedVariant.language} - ${selectedVariant.variant_name}`;
                  formValues._previousName = formValues.name;
                }

                // Update code only if it hasn't been manually changed
                if (
                  !formValues._previousCode ||
                  formValues.code === formValues._previousCode
                ) {
                  formValues.code = selectedVariant.bcp47_tag;
                  formValues._previousCode = selectedVariant.bcp47_tag;
                }

                // Update help text with description
                if (fields.variant && selectedVariant.description) {
                  fields.variant.help = selectedVariant.description;
                }
              }
            }
          },
          submit: async (formValues) => {
            await this.submitVariantCreation(
              formValues,
              sourceCode,
              sourceLang,
              variantsWithAvailability
            );
          },
        },
      });
    },
    async submitVariantCreation(
      formValues,
      sourceCode,
      sourceLang,
      availableVariants
    ) {
      try {
        // Extract variant_code from the selected variant
        // formValues.variant contains bcp47_tag (e.g., 'fr-x-falc')
        let variantCode = null;

        if (
          availableVariants &&
          availableVariants.length > 0 &&
          formValues.variant
        ) {
          const selectedVariant = availableVariants.find(
            (v) => v.bcp47_tag === formValues.variant
          );
          if (selectedVariant) {
            variantCode = selectedVariant.variant_code; // e.g., 'falc'
          }
        }

        const payload = {
          code: formValues.code,
          name: formValues.name,
          source: sourceCode,
          locale: sourceLang.locale,
          direction: sourceLang.direction,
          variant: variantCode,
        };

        const response = await this.$api.post(
          "simplify/language/create",
          payload
        );

        if (response.success) {
          window.location.reload();
        } else {
          this.$panel.notification.error(
            response.message ||
              this.$t("simplify.languages.notification.createError")
          );
        }
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.error.generic", { message: error.message })
        );
      }
    },
    showSourceSelectionDialog(sourceOptions, onSubmit) {
      const fields = {
        source: {
          label: this.$t("simplify.languages.dialog.sourceLabel"),
          type: "select",
          required: true,
          empty: false,
          options: sourceOptions,
          help: this.$t("simplify.languages.dialog.sourceHelp"),
        },
      };

      this.$dialog({
        component: "k-form-dialog",
        props: {
          fields,
          submitButton: this.$t("simplify.languages.dialog.next"),
          value: { source: "" },
        },
        on: {
          submit: (values) => {
            onSubmit(values.source);
          },
        },
      });
    },
    async openAddModelDialog() {
      // Load available models
      try {
        const response = await this.$api.get("simplify/models/available");
        if (!response.success) {
          this.$panel.notification.error(
            this.$t("simplify.models.notification.apiUnavailable")
          );
          return;
        }

        const availableModels = response.models;
        const providerInfo = response.providers || {};

        // Get configured providers with API keys from config
        const configuredProviders = [];
        const providers = this.config.providers || {};

        for (const [providerId, providerConfig] of Object.entries(providers)) {
          // Check if provider has API key configured and is in available models
          if (providerConfig.apikey && availableModels[providerId]) {
            configuredProviders.push(providerId);
          }
        }

        if (configuredProviders.length === 0) {
          this.$panel.notification.error(
            this.$t("simplify.models.notification.error", {
              message: "No providers with API keys configured",
            })
          );
          return;
        }

        const onlyOneProvider = configuredProviders.length === 1;

        // If only one provider configured, skip provider selection
        if (onlyOneProvider) {
          this.openModelSelectionDialog(
            configuredProviders[0],
            availableModels,
            providerInfo
          );
          return;
        }

        // Multiple providers: show provider selection with nice names
        const providerOptions = configuredProviders.map((provider) => ({
          value: provider,
          text: providerInfo[provider]?.name || provider,
        }));

        this.$dialog({
          component: "k-form-dialog",
          props: {
            fields: {
              provider_type: {
                label: this.$t("simplify.models.dialog.provider"),
                type: "select",
                options: providerOptions,
                required: true,
              },
            },
            submitButton: this.$t("simplify.languages.dialog.next"),
          },
          on: {
            submit: (values) => {
              this.openModelSelectionDialog(
                values.provider_type,
                availableModels,
                providerInfo
              );
            },
          },
        });
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.models.notification.apiUnavailable")
        );
      }
    },
    async openModelSelectionDialog(
      providerType,
      availableModels,
      providerInfo
    ) {
      const models = availableModels[providerType] || [];

      // Get already configured models for this provider
      const configuredModels = this.data.models || {};
      const alreadyConfigured = Object.keys(configuredModels)
        .filter((key) => key.startsWith(`${providerType}-`))
        .map((key) => key.replace(`${providerType}-`, ""));

      // Filter out already configured models
      const availableModelsList = models.filter(
        (model) => !alreadyConfigured.includes(model)
      );

      // Load community status for all models
      let communityStatus = {};
      try {
        const statusResponse = await this.$api.get(
          `simplify/models/community-status/${providerType}`
        );
        if (statusResponse.success) {
          communityStatus = statusResponse.status || {};
        }
      } catch (error) {
        console.warn("Failed to load community status:", error);
      }

      // Build model options with community status
      const modelOptions = availableModelsList.map((model) => {
        const status = communityStatus[model];
        let label = model;

        // Show "Recommended" if API says so
        if (status && status.recommended) {
          label = `${model} (${this.$t("simplify.models.status.recommended")})`;
        }

        return {
          value: model,
          text: label,
        };
      });

      // Add custom option
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
        custom_name: {
          label: this.$t("simplify.models.dialog.customName"),
          type: "text",
          counter: false,
          when: { model: "custom" },
        },
      };

      // Set help text for first model
      const firstModel = models.length > 0 ? models[0] : null;
      if (firstModel && communityStatus[firstModel]) {
        const status = communityStatus[firstModel];
        fields.model.help = this.formatCommunityStatusHelp(status);
      }

      this.$dialog({
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
            // Update help text when model changes
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
            await this.addModel(providerType, values);
          },
        },
      });
    },
    formatCommunityStatusHelp(status) {
      const statusText = this.$t(`simplify.community.status.${status.status}`);
      const qualityText = this.$t(
        `simplify.community.quality.${status.quality}`
      );

      return `${statusText} ${qualityText}`;
    },
    async addModel(providerType, values) {
      try {
        const payload = {
          provider_type: providerType,
          model: values.model === "custom" ? values.custom_name : values.model,
          custom_name: values.model === "custom" ? values.custom_name : null,
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
    openDeleteVariantDialog(variant) {
      this.$dialog({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.languages.delete.confirm", {
            name: variant.name,
            code: variant.code,
          }),
          submitButton: this.$t("delete"),
          icon: "trash",
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.delete(
                `languages/${variant.code}`
              );

              if (response) {
                window.location.reload();
              } else {
                this.$panel.notification.error(
                  this.$t("simplify.languages.notification.deleteError")
                );
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
    async openAssignProviderDialog(variant) {
      // Load available models
      let availableModels = [];
      try {
        const response = await this.$api.get("simplify/models");
        if (response.success && response.models) {
          const providerNames = response.providerNames || {};
          // Convert object to array
          availableModels = Object.values(response.models).map((model) => ({
            value: model.config_id,
            text: `${
              providerNames[model.provider_type] || model.provider_type
            }: ${model.model}`,
          }));
        }
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.error.generic", { message: error.message })
        );
        return;
      }

      // If no models available, show notification and return
      if (availableModels.length === 0) {
        this.$panel.notification.error(
          this.$t("simplify.models.dialog.noModelsAvailable")
        );
        return;
      }

      this.$panel.dialog.open({
        component: "k-form-dialog",
        props: {
          fields: {
            model: {
              label: this.$t("simplify.provider.select"),
              type: "select",
              options: availableModels,
              help: this.$t("simplify.provider.assign.dialog.help"),
              required:
                availableModels.length > 1 || availableModels[0]?.value !== "",
            },
          },
          value: {
            model: variant.providerModel || "",
          },
          submitButton: this.$t("simplify.provider.assign.dialog.submit"),
        },
        on: {
          submit: async (values) => {
            try {
              const response = await this.$api.post(
                "simplify/variant/assign-model",
                {
                  variantCode: variant.code,
                  modelConfigId: values.model,
                }
              );

              if (response.success) {
                this.$panel.notification.success();
                this.$panel.dialog.close();
                window.location.reload();
              } else {
                this.$panel.notification.error(
                  response.message || this.$t("simplify.provider.assign.error")
                );
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
    openDeleteModelDialog(model) {
      this.$dialog({
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
              } else {
                this.$panel.notification.error(
                  this.$t("simplify.models.notification.error", {
                    message: response.message || "Failed to delete model",
                  })
                );
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
